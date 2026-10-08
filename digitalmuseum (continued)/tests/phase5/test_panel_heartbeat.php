<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_panel_heartbeat.php
//
// Test case AP2 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test heartbeat update
//   Steps:            Wait 20s
//   Expected Result:  Panel updates quietly
//
// js/heartbeat.js itself is just a 20-second setInterval calling
// MuseumAdaptiveEngine.triggerUpdate() with no artefact_id (see that
// file's comments) - there's no separate "heartbeat logic" to test
// beyond the timer, which isn't something a PHP script can observe.
// What IS testable, and is the actual substance of "the panel updates
// quietly", is the call a heartbeat tick makes: POST api/trigger_rule.php
// with { session_id } and no artefact_id. This test calls that endpoint
// exactly as a heartbeat tick would and verifies it behaves correctly
// on both sides of the update threshold - staying silent for an idle
// session, and producing a real update once session-level signals
// (navigation_depth here) exist.
//
// Creates a throwaway participant/session directly via PDO (like Phase 4's
// AE5 test), simulates browsing by inserting event_log rows directly
// (rather than a real browser), calls the real endpoint over HTTP with
// cURL, and cleans up afterwards - deleting the participant cascades to
// session_log -> event_log/adaptive_suggestion/adaptive_event (all
// ON DELETE CASCADE on session_id - see create_tables.sql).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl        = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$triggerUrl      = $baseUrl . '/digitalmuseum/api/trigger_rule.php';
$testParticipantCode = 'TEST_AP2_' . time();

$errors        = [];
$participantId = null;

