<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// tests/phase6/test_questionnaire_submission.php
//
// Test case QI2 (Testing Plans.docx > Phase 6 Test Cases)
//   Description:     Test submission
//   Steps:            Submit form
//   Expected Result:  Microsoft confirmation appears
//
// Note: "Microsoft confirmation appears" is the original wording from
// when this screen was an embedded Microsoft Form - see the design-
// deviation note at the top of questionnaire.php. There is no
// Microsoft confirmation any more; the equivalent expected result for
// the native form is that submitting shows this app's own completion
// screen and the answers land in questionnaire_response.
//
// Same session-setup pattern as QI1 (test_questionnaire_load.php): a
// real session is created via index.php's researcher-setup form, and
// both browsing tasks are completed via the real api/log_timeout.php
// endpoint. This test then POSTs a full, valid set of answers to
// questionnaire.php itself (not directly to
// api/post_questionnaire_response.php) so it exercises the whole
// chain exactly as a participant's browser would: field validation,
// the post-redirect-GET flow, and the resulting completion screen -
// then checks straight in the database that questionnaire_response
// and session_log ended up correct.
//
// Answer values for the two multiple_choice items match the options
// hardcoded in questionnaire.php (see that file's comment on why
// they're hardcoded there rather than read from the schema).
//
// Creates a throwaway participant/session/responses, verifies them,
// then deletes them - leaving the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl              = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl             = $baseUrl . '/digitalmuseum/index.php';
$questionnaireUrl     = $baseUrl . '/digitalmuseum/questionnaire.php';
$timeoutUrl           = $baseUrl . '/digitalmuseum/api/log_timeout.php';
$testParticipantCode  = 'TEST_QI2_' . time();

$errors        = [];
$participantId = null;

