<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// includes/adaptive_scoring.php
//
// Scoring model (Phased Build Plan.docx > Phase 4 > "Implement scoring
// model": signal weights, priority multipliers, interest score
// calculation, serendipity threshold).
//
// Signal weights and priority multipliers themselves aren't hard-coded
// here - they already live per-rule in adaptive_rule.trigger_condition
// (Adaptive Logic.docx's Signal Weight Tables / Rule Weighting Table,
// seeded by database/insert_rules.sql) and arrive via the 'weight' and
// 'priority_multiplier' fields that includes/adaptive_rules.php's
// evaluateAllRules() already attaches to each fired rule. This file's
// only job is the arithmetic: turn a list of fired rules into one
// interest score, per Adaptive Logic.docx's Score Normalisation Formula,
// then classify that score.
//
// Input contract: everything here takes the $firedRules array shape
// produced by adaptive_rules.php's evaluateAllRules() - a list of
// ['rule_id', 'rule_name', 'category', 'weight', 'priority',
// 'priority_multiplier', 'effect']. This file doesn't touch the
// database or know about sessions/artefacts; includes/adaptive_suggestions.php
// (next) decides *which* fired rules get grouped together (e.g. "every
// rule that fired for this artefact/subtheme this session") before
// handing that group to scoreSignals() below.
// =====================================================================

// ---------------------------------------------------------------------
// Interest score calculation
// ---------------------------------------------------------------------

/**
 * Score Normalisation Formula (Adaptive Logic.docx > "Adaptive Rule
 * Weighting Scoring Model"):
 *
 *   Interest Score = ( Signal Weight * Priority Multiplier ) / Number of Signals
 *
 * "Number of Signals" is the count of fired rules being combined into
 * one score - in every real scenario in this project a fired rule maps
 * to exactly one behavioural signal (or, for the four compound
 * serendipity rules, one already-combined signal), so count($firedRules)
 * is the correct denominator: this averages each contributing signal's
 * (weight * priority_multiplier) rather than letting the score grow
 * unbounded as more signals happen to fire together.
 *
 * Rounded to 2dp to match adaptive_suggestion.interest_score's
 * DECIMAL(4,2) column (database/add_interest_score.sql) - the value
 * this function returns is exactly what gets stored there.
 */
function calculateInterestScore(array $firedRules): float
{
    if (empty($firedRules)) {
        return 0.0;
    }

    $sum = 0.0;
    foreach ($firedRules as $rule) {
        $weight     = (float)($rule['weight'] ?? 0.0);
        $multiplier = (float)($rule['priority_multiplier'] ?? 1.0);
        $sum += $weight * $multiplier;
    }

    $numberOfSignals = count($firedRules);
    return round($sum / $numberOfSignals, 2);
}

// ---------------------------------------------------------------------
// Score categorisation (Adaptive Logic.docx > "Score Range")
// ---------------------------------------------------------------------

/**
 * The doc's bands (Weak 0.0-0.5, Moderate 0.6-1.0, Strong 1.1-1.5, Very
 * strong 1.6-2.0, Serendipity trigger >2.0) leave narrow gaps between
 * bands (e.g. nothing is defined for 0.51-0.59) because the source
 * table was written around the rounded example weights, not as a
 * mathematically continuous scale. categoriseInterestScore() below uses
 * each band's upper bound as a "score <= X" cutoff instead, which keeps
 * every band's documented meaning exactly as specified while giving a
 * defined answer for every possible score, not just the example values.
 */
const ADAPTIVE_SCORE_BANDS = [
    ['label' => 'weak',                'max' => 0.5],
    ['label' => 'moderate',            'max' => 1.0],
    ['label' => 'strong',              'max' => 1.5],
    ['label' => 'very_strong',         'max' => 2.0],
    ['label' => 'serendipity_trigger', 'max' => null], // > 2.0, no upper bound
];

const ADAPTIVE_SERENDIPITY_THRESHOLD = 2.0;

function categoriseInterestScore(float $score): string
{
    foreach (ADAPTIVE_SCORE_BANDS as $band) {
        if ($band['max'] === null || $score <= $band['max']) {
            return $band['label'];
        }
    }
    return 'serendipity_trigger'; // unreachable - last band always matches
}

/**
 * Adaptive Logic.docx > "Score Range": "Serendipity trigger: >2.0" - a
 * score past this point should trigger serendipity mode outright,
 * separately from (and in addition to) any serendipity rule already
 * having fired directly (Adaptive Logic.docx > "Cumulative Score
 * Trigger": "When interest weights exceed a threshold, the panel
 * updates without a single strong trigger").
 */
function meetsSerendipityThreshold(float $score): bool
{
    return $score > ADAPTIVE_SERENDIPITY_THRESHOLD;
}

// ---------------------------------------------------------------------
// Convenience wrapper
// ---------------------------------------------------------------------

/**
 * Runs a group of fired rules through the full scoring pipeline in one
 * call - what includes/adaptive_suggestions.php and the Phase 4 test
 * harness (Testing Plans.docx > AE4 "Feed known weights -> Correct
 * interest score returned") will normally use rather than calling the
 * three functions above individually.
 */
function scoreSignals(array $firedRules): array
{
    $score = calculateInterestScore($firedRules);

    return [
        'interest_score'        => $score,
        'interest_category'     => categoriseInterestScore($score),
        'serendipity_triggered' => meetsSerendipityThreshold($score),
        'signal_count'          => count($firedRules),
    ];
}
