<?php
// =====================================================================
// Digital Museum Research Project
// Phase 8 - Artefact Order
// tests/phase8/test_subtheme_interleaving.php
//
// Test case AO3 (Testing Plans.docx > Phase 8 Test Cases)
//   Description:     Test subtheme interleaving
//   Steps:            Inspect the first 12 artefacts shown
//   Expected Result:  No two consecutive artefacts share the same
//                      subtheme
//
// Same pattern as tests/phase8/test_order_randomization.php (AO1) and
// test_order_consistency.php (AO2): one real, throwaway participant is
// created via index.php's actual researcher-setup form, and static.php
// is loaded for real over HTTP. The artefact display order is read
// straight off the rendered grid markup (data-artefact-id, in DOM
// order), then each artefact_id is looked up against the DB to get its
// subtheme - simpler and more robust than parsing subtheme names back
// out of the nested card HTML.
//
// Beyond the literal AO3 check (TC1 - just the first 12 cards, exactly
// as the test case's own steps describe), TC2 extends the same
// no-two-consecutive-same-subtheme check across the *entire* rendered
// grid, not only the first 12 - includes/artefact_order.php's
// round-robin interleave (Phase 8 design decision, see that file's
// header comment) is meant to hold throughout the grid, not just at
// the start, so this is a stronger version of the same expectation
// rather than a different one.
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

/**
 * Returns the artefact_id positions (0-based) of the first place two
 * consecutive entries in $order share the same subtheme, using
 * $subthemeByArtefactId to look each one up - empty array if none
 * found. Only checks up to $limit entries (null = check all of $order).
 */
function findConsecutiveSameSubtheme(array $order, array $subthemeByArtefactId, ?int $limit = null): array
{
    $violations = [];
    $count      = $limit === null ? count($order) : min($limit, count($order));

    for ($i = 1; $i < $count; $i++) {
        $prevId = $order[$i - 1];
        $curId  = $order[$i];
        $prevSubtheme = $subthemeByArtefactId[$prevId] ?? null;
        $curSubtheme  = $subthemeByArtefactId[$curId] ?? null;
        if ($prevSubtheme !== null && $prevSubtheme === $curSubtheme) {
            $violations[] = "position " . ($i - 1) . " (artefact_id {$prevId}) and position {$i} (artefact_id {$curId}) are both '{$prevSubtheme}'";
        }
    }

    return $violations;
}

$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_ao3_cookies_');

try {
    // -------------------------------------------------------------
    // Setup: one real throwaway participant via index.php's researcher-
    // setup form. Kept short deliberately: participant_code is
    // VARCHAR(20) (database/create_tables.sql) and index.php itself
    // rejects anything over 20 characters - "AO3_" + a 10-digit time()
    // suffix is 14 characters, safely under that limit (AO1's original
    // prefix ran over this limit and silently failed - see
    // tests/phase8/test_order_randomization.php).
    // -------------------------------------------------------------
    $participantCode = 'AO3_' . time();

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
    // AO3's literal step: load static.php, inspect the artefacts shown.
    // -------------------------------------------------------------
    [$httpCode, $body] = httpRequest($staticUrl, $cookieJarPath);
    if ($httpCode !== 200 || strpos($body, '<body class="static-page">') === false) {
        throw new RuntimeException("static.php did not render correctly for participant '{$participantCode}' (HTTP {$httpCode}).");
    }
    $order = extractArtefactOrder($body);

    if (count($order) < 12) {
        throw new RuntimeException('static.php rendered fewer than 12 artefacts - cannot inspect "the first 12 artefacts shown" (AO3).');
    }

    // Look up each artefact_id's subtheme from the DB, rather than
    // re-parsing subtheme names out of the card HTML.
    $stmt = $pdo->query('SELECT a.artefact_id, s.subtheme_name FROM artefact a JOIN subtheme s ON a.subtheme_id = s.subtheme_id');
    $subthemeByArtefactId = [];
    foreach ($stmt->fetchAll() as $row) {
        $subthemeByArtefactId[(int)$row['artefact_id']] = $row['subtheme_name'];
    }

    // -------------------------------------------------------------
    // TC1 (AO3's literal check): no two consecutive artefacts among the
    // first 12 shown share the same subtheme.
    // -------------------------------------------------------------
    $first12Violations = findConsecutiveSameSubtheme($order, $subthemeByArtefactId, 12);
    foreach ($first12Violations as $violation) {
        $errors[] = "TC1: {$violation} (within the first 12 artefacts).";
    }

    // -------------------------------------------------------------
    // TC2: the same no-two-consecutive-same-subtheme property holds
    // across the entire grid, not just the first 12 - the round-robin
    // interleave in includes/artefact_order.php is meant to apply
    // throughout, so this checks the design decision more thoroughly
    // than AO3's literal step alone.
    // -------------------------------------------------------------
    $fullGridViolations = findConsecutiveSameSubtheme($order, $subthemeByArtefactId);
    foreach ($fullGridViolations as $violation) {
        $errors[] = "TC2: {$violation} (across the full grid).";
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

echo "AO3 - Test subtheme interleaving\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: {$indexUrl}, {$staticUrl}\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "No two consecutive artefacts share the same subtheme, in the first 12\n";
    echo "cards shown or across the full rendered grid.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
