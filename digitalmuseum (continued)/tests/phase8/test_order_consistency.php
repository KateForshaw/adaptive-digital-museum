<?php
// =====================================================================
// Digital Museum Research Project
// Phase 8 - Artefact Order
// tests/phase8/test_order_consistency.php
//
// Test case AO2 (Testing Plans.docx > Phase 8 Test Cases)
//   Description:     Test order consistency within participant
//   Steps:            Load static.php then adaptive.php as the same
//                      participant
//   Expected Result:  Same artefact order appears in both
//
// Same pattern as tests/phase8/test_order_randomization.php (AO1): one
// real, throwaway participant is created via index.php's actual
// researcher-setup form, and both static.php and adaptive.php are
// loaded for real over HTTP with the same session cookie. The artefact
// display order is read straight off each page's rendered grid markup
// (data-artefact-id, in DOM order).
//
// Neither page's guard requires the other version to already be
// complete (static.php only blocks if timeout_triggered_static is
// already true; adaptive.php only blocks if timeout_triggered_adaptive
// is already true - see their guard clauses), so both can be loaded
// back-to-back with no transition page/timeout simulation needed for
// this test.
//
// Beyond the literal AO2 result (TC2), this also checks TC1 (both
// grids rendered all 60 artefacts), in the same spirit as AO1's extra
// checks.
//
// Creates one throwaway participant/session, then deletes it - leaving
// the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl     = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl    = $baseUrl . '/digitalmuseum/index.php';
$staticUrl   = $baseUrl . '/digitalmuseum/static.php';
$adaptiveUrl = $baseUrl . '/digitalmuseum/adaptive.php';

$errors        = [];
$participantId = null;

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

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ao2_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: one real throwaway participant via index.php's researcher-
    // setup form. Kept short deliberately: participant_code is
    // VARCHAR(20) (database/create_tables.sql) and index.php itself
    // rejects anything over 20 characters - "AO2_" + a 10-digit time()
    // suffix is 14 characters, safely under that limit (AO1's original
    // "TEST_AO1_P1_" + time() prefix ran over this limit and silently
    // failed - see tests/phase8/test_order_randomization.php).
    // -------------------------------------------------------------
    $participantCode = 'AO2_' . time();

    [, $setupBody] = httpRequest($indexUrl, $cookieJarPath, [
        'participant_code' => $participantCode,
        'condition_name'   => 'static_first',
        'start_session'    => '1',
    ]);

    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $participantCode]);
    $participantId = $stmt->fetchColumn();
    if ($participantId === false) {
        $formError = null;
        if (preg_match('/<ul class="form-errors">.*?<li>(.*?)<\/li>/s', $setupBody, $m)) {
            $formError = strip_tags($m[1]);
        }
        $reason = $formError !== null ? "index.php rejected the form: {$formError}" : 'index.php form submission did not create the participant.';
        throw new RuntimeException("Participant '{$participantCode}' was not created - {$reason}");
    }
    $participantId = (int)$participantId;

    // -------------------------------------------------------------
    // AO2's literal steps: load static.php, then adaptive.php, as the
    // same participant (same cookie jar - same PHP session).
    // -------------------------------------------------------------
    [$httpCodeStatic, $bodyStatic] = httpRequest($staticUrl, $cookieJarPath);
    if ($httpCodeStatic !== 200 || strpos($bodyStatic, '<body class="static-page">') === false) {
        throw new RuntimeException("static.php did not render correctly for participant '{$participantCode}' (HTTP {$httpCodeStatic}).");
    }
    $staticOrder = extractArtefactOrder($bodyStatic);

    [$httpCodeAdaptive, $bodyAdaptive] = httpRequest($adaptiveUrl, $cookieJarPath);
    if ($httpCodeAdaptive !== 200 || strpos($bodyAdaptive, '<body class="adaptive-page">') === false) {
        throw new RuntimeException("adaptive.php did not render correctly for participant '{$participantCode}' (HTTP {$httpCodeAdaptive}).");
    }
    $adaptiveOrder = extractArtefactOrder($bodyAdaptive);

    // -------------------------------------------------------------
    // TC1: both grids actually rendered every artefact - matching
    // orders wouldn't mean much if both were truncated the same way.
    // -------------------------------------------------------------
    $stmt = $pdo->query('SELECT COUNT(*) FROM artefact');
    $totalArtefacts = (int)$stmt->fetchColumn();

    if (count($staticOrder) !== $totalArtefacts) {
        $errors[] = "TC1: static.php's grid rendered " . count($staticOrder) . " artefacts, expected {$totalArtefacts}.";
    }
    if (count($adaptiveOrder) !== $totalArtefacts) {
        $errors[] = "TC1: adaptive.php's grid rendered " . count($adaptiveOrder) . " artefacts, expected {$totalArtefacts}.";
    }

    // -------------------------------------------------------------
    // TC2 (AO2's literal step/result): same participant -> same
    // artefact order in both static.php and adaptive.php.
    // -------------------------------------------------------------
    if ($staticOrder !== $adaptiveOrder) {
        $errors[] = 'TC2: static.php and adaptive.php showed different artefact orders for the same participant - expected identical orders.';
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    @unlink($cookieJarPath);

    // Always clean up, even if an assertion above failed - deleting the
    // participant cascades to session_log, event_log, timeout, and
    // questionnaire_response (ON DELETE CASCADE - see create_tables.sql).
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

echo "AO2 - Test order consistency within participant\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$staticUrl}, {$adaptiveUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "The same participant was shown the exact same, fully complete,\n";
    echo "artefact grid order on both static.php and adaptive.php.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
