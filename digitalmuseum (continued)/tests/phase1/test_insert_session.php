<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_insert_session.php
//
// Test case DF4 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Test session insertion
//   Steps:            Insert dummy session into session_log
//   Expected Result:  Session appears with correct timestamp
//
// session_log.participant_id is a NOT NULL foreign key, and the
// participant table is intentionally left empty as seed data (real
// participants only). So this test creates a throwaway participant,
// uses it for the dummy session, then deletes both at the end -
// leaving the database exactly as it was before the test ran.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$errors = [];
$testParticipantCode = 'TEST_DF4_' . time();
$testSessionStart    = date('Y-m-d H:i:s'); // fixed value we can verify against exactly

$participantId = null;
$sessionId     = null;

try {
    // 1. Create a throwaway participant
    $stmt = $pdo->prepare(
        "INSERT INTO participant (participant_code, participant_notes) VALUES (:code, :notes)"
    );
    $stmt->execute([
        ':code'  => $testParticipantCode,
        ':notes' => 'Temporary participant created by DF4 test - safe to ignore/delete.',
    ]);
    $participantId = (int)$pdo->lastInsertId();

    // 2. Fetch a real condition_id (study_condition must be seeded - see insert_conditions.sql)
    $conditionId = $pdo->query(
        "SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1"
    )->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    // 3. Insert the dummy session
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
        ':notes'          => 'Temporary session created by DF4 test - safe to ignore/delete.',
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // 4. Retrieve it back and verify
    $stmt = $pdo->prepare("SELECT * FROM session_log WHERE session_id = :id");
    $stmt->execute([':id' => $sessionId]);
    $row = $stmt->fetch();

    if (!$row) {
        $errors[] = "Session {$sessionId} was inserted but could not be retrieved.";
    } else {
        if ((int)$row['participant_id'] !== $participantId) {
            $errors[] = "participant_id mismatch: expected {$participantId}, got {$row['participant_id']}.";
        }
        if ((int)$row['condition_id'] !== (int)$conditionId) {
            $errors[] = "condition_id mismatch: expected {$conditionId}, got {$row['condition_id']}.";
        }
        if ($row['session_start'] !== $testSessionStart) {
            $errors[] = "session_start mismatch: expected '{$testSessionStart}', got '{$row['session_start']}'.";
        }
        if ($row['session_end'] !== null) {
            $errors[] = "session_end should be NULL for an in-progress session, got '{$row['session_end']}'.";
        }
    }
} catch (Exception $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed
    if ($sessionId) {
        $pdo->prepare("DELETE FROM session_log WHERE session_id = :id")->execute([':id' => $sessionId]);
    }
    if ($participantId) {
        $pdo->prepare("DELETE FROM participant WHERE participant_id = :id")->execute([':id' => $participantId]);
    }
}

echo "DF4 - Test session insertion\n";
echo str_repeat('-', 50) . "\n";
echo "Inserted dummy session_id: " . ($sessionId ?? 'n/a') . " with session_start = {$testSessionStart}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Session was inserted and retrieved with the correct participant_id, condition_id, and timestamp.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
