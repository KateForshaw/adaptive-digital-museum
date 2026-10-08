<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// tests/phase6/test_questionnaire_session_end.php
//
// Test case QI3 (Testing Plans.docx > Phase 6 Test Cases)
//   Description:     Test session_end logging
//   Steps:            Submit form
//   Expected Result:  session_end logged
//
// QI1 (test_questionnaire_load.php) already covers questionnaire.php
// loading, and QI2 (test_questionnaire_submission.php) already covers
// the full UI submission flow end-to-end, including that session_end
// gets set. Rather than repeat that same UI walkthrough a third time,
// this test mirrors BL7 (tests/phase2/test_session_end.php): it calls
// api/post_questionnaire_response.php directly with cURL, and checks
// its session-closing behaviour thoroughly - exact session_end value,
// exactly one matching event_log row, idempotency/rejection on a
// second call, and its validation/error responses.
//
// One real difference from api/session_end.php (BL7's endpoint):
// post_questionnaire_response.php REJECTS a second call on an
// already-closed session (409), it does not silently no-op like
// session_end.php's idempotent 200 - see that file's header comment
// for why (a second call means a second, unwanted set of responses
// would otherwise need to be accepted too).
//
// Creates a throwaway participant/session directly in the database
// (mirrors the state a real browsing flow would leave behind, without
// re-running index.php/log_timeout.php - those are exercised by QI1/
// QI2 already), then deletes everything afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/post_questionnaire_response.php';

$errors               = [];
$testParticipantCode  = 'TEST_QI3_' . time(); // participant_code is VARCHAR(20) - keep prefix short
$participantId        = null;
$sessionId            = null;

// A couple of real, valid answers - enough to exercise the
// session-closing logic without duplicating QI2's full 14-item check.
$sampleResponses = [
    ['item_id' => 1, 'value' => 'Static version'],
    ['item_id' => 2, 'value' => '4'],
];

// ---------------------------------------------------------------------
// Helpers - same pattern as tests/phase2/test_session_end.php
// ---------------------------------------------------------------------
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

function getRequest(string $url): int
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode;
}

/** Creates a throwaway participant + open session_log row, returns [participantId, sessionId]. */
function createTestSession(PDO $pdo, string $participantCode): array
{
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => $participantCode]);
    $participantId = (int)$pdo->lastInsertId();

    $conditionId = $pdo->query('SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1')
        ->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag)
        VALUES
            (:participant_id, :condition_id, :session_start, TRUE, TRUE, FALSE)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => date('Y-m-d H:i:s', time() - 600), // started 10 minutes ago
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    return [$participantId, $sessionId];
}

