<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// tests/phase2/test_session_end.php
//
// Test case BL7 (Testing Plans.docx > Phase 2 Test Cases)
//   Description:     Test session_end
//   Steps:            Submit questionnaire / end session
//   Expected Result:  session_end event logged
//
// Like test_session_start.php, this exercises the real HTTP endpoint
// (api/session_end.php) with cURL rather than querying the database
// directly, so the request validation and JSON handling that the real
// frontend depends on actually gets tested.
//
// Creates a throwaway participant/session directly in the database
// (session_start.php is not involved here - that's tested separately),
// then verifies session_end.php against it, and deletes everything
// afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/session_end.php';

$errors = [];
$testParticipantCode = 'TEST_SE_' . time(); // participant_code is VARCHAR(20) - keep prefix short
$participantId = null;
$sessionId     = null;

// ---------------------------------------------------------------------
// Helper: POST JSON to the endpoint via cURL, return [httpCode, decodedBody]
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

try {
    // -------------------------------------------------------------
    // Setup: create a throwaway participant + open session directly
    // (mirrors the state session_start.php would have left behind)
    // -------------------------------------------------------------
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => $testParticipantCode]);
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
            (:participant_id, :condition_id, :session_start, FALSE, FALSE, FALSE)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => date('Y-m-d H:i:s', time() - 60), // started a minute ago
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // -------------------------------------------------------------
    // TC1: Valid request closes the session and logs a matching
    //      'session_end' event
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($endpoint, ['session_id' => $sessionId]);

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
            $errors[] = 'TC1: session_log.session_end is still NULL after calling session_end.';
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
        }
    }

    // -------------------------------------------------------------
    // TC2: Calling session_end again on the same (now closed) session
    //      is idempotent - no error, no second event_log row
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($endpoint, ['session_id' => $sessionId]);

    if ($httpCode2 !== 200 || ($data2['success'] ?? false) !== true) {
        $errors[] = "TC2: calling session_end twice should still return 200/success=true, got {$httpCode2}.";
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'session_end'"
    );
    $stmt->execute([':id' => $sessionId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        $errors[] = 'TC2: calling session_end twice should not create a second session_end event.';
    }

    // -------------------------------------------------------------
    // TC3: session_id integrity - a session_id that does not exist
    //      (the endpoint reports this as a 500 server-side error,
    //      not a 400 validation error - see api/session_end.php)
    // -------------------------------------------------------------
    [$httpCode3, $data3] = postJson($endpoint, ['session_id' => 999999999]);
    if ($httpCode3 !== 500 || ($data3['success'] ?? true) !== false) {
        $errors[] = "TC3: expected HTTP 500 + success=false for a non-existent session_id, got {$httpCode3}.";
    }

    // -------------------------------------------------------------
    // TC4: Missing session_id -> 400
    // -------------------------------------------------------------
    [$httpCode4, $data4] = postJson($endpoint, []);
    if ($httpCode4 !== 400 || ($data4['success'] ?? true) !== false) {
        $errors[] = "TC4: expected HTTP 400 + success=false for a missing session_id, got {$httpCode4}.";
    }

    // -------------------------------------------------------------
    // TC5: Wrong HTTP method (GET) -> 405
    // -------------------------------------------------------------
    $httpCode5 = getRequest($endpoint);
    if ($httpCode5 !== 405) {
        $errors[] = "TC5: expected HTTP 405 for a GET request, got {$httpCode5}.";
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed. Deleting the
    // participant cascades to session_log (ON DELETE CASCADE) and from
    // there to event_log (ON DELETE CASCADE) - see create_tables.sql.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "BL7 - Test session_end\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session used for test: ' . ($sessionId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "session_end closes the session, logs exactly one matching session_end event, ";
    echo "stays idempotent on repeat calls, and rejects invalid requests correctly.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
