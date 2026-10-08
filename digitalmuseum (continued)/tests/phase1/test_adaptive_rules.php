<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_adaptive_rules.php
//
// Test case DF8 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Verify adaptive_rule table populated correctly
//   Steps:            Run SELECT * FROM adaptive_rule
//   Expected Result:  All 27 rules appear with active_flag = TRUE and
//                     valid trigger_condition JSON
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$expectedCount = 27;
$errors = [];
$seenNames = [];

$stmt = $pdo->query('SELECT * FROM adaptive_rule ORDER BY rule_id');
$rows = $stmt->fetchAll();

$actualCount = count($rows);
if ($actualCount !== $expectedCount) {
    $errors[] = "Expected {$expectedCount} rules, found {$actualCount}.";
}

foreach ($rows as $row) {
    $id   = $row['rule_id'];
    $name = $row['rule_name'] ?? '(unknown)';

    if (trim((string)$row['rule_name']) === '') {
        $errors[] = "Rule {$id}: rule_name is empty.";
    } elseif (in_array($name, $seenNames, true)) {
        $errors[] = "Rule {$id}: rule_name '{$name}' is duplicated.";
    } else {
        $seenNames[] = $name;
    }

    if (trim((string)$row['rule_description']) === '') {
        $errors[] = "Rule {$id} ('{$name}'): rule_description is empty.";
    }

    // active_flag is stored as a MySQL BOOLEAN (TINYINT), so compare loosely
    if ((int)$row['active_flag'] !== 1) {
        $errors[] = "Rule {$id} ('{$name}'): active_flag is not TRUE (got '{$row['active_flag']}').";
    }

    $rawCondition = $row['trigger_condition'];
    if (trim((string)$rawCondition) === '') {
        $errors[] = "Rule {$id} ('{$name}'): trigger_condition is empty.";
        continue;
    }

    $decoded = json_decode($rawCondition, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = "Rule {$id} ('{$name}'): trigger_condition is not valid JSON - " . json_last_error_msg();
        continue;
    }

    if (!is_array($decoded) || !isset($decoded['signal'])) {
        $errors[] = "Rule {$id} ('{$name}'): trigger_condition JSON is missing a 'signal' key.";
    }
    if (!isset($decoded['priority'])) {
        $errors[] = "Rule {$id} ('{$name}'): trigger_condition JSON is missing a 'priority' key.";
    }
    if (!isset($decoded['effect'])) {
        $errors[] = "Rule {$id} ('{$name}'): trigger_condition JSON is missing an 'effect' key.";
    }
}

echo "DF8 - Verify adaptive_rule table populated correctly\n";
echo str_repeat('-', 50) . "\n";
echo "Rows returned: {$actualCount} (expected {$expectedCount})\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "All {$actualCount} rules are present, active, with unique names and "
        . "well-formed trigger_condition JSON (signal, priority, effect all present).\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