try {
    // -------------------------------------------------------------
    // Setup: throwaway participant + open session, both timeout flags
    // already TRUE (mirrors a session that has genuinely finished both
    // browsing tasks, same state QI1/QI2 reach via the real timeout
    // endpoint).
    // -------------------------------------------------------------
    [$participantId, $sessionId] = createTestSession($pdo, $testParticipantCode);

    // -------------------------------------------------------------
    // TC1: a valid submission closes the session and logs exactly one
    // matching 'session_end' event (QI3's expected result).
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($endpoint, [
        'session_id'     => $sessionId,
        'participant_id' => $participantId,
        'responses'      => $sampleResponses,
    ]);

    if ($httpCode !== 200) {
        $errors[] = "TC1: expected HTTP 200, got {$httpCode}.";
    }
    if (!is_array($data) || ($data['success'] ?? false) !== true) {
        $errors[] = 'TC1: response did not have success = true. Body: ' . json_encode($data);
    } else {
        $stmt = $pdo->prepare('SELECT session_end FROM session_log WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $sessionEndInDb = $stmt->fetchColumn();

        if ($sessionEndInDb === null) {
            $errors[] = 'TC1: session_log.session_end is still NULL after a valid submission.';
        } elseif ($sessionEndInDb !== $data['session_end']) {
            $errors[] = 'TC1: session_log.session_end does not match the value returned by the endpoint.';
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM event_log WHERE session_id = :id AND event_type = 'session_end'"
        );
        $stmt->execute([':id' => $sessionId]);
        $eventRows = $stmt->fetchAll();

        if (count($eventRows) !== 1) {
            $errors[] = 'TC1: expected exactly 1 session_end event in event_log, found ' . count($eventRows) . '.';
        } elseif ($eventRows[0]['artefact_id'] !== null) {
            $errors[] = 'TC1: session_end event should have a NULL artefact_id (session-level event).';
        } elseif ($eventRows[0]['timestamp'] !== $sessionEndInDb) {
            $errors[] = 'TC1: session_end event timestamp does not match session_log.session_end.';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM questionnaire_response WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        if ((int)$stmt->fetchColumn() !== count($sampleResponses)) {
            $errors[] = 'TC1: questionnaire_response row count does not match the responses submitted.';
        }
    }

    // -------------------------------------------------------------
    // TC2: calling again on the same (now closed) session is REJECTED,
    // not idempotent - unlike api/session_end.php (BL7), a second call
    // here would mean accepting a second set of responses too, so it
    // must fail with 409 and leave the database untouched.
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($endpoint, [
        'session_id'     => $sessionId,
        'participant_id' => $participantId,
        'responses'      => $sampleResponses,
    ]);

    if ($httpCode2 !== 409 || ($data2['success'] ?? true) !== false) {
        $errors[] = "TC2: expected HTTP 409 + success=false when resubmitting a closed session, got {$httpCode2}.";
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'session_end'"
    );
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        $errors[] = 'TC2: resubmitting should not create a second session_end event.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questionnaire_response WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== count($sampleResponses)) {
        $errors[] = 'TC2: resubmitting should not insert duplicate questionnaire_response rows.';
    }

    // -------------------------------------------------------------
    // TC3: session_id integrity - a session_id that does not exist
    // (reported as a 500 server-side error, same convention as BL7's
    // TC3 for api/session_end.php)
    // -------------------------------------------------------------
    [$httpCode3, $data3] = postJson($endpoint, [
        'session_id'     => 999999999,
        'participant_id' => $participantId,
        'responses'      => $sampleResponses,
    ]);
    if ($httpCode3 !== 500 || ($data3['success'] ?? true) !== false) {
        $errors[] = "TC3: expected HTTP 500 + success=false for a non-existent session_id, got {$httpCode3}.";
    }

    // -------------------------------------------------------------
    // TC4: participant_id integrity - a participant_id that doesn't
    // match the session's own participant_id (this check has no BL7
    // equivalent - api/session_end.php doesn't take a participant_id
    // at all, but responses must be attributed to the right person)
    // -------------------------------------------------------------
    [$httpCode4, $data4] = postJson($endpoint, [
        'session_id'     => $sessionId,
        'participant_id' => 999999999,
        'responses'      => $sampleResponses,
    ]);
    if ($httpCode4 !== 500 || ($data4['success'] ?? true) !== false) {
        $errors[] = "TC4: expected HTTP 500 + success=false for a mismatched participant_id, got {$httpCode4}.";
    }

    // -------------------------------------------------------------
    // TC5: missing responses -> 400
    // -------------------------------------------------------------
    [$httpCode5, $data5] = postJson($endpoint, [
        'session_id'     => $sessionId,
        'participant_id' => $participantId,
    ]);
    if ($httpCode5 !== 400 || ($data5['success'] ?? true) !== false) {
        $errors[] = "TC5: expected HTTP 400 + success=false for missing responses, got {$httpCode5}.";
    }

    // -------------------------------------------------------------
    // TC6: wrong HTTP method (GET) -> 405
    // -------------------------------------------------------------
    $httpCode6 = getRequest($endpoint);
    if ($httpCode6 !== 405) {
        $errors[] = "TC6: expected HTTP 405 for a GET request, got {$httpCode6}.";
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - deleting the
    // participant cascades to session_log, event_log, and
    // questionnaire_response (ON DELETE CASCADE - see create_tables.sql).
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "QI3 - Test session_end logging\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session used for test: ' . ($sessionId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "A valid submission closes the session and logs exactly one matching session_end event; ";
    echo "resubmitting a closed session is rejected (not silently accepted); and invalid requests ";
    echo "(unknown session, mismatched participant, missing responses, wrong method) are all rejected correctly.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
