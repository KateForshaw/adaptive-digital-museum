<?php
// =====================================================================
// Digital Museum Research Project
// Phase 10 - Pilot Testing
// tests/phase10/test_check_logs_export.php
//
// Test case PT3 (Testing Plans.docx > Phase 10 Test Cases)
//   Description:      Check logs
//   Steps:             Export logs
//   Expected Result:  All events present
//
// Read-only, against REAL pilot data - participant_code 'PILOT1' and
// 'PILOT2' (same convention as PT2 - no throwaway participants, nothing
// inserted or deleted). Two things happen here, matching Testing
// Plans.docx's own framing of this test phase (Tools Used: "...log
// exports"; Test Evidence: "...exported CSV"):
//
//   1. COMPLETENESS CHECK - "All events present" is judged against what
//      a genuinely complete run of index.php -> static.php -> transition1.php
//      -> adaptive.php -> transition2.php -> questionnaire.php actually
//      guarantees, not against every possible event_type (some are
//      legitimately behaviour-dependent and can't be forced to a fixed
//      count):
//        - Exactly 1 'session_start' and exactly 1 'session_end' event
//          (api/session_start.php, api/post_questionnaire_response.php).
//        - Exactly 2 'timeout_redirect' events and exactly 2 `timeout`
//          rows (version_name 'static' + 'adaptive') - js/timeout.js's
//          fireTimeout() is the ONLY way past static.php/adaptive.php
//          (no early "I'm done" button), so a complete session always
//          times out naturally on both tasks.
//        - Zero 'error_trigger' events - fallback.php only logs these on
//          a genuine failure, so any presence here is a red flag, not a
//          missing-item flag.
//        - A questionnaire_response row for every REQUIRED item
//          (questionnaire.php's $optionalItemIds = [6, 11, 13, 14] -
//          duplicated below since it's a literal PHP array there, not a
//          DB-driven flag - both the HTML `required` attribute and
//          post_questionnaire_response.php's server-side check block
//          submission without these, so they must all be present in any
//          row that reached session_end).
//      'click'/'navigation_depth'/'dwell_start'/'dwell_end' (exploration
//      happened at all) and 'revisit'/'adaptive_trigger'/'panel_impression'/
//      'panel_click' (behaviour- and engine-dependent) are reported as
//      evidence but NOT hard-failed on - a quiet pilot participant
//      legitimately produces fewer of these, and that's not a logging
//      defect.
//
//   2. CSV EXPORT - writes tests/phase10/pt3_exports/{code}_event_log.csv,
//      {code}_timeout.csv and {code}_questionnaire_response.csv for each
//      participant, the literal "export logs"/"exported CSV" evidence
//      Testing Plans.docx calls for. Exported regardless of whether the
//      completeness check passes, so the raw evidence is always there to
//      inspect even on a FAIL.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

// questionnaire.php's own source of truth (see header comment above) -
// every other item_id is required.
const OPTIONAL_QUESTIONNAIRE_ITEM_IDS = [6, 11, 13, 14];

$errors           = [];
$participantCodes = ['PILOT1', 'PILOT2'];
$exportDir        = __DIR__ . '/pt3_exports';

if (!is_dir($exportDir) && !mkdir($exportDir, 0777, true) && !is_dir($exportDir)) {
    throw new RuntimeException("Could not create export directory: {$exportDir}");
}

/**
 * Writes $rows (array of associative arrays, all sharing the same keys)
 * to a CSV file, header row first. Writes just the header if $rows is
 * empty, so the file's existence and columns still document what was
 * checked even when there's nothing to export.
 */
function writeCsv(string $path, array $rows, array $columns): void
{
    $fh = fopen($path, 'w');
    if ($fh === false) {
        throw new RuntimeException("Could not open {$path} for writing.");
    }
    fputcsv($fh, $columns);
    foreach ($rows as $row) {
        fputcsv($fh, array_map(fn ($col) => $row[$col] ?? '', $columns));
    }
    fclose($fh);
}

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

