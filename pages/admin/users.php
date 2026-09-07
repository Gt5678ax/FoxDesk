<?php
/**
 * Admin - Users Management
 *
 * Request handling and view models live in the team module. This controller
 * only selects the surface and composes shared components.
 */

$page_title = t('Users');
$page = 'admin';

$tab = ($_GET['tab'] ?? '') === 'ai_agents' ? 'ai_agents' : 'users';
$user_table_capabilities = team_users_table_capabilities();
$ai_agent_col_exists = $user_table_capabilities['ai_agent'];

$filter_state = team_users_filter_state($_GET);
$filter_search = $filter_state['search'];
$filter_role = $filter_state['role'];
$filter_status = $filter_state['status'];

$time_range = $_GET['time_range'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$range_data = get_time_range_bounds($time_range, $from_date, $to_date);
$time_range = $range_data['range'];
$range_start = $range_data['start'];
$range_end = $range_data['end'];
$time_tracking_available = ticket_time_table_exists();
$time_totals = [];

try {
    $organizations = get_organizations(true);
} catch (Exception $e) {
    $organizations = [];
}

$valid_organization_ids = team_users_valid_organization_ids($organizations);
$organization_names_by_id = [];
foreach ($organizations as $organization) {
    $organization_names_by_id[(int) ($organization['id'] ?? 0)] = (string) ($organization['name'] ?? '');
}

$email_pref_column_exists = $user_table_capabilities['email_notifications'];
$in_app_pref_column_exists = $user_table_capabilities['in_app_notifications'];
$in_app_sound_column_exists = $user_table_capabilities['in_app_sound'];
$notification_preferences_available = $email_pref_column_exists && $in_app_pref_column_exists && $in_app_sound_column_exists;
$contact_phone_column_exists = $user_table_capabilities['contact_phone'];
$notes_column_exists = $user_table_capabilities['notes'];
$deleted_at_column_exists = $user_table_capabilities['deleted_at'];

$get_user_fk_references = team_users_fk_reference_loader();

include BASE_PATH . '/includes/modules/team/team-users-actions.php';

$users = team_users_fetch($filter_state, $user_table_capabilities);
$time_totals = $time_tracking_available ? team_users_time_totals($range_start, $range_end) : [];

$ai_agents = [];
$ai_agent_tokens = [];
if ($ai_agent_col_exists) {
    $ai_agents = team_ai_agents_fetch($deleted_at_column_exists);
    $ai_agent_tokens = team_ai_agent_tokens_fetch($ai_agents);
}

$ai_agent_token_scope_groups = team_ai_agent_token_scope_groups();
$ai_agent_token_default_scope_groups = team_ai_agent_token_default_scope_groups();
$ai_agent_token_group_scopes = [];
foreach ($ai_agent_token_scope_groups as $group_key => $group) {
    $ai_agent_token_group_scopes[$group_key] = $group['scopes'] ?? [];
}

$new_ai_token = $_SESSION['new_ai_agent_token'] ?? null;
$new_ai_agent_id = $_SESSION['new_ai_agent_id'] ?? null;
unset($_SESSION['new_ai_agent_token'], $_SESSION['new_ai_agent_id']);

require_once BASE_PATH . '/includes/header.php';

$page_header_title = $page_title;
$page_header_subtitle = '';
include BASE_PATH . '/includes/components/page-header.php';

if ($ai_agent_col_exists) {
    include BASE_PATH . '/includes/components/team/team-tabs.php';
}

if ($tab === 'ai_agents' && $ai_agent_col_exists) {
    include BASE_PATH . '/includes/components/team/ai-agents-surface.php';
} else {
    include BASE_PATH . '/includes/components/team/users-surface.php';
    include BASE_PATH . '/includes/components/team/user-edit-surface.php';
}

require_once BASE_PATH . '/includes/footer.php';