/**
 * GET/POST-with-form-fields via cURL, sharing cookies with
 * $cookieJarPath - same helper QI1/AP1/AP6 use for the researcher-
 * setup + page-load/submit flow.
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

// A complete, valid answer for every one of the 14 items - keyed by
// item_id so it can be posted as item_<id> fields, matching
// questionnaire.php's field naming.
$validAnswers = [
    1  => 'Static version',   // multiple_choice - order_completed_first
    2  => '4',                 // likert - static_relevance
    3  => '3',                 // likert - static_surprise
    4  => '5',                 // likert - static_freedom
    5  => '4',                 // likert - static_experience
    6  => 'Enjoyed the static browsing experience.',       // free_text (optional) - static_comment
    7  => '5',                 // likert - adaptive_relevance
    8  => '4',                 // likert - adaptive_surprise
    9  => '5',                 // likert - adaptive_freedom
    10 => '5',                 // likert - adaptive_experience
    11 => 'The panel suggestions felt well-matched.',       // free_text (optional) - adaptive_comment
    12 => 'Adaptive version', // multiple_choice - preferred_version
    13 => 'The suggestions helped me find things I would have missed.', // free_text (optional) - preference_reason
    14 => 'No further comments.', // free_text (optional) - final_comments
];

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_qi2_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: real session via index.php's researcher-setup form, both
    // browsing tasks completed via the real timeout endpoint - same
    // as QI1, so questionnaire.php's guard lets this session through.
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

    foreach (['static', 'adaptive'] as $version) {
        [$httpCodeT, $dataT] = postJson($timeoutUrl, [
            'session_id'   => $sessionId,
            'version_name' => $version,
        ]);
        if ($httpCodeT !== 201 || ($dataT['success'] ?? false) !== true) {
            throw new RuntimeException("Setup: logging the {$version} timeout failed. Body: " . json_encode($dataT));
        }
    }

    // -------------------------------------------------------------
    // TC1: submitting with a required item left blank must NOT
    // succeed - the form should redisplay with an error, session_end
    // must stay NULL, and nothing should land in questionnaire_response.
    // (Defensive check alongside QI2's own "happy path" below.)
    // -------------------------------------------------------------
    $incompleteAnswers = $validAnswers;
    unset($incompleteAnswers[1]); // drop the required order_completed_first answer

    $incompletePostFields = ['submit_questionnaire' => '1'];
    foreach ($incompleteAnswers as $itemId => $value) {
        $incompletePostFields["item_{$itemId}"] = $value;
    }

    [, $bodyIncomplete] = httpRequest($questionnaireUrl, $cookieJarPath, $incompletePostFields);

    if (strpos($bodyIncomplete, 'class="questionnaire-complete"') !== false) {
        $errors[] = 'TC1: an incomplete submission (missing a required answer) was accepted.';
    }
    if (strpos($bodyIncomplete, 'class="questionnaire-form"') === false) {
        $errors[] = 'TC1: an incomplete submission did not redisplay the form.';
    }

    $stmt = $pdo->prepare('SELECT session_end FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ($stmt->fetchColumn() !== null) {
        $errors[] = 'TC1: session_end was set even though the submission was incomplete.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questionnaire_response WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 0) {
        $errors[] = 'TC1: questionnaire_response rows were inserted despite the incomplete submission.';
    }

    // -------------------------------------------------------------
    // TC2: a complete, valid submission - QI2 itself. Expect the
    // completion screen, all 14 responses saved, and the session closed.
    // -------------------------------------------------------------
    $postFields = ['submit_questionnaire' => '1'];
    foreach ($validAnswers as $itemId => $value) {
        $postFields["item_{$itemId}"] = $value;
    }

    [$httpCode2, $body2] = httpRequest($questionnaireUrl, $cookieJarPath, $postFields);

    if ($httpCode2 !== 200) {
        $errors[] = "TC2: expected HTTP 200 after the post-redirect-GET, got {$httpCode2}.";
    }
    if (strpos($body2, 'class="questionnaire-complete"') === false) {
        $errors[] = 'TC2: submitting a complete questionnaire did not show the completion screen.';
    }
    if (strpos($body2, 'Thank you for completing the questionnaire.') === false) {
        $errors[] = 'TC2: completion screen is missing its confirmation message.';
    }
    if (strpos($body2, 'class="questionnaire-form"') !== false) {
        $errors[] = 'TC2: the form was still shown alongside the completion screen.';
    }

    $stmt = $pdo->prepare('SELECT session_end FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    $sessionEnd = $stmt->fetchColumn();
    if ($sessionEnd === null || $sessionEnd === false) {
        $errors[] = 'TC2: session_log.session_end was not set after a complete submission.';
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'session_end'"
    );
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        $errors[] = 'TC2: expected exactly 1 session_end event in event_log after submission.';
    }

    $stmt = $pdo->prepare(
        'SELECT item_id, response_value FROM questionnaire_response WHERE session_id = :id ORDER BY item_id'
    );
    $stmt->execute([':id' => $sessionId]);
    $savedResponses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // item_id => response_value

    if (count($savedResponses) !== 14) {
        $errors[] = 'TC2: expected 14 saved responses, found ' . count($savedResponses) . '.';
    }
    foreach ($validAnswers as $itemId => $expectedValue) {
        if (!array_key_exists($itemId, $savedResponses)) {
            $errors[] = "TC2: no saved response found for item_id {$itemId}.";
        } elseif ($savedResponses[$itemId] !== $expectedValue) {
            $errors[] = "TC2: item_id {$itemId} saved as \"{$savedResponses[$itemId]}\", expected \"{$expectedValue}\".";
        }
    }

    // -------------------------------------------------------------
    // TC3: submitting again (e.g. a stray resubmission after the
    // participant has already finished) must not create a second set
    // of responses or a second session_end event - questionnaire.php's
    // alreadyComplete guard should show the completion screen straight
    // away without touching the database.
    // -------------------------------------------------------------
    [, $body3] = httpRequest($questionnaireUrl, $cookieJarPath, $postFields);

    if (strpos($body3, 'class="questionnaire-complete"') === false) {
        $errors[] = 'TC3: resubmitting after completion did not show the completion screen.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questionnaire_response WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 14) {
        $errors[] = 'TC3: resubmitting created duplicate questionnaire_response rows.';
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'session_end'"
    );
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        $errors[] = 'TC3: resubmitting created a duplicate session_end event.';
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

echo "QI2 - Test submission\n";
echo str_repeat('-', 50) . "\n";
echo "Pages/endpoints tested: {$indexUrl}, {$questionnaireUrl}, {$timeoutUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "An incomplete submission is rejected and redisplays the form; a complete submission saves all 14 ";
    echo "responses, closes the session, and shows the completion screen; resubmitting afterwards changes nothing.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
