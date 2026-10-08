<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// tests/phase7/test_reset_logic.php
//
// Test case FB4 (Testing Plans.docx > Phase 7 Test Cases)
//   Description:     Test reset logic
//   Steps:            Click "Return to Home"
//   Expected Result:  Home loads cleanly
//
// Same pattern as the other Phase 7 tests: a real session is created
// via index.php's actual researcher-setup form, and the "Click Return
// to Home" step is exercised as a real POST to fallback.php (the same
// request the button's <form> on that page submits), not by touching
// session_log/the PHP session directly.
//
// Beyond the literal "Home loads cleanly" result, this also checks the
// two things Phase 7's own "what to test before moving on" list calls
// out directly: that the reset doesn't leave a dangling open session in
// the database, and that counterbalancing stays intact (the reset PHP
// session is genuinely cleared, not just hidden - a later request with
// the same cookie can't keep riding on the old session).
//
// Creates a throwaway participant/session, exercises the real reset
// flow, then deletes the participant - leaving the database exactly as
// it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl             = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl            = $baseUrl . '/digitalmuseum/index.php';
$staticUrl           = $baseUrl . '/digitalmuseum/static.php';
$fallbackUrl         = $baseUrl . '/digitalmuseum/fallback.php';
$testParticipantCode = 'TEST_FB4_' . time();

$errors        = [];
$participantId = null;

/** GET/POST-with-form-fields via cURL, sharing cookies with $cookieJarPath. */
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
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $body];
}

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_fb4_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: real session via index.php's researcher-setup form.
    // -------------------------------------------------------------
    httpRequest($indexUrl, $cookieJarPath, [
        'participant_code' => $testParticipantCode,
        'condition_name'   => 'static_first',
        'start_session'    => '1',
    ]);

    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $testParticipantCode]);
    $participantId = $stmt->fetchColumn();
    if ($participantId === false) {
        throw new RuntimeException('index.php form submission did not create a participant - cannot continue.');
    }
    $participantId = (int)$participantId;

    $stmt = $pdo->prepare('SELECT session_id FROM session_log WHERE participant_id = :id');
    $stmt->execute([':id' => $participantId]);
    $sessionId = (int)$stmt->fetchColumn();

    // -------------------------------------------------------------
    // TC1: arrive at the fallback screen with this active session
    // (any of FB1-FB3's triggers would land here in real use - loading
    // fallback.php directly is enough to exercise the reset button
    // itself, which is what FB4 is actually testing).
    // -------------------------------------------------------------
    [$httpCode1, $body1] = httpRequest($fallbackUrl, $cookieJarPath);
    if ($httpCode1 !== 200 || strpos($body1, '<body class="fallback-page">') === false) {
        $errors[] = 'TC1: fallback.php did not render correctly for a session in this state.';
    }
    if (strpos($body1, 'name="reset" value="1"') === false) {
        $errors[] = 'TC1: fallback.php is missing its "Return to Home" reset button.';
    }

    // -------------------------------------------------------------
    // TC2 (FB4's literal step): "click" Return to Home - POST the same
    // reset=1 request the button's form submits.
    // -------------------------------------------------------------
    [$httpCode2, $body2] = httpRequest($fallbackUrl, $cookieJarPath, ['reset' => '1']);

    if ($httpCode2 !== 200) {
        $errors[] = "TC2: expected HTTP 200 after following the Return to Home redirect, got {$httpCode2}.";
    }
    if (strpos($body2, '<body class="home-page">') === false) {
        $errors[] = 'TC2: Return to Home did not land back on index.php (home loads cleanly).';
    }
    if (strpos($body2, 'Session active') !== false) {
        $errors[] = 'TC2: index.php still shows an active session after Return to Home - the reset did not clear it.';
    }
    if (strpos($body2, 'Researcher setup') === false) {
        $errors[] = 'TC2: index.php did not show the researcher-setup form again after the reset.';
    }

    // -------------------------------------------------------------
    // TC3: the reset must actually close out the DB session, not just
    // clear the local PHP session - Phase 7's own checklist asks
    // "does the system recover gracefully?", and a session left open
    // forever in session_log would not be a graceful recovery.
    // -------------------------------------------------------------
    $stmt = $pdo->prepare('SELECT session_end FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    $sessionEnd = $stmt->fetchColumn();
    if ($sessionEnd === null) {
        $errors[] = 'TC3: session_log.session_end is still NULL after Return to Home - the DB session was not closed out.';
    }

    // -------------------------------------------------------------
    // TC4: counterbalancing check - the PHP session must be genuinely
    // cleared, not just hidden. The same browser cookie, reused after
    // the reset, must not still be able to ride on the old session; it
    // should now look exactly like a fresh, session-less visit.
    // -------------------------------------------------------------
    [, $body4] = httpRequest($staticUrl, $cookieJarPath);
    if (strpos($body4, '<body class="fallback-page">') === false) {
        $errors[] = 'TC4: after Return to Home, the same browser cookie could still reach static.php - the session was not fully cleared.';
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    @unlink($cookieJarPath);

    // Always clean up, even if an assertion above failed - deleting the
    // participant cascades to session_log, event_log, timeout, and
    // questionnaire_response (ON DELETE CASCADE - see create_tables.sql).
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "FB4 - Test reset logic\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$fallbackUrl}, {$staticUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Clicking Return to Home on the fallback screen closes out the open DB session,\n";
    echo "fully clears the local PHP session, and lands cleanly back on index.php's\n";
    echo "researcher-setup screen - with no way for the same browser session to keep\n";
    echo "riding on the old, now-closed session.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
