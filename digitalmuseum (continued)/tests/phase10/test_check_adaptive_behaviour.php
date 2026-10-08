<?php
// =====================================================================
// Digital Museum Research Project
// Phase 10 - Pilot Testing
// tests/phase10/test_check_adaptive_behaviour.php
//
// Test case PT2 (Testing Plans.docx > Phase 10 Test Cases)
//   Description:      Check adaptive behaviour
//   Steps:             Observe panel updates
//   Expected Result:  Behaviour matches rules
//
// Read-only, against REAL pilot data - participant_code 'PILOT1' and
// 'PILOT2' (the pilots PT1 already ran end-to-end), NOT throwaway test
// participants like the Phase 4/5/8 harnesses. Nothing is inserted or
// deleted here; this only re-checks what api/trigger_rule.php and
// api/log_adaptive_event.php already persisted while the pilots used
// static.php/adaptive.php for real.
//
// This script is the automated half: it re-checks the logged evidence
// against the exact guarantees the code makes, so "Behaviour matches rules"
// isn't just eyeballed:
//
//   1. Every adaptive_event.rule_id references a real, currently
//      active_flag=TRUE adaptive_rule row (trigger_rule.php only ever
//      fires rules from loadActiveRules()'s active set).
//   2. Every adaptive_trigger event_log row has exactly one matching
//      adaptive_event row, and exactly 4 adaptive_suggestion rows
//      sharing the same session_id/rule_id/timestamp - trigger_rule.php
//      always persists 4 suggestions + 1 trigger per update, in one
//      transaction.
//   3. Those 4 suggestions have panel_position 0-3 (unique, no gaps)
//      and no duplicate artefact_id - adaptive_suggestions.php's
//      Ranking Process guarantee ("always 4, never a repeat").
//   4. Each suggestion's interest_score equals its batch's
//      adaptive_event.trigger_confidence - both are written from the
//      same $scoreResult['interest_score'] value in trigger_rule.php.
//   5. No trigger_confidence falls in the "weak" band (<=0.5, Adaptive
//      Logic.docx > Score Range) - trigger_rule.php explicitly declines
//      to update on a weak score, so a logged trigger with a weak score
//      would mean that guard didn't hold.
//   6. clicked_flag consistency both ways: every panel_click event_log
//      row's suggestion is flagged clicked_flag=TRUE, and every
//      clicked_flag=TRUE suggestion has a panel_click event referencing
//      it (api/log_adaptive_event.php is the only place that sets it).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$errors            = [];
$participantCodes  = ['PILOT1', 'PILOT2'];
$triggers          = [];

