<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// tests/phase2/test_session_start.php
//
// Test case BL5 (Testing Plans.docx > Phase 2 Test Cases)
//   Description:     Test session_start
//   Steps:            Load home page
//   Expected Result:  session_start event logged
//
// Unlike the Phase 1 tests, this exercises the real HTTP endpoint
// (api/session_start.php) with cURL rather than querying the database
// directly - that's the only way to test the request validation and
// JSON handling that actually runs when the frontend calls it.
//
// Creates throwaway participant(s)/session(s)/event(s), verifies them,
// then deletes them - leaving the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/session_start.php';

$errors = [];
$testParticipantCode = 'TEST_BL5_' . time();
$createdParticipantIds = [];
$createdSessionIds     = [];

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
    // TC1: Valid request creates a participant, a session, and a
    //      matching 'session_start' event
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($endpoint, [
        'participant_code' => $testParticipantCode,
        'condition_name'   => 'static_first',
    ]);

    if ($httpCode !== 201) {
        $errors[] = "TC1: expected HTTP 201, got {$httpCode}.";
    }
    if (!is_array($data) || ($data['success'] ?? false) !== true) {
        $errors[] = 'TC1: response did not have success = true. Body: ' . json_encode($data);
    } else {
        $participantId = (int)$data['participant_id'];
        $sessionId     = (int)$data['session_id'];
        $createdParticipantIds[] = $participantId;
        $createdSessionIds[]     = $sessionId;

        // Verify session_log row
        $stmt = $pdo->prepare('SELECT * FROM session_log WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $sessionRow = $stmt->fetch();

        if (!$sessionRow) {
            $errors[] = "TC1: session_id {$sessionId} was returned but no session_log row was found.";
        } else {
            if ((int)$sessionRow['participant_id'] !== $participantId) {
                $errors[] = 'TC1: session_log.participant_id does not match the participant created.';
            }
            if ($sessionRow['session_start'] !== $data['session_start']) {
                $errors[] = 'TC1: session_log.session_start does not match the value returned by the endpoint.';
            }
            if ($sessionRow['session_end'] !== null) {
                $errors[] = 'TC1: session_log.session_end should be NULL for a freshly started session.';
            }
        }

        // Verify the matching event_log row (this is what BL5 actually checks)
        $stmt = $pdo->prepare(
            "SELECT * FROM event_log WHERE session_id = :id AND event_type = 'session_start'"
        );
        $stmt->execute([':id' => $sessionId]);
        $eventRows = $stmt->fetchAll();

        if (count($eventRows) !== 1) {
            $errors[] = 'TC1: expected exactly 1 session_start event in event_log, found ' . count($eventRows) . '.';
        } elseif ($eventRows[0]['artefact_id'] !== null) {
            $errors[] = 'TC1: session_start event should have a NULL artefact_id (session-level event).';
        }
    }

    // -------------------------------------------------------------
    // TC2: Calling again with the SAME participant_code reuses the
    //      participant but creates a NEW session (find-or-create)
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($endpoint, [
        'participant_code' => $testParticipantCode,
        'condition_name'   => 'adaptive_first',
    ]);

    if ($httpCode2 !== 201 || ($data2['success'] ?? false) !== true) {
        $errors[] = 'TC2: second call with the same participant_code did not succeed. Body: ' . json_encode($data2);
    } else {
        $createdParticipantIds[] = (int)$data2['participant_id'];
        $createdSessionIds[]     = (int)$data2['session_id'];

        if ((int)$data2['participant_id'] !== (int)$data['participant_id']) {
            $errors[] = 'TC2: same participant_code should resolve to the same participant_id.';
        }
        if ((int)$data2['session_id'] === (int)$data['session_id']) {
            $errors[] = 'TC2: a second session_start call should create a new session_id, not reuse the first.';
        }
    }

    // -------------------------------------------------------------
    // TC3: Missing participant_code -> 400, no row created
    // -------------------------------------------------------------
    [$httpCode3, $data3] = postJson($endpoint, [
        'condition_name' => 'static_first',
    ]);
    if ($httpCode3 !== 400 || ($data3['success'] ?? true) !== false) {
        $errors[] = "TC3: expected HTTP 400 + success=false for missing participant_code, got {$httpCode3}.";
    }

    // -------------------------------------------------------------
    // TC4: Invalid condition_name -> 400
    // -------------------------------------------------------------
    [$httpCode4, $data4] = postJson($endpoint, [
        'participant_code' => $testParticipantCode,
        'condition_name'   => 'not_a_real_condition',
    ]);
    if ($httpCode4 !== 400 || ($data4['success'] ?? true) !== false) {
        $errors[] = "TC4: expected HTTP 400 + success=false for invalid condition_name, got {$httpCode4}.";
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
    foreach (array_unique($createdParticipantIds) as $id) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $id]);
    }
}

echo "BL5 - Test session_start\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session(s) created during test: ' . implode(', ', $createdSessionIds) . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "session_start creates a session_log row and a matching session_start event_log row, ";
    echo "reuses existing participants, and rejects invalid requests correctly.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
