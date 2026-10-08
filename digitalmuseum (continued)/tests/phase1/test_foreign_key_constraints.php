<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_foreign_key_constraints.php
//
// Test case DF3 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Test foreign key constraints
//   Steps:            Attempt to insert artefact with invalid subtheme_id
//   Expected Result:  Insert rejected with FK error
//
// Runs inside a transaction that is always rolled back, so this test
// never leaves data behind even if the FK constraint turns out not to
// be enforced (the row-count check below catches that case too).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$invalidSubthemeId = 999999; // does not exist in the subtheme table
$testExternalId    = 'test_fk_df3_' . time();

$countBefore = (int)$pdo->query('SELECT COUNT(*) FROM artefact')->fetchColumn();

$pdo->beginTransaction();
$insertRejected = false;
$errorMessage   = '';

try {
    $stmt = $pdo->prepare("
        INSERT INTO artefact
            (theme_id, subtheme_id, external_id, artefact_title, artefact_description, image_url, source_url, tags)
        VALUES
            (:theme_id, :subtheme_id, :external_id, :title, :description, :image_url, :source_url, :tags)
    ");
    $stmt->execute([
        ':theme_id'    => 1,
        ':subtheme_id' => $invalidSubthemeId,
        ':external_id' => $testExternalId,
        ':title'       => 'DF3 Test Artefact (should not be inserted)',
        ':description' => 'Temporary row used to test FK constraint enforcement.',
        ':image_url'   => 'https://example.com/test.jpg',
        ':source_url'  => 'https://example.com/test',
        ':tags'        => 'test',
    ]);
} catch (PDOException $e) {
    $insertRejected = true;
    $errorMessage   = $e->getMessage();
}

if ($pdo->inTransaction()) {
    $pdo->rollBack();
}

$countAfter = (int)$pdo->query('SELECT COUNT(*) FROM artefact')->fetchColumn();

echo "DF3 - Test foreign key constraints\n";
echo str_repeat('-', 50) . "\n";
echo "Attempted: INSERT INTO artefact with subtheme_id = {$invalidSubthemeId} (does not exist)\n";
echo "Artefact row count before: {$countBefore}, after: {$countAfter}\n\n";

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
    echo "Insert succeeded when it should have been rejected - the foreign key constraint on artefact.subtheme_id is not being enforced.\n";
} elseif (!$isForeignKeyError) {
    echo "Insert was rejected, but not with the expected foreign key error:\n - {$errorMessage}\n";
}
if ($countAfter !== $countBefore) {
    echo "Row count changed unexpectedly ({$countBefore} -> {$countAfter}).\n";
}
exit(1);
