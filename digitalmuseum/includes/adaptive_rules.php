<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// includes/adaptive_rules.php
//
// Rule evaluation functions (Phased Build Plan.docx > Phase 4 > "Implement
// rule evaluation functions": dwell thresholds, revisit rules, navigation
// depth, switching patterns, branching factor, serendipity triggers) plus
// priority-based conflict resolution (Adaptive Logic.docx > "Conflict
// Resolution").
//
// Source of truth for what each rule means is adaptive_rule.trigger_condition
// (Database Design.docx), seeded from Adaptive Logic.docx's rule tables by
// database/insert_rules.sql. This file never hard-codes a threshold - it
// reads them from the DB (via loadActiveRules()) so all 27 rules stay
// editable in one place, in insert_rules.sql, not scattered through code.
//
// Scope: this file only decides which rules FIRE for a given set of
// behavioural signals, and which one wins when several fire at once. It
// does not compute interest scores (includes/adaptive_scoring.php, next)
// or choose which artefacts to display (includes/adaptive_suggestions.php,
// after that) - kept separate so each stage is independently testable,
// matching the Phase 4 "backend-only test harness" requirement
// (Testing Plans.docx > AE1-AE8).
// =====================================================================

// ---------------------------------------------------------------------
// Fetch active rules
// ---------------------------------------------------------------------

/**
 * Loads every active_flag = TRUE rule from adaptive_rule, decoding its
 * trigger_condition JSON up front. Returns rule_id => decoded rule so
 * evaluateAllRules() (and tests) can work with plain arrays instead of
 * re-touching the database or re-parsing JSON per evaluation.
 *
 * Takes $pdo rather than requiring config/db.php itself, so the Phase 4
 * test harness (tests/phase4/*.php) can pass in its own connection, or
 * skip the database entirely and hand evaluateAllRules() a hand-built
 * rule list for a pure, isolated test of one rule.
 */
function loadActiveRules(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT rule_id, rule_name, rule_description, trigger_condition
         FROM adaptive_rule
         WHERE active_flag = TRUE
         ORDER BY rule_id'
    );

    $rules = [];
    foreach ($stmt->fetchAll() as $row) {
        $condition = json_decode($row['trigger_condition'], true);
        if (!is_array($condition)) {
            // A single malformed trigger_condition shouldn't take down
            // the whole engine - skip it rather than fatally erroring.
            continue;
        }
        $rules[(int)$row['rule_id']] = [
            'rule_id'          => (int)$row['rule_id'],
            'rule_name'        => $row['rule_name'],
            'rule_description' => $row['rule_description'],
            'condition'        => $condition,
        ];
    }
    return $rules;
}

// ---------------------------------------------------------------------
// Single-signal condition matching
// ---------------------------------------------------------------------

/**
 * Behavioural signals this engine understands (Adaptive Logic.docx >
 * "Behavioural Signal Framework"). Callers build an array with whichever
 * of these are relevant to the moment being evaluated (e.g. on modal
 * close: dwell_seconds + revisit_count + revisit_interval; on a
 * heartbeat tick: navigation_depth + theme_switches + subtheme_switches
 * + branching_factor). Keys that are left out, or explicitly null, are
 * treated as "not applicable" - rules needing that signal simply won't
 * fire, rather than the engine erroring:
 *
 *   dwell_seconds        float|null  seconds spent on the artefact just closed
 *   revisit_count        int|null    times this artefact has been opened this session
 *   revisit_interval     float|null  seconds since the previous open of this artefact
 *   navigation_depth     int|null    total artefacts opened this session
 *   theme_switches       int|null    number of theme-to-theme transitions this session
 *   subtheme_switches    int|null    number of subtheme-to-subtheme transitions this session
 *   branching_factor     int|null    number of distinct subthemes explored this session
 *   artefact_popularity  string|null 'low'|'medium'|'high' - how often the
 *                                    just-closed artefact is opened across all sessions
 */

/**
 * Matches one signal condition - a simple rule's whole trigger_condition,
 * or one entry inside a compound rule's "conditions" list (see
 * evaluateRule()) - against the supplied signals.
 */
function matchesCondition(array $condition, array $signals): bool
{
    switch ($condition['signal'] ?? null) {
        case 'dwell_time':
            return withinBounds($signals['dwell_seconds'] ?? null, $condition, 'seconds');

        case 'revisit_count':
            return withinBounds($signals['revisit_count'] ?? null, $condition, '');

        case 'revisit_interval':
            return withinBounds($signals['revisit_interval'] ?? null, $condition, 'seconds');

        case 'navigation_depth':
            return withinBounds($signals['navigation_depth'] ?? null, $condition, '');

        case 'theme_switches':
            return withinBounds($signals['theme_switches'] ?? null, $condition, '');

        case 'subtheme_switches':
            return withinBounds($signals['subtheme_switches'] ?? null, $condition, '');

        case 'branching_factor':
            return withinBounds($signals['branching_factor'] ?? null, $condition, '');

        case 'artefact_popularity':
            $value = $signals['artefact_popularity'] ?? null;
            return $value !== null && $value === ($condition['category'] ?? null);

        default:
            // Unknown/missing signal name - fail closed (never fires)
            // rather than guessing, so a typo'd trigger_condition can't
            // silently match every evaluation.
            return false;
    }
}

