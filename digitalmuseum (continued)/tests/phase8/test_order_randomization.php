<?php
// =====================================================================
// Digital Museum Research Project
// Phase 8 - Artefact Order
// tests/phase8/test_order_randomization.php
//
// Test case AO1 (Testing Plans.docx > Phase 8 Test Cases)
//   Description:     Test order randomisation
//   Steps:            Load static.php as two different participants
//   Expected Result:  Different artefact orders appear
//
// Same pattern as the Phase 7 tests (see tests/phase7/test_reset_logic.php):
// two real, throwaway participants are created via index.php's actual
// researcher-setup form (not by writing to session_log directly), each
// with its own cookie jar, and static.php is loaded for real over HTTP
// for each. The artefact display order is read straight off the
// rendered grid markup (data-artefact-id, in DOM order) rather than the
// page's embedded ARTEFACTS JSON, since the grid markup is what a
// participant - and AO1's literal step - actually sees.
//
// Beyond the literal AO1 result (TC2), this also checks TC1 (both
// grids actually rendered all 60 artefacts) and TC3 (each participant's
// order is a genuine reshuffle of the full catalogue, not a subset or a
// duplicate) - both implicit in Phase 8 Testing Plan's Exit Criteria
// ("Order is randomised per participant... without breaking
// comparability") and in the same spirit as how the Phase 7 tests check
// more than just their one literal step.
//
// Creates two throwaway participants/sessions, then deletes both -
// leaving the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl    = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl   = $baseUrl . '/digitalmuseum/index.php';
$staticUrl  = $baseUrl . '/digitalmuseum/static.php';

$errors         = [];
$participantIds = [];

/** GET/POST-with-form-fields via cURL, sharing cookies with $cookieJarPath. */
function httpRequest(string $url, string $cookieJarPath, ?array $postFields = null): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJarPath,
        CURLOPT_COOKIEFILE     => $cookieJarPath,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($postFields !== null) {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $body];
}

/** Reads the grid's artefact display order straight off the rendered markup. */
function extractArtefactOrder(string $html): array
{
    preg_match_all('/data-artefact-id="(\d+)"/', $html, $matches);
    return array_map('intval', $matches[1]);
}

/**
 * Creates one throwaway participant/session via index.php's real
 * researcher-setup form, then loads static.php for real - returns
 * [participantId, artefactOrder].
 */
function loadStaticAsNewParticipant(PDO $pdo, string $indexUrl, string $staticUrl, string $participantCode): array
{
    $cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ao1_cookies_');

    [, $setupBody] = httpRequest($indexUrl, $cookieJarPath, [
        'participant_code' => $participantCode,
        'condition_name'   => 'static_first',
        'start_session'    => '1',
    ]);

    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $participantCode]);
    $participantId = $stmt->fetchColumn();
    if ($participantId === false) {
        @unlink($cookieJarPath);
        // Surface index.php's own "form-errors" list (e.g. participant_code
        // over 20 characters, or an unselected condition) instead of just
        // "cannot continue" - it's usually the real reason the row was
        // never created.
        $formError = null;
        if (preg_match('/<ul class="form-errors">.*?<li>(.*?)<\/li>/s', $setupBody, $m)) {
            $formError = strip_tags($m[1]);
        }
        $reason = $formError !== null ? "index.php rejected the form: {$formError}" : 'index.php form submission did not create the participant.';
        throw new RuntimeException("Participant '{$participantCode}' was not created - {$reason}");
    }
    $participantId = (int)$participantId;

    [$httpCode, $body] = httpRequest($staticUrl, $cookieJarPath);
    @unlink($cookieJarPath);

    if ($httpCode !== 200 || strpos($body, '<body class="static-page">') === false) {
        throw new RuntimeException("static.php did not render correctly for participant '{$participantCode}' (HTTP {$httpCode}).");
    }

    return [$participantId, extractArtefactOrder($body)];
}

try {
    // Kept short deliberately: participant_code is VARCHAR(20)
    // (database/create_tables.sql) and index.php itself rejects
    // anything over 20 characters - "AO1P1_" + a 10-digit time()
    // suffix is 16 characters, safely under that limit.
    $codeSuffix = time();

    [$participantId1, $order1] = loadStaticAsNewParticipant($pdo, $indexUrl, $staticUrl, "AO1P1_{$codeSuffix}");
    $participantIds[] = $participantId1;

    [$participantId2, $order2] = loadStaticAsNewParticipant($pdo, $indexUrl, $staticUrl, "AO1P2_{$codeSuffix}");
    $participantIds[] = $participantId2;

    // -------------------------------------------------------------
    // TC1: both grids actually rendered every artefact - a difference
    // caused by a truncated/broken grid wouldn't be evidence of real
    // randomisation.
    // -------------------------------------------------------------
    $stmt = $pdo->query('SELECT COUNT(*) FROM artefact');
    $totalArtefacts = (int)$stmt->fetchColumn();

    if (count($order1) !== $totalArtefacts) {
        $errors[] = "TC1: participant 1's grid rendered " . count($order1) . " artefacts, expected {$totalArtefacts}.";
    }
    if (count($order2) !== $totalArtefacts) {
        $errors[] = "TC1: participant 2's grid rendered " . count($order2) . " artefacts, expected {$totalArtefacts}.";
    }

    // -------------------------------------------------------------
    // TC2 (AO1's literal step/result): two different participants ->
    // different artefact orders.
    // -------------------------------------------------------------
    if ($order1 === $order2) {
        $errors[] = 'TC2: participant 1 and participant 2 were shown the exact same artefact order - expected different orders.';
    }

    // -------------------------------------------------------------
    // TC3: each order is a genuine reshuffle of the full catalogue -
    // same set of artefact_id as artefact_id (Database Design.docx >
    // "60 artefacts"), just reordered, not a subset or duplicate.
    // -------------------------------------------------------------
    $stmt = $pdo->query('SELECT artefact_id FROM artefact ORDER BY artefact_id');
    $allArtefactIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $sorted1 = $order1;
    sort($sorted1);
    $sorted2 = $order2;
    sort($sorted2);

    if ($sorted1 !== $allArtefactIds) {
        $errors[] = "TC3: participant 1's artefact order is not a reordering of the full catalogue (missing/duplicate artefact_id).";
    }
    if ($sorted2 !== $allArtefactIds) {
        $errors[] = "TC3: participant 2's artefact order is not a reordering of the full catalogue (missing/duplicate artefact_id).";
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed - deleting the
    // participants cascades to session_log, event_log, timeout, and
    // questionnaire_response (ON DELETE CASCADE - see create_tables.sql).
    foreach ($participantIds as $id) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $id]);
    }
}

echo "AO1 - Test order randomisation\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$staticUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Two different participants loading static.php were shown different,\n";
    echo "but each fully complete, artefact grid orders.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
