<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// tests/phase7/test_broken_db.php
//
// Test case FB2 (Testing Plans.docx > Phase 7 Test Cases)
//   Description:     Test broken DB connection
//   Steps:            Disable DB temporarily
//   Expected Result:  Fallback screen appears
//
// A NOTE ON WHAT THIS SCRIPT CAN AND CAN'T AUTOMATE:
// "Disable DB temporarily" means actually stopping MySQL. This script
// deliberately does NOT do that itself - it shares the same live MAMP
// MySQL instance as every other test and as any other work happening
// in this project, so an automated test stopping it mid-run would be
// disruptive and outside what a test script should be doing on its
// own. Instead:
//
//   TC1 - automated: confirms fallback.php?reason=broken_db renders
//         the correct calm screen directly (the message-selection
//         logic that would show once a page detects the DB is down).
//   TC2 - automated: proves the actual resilience mechanism holds up
//         under a real connection failure - it runs the exact same
//         connect-with-timeout logic as config/db_connect.php's
//         safe_db_connect(), but pointed at a port nothing is
//         listening on, and confirms it returns null gracefully
//         (within its 2-second timeout) instead of crashing.
//   Manual step - printed at the end of this script's output: stop
//         MySQL in MAMP once, load static.php or adaptive.php directly,
//         confirm the fallback screen with the "having trouble reaching
//         the study database" message appears, then restart MySQL. That
//         one step needs a human because it means taking the real
//         database down.
// =====================================================================

header('Content-Type: text/plain');

$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/digitalmuseum';

$errors = [];

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $body];
}

/**
 * Mirrors config/db_connect.php's safe_db_connect() exactly, but takes
 * the DSN as a parameter so TC2 can point it at an address nothing is
 * listening on, to simulate the DB being down without touching the
 * real MySQL instance.
 */
function attemptConnect(string $dsn): ?PDO
{
    try {
        return new PDO(
            $dsn,
            'root',
            'root',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 2,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

try {
    // -------------------------------------------------------------
    // TC1: fallback.php?reason=broken_db shows the correct calm
    // message and screen directly.
    // -------------------------------------------------------------
    [$httpCode1, $body1] = httpGet($baseUrl . '/fallback.php?reason=broken_db');

    if ($httpCode1 !== 200) {
        $errors[] = "TC1: expected HTTP 200 loading fallback.php?reason=broken_db, got {$httpCode1}.";
    }
    if (strpos($body1, '<body class="fallback-page">') === false) {
        $errors[] = 'TC1: fallback screen did not render for reason=broken_db.';
    }
    if (strpos($body1, 'trouble reaching the study database') === false) {
        $errors[] = 'TC1: fallback screen did not show the broken_db-specific message.';
    }
    if (strpos($body1, 'Return to Home') === false) {
        $errors[] = 'TC1: fallback screen is missing its "Return to Home" button.';
    }

    // -------------------------------------------------------------
    // TC2: the same connect-with-timeout logic every guarded page
    // relies on (config/db_connect.php's safe_db_connect()) must
    // return null - not throw, not hang - when the DB is unreachable.
    // Port 3399 is not MySQL's port (3306) and nothing in this
    // environment listens on it, so this reliably simulates "DB down"
    // without touching the real database.
    // -------------------------------------------------------------
    $start = microtime(true);
    $deadConnection = attemptConnect('mysql:host=127.0.0.1;port=3399;dbname=digitalmuseum;charset=utf8mb4');
    $elapsed = microtime(true) - $start;

    if ($deadConnection !== null) {
        $errors[] = 'TC2: expected null connecting to an unreachable DB, got a live PDO connection instead.';
    }
    if ($elapsed > 5.0) {
        $errors[] = "TC2: connection attempt took {$elapsed}s to fail - ATTR_TIMEOUT does not appear to be working, a real outage could hang the page.";
    }

    // Sanity check: the exact same logic against the REAL database
    // should still succeed - proves TC2 above is actually testing
    // connection failure, not just misconfigured DSN syntax.
    $liveConnection = attemptConnect('mysql:host=127.0.0.1;port=3306;dbname=digitalmuseum;charset=utf8mb4');
    if ($liveConnection === null) {
        $errors[] = 'TC2 sanity check: could not connect to the real database with correct settings - is MAMP/MySQL running?';
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
}

echo "FB2 - Test broken DB connection\n";
echo str_repeat('-', 50) . "\n";
echo "Automated: fallback.php's broken_db message, and safe_db_connect()'s resilience\n";
echo "against an unreachable DB (simulated via a closed port, not the real MySQL instance).\n\n";

if (empty($errors)) {
    echo "AUTOMATED RESULT: PASS\n";
    echo "fallback.php shows the correct message for reason=broken_db, and the connection\n";
    echo "helper every guarded page relies on fails gracefully (returns null, doesn't hang)\n";
    echo "when the database is unreachable.\n";
} else {
    echo "AUTOMATED RESULT: FAIL\n";
    echo count($errors) . " issue(s) found:\n";
    foreach ($errors as $e) {
        echo " - {$e}\n";
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "MANUAL STEP STILL NEEDED TO FULLY CLOSE OUT FB2:\n";
echo str_repeat('=', 50) . "\n";
echo "This script cannot safely stop your live MySQL instance mid-test, so the literal\n";
echo "FB2 step (\"Disable DB temporarily\") needs one manual check:\n";
echo "  1. In MAMP, stop the MySQL server (leave Apache running).\n";
echo "  2. In a browser, load static.php or adaptive.php directly.\n";
echo "  3. Confirm the fallback screen appears with: \"We're having trouble reaching\n";
echo "     the study database right now.\"\n";
echo "  4. Restart MySQL in MAMP before continuing any other testing.\n";

exit(empty($errors) ? 0 : 1);
