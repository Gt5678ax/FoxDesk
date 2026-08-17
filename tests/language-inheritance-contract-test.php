<?php

$root = dirname(__DIR__);
define('BASE_PATH', $root);

$GLOBALS['language_contract_workspace'] = 'fr';
function get_setting($key, $default = null) {
    return $key === 'app_language' ? $GLOBALS['language_contract_workspace'] : $default;
}

require_once $root . '/includes/locale-functions.php';

$assert = static function ($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(foxdesk_workspace_language() === 'fr', 'Workspace language must be read from app_language.');
$assert(foxdesk_user_language_override(['language' => null]) === null, 'NULL must inherit the workspace language.');
$assert(foxdesk_user_language_override(['language' => 'de']) === 'de', 'A supported user locale must remain explicit.');
$assert(foxdesk_effective_user_language(['language' => null]) === 'fr', 'An inherited user must resolve to the workspace language.');
$assert(foxdesk_effective_user_language(['language' => 'de']) === 'de', 'An explicit user language must override the workspace.');

$functions = file_get_contents($root . '/includes/functions.php');
$profile = file_get_contents($root . '/pages/profile.php');
$settings = file_get_contents($root . '/includes/modules/settings/settings-actions.php');
$schema = file_get_contents($root . '/includes/schema.sql');
$auth = file_get_contents($root . '/includes/auth.php');

$assert(!str_contains($functions, 'db_update(\'users\', [\'language\' => $requested]'), 'GET ?lang must never persist a profile preference.');
$assert(str_contains($profile, '<option value=""'), 'Profile must expose workspace inheritance.');
$assert(str_contains($settings, "['language' => null]"), 'Workspace save must be able to return the current user to inheritance.');
$assert(str_contains($schema, 'language VARCHAR(35) NULL DEFAULT NULL'), 'New installations must default users to workspace inheritance.');
$assert(str_contains($auth, '$language = null'), 'New users must inherit the workspace language by default.');

$catalog = json_decode((string) file_get_contents($root . '/locales/catalogs/fr.json'), true);
$assert(($catalog['Use workspace default ({language})'] ?? '') !== '', 'French inheritance copy is missing.');
$assert(str_contains((string) $catalog['Use workspace default ({language})'], '{language}'), 'Translated inheritance copy lost its placeholder.');

echo "Language inheritance contract passed.\n";