// ---------------------------------------------------------------------
// Resolve the two pilot participants + their sessions
// ---------------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count($participantCodes), '?'));
$stmt = $pdo->prepare("
    SELECT p.participant_id, p.participant_code, s.session_id
    FROM participant p
    JOIN session_log s ON s.participant_id = p.participant_id
    WHERE p.participant_code IN ($placeholders)
    ORDER BY p.participant_code, s.session_id
");
$stmt->execute($participantCodes);
$sessions = $stmt->fetchAll();

if (empty($sessions)) {
    $errors[] = "No session_log rows found for participant_code IN ('PILOT1','PILOT2') - "
        . "has PT1's pilot session actually been run yet?";
}

$sessionIds    = array_column($sessions, 'session_id');
$sessionToCode = [];
foreach ($sessions as $row) {
    $sessionToCode[(int)$row['session_id']] = $row['participant_code'];
}

if (!empty($sessionIds)) {
    $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));

    // -------------------------------------------------------------
    // Check 1 - rule_id integrity + active_flag on every logged row
    // -------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT DISTINCT ae.rule_id, r.rule_name, r.active_flag
        FROM adaptive_event ae
        LEFT JOIN adaptive_rule r ON r.rule_id = ae.rule_id
        WHERE ae.session_id IN ($sessionPlaceholders)
    ");
    $stmt->execute($sessionIds);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['rule_name'] === null) {
            $errors[] = "adaptive_event references rule_id {$row['rule_id']}, which doesn't exist in adaptive_rule.";
        } elseif (!(bool)$row['active_flag']) {
            $errors[] = "adaptive_event fired rule '{$row['rule_name']}' (rule_id {$row['rule_id']}), "
                . "but that rule is not currently active_flag=TRUE.";
        }
    }

    // -------------------------------------------------------------
    // Checks 2-5 - one adaptive_trigger batch at a time
    // -------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT ae.adaptive_event_id, ae.event_id, ae.session_id, ae.rule_id,
               ae.trigger_timestamp, ae.trigger_confidence, r.rule_name
        FROM adaptive_event ae
        JOIN event_log el ON el.event_id = ae.event_id AND el.event_type = 'adaptive_trigger'
        LEFT JOIN adaptive_rule r ON r.rule_id = ae.rule_id
        WHERE ae.session_id IN ($sessionPlaceholders)
        ORDER BY ae.session_id, ae.trigger_timestamp
    ");
    $stmt->execute($sessionIds);
    $triggers = $stmt->fetchAll();

    if (empty($triggers)) {
        $errors[] = "No adaptive_trigger events found for PILOT1/PILOT2 - the adaptive panel never updated "
            . "during either pilot session, so PT2 has nothing to check.";
    }

    foreach ($triggers as $trigger) {
        $code  = $sessionToCode[(int)$trigger['session_id']] ?? '?';
        $label = "{$code} / session {$trigger['session_id']} / rule '{$trigger['rule_name']}' "
            . "@ {$trigger['trigger_timestamp']}";

        // Check 5 - never a weak score
        if ((float)$trigger['trigger_confidence'] <= 0.5) {
            $errors[] = "{$label}: trigger_confidence {$trigger['trigger_confidence']} is in the 'weak' band "
                . "(<=0.5) - trigger_rule.php should never persist an update on a weak score.";
        }

        // Check 2/3/4 - the matching suggestion batch
        $stmt2 = $pdo->prepare("
            SELECT suggestion_id, artefact_id, interest_score, panel_position
            FROM adaptive_suggestion
            WHERE session_id = :session_id AND rule_id = :rule_id
              AND suggestion_timestamp = :ts
            ORDER BY panel_position
        ");
        $stmt2->execute([
            ':session_id' => $trigger['session_id'],
            ':rule_id'    => $trigger['rule_id'],
            ':ts'         => $trigger['trigger_timestamp'],
        ]);
        $batch = $stmt2->fetchAll();

        if (count($batch) !== 4) {
            $errors[] = "{$label}: expected 4 adaptive_suggestion rows, found " . count($batch) . ".";
            continue;
        }

        $positions = array_map('intval', array_column($batch, 'panel_position'));
        sort($positions);
        if ($positions !== [0, 1, 2, 3]) {
            $errors[] = "{$label}: panel_position values should be exactly 0,1,2,3 - got "
                . implode(',', $positions) . ".";
        }

        $artefactIds = array_column($batch, 'artefact_id');
        if (count(array_unique($artefactIds)) !== count($artefactIds)) {
            $errors[] = "{$label}: suggestion batch contains a duplicate artefact_id - "
                . implode(',', $artefactIds) . ".";
        }

        foreach ($batch as $suggestion) {
            if ((float)$suggestion['interest_score'] !== (float)$trigger['trigger_confidence']) {
                $errors[] = "{$label}: suggestion_id {$suggestion['suggestion_id']}'s interest_score "
                    . "({$suggestion['interest_score']}) does not match this trigger's trigger_confidence "
                    . "({$trigger['trigger_confidence']}) - both should come from the same interest_score value.";
            }
        }
    }

    // -------------------------------------------------------------
    // Check 6 - clicked_flag consistency, both directions
    // -------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT el.suggestion_id, s.clicked_flag
        FROM event_log el
        JOIN adaptive_suggestion s ON s.suggestion_id = el.suggestion_id
        WHERE el.session_id IN ($sessionPlaceholders)
          AND el.event_type = 'panel_click'
          AND el.suggestion_id IS NOT NULL
    ");
    $stmt->execute($sessionIds);
    foreach ($stmt->fetchAll() as $row) {
        if (!(bool)$row['clicked_flag']) {
            $errors[] = "suggestion_id {$row['suggestion_id']} has a panel_click event logged, "
                . "but clicked_flag is still FALSE.";
        }
    }

    $stmt = $pdo->prepare("
        SELECT s.suggestion_id
        FROM adaptive_suggestion s
        WHERE s.session_id IN ($sessionPlaceholders) AND s.clicked_flag = TRUE
          AND NOT EXISTS (
              SELECT 1 FROM event_log el
              WHERE el.suggestion_id = s.suggestion_id AND el.event_type = 'panel_click'
          )
    ");
    $stmt->execute($sessionIds);
    foreach ($stmt->fetchAll() as $row) {
        $errors[] = "suggestion_id {$row['suggestion_id']} has clicked_flag=TRUE, but no panel_click event "
            . "references it - clicked_flag can only be set by api/log_adaptive_event.php's panel_click handler.";
    }
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "PT2 - Check adaptive behaviour\n";
echo str_repeat('-', 50) . "\n";
echo 'Sessions checked: ' . count($sessions) . ' (participant_code PILOT1/PILOT2)' . "\n";
echo 'Adaptive triggers checked: ' . count($triggers) . "\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Every logged adaptive trigger fired an active rule, persisted exactly 4 uniquely-positioned, ";
    echo "duplicate-free suggestions with a matching interest_score/trigger_confidence, never on a weak score, ";
    echo "and clicked_flag agrees with the logged panel_click events in both directions.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
