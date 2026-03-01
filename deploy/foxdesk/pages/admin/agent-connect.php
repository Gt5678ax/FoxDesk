<?php
/**
 * Agent Connect — AI Connection Instructions Generator
 *
 * Generates ready-to-paste instruction packages for connecting AI tools
 * (Claude, ChatGPT, Cursor, etc.) to the helpdesk API.
 *
 * Accessed via: ?page=admin&section=agent-connect&id={agent_id}
 */

if (!is_admin()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$page_title = t('Agent Connect');
$agent_id = (int) ($_GET['id'] ?? 0);

// Load agent
$agent = null;
if ($agent_id > 0) {
    $agent = db_fetch_one("SELECT * FROM users WHERE id = ? AND is_ai_agent = 1", [$agent_id]);
}

if (!$agent) {
    flash(t('Agent not found.'), 'error');
    header('Location: index.php?page=admin&section=users&tab=ai_agents');
    exit;
}

// Handle token generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_connect_token'])) {
    require_csrf_token();
    if (function_exists('generate_api_token')) {
        $token_result = generate_api_token($agent_id, $agent['first_name']);
        if ($token_result && !empty($token_result['token'])) {
            $_SESSION['agent_connect_token'] = $token_result['token'];
            $_SESSION['agent_connect_id'] = $agent_id;
            flash(t('New token generated.'), 'success');
        } else {
            flash(t('Failed to generate token.'), 'error');
        }
    }
    header('Location: index.php?page=admin&section=agent-connect&id=' . $agent_id);
    exit;
}

// Check for token availability
$token = null;
// Fresh token from agent creation or token generation flow
if (!empty($_SESSION['new_ai_agent_token'])) {
    $token = $_SESSION['new_ai_agent_token'];
    // Move to connect-specific session key so it persists on this page
    $_SESSION['agent_connect_token'] = $token;
    $_SESSION['agent_connect_id'] = $agent_id;
    unset($_SESSION['new_ai_agent_token']);
} elseif (!empty($_SESSION['agent_connect_token']) && ($_SESSION['agent_connect_id'] ?? 0) === $agent_id) {
    $token = $_SESSION['agent_connect_token'];
}

// Build context data
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$api_base = $base_url . '/index.php?page=api&action=';

$statuses = get_statuses();
$priorities = get_priorities();
$ticket_types = function_exists('get_ticket_types') ? get_ticket_types() : [];
$app_name = get_setting('app_name', 'FoxDesk');
$custom_instructions = get_setting('agent_custom_instructions', '');

// Build status list string
$status_list = implode(', ', array_map(fn($s) => $s['name'], $statuses));
$priority_list = implode(', ', array_map(fn($p) => $p['name'], $priorities));
$type_list = !empty($ticket_types) ? implode(', ', array_map(fn($t) => $t['name'], $ticket_types)) : 'None configured';

// Token display string
$token_display = $token ?? 'YOUR_API_TOKEN_HERE';

// === Generate instruction templates ===

// --- System Prompt format (for Claude.ai, ChatGPT, any LLM) ---
$system_prompt = <<<PROMPT
# {$app_name} — AI Agent Instructions

You are an AI assistant connected to the {$app_name} helpdesk system.
You can create tickets, add comments, update statuses, and log time via the REST API.

## Authentication

- **API Base URL:** `{$api_base}`
- **Bearer Token:** `{$token_display}`
- Include in every request: `Authorization: Bearer {$token_display}`

## Available API Endpoints

### Read Operations
- **GET** `agent-me` — Your agent info
- **GET** `agent-list-statuses` — All ticket statuses
- **GET** `agent-list-priorities` — All priority levels
- **GET** `agent-list-users` — All users (optional: `?role=agent`, `?exclude_ai=1`)
- **GET** `agent-list-tickets` — Search tickets (params: `status`, `priority`, `search`, `assignee_id`, `limit`, `offset`)
- **GET** `agent-get-ticket` — Full ticket detail (params: `hash` or `id`)

### Write Operations (POST, JSON body)
- **POST** `agent-create-ticket` — Create a ticket
  - Required: `title`
  - Optional: `description`, `priority_id`, `status_id`, `assignee_id`, `due_date`, `tags`, `duration_minutes`, `time_summary`
