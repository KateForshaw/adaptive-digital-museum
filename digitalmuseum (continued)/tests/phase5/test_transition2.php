<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_transition2.php
//
// Test case AP6 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test transition page 2
//   Steps:            Wait 7 minutes
//   Expected Result:  Redirect to questionnaire
//
// "Wait 7 minutes" isn't literally waited out here - instead this
// exercises the real chain of events that a timeout produces: a session
// is started via index.php's actual researcher-setup form (as AP1 did),
// then api/log_timeout.php (Phase 2, already tested by BL6) is called
// for both 'static' and 'adaptive' - the same call static.php/adaptive.php's
// js/timeout.js makes when its 7-minute timer fires - which flips
// session_log's timeout_triggered_* flags. transition2.php is then
// requested with the same session cookie at each stage, confirming it
// only shows once BOTH flags are set, not after just one.
//
// Creates a throwaway participant/session via the real index.php form
// submission, exercises the real timeout endpoint, verifies
// transition2.php's guard and content, then deletes the participant -
// leaving the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl            = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl            = $baseUrl . '/digitalmuseum/index.php';
$transition2Url      = $baseUrl . '/digitalmuseum/transition2.php';
$timeoutUrl          = $baseUrl . '/digitalmuseum/api/log_timeout.php';
$testParticipantCode = 'TEST_AP6_' . time();

$errors        = [];
$participantId = null;

/**
 * GET/POST-with-form-fields via cURL, sharing cookies with
 * $cookieJarPath - same helper AP1 uses for the researcher-setup +
 * page-load flow.
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

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ap6_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: real session via index.php's researcher-setup form (same
    // path AP1 uses), condition doesn't matter here since both tasks
    // get completed either way.
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
    // TC1: neither task has timed out yet - transition2.php must
    // redirect away, not show its content.
    // -------------------------------------------------------------
    [, $body1] = httpRequest($transition2Url, $cookieJarPath);
    if (strpos($body1, 'Browsing Sessions Complete') !== false) {
        $errors[] = 'TC1: transition2.php showed its content before either task had timed out.';
    }

    // -------------------------------------------------------------
    // TC2: only the static task has timed out - still must redirect
    // away (this is transition1.php's moment, not transition2.php's).
    // -------------------------------------------------------------
    [$httpCodeT1, $dataT1] = postJson($timeoutUrl, [
        'session_id'   => $sessionId,
        'version_name' => 'static',
    ]);
    if ($httpCodeT1 !== 201 || ($dataT1['success'] ?? false) !== true) {
        $errors[] = 'TC2 setup: logging the static timeout failed. Body: ' . json_encode($dataT1);
    }

    [, $body2] = httpRequest($transition2Url, $cookieJarPath);
    if (strpos($body2, 'Browsing Sessions Complete') !== false) {
        $errors[] = 'TC2: transition2.php showed its content after only the static task had timed out.';
    }

    // -------------------------------------------------------------
    // TC3: both tasks have now timed out - transition2.php should show
    // its content and link on to questionnaire.php (AP6's expected result).
    // -------------------------------------------------------------
    [$httpCodeT2, $dataT2] = postJson($timeoutUrl, [
        'session_id'   => $sessionId,
        'version_name' => 'adaptive',
    ]);
    if ($httpCodeT2 !== 201 || ($dataT2['success'] ?? false) !== true) {
        $errors[] = 'TC3 setup: logging the adaptive timeout failed. Body: ' . json_encode($dataT2);
    }

    [$httpCode3, $body3] = httpRequest($transition2Url, $cookieJarPath);
    if ($httpCode3 !== 200) {
        $errors[] = "TC3: expected HTTP 200 for transition2.php once both tasks are done, got {$httpCode3}.";
    }
    if (strpos($body3, 'Browsing Sessions Complete') === false) {
        $errors[] = 'TC3: transition2.php did not show its content once both tasks had timed out.';
    }
    if (strpos($body3, 'href="questionnaire.php"') === false) {
        $errors[] = 'TC3: transition2.php is missing the link to questionnaire.php (AP6\'s expected result).';
    }
    if (strpos($body3, 'Continue to Questionnaire') === false) {
        $errors[] = 'TC3: transition2.php is missing the "Continue to Questionnaire" button text.';
    }

    // -------------------------------------------------------------
    // TC4: with no active session at all, transition2.php must also
    // redirect away (separate scenario from TC1's "session exists but
    // incomplete" - covers "no session" out-of-order access too).
    // -------------------------------------------------------------
    $noCookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ap6_nocookie_');
    [, $body4] = httpRequest($transition2Url, $noCookieJarPath);
    unlink($noCookieJarPath);
    if (strpos($body4, 'Browsing Sessions Complete') !== false) {
        $errors[] = 'TC4: transition2.php served its content with no active session at all.';
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    @unlink($cookieJarPath);

    // Always clean up, even if an assertion above failed - cascades
    // through session_log to event_log/timeout (create_tables.sql).
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AP6 - Test transition page 2\n";
echo str_repeat('-', 50) . "\n";
echo "Pages/endpoints tested: {$indexUrl}, {$transition2Url}, {$timeoutUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "transition2.php stays hidden with no session, and after only one task times out, and shows its ";
    echo "content with the Continue to Questionnaire link only once both browsing tasks have timed out.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
