<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_panel_freeze.php
//
// Test case AP5 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test freeze-before-timeout
//   Steps:            Wait final 30s
//   Expected Result:  Panel stops updating
//
// The freeze window (Adaptive Logic.docx > "Timeout Protection" - "No
// updates occur during the final 30 seconds before the 7-minute
// timeout") is enforced server-side in api/trigger_rule.php, not just
// visually in the client (adaptive.php's freezeCallback just adds a CSS
// class) - so, like AE5's technique in Phase 4, this test simulates
// "waiting" by setting session_start far enough in the past that the
// 390-second freeze boundary (420s session - 30s freeze window) has
// already passed, rather than actually waiting 7 minutes.
//
// Simulates the SAME qualifying browsing signals AP2 used (navigation_depth
// = 4, which reliably clears the update threshold) at two points: just
// before the freeze boundary (still updates normally) and just after it
// (frozen) - proving the freeze specifically suppresses what would
// otherwise be a legitimate update, not just a coincidentally-quiet one.
//
// Creates a throwaway participant/session directly via PDO, simulates
// browsing by inserting event_log rows directly, calls the real
// endpoint over HTTP, and cleans up afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl    = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$triggerUrl = $baseUrl . '/digitalmuseum/api/trigger_rule.php';

$errors = [];
$createdParticipantIds = [];

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
 * Sets up a throwaway participant/session with session_start
 * $secondsAgo in the past, simulates navigation_depth=4 browsing
 * starting shortly after session_start, and returns the session_id.
 *
 * $label must be a single character - participant_code is VARCHAR(20)
 * (create_tables.sql), and 'TEST_AP5' + '_' + $label + '_' + time()'s
 * 10 digits already uses 19 of those 20 characters.
 */
function setUpSessionWithBrowsing(PDO $pdo, string $label, int $secondsAgo, array &$createdParticipantIds): int
{
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => 'AP5_' . $label . '_' . time()]);
    $participantId = (int)$pdo->lastInsertId();
    $createdParticipantIds[] = $participantId;

    $conditionId = $pdo->query('SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1')
        ->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    $sessionStart = date('Y-m-d H:i:s', time() - $secondsAgo);

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
        ':session_start'  => $sessionStart,
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    $subthemeId = $pdo->query('
        SELECT subtheme_id FROM artefact GROUP BY subtheme_id HAVING COUNT(*) >= 4 ORDER BY subtheme_id LIMIT 1
    ')->fetchColumn();
    if ($subthemeId === false) {
        throw new RuntimeException('No subtheme with at least 4 artefacts found - cannot simulate browsing.');
    }
    $stmt = $pdo->prepare('SELECT artefact_id FROM artefact WHERE subtheme_id = :subtheme_id LIMIT 4');
    $stmt->execute([':subtheme_id' => $subthemeId]);
    $artefactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $eventTime = time() - $secondsAgo + 2; // shortly after session_start, still well inside the task window
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
            ':session_id' => $sessionId, ':artefact_id' => $artefactId,
            ':timestamp'  => $timestamp, ':depth' => $depth + 1,
        ]);
        $eventTime += 2;
    }

    return $sessionId;
}

try {
    // -------------------------------------------------------------
    // TC1: 385 seconds into the task (5s before the 390s freeze
    // boundary) - qualifying signals should still update normally.
    // -------------------------------------------------------------
    $activeSessionId = setUpSessionWithBrowsing($pdo, 'A', 385, $createdParticipantIds);
    [$httpCode1, $data1] = postJson($triggerUrl, ['session_id' => $activeSessionId]);

    if ($httpCode1 !== 200 || ($data1['success'] ?? false) !== true) {
        $errors[] = 'TC1: request failed. Body: ' . json_encode($data1);
    } elseif (($data1['updated'] ?? null) !== true) {
        $errors[] = 'TC1: expected updated=true at 385s (still before the freeze window), got: '
            . json_encode($data1);
    }

    // -------------------------------------------------------------
    // TC2: 395 seconds into the task (5s past the 390s freeze
    // boundary) - the SAME qualifying signals must now be suppressed.
    // -------------------------------------------------------------
    $frozenSessionId = setUpSessionWithBrowsing($pdo, 'F', 395, $createdParticipantIds);
    [$httpCode2, $data2] = postJson($triggerUrl, ['session_id' => $frozenSessionId]);

    if ($httpCode2 !== 200 || ($data2['success'] ?? false) !== true) {
        $errors[] = 'TC2: request failed. Body: ' . json_encode($data2);
    } elseif (($data2['updated'] ?? null) !== false || ($data2['reason'] ?? null) !== 'frozen') {
        $errors[] = 'TC2: expected updated=false, reason=frozen at 395s (past the freeze boundary), got: '
            . json_encode($data2);
    } else {
        // Confirm the freeze happens BEFORE anything is persisted, not
        // just an empty-looking response with side effects still applied.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM adaptive_suggestion WHERE session_id = :id');
        $stmt->execute([':id' => $frozenSessionId]);
        if ((int)$stmt->fetchColumn() !== 0) {
            $errors[] = 'TC2: no adaptive_suggestion rows should be persisted while frozen.';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_log WHERE session_id = :id AND event_type = 'adaptive_trigger'");
        $stmt->execute([':id' => $frozenSessionId]);
        if ((int)$stmt->fetchColumn() !== 0) {
            $errors[] = 'TC2: no adaptive_trigger event should be logged while frozen.';
        }
    }

    // -------------------------------------------------------------
    // TC3: exactly on the boundary (390s) - the docx's "final 30 seconds"
    // is inclusive, so this should already be frozen (>=, not >).
    // -------------------------------------------------------------
    $boundarySessionId = setUpSessionWithBrowsing($pdo, 'B', 390, $createdParticipantIds);
    [$httpCode3, $data3] = postJson($triggerUrl, ['session_id' => $boundarySessionId]);

    if ($httpCode3 !== 200 || ($data3['success'] ?? false) !== true) {
        $errors[] = 'TC3: request failed. Body: ' . json_encode($data3);
    } elseif (($data3['updated'] ?? null) !== false || ($data3['reason'] ?? null) !== 'frozen') {
        $errors[] = 'TC3: expected updated=false, reason=frozen exactly at the 390s boundary, got: '
            . json_encode($data3);
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - cascades
    // through session_log to event_log/adaptive_suggestion/adaptive_event.
    foreach (array_unique($createdParticipantIds) as $id) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $id]);
    }
}

echo "AP5 - Test freeze-before-timeout\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$triggerUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "The same qualifying browsing signals update the panel normally right up to the 390s mark, and are ";
    echo "correctly suppressed (with nothing persisted) from that point through the end of the session.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
