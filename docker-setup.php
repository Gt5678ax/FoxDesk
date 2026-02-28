<?php
/**
 * Docker auto-install script
 * Creates schema, admin user, and seeds default data.
 * Runs only once when config.php is first generated.
 */

$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'foxdesk';
$dbUser = getenv('DB_USER') ?: 'foxdesk';
$dbPass = getenv('DB_PASS') ?: 'foxdesk123';

$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@foxdesk.local';
$adminPass  = getenv('ADMIN_PASS') ?: 'Admin123!';
$adminName  = getenv('ADMIN_NAME') ?: 'Admin';
$adminSurname = getenv('ADMIN_SURNAME') ?: 'User';

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $safe_db = '`' . str_replace('`', '``', $dbName) . '`';
    $pdo->query("CREATE DATABASE IF NOT EXISTS {$safe_db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->query("USE {$safe_db}");

    // Check if tables already exist
    $tables = $pdo->query('SHOW TABLES')->fetchAll();
    if (count($tables) > 0) {
        echo "Tables already exist, skipping schema.\n";
        exit(0);
    }

    // Create schema
    $sql = file_get_contents('/var/www/html/includes/schema.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->query($statement);
        }
    }
    echo "Schema created.\n";

    // Create admin user
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password, first_name, last_name, role, is_active, created_at) VALUES (?, ?, ?, ?, 'admin', 1, NOW())");
    $stmt->execute([$adminEmail, $hash, $adminName, $adminSurname]);
    echo "Admin user created: {$adminEmail}\n";

    // Seed statuses
    $statuses = [
        ['New', 'new', '#0a84ff', 1, 1, 0],
        ['Testing', 'testing', '#5e5ce6', 2, 0, 0],
        ['Waiting for customer', 'waiting', '#ff9f0a', 3, 0, 0],
        ['In progress', 'processing', '#30b0c7', 4, 0, 0],
        ['Done', 'done', '#34c759', 5, 0, 1],
        ['Cancelled', 'cancelled', '#ff3b30', 6, 0, 1]
    ];
    $stmt = $pdo->prepare("INSERT INTO statuses (name, slug, color, sort_order, is_default, is_closed) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($statuses as $s) $stmt->execute($s);
    echo "Statuses seeded.\n";

    // Seed priorities
    $priorities = [
        ['Low', 'low', '#34c759', 'fa-arrow-down', 1, 0],
        ['Medium', 'medium', '#0a84ff', 'fa-minus', 2, 1],
        ['High', 'high', '#ff9f0a', 'fa-arrow-up', 3, 0],
        ['Urgent', 'urgent', '#ff3b30', 'fa-exclamation', 4, 0]
    ];
    $stmt = $pdo->prepare("INSERT INTO priorities (name, slug, color, icon, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($priorities as $p) $stmt->execute($p);
    echo "Priorities seeded.\n";

    // Seed ticket types
    $types = [
        ['Bug', 'bug', 'fa-bug', '#ff3b30', 1, 0, 1],
        ['Feature Request', 'feature-request', 'fa-lightbulb', '#ff9f0a', 2, 0, 1],
        ['Question', 'question', 'fa-question-circle', '#0a84ff', 3, 1, 1],
        ['Task', 'task', 'fa-tasks', '#34c759', 4, 0, 1]
    ];
    $stmt = $pdo->prepare("INSERT INTO ticket_types (name, slug, icon, color, sort_order, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($types as $t) $stmt->execute($t);
    echo "Ticket types seeded.\n";

    // Seed default settings
    $appName = getenv('APP_NAME') ?: 'FoxDesk';
    $appUrl  = getenv('APP_URL') ?: 'http://localhost:8888';
    $settings = [
        ['app_name', $appName],
        ['app_url', $appUrl],
        ['app_language', 'en'],
        ['time_format', '24'],
        ['currency', 'CZK'],
        ['billing_rounding', '15'],
        ['smtp_host', ''],
        ['smtp_port', '587'],
        ['smtp_encryption', 'tls'],
        ['smtp_user', ''],
        ['smtp_pass', ''],
        ['smtp_from_email', ''],
        ['smtp_from_name', $appName],
        ['email_notifications_enabled', '0'],
        ['notify_on_status_change', '1'],
        ['notify_on_new_comment', '1'],
        ['notify_on_new_ticket', '1'],
        ['max_upload_size', '10'],
        ['app_logo', ''],
        ['favicon', ''],
        ['update_check_enabled', '1'],
        ['update_check_dismissed_version', '']
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $s) $stmt->execute($s);
    echo "Default settings seeded.\n";

    // Seed email templates
    $templates = [
        ['status_change', 'Status changed for ticket #{ticket_id}: {ticket_title}', "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\nPrevious status: {old_status}\nNew status: {new_status}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"],
        ['new_comment', 'New comment on ticket #{ticket_id}: {ticket_title}', "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\nFrom: {commenter_name}\n\n---\n{comment_text}\n---\n\nView comment: {comment_url}\n\nRegards,\n{app_name}"],
        ['new_ticket', 'New ticket #{ticket_id}: {ticket_title}', "Hello,\n\nA new ticket has been created.\n\nSubject: {ticket_title}\nPriority: {priority}\nFrom: {user_name} ({user_email})\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"],
        ['password_reset', 'Password reset', "Hello,\n\nYou requested a password reset. Click the link below:\n{reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.\n\nRegards,\n{app_name}"]
    ];
    $stmt = $pdo->prepare("INSERT INTO email_templates (template_key, subject, body, is_active) VALUES (?, ?, ?, 1)");
    foreach ($templates as $t) $stmt->execute($t);
    echo "Email templates seeded.\n";

    echo "Setup complete!\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
