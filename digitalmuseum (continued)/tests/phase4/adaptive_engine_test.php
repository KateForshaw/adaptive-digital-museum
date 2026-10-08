<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// tests/phase4/adaptive_engine_test.php
//
// Test cases AE1, AE2, AE3, AE5, AE7, AE8 (Testing Plans.docx > Phase 4 Test Cases)
//   AE1  Test dwell threshold rule    - Simulate 20s dwell             -> Deep-dive rule fires
//   AE2  Test revisit rule            - Simulate 3 revisits            -> Multiple-revisit rule fires
//   AE3  Test switching rule          - Simulate rapid switching       -> Serendipity rule fires
//   AE5  Test suggestion diversity    - Simulate broad browsing        -> 4 unique suggestions returned
//   AE7  Test navigation depth rule   - Simulate navigation_depth = 8  -> navdepth_deep rule fires
//   AE8  Test branching factor rule   - Simulate branching_factor = 6  -> branching_high rule fires
//
// This is the "backend-only test harness" Phased Build Plan.docx's
// Phase 4 section calls for: it calls includes/adaptive_rules.php's
// functions directly against the real 27 seeded rules - no HTTP/cURL
// round-trip like the Phase 1/2 tests, because there's no API endpoint
// wired up yet at this point in the build (that's Phase 5).
//
// AE4 (scoring model) and AE6 (rule priority) are covered by
// test_scoring_model.php and test_rule_priority.php respectively - kept
// separate because they exercise a different file/behaviour each. AE5
// is here rather than there because, like AE1-3/7-8, it's about the
// engine's output correctness (diversity) rather than one narrow
// behaviour - it just additionally needs a throwaway session with
// event_log history behind it, played in from simulate_events.json
// (see the AE5 section below), unlike the other five here which only
// need the rule catalogue itself.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/adaptive_rules.php';
require_once __DIR__ . '/../../includes/adaptive_scoring.php';
require_once __DIR__ . '/../../includes/adaptive_suggestions.php';

header('Content-Type: text/plain');

$errors = [];

// All 5 cases below only need to know which rules fire for a given
// signal set - loaded once from the real database, reused by every
// case, so this is testing the actual production rule catalogue
// (database/insert_rules.sql), not a synthetic stand-in.
$rules = loadActiveRules($pdo);

