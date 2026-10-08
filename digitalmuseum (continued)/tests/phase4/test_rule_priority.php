<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// tests/phase4/test_rule_priority.php
//
// Test case AE6 (Testing Plans.docx > Phase 4 Test Cases)
//   Description:      Test rule priority
//   Steps:             Trigger multiple rules
//   Expected Result:  Serendipity overrides others
//
// Exercises resolveHighestPriorityRule() (includes/adaptive_rules.php),
// which is used both for conflict resolution here and to pick the panel
// mode in includes/adaptive_suggestions.php - so getting this right
// matters beyond just AE6. Adaptive Logic.docx > "Conflict Resolution"
// specifies a fixed precedence:
//   1. Serendipity rules override all others
//   2. If no serendipity rule fired, higher priority overrides lower
//   3. (reinforcement overrides drift, which falls out of (2) since
//      reinforcement rules sit at medium/high and drift-type rules
//      generally sit at medium too, differentiated by rule content
//      rather than a distinct priority tier)
//
// Backend-only harness, same as adaptive_engine_test.php - calls
// includes/adaptive_rules.php directly against the real 27 seeded
// rules, no HTTP round-trip.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/adaptive_rules.php';

header('Content-Type: text/plain');

$errors = [];

$rules = loadActiveRules($pdo);
if (empty($rules)) {
    $errors[] = 'loadActiveRules() returned no rules - has insert_rules.sql been run?';
}

// ---------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------

/**
 * Evaluates $signals, resolves the winner, and checks it's the rule
 * named $expectedWinnerName. Reports every rule that actually fired
 * when the assertion fails, so a mismatch is easy to diagnose.
 */
function assertWinningRule(array $rules, array $signals, ?string $expectedWinnerName, string $caseId, array &$errors): void
{
    $fired = evaluateAllRules($rules, $signals);
    $winner = resolveHighestPriorityRule($fired);
    $winnerName = $winner['rule_name'] ?? null;

    if ($winnerName !== $expectedWinnerName) {
        $firedNames = array_column($fired, 'rule_name');
        $errors[] = "{$caseId}: expected winner "
            . ($expectedWinnerName === null ? '(none - no rule should fire)' : "'{$expectedWinnerName}'")
            . ', got ' . ($winnerName ?? '(none)') . '. Rules that fired: '
            . (empty($firedNames) ? '(none)' : implode(', ', $firedNames));
    }
}

// ---------------------------------------------------------------------
// TC1 - Serendipity beats an equal-priority (very_high) non-serendipity rule
//
// dwell_seconds=35 fires both dwell_immersive (rule 4, very_high, NOT
// serendipity) and serendipity_immersive_rare (rule 24, very_high,
// serendipity - its compound condition also needs low popularity,
// supplied here). revisit_count=2 additionally fires revisit_single
// (medium), just to confirm a mid-priority rule in the mix doesn't
// distract the resolver. Expected winner: serendipity_immersive_rare -
// this is the case a plain "highest priority wins" sort (with no
// serendipity tie-break) would get wrong, since rule 4 is equally
// very_high and would sort first/last arbitrarily without it.
// ---------------------------------------------------------------------
assertWinningRule(
    $rules,
    ['dwell_seconds' => 35, 'artefact_popularity' => 'low', 'revisit_count' => 2],
    'serendipity_immersive_rare',
    'AE6-TC1',
    $errors
);

// ---------------------------------------------------------------------
// TC2 - Serendipity beats several 'high' priority rules at once
//
// theme_switches=2 + subtheme_switches=5 fires three 'high' priority
// rules (dwell_deep, theme_switch_oscillation, subtheme_switch_high)
// AND the compound serendipity_theme_subtheme_switch rule (very_high).
// Expected winner: serendipity_theme_subtheme_switch.
// ---------------------------------------------------------------------
assertWinningRule(
    $rules,
    ['dwell_seconds' => 20, 'theme_switches' => 2, 'subtheme_switches' => 5],
    'serendipity_theme_subtheme_switch',
    'AE6-TC2',
    $errors
);

// ---------------------------------------------------------------------
// TC3 - No serendipity rule fires -> plain priority rank decides
//
// dwell_seconds=20 fires dwell_deep (high); revisit_count=2 and
// theme_switches=0 fire revisit_single and theme_switch_none (both
// medium). No serendipity rule is in the mix here, so this exercises
// the "everything else" branch of the resolver, not the serendipity
// tie-break - dwell_deep should win on priority rank alone.
// ---------------------------------------------------------------------
assertWinningRule(
    $rules,
    ['dwell_seconds' => 20, 'revisit_count' => 2, 'theme_switches' => 0],
    'dwell_deep',
    'AE6-TC3',
    $errors
);

// ---------------------------------------------------------------------
// TC4 - Nothing fires -> resolver returns null, not an error
//
// dwell_seconds=1 is a micro-dwell (0-3s, Adaptive Logic.docx: "Ignore")
// - deliberately below every rule's threshold, so no rule fires at all.
// ---------------------------------------------------------------------
assertWinningRule($rules, ['dwell_seconds' => 1], null, 'AE6-TC4', $errors);

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "AE6 - Test rule priority\n";
echo str_repeat('-', 50) . "\n";
echo 'Active rules loaded from adaptive_rule: ' . count($rules) . "\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Serendipity rules override same- and higher-priority non-serendipity rules, ";
    echo "plain priority rank decides when no serendipity rule fires, ";
    echo "and no rules firing resolves to null rather than an error.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