/**
 * Shared inclusive min/max range check. $suffix picks between the plain
 * (min/max) and *_seconds (min_seconds/max_seconds) key pairs used by
 * different rule categories in insert_rules.sql. A bound that's absent,
 * or explicitly null, means "no limit on that side".
 */
function withinBounds($value, array $condition, string $suffix): bool
{
    if ($value === null) {
        return false; // signal wasn't supplied for this evaluation - can't match
    }

    $minKey = $suffix === '' ? 'min' : "min_{$suffix}";
    $maxKey = $suffix === '' ? 'max' : "max_{$suffix}";

    if (array_key_exists($minKey, $condition) && $condition[$minKey] !== null && $value < $condition[$minKey]) {
        return false;
    }
    if (array_key_exists($maxKey, $condition) && $condition[$maxKey] !== null && $value > $condition[$maxKey]) {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------
// Rule-level evaluation (handles both simple and compound rules)
// ---------------------------------------------------------------------

/**
 * Does this one rule fire for the given signals? Compound rules
 * (signal = "compound", used by the 4 serendipity rules - insert_rules.sql
 * rule_id 24-27) require every sub-condition in "conditions" to match -
 * logical AND, per Adaptive Logic.docx's serendipity definitions, e.g.
 * "immersive_dwell (>30s) AND artefact_popularity = low".
 */
function evaluateRule(array $rule, array $signals): bool
{
    $condition = $rule['condition'];

    if (($condition['signal'] ?? null) === 'compound') {
        $subConditions = $condition['conditions'] ?? [];
        if (empty($subConditions)) {
            return false; // a compound rule with nothing to check never fires
        }
        foreach ($subConditions as $subCondition) {
            if (!matchesCondition($subCondition, $signals)) {
                return false;
            }
        }
        return true;
    }

    return matchesCondition($condition, $signals);
}

// ---------------------------------------------------------------------
// Evaluate every active rule
// ---------------------------------------------------------------------

/**
 * Runs every rule in $rules against $signals and returns the ones that
 * fire, each annotated with the fields includes/adaptive_scoring.php and
 * includes/adaptive_suggestions.php need next (weight, priority,
 * priority_multiplier, effect) so neither has to re-read the database or
 * re-parse trigger_condition themselves.
 *
 * $rules is normally the output of loadActiveRules($pdo), but is taken
 * as a plain array (not $pdo) so the test harness can evaluate against a
 * fixed, hand-built rule set with no database involved - Testing
 * Plans.docx AE1-AE3 and AE7-AE8 each "simulate" one signal and check
 * that one specific named rule fires.
 */
function evaluateAllRules(array $rules, array $signals): array
{
    $fired = [];

    foreach ($rules as $rule) {
        if (!evaluateRule($rule, $signals)) {
            continue;
        }

        $condition = $rule['condition'];
        $fired[] = [
            'rule_id'             => $rule['rule_id'],
            'rule_name'           => $rule['rule_name'],
            'category'            => $condition['category'] ?? null,
            'weight'              => $condition['weight'] ?? 0.0,
            'priority'            => $condition['priority'] ?? 'low',
            'priority_multiplier' => $condition['priority_multiplier'] ?? 1.0,
            'effect'              => $condition['effect'] ?? null,
        ];
    }

    return $fired;
}

// ---------------------------------------------------------------------
// Conflict resolution (Adaptive Logic.docx > "Conflict Resolution")
// ---------------------------------------------------------------------

const ADAPTIVE_PRIORITY_RANK = [
    'very_high' => 4,
    'high'      => 3,
    'medium'    => 2,
    'low'       => 1,
];

/**
 * When multiple rules fire at once, picks the one that should drive the
 * panel update, per Adaptive Logic.docx's fixed precedence:
 *   1. Serendipity rules override all others.
 *   2. If no serendipity rule fired, deep-dive/high-priority rules
 *      override reinforcement rules.
 *   3. If neither fired, reinforcement rules override drift rules.
 *
 * In insert_rules.sql every serendipity rule (24-27) is already named
 * serendipity_* and is priority = very_high, but so are a few
 * non-serendipity rules (e.g. dwell_immersive, navdepth_verydeep) - a
 * plain priority-rank sort alone can't tell those apart, so serendipity
 * is checked explicitly first and always wins ties.
 */
function resolveHighestPriorityRule(array $firedRules): ?array
{
    if (empty($firedRules)) {
        return null;
    }

    usort($firedRules, function (array $a, array $b): int {
        // strpos(...) === 0 rather than str_starts_with() - PHP 8.0+ only,
        // and it's safer not to assume MAMP is running 8.0+ for one string check
        $aIsSerendipity = strpos($a['rule_name'], 'serendipity_') === 0;
        $bIsSerendipity = strpos($b['rule_name'], 'serendipity_') === 0;

        if ($aIsSerendipity !== $bIsSerendipity) {
            return $aIsSerendipity ? -1 : 1; // serendipity sorts first
        }

        $aRank = ADAPTIVE_PRIORITY_RANK[$a['priority']] ?? 0;
        $bRank = ADAPTIVE_PRIORITY_RANK[$b['priority']] ?? 0;
        return $bRank <=> $aRank; // higher rank sorts first
    });

    return $firedRules[0];
}
