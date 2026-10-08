<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// tests/phase4/test_scoring_model.php
//
// Test case AE4 (Testing Plans.docx > Phase 4 Test Cases)
//   Description:      Test scoring model
//   Steps:             Feed known weights
//   Expected Result:  Correct interest score returned
//
// Unlike adaptive_engine_test.php and test_rule_priority.php, this file
// doesn't touch the database at all - includes/adaptive_scoring.php is
// pure arithmetic over whatever fired-rule weights it's handed (see its
// own header comment), and AE4's "Feed known weights" wording is asking
// for exactly that: known, hand-picked inputs with a hand-checkable
// expected output, not a live rule lookup. The weight/priority_multiplier
// pairs below are taken directly from real rules (Adaptive Logic.docx's
// Signal Weight Tables / Priority multiplier table, database/insert_rules.sql)
// so the expected results can be checked by hand against those tables.
// =====================================================================

require_once __DIR__ . '/../../includes/adaptive_scoring.php';

header('Content-Type: text/plain');

$errors = [];

// ---------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------

function assertScoreResult(
    array $firedRules,
    float $expectedScore,
    string $expectedCategory,
    bool $expectedSerendipity,
    string $caseId,
    array &$errors
): void {
    $result = scoreSignals($firedRules);

    if (abs($result['interest_score'] - $expectedScore) > 0.001) {
        $errors[] = "{$caseId}: expected interest_score {$expectedScore}, got {$result['interest_score']}.";
    }
    if ($result['interest_category'] !== $expectedCategory) {
        $errors[] = "{$caseId}: expected interest_category '{$expectedCategory}', got '{$result['interest_category']}'.";
    }
    if ($result['serendipity_triggered'] !== $expectedSerendipity) {
        $errors[] = "{$caseId}: expected serendipity_triggered=" . ($expectedSerendipity ? 'true' : 'false')
            . ', got ' . ($result['serendipity_triggered'] ? 'true' : 'false') . '.';
    }
}

// ---------------------------------------------------------------------
// TC1 - Single fired rule, one signal
//
// dwell_engaged: weight 0.8, priority_multiplier 1.2 (medium)
// score = (0.8 * 1.2) / 1 = 0.96 -> "moderate" band (<=1.0)
// ---------------------------------------------------------------------
assertScoreResult(
    [['rule_name' => 'dwell_engaged', 'weight' => 0.8, 'priority_multiplier' => 1.2]],
    0.96,
    'moderate',
    false,
    'AE4-TC1',
    $errors
);

// ---------------------------------------------------------------------
// TC2 - Two fired rules averaged together
//
// dwell_deep: weight 1.2, priority_multiplier 1.5 (high)
// revisit_single: weight 0.7, priority_multiplier 1.2 (medium)
// score = ((1.2*1.5) + (0.7*1.2)) / 2 = (1.8 + 0.84) / 2 = 1.32
// -> "strong" band (<=1.5)
// ---------------------------------------------------------------------
assertScoreResult(
    [
        ['rule_name' => 'dwell_deep',     'weight' => 1.2, 'priority_multiplier' => 1.5],
        ['rule_name' => 'revisit_single', 'weight' => 0.7, 'priority_multiplier' => 1.2],
    ],
    1.32,
    'strong',
    false,
    'AE4-TC2',
    $errors
);

// ---------------------------------------------------------------------
// TC3 - A single very_high signal alone clears the serendipity threshold
//
// dwell_immersive: weight 1.8, priority_multiplier 2.0 (very_high)
// score = (1.8 * 2.0) / 1 = 3.6 -> past 2.0, "serendipity_trigger"
// (matches Adaptive Logic.docx's own description of this rule:
// "Trigger deep-dive or serendipity mode")
// ---------------------------------------------------------------------
assertScoreResult(
    [['rule_name' => 'dwell_immersive', 'weight' => 1.8, 'priority_multiplier' => 2.0]],
    3.6,
    'serendipity_trigger',
    true,
    'AE4-TC3',
    $errors
);

// ---------------------------------------------------------------------
// TC4 - No fired rules -> neutral zero score, not an error
//
// Matches Adaptive Logic.docx > Panel Reset Behaviour > "Session Start:
// Panel begins neutral - no suggestions".
// ---------------------------------------------------------------------
assertScoreResult([], 0.0, 'weak', false, 'AE4-TC4', $errors);

// ---------------------------------------------------------------------
// TC5 - Boundary: a score of exactly 2.0 is "very_strong", not
// "serendipity_trigger" - Adaptive Logic.docx > Score Range says
// "Serendipity trigger: >2.0" (strictly greater than), so exactly 2.0
// must land in the band below it.
//
// weight 1.0 * priority_multiplier 2.0 / 1 signal = 2.0 exactly
// ---------------------------------------------------------------------
assertScoreResult(
    [['rule_name' => 'boundary_check', 'weight' => 1.0, 'priority_multiplier' => 2.0]],
    2.0,
    'very_strong',
    false,
    'AE4-TC5',
    $errors
);

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "AE4 - Test scoring model\n";
echo str_repeat('-', 50) . "\n";
echo "No database connection used - pure arithmetic over hand-fed weights.\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "calculateInterestScore()/categoriseInterestScore()/meetsSerendipityThreshold() ";
    echo "all return the correct values for known weight inputs, including the >2.0 boundary.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
