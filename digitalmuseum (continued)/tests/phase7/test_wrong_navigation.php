<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// tests/phase7/test_wrong_navigation.php
//
// Test case FB3 (Testing Plans.docx > Phase 7 Test Cases)
//   Description:     Test incorrect navigation
//   Steps:            Jump from static -> questionnaire
//   Expected Result:  Fallback screen appears
//
// Same pattern as QI1/AP-series tests: a real session is created via
// index.php's actual researcher-setup form, and real timeouts are
// logged via api/log_timeout.php (the same endpoint static.php/
// adaptive.php's own timeout logic calls) rather than writing to
// session_log directly.
//
// TC1 is FB3's literal step: with the static task not yet timed out at
// all, jump straight to questionnaire.php. TC2-TC5 extend the same
// check to every other "reached out of order" guard added in Phase 7 -
// transition1.php (first task not done yet), transition2.php (both
// tasks not done yet), and static.php re-entered after it has already
// timed out (Wireframes.docx's "prevent re-entering a finished task"
// rule) - since Phase 7's Testing Plan scopes this test to the
// fallback system as a whole, not just the one questionnaire.php jump.
//
// Creates a throwaway participant/session, exercises the real guards,
// then deletes the participant - leaving the database exactly as it
// was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl             = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl            = $baseUrl . '/digitalmuseum/index.php';
$staticUrl           = $baseUrl . '/digitalmuseum/static.php';
$transition1Url      = $baseUrl . '/digitalmuseum/transition1.php';
$transition2Url      = $baseUrl . '/digitalmuseum/transition2.php';
$questionnaireUrl    = $baseUrl . '/digitalmuseum/questionnaire.php';
$timeoutUrl          = $baseUrl . '/digitalmuseum/api/log_timeout.php';
$testParticipantCode = 'TEST_FB3_' . time();

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

/** POST JSON (no cookies needed - session_id is passed explicitly). */
function postJson(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, json_decode($body, true)];
}

function assertFallbackShown(string $body, string $label, array &$errors): void
{
    if (strpos($body, '<body class="fallback-page">') === false) {
        $errors[] = "{$label}: fallback screen did not appear.";
    } elseif (strpos($body, 'available yet at this point in the study') === false) {
        $errors[] = "{$label}: fallback screen shown, but not the wrong_navigation-specific message.";
    }
}

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_fb3_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: real session via index.php's researcher-setup form,
    // condition static_first (so static.php is the first task).
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
    // TC1 (FB3's literal step): neither task has timed out yet - jump
    // straight from a fresh session to questionnaire.php.
    // -------------------------------------------------------------
    [, $body1] = httpRequest($questionnaireUrl, $cookieJarPath);
    assertFallbackShown($body1, 'TC1 (static -> questionnaire, no tasks done)', $errors);

    // -------------------------------------------------------------
    // TC2: only the static task has timed out - still not enough to
    // reach questionnaire.php.
    // -------------------------------------------------------------
    [$httpCodeT1, $dataT1] = postJson($timeoutUrl, [
        'session_id'   => $sessionId,
        'version_name' => 'static',
    ]);
    if ($httpCodeT1 !== 201 || ($dataT1['success'] ?? false) !== true) {
        $errors[] = 'TC2 setup: logging the static timeout failed. Body: ' . json_encode($dataT1);
    }

    [, $body2] = httpRequest($questionnaireUrl, $cookieJarPath);
    assertFallbackShown($body2, 'TC2 (questionnaire.php after only static done)', $errors);

    // -------------------------------------------------------------
    // TC3: only the static task done - jumping to transition2.php
    // (which requires BOTH tasks done) is also out of order.
    // -------------------------------------------------------------
    [, $body3] = httpRequest($transition2Url, $cookieJarPath);
    assertFallbackShown($body3, 'TC3 (transition2.php after only static done)', $errors);

    // -------------------------------------------------------------
    // TC4: static (the first task) has already timed out - jumping
    // BACK into static.php again would re-enter a finished task and
    // break counterbalancing.
    // -------------------------------------------------------------
    [, $body4] = httpRequest($staticUrl, $cookieJarPath);
    assertFallbackShown($body4, 'TC4 (re-entering static.php after it is already done)', $errors);

    // -------------------------------------------------------------
    // TC5: a second, completely fresh session (no timeouts at all)
    // jumping straight to transition1.php, which requires the first
    // task to have already timed out.
    // -------------------------------------------------------------
    $secondCookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_fb3_cookies2_');
    $secondParticipantCode = 'TEST_FB3B_' . time();
    httpRequest($indexUrl, $secondCookieJarPath, [
        'participant_code' => $secondParticipantCode,
        'condition_name'   => 'static_first',
        'start_session'    => '1',
    ]);

    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $secondParticipantCode]);
    $secondParticipantId = $stmt->fetchColumn();

    [, $body5] = httpRequest($transition1Url, $secondCookieJarPath);
    assertFallbackShown($body5, 'TC5 (transition1.php before the first task is done)', $errors);

    @unlink($secondCookieJarPath);
    if ($secondParticipantId !== false) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => (int)$secondParticipantId]);
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

echo "FB3 - Test incorrect navigation\n";
echo str_repeat('-', 50) . "\n";
echo "Pages/endpoints tested: {$indexUrl}, {$staticUrl}, {$transition1Url}, {$transition2Url},\n";
echo "{$questionnaireUrl}, {$timeoutUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Every out-of-order navigation attempt (skipping ahead to questionnaire.php or\n";
    echo "transition2.php before both tasks are done, reaching transition1.php before the\n";
    echo "first task is done, or re-entering a finished static.php) correctly shows the\n";
    echo "fallback screen instead of the requested page.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
