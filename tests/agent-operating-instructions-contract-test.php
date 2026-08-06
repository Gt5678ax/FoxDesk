<?php

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/includes/locale-functions.php';
require BASE_PATH . '/includes/modules/agent/operating-instructions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$requiredKeys = [
    'Agent instructions: FoxDesk tickets',
    'Use only the FoxDesk Agent API. Never use a web browser.',
    'At the start of every session, call agent-docs and then verify your identity with agent-me.',
    'Before changing an existing ticket, always read its current state with agent-get-ticket.',
    'Every POST request must include a unique Idempotency-Key.',
    'Use agent-add-update for a comment without tracked time.',
    'Use agent-add-work-entry when the work must count toward tracked or billable time.',
    'Each tracked work comment has a matching time entry with a non-null comment_id.',
    'total_time_minutes matches the sum of saved time entries.',
];

foreach (foxdesk_agent_instruction_languages() as $language) {
    $catalog = require BASE_PATH . '/includes/lang/' . $language . '.php';
    foreach ($requiredKeys as $key) {
        $assert(isset($catalog[$key]) && trim((string) $catalog[$key]) !== '', "{$language} is missing: {$key}");
    }

    $instructions = foxdesk_agent_operating_instructions($language, ['language' => $language]);
    $assert($instructions['language'] === $language, "{$language} instructions use the wrong language.");
    $assert($instructions['machine_rules']['required_first_actions'] === ['agent-docs', 'agent-me'], 'Session bootstrap is incorrect.');
    $assert($instructions['machine_rules']['read_before_write_action'] === 'agent-get-ticket', 'Read-before-write is missing.');
    $assert($instructions['machine_rules']['post_requires_unique_idempotency_key'] === true, 'POST idempotency is not required.');
    $assert($instructions['schema_version'] === 5, 'Agent instruction schema is stale.');
    $assert($instructions['machine_rules']['comment_only_action'] === 'agent-add-update', 'Comment-only action is incorrect.');
    $assert($instructions['machine_rules']['tracked_work_action'] === 'agent-add-work-entry', 'Tracked-work action is incorrect.');
    $assert($instructions['machine_rules']['multi_entry_plan_action'] === 'agent-plan-work-log', 'Multi-entry preview action is incorrect.');
    $assert($instructions['machine_rules']['multi_entry_apply_action'] === 'agent-apply-work-log-plan', 'Multi-entry apply action is incorrect.');
    $assert($instructions['machine_rules']['multi_entry_requires_preview_confirmation'] === true, 'Multi-entry preview confirmation is required.');
    $assert($instructions['machine_rules']['tracked_work_requires_linked_comment_id'] === true, 'Tracked work does not require a linked comment.');
    $assert($instructions['machine_rules']['expected_total_time_rule'] === 'sum(time_entries.duration_minutes)', 'Tracked time total rule is incorrect.');
    $assert(str_contains($instructions['daily_entries']['example_html'], '<ul>'), 'Daily example must preserve an HTML list.');
    $assert(!preg_match('/20\d{2}/', $instructions['daily_entries']['example_html']), 'Daily example must keep dates out of prose.');
}

$handler = file_get_contents(BASE_PATH . '/includes/api/agent-handler.php');
$router = file_get_contents(BASE_PATH . '/includes/api/router.php');
$auth = file_get_contents(BASE_PATH . '/includes/auth.php');
$workflow = file_get_contents(BASE_PATH . '/docs/AGENT_TICKET_WORKFLOW.md');

$assert(str_contains($handler, "function api_agent_docs()"), 'agent-docs handler is missing.');
$assert(str_contains($handler, "'operating_instructions' => \$operating_instructions"), 'Structured instructions are missing from agent-docs.');
$assert(str_contains($handler, "'operating_instructions_markdown'"), 'Readable Markdown instructions are missing from agent-docs.');
$assert(str_contains($handler, "\$_GET['instruction_language']"), 'Explicit instruction language is not supported.');
$assert(str_contains($router, "'agent-docs' => 'api_agent_docs'"), 'agent-docs route is missing.');
$assert(str_contains($auth, "'agent-docs' => null"), 'agent-docs is not available to every valid token.');
$assert(str_contains($handler, "'action' => 'agent-add-work-entry'"), 'Tracked-work action is missing from live docs.');
$assert(str_contains($handler, "'action' => 'agent-plan-work-log'"), 'Work-log preview action is missing from live docs.');
$assert(str_contains($handler, "'action' => 'agent-apply-work-log-plan'"), 'Work-log apply action is missing from live docs.');
$assert(str_contains($handler, "'action' => 'agent-delete-ticket-permanently'"), 'Permanent-delete action is missing from live docs.');
$assert(str_contains($workflow, 'non-null `comment_id`'), 'Workflow does not require linked tracked work.');
$assert(!str_contains($workflow, '`total_time_minutes` is `0`'), 'Workflow still requires zero tracked time.');
$assert(str_contains($workflow, '`worked_on`'), 'Workflow must keep the date in a structured field.');

echo "Agent operating instructions contract OK\n";
