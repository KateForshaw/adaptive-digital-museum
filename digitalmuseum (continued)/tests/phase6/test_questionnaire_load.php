<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// tests/phase6/test_questionnaire_load.php
//
// Test case QI1 (Testing Plans.docx > Phase 6 Test Cases)
//   Description:     Test questionnaire loads
//   Steps:            Open questionnaire.php
//   Expected Result:  Form visible
//
// Note: questionnaire.php is a native PHP/HTML form, not the embedded
// Microsoft Form the original Testing Plans.docx wording assumed - see
// the design-deviation note at the top of questionnaire.php. "Form
// visible" here means the native form itself renders with all 14
// questionnaire items, not an iframe loading.
//
// Same pattern as AP1 (test_panel_load.php) and AP6 (test_transition2.php):
// a real session is created via index.php's actual researcher-setup
// form, and both browsing tasks are completed via the real
// api/log_timeout.php endpoint (the same call static.php/adaptive.php's
// js/timeout.js makes) rather than writing to session_log directly -
// questionnaire.php's guard requires both timeout flags to be set,
// exactly like transition2.php's.
//
// Creates a throwaway participant/session, exercises the real guard +
// render logic, then deletes the participant - leaving the database
// exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl             = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl            = $baseUrl . '/digitalmuseum/index.php';
$questionnaireUrl     = $baseUrl . '/digitalmuseum/questionnaire.php';
$timeoutUrl           = $baseUrl . '/digitalmuseum/api/log_timeout.php';
$testParticipantCode = 'TEST_QI1_' . time();

$errors        = [];
$participantId = null;

/**
 * GET/POST-with-form-fields via cURL, sharing cookies with
 * $cookieJarPath - same helper AP1/AP6 use for the researcher-setup +
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

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_qi1_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: real session via index.php's researcher-setup form (same
    // path AP1/AP6 use).
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
    // TC1: neither task has timed out yet - questionnaire.php must
    // redirect away, not show the form (same guard as transition2.php).
    //
    // Checked via the <body class="questionnaire-page"> marker rather
    // than the "Post-Study Questionnaire" text: with
    // CURLOPT_FOLLOWLOCATION on, a redirected request lands on
    // index.php, whose home screen nav card is itself titled
    // "Post-Study Questionnaire" - that text alone isn't a reliable
    // signal that questionnaire.php actually rendered.
    // -------------------------------------------------------------
    [, $body1] = httpRequest($questionnaireUrl, $cookieJarPath);
    if (strpos($body1, '<body class="questionnaire-page">') !== false) {
        $errors[] = 'TC1: questionnaire.php showed its content before either task had timed out.';
    }

    // -------------------------------------------------------------
    // TC2: only the static task has timed out - still must redirect away.
    // -------------------------------------------------------------
    [$httpCodeT1, $dataT1] = postJson($timeoutUrl, [
        'session_id'   => $sessionId,
        'version_name' => 'static',
    ]);
    if ($httpCodeT1 !== 201 || ($dataT1['success'] ?? false) !== true) {
        $errors[] = 'TC2 setup: logging the static timeout failed. Body: ' . json_encode($dataT1);
    }

    [, $body2] = httpRequest($questionnaireUrl, $cookieJarPath);
    if (strpos($body2, '<body class="questionnaire-page">') !== false) {
        $errors[] = 'TC2: questionnaire.php showed its content after only the static task had timed out.';
    }

    // -------------------------------------------------------------
    // TC3: both tasks have now timed out - questionnaire.php should
    // show the form, fully populated with all 14 items (QI1's expected
    // result: "Form visible").
    // -------------------------------------------------------------
    [$httpCodeT2, $dataT2] = postJson($timeoutUrl, [
        'session_id'   => $sessionId,
        'version_name' => 'adaptive',
    ]);
    if ($httpCodeT2 !== 201 || ($dataT2['success'] ?? false) !== true) {
        $errors[] = 'TC3 setup: logging the adaptive timeout failed. Body: ' . json_encode($dataT2);
    }

    [$httpCode3, $body3] = httpRequest($questionnaireUrl, $cookieJarPath);
    if ($httpCode3 !== 200) {
        $errors[] = "TC3: expected HTTP 200 for questionnaire.php once both tasks are done, got {$httpCode3}.";
    }
    if (strpos($body3, 'Post-Study Questionnaire') === false) {
        $errors[] = 'TC3: questionnaire.php did not show its content once both tasks had timed out.';
    }
    if (strpos($body3, 'class="questionnaire-form"') === false) {
        $errors[] = 'TC3: questionnaire.php response is missing the questionnaire form itself.';
    }

    $itemCount = substr_count($body3, 'class="questionnaire-item"');
    if ($itemCount !== 14) {
        $errors[] = "TC3: expected all 14 questionnaire items to render, found {$itemCount}.";
    }

    if (strpos($body3, 'Which version did you complete first?') === false) {
        $errors[] = 'TC3: first item (item_id 1) text is missing from the rendered form.';
    }
    if (strpos($body3, 'Any final comments about your experience today?') === false) {
        $errors[] = 'TC3: last item (item_id 14) text is missing from the rendered form.';
    }
    if (strpos($body3, 'name="submit_questionnaire"') === false) {
        $errors[] = 'TC3: questionnaire.php response is missing the submit button.';
    }

    // -------------------------------------------------------------
    // TC4: with no active session at all, questionnaire.php must also
    // redirect away.
    // -------------------------------------------------------------
    $noCookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_qi1_nocookie_');
    [, $body4] = httpRequest($questionnaireUrl, $noCookieJarPath);
    unlink($noCookieJarPath);
    if (strpos($body4, '<body class="questionnaire-page">') !== false) {
        $errors[] = 'TC4: questionnaire.php served its content with no active session at all.';
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

echo "QI1 - Test questionnaire loads\n";
echo str_repeat('-', 50) . "\n";
echo "Pages/endpoints tested: {$indexUrl}, {$questionnaireUrl}, {$timeoutUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "questionnaire.php stays hidden with no session and before both tasks time out, then renders the ";
    echo "full 14-item native form (not an iframe) once both browsing tasks are complete.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
