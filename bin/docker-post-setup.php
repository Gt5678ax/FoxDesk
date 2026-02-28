#!/usr/bin/env php
<?php
/**
 * Docker post-setup: configure SMTP, IMAP, and seed demo data.
 * Called from docker-entrypoint.sh after docker-setup.php.
 */

require '/var/www/html/config.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    if ($stmt->fetch()) {
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

// ─── SMTP Configuration ──────────────────────────
$smtp_host = getenv('SMTP_HOST');
if ($smtp_host) {
    echo "Configuring SMTP for {$smtp_host}:" . (getenv('SMTP_PORT') ?: '1025') . "...\n";
    $smtp = [
        'smtp_host'                   => $smtp_host,
        'smtp_port'                   => getenv('SMTP_PORT') ?: '587',
        'smtp_user'                   => getenv('SMTP_FROM_EMAIL') ?: 'support@foxdesk.local',
        'smtp_pass'                   => '',
        'smtp_from_email'             => getenv('SMTP_FROM_EMAIL') ?: 'support@foxdesk.local',
        'smtp_from_name'              => getenv('SMTP_FROM_NAME') ?: 'FoxDesk Support',
        'smtp_encryption'             => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'email_notifications_enabled' => '1',
        'notify_on_status_change'     => '1',
        'notify_on_new_comment'       => '1',
        'notify_on_new_ticket'        => '1',
    ];
    foreach ($smtp as $k => $v) {
        set_setting($pdo, $k, $v);
    }
    echo "  SMTP configured.\n";
}

// ─── IMAP Configuration ──────────────────────────
$imap_host = getenv('IMAP_HOST');
$imap_enabled = getenv('IMAP_ENABLED');
if ($imap_host && $imap_enabled === 'true') {
    echo "Configuring IMAP for {$imap_host}:" . (getenv('IMAP_PORT') ?: '993') . "...\n";
    $imap = [
        'imap_enabled'               => '1',
        'imap_host'                  => $imap_host,
        'imap_port'                  => getenv('IMAP_PORT') ?: '993',
        'imap_encryption'            => getenv('IMAP_ENCRYPTION') ?: 'ssl',
        'imap_username'              => getenv('IMAP_USERNAME') ?: '',
        'imap_password'              => getenv('IMAP_PASSWORD') ?: '',
        'imap_folder'                => 'INBOX',
        'imap_processed_folder'      => 'Processed',
        'imap_failed_folder'         => 'Failed',
        'imap_max_emails_per_run'    => '50',
        'imap_validate_cert'         => '0',
        'imap_allow_unknown_senders' => '1',
    ];
    foreach ($imap as $k => $v) {
        set_setting($pdo, $k, $v);
    }

    // Add wildcard allowed sender for dev testing
    try {
        $pdo->exec("INSERT IGNORE INTO allowed_senders (type, value, active) VALUES ('domain', '*', 1)");
    } catch (Exception $e) {
        // ignore if table doesn't exist yet
    }
    echo "  IMAP configured.\n";
}

// ─── Seed Demo Data ──────────────────────────────
echo "Seeding demo data...\n";

$seed_files = ['seed_data.sql', 'seed_emails.sql'];
foreach ($seed_files as $file) {
    $path = "/var/www/html/$file";
    if (file_exists($path)) {
        try {
            $sql = file_get_contents($path);
            $pdo->exec($sql);
            echo "  $file loaded.\n";
        } catch (Exception $e) {
            echo "  $file skipped: " . $e->getMessage() . "\n";
        }
    }
}

echo "Post-setup complete.\n";
