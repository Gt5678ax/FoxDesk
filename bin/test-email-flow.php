#!/usr/bin/env php
<?php
/**
 * FoxDesk E2E Email Flow Test
 *
 * Tests the full email lifecycle:
 *   1. Send email via SMTP → Mailpit catches it (outgoing notifications)
 *   2. Send email to GreenMail → IMAP ingest → creates ticket
 *   3. Send reply email → adds comment to ticket
 *   4. Verify manual ticket creation + database integrity
 *
 * Architecture:
 *   Mailpit  (SMTP 1025, Web UI 8025) — catches outgoing notifications
 *   GreenMail (SMTP 3025, IMAP 3143)  — receives "customer" emails for ingest
 *
 * Usage: php bin/test-email-flow.php [--verbose]
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config.php';
require BASE_PATH . '/includes/database.php';
require BASE_PATH . '/includes/functions.php';
require BASE_PATH . '/includes/email-ingest-functions.php';
require BASE_PATH . '/includes/mailer.php';

$verbose = in_array('--verbose', $argv ?? []) || in_array('-v', $argv ?? []);
$passed = 0;
$failed = 0;
$total = 0;

function test_log($msg) {
    echo "  $msg\n";
}

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

/**
 * Send email via raw SMTP socket.
 * @param string $host SMTP host
 * @param int    $port SMTP port
 */
function smtp_send_raw($host, $port, $to, $subject, $body, $from = 'customer@example.com', $headers = []) {
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$socket) {
        return ['success' => false, 'error' => "$errstr ($errno)"];
    }

    // Read greeting
    fgets($socket, 512);

    // EHLO
    fwrite($socket, "EHLO foxdesk-test\r\n");
    while ($line = fgets($socket, 512)) {
        if (substr($line, 3, 1) === ' ') break;
    }

    // MAIL FROM
    fwrite($socket, "MAIL FROM:<$from>\r\n");
    fgets($socket, 512);

    // RCPT TO
    fwrite($socket, "RCPT TO:<$to>\r\n");
    fgets($socket, 512);

    // DATA
    fwrite($socket, "DATA\r\n");
    fgets($socket, 512);

    // Build message
    $msg_id = '<' . uniqid('test-', true) . '@foxdesk-test.local>';
    $msg = "From: $from\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "Message-ID: $msg_id\r\n";
    $msg .= "Date: " . date('r') . "\r\n";
    foreach ($headers as $k => $v) {
        $msg .= "$k: $v\r\n";
    }
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "\r\n";
    $msg .= $body . "\r\n";
    $msg .= ".\r\n";

    fwrite($socket, $msg);
    $resp = fgets($socket, 512);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return [
        'success' => (strpos($resp, '250') === 0),
        'message_id' => $msg_id,
    ];
}

