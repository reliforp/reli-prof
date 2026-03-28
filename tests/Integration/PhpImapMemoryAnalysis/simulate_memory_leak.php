<?php
/**
 * Simulate the memory leak from Webklex/php-imap#531.
 *
 * The issue: Message ↔ Attachment circular references prevent GC from
 * freeing memory. Each Attachment holds a reference to its parent Message
 * ($oMessage), and the Message holds AttachmentCollection with all Attachments.
 *
 * This script creates many Message objects from raw email strings
 * (no IMAP server needed) to demonstrate memory growth.
 *
 * Usage:
 *   php simulate_memory_leak.php
 *
 * For reli-prof (from another terminal):
 *   sudo php /home/user/reli-prof/reli inspector:memory -p <PID>
 */

require_once __DIR__ . '/vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

// Initialize ClientManager with minimal config required for Message::fromString()
ClientManager::$config = require __DIR__ . '/vendor/webklex/php-imap/src/config/imap.php';

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Build a raw email with multiple attachments
function buildRawEmail(int $numAttachments = 3, int $attachmentSizeKB = 50): string {
    $boundary = 'boundary_' . bin2hex(random_bytes(8));
    $raw = "From: sender@example.com\r\n";
    $raw .= "To: recipient@example.com\r\n";
    $raw .= "Subject: Test Email with Attachments\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $raw .= "\r\n";

    // Text body
    $raw .= "--$boundary\r\n";
    $raw .= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
    $raw .= "\r\n";
    $raw .= "This is the email body.\r\n";

    // Attachments
    for ($i = 0; $i < $numAttachments; $i++) {
        $raw .= "--$boundary\r\n";
        $raw .= "Content-Type: application/octet-stream\r\n";
        $raw .= "Content-Disposition: attachment; filename=\"file_$i.bin\"\r\n";
        $raw .= "Content-Transfer-Encoding: base64\r\n";
        $raw .= "\r\n";
        // Generate base64-encoded attachment content
        $raw .= chunk_split(base64_encode(random_bytes($attachmentSizeKB * 1024)), 76, "\r\n");
    }

    $raw .= "--$boundary--\r\n";
    return $raw;
}

echo "Phase 1: Creating messages with attachments (simulating inbox fetch loop)...\n";
echo "  Each message has 3 attachments of ~50KB each.\n";
echo "  This creates Message ↔ Attachment circular references.\n\n";

$messages = [];
$numMessages = 200;

for ($i = 0; $i < $numMessages; $i++) {
    $rawEmail = buildRawEmail(3, 50);
    $message = Message::fromString($rawEmail);
    $messages[] = $message;

    if (($i + 1) % 50 === 0) {
        $memMB = round(memory_get_usage(true) / 1024 / 1024, 2);
        echo "  Created $i messages, memory: $memMB MB\n";
    }
}

$memAfter = memory_get_usage(true);
$peakMem = memory_get_peak_usage(true);
echo "\nDone creating $numMessages messages.\n";
echo "  memory_get_usage: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "  peak memory: " . round($peakMem / 1024 / 1024, 2) . " MB\n";

echo "\nPhase 2: Attempting to free messages (unset)...\n";
$memBefore = memory_get_usage(true);

// Try to free - but circular refs prevent GC
foreach ($messages as $k => $m) {
    unset($messages[$k]);
}
unset($messages);

$memAfterUnset = memory_get_usage(true);
echo "  memory before unset: " . round($memBefore / 1024 / 1024, 2) . " MB\n";
echo "  memory after unset: " . round($memAfterUnset / 1024 / 1024, 2) . " MB\n";
$freed = $memBefore - $memAfterUnset;
echo "  freed: " . round($freed / 1024 / 1024, 2) . " MB\n";

if ($freed < $memBefore * 0.5) {
    echo "  ⚠ Less than 50% freed — circular references are preventing GC!\n";
}

echo "\nPhase 3: Running gc_collect_cycles()...\n";
$collected = gc_collect_cycles();
$memAfterGC = memory_get_usage(true);
echo "  Cycles collected: $collected\n";
echo "  memory after GC: " . round($memAfterGC / 1024 / 1024, 2) . " MB\n";
echo "  Additional freed by GC: " . round(($memAfterUnset - $memAfterGC) / 1024 / 1024, 2) . " MB\n";

echo "\n=== Keeping process alive for 60 seconds for reli-prof analysis ===\n";
echo "Attach now: sudo php /home/user/reli-prof/reli inspector:memory -p $pid\n\n";

// Recreate messages to have something to analyze
echo "Recreating messages for analysis...\n";
$messages = [];
for ($i = 0; $i < $numMessages; $i++) {
    $rawEmail = buildRawEmail(3, 50);
    $messages[] = Message::fromString($rawEmail);
}
echo "Ready. memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "READY_FOR_ANALYSIS\n";

// Wait
sleep(60);
