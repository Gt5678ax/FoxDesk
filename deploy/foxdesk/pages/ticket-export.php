<?php
/**
 * Ticket Markdown Export (agents/admin only)
 */

if (!function_exists('ticket_export_md_inline')) {
    function ticket_export_md_inline($value)
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));
        return $value;
    }
}

if (!function_exists('ticket_export_md_block')) {
    function ticket_export_md_from_html($html)
    {
        $html = str_replace(["\r\n", "\r"], "\n", (string) $html);

        // Links first, before stripping tags.
        $html = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($m) {
            $href = trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(strip_tags((string) ($m[2] ?? '')));
            if ($text === '') {
                $text = $href;
            }
            return '[' . $text . '](' . $href . ')';
        }, $html);

        // Headings.
        for ($level = 6; $level >= 1; $level--) {
            $prefix = str_repeat('#', $level);
            $pattern = '/<h' . $level . '[^>]*>(.*?)<\/h' . $level . '>/is';
            $html = preg_replace_callback($pattern, function ($m) use ($prefix) {
                $text = trim(strip_tags((string) ($m[1] ?? '')));
                return "\n\n" . $prefix . ' ' . $text . "\n\n";
            }, $html);
        }

        // Lists and block-level structure.
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<\/li>/i', '', $html);
        $html = preg_replace('/<\/?(ul|ol)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|section|article|blockquote|pre)>/i', "\n\n", $html);
        $html = preg_replace('/<(p|div|section|article|blockquote|pre)[^>]*>/i', '', $html);

        // Inline formatting.
        $html = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html);
        $html = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html);
        $html = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html);

        // Remove remaining HTML tags.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\t", '    ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string) $text);
    }

    function ticket_export_md_block($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        if (preg_match('/<[^>]+>/', $value)) {
            $value = ticket_export_md_from_html($value);
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);
        return trim($value);
    }
}