- **POST** `agent-add-comment` — Add comment to a ticket
  - Required: `ticket_hash` or `ticket_id`, `content`
  - Optional: `is_internal` (boolean), `duration_minutes`, `time_summary`
- **POST** `agent-update-status` — Change ticket status
  - Required: `ticket_hash` or `ticket_id`, `status_id` or `status` (name)
- **POST** `agent-log-time` — Log time entry
  - Required: `ticket_hash` or `ticket_id`, `duration_minutes`
  - Optional: `summary`, `is_billable`, `started_at`, `ended_at`

## Current System Configuration

- **Statuses:** {$status_list}
- **Priorities:** {$priority_list}
- **Ticket Types:** {$type_list}

## Usage Examples

### Create a ticket
```
POST {$api_base}agent-create-ticket
Headers: Authorization: Bearer {$token_display}, Content-Type: application/json
Body: {"title": "Bug report", "description": "Details...", "tags": "bug"}
```

### List open tickets
```
GET {$api_base}agent-list-tickets?status=Open&limit=10
Headers: Authorization: Bearer {$token_display}
```

### Add a comment
```
POST {$api_base}agent-add-comment
Headers: Authorization: Bearer {$token_display}, Content-Type: application/json
Body: {"ticket_hash": "ABC123", "content": "Working on this...", "is_internal": true}
```

PROMPT;

// Append custom instructions if set
if (!empty(trim($custom_instructions))) {
    $system_prompt .= "\n## Additional Instructions\n\n" . trim($custom_instructions) . "\n";
}

// --- CLAUDE.md format (for Claude Code) ---
$claude_md = <<<CLAUDEMD
# CLAUDE.md — {$app_name} Agent Context

## Project

{$app_name} — connected helpdesk system.
URL: {$base_url}

## API Access

API Base URL: {$api_base}

The API token is stored in `.env` file (gitignored). Load it with:
```bash
source .env
```

Environment variables:
- `HELPDESK_API_URL` — API base URL
- `HELPDESK_API_TOKEN` — Bearer token

## Quick Reference

### Create a ticket
```bash
source .env
curl -s -X POST "\${HELPDESK_API_URL}agent-create-ticket" \\
  -H "Authorization: Bearer \${HELPDESK_API_TOKEN}" \\
  -H "Content-Type: application/json" \\
  -d '{"title": "Summary", "description": "Details...", "tags": "tag1,tag2"}'
```

### List tickets
```bash
source .env
curl -s "\${HELPDESK_API_URL}agent-list-tickets?status=Open&limit=10" \\
  -H "Authorization: Bearer \${HELPDESK_API_TOKEN}"
```

### All Endpoints
- GET `agent-me` — Agent info
- GET `agent-list-statuses` — Statuses: {$status_list}
- GET `agent-list-priorities` — Priorities: {$priority_list}
- GET `agent-list-users` — Users (?role=agent)
- GET `agent-list-tickets` — Search (status, priority, search, limit)
- GET `agent-get-ticket` — Detail (?hash= or ?id=)
- POST `agent-create-ticket` — {title, description, priority_id, tags, duration_minutes}
- POST `agent-add-comment` — {ticket_hash, content, is_internal, duration_minutes}
- POST `agent-update-status` — {ticket_hash, status_id or status}
- POST `agent-log-time` — {ticket_hash, duration_minutes, summary}

CLAUDEMD;

if (!empty(trim($custom_instructions))) {
    $claude_md .= "\n## Instructions\n\n" . trim($custom_instructions) . "\n";
}

// --- .env format ---
$env_file = <<<ENV
# {$app_name} API credentials for AI agents
# This file should be gitignored — never commit it
HELPDESK_API_URL={$api_base}
HELPDESK_API_TOKEN={$token_display}
ENV;

// --- JavaScript/localStorage format (for Chrome plugin / browser agents) ---
$js_snippet = <<<JS
// Run this in the browser console on {$base_url}
// It stores the API config in localStorage for browser-based AI agents

