<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_panel_load.php
//
// Test case AP1 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test panel loads
//   Steps:            Open adaptive.php
//   Expected Result:  Panel shows "Suggested for You"
//
// Like the Phase 2 HTTP-endpoint tests (e.g. test_session_start.php),
// this exercises the real pages over HTTP with cURL rather than
// including adaptive.php directly - the guard logic (session checks,
// redirectTo computation) only runs when the page is actually requested.
// A cookie jar carries the PHP session across two requests, mirroring
// the real participant flow: POST index.php's researcher-setup form
// (creates the session, same as a researcher would), then GET
// adaptive.php with that same session.
//
// Creates a throwaway participant/session via the real index.php form
// submission (not a direct DB insert - this also exercises the
// genuine session_start flow), verifies adaptive.php's response, then
// deletes them - leaving the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl           = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl           = $baseUrl . '/digitalmuseum/index.php';
$adaptiveUrl        = $baseUrl . '/digitalmuseum/adaptive.php';
$testParticipantCode = 'TEST_AP1_' . time();

$errors        = [];
$participantId = null;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * GET/POST via cURL, sharing cookies with $cookieJarPath across calls -
 * this is what lets the second request (adaptive.php) see the PHP
 * session the first request (index.php's form) created.
 */
function httpRequest(string $url, string $cookieJarPath, ?array $postFields = null): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJarPath,
        CURLOPT_COOKIEFILE     => $cookieJarPath,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($postFields !== null) {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    curl_setopt_array($ch, $options);
    $body     = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $body];
}

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ap1_cookies_');

try {
    // -------------------------------------------------------------
    // TC1: A session with no active session cookie is redirected away
    // from adaptive.php, never reaching the panel (guard check, before
    // creating any real session below).
    // -------------------------------------------------------------
    $noCookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ap1_nocookie_');
    [, $noSessionBody] = httpRequest($adaptiveUrl, $noCookieJarPath);
    unlink($noCookieJarPath);

    if (strpos($noSessionBody, 'Suggested for You') !== false) {
        $errors[] = 'TC1: adaptive.php served panel content with no active session - guard is not working.';
    }

    // -------------------------------------------------------------
    // TC2: Start a real session via index.php's researcher-setup form
    // (same path a researcher actually uses), condition = adaptive_first
    // so adaptive.php is this session's first, unlocked task.
    // -------------------------------------------------------------
    [$startHttpCode, ] = httpRequest($indexUrl, $cookieJarPath, [
        'participant_code' => $testParticipantCode,
        'condition_name'   => 'adaptive_first',
        'start_session'    => '1',
    ]);

    if ($startHttpCode !== 200) {
        $errors[] = "TC2: expected HTTP 200 after following index.php's post-redirect, got {$startHttpCode}.";
    }

    // Look the session up from the DB rather than scraping HTML for it -
    // participant_code is unique to this test run.
    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $testParticipantCode]);
    $participantId = $stmt->fetchColumn();

    if ($participantId === false) {
        throw new RuntimeException('TC2: index.php form submission did not create a participant - cannot continue.');
    }
    $participantId = (int)$participantId;

    $stmt = $pdo->prepare('SELECT session_id FROM session_log WHERE participant_id = :id');
    $stmt->execute([':id' => $participantId]);
    $sessionId = (int)$stmt->fetchColumn();

    // -------------------------------------------------------------
    // TC3: GET adaptive.php with that session's cookie - this is AP1
    // itself: the panel loads and shows "Suggested for You".
    // -------------------------------------------------------------
    [$adaptiveHttpCode, $adaptiveBody] = httpRequest($adaptiveUrl, $cookieJarPath);

    if ($adaptiveHttpCode !== 200) {
        $errors[] = "TC3: expected HTTP 200 for adaptive.php with an active session, got {$adaptiveHttpCode}.";
    }
    if (strpos($adaptiveBody, 'Suggested for You') === false) {
        $errors[] = 'TC3: adaptive.php response did not contain "Suggested for You" (AP1\'s expected result).';
    }
    if (strpos($adaptiveBody, 'id="adaptivePanel"') === false) {
        $errors[] = 'TC3: adaptive.php response is missing the #adaptivePanel container.';
    }
    if (substr_count($adaptiveBody, 'adaptive-panel__slot--empty') !== 4) {
        $errors[] = 'TC3: expected exactly 4 empty panel slots on first load (Adaptive Logic.docx > '
            . '"Session Start: Panel begins neutral - no suggestions"), found '
            . substr_count($adaptiveBody, 'adaptive-panel__slot--empty') . '.';
    }
    if (strpos($adaptiveBody, 'Suggestions will appear here as you explore.') === false) {
        $errors[] = 'TC3: adaptive.php response is missing the neutral empty-state message.';
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    @unlink($cookieJarPath);

    // Always clean up, even if an assertion above failed - deleting the
    // participant cascades to session_log (ON DELETE CASCADE) - see
    // create_tables.sql.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AP1 - Test panel loads\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$adaptiveUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "adaptive.php redirects away with no active session, and loads correctly with one - showing ";
    echo "\"Suggested for You\", the neutral empty-state message, and 4 empty panel slots ready for the engine to fill.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
