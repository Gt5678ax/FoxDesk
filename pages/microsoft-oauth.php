<?php
/** Authenticated Microsoft OAuth callback for the current workspace. */
if (!is_admin()) {
    http_response_code(403);
    flash(t('Access denied.'), 'error');
    redirect(foxdesk_authenticated_home_page());
}

require_once BASE_PATH . '/includes/microsoft-mail-functions.php';

try {
    $oauth_error = trim((string) ($_GET['error'] ?? ''));
    if ($oauth_error !== '') {
        throw new RuntimeException('Microsoft authorization was cancelled or denied.');
    }
    $result = microsoft_mail_complete_authorization(
        (string) ($_GET['state'] ?? ''),
        (string) ($_GET['code'] ?? '')
    );
    if (function_exists('log_security_event')) {
        log_security_event('microsoft_mail_connected', (int) (current_user()['id'] ?? 0), json_encode([
            'mailbox_domain' => substr(strrchr((string) $result['mailbox_email'], '@') ?: '', 1),
        ]));
    }
    flash(t('Microsoft mailbox {email} connected.', ['email' => (string) $result['mailbox_email']]), 'success');
} catch (Throwable $e) {
    if (function_exists('debug_log')) {
        debug_log('Microsoft OAuth callback failed', ['error_class' => get_class($e)], 'warning', 'settings');
    }
    flash(t('Microsoft connection failed: {error}', ['error' => $e->getMessage()]), 'error');
}

redirect('admin', ['section' => 'settings', 'tab' => 'email']);