localStorage.setItem('ai_agent_config', JSON.stringify({
  api_url: '{$api_base}',
  api_token: '{$token_display}',
  agent_name: '{$agent['first_name']}',
  helpdesk_name: '{$app_name}'
}));

console.log('AI agent config saved to localStorage');
JS;

// Handle custom instructions save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_custom_instructions'])) {
    require_csrf_token();
    $new_instructions = trim($_POST['custom_instructions'] ?? '');
    save_setting('agent_custom_instructions', $new_instructions);
    $custom_instructions = $new_instructions;
    flash(t('Custom instructions saved.'), 'success');
    header('Location: index.php?page=admin&section=agent-connect&id=' . $agent_id);
    exit;
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = t('Agent Connect');
$page_header_subtitle = e($agent['first_name']) . ' — ' . t('Connection instructions for AI tools');
include BASE_PATH . '/includes/components/page-header.php';
?>

<!-- Back link -->
<div class="mb-4">
    <a href="<?php echo url('admin', ['section' => 'users', 'tab' => 'ai_agents']); ?>"
       class="text-sm text-blue-600 hover:text-blue-800 inline-flex items-center gap-1">
        <?php echo get_icon('arrow-left', 'w-4 h-4'); ?>
        <?php echo e(t('Back to AI agents')); ?>
    </a>
</div>

<?php if (!$token): ?>
<!-- No token available — need to generate one -->
<div class="card card-body max-w-2xl">
    <div class="text-center py-6">
        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <?php echo get_icon('key', 'w-8 h-8 text-orange-500'); ?>
        </div>
        <h3 class="text-lg font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('API token required')); ?></h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">
            <?php echo e(t('To generate connection instructions, a new API token is needed. This will replace any existing token for this agent.')); ?>
        </p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <button type="submit" name="generate_connect_token" class="btn btn-primary">
                <?php echo get_icon('key', 'w-4 h-4 inline mr-1'); ?>
                <?php echo e(t('Generate token & get instructions')); ?>
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<!-- Token available — show instructions -->

<!-- Agent info bar -->
<div class="card card-body mb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center">
                <?php echo get_icon('bot', 'w-5 h-5 text-purple-600'); ?>
            </div>
            <div>
                <p class="font-semibold" style="color: var(--text-primary);"><?php echo e($agent['first_name']); ?></p>
                <p class="text-xs" style="color: var(--text-muted);">
                    <?php if (!empty($agent['ai_model'])): ?>
                        <span class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded"><?php echo e($agent['ai_model']); ?></span>
                    <?php endif; ?>
                    <span class="ml-1"><?php echo e(t('Token:')); ?> <code class="text-green-600"><?php echo e(substr($token, 0, 12)); ?>...</code></span>
                </p>
            </div>
        </div>
        <div class="text-xs" style="color: var(--text-muted);">
            <?php echo e(t('API Base:')); ?> <code style="color: var(--text-secondary);"><?php echo e($api_base); ?></code>
        </div>
    </div>
</div>