$requiredItemIds = $pdo->query('
    SELECT item_id, item_text FROM questionnaire_item
    WHERE item_id NOT IN (' . implode(',', OPTIONAL_QUESTIONNAIRE_ITEM_IDS) . ')
    ORDER BY item_id
')->fetchAll();

echo "PT3 - Check logs (export + completeness)\n";
echo str_repeat('-', 50) . "\n";

foreach ($sessions as $row) {
    $code      = $row['participant_code'];
    $sessionId = (int)$row['session_id'];

    // -------------------------------------------------------------
    // Hard-required completeness checks
    // -------------------------------------------------------------
    $countByType = $pdo->prepare("
        SELECT event_type, COUNT(*) AS c FROM event_log WHERE session_id = :id GROUP BY event_type
    ");
    $countByType->execute([':id' => $sessionId]);
    $counts = array_column($countByType->fetchAll(), 'c', 'event_type');
    $counts = array_map('intval', $counts);

    $get = fn (string $type): int => $counts[$type] ?? 0;

    if ($get('session_start') !== 1) {
        $errors[] = "{$code} (session {$sessionId}): expected exactly 1 'session_start' event, found " . $get('session_start') . '.';
    }
    if ($get('session_end') !== 1) {
        $errors[] = "{$code} (session {$sessionId}): expected exactly 1 'session_end' event, found " . $get('session_end') . '.';
    }
    if ($get('error_trigger') !== 0) {
        $errors[] = "{$code} (session {$sessionId}): found " . $get('error_trigger') . " 'error_trigger' event(s) - "
            . 'this only logs on a genuine failure (fallback.php), so its presence is a red flag.';
    }
    if ($get('timeout_redirect') !== 2) {
        $errors[] = "{$code} (session {$sessionId}): expected exactly 2 'timeout_redirect' events (static + "
            . 'adaptive), found ' . $get('timeout_redirect') . '.';
    }

    $stmt = $pdo->prepare('SELECT version_name, COUNT(*) AS c FROM timeout WHERE session_id = :id GROUP BY version_name');
    $stmt->execute([':id' => $sessionId]);
    $timeoutCounts = array_map('intval', array_column($stmt->fetchAll(), 'c', 'version_name'));
    foreach (['static', 'adaptive'] as $version) {
        if (($timeoutCounts[$version] ?? 0) !== 1) {
            $errors[] = "{$code} (session {$sessionId}): expected exactly 1 '{$version}' row in `timeout`, found "
                . ($timeoutCounts[$version] ?? 0) . '.';
        }
    }

    $stmt = $pdo->prepare('SELECT item_id FROM questionnaire_response WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    $answeredItemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($requiredItemIds as $item) {
        if (!in_array((int)$item['item_id'], $answeredItemIds, true)) {
            $errors[] = "{$code} (session {$sessionId}): missing questionnaire_response for required item_id "
                . "{$item['item_id']} ('{$item['item_text']}').";
        }
    }

    // -------------------------------------------------------------
    // Evidence-only counts (not hard-failed on - see header comment)
    // -------------------------------------------------------------
    echo "{$code} (session {$sessionId}):\n";
    foreach ([
        'click', 'navigation_depth', 'dwell_start', 'dwell_end',
        'revisit', 'adaptive_trigger', 'panel_impression', 'panel_click',
    ] as $type) {
        echo "  {$type}: " . $get($type) . "\n";
    }
    echo '  questionnaire_response: ' . count($answeredItemIds) . ' of ' . count($requiredItemIds) . " required answered\n";

    // -------------------------------------------------------------
    // CSV export - always written, regardless of pass/fail above
    // -------------------------------------------------------------
    $stmt = $pdo->prepare('
        SELECT event_id, session_id, artefact_id, suggestion_id, rule_id, event_type, `timestamp`, dwell_duration, navigation_depth
        FROM event_log WHERE session_id = :id ORDER BY `timestamp`, event_id
    ');
    $stmt->execute([':id' => $sessionId]);
    writeCsv(
        "{$exportDir}/{$code}_event_log.csv",
        $stmt->fetchAll(),
        ['event_id', 'session_id', 'artefact_id', 'suggestion_id', 'rule_id', 'event_type', 'timestamp', 'dwell_duration', 'navigation_depth']
    );

    $stmt = $pdo->prepare('
        SELECT timeout_id, session_id, version_name, timeout_timestamp, auto_redirect_flag, timeout_notes
        FROM timeout WHERE session_id = :id ORDER BY timeout_timestamp
    ');
    $stmt->execute([':id' => $sessionId]);
    writeCsv(
        "{$exportDir}/{$code}_timeout.csv",
        $stmt->fetchAll(),
        ['timeout_id', 'session_id', 'version_name', 'timeout_timestamp', 'auto_redirect_flag', 'timeout_notes']
    );

    $stmt = $pdo->prepare('
        SELECT qr.response_id, qr.session_id, qr.item_id, qi.item_text, qr.response_value, qr.response_timestamp
        FROM questionnaire_response qr
        JOIN questionnaire_item qi ON qi.item_id = qr.item_id
        WHERE qr.session_id = :id ORDER BY qi.item_order
    ');
    $stmt->execute([':id' => $sessionId]);
    writeCsv(
        "{$exportDir}/{$code}_questionnaire_response.csv",
        $stmt->fetchAll(),
        ['response_id', 'session_id', 'item_id', 'item_text', 'response_value', 'response_timestamp']
    );

    echo "  Exported: pt3_exports/{$code}_event_log.csv, {$code}_timeout.csv, {$code}_questionnaire_response.csv\n\n";
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Every pilot session has exactly the events a complete run guarantees (session_start/session_end, ";
    echo "both timeouts, both timeout_redirects, no error_trigger, every required questionnaire item answered), ";
    echo "and full CSV exports have been written to tests/phase10/pt3_exports/.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
