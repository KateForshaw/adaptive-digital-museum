<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_study_conditions.php
//
// Test case DF6 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Verify study_condition table populated correctly
//   Steps:            Run SELECT * FROM study_condition
//   Expected Result:  Both static_first and adaptive_first rows appear
//                     with descriptions
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$expectedCount = 2;
$expectedNames = ['static_first', 'adaptive_first'];
$errors = [];

$stmt = $pdo->query('SELECT * FROM study_condition');
$rows = $stmt->fetchAll();

$actualCount = count($rows);
if ($actualCount !== $expectedCount) {
    $errors[] = "Expected {$expectedCount} rows, found {$actualCount}.";
}

$foundNames = array_column($rows, 'condition_name');

foreach ($expectedNames as $name) {
    if (!in_array($name, $foundNames, true)) {
        $errors[] = "Missing expected condition_name '{$name}'.";
    }
}

foreach ($rows as $row) {
    $name = $row['condition_name'] ?? '(unknown)';
    if (!array_key_exists('condition_description', $row) || trim((string)$row['condition_description']) === '') {
        $errors[] = "Condition '{$name}': condition_description is empty.";
    }
}

echo "DF6 - Verify study_condition table populated correctly\n";
echo str_repeat('-', 50) . "\n";
echo "Rows returned: {$actualCount} (expected {$expectedCount})\n";
echo "condition_name values found: " . implode(', ', $foundNames) . "\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Both 'static_first' and 'adaptive_first' rows are present, each with a description.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
