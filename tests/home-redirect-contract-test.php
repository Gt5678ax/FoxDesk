<?php

$root = dirname(__DIR__);

function assert_home_redirect_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$files = [
    'index.php',
    'includes/security-helpers.php',
    'pages/login.php',
    'pages/forgot-password.php',
    'pages/reset-password.php',
    'pages/user-profile.php',
    'pages/new-ticket.php',
    'pages/admin/agent-connect.php',
    'pages/admin/reports.php',
    'pages/admin/migration-export.php',
];

$composed_surfaces = [
    'pages/new-ticket.php' => [
        'includes/components/new-ticket-form.php',
        'includes/components/new-ticket-assets.php',
    ],
    'pages/admin/reports.php' => [
        'includes/modules/reports/report-page-controller.php',
    ],
];

$read_surface = static function (string $file) use ($root, $composed_surfaces): string {
    $contents = file_get_contents($root . '/' . $file);
    assert_home_redirect_contract($contents !== false, $file . ' must be readable.');

    foreach ($composed_surfaces[$file] ?? [] as $component) {
        $component_contents = file_get_contents($root . '/' . $component);
        assert_home_redirect_contract($component_contents !== false, $component . ' must be readable.');
        $contents .= "\n" . $component_contents;
    }

    return $contents;
};

foreach ($files as $file) {
    $contents = $read_surface($file);
    assert_home_redirect_contract(
        strpos($contents, "header('Location: index.php?page=dashboard');") === false,
        $file . ' must not hard-code dashboard as a fallback redirect.'
    );
}

foreach ($files as $file) {
    $contents = $read_surface($file);
    assert_home_redirect_contract(
        strpos($contents, 'foxdesk_authenticated_home_page') !== false,
        $file . ' should use authenticated home routing for fallback redirects.'
    );
}

echo "Home redirect contract OK\n";
