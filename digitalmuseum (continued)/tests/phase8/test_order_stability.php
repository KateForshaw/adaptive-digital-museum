<?php
// =====================================================================
// Digital Museum Research Project
// Phase 8 - Artefact Order
// tests/phase8/test_order_stability.php
//
// Test case AO4 (Testing Plans.docx > Phase 8 Test Cases)
//   Description:     Test order stability on reload
//   Steps:            Reload static.php mid-session
//   Expected Result:  Artefact order remains unchanged
//
// Same pattern as the rest of the Phase 8 tests (AO1-AO3): one real,
// throwaway participant is created via index.php's actual
// researcher-setup form, and static.php is loaded for real over HTTP.
// The artefact display order is read straight off the rendered grid
// markup (data-artefact-id, in DOM order).
//
// Where AO2 (test_order_consistency.php) reloads with the SAME
// participant_id across two DIFFERENT pages (static.php then
// adaptive.php), AO4 reloads the SAME page three times in the same
// session - the more literal "hit refresh mid-task" scenario a
// participant would actually trigger, and a distinct code path: it
// confirms includes/artefact_order.php's seeded shuffle is stable
// across repeated calls within one request-response cycle each time,
// not just stable when compared across two different pages.
//
// Beyond the literal AO4 result (TC2, first vs. second load), TC1
// checks both loads rendered all 60 artefacts, and TC3 reloads a third
// time to rule out the first two loads having matched by coincidence.
//
// Creates one throwaway participant/session, then deletes it - leaving
// the database exactly as it was before.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl   = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$indexUrl  = $baseUrl . '/digitalmuseum/index.php';
$staticUrl = $baseUrl . '/digitalmuseum/static.php';

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

/** Loads static.php and returns [httpCode, artefactOrder]. */
function loadStaticOrder(string $staticUrl, string $cookieJarPath): array
{
    [$httpCode, $body] = httpRequest($staticUrl, $cookieJarPath);
    if ($httpCode !== 200 || strpos($body, '<body class="static-page">') === false) {
        throw new RuntimeException("static.php did not render correctly (HTTP {$httpCode}).");
    }
    return [$httpCode, extractArtefactOrder($body)];
}

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ao4_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: one real throwaway participant via index.php's researcher-
    // setup form. Kept short deliberately: participant_code is
    // VARCHAR(20) (database/create_tables.sql) and index.php itself
    // rejects anything over 20 characters - "AO4_" + a 10-digit time()
    // suffix is 14 characters, safely under that limit (AO1's original
    // prefix ran over this limit and silently failed - see
    // tests/phase8/test_order_randomization.php).
    // -------------------------------------------------------------
    $participantCode = 'AO4_' . time();

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
    // AO4's literal step: reload static.php mid-session, three times,
    // same cookie jar (same PHP session) throughout.
    // -------------------------------------------------------------
    [, $order1] = loadStaticOrder($staticUrl, $cookieJarPath);
    [, $order2] = loadStaticOrder($staticUrl, $cookieJarPath);
    [, $order3] = loadStaticOrder($staticUrl, $cookieJarPath);

    // -------------------------------------------------------------
    // TC1: every reload actually rendered all 60 artefacts - matching
    // orders wouldn't mean much if all three loads were truncated the
    // same way.
    // -------------------------------------------------------------
    $stmt = $pdo->query('SELECT COUNT(*) FROM artefact');
    $totalArtefacts = (int)$stmt->fetchColumn();

    foreach (['first' => $order1, 'second' => $order2, 'third' => $order3] as $label => $order) {
        if (count($order) !== $totalArtefacts) {
            $errors[] = "TC1: the {$label} load rendered " . count($order) . " artefacts, expected {$totalArtefacts}.";
        }
    }

    // -------------------------------------------------------------
    // TC2 (AO4's literal step/result): reloading static.php mid-session
    // does not change the artefact order.
    // -------------------------------------------------------------
    if ($order1 !== $order2) {
        $errors[] = 'TC2: reloading static.php changed the artefact order between the first and second load - expected it to stay the same.';
    }

    // -------------------------------------------------------------
    // TC3: a third reload still matches, ruling out the first two loads
    // having matched by coincidence.
    // -------------------------------------------------------------
    if ($order1 !== $order3) {
        $errors[] = 'TC3: reloading static.php a third time changed the artefact order - expected it to stay the same across every reload.';
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

echo "AO4 - Test order stability on reload\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$staticUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Reloading static.php mid-session showed the exact same, fully complete,\n";
    echo "artefact grid order every time.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
