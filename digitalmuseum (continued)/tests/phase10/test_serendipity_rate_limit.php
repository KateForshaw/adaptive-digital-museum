<?php
// =====================================================================
// Digital Museum Research Project
// Phase 10 - Pilot Testing
// tests/phase10/test_serendipity_rate_limit.php
//
// Test case PT4 (Testing Plans.docx > Phase 10 Test Cases - added this
// session, after PT2 surfaced the gap this test proves is now fixed)
//   Description:      Check serendipity frequency control
//   Steps:             Trigger serendipity twice within 20s
//   Expected Result:  Second trigger blocked; third (after gap) allowed
//
// PT2 (tests/phase10/test_check_adaptive_behaviour.php) found real
// pilot data (PILOT1, session 47) where 'serendipity_theme_subtheme_switch'
// fired 10 times inside one second - Adaptive Logic.docx's "Serendipity
// Frequency Control" (max 1 update/20s, min 10s between triggers) was
// specified but never actually implemented anywhere. The fix added
// SERENDIPITY_MIN_GAP_SECONDS + getLastSerendipityTriggerTimestamp() to
// api/trigger_rule.php. This test proves that fix behaves correctly.
//
// Deliberately reproduces the exact rule PT2 found the problem with -
// serendipity_theme_subtheme_switch (insert_rules.sql #25: theme_switches
// >= 2 AND subtheme_switches >= 5) - rather than AP3's dwell_immersive/
// artefact_popularity=low recipe. That rule depends on artefact_popularity,
// which is a GLOBAL click count across every session ever run (api/
// trigger_rule.php's deriveArtefactSignals()) - after months of real
// pilot/dev testing there's no longer any guaranteed "zero click history"
// artefact to rely on (this test's first version failed for exactly that
// reason). theme_switches/subtheme_switches, by contrast, are derived
// purely from THIS session's own 'click' event_log rows
// (deriveSessionSignals()), so they're fully controllable regardless of
// what any other session has ever done: 6 clicks alternating between the
// study's 2 themes, each from a distinct subtheme, gives theme_switches=5
// and subtheme_switches=5 - comfortably past both thresholds - with no
// dwell_end events needed at all (a plain heartbeat-style call, no
// artefact_id, is enough to exercise this rule).
//
//   TC1 - the qualifying click sequence + first call updates normally
//         (mode=serendipity, rule_name=serendipity_theme_subtheme_switch)
//         and persists an adaptive_event.
//   TC2 - a second call immediately afterwards (same session, no new
//         clicks needed - the same cumulative signals still qualify,
//         exactly reproducing what PILOT1's session actually did) must
//         be rate-limited: updated=false, reason=serendipity_rate_limited,
//         nothing new persisted.
//   TC3 - TC1's persisted adaptive_event/event_log timestamps are pushed
//         25s into the past directly in the DB (the AP5/AE5 technique -
//         simulating elapsed time rather than sleeping in the test), then
//         a third call: the gap has now passed, so this one must update
//         normally again.
//
// Creates one throwaway participant/session directly via PDO, simulates
// browsing by inserting event_log rows directly, calls the real endpoint
// over HTTP, and cleans up afterwards (participant delete cascades
// through session_log to everything else - create_tables.sql).
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl    = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$triggerUrl = $baseUrl . '/digitalmuseum/api/trigger_rule.php';
$testParticipantCode = 'PT4_' . time();

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

/**
 * One artefact per distinct subtheme within $themeId, up to $count -
 * schema/catalogue data only (theme_id/subtheme_id never change), so
 * unlike click-history-based popularity this is unaffected by however
 * much real pilot/dev usage has already happened.
 */
