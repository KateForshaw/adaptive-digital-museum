<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_condition_fk_constraint.php
//
// Test case DF9 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Test session_log foreign key to study_condition
//   Steps:            Attempt to insert session_log row with invalid
//                     condition_id
//   Expected Result:  Insert rejected with FK error
//
// Runs inside a transaction that is always rolled back (participant
// included), so this test never leaves data behind even if the FK
// constraint turns out not to be enforced (the row-count check below
// catches that case too).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$invalidConditionId  = 999999; // does not exist in study_condition
$testParticipantCode = 'TEST_DF9_' . time();

$countBefore = (int)$pdo->query('SELECT COUNT(*) FROM session_log')->fetchColumn();

$pdo->beginTransaction();
$insertRejected = false;
$errorMessage   = '';

try {
    // session_log.participant_id is NOT NULL, so a valid participant is
    // needed first - created inside this same transaction so it is
    // rolled back along with everything else.
    $stmt = $pdo->prepare(
        "INSERT INTO participant (participant_code, participant_notes) VALUES (:code, :notes)"
    );
    $stmt->execute([
        ':code'  => $testParticipantCode,
        ':notes' => 'Temporary participant created by DF9 test - rolled back, never persisted.',
    ]);
    $participantId = (int)$pdo->lastInsertId();

    // Attempt the session insert with an invalid condition_id
    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start, session_end,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag, session_notes)
        VALUES
            (:participant_id, :condition_id, :session_start, NULL, FALSE, FALSE, FALSE, :notes)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $invalidConditionId,
        ':session_start'  => date('Y-m-d H:i:s'),
        ':notes'          => 'DF9 test session (should not be inserted).',
    ]);
} catch (PDOException $e) {
    $insertRejected = true;
    $errorMessage   = $e->getMessage();
}

if ($pdo->inTransaction()) {
    $pdo->rollBack();
}

$countAfter = (int)$pdo->query('SELECT COUNT(*) FROM session_log')->fetchColumn();

echo "DF9 - Test session_log foreign key to study_condition\n";
echo str_repeat('-', 50) . "\n";
echo "Attempted: INSERT INTO session_log with condition_id = {$invalidConditionId} (does not exist)\n";
echo "session_log row count before: {$countBefore}, after: {$countAfter}\n\n";

$isForeignKeyError = $insertRejected && (
    strpos($errorMessage, '1452') !== false
    || strpos($errorMessage, 'FOREIGN KEY') !== false
    || strpos($errorMessage, '23000') !== false
);

if ($isForeignKeyError && $countAfter === $countBefore) {
    echo "RESULT: PASS\n";
    echo "Insert was correctly rejected with a foreign key constraint error, and no row was left behind:\n";
    echo " - {$errorMessage}\n";
    exit(0);
}

echo "RESULT: FAIL\n";
if (!$insertRejected) {
    echo "Insert succeeded when it should have been rejected - the foreign key constraint on session_log.condition_id is not being enforced.\n";
} elseif (!$isForeignKeyError) {
    echo "Insert was rejected, but not with the expected foreign key error:\n - {$errorMessage}\n";
}
if ($countAfter !== $countBefore) {
    echo "Row count changed unexpectedly ({$countBefore} -> {$countAfter}).\n";
}
exit(1);
