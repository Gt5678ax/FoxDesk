<?php
/**
 * Main ticket detail surface. Variables are prepared by pages/ticket-detail.php.
 */
?>
<div class="workflow-surface workflow-surface--ticket-detail ticket-detail-page"
    data-core-workflow-surface="ticket-detail"
    data-ticket-detail-surface
    data-ticket-id="<?php echo (int) $ticket_id; ?>">
    <!-- Main Content -->
    <div class="ticket-detail-main">
        <!-- Ticket Work Panel -->
        <div class="card ticket-work-panel">
            <div class="ticket-work-panel__summary min-w-0">
                <?php
                $back_ref = $_GET['ref'] ?? '';
                if ($back_ref === 'dashboard') {
                    $back_url = url('dashboard');
                } elseif ($back_ref === 'notifications') {
                    $back_url = url('notifications');
                } else {
                    $back_url = url('tickets');
                }
                ?>
                <div class="ticket-work-panel__meta">
                    <a href="<?php echo $back_url; ?>" class="inline-flex items-center gap-1 hover:underline text-theme-muted">
                        <?php echo get_icon('arrow-left', 'w-3.5 h-3.5 back-link-icon'); ?>
                        <?php echo e(t('Back')); ?>
                    </a>
                    <span><?php echo get_ticket_code($ticket_id); ?></span>
                    <?php ticket_detail_render_status_pill($ticket, $statuses); ?>
                    <?php if (!empty($ticket['is_archived'])): ?>
                        <span class="px-1.5 py-0.5 fd-rounded-pill text-[11px] font-medium bg-theme-tertiary text-theme-secondary"><?php echo e(t('Archived')); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($ticket['organization_name'])): ?>
                        <bdi><?php echo e($ticket['organization_name']); ?></bdi>
                    <?php endif; ?>
                </div>
                <h1 class="ticket-work-panel__title" dir="auto" title="<?php echo e($ticket['title']); ?>"><?php echo e($ticket['title']); ?></h1>
            </div>
            <div class="ticket-work-panel__actions" aria-label="<?php echo e(t('Primary actions')); ?>">
                <?php foreach ($ticket_primary_actions as $action): ?>
                    <?php $action_class = ticket_detail_primary_action_class($action); ?>
                    <?php $action_title = t($action['title'] ?? $action['label']); ?>
                    <?php if ($action['type'] === 'anchor'): ?>
                        <a href="<?php echo e($action['href']); ?>" class="<?php echo e($action_class); ?>"
                           title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                        </a>
                    <?php elseif ($action['type'] === 'submit'): ?>
                        <form method="post" class="ticket-primary-action-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="status_id" value="<?php echo (int) $action['status_id']; ?>">
                            <button type="submit" name="<?php echo e($action['name']); ?>" class="<?php echo e($action_class); ?>"
                                    title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                                <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                                <span><?php echo e(t($action['label'])); ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button"
                            <?php if (!empty($action['id'])): ?>id="<?php echo e($action['id']); ?>"<?php endif; ?>
                            <?php if (!empty($action['onclick'])): ?>onclick="<?php echo e($action['onclick']); ?>"<?php endif; ?>
                            <?php if (($action['key'] ?? '') === 'edit'): ?>data-ticket-edit-open<?php endif; ?>
                            title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>"
                            class="<?php echo e($action_class); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                            <?php if (($action['key'] ?? '') === 'start_work' && $timer_state !== 'stopped'): ?>
                                <span id="toolbar-timer-elapsed" class="ticket-primary-action__timer"><?php echo format_duration_minutes($active_timer_elapsed); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Description Card -->
        <?php $initial_attachments = ticket_detail_initial_attachments($attachments); ?>
        <?php if (!empty($ticket['description']) || !empty($initial_attachments)): ?>
                <div class="card card-body">
                    <?php if (!empty($ticket['description'])): ?>
                            <div class="prose max-w-none rich-content text-theme-secondary" dir="auto">
                                <?php echo render_content($ticket['description']); ?>
                            </div>
                    <?php endif; ?>

                    <?php if (!empty($initial_attachments)): ?>
                            <div class="<?php echo !empty($ticket['description']) ? 'mt-4 pt-4 border-t' : ''; ?>">
                                <h4 class="text-sm font-medium mb-1 text-theme-secondary">
                                    <?php echo e(t('Attachments')); ?></h4>
                                <?php $component_attachments = $initial_attachments; $component_layout = 'grid'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                            </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-2.5 border-t flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-xs text-theme-muted">
                        <div class="flex items-center gap-3">
                            <span class="ticket-meta-avatar" aria-hidden="true">
                                <span class="ticket-meta-avatar__initial"><?php echo e($ticket_creator_initial); ?></span>
                            </span>
                            <span><?php echo e(t('Created by')); ?>:
                                <?php if (is_agent()): ?>
                                        <a href="<?php echo url('user-profile', ['id' => $ticket['user_id']]); ?>"
                                            class="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                                            <?php echo e($ticket_creator_name); ?>
                                        </a>
                                <?php else: ?>
                                        <strong><?php echo e($ticket_creator_name); ?></strong>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div>
                            <?php echo format_date($ticket['created_at']); ?>
                        </div>
                    </div>

                    <?php
                    // Show edit history only to users explicitly allowed (admins always allowed)
                    $ticket_history = $can_view_edit_history ? get_ticket_history($ticket_id) : [];
                    if ($can_view_edit_history && !empty($ticket_history)):
                        ?>
                            <details class="mt-4 pt-4 border-t">
                                <summary class="flex items-center gap-2 cursor-pointer text-sm text-theme-muted">
                                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                                    <?php echo e(t('Edit history')); ?> (<?php echo count($ticket_history); ?>)
                                </summary>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($ticket_history as $history): ?>
                                            <?php
                                            $is_long_text_change = in_array($history['field_name'], ['description', 'comment_content', 'comment_deleted'], true);
                                            $is_attachment_event = in_array($history['field_name'], ['attachment_added', 'attachment_unlinked'], true);
                                            ?>
                                            <div class="flex items-start gap-3 text-xs p-2 rounded-lg bg-theme-secondary">
                                                <div class="flex-shrink-0 w-6 h-6 fd-rounded-pill flex items-center justify-center bg-theme-tertiary">
                                                    <span class="font-medium text-xs text-theme-secondary">
                                                        <?php echo strtoupper(substr($history['first_name'] ?? 'U', 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center gap-1 text-theme-secondary">
                                                        <strong><?php echo e(($history['first_name'] ?? '') . ' ' . ($history['last_name'] ?? '')); ?></strong>
                                                        <span><?php echo e(t('changed')); ?></span>
                                                        <span
                                                            class="font-medium"><?php echo get_history_field_label($history['field_name']); ?></span>
                                                    </div>
                                                    <?php if ($is_long_text_change): ?>
                                                            <div class="mt-2 space-y-2">
                                                                <div class="rounded border border-red-200 bg-red-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-red-700 mb-1">
                                                                        <?php echo e(t('Previous')); ?></div>
                                                                    <div class="text-xs text-red-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['old_value']); ?>
                                                                    </div>
                                                                </div>
                                                                <div class="rounded border border-green-200 bg-green-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-green-700 mb-1">
                                                                        <?php echo e(t('New')); ?></div>
                                                                    <div class="text-xs text-green-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['new_value']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                    <?php elseif ($is_attachment_event): ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-theme-muted">
                                                                <?php if ($history['field_name'] === 'attachment_added'): ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-medium">+
                                                                            <?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                                <?php else: ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">-
                                                                            <?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                    <?php else: ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-theme-muted">
                                                                <span
                                                                    class="line-through"><?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <span>→</span>
                                                                <span class="font-medium text-theme-secondary"><?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div class="mt-1 text-theme-muted">
                                                        <?php echo format_date($history['created_at']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                    <?php endif; ?>
                </div>
        <?php endif; ?>

        <?php
        $time_entries = ($time_tracking_available && can_view_time($user)) ? $ticket_detail_context['time_entries'] : [];
        $ticket_timeline = ticket_detail_build_timeline($comments, $time_entries);
        $time_entries_by_comment = $ticket_timeline['time_entries_by_comment'];
        $timeline_items = $ticket_timeline['timeline_items'];
        ?>

        <!-- Comments & Time Log Combined -->
        <div class="card">
            <div class="card-header">
                <h3 class="font-semibold text-theme-primary"><?php echo e(t('Activity')); ?>
                    (<?php echo e(tn('comment.count', count($comments))); ?>)</h3>
                <?php if ($time_tracking_available && $total_time_minutes > 0 && can_view_time($user)): ?>
                        <span
                            class="text-xs font-semibold px-2 py-1 bg-blue-50 text-blue-700 rounded flex items-center gap-1">
                            <?php echo get_icon('clock', 'w-3 h-3'); ?>
                            <?php echo format_duration_minutes($total_time_minutes); ?>
                        </span>
                <?php endif; ?>
            </div>

            <?php if (empty($timeline_items)): ?>
                    <div class="p-4 text-center text-theme-muted">
                        <?php echo e(t('No comments yet.')); ?>
                    </div>
            <?php else: ?>
                    <div class="divide-y border-theme-light">
                        <?php foreach ($timeline_items as $timeline_item): ?>
                                <?php if ($timeline_item['type'] === 'time_entry'): ?>
                                        <?php $entry = $timeline_item['data']; ?>
                                        <?php if (can_view_time($user)): ?>
                                                <div class="flex justify-center py-2.5">
                                                    <div class="time-entry-row inline-flex flex-wrap items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
                                                        style="background: var(--surface-secondary); color: var(--text-muted);">
                                                        <?php echo get_icon('clock', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                                                        <span class="font-medium text-theme-secondary"><?php
                                                        if (empty($entry['ended_at'])) {
                                                            $elapsed = max(0, time() - strtotime($entry['started_at']));
                                                            if (!empty($entry['paused_at'])) {
                                                                $elapsed = max(0, strtotime($entry['paused_at']) - strtotime($entry['started_at']));
                                                            }
                                                            $elapsed -= (int) ($entry['paused_seconds'] ?? 0);
                                                            echo format_duration_minutes(max(0, floor($elapsed / 60)));
                                                            if (!empty($entry['paused_at'])) {
                                                                echo ' <span class="text-yellow-600">(' . t('Paused') . ')</span>';
                                                            } else {
                                                                echo ' <span class="text-green-600">(' . t('Running') . ')</span>';
                                                            }
                                                        } else {
                                                            echo format_duration_minutes($entry['duration_minutes']);
                                                        }
                                                        ?></span>
                                                        <span style="color: var(--border-light);">·</span>
                                                        <span><?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></span>
                                                        <?php if (!empty($entry['summary'])): ?>
                                                                <span style="color: var(--border-light);">·</span>
                                                                <span class="truncate max-w-[200px]"
                                                                    title="<?php echo e($entry['summary']); ?>"><?php echo e($entry['summary']); ?></span>
                                                        <?php endif; ?>
                                                        <span style="color: var(--border-light);">·</span>
                                                        <span><?php echo e(ticket_time_entry_display_date($entry)); ?></span>
                                                        <?php $can_edit_this_entry = is_admin() || (is_agent() && (int) $entry['user_id'] === (int) $user['id']); ?>
                                                        <?php if ($can_edit_this_entry): ?>
                                                                <span class="time-entry-actions" data-time-entry-id="<?php echo (int) $entry['id']; ?>">
                                                                    <?php if (!empty($entry['ended_at'])): ?>
                                                                            <button type="button"
                                                                                onclick="openEditTimeEntry(<?php echo htmlspecialchars(json_encode($entry)); ?>)"
                                                                                class="p-0.5 hover:text-blue-600 transition text-theme-muted"
                                                                                title="<?php echo e(t('Edit')); ?>">
                                                                                <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                            </button>
                                                                    <?php endif; ?>
                                                                        <button type="button"
                                                                            class="p-0.5 hover:text-red-500 transition text-theme-muted"
                                                                            title="<?php echo e(t('Delete')); ?>"
                                                                            onclick="deleteTimeEntry(<?php echo (int) $entry['id']; ?>)">
                                                                            <?php echo get_icon('trash', 'w-3 h-3'); ?>
                                                                        </button>
                                                                </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <?php endif; ?>
                                <?php else: ?>
                                        <?php $comment = $timeline_item['data']; ?>
                                        <?php
                                        $comment_attachments = ticket_detail_comment_attachments($attachments, (int) $comment['id']);
                                        $is_own_comment = ((int) $comment['user_id'] === (int) $user['id']);
                                        ?>
                                        <div id="comment-<?php echo $comment['id']; ?>"
                                            class="comment-item group px-4 lg:px-5 py-4 transition-colors hover:bg-[var(--surface-secondary)]/40 <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="flex gap-3">
                                                <!-- Avatar -->
                                                <?php echo render_user_avatar($comment, 'md', 'mt-0.5 ' . ($is_own_comment ? 'ticket-comment__avatar--own' : '')); ?>

                                                <!-- Content -->
                                                <div class="flex-1 min-w-0">
                                                    <!-- Header: name + badges + timestamp + actions -->
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-semibold text-sm text-theme-primary">
                                                            <?php echo e($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                                        </span>
                                                        <?php if ($is_own_comment): ?>
                                                                <span class="text-xs px-1.5 py-0.5 rounded font-medium"
                                                                    style="background: var(--primary-soft); color: var(--primary);"><?php echo e(t('You')); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                                <span
                                                                    class="text-xs px-1.5 py-0.5 rounded font-medium bg-amber-50 text-amber-700"><?php echo e(t('Internal')); ?></span>
                                                        <?php endif; ?>
                                                        <span class="text-xs text-theme-muted"><?php
                                                        echo !empty($comment['work_date'])
                                                            ? e(format_date($comment['work_date'], 'd.m.Y'))
                                                            : e(format_date($comment['created_at']));
                                                        ?></span>
                                                        <?php if ($can_view_edit_history && !empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                                                <span class="text-xs italic text-theme-muted">(<?php echo e(t('edited')); ?>)</span>
                                                        <?php endif; ?>

                                                        <!-- Edit/Delete actions (visible on hover) -->
                                                        <?php if (is_admin() || (is_agent() && (int) $comment['user_id'] === (int) $user['id'])): ?>
                                                                <div class="comment-actions">
                                                                    <button type="button"
                                                                        onclick="openEditCommentModal(<?php echo $comment['id']; ?>, <?php echo htmlspecialchars(json_encode($comment['content']), ENT_QUOTES, 'UTF-8'); ?>)"
                                                                        class="hover:text-blue-600 p-1 rounded transition text-theme-muted" title="<?php echo e(t('Edit comment')); ?>">
                                                                        <?php echo get_icon('pencil', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                    <button type="button" onclick="deleteComment(<?php echo $comment['id']; ?>)"
                                                                        class="hover:text-red-600 p-1 rounded transition text-theme-muted" title="<?php echo e(t('Delete comment')); ?>">
                                                                        <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Comment body -->
                                                    <div class="break-words rich-content text-sm" dir="auto"
                                                        id="comment-content-<?php echo $comment['id']; ?>"
                                                        style="color: var(--text-secondary);">
                                                        <?php echo render_content($comment['content']); ?>
                                                    </div>

                                                    <!-- Attachments -->
                                                    <?php if (!empty($comment_attachments)): ?>
                                                        <?php $component_attachments = $comment_attachments; $component_layout = 'inline'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                                                    <?php endif; ?>

                                                    <?php
                                                    // Linked time entries (detail rows)
                                                    $comment_time_entries = $time_entries_by_comment[$comment['id']] ?? [];
                                                    $comment_linked_time = 0;
                                                    foreach ($comment_time_entries as $te) {
                                                        $comment_linked_time += (int) ($te['duration_minutes'] ?? 0);
                                                    }
                                                    // Show summary badge only if NO detailed entries (fallback for old time_spent)
                                                    $display_time = $comment_linked_time > 0 ? 0 : ($comment['time_spent'] ?? 0);
                                                    if ($display_time > 0 && can_view_time($user)): ?>
                                                            <div class="mt-2 inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded-md"
                                                                style="background: var(--surface-secondary); color: var(--text-muted);">
                                                                <?php echo get_icon('clock', 'w-3 h-3'); ?>
                                                                <span><?php echo e(format_duration_minutes($display_time)); ?></span>
                                                            </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($comment_time_entries) && can_view_time($user)): ?>
                                                            <div class="mt-2 space-y-1.5">
                                                                <?php foreach ($comment_time_entries as $entry): ?>
                                                                        <?php $can_edit_this_entry = is_admin() || (is_agent() && (int) $entry['user_id'] === (int) $user['id']); ?>
                                                                        <div class="time-entry-row inline-flex flex-wrap items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
                                                                            style="background: var(--surface-secondary); color: var(--text-muted);">
                                                                            <?php echo get_icon('clock', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                                                                            <span class="font-medium text-theme-secondary"><?php
                                                                            if (empty($entry['ended_at'])) {
                                                                                echo format_duration_minutes(max(0, (int) floor(calculate_timer_elapsed($entry) / 60)));
                                                                                if (!empty($entry['paused_at'])) {
                                                                                    echo ' <span class="text-yellow-600">(' . t('Paused') . ')</span>';
                                                                                } else {
                                                                                    echo ' <span class="text-green-600">(' . t('Running') . ')</span>';
                                                                                }
                                                                            } else {
                                                                                echo format_duration_minutes($entry['duration_minutes']);
                                                                            }
                                                                            ?></span>
                                                                            <span style="color: var(--border-light);">·</span>
                                                                            <span><?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></span>
                                                                            <?php if (!empty($entry['summary'])): ?>
                                                                                    <span style="color: var(--border-light);">·</span>
                                                                                    <span class="truncate max-w-[200px]"
                                                                                        title="<?php echo e($entry['summary']); ?>"><?php echo e($entry['summary']); ?></span>
                                                                            <?php endif; ?>
                                                                            <span style="color: var(--border-light);">·</span>
                                                                            <span><?php echo e(ticket_time_entry_display_date($entry)); ?></span>
                                                                            <?php if ($can_edit_this_entry): ?>
                                                                                    <span class="time-entry-actions" data-time-entry-id="<?php echo (int) $entry['id']; ?>">
                                                                                        <?php if (!empty($entry['ended_at'])): ?>
                                                                                                <button type="button"
                                                                                                    onclick="openEditTimeEntry(<?php echo htmlspecialchars(json_encode($entry)); ?>)"
                                                                                                    class="p-0.5 hover:text-blue-600 transition text-theme-muted" title="<?php echo e(t('Edit time')); ?>">
                                                                                                    <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                                                </button>
                                                                                        <?php endif; ?>
                                                                                            <button type="button"
                                                                                                class="p-0.5 hover:text-red-500 transition text-theme-muted"
                                                                                                title="<?php echo e(t('Delete time')); ?>"
                                                                                                onclick="deleteTimeEntry(<?php echo (int) $entry['id']; ?>)">
                                                                                                <?php echo get_icon('trash', 'w-3 h-3'); ?>
                                                                                            </button>
                                                                                    </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
            <?php endif; ?>

            <?php include BASE_PATH . '/includes/components/ticket-detail-composer.php'; ?>

        </div>
    </div>

    <?php include BASE_PATH . '/includes/components/ticket-detail-sidebar.php'; ?>
</div>

<?php include BASE_PATH . '/includes/components/ticket-detail-modals.php'; ?>
