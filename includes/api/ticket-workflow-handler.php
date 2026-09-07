<?php
function api_ticket_workflow(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Method not allowed', 405);
    require_csrf_token(true);
    $user = current_user();
    if (!$user || !in_array($user['role'] ?? '', ['agent', 'admin'], true)) api_error('Forbidden', 403);
    if (!empty($GLOBALS['is_api_token_auth']) && (!api_token_has_scope('tickets:read') || !api_token_has_scope('tickets:write'))) api_error('Forbidden', 403);
    $input = !empty($_POST) ? $_POST : get_json_input();
    $ticket = !empty($input['ticket_hash']) ? get_ticket_by_hash((string) $input['ticket_hash']) : get_ticket((int) ($input['ticket_id'] ?? 0));
    if (!$ticket || !can_see_ticket($ticket, $user)) api_error('Ticket not found', 404);
    try {
        $result = ticket_workflow_apply($ticket, $user, $input);
    } catch (DomainException $error) {
        ticket_workflow_api_error($ticket, $user, $error);
    } catch (Throwable $error) {
        error_log('Ticket workflow failed: ' . $error->getMessage());
        api_error(t('workflow.save_failed'), 500);
    }
    api_success($result);
}
