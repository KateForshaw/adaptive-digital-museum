<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_panel_trigger.php
//
// Test case AP3 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test dwell-trigger update
//   Steps:            Dwell > 30s
//   Expected Result:  Panel updates with deep dive + serendipity
//
// "Dwell > 30s" is an event-driven trigger, not a heartbeat one - it
// maps to js/adaptive_engine.js's modal-close hook, which calls
// api/trigger_rule.php WITH the artefact_id whose modal just closed
// (unlike AP2's heartbeat call, which sends none). This test simulates
// that: inserts a dwell_end event with dwell_duration > 30 for a
// rarely-viewed artefact, then calls the endpoint exactly as
// adaptive_engine.js would.
//
// A >30s dwell on a rarely-viewed artefact naturally fires two rules at
// once: dwell_immersive (simple, insert_rules.sql #4 - "triggers
// deep-dive or serendipity mode") and serendipity_immersive_rare
// (compound, #24 - dwell_immersive AND artefact_popularity=low). This
// combination is what surfaced the includes/adaptive_suggestions.php
// gap fixed alongside this test (see selectSuggestionMode()'s updated
// comment): serendipity_immersive_rare's effect string
// ("show_rare_unique_artefacts") didn't contain a keyword the mode
// matcher recognised, so despite winning conflict resolution (it's
// serendipity-named, which always wins - adaptive_rules.php's
// resolveHighestPriorityRule()), mode selection fell through to
// balanced_browsing instead of reflecting AP3's expected serendipity
// composition. With the fix, a serendipity-named winning rule always
// resolves to serendipity mode, matching dwell_immersive's own
// description ("...or serendipity mode") for exactly this scenario.
//
// Creates a throwaway participant/session directly via PDO, simulates
// the dwell by inserting event_log rows directly, calls the real
// endpoint over HTTP, and cleans up afterwards (participant delete
// cascades through session_log to everything else - create_tables.sql).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl         = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$triggerUrl      = $baseUrl . '/digitalmuseum/api/trigger_rule.php';
$testParticipantCode = 'TEST_AP3_' . time();

$errors        = [];
$participantId = null;

function postJson(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, json_decode($body, true)];
}