/**
 * POST JSON to the endpoint via cURL, return [httpCode, decodedBody] -
 * same helper shape as the Phase 2 endpoint tests.
 */
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
    // Setup: throwaway participant + session (adaptive task in progress,
    // started "now" so it's nowhere near the freeze window)
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

    // -------------------------------------------------------------
    // TC1: A heartbeat tick on a freshly-started, idle session (no
    // browsing yet) must NOT update the panel - Adaptive Logic.docx >
    // "Session Start: Panel begins neutral - no suggestions".
    // -------------------------------------------------------------
    [$httpCode1, $data1] = postJson($triggerUrl, ['session_id' => $sessionId]);

    if ($httpCode1 !== 200 || ($data1['success'] ?? false) !== true) {
        $errors[] = 'TC1: request failed. Body: ' . json_encode($data1);
    } elseif (($data1['updated'] ?? null) !== false) {
        $errors[] = 'TC1: expected updated=false for an idle session with no browsing yet, got: '
            . json_encode($data1);
    }

    // -------------------------------------------------------------
    // Simulate browsing: 4 artefacts opened in the same subtheme, so
    // navdepth_moderate (navigation_depth 4-7) is guaranteed to fire and
    // clear the update threshold, without also depending on a specific
    // theme/subtheme-switching rule firing.
    // -------------------------------------------------------------
    $subthemeId = $pdo->query('
        SELECT subtheme_id FROM artefact GROUP BY subtheme_id HAVING COUNT(*) >= 4 ORDER BY subtheme_id LIMIT 1
    ')->fetchColumn();
    if ($subthemeId === false) {
        throw new RuntimeException('No subtheme with at least 4 artefacts found - cannot simulate browsing.');
    }

    $stmt = $pdo->prepare('SELECT artefact_id FROM artefact WHERE subtheme_id = :subtheme_id LIMIT 4');
    $stmt->execute([':subtheme_id' => $subthemeId]);
    $artefactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $eventTime = time();
    foreach ($artefactIds as $depth => $artefactId) {
        $timestamp = date('Y-m-d H:i:s', $eventTime);
        $pdo->prepare("
            INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
            VALUES (:session_id, :artefact_id, 'click', :timestamp)
        ")->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':timestamp' => $timestamp]);

        $pdo->prepare("
            INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`, navigation_depth)
            VALUES (:session_id, :artefact_id, 'navigation_depth', :timestamp, :depth)
        ")->execute([
            ':session_id' => $sessionId,
            ':artefact_id' => $artefactId,
            ':timestamp'  => $timestamp,
            ':depth'      => $depth + 1,
        ]);
        $eventTime += 2; // spread a couple of seconds apart
    }

    // -------------------------------------------------------------
    // TC2: A heartbeat tick now (still no artefact_id - this is exactly
    // what js/heartbeat.js sends) must update the panel, since
    // navigation_depth = 4 clears the "not weak" threshold.
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($triggerUrl, ['session_id' => $sessionId]);

    if ($httpCode2 !== 200 || ($data2['success'] ?? false) !== true) {
        $errors[] = 'TC2: request failed. Body: ' . json_encode($data2);
    } elseif (($data2['updated'] ?? null) !== true) {
        $errors[] = 'TC2: expected updated=true after simulating navigation_depth=4, got: ' . json_encode($data2);
    } else {
        if (empty($data2['rule_id'])) {
            $errors[] = 'TC2: response is missing rule_id.';
        }
        if (($data2['interest_score'] ?? 0) <= 0.5) {
            $errors[] = 'TC2: expected interest_score > 0.5 (not "weak"), got ' . ($data2['interest_score'] ?? 'null') . '.';
        }
        $suggestions = $data2['suggestions'] ?? [];
        if (count($suggestions) !== 4) {
            $errors[] = 'TC2: expected exactly 4 suggestions, got ' . count($suggestions) . '.';
        }
        $suggestedArtefactIds = array_column($suggestions, 'artefact_id');
        if (count(array_unique($suggestedArtefactIds)) !== count($suggestedArtefactIds)) {
            $errors[] = 'TC2: suggestions were not all unique - ' . json_encode($suggestedArtefactIds);
        }

        // -------------------------------------------------------------
        // TC3: The update was actually persisted, not just returned -
        // 4 new adaptive_suggestion rows, 1 adaptive_trigger event with
        // artefact_id NULL (session-level, since no artefact_id was
        // sent - matching a real heartbeat call), and a matching
        // adaptive_event row.
        // -------------------------------------------------------------
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM adaptive_suggestion WHERE session_id = :id AND rule_id = :rule_id');
        $stmt->execute([':id' => $sessionId, ':rule_id' => $data2['rule_id']]);
        if ((int)$stmt->fetchColumn() !== 4) {
            $errors[] = 'TC3: expected 4 adaptive_suggestion rows persisted for this session/rule.';
        }

        $stmt = $pdo->prepare("
            SELECT event_id, artefact_id FROM event_log
            WHERE session_id = :id AND event_type = 'adaptive_trigger' AND rule_id = :rule_id
        ");
        $stmt->execute([':id' => $sessionId, ':rule_id' => $data2['rule_id']]);
        $triggerEvent = $stmt->fetch();

        if (!$triggerEvent) {
            $errors[] = 'TC3: no adaptive_trigger row found in event_log for this session/rule.';
        } else {
            if ($triggerEvent['artefact_id'] !== null) {
                $errors[] = 'TC3: adaptive_trigger from a heartbeat call (no artefact_id sent) should have '
                    . 'a NULL artefact_id - it is session-level, not artefact-specific.';
            }

            $stmt = $pdo->prepare('SELECT trigger_confidence FROM adaptive_event WHERE event_id = :event_id');
            $stmt->execute([':event_id' => $triggerEvent['event_id']]);
            $triggerConfidence = $stmt->fetchColumn();

            if ($triggerConfidence === false) {
                $errors[] = 'TC3: no matching adaptive_event row found for the adaptive_trigger event.';
            } elseif (abs((float)$triggerConfidence - (float)$data2['interest_score']) > 0.01) {
                $errors[] = "TC3: adaptive_event.trigger_confidence ({$triggerConfidence}) does not match "
                    . "the returned interest_score ({$data2['interest_score']}).";
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - cascades
    // through session_log to event_log/adaptive_suggestion/adaptive_event.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AP2 - Test heartbeat update\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$triggerUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "A heartbeat-style call (session_id only, no artefact_id) stays silent on an idle session, and, once ";
    echo "navigation_depth crosses the threshold, quietly produces and persists a real 4-suggestion update.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