if (empty($rules)) {
    $errors[] = 'loadActiveRules() returned no rules - has insert_rules.sql been run?';
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Evaluates $signals against every active rule and checks that
 * $expectedRuleName is among the ones that fired.
 */
function assertRuleFires(array $rules, array $signals, string $expectedRuleName, string $caseId, array &$errors): void
{
    $fired = evaluateAllRules($rules, $signals);
    $firedNames = array_column($fired, 'rule_name');

    if (!in_array($expectedRuleName, $firedNames, true)) {
        $errors[] = "{$caseId}: expected '{$expectedRuleName}' to fire for signals " . json_encode($signals)
            . '. Rules that did fire: ' . (empty($firedNames) ? '(none)' : implode(', ', $firedNames));
    }
}

/**
 * AE3 only asks for "a Serendipity rule" to fire, not one named rule in
 * particular, so this checks any fired rule whose effect is a
 * serendipity trigger, rather than pinning to one rule_name.
 */
function assertSerendipityEffectFires(array $rules, array $signals, string $caseId, array &$errors): void
{
    $fired = evaluateAllRules($rules, $signals);

    $serendipityRuleNames = [];
    foreach ($fired as $rule) {
        if (strpos((string)$rule['effect'], 'serendipity') !== false) {
            $serendipityRuleNames[] = $rule['rule_name'];
        }
    }

    if (empty($serendipityRuleNames)) {
        $firedNames = array_column($fired, 'rule_name');
        $errors[] = "{$caseId}: expected a serendipity-triggering rule to fire for signals " . json_encode($signals)
            . '. Rules that did fire: ' . (empty($firedNames) ? '(none)' : implode(', ', $firedNames));
    }
}

// ---------------------------------------------------------------------
// AE1 - Test dwell threshold rule
// dwell_deep (rule_id 3) covers 15-30s dwell (database/insert_rules.sql)
// ---------------------------------------------------------------------
assertRuleFires($rules, ['dwell_seconds' => 20], 'dwell_deep', 'AE1', $errors);

// ---------------------------------------------------------------------
// AE2 - Test revisit rule
// revisit_multiple (rule_id 6) covers 3+ revisits
// ---------------------------------------------------------------------
assertRuleFires($rules, ['revisit_count' => 3], 'revisit_multiple', 'AE2', $errors);

// ---------------------------------------------------------------------
// AE3 - Test switching rule
// theme_switch_rapid (rule_id 15) covers 3+ theme switches, effect
// trigger_serendipity_suggestions - the simplest single-signal way to
// "simulate rapid switching"; the compound serendipity rules (24-27)
// need two different signal types firing together, which this case's
// one-line description doesn't call for.
// ---------------------------------------------------------------------
assertSerendipityEffectFires($rules, ['theme_switches' => 3], 'AE3', $errors);

// ---------------------------------------------------------------------
// AE7 - Test navigation depth rule
// navdepth_deep (rule_id 10) covers 8-12 artefacts
// ---------------------------------------------------------------------
assertRuleFires($rules, ['navigation_depth' => 8], 'navdepth_deep', 'AE7', $errors);

// ---------------------------------------------------------------------
// AE8 - Test branching factor rule
// branching_high (rule_id 22) covers 6-9 subthemes
// ---------------------------------------------------------------------
assertRuleFires($rules, ['branching_factor' => 6], 'branching_high', 'AE8', $errors);

// ---------------------------------------------------------------------
// AE5 - Test suggestion diversity
// Steps: Simulate broad browsing -> Expected: 4 unique suggestions returned
//
// Needs a real (throwaway) session with event_log history behind it -
// includes/adaptive_suggestions.php derives its session context
// (dominant subtheme/theme, visited artefacts) straight from event_log,
// so this case can't be a pure function call like the ones above. Uses
// simulate_events.json as its fixture rather than hard-coding
// artefact_ids in this file, so the simulated browsing behaviour is
// visible/editable independently of the test logic. Cleans up the
// throwaway participant afterwards (cascades to session_log/event_log -
// same pattern as tests/phase2/test_session_end.php).
// ---------------------------------------------------------------------

$ae5ParticipantId = null;

try {
    $fixture = json_decode(file_get_contents(__DIR__ . '/simulate_events.json'), true);
    if (!is_array($fixture) || empty($fixture['events'])) {
        throw new RuntimeException('simulate_events.json is missing or has no events.');
    }

    // ---- Throwaway participant + session ----
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => 'TEST_AE5_' . time()]);
    $ae5ParticipantId = (int)$pdo->lastInsertId();

    $conditionId = $pdo->query('SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1')
        ->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag)
        VALUES
            (:participant_id, :condition_id, :session_start, FALSE, FALSE, FALSE)
    ");
    $stmt->execute([
        ':participant_id' => $ae5ParticipantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => date('Y-m-d H:i:s', time() - 300), // 5 minutes ago
    ]);
    $ae5SessionId = (int)$pdo->lastInsertId();

    // ---- Play the fixture's broad-browsing sequence into event_log ----
    $eventTime = time() - 250; // comfortably inside the 7-minute session window
    foreach ($fixture['events'] as $event) {
        $stmt = $pdo->prepare('
            SELECT artefact_id FROM artefact
            JOIN subtheme USING (subtheme_id)
            WHERE subtheme_name = :subtheme_name
            ORDER BY artefact_id LIMIT 1
        ');
        $stmt->execute([':subtheme_name' => $event['subtheme_name']]);
        $artefactId = $stmt->fetchColumn();
        if ($artefactId === false) {
            throw new RuntimeException("simulate_events.json references unknown subtheme '{$event['subtheme_name']}'.");
        }

        $pdo->prepare("
            INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
            VALUES (:session_id, :artefact_id, 'click', :timestamp)
        ")->execute([
            ':session_id'  => $ae5SessionId,
            ':artefact_id' => $artefactId,
            ':timestamp'   => date('Y-m-d H:i:s', $eventTime),
        ]);
        $eventTime += 20; // spread 20s apart, roughly heartbeat-cadence
    }

    // ---- TC1: every panel mode's composition returns 4 unique artefacts ----
    foreach (array_keys(PANEL_COMPOSITION) as $mode) {
        $suggestions = buildSuggestions($pdo, $ae5SessionId, $mode);
        $artefactIds = array_column($suggestions, 'artefact_id');

        if (count($suggestions) !== 4) {
            $errors[] = "AE5-TC1 ({$mode}): expected 4 suggestions, got " . count($suggestions) . '.';
        }
        if (count(array_unique($artefactIds)) !== count($artefactIds)) {
            $errors[] = "AE5-TC1 ({$mode}): suggestions were not all unique - " . json_encode($artefactIds);
        }
    }

    // ---- TC2: the full pipeline (rules -> score -> mode -> suggestions)
    // also returns 4 unique artefacts, adding a rapid-switching signal on
    // top of the same broad session so real rules actually fire first ----
    $firedRules  = evaluateAllRules($rules, ['theme_switches' => 5, 'branching_factor' => 6]);
    $scoreResult = scoreSignals($firedRules);
    $pipelineSuggestions  = generateSuggestions($pdo, $ae5SessionId, $firedRules, $scoreResult);
    $pipelineArtefactIds  = array_column($pipelineSuggestions, 'artefact_id');

    if (count($pipelineSuggestions) !== 4) {
        $errors[] = 'AE5-TC2: expected 4 suggestions from generateSuggestions(), got '
            . count($pipelineSuggestions) . '.';
    }
    if (count(array_unique($pipelineArtefactIds)) !== count($pipelineArtefactIds)) {
        $errors[] = 'AE5-TC2: full-pipeline suggestions were not all unique - ' . json_encode($pipelineArtefactIds);
    }
} catch (Throwable $e) {
    $errors[] = 'AE5: unexpected error - ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - deleting the
    // participant cascades to session_log (ON DELETE CASCADE) and from
    // there to event_log (ON DELETE CASCADE) - see create_tables.sql.
    if ($ae5ParticipantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $ae5ParticipantId]);
    }
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "AE1, AE2, AE3, AE5, AE7, AE8 - Adaptive rule firing + suggestion diversity\n";
echo str_repeat('-', 50) . "\n";
echo 'Active rules loaded from adaptive_rule: ' . count($rules) . "\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Each simulated signal fired exactly the rule Testing Plans.docx expects, ";
    echo "and every panel mode - plus the full engine pipeline - returned 4 unique artefacts ";
    echo "for a simulated broad-browsing session.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
