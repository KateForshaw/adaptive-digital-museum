<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_questionnaire_items.php
//
// Test case DF7 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Verify questionnaire_item table populated correctly
//   Steps:            Run SELECT * FROM questionnaire_item ORDER BY item_order
//   Expected Result:  All 14 items appear in order 1-14 with correct
//                     item_type values
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$expectedCount = 14;
$validTypes    = ['multiple_choice', 'likert', 'free_text'];

// Expected item_type per item_order, per insert_questionnaire_items.sql
$expectedTypes = [
    1  => 'multiple_choice', // order_completed_first
    2  => 'likert',          // static_relevance
    3  => 'likert',          // static_surprise
    4  => 'likert',          // static_freedom
    5  => 'likert',          // static_experience
    6  => 'free_text',       // static_comment
    7  => 'likert',          // adaptive_relevance
    8  => 'likert',          // adaptive_surprise
    9  => 'likert',          // adaptive_freedom
    10 => 'likert',          // adaptive_experience
    11 => 'free_text',       // adaptive_comment
    12 => 'multiple_choice', // preferred_version
    13 => 'free_text',       // preference_reason
    14 => 'free_text',       // final_comments
];

$errors = [];

$stmt = $pdo->query('SELECT * FROM questionnaire_item ORDER BY item_order');
$rows = $stmt->fetchAll();

$actualCount = count($rows);
if ($actualCount !== $expectedCount) {
    $errors[] = "Expected {$expectedCount} items, found {$actualCount}.";
}

$seenOrders = [];
foreach ($rows as $index => $row) {
    $order = (int)$row['item_order'];
    $type  = $row['item_type'];
    $seenOrders[] = $order;

    // item_order should run 1, 2, 3 ... in sequence with no gaps/duplicates
    $expectedOrderHere = $index + 1;
    if ($order !== $expectedOrderHere) {
        $errors[] = "Row at position " . ($index + 1) . " has item_order {$order}, expected {$expectedOrderHere} (gap or duplicate in ordering).";
    }

    if (!in_array($type, $validTypes, true)) {
        $errors[] = "Item {$order}: item_type '{$type}' is not a recognised type.";
    }

    if (isset($expectedTypes[$order]) && $type !== $expectedTypes[$order]) {
        $errors[] = "Item {$order}: expected item_type '{$expectedTypes[$order]}', got '{$type}'.";
    }

    if ($type === 'likert') {
        if (empty($row['scale_min_label']) || empty($row['scale_max_label'])) {
            $errors[] = "Item {$order}: likert item is missing scale_min_label or scale_max_label.";
        }
    }

    if (trim((string)$row['item_text']) === '') {
        $errors[] = "Item {$order}: item_text is empty.";
    }
}

echo "DF7 - Verify questionnaire_item table populated correctly\n";
echo str_repeat('-', 50) . "\n";
echo "Rows returned: {$actualCount} (expected {$expectedCount})\n";
echo "item_order sequence: " . implode(', ', $seenOrders) . "\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "All 14 items are present in order 1-14, each with a valid item_type "
        . "matching the questionnaire design, and likert items carry both scale labels.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
