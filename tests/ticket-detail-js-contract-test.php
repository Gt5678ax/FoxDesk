<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/ticket-detail.php');
$assetPaths = [
    'assets/js/ticket-detail-core.js',
    'assets/js/ticket-detail-workflow.js',
    'assets/js/ticket-detail-records.js',
    'assets/js/ticket-detail-admin.js',
    'assets/js/ticket-detail.js',
];
$assets = [];
foreach ($assetPaths as $assetPath) {
    $content = file_get_contents($root . '/' . $assetPath);
    if ($content === false) {
        fwrite(STDERR, 'Ticket detail JS module missing: ' . $assetPath . PHP_EOL);
        exit(1);
    }
    $assets[$assetPath] = $content;
}
$asset = implode("\n", $assets);
$paste_drop_asset = file_get_contents($root . '/assets/js/attachment-paste-drop.js');
$theme = file_get_contents($root . '/theme.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false, 'Ticket detail page must be readable.');
$assert($asset !== false, 'Ticket detail JS asset must be readable.');
$assert($paste_drop_asset !== false, 'Attachment paste/drop JS asset must be readable.');
$assert($theme !== false, 'Theme CSS must be readable.');

$has_visible_default_opacity = static function (string $css, string $selector): bool {
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{[^}]*opacity:\s*([0-9]*\.?[0-9]+)/s';
    return preg_match($pattern, $css, $matches) === 1 && (float) $matches[1] > 0;
};

$assert($has_visible_default_opacity($theme, '.comment-actions'), 'Editable comment actions must remain visible without hover.');
$assert($has_visible_default_opacity($theme, '.time-entry-actions'), 'Editable time-entry actions must remain visible without hover.');
$assert(str_contains($theme, '.comment-actions:focus-within'), 'Comment actions must become fully visible on keyboard focus.');
$assert(str_contains($theme, '.time-entry-actions:focus-within'), 'Time-entry actions must become fully visible on keyboard focus.');

foreach ([
    'window.FoxDeskTicketDetailConfig',
    'assets/js/quill-image-upload.js',
    'assets/js/attachment-paste-drop.js',
    'assets/js/autosave.js',
] as $needle) {
    $assert(str_contains($page, $needle), 'Ticket detail page missing JS contract: ' . $needle);
}

$lastPosition = -1;
foreach ($assetPaths as $assetPath) {
    $position = strpos($page, $assetPath);
    $assert($position !== false, 'Ticket detail page must load JS module: ' . $assetPath);
    $assert($position > $lastPosition, 'Ticket detail JS modules must load in dependency order: ' . $assetPath);
    $lastPosition = $position;
    $lineCount = substr_count($assets[$assetPath], "\n") + 1;
    $assert($lineCount <= 900, $assetPath . ' exceeds the 900-line browser-module limit: ' . $lineCount);
}

$assert(str_contains($assets['assets/js/ticket-detail-core.js'], 'window.FoxDeskTicketDetailRuntime'), 'Core module must publish the shared runtime.');
$assert(str_contains($assets['assets/js/ticket-detail-core.js'], 'getIcon: getIcon'), 'Core module must publish icon rendering used by workflow controls.');
$assert(str_contains($assets['assets/js/ticket-detail-workflow.js'], 'var icons = config.icons || {};'), 'Workflow module must bind configured timer icons from the shared runtime.');
$assert(str_contains($assets['assets/js/ticket-detail-workflow.js'], 'var getIcon = runtime.getIcon;'), 'Workflow module must bind shared icon rendering explicitly.');
$assert(!str_contains($assets['assets/js/ticket-detail-workflow.js'], 'var editCommentEditor = null;'), 'Workflow module must not own record editor state.');
$assert(str_contains($assets['assets/js/ticket-detail-records.js'], 'var editCommentEditor = null;'), 'Records module must own edit-comment editor state.');
$assert(str_contains($assets['assets/js/ticket-detail.js'], 'var ready = runtime.ready;'), 'Bootstrap module must bind the shared DOM-ready helper explicitly.');
$assert(str_contains($assets['assets/js/ticket-detail.js'], 'runtime.initTimer()'), 'Bootstrap module must initialize extracted behavior.');

foreach ([
    'function quickEditField',
    'function openEditCommentModal',
    'function openEditTimeEntry',
    'function openEditTicketModal',
    'function openTicketTimeline',
    'const ICONS',
    'let commentEditor',
    'let editDescriptionEditor',
] as $inlineNeedle) {
    $assert(!str_contains($page, $inlineNeedle), 'Ticket detail page must not own inline JS behavior: ' . $inlineNeedle);
}

foreach ([
    'window.quickEditField',
    'window.openEditCommentModal',
    'window.openEditTimeEntry',
    'window.openEditTicketModal',
    'window.openTicketTimeline',
    'initUploadPreview',
    'FoxDeskAttachmentPasteDrop.bind',
    "targetSelectors: ['#comment-form', '#comment-upload-zone']",
    'initQuillEditors',
    'initTicketEditModalControls',
    "document.querySelectorAll('[data-ticket-edit-open]')",
    "document.querySelectorAll('[data-ticket-edit-close]')",
    'initTags',
    'initTimer',
    'updateCompleteActionTitle',
    'completeTimerHelp',
    'completeHelp',
    'initAutosave',
    "classList.add('is-open')",
    "classList.remove('is-open')",
    "classList.add('ticket-timeline-open')",
    "classList.remove('ticket-timeline-open')",
    'ticket-timeline-empty',
] as $assetNeedle) {
    $assert(str_contains($asset, $assetNeedle), 'Ticket detail JS asset missing behavior: ' . $assetNeedle);
}

foreach ([
    'window.FoxDeskAttachmentPasteDrop',
    'function autoBindKnownSurfaces',
    "inputId: 'comment-file-input'",
    "inputId: 'file-input'",
    "targetSelectors: ['#comment-form', '#comment-upload-zone']",
    "targetSelectors: ['#new-ticket-form', '#upload-zone']",
] as $pasteDropNeedle) {
    $assert(str_contains($paste_drop_asset, $pasteDropNeedle), 'Attachment paste/drop asset missing behavior: ' . $pasteDropNeedle);
}

foreach ([
    'body.ticket-timeline-open',
    '.ticket-timeline-overlay.is-open',
    '.ticket-timeline-empty',
] as $themeNeedle) {
    $assert(str_contains($theme, $themeNeedle), 'Theme CSS missing ticket timeline state: ' . $themeNeedle);
}

echo "Ticket detail JS contract OK\n";
