<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_fetch_artefacts.php
//
// Test case DF1 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Verify artefact table loads correctly
//   Steps:            Run SELECT * FROM artefact
//   Expected Result:  All 60 artefacts appear with correct fields
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$expectedCount  = 60;
$requiredFields = [
    'artefact_id',
    'theme_id',
    'subtheme_id',
    'external_id',
    'artefact_title',
    'artefact_description',
    'image_url',
    'source_url',
];
// 'tags' is intentionally excluded above: it is NULL-able in the schema.

$errors = [];

$stmt = $pdo->query('SELECT * FROM artefact');
$rows = $stmt->fetchAll();

$actualCount = count($rows);
if ($actualCount !== $expectedCount) {
    $errors[] = "Expected {$expectedCount} artefacts, found {$actualCount}.";
}

foreach ($rows as $row) {
    $id = $row['artefact_id'] ?? '(unknown id)';
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $row)) {
            $errors[] = "Artefact {$id}: missing column '{$field}'.";
            continue;
        }
        if ($row[$field] === null || $row[$field] === '') {
            $errors[] = "Artefact {$id}: field '{$field}' is empty.";
        }
    }
}

echo "DF1 - Verify artefact table loads correctly\n";
echo str_repeat('-', 50) . "\n";
echo "Rows returned: {$actualCount} (expected {$expectedCount})\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "All {$actualCount} artefacts were returned, each with every required field populated.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
