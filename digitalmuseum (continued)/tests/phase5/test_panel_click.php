<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// tests/phase5/test_panel_click.php
//
// Test case AP4 (Testing Plans.docx > Phase 5 Test Cases)
//   Description:     Test panel click logging
//   Steps:            Click suggestion
//   Expected Result:  panel_click logged
//
// Phase 2's BL8 (tests/phase2/test_adaptive_event_logging.php) already
// covers api/log_adaptive_event.php generically for all 3 adaptive-only
// event types, including validation/error cases - no need to repeat
// that here. AP4's focus is narrower and more specific: adaptive.php's
// panel click handler is what actually fires panel_click in practice
// (see its "Adaptive panel: click delegation" section), and the one
// thing BL8 never checked is whether the click is actually recorded
// against the suggestion it belongs to - adaptive_suggestion.clicked_flag,
// fixed alongside this test (api/log_adaptive_event.php now sets it on
// a panel_click - previously nothing in the codebase ever did, despite
// every suggestion being created with clicked_flag = FALSE).
//
// Creates a throwaway participant/session/adaptive_suggestion pair
// directly in the database (same fixture pattern as BL8), calls the
// real endpoint over HTTP with cURL exactly as adaptive.php's panel
// click handler does, and cleans up afterwards.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/log_adaptive_event.php';

$errors        = [];
$testParticipantCode = 'TEST_AP4_' . time();
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

function fetchClickedFlag(PDO $pdo, int $suggestionId): bool
{
    $stmt = $pdo->prepare('SELECT clicked_flag FROM adaptive_suggestion WHERE suggestion_id = :id');
    $stmt->execute([':id' => $suggestionId]);
    return (bool)$stmt->fetchColumn();
}

try {
    // -------------------------------------------------------------
    // Setup: throwaway participant + session, a real rule_id and
    // artefact_id, and TWO sibling suggestions from the same panel
    // update - only one of them gets clicked, so the fix's UPDATE can
    // be checked as scoped to suggestion_id, not every row.
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

    $ruleId = $pdo->query('SELECT rule_id FROM adaptive_rule ORDER BY rule_id LIMIT 1')->fetchColumn();
    if ($ruleId === false) {
        throw new RuntimeException('adaptive_rule table is empty - run insert_rules.sql first.');
    }
    $ruleId = (int)$ruleId;

    $artefactIds = $pdo->query('SELECT artefact_id FROM artefact ORDER BY artefact_id LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($artefactIds) < 2) {
        throw new RuntimeException('Need at least 2 artefacts to set up this test.');
    }

    $suggestionIds = [];
    foreach ($artefactIds as $position => $artefactId) {
        $stmt = $pdo->prepare("
            INSERT INTO adaptive_suggestion
                (rule_id, session_id, artefact_id, suggestion_timestamp, panel_position, clicked_flag)
            VALUES
                (:rule_id, :session_id, :artefact_id, :timestamp, :position, FALSE)
        ");
        $stmt->execute([
            ':rule_id'     => $ruleId,
            ':session_id'  => $sessionId,
            ':artefact_id' => $artefactId,
            ':timestamp'   => date('Y-m-d H:i:s'),
            ':position'    => $position,
        ]);
        $suggestionIds[] = (int)$pdo->lastInsertId();
    }
    [$clickedSuggestionId, $unclickedSuggestionId] = $suggestionIds;
    $clickedArtefactId = $artefactIds[0];

    // -------------------------------------------------------------
    // AP4 itself: click the first suggestion - same payload shape as
    // adaptive.php's panel click handler sends via MuseumLogging.logAdaptiveEvent().
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($endpoint, [
        'session_id'    => $sessionId,
        'event_type'    => 'panel_click',
        'rule_id'       => $ruleId,
        'suggestion_id' => $clickedSuggestionId,
        'artefact_id'   => $clickedArtefactId,
    ]);

    if ($httpCode !== 201 || ($data['success'] ?? false) !== true) {
        $errors[] = "Request failed - expected HTTP 201 + success=true, got {$httpCode}. Body: " . json_encode($data);
    } else {
        // event_log side (already covered generically by BL8, checked
        // briefly here too since it's central to what AP4 asks for)
        $stmt = $pdo->prepare('SELECT * FROM event_log WHERE event_id = :id');
        $stmt->execute([':id' => $data['event_id']]);
        $eventRow = $stmt->fetch();

        if (!$eventRow || $eventRow['event_type'] !== 'panel_click') {
            $errors[] = 'event_log row was not stored as a panel_click event.';
        } elseif ((int)$eventRow['suggestion_id'] !== $clickedSuggestionId) {
            $errors[] = 'event_log.suggestion_id does not match the clicked suggestion.';
        }

        // The fix under test: clicked_flag on the clicked suggestion...
        if (!fetchClickedFlag($pdo, $clickedSuggestionId)) {
            $errors[] = 'adaptive_suggestion.clicked_flag was NOT set to TRUE after a panel_click - the fix did not take effect.';
        }
        // ...and NOT on its unclicked sibling from the same panel update.
        if (fetchClickedFlag($pdo, $unclickedSuggestionId)) {
            $errors[] = 'adaptive_suggestion.clicked_flag was set on a suggestion that was never clicked - '
                . 'the UPDATE is not correctly scoped to suggestion_id.';
        }

        // -------------------------------------------------------------
        // Clicking the same suggestion again: still logs a new event
        // (every click is its own event_log row), and clicked_flag
        // simply stays TRUE - not toggled.
        // -------------------------------------------------------------
        [$httpCode2, $data2] = postJson($endpoint, [
            'session_id'    => $sessionId,
            'event_type'    => 'panel_click',
            'rule_id'       => $ruleId,
            'suggestion_id' => $clickedSuggestionId,
            'artefact_id'   => $clickedArtefactId,
        ]);

        if ($httpCode2 !== 201 || ($data2['success'] ?? false) !== true) {
            $errors[] = "Second click on the same suggestion failed, expected HTTP 201, got {$httpCode2}.";
        } elseif ((int)$data2['event_id'] === (int)$data['event_id']) {
            $errors[] = 'Second click should create a new event_log row, not reuse the first.';
        }
        if (!fetchClickedFlag($pdo, $clickedSuggestionId)) {
            $errors[] = 'clicked_flag should remain TRUE after a second click on the same suggestion.';
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - cascades
    // through session_log to event_log/adaptive_suggestion/adaptive_event.
    // The rule and artefacts used are pre-existing seed data and are
    // never modified.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AP4 - Test panel click logging\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Clicking a suggestion logs a panel_click event correctly and sets adaptive_suggestion.clicked_flag, ";
    echo "scoped to exactly that suggestion, and repeat clicks keep logging without disturbing the flag.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
