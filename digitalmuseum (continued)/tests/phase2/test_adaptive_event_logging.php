<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// tests/phase2/test_adaptive_event_logging.php
//
// Test case BL8 (Testing Plans.docx > Phase 2 Test Cases)
//   Description:     Test adaptive event logging
//   Steps:            Simulate adaptive_trigger, panel_impression, panel_click
//   Expected Result:  Adaptive events logged correctly to event_log and adaptive_event
//
// The adaptive engine itself is not built until Phase 4/5, so this
// test simulates the calls it will eventually make. It exists now
// because api/log_adaptive_event.php is part of Phase 2's logging
// pipeline and needs to be verified ahead of the engine calling it.
//
// Covers all 3 adaptive-only event types (Behaviour Logging Map.docx >
// "Adaptive-Only Events"): adaptive_trigger, panel_impression, panel_click.
//
// Like the other Phase 2 tests, this exercises the real HTTP endpoint
// (api/log_adaptive_event.php) with cURL rather than querying the
// database directly.
//
// Creates a throwaway participant/session/adaptive_suggestion directly
// in the database, uses a real (already-seeded) rule_id and artefact_id,
// then deletes everything afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/log_adaptive_event.php';

$errors = [];
$testParticipantCode = 'TEST_AE_' . time(); // participant_code is VARCHAR(20) - keep prefix short
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

function fetchEvent(PDO $pdo, int $eventId)
{
    $stmt = $pdo->prepare('SELECT * FROM event_log WHERE event_id = :id');
    $stmt->execute([':id' => $eventId]);
    return $stmt->fetch();
}

function fetchAdaptiveEvent(PDO $pdo, int $adaptiveEventId)
{
    $stmt = $pdo->prepare('SELECT * FROM adaptive_event WHERE adaptive_event_id = :id');
    $stmt->execute([':id' => $adaptiveEventId]);
    return $stmt->fetch();
}

