<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// tests/phase2/test_timeout_logging.php
//
// Test case BL6 (Testing Plans.docx > Phase 2 Test Cases)
//   Description:     Test timeout event
//   Steps:            Trigger timeout manually
//   Expected Result:  timeout event logged with correct version
//
// Like the other Phase 2 tests, this exercises the real HTTP endpoint
// (api/log_timeout.php) with cURL rather than querying the database
// directly.
//
// Creates a throwaway participant/session directly in the database,
// triggers both a static and an adaptive timeout on it (mirroring the
// within-subjects design where one session times out on both versions
// - Study Protocol Design.docx), then deletes everything afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/log_timeout.php';

$errors = [];
$testParticipantCode = 'TEST_TO_' . time(); // participant_code is VARCHAR(20) - keep prefix short
$participantId = null;
$sessionId     = null;

// ---------------------------------------------------------------------
// Helpers
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
    // Setup: throwaway participant + open session
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
        ':session_start'  => date('Y-m-d H:i:s'),
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // -------------------------------------------------------------
    // BL6a: trigger a static timeout
    // -------------------------------------------------------------
    [$httpCode1, $data1] = postJson($endpoint, [
        'session_id'   => $sessionId,
        'version_name' => 'static',
    ]);

    if ($httpCode1 !== 201 || ($data1['success'] ?? false) !== true) {
        $errors[] = "BL6a: expected HTTP 201 + success=true for a static timeout, got {$httpCode1}.";
    } else {
        $stmt = $pdo->prepare('SELECT * FROM timeout WHERE timeout_id = :id');
        $stmt->execute([':id' => $data1['timeout_id']]);
        $timeoutRow = $stmt->fetch();

        if (!$timeoutRow || $timeoutRow['version_name'] !== 'static' || (int)$timeoutRow['auto_redirect_flag'] !== 1) {
            $errors[] = 'BL6a: timeout row was not stored correctly for the static version.';
        }

        $stmt = $pdo->prepare('SELECT timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $sessionFlags = $stmt->fetch();

        if (!$sessionFlags || (int)$sessionFlags['timeout_triggered_static'] !== 1) {
            $errors[] = 'BL6a: session_log.timeout_triggered_static should be TRUE after a static timeout.';
        }
        if ($sessionFlags && (int)$sessionFlags['timeout_triggered_adaptive'] !== 0) {
            $errors[] = 'BL6a: session_log.timeout_triggered_adaptive should still be FALSE at this point.';
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM event_log WHERE session_id = :id AND event_type = 'timeout_redirect'"
        );
        $stmt->execute([':id' => $sessionId]);
        $eventRows = $stmt->fetchAll();

        if (count($eventRows) !== 1) {
            $errors[] = 'BL6a: expected exactly 1 timeout_redirect event after the static timeout, found ' . count($eventRows) . '.';
        } elseif ($eventRows[0]['artefact_id'] !== null) {
            $errors[] = 'BL6a: timeout_redirect event should have a NULL artefact_id (session-level event).';
        }
    }

    // -------------------------------------------------------------
    // BL6b: trigger an adaptive timeout on the SAME session (a
    // participant times out once per version within one session)
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($endpoint, [
        'session_id'         => $sessionId,
        'version_name'       => 'adaptive',
        'auto_redirect_flag' => true,
        'timeout_notes'      => 'BL6 manual trigger test',
    ]);

    if ($httpCode2 !== 201 || ($data2['success'] ?? false) !== true) {
        $errors[] = "BL6b: expected HTTP 201 + success=true for an adaptive timeout, got {$httpCode2}.";
    } else {
        $stmt = $pdo->prepare('SELECT timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $sessionFlags = $stmt->fetch();

        if (!$sessionFlags || (int)$sessionFlags['timeout_triggered_adaptive'] !== 1) {
            $errors[] = 'BL6b: session_log.timeout_triggered_adaptive should be TRUE after an adaptive timeout.';
        }
        if ($sessionFlags && (int)$sessionFlags['timeout_triggered_static'] !== 1) {
            $errors[] = 'BL6b: session_log.timeout_triggered_static should still be TRUE from BL6a.';
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'timeout_redirect'"
        );
        $stmt->execute([':id' => $sessionId]);
        if ((int)$stmt->fetchColumn() !== 2) {
            $errors[] = 'BL6b: expected 2 timeout_redirect events total after both timeouts.';
        }
    }

    // -------------------------------------------------------------
    // Validation + integrity checks
    // -------------------------------------------------------------
    [$httpCodeVer, $dataVer] = postJson($endpoint, [
        'session_id'   => $sessionId,
        'version_name' => 'not_a_real_version',
    ]);
    if ($httpCodeVer !== 400 || ($dataVer['success'] ?? true) !== false) {
        $errors[] = "Validation: invalid version_name should return 400, got {$httpCodeVer}.";
    }

    [$httpCodeNoSess, $dataNoSess] = postJson($endpoint, [
        'version_name' => 'static',
    ]);
    if ($httpCodeNoSess !== 400 || ($dataNoSess['success'] ?? true) !== false) {
        $errors[] = "Validation: missing session_id should return 400, got {$httpCodeNoSess}.";
    }

    [$httpCodeBadSess, $dataBadSess] = postJson($endpoint, [
        'session_id'   => 999999999,
        'version_name' => 'static',
    ]);
    if ($httpCodeBadSess !== 500 || ($dataBadSess['success'] ?? true) !== false) {
        $errors[] = "Validation: non-existent session_id should return 500, got {$httpCodeBadSess}.";
    }

    $httpCodeMethod = getRequest($endpoint);
    if ($httpCodeMethod !== 405) {
        $errors[] = "Validation: expected HTTP 405 for a GET request, got {$httpCodeMethod}.";
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed. Deleting the
    // participant cascades to session_log, which cascades to both
    // event_log and timeout - see create_tables.sql.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "BL6 - Test timeout event\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session used for test: ' . ($sessionId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Static and adaptive timeouts both log a timeout row and a matching timeout_redirect event, ";
    echo "flip the correct session_log flag, and invalid requests are rejected.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