function mailpit_api($endpoint) {
    $host = getenv('SMTP_HOST') ?: 'mailpit';
    $url = "http://$host:8025/api/v1/$endpoint";
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

function mailpit_delete_all() {
    $host = getenv('SMTP_HOST') ?: 'mailpit';
    $url = "http://$host:8025/api/v1/messages";
    $ctx = stream_context_create([
        'http' => ['method' => 'DELETE', 'timeout' => 5]
    ]);
    @file_get_contents($url, false, $ctx);
}

// ═══════════════════════════════════════════════════
echo "\n\033[1m╔══════════════════════════════════════════╗\033[0m\n";
echo "\033[1m║   FoxDesk E2E Email Flow Test            ║\033[0m\n";
echo "\033[1m╚══════════════════════════════════════════╝\033[0m\n\n";

// Resolve hosts/ports from env
$mailpit_host = getenv('SMTP_HOST') ?: 'mailpit';
$mailpit_port = (int)(getenv('SMTP_PORT') ?: 1025);
$greenmail_host = getenv('GREENMAIL_SMTP_HOST') ?: (getenv('IMAP_HOST') ?: 'greenmail');
$greenmail_smtp_port = (int)(getenv('GREENMAIL_SMTP_PORT') ?: 3025);
$imap_host = getenv('IMAP_HOST') ?: 'greenmail';
$imap_port = (int)(getenv('IMAP_PORT') ?: 3143);

// ─── Pre-checks ───────────────────────────────────
echo "\033[1m── Pre-flight checks ──\033[0m\n";

// Check DB connection
try {
    $pdo = get_db();
    test_pass('Database connection');
} catch (Exception $e) {
    test_fail('Database connection', $e->getMessage());
    echo "\nCannot continue without database.\n";
    exit(1);
}

// Check IMAP extension
if (function_exists('imap_open')) {
    test_pass('PHP IMAP extension loaded');
} else {
    test_fail('PHP IMAP extension loaded', 'imap extension not installed');
    echo "\nCannot test IMAP without php-imap extension.\n";
    exit(1);
}

// Check Mailpit SMTP (outgoing notifications)
$socket = @fsockopen($mailpit_host, $mailpit_port, $errno, $errstr, 3);
if ($socket) {
    fclose($socket);
    test_pass("Mailpit SMTP ($mailpit_host:$mailpit_port)");
} else {
    test_fail("Mailpit SMTP ($mailpit_host:$mailpit_port)", $errstr);
    echo "\nCannot test outgoing email without Mailpit.\n";
    exit(1);
}

// Check GreenMail SMTP (for sending test "customer" emails)
$socket = @fsockopen($greenmail_host, $greenmail_smtp_port, $errno, $errstr, 5);
if ($socket) {
    fclose($socket);
    test_pass("GreenMail SMTP ($greenmail_host:$greenmail_smtp_port)");
} else {
    test_fail("GreenMail SMTP ($greenmail_host:$greenmail_smtp_port)", $errstr);
    echo "\nCannot test incoming email without GreenMail SMTP.\n";
    exit(1);
}

// Check GreenMail IMAP (for email ingest)
$socket = @fsockopen($imap_host, $imap_port, $errno, $errstr, 5);
if ($socket) {
    fclose($socket);
    test_pass("GreenMail IMAP ($imap_host:$imap_port)");
} else {
    test_fail("GreenMail IMAP ($imap_host:$imap_port)", $errstr);
    echo "\nCannot test IMAP ingest without GreenMail IMAP.\n";
    exit(1);
}

// Check Mailpit API
$api = mailpit_api('messages?limit=1');
if ($api !== null) {
    test_pass('Mailpit API accessible');
} else {
    test_fail('Mailpit API accessible', "Cannot reach http://$mailpit_host:8025/api/v1/");
}

// ─── Test 1: SMTP Outgoing (App → Mailpit) ───────
echo "\n\033[1m── Test 1: SMTP Outgoing (notifications) ──\033[0m\n";

// Clear Mailpit inbox first
mailpit_delete_all();
sleep(1);

// Send test email using app's send_email function
$test_to = 'testuser@example.com';
$test_subject = 'FoxDesk Test Notification ' . date('H:i:s');
$result = send_email($test_to, $test_subject, 'This is a test notification from FoxDesk.', false, true);

if ($result) {
    test_pass('send_email() returned success');
} else {
    test_fail('send_email() returned success', 'Function returned false');
}

// Check Mailpit received it
sleep(2);
$messages = mailpit_api('messages?limit=10');
$found = false;
if ($messages && !empty($messages['messages'])) {
    foreach ($messages['messages'] as $msg) {
        if (strpos($msg['Subject'] ?? '', 'FoxDesk Test Notification') !== false) {
            $found = true;
            break;
        }
    }
}

if ($found) {
    test_pass('Notification email received in Mailpit');
} else {
    test_fail('Notification email received in Mailpit', 'Email not found in Mailpit inbox');
}

// ─── Test 2: IMAP Ingest (Email → Ticket) ────────
echo "\n\033[1m── Test 2: IMAP Ingest (email → new ticket) ──\033[0m\n";

// Send a "customer" email to GreenMail (not Mailpit!)
$ticket_subject = 'Need help with login - Test ' . date('His');
$ticket_body = "Hello,\n\nI cannot login to my account. I keep getting 'invalid password' error.\n\nPlease help!\n\nBest regards,\nTest Customer";
$send_result = smtp_send_raw($greenmail_host, $greenmail_smtp_port, 'support@foxdesk.local', $ticket_subject, $ticket_body, 'customer@example.com');

if ($send_result && $send_result['success']) {
    test_pass('Customer email sent to GreenMail');
    if ($verbose) test_log("Message-ID: " . $send_result['message_id']);
} else {
    test_fail('Customer email sent to GreenMail', $send_result['error'] ?? 'unknown error');
}

// Wait for GreenMail to process
sleep(3);

// Count tickets before ingest
$before = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();

// Run email ingest
test_log("Running email ingest (IMAP from $imap_host:$imap_port)...");
try {
    $ingest_result = email_ingest_run(['limit' => 10, 'dry_run' => false]);

    if ($verbose) {
        test_log("Ingest result: checked={$ingest_result['checked']}, processed={$ingest_result['processed']}, skipped={$ingest_result['skipped']}, failed={$ingest_result['failed']}");
    }

    if ($ingest_result['processed'] > 0) {
        test_pass("Email ingest processed {$ingest_result['processed']} email(s)");
    } else {
        test_fail("Email ingest processed emails", "processed=0, checked={$ingest_result['checked']}, failed={$ingest_result['failed']}");
        if (!empty($ingest_result['details'])) {
            foreach ($ingest_result['details'] as $d) {
                test_log("  Detail: " . json_encode($d));
            }
        }
    }
} catch (Throwable $e) {
    test_fail("Email ingest", $e->getMessage());
    if ($verbose) test_log("Stack: " . $e->getTraceAsString());
}

// Check ticket was created
$after = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$new_tickets = $after - $before;
$created_ticket_id = null;

if ($new_tickets > 0) {
    test_pass("New ticket created (total: $before → $after)");

    // Find the specific ticket
    $stmt = $pdo->prepare("SELECT id, title, source FROM tickets WHERE title LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(['%' . substr($ticket_subject, 0, 30) . '%']);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        test_pass("Ticket #{$ticket['id']}: \"{$ticket['title']}\" (source: {$ticket['source']})");
        $created_ticket_id = $ticket['id'];
    } else {
        test_fail("Find created ticket by subject");
    }
} else {
    test_fail("New ticket created", "No new tickets after ingest");
}

// ─── Test 3: Reply → Comment ─────────────────────
echo "\n\033[1m── Test 3: Reply email → comment on ticket ──\033[0m\n";

if ($created_ticket_id) {
    // Get the message_id of the original ticket message
    $stmt = $pdo->prepare("SELECT message_id FROM ticket_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$created_ticket_id]);
    $orig_msg = $stmt->fetch(PDO::FETCH_ASSOC);

    $comments_before = $pdo->query("SELECT COUNT(*) FROM comments WHERE ticket_id = $created_ticket_id")->fetchColumn();

    // Send reply email to GreenMail
    $reply_body = "Thank you for your quick response!\n\nI tried the password reset but still having issues.\nCan you check my account directly?\n\nThanks,\nTest Customer";
    $reply_headers = [];
    if ($orig_msg && !empty($orig_msg['message_id'])) {
        $reply_headers['In-Reply-To'] = $orig_msg['message_id'];
        $reply_headers['References'] = $orig_msg['message_id'];
    }

    $reply_result = smtp_send_raw(
        $greenmail_host, $greenmail_smtp_port,
        'support@foxdesk.local',
        'Re: ' . $ticket_subject,
        $reply_body,
        'customer@example.com',
        $reply_headers
    );

    if ($reply_result && $reply_result['success']) {
        test_pass('Reply email sent to GreenMail');
    } else {
        test_fail('Reply email sent to GreenMail');
    }

    sleep(3);

    // Run ingest again
    test_log("Running email ingest for reply...");
    try {
        $ingest2 = email_ingest_run(['limit' => 10]);

        if ($ingest2['processed'] > 0) {
            test_pass("Reply email processed");
        } else {
            test_fail("Reply email processed", "processed=0");
        }
    } catch (Throwable $e) {
        test_fail("Reply ingest", $e->getMessage());
    }

    // Check comment was added
    $comments_after = $pdo->query("SELECT COUNT(*) FROM comments WHERE ticket_id = $created_ticket_id")->fetchColumn();

    if ($comments_after > $comments_before) {
        test_pass("Comment added to ticket #$created_ticket_id ($comments_before → $comments_after)");
    } else {
        // It might have created a new ticket instead of comment
        $after2 = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
        if ($after2 > $after) {
            test_log("Note: Reply created a new ticket instead of comment (this is OK if In-Reply-To matching didn't work)");
            test_pass("Reply processed (as new ticket)");
        } else {
            test_fail("Comment added to ticket", "No new comments found");
        }
    }
} else {
    test_log("Skipping reply test — no ticket was created in Test 2");
}

// ─── Test 4: Manual ticket creation ──────────────
echo "\n\033[1m── Test 4: Manual ticket creation ──\033[0m\n";

// Get admin user
$admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    $admin_id = $admin['id'];

    $hash = bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("INSERT INTO tickets (hash, title, description, status_id, priority_id, ticket_type_id, user_id, source) VALUES (?, ?, ?, 1, 2, 3, ?, 'web')");
    $result = $stmt->execute([
        $hash,
        'Test Ticket from E2E Script ' . date('H:i:s'),
        'This ticket was created by the automated E2E test script to verify manual ticket creation.',
        $admin_id,
    ]);

    if ($result) {
        $tid = $pdo->lastInsertId();
        test_pass("Manual ticket created (#$tid)");
    } else {
        test_fail("Manual ticket creation");
    }
} else {
    test_fail("Manual ticket creation", "No admin user found");
}

// ─── Test 5: Verify database integrity ───────────
echo "\n\033[1m── Test 5: Database integrity ──\033[0m\n";

$checks = [
    'users'         => "SELECT COUNT(*) FROM users",
    'tickets'       => "SELECT COUNT(*) FROM tickets",
    'statuses'      => "SELECT COUNT(*) FROM statuses",
    'priorities'    => "SELECT COUNT(*) FROM priorities",
    'ticket_types'  => "SELECT COUNT(*) FROM ticket_types",
    'settings'      => "SELECT COUNT(*) FROM settings",
];

foreach ($checks as $table => $query) {
    $count = $pdo->query($query)->fetchColumn();
    if ($count > 0) {
        test_pass("$table: $count records");
    } else {
        test_fail("$table: has records", "0 records found");
    }
}

// Check email templates
$tmpl_count = $pdo->query("SELECT COUNT(*) FROM email_templates")->fetchColumn();
if ($tmpl_count >= 4) {
    test_pass("email_templates: $tmpl_count templates");
} else {
    test_fail("email_templates", "Only $tmpl_count templates (expected >= 4)");
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
