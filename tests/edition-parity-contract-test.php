<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$matrix_path = $root . '/docs/EDITION_PARITY_MATRIX.md';
$assert(is_file($matrix_path), 'Self-hosted edition parity matrix must exist.');

$matrix = file_get_contents($matrix_path);
$assert($matrix !== false, 'Unable to read self-hosted edition parity matrix.');
$manifest = json_decode((string) file_get_contents($root . '/config/core-parity-manifest.json'), true);
$assert(is_array($manifest), 'Self-hosted core parity manifest must be valid JSON.');
$forbidden_paths = $manifest['forbiddenPaths'] ?? [];

foreach ([
    '| Work | shared |',
    '| Intake queues | shared internal |',
    '| Tickets | shared |',
    '| Ticket detail | shared |',
    '| New ticket | shared |',
    '| Clients | shared |',
    '| Reports | shared |',
    '| Search | shared |',
    '| Notifications | shared |',
    '| Email rendering | shared |',
    '| Installer | self-hosted |',
    '| Public updater | self-hosted |',
    '| Migration source | self-hosted |',
    '| Billing | saas |',
    '| Platform console | saas |',
] as $needle) {
    $assert(str_contains($matrix, $needle), 'Edition parity matrix is missing classification: ' . $needle);
}

foreach ([
    'pages/admin/migration-export.php',
    'install.php',
    'upgrade.php',
] as $route) {
    $assert(is_file($root . '/' . $route), 'Self-hosted repository must own ' . $route . '.');
}

foreach ([
    'pages/platform.php',
    'pages/billing.php',
    'pages/cloud.php',
    'pages/signup.php',
    'pages/stripe-webhook.php',
] as $route) {
    $assert(!is_file($root . '/' . $route), 'Self-hosted repository must not expose SaaS-only route ' . $route . '.');
    $assert(str_contains($matrix, $route), 'Self-hosted exclusion list must name ' . $route . '.');
    $assert(in_array($route, $forbidden_paths, true), 'Core parity manifest must reject SaaS-only route ' . $route . '.');
}

foreach ([
    'includes/tenant-functions.php',
    'includes/billing-functions.php',
    'includes/signup-functions.php',
    'includes/automation-usage-functions.php',
    'includes/storage-functions.php',
    'includes/email-routing-functions.php',
    'includes/marketing-events.php',
    'includes/api/migration-handler.php',
    'includes/modules/agent/pairing.php',
    'includes/modules/agent/thread-report.php',
] as $module) {
    $assert(!is_file($root . '/' . $module), 'Self-hosted repository must not contain SaaS-only module ' . $module . '.');
    $assert(str_contains($matrix, $module), 'Self-hosted exclusion list must name ' . $module . '.');
    $assert(in_array($module, $forbidden_paths, true), 'Core parity manifest must reject SaaS-only module ' . $module . '.');
}

foreach ([
    '`mine`, `unassigned`, `overdue`, `waiting`, `done_today`',
    '`triage`, `customer_replies`, `email_imports`',
    '`open`, `waiting`, `done`, `all`, `archived`',
    'No random client fallback',
    'One user action creates at most one meaningful email',
] as $needle) {
    $assert(str_contains($matrix, $needle), 'Self-hosted parity matrix is missing shared behavior: ' . $needle);
}

echo "Self-hosted edition parity contract OK\n";
