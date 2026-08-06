<?php

/**
 * FoxDesk localization catalog build and validation tool.
 *
 * Source of truth: locales/catalogs/<BCP-47>.json
 * Runtime output:  includes/lang/<BCP-47>.php
 */

$root = dirname(__DIR__);
define('BASE_PATH', $root);
require_once $root . '/includes/locale-functions.php';

$command = $argv[1] ?? '--validate';
$catalogDirectory = $root . '/locales/catalogs';
$runtimeDirectory = $root . '/includes/lang';

function i18n_fail(string $message): void
{
    fwrite(STDERR, '[i18n] ' . $message . PHP_EOL);
    exit(1);
}

function i18n_placeholders(string $value): array
{
    preg_match_all('/\{[a-zA-Z0-9_]+\}/', $value, $matches);
    $placeholders = array_values(array_unique($matches[0] ?? []));
    sort($placeholders);
    return $placeholders;
}

function i18n_catalog_json(string $locale, array $messages): string
{
    $encoded = json_encode(
        $messages,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        i18n_fail('Unable to encode ' . $locale . ' catalog as JSON.');
    }
    return $encoded . PHP_EOL;
}

function i18n_runtime_php(string $locale, array $messages): string
{
    $lines = [
        '<?php',
        '',
        '// Generated from locales/catalogs/' . $locale . '.json by bin/i18n-catalogs.php.',
        '// Edit the JSON source and run npm run i18n:build.',
        'return [',
    ];

    foreach ($messages as $key => $value) {
        $lines[] = '    ' . var_export((string) $key, true) . ' => ' . var_export((string) $value, true) . ',';
    }
    $lines[] = '];';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function i18n_load_json_catalog(string $path, string $locale): array
{
    if (!is_file($path)) {
        i18n_fail('Missing JSON catalog for ' . $locale . ': ' . $path);
    }
    $messages = json_decode((string) file_get_contents($path), true);
    if (!is_array($messages) || array_is_list($messages)) {
        i18n_fail('Catalog must be a JSON object: ' . $path);
    }
    foreach ($messages as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            i18n_fail('Catalog keys and values must be strings: ' . $path);
        }
    }
    return $messages;
}

if ($command === '--seed-from-php') {
    if (!is_dir($catalogDirectory) && !mkdir($catalogDirectory, 0775, true) && !is_dir($catalogDirectory)) {
        i18n_fail('Unable to create ' . $catalogDirectory);
    }

    $englishPath = $runtimeDirectory . '/en.php';
    $english = is_file($englishPath) ? require $englishPath : null;
    if (!is_array($english)) {
        i18n_fail('English PHP catalog is missing.');
    }

    foreach (foxdesk_locale_registry() as $locale => $metadata) {
        $runtimePath = $runtimeDirectory . '/' . $locale . '.php';
        $messages = is_file($runtimePath) ? require $runtimePath : $english;
        if (!is_array($messages)) {
            i18n_fail('Invalid PHP catalog: ' . $runtimePath);
        }
        file_put_contents(
            $catalogDirectory . '/' . $locale . '.json',
            i18n_catalog_json($locale, $messages)
        );
        echo '[i18n] Seeded ' . $locale . PHP_EOL;
    }
    exit(0);
}

$registry = foxdesk_locale_registry();
$catalogs = [];
foreach ($registry as $locale => $metadata) {
    $catalogs[$locale] = i18n_load_json_catalog(
        $catalogDirectory . '/' . $locale . '.json',
        $locale
    );
}

$english = $catalogs['en'] ?? null;
if (!is_array($english)) {
    i18n_fail('English JSON catalog is required.');
}

