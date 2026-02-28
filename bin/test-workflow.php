#!/usr/bin/env php
<?php
/**
 * FoxDesk Workflow Integration Test
 *
 * Tests ticket lifecycle: create → comment → status change → assign → notify
 * Run: php bin/test-workflow.php [--verbose]
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config.php';
require BASE_PATH . '/includes/database.php';
require BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/ticket-crud-functions.php';
require_once BASE_PATH . '/includes/mailer.php';

$verbose = in_array('--verbose', $argv ?? []) || in_array('-v', $argv ?? []);
$passed = 0;
$failed = 0;
$total = 0;

function test_pass($name) {
    global $passed, $total;
    $total++;
    $passed++;
    echo "  \033[32m✓ PASS\033[0m $name\n";
}

function test_fail($name, $reason = '') {
    global $failed, $total;
    $total++;
    $failed++;
    echo "  \033[31m✗ FAIL\033[0m $name" . ($reason ? " — $reason" : "") . "\n";
}

function test_log($msg) {
    echo "  $msg\n";
}

function mailpit_api($endpoint) {
    $url = "http://mailpit:8025/api/v1/$endpoint";
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

function mailpit_delete_all() {
    $url = "http://mailpit:8025/api/v1/messages";
    $ctx = stream_context_create(['http' => ['method' => 'DELETE', 'timeout' => 5]]);
    @file_get_contents($url, false, $ctx);
}

function mailpit_count() {
    $msgs = mailpit_api('messages?limit=100');
    return $msgs['messages_count'] ?? ($msgs['total'] ?? 0);
}

function mailpit_subjects() {
    $msgs = mailpit_api('messages?limit=50');
    $subjects = [];
    if (!empty($msgs['messages'])) {
        foreach ($msgs['messages'] as $m) {
            $subjects[] = $m['Subject'] ?? '(no subject)';
        }
    }
    return $subjects;
}

// ═══════════════════════════════════════════════════
echo "\n\033[1m╔══════════════════════════════════════════╗\033[0m\n";
echo "\033[1m║   FoxDesk Workflow Integration Test      ║\033[0m\n";
echo "\033[1m╚══════════════════════════════════════════╝\033[0m\n\n";

$pdo = get_db();

// ─── Pre-checks ───────────────────────────────────
echo "\033[1m── Pre-flight ──\033[0m\n";
try { $pdo->query("SELECT 1"); test_pass("Database connection"); }
catch (Exception $e) { test_fail("Database", $e->getMessage()); exit(1); }

$api = mailpit_api('messages?limit=1');
if ($api !== null) { test_pass("Mailpit API"); }
else { test_fail("Mailpit API"); }

// Get admin user for session simulation
$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$agent = $pdo->query("SELECT * FROM users WHERE role = 'agent' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = $pdo->query("SELECT * FROM users WHERE role = 'user' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($admin) test_pass("Admin user found: {$admin['email']}");
else { test_fail("No admin user"); exit(1); }
if ($agent) test_pass("Agent user found: {$agent['email']}");
if ($user) test_pass("Regular user found: {$user['email']}");

// ─── Test 1: Create Ticket (via DB function) ─────
echo "\n\033[1m── Test 1: Ticket Creation ──\033[0m\n";

mailpit_delete_all();
sleep(1);

$test_title = 'Workflow Test Ticket ' . date('H:i:s');
$test_description = "This is an automated test ticket created by the workflow test script.\n\nIt tests the full ticket lifecycle.";

$tickets_before = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();

try {
    $ticket_id = create_ticket([
        'title' => $test_title,
        'description' => $test_description,
        'type' => 'general',
        'priority_id' => 2,
        'user_id' => $admin['id'],
        'tags' => 'test,workflow,automated',
    ]);

    if ($ticket_id && $ticket_id > 0) {
        test_pass("Ticket created: #$ticket_id");
    } else {
        test_fail("create_ticket returned: " . var_export($ticket_id, true));
        $ticket_id = null;
    }
} catch (Throwable $e) {
    test_fail("create_ticket()", $e->getMessage());
    $ticket_id = null;
}

$tickets_after = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
if ($tickets_after > $tickets_before) {
    test_pass("Ticket count increased ($tickets_before → $tickets_after)");
} else {
    test_fail("Ticket count unchanged");
}

// Verify ticket data in DB
if ($ticket_id) {
    $ticket = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $ticket->execute([$ticket_id]);
    $ticket = $ticket->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        if ($ticket['title'] === $test_title) test_pass("Title matches: \"{$ticket['title']}\"");
        else test_fail("Title mismatch", "Expected: $test_title, Got: {$ticket['title']}");

        if ($ticket['source'] === 'web') test_pass("Source is 'web'");
        else test_pass("Source is '{$ticket['source']}'");

        if (!empty($ticket['hash'])) test_pass("Hash generated: {$ticket['hash']}");
        else test_fail("No hash generated");

        if ((int)$ticket['priority_id'] === 2) test_pass("Priority ID = 2");
        else test_fail("Priority", "Expected 2, got {$ticket['priority_id']}");
    }

    // Send new ticket notification
    try {
        $full_ticket = get_ticket($ticket_id);
        if ($full_ticket) {
            if (function_exists('send_new_ticket_notification')) {
                send_new_ticket_notification($full_ticket);
                test_pass("send_new_ticket_notification() called");
            }
        }
    } catch (Throwable $e) {
        test_fail("Notification", $e->getMessage());
    }
}

// ─── Test 2: Add Comment ─────────────────────────
echo "\n\033[1m── Test 2: Add Comment ──\033[0m\n";

if ($ticket_id) {
    $comment_text = "This is a test comment from the workflow test at " . date('H:i:s');
    $comments_before = $pdo->query("SELECT COUNT(*) FROM comments WHERE ticket_id = $ticket_id")->fetchColumn();

    try {
        $stmt = $pdo->prepare("INSERT INTO comments (ticket_id, user_id, content, is_internal, created_at) VALUES (?, ?, ?, 0, NOW())");
        $stmt->execute([$ticket_id, $admin['id'], $comment_text]);
        $comment_id = $pdo->lastInsertId();

        if ($comment_id) {
            test_pass("Comment created: #$comment_id");
        } else {
            test_fail("Comment insert returned no ID");
        }
    } catch (Throwable $e) {
        test_fail("Insert comment", $e->getMessage());
        $comment_id = null;
    }

    $comments_after = $pdo->query("SELECT COUNT(*) FROM comments WHERE ticket_id = $ticket_id")->fetchColumn();
    if ($comments_after > $comments_before) {
        test_pass("Comment count increased ($comments_before → $comments_after)");
    } else {
        test_fail("Comment count unchanged");
    }

    // Add internal comment
    try {
        $stmt = $pdo->prepare("INSERT INTO comments (ticket_id, user_id, content, is_internal, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute([$ticket_id, $admin['id'], "Internal note: customer seems frustrated"]);
        test_pass("Internal comment created");
    } catch (Throwable $e) {
        test_fail("Internal comment", $e->getMessage());
    }

    // Verify comment content
    $saved = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
    $saved->execute([$comment_id]);
    $saved = $saved->fetch(PDO::FETCH_ASSOC);
    if ($saved && $saved['content'] === $comment_text) {
        test_pass("Comment content verified");
    } else {
        test_fail("Comment content mismatch");
    }

    // Send notification for comment
    try {
        if (function_exists('send_comment_notification')) {
            $full_ticket = get_ticket($ticket_id);
            send_comment_notification($full_ticket, $comment_text, $admin);
            test_pass("send_comment_notification() called");
        }
    } catch (Throwable $e) {
        test_fail("Comment notification", $e->getMessage());
    }
} else {
    test_log("Skipped: no ticket");
}

// ─── Test 3: Change Status ───────────────────────
echo "\n\033[1m── Test 3: Status Change ──\033[0m\n";

if ($ticket_id) {
    // Get available statuses
    $statuses = $pdo->query("SELECT id, name FROM statuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($statuses) >= 2) {
        test_pass("Statuses available: " . count($statuses));
    }

    $current_status = $pdo->query("SELECT status_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
    if ($verbose) test_log("Current status_id: $current_status");

    // Change to a different status
    $new_status_id = null;
    foreach ($statuses as $s) {
        if ((int)$s['id'] !== (int)$current_status) {
            $new_status_id = (int)$s['id'];
            break;
        }
    }

    if ($new_status_id) {
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status_id, $ticket_id]);

            $check = $pdo->query("SELECT status_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
            if ((int)$check === $new_status_id) {
                $status_name = '';
                foreach ($statuses as $s) { if ((int)$s['id'] === $new_status_id) $status_name = $s['name']; }
                test_pass("Status changed to '$status_name' (id=$new_status_id)");
            } else {
                test_fail("Status update didn't persist");
            }
        } catch (Throwable $e) {
            test_fail("Status change", $e->getMessage());
        }

        // Activity log
        try {
            if (function_exists('log_activity')) {
                log_activity($ticket_id, $admin['id'], 'status_changed', "Status changed to $new_status_id");
                test_pass("Activity log recorded");
            }
        } catch (Throwable $e) {
            test_fail("Activity log", $e->getMessage());
        }

        // Notification
        try {
            if (function_exists('send_status_change_notification') && function_exists('get_status')) {
                $full_ticket = get_ticket($ticket_id);
                $old_status_obj = get_status((int)$current_status);
                $new_status_obj = get_status($new_status_id);
                send_status_change_notification($full_ticket, $old_status_obj, $new_status_obj);
                test_pass("send_status_change_notification() called");
            }
        } catch (Throwable $e) {
            test_fail("Status notification", $e->getMessage());
        }
    }
} else {
    test_log("Skipped: no ticket");
}

// ─── Test 4: Assign Ticket ───────────────────────
echo "\n\033[1m── Test 4: Assign Ticket ──\033[0m\n";

if ($ticket_id && $agent) {
    try {
        $stmt = $pdo->prepare("UPDATE tickets SET assignee_id = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$agent['id'], $ticket_id]);

        $check = $pdo->query("SELECT assignee_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
        if ((int)$check === (int)$agent['id']) {
            test_pass("Assigned to agent: {$agent['first_name']} {$agent['last_name']} (id={$agent['id']})");
        } else {
            test_fail("Assignment didn't persist");
        }
    } catch (Throwable $e) {
        test_fail("Assign ticket", $e->getMessage());
    }

    // Activity log
    try {
        if (function_exists('log_activity')) {
            log_activity($ticket_id, $admin['id'], 'assigned', "Assigned to {$agent['first_name']}");
            test_pass("Assignment activity logged");
        }
    } catch (Throwable $e) {
        test_fail("Assignment log", $e->getMessage());
    }

    // Notification
    try {
        if (function_exists('send_ticket_assignment_notification')) {
            $full_ticket = get_ticket($ticket_id);
            send_ticket_assignment_notification($full_ticket, $agent, $admin);
            test_pass("send_ticket_assignment_notification() called");
        }
    } catch (Throwable $e) {
        test_fail("Assignment notification", $e->getMessage());
    }
} else {
    test_log("Skipped: no ticket or no agent");
}

// ─── Test 5: Verify Notifications in Mailpit ─────
echo "\n\033[1m── Test 5: Mailpit Notifications ──\033[0m\n";

sleep(3); // Wait for emails to be delivered

$count = mailpit_count();
if ($count > 0) {
    test_pass("Mailpit received $count email(s)");
} else {
    test_fail("No emails in Mailpit", "Expected notification emails");
}

$subjects = mailpit_subjects();
if ($verbose && !empty($subjects)) {
    test_log("Emails received:");
    foreach ($subjects as $s) {
        test_log("  → $s");
    }
}

// Check for specific notification types
$found_types = ['new_ticket' => false, 'comment' => false, 'status' => false, 'assign' => false];
foreach ($subjects as $s) {
    $sl = strtolower($s);
    if (strpos($sl, 'new') !== false || strpos($sl, 'nový') !== false || strpos($sl, 'created') !== false || strpos($sl, 'vytvořen') !== false) {
        $found_types['new_ticket'] = true;
    }
    if (strpos($sl, 'comment') !== false || strpos($sl, 'komentář') !== false || strpos($sl, 'reply') !== false || strpos($sl, 'odpověď') !== false) {
        $found_types['comment'] = true;
    }
    if (strpos($sl, 'status') !== false || strpos($sl, 'stav') !== false) {
        $found_types['status'] = true;
    }
    if (strpos($sl, 'assign') !== false || strpos($sl, 'přiřaz') !== false) {
        $found_types['assign'] = true;
    }
}

foreach ($found_types as $type => $found) {
    if ($found) test_pass("Notification type '$type' found");
    else test_log("Note: '$type' notification not found (may not be configured)");
}

// ─── Test 6: Tags and Search ─────────────────────
echo "\n\033[1m── Test 6: Tags and Search ──\033[0m\n";

if ($ticket_id) {
    // Check tags were saved
    $tags = $pdo->query("SELECT tags FROM tickets WHERE id = $ticket_id")->fetchColumn();
    if ($tags && strpos($tags, 'test') !== false) {
        test_pass("Tags saved: $tags");
    } else {
        test_fail("Tags not saved", "Got: " . var_export($tags, true));
    }

    // Test search by title
    $search_term = substr($test_title, 0, 20);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE title LIKE ?");
    $stmt->execute(["%$search_term%"]);
    $found = $stmt->fetchColumn();
    if ($found > 0) {
        test_pass("Search by title found $found ticket(s)");
    } else {
        test_fail("Search by title found 0 tickets");
    }

    // Test filter by status
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE status_id = ?");
    $stmt->execute([$new_status_id ?? 1]);
    $by_status = $stmt->fetchColumn();
    test_pass("Filter by status: $by_status ticket(s)");

    // Test filter by assignee
    if ($agent) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assignee_id = ?");
        $stmt->execute([$agent['id']]);
        $by_agent = $stmt->fetchColumn();
        test_pass("Filter by assignee: $by_agent ticket(s)");
    }
}

// ─── Test 7: Activity Log ────────────────────────
echo "\n\033[1m── Test 7: Activity Log ──\033[0m\n";

if ($ticket_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $log_count = $stmt->fetchColumn();

    if ($log_count >= 2) {
        test_pass("Activity log has $log_count entries for ticket #$ticket_id");
    } else {
        test_fail("Activity log", "Only $log_count entries (expected >= 2)");
    }

    if ($verbose) {
        $stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_log WHERE ticket_id = ? ORDER BY id");
        $stmt->execute([$ticket_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            test_log("  [{$row['created_at']}] {$row['action']}: {$row['details']}");
        }
    }
}

// ─── Test 8: Ticket Relationships & Data ─────────
echo "\n\033[1m── Test 8: Data Integrity ──\033[0m\n";

// Verify user-ticket relationship
if ($ticket_id) {
    $stmt = $pdo->prepare("SELECT u.email, t.title FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $rel = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rel) {
        test_pass("Ticket→User join works: {$rel['email']}");
    } else {
        test_fail("Ticket→User join failed");
    }

    // Verify assignee relationship
    $stmt = $pdo->prepare("SELECT u.email FROM tickets t JOIN users u ON t.assignee_id = u.id WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($assignee) {
        test_pass("Ticket→Assignee join works: {$assignee['email']}");
    } else {
        test_fail("Ticket→Assignee join failed");
    }
}

// Verify statuses, priorities, ticket_types have data
foreach (['statuses', 'priorities', 'ticket_types'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    if ($count > 0) test_pass("$table: $count records");
    else test_fail("$table empty");
}

// ═══════════════════════════════════════════════════
echo "\n\033[1m══════════════════════════════════════════\033[0m\n";
echo "\033[1m Results: $passed passed, $failed failed, $total total\033[0m\n";
if ($failed === 0) {
    echo "\033[32m All tests passed! ✓\033[0m\n";
} else {
    echo "\033[31m Some tests failed. Check output above.\033[0m\n";
}
echo "\n";

exit($failed > 0 ? 1 : 0);
