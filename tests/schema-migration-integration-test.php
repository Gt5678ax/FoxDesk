<?php
if (getenv('FOXDESK_SCHEMA_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set FOXDESK_SCHEMA_INTEGRATION=1 to run this temporary-database test.\n");
    exit(2);
}

$host = getenv('FOXDESK_SCHEMA_DB_HOST') ?: 'db';
$port = (int) (getenv('FOXDESK_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('FOXDESK_SCHEMA_DB_USER') ?: 'root';
$pass = getenv('FOXDESK_SCHEMA_DB_PASS') ?: 'rootpass';
$database = 'foxdesk_self_schema_' . bin2hex(random_bytes(5));
$root = dirname(__DIR__);
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$split = static function (string $sql): array {
    $statements = [];
    $buffer = '';
    $quote = null;
    $escaped = false;
    for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;
        if ($escaped) {
            $escaped = false;
            continue;
        }
        if ($char === '\\' && $quote !== null) {
            $escaped = true;
            continue;
        }
        if (($char === "'" || $char === '"') && ($quote === null || $quote === $char)) {
            $quote = $quote === null ? $char : null;
            continue;
        }
        if ($char === ';' && $quote === null) {
            $statements[] = trim(substr($buffer, 0, -1));
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return array_values(array_filter($statements, static fn(string $statement): bool => $statement !== ''));
};

try {
    $db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($split((string) file_get_contents($root . '/includes/schema.sql')) as $statement) {
        $db->exec($statement);
    }
    // Recreate the previous stable schema so the locale-width migration is
    // exercised instead of only checking a clean install that is already wide.
    $db->exec("ALTER TABLE users MODIFY language VARCHAR(5) DEFAULT 'en'");
    $db->exec("ALTER TABLE email_templates MODIFY language VARCHAR(5) DEFAULT 'en'");
    $db->exec("ALTER TABLE report_templates MODIFY report_language VARCHAR(5) DEFAULT 'en'");
    $db->exec("INSERT INTO statuses (id, name, slug, color, sort_order, is_default, is_closed) VALUES
        (10, 'Done', 'done', '#34c759', 5, 0, 1),
        (11, 'Cancelled', 'cancelled', '#ff3b30', 6, 0, 1)");
    $db->exec("INSERT INTO users (id, email, password, first_name, role) VALUES
        (1, 'schema-test@example.test', 'not-a-login-hash', 'Schema', 'admin')");
    $db->exec("INSERT INTO tickets (id, hash, title, user_id, status_id) VALUES
        (1, 'schematest000001', 'Canonical status migration', 1, 11)");
    $db->exec("INSERT INTO ticket_history (ticket_id, user_id, field_name, old_value, new_value) VALUES
        (1, 1, 'status_id', '10', '11')");
    $db->exec("INSERT INTO recurring_tasks (id, title, status_id, start_date) VALUES
        (1, 'Canonical recurring status migration', 11, CURRENT_DATE())");
    $files = array_merge(glob($root . '/migrations/*.sql') ?: [], glob($root . '/migrations/*.php') ?: []);
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        if (str_ends_with($file, '.php')) {
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException(basename($file) . ' did not return a callable.');
            }
            $migration($db);
        } else {
            foreach ($split((string) file_get_contents($file)) as $statement) {
                $db->exec($statement);
            }
        }
    }

    $requirements = [
        'users' => ['remember_token', 'notification_preferences', 'is_ai_agent', 'ai_model'],
        'recurring_tasks' => ['due_days', 'paused_at', 'resume_date', 'tags'],
        'report_templates' => ['custom_billable_rate', 'expires_at', 'schedule_enabled', 'agent_ids'],
        'email_ingest_logs' => ['sender_email', 'subject', 'ticket_id'],
        'email_provider_connections' => ['tenant_id', 'client_secret_ciphertext', 'access_token_ciphertext', 'refresh_token_ciphertext'],
        'notifications' => ['actor_id', 'data', 'is_resolved'],
        'ticket_time_entries' => ['worked_on', 'time_precision'],
    ];
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException("Missing {$table}.{$column} after install/migration.");
            }
        }
    }
    foreach ([
        ['users', 'language'],
        ['email_templates', 'language'],
        ['report_templates', 'report_language'],
    ] as [$table, $column]) {
        $stmt = $db->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() !== 35) {
            throw new RuntimeException("{$table}.{$column} was not widened to VARCHAR(35).");
        }
    }
    $userLanguage = $db->query("SHOW COLUMNS FROM users LIKE 'language'")->fetch(PDO::FETCH_ASSOC);
    if (($userLanguage['Null'] ?? '') !== 'YES'
        || !array_key_exists('Default', $userLanguage)
        || $userLanguage['Default'] !== null) {
        throw new RuntimeException('users.language must allow NULL workspace inheritance: ' . json_encode($userLanguage));
    }
    foreach (['recurring_task_runs', 'ticket_history', 'agent_client_billable_rates', 'push_subscriptions', 'pending_deletions', 'email_provider_connections'] as $table) {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException("Missing table {$table} after install/migration.");
        }
    }
    $db->exec("INSERT INTO recurring_task_runs (recurring_task_id, ticket_id, status, error_message, created_at)
        VALUES (1, NULL, 'success', NULL, NOW())");
    if ((int) $db->lastInsertId() < 1) {
        throw new RuntimeException('Recurring task run cannot be written using the runtime contract.');
    }
    $closedStatuses = $db->query('SELECT COUNT(*) FROM statuses WHERE is_closed = 1')->fetchColumn();
    if ((int) $closedStatuses !== 1) {
        throw new RuntimeException('Canonical Done migration must leave exactly one closed status.');
    }
    $doneId = (int) $db->query("SELECT id FROM statuses WHERE slug = 'done' LIMIT 1")->fetchColumn();
    if ($doneId !== 10
        || (int) $db->query('SELECT status_id FROM tickets WHERE id = 1')->fetchColumn() !== $doneId
        || (int) $db->query('SELECT status_id FROM recurring_tasks WHERE id = 1')->fetchColumn() !== $doneId
        || (string) $db->query('SELECT new_value FROM ticket_history WHERE ticket_id = 1 LIMIT 1')->fetchColumn() !== (string) $doneId) {
        throw new RuntimeException('Terminal status references were not remapped to canonical Done.');
    }
    echo "Self-hosted schema migration integration test passed.\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