if (!function_exists('ticket_export_md_attachment_url')) {
    function ticket_export_md_attachment_url($filename, $base_url)
    {
        $upload_dir = trim((string) (defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/');
        return rtrim($base_url, '/') . '/' . $upload_dir . '/' . rawurlencode((string) $filename);
    }
}

// Support both hash-based URLs (t=hash) and legacy ID-based URLs (id=123)
$ticket_hash = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
$ticket_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!empty($ticket_hash)) {
    $ticket = get_ticket_by_hash($ticket_hash);
    if ($ticket) {
        $ticket_id = (int) $ticket['id'];
    }
} else {
    $ticket = get_ticket($ticket_id);
}

if (!$ticket) {
    http_response_code(404);
    echo t('Ticket not found.');
    exit;
}

$user = current_user();
if (!$user || !is_agent() || !can_see_ticket($ticket, $user)) {
    http_response_code(403);
    echo t('Access denied.');
    exit;
}

$ticket_id = (int) $ticket['id'];
$comments = get_ticket_comments($ticket_id);
$attachments = get_ticket_attachments($ticket_id);
$ticket_code = get_ticket_code($ticket_id);
$base_url = get_base_url();

$root_attachments = [];
$comment_attachments = [];
foreach ($attachments as $attachment) {
    $comment_id = (int) ($attachment['comment_id'] ?? 0);
    if ($comment_id > 0) {
        if (!isset($comment_attachments[$comment_id])) {
            $comment_attachments[$comment_id] = [];
        }
        $comment_attachments[$comment_id][] = $attachment;
    } else {
        $root_attachments[] = $attachment;
    }
}

$priority_name = (string) ($ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'normal'));
$requester_name = trim((string) (($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')));
if ($requester_name === '') {
    $requester_name = (string) ($ticket['email'] ?? t('Unknown user'));
}

$assignee_name = trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')));
if ($assignee_name === '') {
    $assignee_name = '-';
}

$due_line = '-';
if (!empty($ticket['due_date'])) {
    $due_ts = strtotime((string) $ticket['due_date']);
    if ($due_ts !== false) {
        $due_line = date('Y-m-d H:i', $due_ts);
        if ($due_ts < time()) {
            $due_line .= ' (' . t('Overdue') . ')';
        }
    }
}

$lines = [];
$lines[] = '# ' . ticket_export_md_inline($ticket_code . ' - ' . ($ticket['title'] ?? ''));
$lines[] = '';
$lines[] = '- ' . t('Status') . ': ' . ticket_export_md_inline($ticket['status_name'] ?? '');
$lines[] = '- ' . t('Priority') . ': ' . ticket_export_md_inline($priority_name);
$lines[] = '- ' . t('Type') . ': ' . ticket_export_md_inline(get_type_label($ticket['type'] ?? 'general'));
$lines[] = '- ' . t('Created') . ': ' . date('Y-m-d H:i', strtotime((string) $ticket['created_at']));
$lines[] = '- ' . t('Due date') . ': ' . ticket_export_md_inline($due_line);
$lines[] = '- ' . t('User') . ': ' . ticket_export_md_inline($requester_name);
$lines[] = '- ' . t('Assignee') . ': ' . ticket_export_md_inline($assignee_name);

if (!empty($ticket['organization_name'])) {
    $lines[] = '- ' . t('Company') . ': ' . ticket_export_md_inline($ticket['organization_name']);
}
if (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists() && !empty($ticket['tags'])) {
    $ticket_tags = get_ticket_tags_array($ticket['tags']);
    if (!empty($ticket_tags)) {
        $lines[] = '- ' . t('Tags') . ': ' . ticket_export_md_inline(implode(', ', $ticket_tags));
    }
}

$lines[] = '';
$lines[] = '## ' . t('Description');
$description = ticket_export_md_block((string) ($ticket['description'] ?? ''));
if ($description === '') {
    $lines[] = '_(' . t('No description') . ')_';
} else {
    foreach (explode("\n", $description) as $line) {
        $lines[] = rtrim($line);
    }
}

$lines[] = '';
$lines[] = '## ' . t('Attachments');
if (empty($root_attachments)) {
    $lines[] = '- -';
} else {
    foreach ($root_attachments as $attachment) {
        $name = ticket_export_md_inline((string) ($attachment['original_name'] ?? $attachment['filename'] ?? 'file'));
        $url = ticket_export_md_attachment_url($attachment['filename'] ?? '', $base_url);
        $lines[] = '- [' . $name . '](' . $url . ')';
    }
}

$lines[] = '';
$lines[] = '## ' . t('Comments');
if (empty($comments)) {
    $lines[] = '_(' . t('No comments yet.') . ')_';
} else {
    foreach ($comments as $comment) {
        $author = trim((string) (($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? '')));
        if ($author === '') {
            $author = (string) ($comment['email'] ?? t('Unknown user'));
        }
        $created = !empty($comment['created_at']) ? date('Y-m-d H:i', strtotime((string) $comment['created_at'])) : '-';
        $header = '### ' . ticket_export_md_inline($created . ' - ' . $author);
        if (!empty($comment['is_internal'])) {
            $header .= ' [' . t('Internal') . ']';
        }
        $lines[] = $header;
        $lines[] = '';

        $content = ticket_export_md_block((string) ($comment['content'] ?? ''));
        if ($content === '') {
            $lines[] = '-';
        } else {
            foreach (explode("\n", $content) as $line) {
                $lines[] = rtrim($line);
            }
        }

        $cid = (int) ($comment['id'] ?? 0);
        if ($cid > 0 && !empty($comment_attachments[$cid])) {
            $lines[] = '';
            $lines[] = t('Attachments') . ':';
            foreach ($comment_attachments[$cid] as $attachment) {
                $name = ticket_export_md_inline((string) ($attachment['original_name'] ?? $attachment['filename'] ?? 'file'));
                $url = ticket_export_md_attachment_url($attachment['filename'] ?? '', $base_url);
                $lines[] = '- [' . $name . '](' . $url . ')';
            }
        }

        $lines[] = '';
    }
}

$filename_safe = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $ticket_code));
$filename_safe = trim((string) $filename_safe, '-');
$title_slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', ticket_export_md_inline((string) ($ticket['title'] ?? ''))));
$title_slug = trim((string) $title_slug, '-');
if ($title_slug !== '') {
    $filename_safe .= '-' . substr($title_slug, 0, 50);
    $filename_safe = trim($filename_safe, '-');
}
if ($filename_safe === '') {
    $filename_safe = 'ticket-' . $ticket_id;
}

header('Content-Type: text/markdown; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename_safe . '.md"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo implode("\n", $lines);
exit;

