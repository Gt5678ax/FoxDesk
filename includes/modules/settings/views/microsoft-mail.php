<?php
/** Microsoft 365 mail connection card. Included inside the email settings form. */
$microsoft_connected = !empty($microsoft_mail_view['connected']);
$microsoft_status_class = $microsoft_connected ? 'badge-success' : 'badge-neutral';
?>
<div class="card card-body mb-2" data-testid="microsoft-mail-settings">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3 mb-4">
        <div class="max-w-2xl">
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <h3 class="font-semibold text-theme-primary"><?php echo e(t('Microsoft 365 / Outlook')); ?></h3>
                <span class="badge <?php echo $microsoft_status_class; ?>">
                    <?php echo e(t($microsoft_connected ? 'Connected' : 'Not connected')); ?>
                </span>
            </div>
            <p class="text-sm text-theme-muted">
                <?php echo e(t('Connect securely with OAuth2. Password-based Outlook sign-in is not supported.')); ?>
            </p>
        </div>
        <?php if ($microsoft_connected): ?>
            <div class="text-sm lg:text-right">
                <div class="font-medium text-theme-primary"><?php echo e((string) $microsoft_mail_view['mailbox_email']); ?></div>
                <?php if (!empty($microsoft_mail_view['last_sync_at'])): ?>
                    <div class="text-xs text-theme-muted">
                        <?php echo e(t('Last mailbox sync: {time}', ['time' => (string) $microsoft_mail_view['last_sync_at']])); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$microsoft_connected): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Microsoft tenant')); ?></label>
                <input type="text" name="microsoft_tenant_identifier" class="form-input"
                    value="<?php echo e((string) ($microsoft_mail_view['tenant_identifier'] ?? 'common')); ?>"
                    placeholder="common">
                <p class="text-xs mt-1 text-theme-muted"><?php echo e(t('Use common, organizations, or your Microsoft tenant ID.')); ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Mailbox account')); ?></label>
                <input type="email" name="microsoft_login_hint" class="form-input" placeholder="support@example.com">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Client ID')); ?></label>
                <input type="text" name="microsoft_client_id" class="form-input"
                    value="<?php echo e((string) ($microsoft_mail_view['client_id'] ?? '')); ?>" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Client secret')); ?></label>
                <input type="password" name="microsoft_client_secret" class="form-input" autocomplete="new-password"
                    placeholder="<?php echo !empty($microsoft_mail_view['client_secret_set']) ? '********' : ''; ?>">
                <?php if (!empty($microsoft_mail_view['client_secret_set'])): ?>
                    <p class="text-xs mt-1 text-theme-muted"><?php echo e(t('Leave blank to keep the current secret.')); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Redirect URI')); ?></label>
            <input type="text" readonly class="form-input font-mono text-xs"
                value="<?php echo e((string) ($microsoft_mail_view['redirect_uri'] ?? '')); ?>">
            <p class="text-xs mt-1 text-theme-muted"><?php echo e(t('Add this exact URI to the Microsoft Entra app registration.')); ?></p>
        </div>
        <div class="mt-4">
            <button type="submit" name="connect_microsoft" class="btn btn-primary w-full sm:w-auto">
                <?php echo e(t('Connect Microsoft mailbox')); ?>
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
            <label class="settings-choice-card flex gap-3 p-3 border fd-rounded-control cursor-pointer">
                <input type="checkbox" name="microsoft_inbound_enabled" <?php echo !empty($microsoft_mail_view['inbound_enabled']) ? 'checked' : ''; ?>>
                <span>
                    <strong class="block text-theme-primary"><?php echo e(t('Receive mail through Microsoft')); ?></strong>
                    <span class="block text-sm text-theme-muted"><?php echo e(t('Create and update tickets from unread inbox messages.')); ?></span>
                </span>
            </label>
            <label class="settings-choice-card flex gap-3 p-3 border fd-rounded-control cursor-pointer">
                <input type="checkbox" name="microsoft_outbound_enabled" <?php echo !empty($microsoft_mail_view['outbound_enabled']) ? 'checked' : ''; ?>>
                <span>
                    <strong class="block text-theme-primary"><?php echo e(t('Send mail through Microsoft')); ?></strong>
                    <span class="block text-sm text-theme-muted"><?php echo e(t('Send FoxDesk notifications from the connected mailbox.')); ?></span>
                </span>
            </label>
        </div>
        <?php if (!empty($microsoft_mail_view['last_error'])): ?>
            <div class="settings-warning-box p-3 fd-rounded-control border text-sm mb-4">
                <?php echo e(t('Last Microsoft error: {error}', ['error' => (string) $microsoft_mail_view['last_error']])); ?>
            </div>
        <?php endif; ?>
        <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
            <button type="submit" name="save_microsoft_directions" class="btn btn-primary w-full sm:w-auto">
                <?php echo e(t('Save Microsoft settings')); ?>
            </button>
            <button type="submit" name="test_microsoft" class="btn btn-secondary w-full sm:w-auto">
                <?php echo e(t('Test Microsoft connection')); ?>
            </button>
            <button type="submit" name="disconnect_microsoft" class="btn btn-secondary w-full sm:w-auto"
                data-confirm="<?php echo e(t('Disconnect this Microsoft mailbox?')); ?>"
                onclick="return confirm(this.dataset.confirm)">
                <?php echo e(t('Disconnect')); ?>
            </button>
        </div>
    <?php endif; ?>
</div>