<!-- Format selector tabs -->
<div class="space-y-4">
    <div class="flex flex-wrap gap-0.5 p-0.5 rounded-lg w-fit" style="background: var(--surface-secondary);">
        <button onclick="switchFormat('system_prompt')" id="tab_system_prompt"
                class="ac-tab ac-tab-active px-4 py-1.5 rounded-md text-sm font-medium transition-colors">
            <?php echo get_icon('message-square', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('System Prompt')); ?>
        </button>
        <button onclick="switchFormat('claude_md')" id="tab_claude_md"
                class="ac-tab ac-tab-inactive px-4 py-1.5 rounded-md text-sm font-medium transition-colors">
            <?php echo get_icon('file-text', 'w-4 h-4 inline mr-1'); ?>CLAUDE.md
        </button>
        <button onclick="switchFormat('env')" id="tab_env"
                class="ac-tab ac-tab-inactive px-4 py-1.5 rounded-md text-sm font-medium transition-colors">
            <?php echo get_icon('terminal', 'w-4 h-4 inline mr-1'); ?>.env
        </button>
        <button onclick="switchFormat('js')" id="tab_js"
                class="ac-tab ac-tab-inactive px-4 py-1.5 rounded-md text-sm font-medium transition-colors">
            <?php echo get_icon('code', 'w-4 h-4 inline mr-1'); ?>JavaScript
        </button>
    </div>

    <!-- System Prompt format -->
    <div id="panel_system_prompt" class="ac-panel">
        <div class="card overflow-hidden">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('System Prompt')); ?></h3>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Paste into Claude.ai, ChatGPT, Cursor, or any AI chat.')); ?></p>
                </div>
                <button onclick="copyToClipboard('content_system_prompt')" class="btn btn-primary btn-sm" id="btn_copy_system_prompt">
                    <?php echo get_icon('copy', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Copy')); ?>
                </button>
            </div>
            <div class="p-4">
                <pre id="content_system_prompt" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre-wrap font-mono max-h-96 overflow-y-auto"><?php echo e($system_prompt); ?></pre>
            </div>
        </div>
        <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-900/20 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <p class="text-xs text-blue-700 dark:text-blue-300">
                <strong><?php echo e(t('How to use:')); ?></strong>
                <?php echo e(t('Copy the text above and paste it into your AI tool\'s system prompt, custom instructions, or at the beginning of a new conversation. The AI will then be able to interact with your helpdesk.')); ?>
            </p>
        </div>
    </div>

    <!-- CLAUDE.md format -->
    <div id="panel_claude_md" class="ac-panel" style="display:none">
        <div class="card overflow-hidden">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold" style="color: var(--text-primary);">CLAUDE.md</h3>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('For Claude Code — place in your project root. Also save the .env file.')); ?></p>
                </div>
                <div class="flex gap-2">
                    <button onclick="downloadFile('claude_md_content', 'CLAUDE.md', 'text/markdown')" class="btn btn-secondary btn-sm">
                        <?php echo get_icon('download', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Download')); ?>
                    </button>
                    <button onclick="copyToClipboard('claude_md_content')" class="btn btn-primary btn-sm" id="btn_copy_claude_md">
                        <?php echo get_icon('copy', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Copy')); ?>
                    </button>
                </div>
            </div>
            <div class="p-4">
                <pre id="claude_md_content" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre-wrap font-mono max-h-96 overflow-y-auto"><?php echo e($claude_md); ?></pre>
            </div>
        </div>
        <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-900/20 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <p class="text-xs text-blue-700 dark:text-blue-300">
                <strong><?php echo e(t('How to use:')); ?></strong>
                <?php echo e(t('1. Download or copy the CLAUDE.md file and place it in your project root. 2. Also save the .env file (switch to the .env tab). 3. Start Claude Code — it will auto-read the instructions.')); ?>
            </p>
        </div>
    </div>

    <!-- .env format -->
    <div id="panel_env" class="ac-panel" style="display:none">
        <div class="card overflow-hidden">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold" style="color: var(--text-primary);">.env</h3>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Environment file for CLI tools. Add to .gitignore!')); ?></p>
                </div>
                <div class="flex gap-2">
                    <button onclick="downloadFile('env_content', '.env', 'text/plain')" class="btn btn-secondary btn-sm">
                        <?php echo get_icon('download', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Download')); ?>
                    </button>
                    <button onclick="copyToClipboard('env_content')" class="btn btn-primary btn-sm" id="btn_copy_env">
                        <?php echo get_icon('copy', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Copy')); ?>
                    </button>
                </div>
            </div>
            <div class="p-4">
                <pre id="env_content" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre-wrap font-mono"><?php echo e($env_file); ?></pre>
            </div>
        </div>
        <div class="mt-2 p-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg">
            <p class="text-xs text-orange-700 dark:text-orange-300">
                <?php echo get_icon('alert-triangle', 'w-4 h-4 inline mr-1'); ?>
                <strong><?php echo e(t('Security:')); ?></strong>
                <?php echo e(t('Always add .env to your .gitignore file. Never commit API tokens to version control.')); ?>
            </p>
        </div>
    </div>

    <!-- JavaScript/localStorage format -->
    <div id="panel_js" class="ac-panel" style="display:none">
        <div class="card overflow-hidden">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold" style="color: var(--text-primary);">JavaScript / localStorage</h3>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('For browser-based AI tools (Chrome plugin, etc.).')); ?></p>
                </div>
                <button onclick="copyToClipboard('js_content')" class="btn btn-primary btn-sm" id="btn_copy_js">
                    <?php echo get_icon('copy', 'w-4 h-4 inline mr-1'); ?><?php echo e(t('Copy')); ?>
                </button>
            </div>
            <div class="p-4">
                <pre id="js_content" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre-wrap font-mono"><?php echo e($js_snippet); ?></pre>
            </div>
        </div>
        <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-900/20 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <p class="text-xs text-blue-700 dark:text-blue-300">
                <strong><?php echo e(t('How to use:')); ?></strong>
                <?php echo e(t('Open your helpdesk in a browser, open DevTools (F12), go to Console, paste the code above, and press Enter. Browser-based AI agents will read the config from localStorage.')); ?>
            </p>
        </div>
    </div>
</div>

<!-- Custom Instructions -->
<div class="card card-body mt-4">
    <details>
        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide mb-2 select-none" style="color: var(--text-muted);">
            <?php echo get_icon('settings', 'w-4 h-4 inline mr-1'); ?>
            <?php echo e(t('Custom agent instructions')); ?>
        </summary>
        <div class="mt-3">
            <p class="text-xs mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Add custom behavioral instructions that will be included in all generated instruction packages. Use this to define company-specific rules for AI agents.')); ?>
            </p>
            <form method="post" class="space-y-3">
                <?php echo csrf_field(); ?>
                <textarea name="custom_instructions" rows="5" class="form-textarea w-full text-sm font-mono"
                    placeholder="<?php echo e(t('Example: Always use Czech language for ticket comments. Tag all bugs with the &quot;bug&quot; tag. Assign urgent tickets to user ID 5.')); ?>"
                ><?php echo e($custom_instructions); ?></textarea>
                <button type="submit" name="save_custom_instructions" class="btn btn-primary btn-sm">
                    <?php echo e(t('Save instructions')); ?>
                </button>
            </form>
        </div>
    </details>
</div>

<?php endif; ?>

<!-- JavaScript helpers -->
<script>
var acActiveClass = 'shadow ac-tab-active';
var acInactiveClass = 'ac-tab-inactive';

function switchFormat(format) {
    var panels = document.querySelectorAll('.ac-panel');
    var tabs = document.querySelectorAll('.ac-tab');
    for (var i = 0; i < panels.length; i++) {
        panels[i].style.display = 'none';
    }
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].className = 'ac-tab px-4 py-1.5 rounded-md text-sm font-medium transition-colors ' + acInactiveClass;
    }
    var panel = document.getElementById('panel_' + format);
    var tab = document.getElementById('tab_' + format);
    if (panel) panel.style.display = '';
    if (tab) tab.className = 'ac-tab px-4 py-1.5 rounded-md text-sm font-medium transition-colors ' + acActiveClass;
}

function copyToClipboard(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;

    var text = el.textContent || el.innerText;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showCopyFeedback(elementId);
        }).catch(function() {
            fallbackCopy(text, elementId);
        });
    } else {
        fallbackCopy(text, elementId);
    }
}

function fallbackCopy(text, elementId) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showCopyFeedback(elementId);
    } catch (e) {
        alert('<?php echo e(t('Copy failed. Please select the text manually and copy.')); ?>');
    }
    document.body.removeChild(textarea);
}

function showCopyFeedback(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var card = el.closest('.card');
    if (!card) return;
    var btn = card.querySelector('button[id^="btn_copy_"]');
    if (!btn) return;

    btn.textContent = '\u2713 <?php echo e(t('Copied!')); ?>';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');
    setTimeout(function() {
        btn.textContent = '<?php echo e(t('Copy')); ?>';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2000);
}

function downloadFile(elementId, filename, mimeType) {
    var el = document.getElementById(elementId);
    if (!el) return;

    var text = el.textContent || el.innerText;
    var blob = new Blob([text], { type: mimeType || 'text/plain' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