function pickArtefactsFromTheme(PDO $pdo, int $themeId, int $count): array
{
    $count = max(0, $count);
    $stmt = $pdo->prepare("
        SELECT MIN(a.artefact_id) AS artefact_id
        FROM artefact a
        WHERE a.theme_id = :theme_id
        GROUP BY a.subtheme_id
        ORDER BY a.subtheme_id
        LIMIT {$count}
    ");
    $stmt->execute([':theme_id' => $themeId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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

    // -------------------------------------------------------------
    // Build a click sequence alternating theme A / theme B, each click
    // from a distinct subtheme: 6 clicks -> 5 transitions, every one a
    // theme switch AND a subtheme switch (theme_switches=5,
    // subtheme_switches=5 - both well past serendipity_theme_subtheme_
    // switch's thresholds of 2 and 5).
    // -------------------------------------------------------------
    $themeIds = $pdo->query('SELECT theme_id FROM theme ORDER BY theme_id')->fetchAll(PDO::FETCH_COLUMN);
    if (count($themeIds) < 2) {
        throw new RuntimeException('Need at least 2 themes - has database/insert_themes.sql been run?');
    }
    [$themeA, $themeB] = array_map('intval', array_slice($themeIds, 0, 2));

    $artefactsA = pickArtefactsFromTheme($pdo, $themeA, 3);
    $artefactsB = pickArtefactsFromTheme($pdo, $themeB, 3);
    if (count($artefactsA) < 3 || count($artefactsB) < 3) {
        throw new RuntimeException('Need at least 3 distinct subthemes per theme - found '
            . count($artefactsA) . ' for theme ' . $themeA . ', ' . count($artefactsB) . ' for theme ' . $themeB . '.');
    }

    $clickSequence = [];
    for ($i = 0; $i < 3; $i++) {
        $clickSequence[] = $artefactsA[$i];
        $clickSequence[] = $artefactsB[$i];
    }

    $clickTime = time();
    foreach ($clickSequence as $artefactId) {
        $pdo->prepare("
            INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
            VALUES (:session_id, :artefact_id, 'click', :timestamp)
        ")->execute([
            ':session_id'  => $sessionId,
            ':artefact_id' => $artefactId,
            ':timestamp'   => date('Y-m-d H:i:s', $clickTime),
        ]);
        $clickTime++; // keep rows distinctly ordered, well within the task window
    }

    // -------------------------------------------------------------
    // TC1 - first call against the qualifying click sequence: should
    // update normally via serendipity_theme_subtheme_switch.
    // -------------------------------------------------------------
    [$httpCode1, $data1] = postJson($triggerUrl, ['session_id' => $sessionId]);

    if ($httpCode1 !== 200 || ($data1['success'] ?? false) !== true) {
        $errors[] = 'TC1: request failed. Body: ' . json_encode($data1);
    } elseif (($data1['updated'] ?? null) !== true || ($data1['mode'] ?? null) !== 'serendipity') {
        $errors[] = 'TC1: expected updated=true, mode=serendipity for the qualifying click sequence, got: '
            . json_encode($data1);
    } elseif (($data1['rule_name'] ?? null) !== 'serendipity_theme_subtheme_switch') {
        $errors[] = "TC1: expected rule_name='serendipity_theme_subtheme_switch', got '"
            . ($data1['rule_name'] ?? 'null') . "'.";
    }

    // -------------------------------------------------------------
    // TC2 - a second call immediately afterwards, same session, no new
    // clicks needed (the same cumulative signals still qualify) - must
    // be rate-limited.
    // -------------------------------------------------------------
    [$httpCode2, $data2] = postJson($triggerUrl, ['session_id' => $sessionId]);

    if ($httpCode2 !== 200 || ($data2['success'] ?? false) !== true) {
        $errors[] = 'TC2: request failed. Body: ' . json_encode($data2);
    } elseif (($data2['updated'] ?? null) !== false || ($data2['reason'] ?? null) !== 'serendipity_rate_limited') {
        $errors[] = 'TC2: expected updated=false, reason=serendipity_rate_limited (<20s after TC1), got: '
            . json_encode($data2);
    } else {
        // Confirm nothing new was persisted for this blocked call - only
        // TC1's 1 trigger + 4 suggestions should exist so far.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'adaptive_trigger'");
        $stmt->execute([':id' => $sessionId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            $errors[] = 'TC2: expected still only 1 adaptive_trigger event logged (TC1\'s) - the rate-limited '
                . 'call should not have logged a second one.';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM adaptive_suggestion WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        if ((int)$stmt->fetchColumn() !== 4) {
            $errors[] = 'TC2: expected still only 4 adaptive_suggestion rows (TC1\'s) - the rate-limited call '
                . 'should not have persisted a second batch.';
        }
    }

    // -------------------------------------------------------------
    // TC3 - push TC1's logged trigger 25s into the past (simulating
    // elapsed time, same technique as AP5/AE5), then a third call
    // should update normally again.
    // -------------------------------------------------------------
    $pastTimestamp = date('Y-m-d H:i:s', time() - 25);
    $pdo->prepare("
        UPDATE adaptive_event ae
        JOIN event_log el ON el.event_id = ae.event_id
        SET ae.trigger_timestamp = :ts, el.`timestamp` = :ts
        WHERE ae.session_id = :session_id
    ")->execute([':ts' => $pastTimestamp, ':session_id' => $sessionId]);

    [$httpCode3, $data3] = postJson($triggerUrl, ['session_id' => $sessionId]);

    if ($httpCode3 !== 200 || ($data3['success'] ?? false) !== true) {
        $errors[] = 'TC3: request failed. Body: ' . json_encode($data3);
    } elseif (($data3['updated'] ?? null) !== true || ($data3['mode'] ?? null) !== 'serendipity') {
        $errors[] = 'TC3: expected updated=true, mode=serendipity once the last serendipity trigger is 25s in '
            . 'the past (>=20s gap), got: ' . json_encode($data3);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'adaptive_trigger'");
        $stmt->execute([':id' => $sessionId]);
        if ((int)$stmt->fetchColumn() !== 2) {
            $errors[] = 'TC3: expected 2 adaptive_trigger events now (TC1 + TC3) - TC2 was correctly blocked.';
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "PT4 - Check serendipity frequency control\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$triggerUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "A serendipity-qualifying click sequence updates the panel normally, an immediate second call on the ";
    echo "same still-qualifying signals is correctly rate-limited with nothing persisted, and a third call made ";
    echo "once the gap has passed (>=20s) updates the panel again.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