try {
    // -------------------------------------------------------------
    // Setup: throwaway participant + session
    // -------------------------------------------------------------
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => $testParticipantCode]);
    $participantId = (int)$pdo->lastInsertId();

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
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => date('Y-m-d H:i:s'),
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // An artefact with zero click history anywhere, so after this test's
    // own click it lands at globalClicks=1 - safely within
    // api/trigger_rule.php's POPULARITY_LOW_MAX (2), guaranteeing
    // artefact_popularity='low' and reproducing the AP3 scenario
    // regardless of what other tests have left behind.
    $artefactId = $pdo->query("
        SELECT a.artefact_id FROM artefact a
        WHERE NOT EXISTS (
            SELECT 1 FROM event_log e WHERE e.artefact_id = a.artefact_id AND e.event_type = 'click'
        )
        ORDER BY a.artefact_id LIMIT 1
    ")->fetchColumn();
    if ($artefactId === false) {
        throw new RuntimeException('No artefact with zero click history found - cannot guarantee a "low" popularity scenario.');
    }
    $artefactId = (int)$artefactId;

    // -------------------------------------------------------------
    // Simulate: the artefact was opened and dwelled on for 35s
    // (Adaptive Logic.docx > Dwell Time Thresholds > "Immersive dwell:
    // >30 seconds"), then the modal closed - the natural event sequence
    // static.php/adaptive.php's real modal flow produces.
    // -------------------------------------------------------------
    $timestamp = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, :artefact_id, 'click', :timestamp)
    ")->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':timestamp' => $timestamp]);

    $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`, navigation_depth)
        VALUES (:session_id, :artefact_id, 'navigation_depth', :timestamp, 1)
    ")->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':timestamp' => $timestamp]);

    $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`, dwell_duration)
        VALUES (:session_id, :artefact_id, 'dwell_end', :timestamp, 35)
    ")->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':timestamp' => $timestamp]);

    // -------------------------------------------------------------
    // AP3 itself: call trigger_rule.php exactly as adaptive_engine.js's
    // modal-close hook would - WITH the artefact_id.
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($triggerUrl, ['session_id' => $sessionId, 'artefact_id' => $artefactId]);

    if ($httpCode !== 200 || ($data['success'] ?? false) !== true) {
        $errors[] = 'Request failed. Body: ' . json_encode($data);
    } elseif (($data['updated'] ?? null) !== true) {
        $errors[] = 'Expected updated=true for a >30s dwell, got: ' . json_encode($data);
    } else {
        if (($data['mode'] ?? null) !== 'serendipity') {
            $errors[] = "Expected mode='serendipity' (dwell_immersive's own description: \"triggers deep-dive "
                . "or serendipity mode\"), got '" . ($data['mode'] ?? 'null') . "'.";
        }
        if (($data['rule_name'] ?? null) !== 'serendipity_immersive_rare') {
            $errors[] = "Expected the compound serendipity_immersive_rare rule to win conflict resolution "
                . "(serendipity-named rules always outrank ties - adaptive_rules.php's "
                . "resolveHighestPriorityRule()), got '" . ($data['rule_name'] ?? 'null') . "'.";
        }

        $suggestions = $data['suggestions'] ?? [];
        if (count($suggestions) !== 4) {
            $errors[] = 'Expected exactly 4 suggestions, got ' . count($suggestions) . '.';
        }
        $suggestedArtefactIds = array_column($suggestions, 'artefact_id');
        if (count(array_unique($suggestedArtefactIds)) !== count($suggestedArtefactIds)) {
            $errors[] = 'Suggestions were not all unique - ' . json_encode($suggestedArtefactIds);
        }

        // Serendipity mode's composition (adaptive_suggestions.php's
        // PANEL_COMPOSITION, matching Adaptive Logic.docx > "Panel
        // Composition Rules > Serendipity mode: 2 serendipities + 1
        // related + 1 neutral").
        $slotTypeCounts = array_count_values(array_column($suggestions, 'slot_type'));
        $expectedCounts = ['serendipity' => 2, 'related' => 1, 'neutral' => 1];
        foreach ($expectedCounts as $slotType => $expectedCount) {
            $actualCount = $slotTypeCounts[$slotType] ?? 0;
            if ($actualCount !== $expectedCount) {
                $errors[] = "Expected {$expectedCount} '{$slotType}' slot(s), got {$actualCount}. "
                    . 'Full composition: ' . json_encode($slotTypeCounts);
            }
        }

        // -------------------------------------------------------------
        // Persistence: 4 adaptive_suggestion rows, and the adaptive_trigger
        // event this time DOES carry the artefact_id (unlike AP2's
        // heartbeat call) - it's an artefact-specific trigger.
        // -------------------------------------------------------------
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM adaptive_suggestion WHERE session_id = :id AND rule_id = :rule_id');
        $stmt->execute([':id' => $sessionId, ':rule_id' => $data['rule_id']]);
        if ((int)$stmt->fetchColumn() !== 4) {
            $errors[] = 'Expected 4 adaptive_suggestion rows persisted for this session/rule.';
        }

        $stmt = $pdo->prepare("
            SELECT artefact_id FROM event_log
            WHERE session_id = :id AND event_type = 'adaptive_trigger' AND rule_id = :rule_id
        ");
        $stmt->execute([':id' => $sessionId, ':rule_id' => $data['rule_id']]);
        $triggerArtefactId = $stmt->fetchColumn();

        if ($triggerArtefactId === false) {
            $errors[] = 'No adaptive_trigger row found in event_log for this session/rule.';
        } elseif ((int)$triggerArtefactId !== $artefactId) {
            $errors[] = "adaptive_trigger's artefact_id ({$triggerArtefactId}) should match the dwelled-on "
                . "artefact ({$artefactId}) for an event-driven trigger.";
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AP3 - Test dwell-trigger update\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$triggerUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "A >30s dwell on a rarely-viewed artefact resolves to serendipity mode (2 serendipity + 1 related + ";
    echo "1 neutral) via the correctly-attributed serendipity_immersive_rare rule, and is fully persisted.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
