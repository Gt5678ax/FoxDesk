<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contractPath = $root . '/config/shared-behavior-contract.json';
$contract = json_decode((string) file_get_contents($contractPath), true);

if (!is_array($contract)) {
    throw new RuntimeException('Shared behavior contract must be valid JSON.');
}

function parity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

parity_assert(($contract['schemaVersion'] ?? null) === 1, 'Unsupported shared behavior contract schema.');
parity_assert(($contract['reporting']['actualTimeIsNeverRounded'] ?? false) === true, 'Actual time must remain unrounded.');
parity_assert(($contract['notifications']['recipientMustBelongToCurrentWorkspace'] ?? false) === true, 'Notification recipient boundary is required.');

if (!function_exists('t')) {
    function t(string $key, array $variables = []): string
    {
        return strtr($key, array_combine(
            array_map(static fn (string $name): string => '{' . $name . '}', array_keys($variables)),
            array_map('strval', array_values($variables))
        ) ?: []);
    }
}

if (!function_exists('round_minutes_nearest')) {
    function round_minutes_nearest(int $minutes, int $increment): int
    {
        return $increment > 1 ? (int) (round($minutes / $increment) * $increment) : $minutes;
    }
}

require_once $root . '/includes/modules/reports/report-totals.php';

$tracked = ['duration_minutes' => 17, 'is_billable' => 1];
$template = ['rounding_minutes' => 15];
parity_assert(report_entry_model_minutes($tracked, $template) === 17, 'Report preview must show the 17 tracked minutes.');
parity_assert(report_entry_model_billable_minutes($tracked, $template) === 15, 'Configured rounding may affect billable time only.');
parity_assert(report_entry_model_minutes(['actual_minutes' => 23, 'duration_minutes' => 17], $template) === 23, 'Explicit actual minutes must be authoritative.');
parity_assert(report_entry_model_billable_minutes(['duration_minutes' => 17, 'is_billable' => 0], $template) === 0, 'Non-billable work must not create billable time.');

$registryPath = $root . '/' . (string) $contract['localization']['registryPath'];
$registry = json_decode((string) file_get_contents($registryPath), true);
$locales = is_array($registry) && isset($registry['locales']) && is_array($registry['locales'])
    ? $registry['locales']
    : $registry;
parity_assert(is_array($locales), 'Locale registry must contain an array of locales.');
parity_assert(count($locales) === (int) $contract['localization']['applicationLocaleCount'], 'Application locale count must match the shared contract.');

$workQueuesSource = (string) file_get_contents($root . '/includes/modules/work/work-queues.php');
foreach ($contract['workflow']['workQueues'] as $queue) {
    parity_assert(str_contains($workQueuesSource, "'" . $queue . "'"), 'Missing shared work queue: ' . $queue);
}

$ticketStatusSource = (string) file_get_contents($root . '/includes/modules/tickets/ticket-list-views.php');
foreach ($contract['workflow']['ticketViews'] as $view) {
    parity_assert(str_contains($ticketStatusSource, "'" . $view . "'"), 'Missing shared ticket view: ' . $view);
}

echo "Shared behavior contract OK\n";