try {
    // -------------------------------------------------------------
    // Setup: throwaway participant + open session, a real rule_id,
    // a real artefact_id, and a throwaway adaptive_suggestion row
    // linking them (needed for the suggestion_id integrity checks)
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

    $ruleId = $pdo->query('SELECT rule_id FROM adaptive_rule ORDER BY rule_id LIMIT 1')->fetchColumn();
    if ($ruleId === false) {
        throw new RuntimeException('adaptive_rule table is empty - run insert_rules.sql first.');
    }
    $ruleId = (int)$ruleId;

    $artefactId = $pdo->query('SELECT artefact_id FROM artefact ORDER BY artefact_id LIMIT 1')->fetchColumn();
    if ($artefactId === false) {
        throw new RuntimeException('artefact table is empty - run insert_metadata.sql first.');
    }
    $artefactId = (int)$artefactId;

    $stmt = $pdo->prepare("
        INSERT INTO adaptive_suggestion
            (rule_id, session_id, artefact_id, suggestion_timestamp, panel_position, clicked_flag)
        VALUES
            (:rule_id, :session_id, :artefact_id, :timestamp, 1, FALSE)
    ");
    $stmt->execute([
        ':rule_id'     => $ruleId,
        ':session_id'  => $sessionId,
        ':artefact_id' => $artefactId,
        ':timestamp'   => date('Y-m-d H:i:s'),
    ]);
    $suggestionId = (int)$pdo->lastInsertId();

    // -------------------------------------------------------------
    // TC1: adaptive_trigger without a suggestion yet (the trigger
    // fires before the engine has generated a suggestion)
    // -------------------------------------------------------------
    [$httpCode1, $data1] = postJson($endpoint, [
        'session_id' => $sessionId,
        'event_type' => 'adaptive_trigger',
        'rule_id'    => $ruleId,
    ]);

    if ($httpCode1 !== 201 || ($data1['success'] ?? false) !== true) {
        $errors[] = "TC1: expected HTTP 201 + success=true for adaptive_trigger, got {$httpCode1}.";
    } else {
        $eventRow = fetchEvent($pdo, (int)$data1['event_id']);
        if (!$eventRow || $eventRow['event_type'] !== 'adaptive_trigger' || $eventRow['suggestion_id'] !== null) {
            $errors[] = 'TC1: adaptive_trigger event_log row was not stored correctly (expected NULL suggestion_id).';
        }
        $adaptiveRow = fetchAdaptiveEvent($pdo, (int)$data1['adaptive_event_id']);
        if (!$adaptiveRow || (int)$adaptiveRow['rule_id'] !== $ruleId || $adaptiveRow['suggestion_id'] !== null) {
            $errors[] = 'TC1: adaptive_event row was not stored correctly.';
        }
    }

    // -------------------------------------------------------------
    // TC2: panel_impression with a real suggestion_id, artefact_id,
    // and trigger_confidence
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($endpoint, [
        'session_id'         => $sessionId,
        'event_type'         => 'panel_impression',
        'rule_id'            => $ruleId,
        'suggestion_id'      => $suggestionId,
        'artefact_id'        => $artefactId,
        'trigger_confidence' => 0.85,
    ]);

    if ($httpCode2 !== 201 || ($data2['success'] ?? false) !== true) {
        $errors[] = "TC2: expected HTTP 201 + success=true for panel_impression, got {$httpCode2}.";
    } else {
        $eventRow = fetchEvent($pdo, (int)$data2['event_id']);
        if (!$eventRow || (int)$eventRow['suggestion_id'] !== $suggestionId || (int)$eventRow['artefact_id'] !== $artefactId) {
            $errors[] = 'TC2: panel_impression event_log row was not stored with the correct suggestion_id/artefact_id.';
        }
        $adaptiveRow = fetchAdaptiveEvent($pdo, (int)$data2['adaptive_event_id']);
        if (!$adaptiveRow || abs((float)$adaptiveRow['trigger_confidence'] - 0.85) > 0.001) {
            $errors[] = 'TC2: adaptive_event.trigger_confidence was not stored correctly.';
        }
    }

    // -------------------------------------------------------------
    // TC3: panel_click with the same suggestion
    // -------------------------------------------------------------
    [$httpCode3, $data3] = postJson($endpoint, [
        'session_id'    => $sessionId,
        'event_type'    => 'panel_click',
        'rule_id'       => $ruleId,
        'suggestion_id' => $suggestionId,
        'artefact_id'   => $artefactId,
    ]);
    if ($httpCode3 !== 201 || ($data3['success'] ?? false) !== true) {
        $errors[] = "TC3: expected HTTP 201 + success=true for panel_click, got {$httpCode3}.";
    }

    // -------------------------------------------------------------
    // Validation + integrity checks
    // -------------------------------------------------------------
    [$httpCodeType, $dataType] = postJson($endpoint, [
        'session_id' => $sessionId,
        'event_type' => 'not_a_real_event_type',
        'rule_id'    => $ruleId,
    ]);
    if ($httpCodeType !== 400 || ($dataType['success'] ?? true) !== false) {
        $errors[] = "Validation: invalid event_type should return 400, got {$httpCodeType}.";
    }

    [$httpCodeNoRule, $dataNoRule] = postJson($endpoint, [
        'session_id' => $sessionId,
        'event_type' => 'adaptive_trigger',
    ]);
    if ($httpCodeNoRule !== 400 || ($dataNoRule['success'] ?? true) !== false) {
        $errors[] = "Validation: missing rule_id should return 400, got {$httpCodeNoRule}.";
    }

    [$httpCodeBadRule, $dataBadRule] = postJson($endpoint, [
        'session_id' => $sessionId,
        'event_type' => 'adaptive_trigger',
        'rule_id'    => 999999999,
    ]);
    if ($httpCodeBadRule !== 500 || ($dataBadRule['success'] ?? true) !== false) {
        $errors[] = "Validation: non-existent rule_id should return 500, got {$httpCodeBadRule}.";
    }

    [$httpCodeBadSugg, $dataBadSugg] = postJson($endpoint, [
        'session_id'    => $sessionId,
        'event_type'    => 'panel_impression',
        'rule_id'       => $ruleId,
        'suggestion_id' => 999999999,
    ]);
    if ($httpCodeBadSugg !== 500 || ($dataBadSugg['success'] ?? true) !== false) {
        $errors[] = "Validation: non-existent suggestion_id should return 500, got {$httpCodeBadSugg}.";
    }

    [$httpCodeBadSess, $dataBadSess] = postJson($endpoint, [
        'session_id' => 999999999,
        'event_type' => 'adaptive_trigger',
        'rule_id'    => $ruleId,
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
    // event_log and adaptive_suggestion, which in turn cascades to
    // adaptive_event - see create_tables.sql. The rule and artefact
    // used are pre-existing seed data and are never modified.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "BL8 - Test adaptive event logging (adaptive_trigger, panel_impression, panel_click)\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session used for test: ' . ($sessionId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "adaptive_trigger, panel_impression, and panel_click events all log correctly to both ";
    echo "event_log and adaptive_event, and invalid requests are rejected.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
