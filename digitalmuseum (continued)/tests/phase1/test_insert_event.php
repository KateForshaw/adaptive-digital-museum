<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_insert_event.php
//
// Test case DF5 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Test event insertion
//   Steps:            Insert dummy event into event_log
//   Expected Result:  Event stored with correct session_id + artefact_id
//
// event_log.session_id is a NOT NULL foreign key, so this test builds
// the chain it needs (throwaway participant -> throwaway session) and
// borrows a real, existing artefact_id rather than inventing one, then
// deletes everything it created at the end - leaving the database
// exactly as it was before the test ran.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$errors = [];
$testParticipantCode = 'TEST_DF5_' . time();
$testSessionStart     = date('Y-m-d H:i:s');
$testEventTimestamp   = date('Y-m-d H:i:s');
$testEventType        = 'click';

$participantId = null;
$sessionId     = null;
$artefactId    = null;
$eventId       = null;

try {
    // 1. Throwaway participant (session_log.participant_id is NOT NULL)
    $stmt = $pdo->prepare(
        "INSERT INTO participant (participant_code, participant_notes) VALUES (:code, :notes)"
    );
    $stmt->execute([
        ':code'  => $testParticipantCode,
        ':notes' => 'Temporary participant created by DF5 test - safe to ignore/delete.',
    ]);
    $participantId = (int)$pdo->lastInsertId();

    // 2. Real condition_id (study_condition must be seeded - see insert_conditions.sql)
    $conditionId = $pdo->query(
        "SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1"
    )->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    // 3. Throwaway session (event_log.session_id is NOT NULL)
    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start, session_end,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag, session_notes)
        VALUES
            (:participant_id, :condition_id, :session_start, NULL, FALSE, FALSE, FALSE, :notes)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => $testSessionStart,
        ':notes'          => 'Temporary session created by DF5 test - safe to ignore/delete.',
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // 4. Real artefact_id (artefact table must be seeded - see insert_metadata.sql)
    $artefactId = $pdo->query(
        "SELECT artefact_id FROM artefact ORDER BY artefact_id LIMIT 1"
    )->fetchColumn();
    if ($artefactId === false) {
        throw new RuntimeException('artefact table is empty - run insert_metadata.sql first.');
    }

    // 5. Insert the dummy event
    $stmt = $pdo->prepare("
        INSERT INTO event_log
            (session_id, artefact_id, suggestion_id, rule_id, event_type, `timestamp`, dwell_duration, navigation_depth)
        VALUES
            (:session_id, :artefact_id, NULL, NULL, :event_type, :timestamp, NULL, NULL)
    ");
    $stmt->execute([
        ':session_id'  => $sessionId,
        ':artefact_id' => $artefactId,
        ':event_type'  => $testEventType,
        ':timestamp'   => $testEventTimestamp,
    ]);
    $eventId = (int)$pdo->lastInsertId();

    // 6. Retrieve it back and verify
    $stmt = $pdo->prepare("SELECT * FROM event_log WHERE event_id = :id");
    $stmt->execute([':id' => $eventId]);
    $row = $stmt->fetch();

    if (!$row) {
        $errors[] = "Event {$eventId} was inserted but could not be retrieved.";
    } else {
        if ((int)$row['session_id'] !== $sessionId) {
            $errors[] = "session_id mismatch: expected {$sessionId}, got {$row['session_id']}.";
        }
        if ((int)$row['artefact_id'] !== (int)$artefactId) {
            $errors[] = "artefact_id mismatch: expected {$artefactId}, got {$row['artefact_id']}.";
        }
        if ($row['event_type'] !== $testEventType) {
            $errors[] = "event_type mismatch: expected '{$testEventType}', got '{$row['event_type']}'.";
        }
        if ($row['timestamp'] !== $testEventTimestamp) {
            $errors[] = "timestamp mismatch: expected '{$testEventTimestamp}', got '{$row['timestamp']}'.";
        }
    }
} catch (Exception $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed
    if ($eventId) {
        $pdo->prepare("DELETE FROM event_log WHERE event_id = :id")->execute([':id' => $eventId]);
    }
    if ($sessionId) {
        $pdo->prepare("DELETE FROM session_log WHERE session_id = :id")->execute([':id' => $sessionId]);
    }
    if ($participantId) {
        $pdo->prepare("DELETE FROM participant WHERE participant_id = :id")->execute([':id' => $participantId]);
    }
}

echo "DF5 - Test event insertion\n";
echo str_repeat('-', 50) . "\n";
echo "Inserted dummy event_id: " . ($eventId ?? 'n/a')
    . " for session_id=" . ($sessionId ?? 'n/a')
    . ", artefact_id=" . ($artefactId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Event was inserted and retrieved with the correct session_id, artefact_id, event_type, and timestamp.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