if ($command === '--sync-drafts') {
    foreach ($catalogs as $locale => $messages) {
        if ($locale === 'en' || foxdesk_locale_status($locale, 'self_hosted') !== 'draft') {
            continue;
        }
        $synced = [];
        foreach ($english as $key => $sourceValue) {
            $synced[$key] = array_key_exists($key, $messages) ? $messages[$key] : $sourceValue;
        }
        foreach (array_diff_key($messages, $english) as $key => $value) {
            $synced[$key] = $value;
        }
        file_put_contents(
            $catalogDirectory . '/' . $locale . '.json',
            i18n_catalog_json($locale, $synced)
        );
        echo '[i18n] Synced draft ' . $locale . PHP_EOL;
    }
    exit(0);
}

foreach ($catalogs as $locale => $messages) {
    $missing = array_diff_key($english, $messages);
    $extra = array_diff_key($messages, $english);
    if ($missing !== []) {
        i18n_fail($locale . ' is missing keys: ' . implode(', ', array_slice(array_keys($missing), 0, 10)));
    }
    if ($extra !== []) {
        i18n_fail($locale . ' has extra keys: ' . implode(', ', array_slice(array_keys($extra), 0, 10)));
    }
    foreach ($english as $key => $sourceValue) {
        if (trim((string) $messages[$key]) === '') {
            i18n_fail($locale . ' has an empty translation for key: ' . $key);
        }
        if (i18n_placeholders((string) $sourceValue) !== i18n_placeholders((string) $messages[$key])) {
            i18n_fail($locale . ' placeholder mismatch for key: ' . $key);
        }
    }
}

$pluralGroups = [];
foreach ($english as $key => $value) {
    if (preg_match('/^(.*)_(zero|one|two|few|many|other)$/', $key, $matches)) {
        $pluralGroups[$matches[1]][$matches[2]] = $key;
    }
}
foreach ($pluralGroups as $base => $categories) {
    if (!isset($categories['other'])) {
        i18n_fail('Plural group is missing _other: ' . $base);
    }
    $sourcePlaceholders = i18n_placeholders((string) $english[$categories['other']]);
    foreach ($catalogs as $locale => $messages) {
        foreach ($categories as $category => $key) {
            if (i18n_placeholders((string) $messages[$key]) !== $sourcePlaceholders) {
                i18n_fail($locale . ' plural placeholder mismatch for key: ' . $key);
            }
        }
    }
}

$catalogFiles = glob($catalogDirectory . '/*.json') ?: [];
$catalogLocales = array_map(
    static fn(string $path): string => basename($path, '.json'),
    $catalogFiles
);
sort($catalogLocales);
$registryLocales = array_keys($registry);
sort($registryLocales);
if ($catalogLocales !== $registryLocales) {
    i18n_fail('Registry and JSON catalog locale sets differ.');
}

if ($command === '--validate') {
    echo '[i18n] 24-locale catalog validation OK' . PHP_EOL;
    exit(0);
}

if (!in_array($command, ['--build', '--check'], true)) {
    i18n_fail('Usage: php bin/i18n-catalogs.php --seed-from-php|--sync-drafts|--validate|--build|--check');
}

$stale = [];
foreach ($catalogs as $locale => $messages) {
    $expected = i18n_runtime_php($locale, $messages);
    $runtimePath = $runtimeDirectory . '/' . $locale . '.php';
    if ($command === '--check') {
        $actual = is_file($runtimePath) ? (string) file_get_contents($runtimePath) : '';
        $expectedNormalized = str_replace(["\r\n", "\r"], "\n", $expected);
        $actualNormalized = str_replace(["\r\n", "\r"], "\n", $actual);
        if (!hash_equals($expectedNormalized, $actualNormalized)) {
            $stale[] = $runtimePath;
        }
        continue;
    }
    file_put_contents($runtimePath, $expected);
    echo '[i18n] Built ' . $locale . PHP_EOL;
}

if ($stale !== []) {
    i18n_fail(
        "Generated PHP catalogs are stale. Run npm run i18n:build.\n" .
        implode(PHP_EOL, $stale)
    );
}

if ($command === '--check') {
    echo '[i18n] Generated PHP catalogs are current' . PHP_EOL;
}
