<?php
/**
 * Converts Microsoft Graph messages into FoxDesk's existing ticket-ingest model.
 * The current workspace is the routing boundary; Graph credentials are tenant-scoped.
 */

function microsoft_email_ingest_headers(array $message): array
{
    $headers = [];
    foreach ((array) ($message['internetMessageHeaders'] ?? []) as $header) {
        if (!is_array($header)) {
            continue;
        }
        $name = strtolower(trim((string) ($header['name'] ?? '')));
        if ($name !== '') {
            $headers[$name] = trim((string) ($header['value'] ?? ''));
        }
    }
    return $headers;
}

function microsoft_email_ingest_raw_headers(array $headers): string
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $cleanName = preg_replace('/[^a-z0-9-]+/i', '', (string) $name);
        $cleanValue = trim((string) preg_replace('/[\r\n]+/', ' ', (string) $value));
        if ($cleanName !== '' && $cleanValue !== '') {
            $lines[] = ucwords($cleanName, '-') . ': ' . $cleanValue;
        }
    }
    return implode("\r\n", $lines);
}

function microsoft_email_ingest_uid(array $message): int
{
    $source = trim((string) ($message['id'] ?? $message['internetMessageId'] ?? ''));
    if ($source === '') {
        $source = json_encode([
            'from' => $message['from'] ?? [],
            'subject' => $message['subject'] ?? '',
            'received' => $message['receivedDateTime'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $uid = (int) hexdec(substr(hash('sha256', (string) $source), 0, 7));
    return $uid > 0 ? $uid : 1;
}

function microsoft_email_ingest_body(array $message): array
{
    $body = (array) ($message['body'] ?? []);
    $content = (string) ($body['content'] ?? '');
    $contentType = strtolower(trim((string) ($body['contentType'] ?? 'text')));

    if (function_exists('email_ingest_prepare_body_content')) {
        return $contentType === 'html'
            ? email_ingest_prepare_body_content('', $content)
            : email_ingest_prepare_body_content($content, '');
    }

    $htmlRaw = $contentType === 'html' ? trim($content) : '';
    $html = email_ingest_sanitize_html($htmlRaw);
    $text = $contentType === 'html'
        ? email_ingest_html_to_text($html)
        : email_ingest_normalize_plain_text($content);
    $display = email_ingest_select_display_body($text, $html);
    if ($display === '') {
        $display = '(No content)';
    }

    return [
        'text' => $text,
        'html_raw' => $htmlRaw,
        'html' => $html,
        'display' => $display,
    ];
}

function microsoft_email_ingest_attachments(array $message, array &$errors): array
{
    if (empty($message['hasAttachments']) || empty($message['id'])) {
        return [];
    }

    $attachments = [];
    foreach (microsoft_mail_message_attachments((string) $message['id']) as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $type = strtolower((string) ($attachment['@odata.type'] ?? ''));
        if ($type !== '#microsoft.graph.fileattachment') {
            $errors[] = 'Microsoft item/reference attachment was skipped.';
            continue;
        }
        $decoded = base64_decode((string) ($attachment['contentBytes'] ?? ''), true);
        if (!is_string($decoded)) {
            $errors[] = 'Microsoft attachment could not be decoded.';
            continue;
        }
        $attachments[] = [
            'filename' => (string) ($attachment['name'] ?? 'attachment.bin'),
            'mime' => (string) ($attachment['contentType'] ?? 'application/octet-stream'),
            'size' => strlen($decoded),
            'content_id' => !empty($attachment['contentId']) ? (string) $attachment['contentId'] : null,
            'data' => $decoded,
        ];
    }
    return $attachments;
}

function microsoft_email_ingest_process_message(array $message, array $cfg, bool $dryRun = false): array
{
    $connection = microsoft_mail_connection() ?? [];
    $mailbox = 'microsoft:' . strtolower((string) ($connection['mailbox_email'] ?? 'mailbox'));
    $uid = microsoft_email_ingest_uid($message);
    $headers = microsoft_email_ingest_headers($message);
    $rawHeaders = microsoft_email_ingest_raw_headers($headers);
    $messageId = email_ingest_normalize_message_id(
        (string) ($message['internetMessageId'] ?? ($headers['message-id'] ?? ''))
    );
    if ($messageId === null && !empty($message['id'])) {
        $messageId = email_ingest_normalize_message_id(
            'graph-' . substr(hash('sha256', (string) $message['id']), 0, 32) . '@foxdesk.local'
        );
    }

    $fromEmail = email_ingest_normalize_email(
        (string) ($message['from']['emailAddress']['address'] ?? '')
    );
    $subject = trim((string) ($message['subject'] ?? ''));
    if ($subject === '') {
        $subject = '(No subject)';
    }

    $skip = static function (string $reason) use ($mailbox, $uid, $messageId, $fromEmail, $subject, $dryRun): array {
        if (!$dryRun) {
            email_ingest_log($mailbox, $uid, $messageId, 'skipped', $reason, null, [
                'sender_email' => $fromEmail,
                'subject' => $subject,
            ]);
        }
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => $reason,
            'message_id' => $messageId,
            'from' => $fromEmail,
        ];
    };

    if (email_ingest_log_exists($mailbox, $uid)
        || ($messageId !== null && email_ingest_message_id_exists($messageId))) {
        return $skip('duplicate_message_id');
    }
    if ($fromEmail === '') {
        return $skip('missing_from');
    }
    if (email_ingest_is_auto_reply(null, $rawHeaders)) {
        return $skip('auto_reply_or_bulk');
    }

    $allowedSender = email_ingest_allowed_sender($fromEmail);
    $allowUnknown = function_exists('email_ingest_workspace_allows_unknown_senders')
        ? email_ingest_workspace_allows_unknown_senders()
        : !empty($cfg['allow_unknown_senders']);
    if (!$allowedSender && !$allowUnknown) {
        return $skip('sender_not_allowed');
    }
    if (!$allowedSender
        && function_exists('email_ingest_sender_rate_limited')
        && email_ingest_sender_rate_limited($fromEmail)) {
        return $skip('sender_rate_limited');
    }

    $body = microsoft_email_ingest_body($message);
    $inReplyTo = email_ingest_normalize_message_id((string) ($headers['in-reply-to'] ?? ''));
    $references = trim((string) ($headers['references'] ?? ''));
    $referenceIds = email_ingest_extract_message_ids($references);

    if ($dryRun) {
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => 'dry_run',
            'message_id' => $messageId,
            'from' => $fromEmail,
        ];
    }

    $requester = email_ingest_resolve_requester_user_id($fromEmail, $allowedSender);
    $requesterUserId = (int) ($requester['user_id'] ?? 0);
    if ($requesterUserId <= 0) {
        $reason = function_exists('email_ingest_requester_failure_reason')
            ? email_ingest_requester_failure_reason((array) $requester)
            : 'requester_resolution_failed';
        email_ingest_log($mailbox, $uid, $messageId, 'failed', $reason, null, [
            'sender_email' => $fromEmail,
            'subject' => $subject,
        ]);
        return [
            'uid' => $uid,
            'status' => 'failed',
            'reason' => $reason,
            'message_id' => $messageId,
        ];
    }

    $attachmentErrors = [];
    $attachments = microsoft_email_ingest_attachments($message, $attachmentErrors);
    $savedFiles = [];
    $db = get_db();
    $ticketId = 0;
    $commentId = null;
    $ticketCreated = false;

    try {
        $db->beginTransaction();
        $ticketId = email_ingest_resolve_ticket_id($subject, $inReplyTo, $referenceIds);
        if ($ticketId <= 0) {
            $ticketId = email_ingest_create_ticket_from_email($requesterUserId, $subject, $body['display']);
            $ticketCreated = true;
        } else {
            $commentId = email_ingest_add_inbound_comment($ticketId, $requesterUserId, $body['display']);
        }
        if ($ticketId <= 0) {
            throw new RuntimeException('Ticket could not be created or resolved.');
        }

        $ticketMessageId = email_ingest_insert_ticket_message([
            'ticket_id' => $ticketId,
            'user_id' => $requesterUserId,
            'comment_id' => $commentId,
            'sender_email' => $fromEmail,
            'subject' => $subject,
            'body_text' => $body['text'],
            'body_html' => $body['html'],
            'body_html_raw' => $body['html_raw'],
            'raw_headers' => $rawHeaders,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references !== '' ? $references : null,
            'mailbox' => $mailbox,
            'uid' => $uid,
        ]);

        foreach ($attachments as $attachment) {
            $stored = email_ingest_store_attachment(
                $ticketId,
                $ticketMessageId,
                $commentId,
                $requesterUserId,
                $attachment,
                $cfg
            );
            if (!empty($stored['stored'])) {
                if (!empty($stored['absolute_path'])) {
                    $savedFiles[] = (string) $stored['absolute_path'];
                }
            } else {
                $attachmentErrors[] = (string) ($stored['error'] ?? 'Attachment was not stored.');
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($savedFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        email_ingest_log($mailbox, $uid, $messageId, 'failed', 'processing_failed', $e->getMessage(), [
            'sender_email' => $fromEmail,
            'subject' => $subject,
            'ticket_id' => $ticketId > 0 ? $ticketId : null,
        ]);
        return [
            'uid' => $uid,
            'status' => 'failed',
            'reason' => 'processing_failed',
            'error' => $e->getMessage(),
            'message_id' => $messageId,
        ];
    }

    email_ingest_log($mailbox, $uid, $messageId, 'processed', null, null, [
        'sender_email' => $fromEmail,
        'subject' => $subject,
        'ticket_id' => $ticketId,
    ]);
    email_ingest_update_state($mailbox, $uid);

    try {
        if (function_exists('email_ingest_dispatch_ticket_notifications')) {
            email_ingest_dispatch_ticket_notifications(
                $ticketId,
                $ticketCreated,
                $requesterUserId,
                $body['display'],
                $commentId,
                count($attachments)
            );
        }
        email_ingest_send_requester_notifications($ticketId, $ticketCreated, $requester);
    } catch (Throwable $e) {
        error_log('Microsoft email ingest post-processing failed: ' . $e->getMessage());
    }

    return [
        'uid' => $uid,
        'status' => 'processed',
        'message_id' => $messageId,
        'from' => $fromEmail,
        'ticket_id' => $ticketId,
        'ticket_created' => $ticketCreated,
        'attachment_errors' => $attachmentErrors,
    ];
}

function microsoft_email_ingest_run(array $options = []): array
{
    $result = [
        'processed' => 0,
        'skipped' => 0,
        'failed' => 0,
        'checked' => 0,
        'details' => [],
        'provider' => 'microsoft_graph',
    ];
    if (!microsoft_mail_is_active('inbound')) {
        $result['disabled'] = true;
        return $result;
    }

    $cfg = email_ingest_config();
    $cfg['enabled'] = true;
    $limit = isset($options['limit']) && (int) $options['limit'] > 0
        ? (int) $options['limit']
        : (int) ($cfg['max_per_run'] ?? 50);
    $dryRun = !empty($options['dry_run']);

    email_ingest_ensure_schema();
    try {
        foreach (microsoft_mail_list_unread($limit) as $message) {
            if (!is_array($message) || empty($message['id'])) {
                continue;
            }
            $result['checked']++;
            try {
                $outcome = microsoft_email_ingest_process_message($message, $cfg, $dryRun);
            } catch (Throwable $e) {
                $outcome = [
                    'uid' => microsoft_email_ingest_uid($message),
                    'status' => 'failed',
                    'reason' => 'exception',
                    'error' => $e->getMessage(),
                ];
            }
            $status = in_array($outcome['status'] ?? '', ['processed', 'skipped', 'failed'], true)
                ? (string) $outcome['status']
                : 'failed';
            $result[$status]++;
            $result['details'][] = $outcome;

            if (!$dryRun) {
                try {
                    microsoft_mail_finish_message((string) $message['id'], $status !== 'failed');
                } catch (Throwable $e) {
                    $result['details'][] = [
                        'uid' => $outcome['uid'] ?? null,
                        'status' => 'failed',
                        'reason' => 'microsoft_message_finalize_failed',
                        'error' => $e->getMessage(),
                    ];
                    $result['failed']++;
                }
            }
        }
        microsoft_mail_record_sync();
    } catch (Throwable $e) {
        microsoft_mail_record_sync($e->getMessage());
        throw $e;
    }

    return $result;
}
