<?php
// =====================================================================
// Digital Museum Research Project
// exports/full_study/export_full_study.php
//
// Exports the full, real-participant dataset out of the live
// `digitalmuseum` database as a set of CSV files, for use in Chapter 4
// data preparation and Chapter 5 analysis and Appendix G.
//
// Scope: every participant whose participant_code matches ppt<N> where
// N is 1-30 (case-insensitive). Pilot sessions (PILOT1/PILOT2) and every
// throwaway test code from the Phase 1-10 test scripts are excluded.
//
// This script does NOT exclude incomplete/irregular sessions - every
// session belonging to an in-scope participant is exported as-is. A
// session_completeness_summary.csv is written alongside the raw tables
// flagging session_start/session_end/error_trigger counts and whether
// every required questionnaire item was answered, so completeness
// filtering (if wanted) can be applied deliberately during analysis
// rather than silently here.
//
// Run from the command line:
//     php export_full_study.php
// or visit it in the browser - both work, it only uses PDO + CSV output.
// =====================================================================

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require __DIR__ . '/../../config/db.php'; // provides $pdo

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------
const PARTICIPANT_MIN = 1;
const PARTICIPANT_MAX = 30;

// Optional questionnaire items - kept in sync with questionnaire.php's
// $optionalItemIds. If that array changes there, update this too.
const OPTIONAL_QUESTIONNAIRE_ITEM_IDS = [6, 11, 13, 14];

$csvDir = __DIR__ . '/csv/';
if (!is_dir($csvDir)) {
    mkdir($csvDir, 0755, true);
}

$log = [];
function logLine(string $line): void {
    global $log;
    echo $line . "\n";
    $log[] = $line;
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function placeholders(array $items): string {
    return implode(',', array_fill(0, count($items), '?'));
}

/**
 * Runs a query and writes the full result set to a CSV file.
 * Returns the number of data rows written.
 */
function exportToCsv(PDO $pdo, string $sql, array $params, string $filepath): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $fh = fopen($filepath, 'w');
    $rowCount = 0;
    $headerWritten = false;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$headerWritten) {
            fputcsv($fh, array_keys($row));
            $headerWritten = true;
        }
        fputcsv($fh, $row);
        $rowCount++;
    }

    // Still write a header-only file if the query returned zero rows,
    // so every expected CSV exists even when a table has nothing to
    // export for this scope.
    if (!$headerWritten) {
        $colCount = $stmt->columnCount();
        $headers = [];
        for ($i = 0; $i < $colCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $headers[] = $meta['name'] ?? "col_$i";
        }
        fputcsv($fh, $headers);
    }

    fclose($fh);
    return $rowCount;
}

// ---------------------------------------------------------------------
// Step 1: work out which participants are in scope (ppt1-ppt30)
// ---------------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT participant_id, participant_code FROM participant
     WHERE LOWER(participant_code) REGEXP '^ppt[0-9]+$'"
);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$inScope = [];       // participant_id => participant_code
$foundNumbers = [];  // ppt number => participant_code
foreach ($candidates as $row) {
    if (preg_match('/^ppt(\d+)$/i', $row['participant_code'], $m)) {
        $num = (int) $m[1];
        if ($num >= PARTICIPANT_MIN && $num <= PARTICIPANT_MAX) {
            $inScope[$row['participant_id']] = $row['participant_code'];
            $foundNumbers[$num] = $row['participant_code'];
        }
    }
}

$expectedCount = PARTICIPANT_MAX - PARTICIPANT_MIN + 1;
logLine("Participants matched: " . count($inScope) . " / expected $expectedCount");

$missing = [];
for ($n = PARTICIPANT_MIN; $n <= PARTICIPANT_MAX; $n++) {
    if (!isset($foundNumbers[$n])) {
        $missing[] = "ppt$n";
    }
}
if (!empty($missing)) {
    logLine("WARNING - missing participant codes: " . implode(', ', $missing));
} else {
    logLine("All expected ppt1-ppt$expectedCount codes were found.");
}

if (empty($inScope)) {
    logLine("No matching participants found - stopping.");
    exit(1);
}

$participantIds = array_keys($inScope);

// ---------------------------------------------------------------------
// Step 2: work out which sessions belong to those participants
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT session_id FROM session_log WHERE participant_id IN (" . placeholders($participantIds) . ")"
);
$stmt->execute($participantIds);
$sessionIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'session_id');

logLine("Sessions in scope: " . count($sessionIds));

if (empty($sessionIds)) {
    logLine("No sessions found for the in-scope participants - stopping.");
    exit(1);
}

$sessionPlaceholders = placeholders($sessionIds);

// ---------------------------------------------------------------------
// Step 3: export the behavioural tables, filtered to those sessions
// ---------------------------------------------------------------------
$rowCounts = [];

$rowCounts['session_log.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM session_log WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id",
    $sessionIds,
    $csvDir . 'session_log.csv'
);

$rowCounts['event_log.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM event_log WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id, `timestamp`",
    $sessionIds,
    $csvDir . 'event_log.csv'
);

$rowCounts['timeout.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM timeout WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id",
    $sessionIds,
    $csvDir . 'timeout.csv'
);

$rowCounts['adaptive_event.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM adaptive_event WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id, trigger_timestamp",
    $sessionIds,
    $csvDir . 'adaptive_event.csv'
);

$rowCounts['adaptive_suggestion.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM adaptive_suggestion WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id, suggestion_timestamp",
    $sessionIds,
    $csvDir . 'adaptive_suggestion.csv'
);

// ---------------------------------------------------------------------
// Step 4: export the questionnaire dataset, filtered to those sessions
// ---------------------------------------------------------------------
$rowCounts['questionnaire_response.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM questionnaire_response WHERE session_id IN ($sessionPlaceholders) ORDER BY session_id, item_id",
    $sessionIds,
    $csvDir . 'questionnaire_response.csv'
);

$rowCounts['questionnaire_item.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM questionnaire_item ORDER BY item_order",
    [],
    $csvDir . 'questionnaire_item.csv'
);

// ---------------------------------------------------------------------
// Step 5: export lookup/reference tables (needed to decode IDs later)
// ---------------------------------------------------------------------
$rowCounts['participant.csv'] = exportToCsv(
    $pdo,
    "SELECT * FROM participant WHERE participant_id IN (" . placeholders($participantIds) . ") ORDER BY participant_id",
    $participantIds,
    $csvDir . 'participant.csv'
);

$rowCounts['study_condition.csv'] = exportToCsv($pdo, "SELECT * FROM study_condition", [], $csvDir . 'study_condition.csv');
$rowCounts['adaptive_rule.csv']   = exportToCsv($pdo, "SELECT * FROM adaptive_rule", [], $csvDir . 'adaptive_rule.csv');
$rowCounts['artefact.csv']        = exportToCsv($pdo, "SELECT * FROM artefact ORDER BY artefact_id", [], $csvDir . 'artefact.csv');
$rowCounts['theme.csv']           = exportToCsv($pdo, "SELECT * FROM theme", [], $csvDir . 'theme.csv');
$rowCounts['subtheme.csv']        = exportToCsv($pdo, "SELECT * FROM subtheme", [], $csvDir . 'subtheme.csv');

foreach ($rowCounts as $file => $count) {
    logLine("Wrote $file - $count row(s)");
}

// ---------------------------------------------------------------------
// Step 6: session completeness diagnostics (informational only - does
// NOT exclude anything from the exports above). Flags, per session,
// whether it looks like a full, uninterrupted run.
// ---------------------------------------------------------------------
$eventStmt = $pdo->prepare(
    "SELECT session_id, event_type, COUNT(*) AS cnt
     FROM event_log
     WHERE session_id IN ($sessionPlaceholders)
       AND event_type IN ('session_start','session_end','error_trigger')
     GROUP BY session_id, event_type"
);
$eventStmt->execute($sessionIds);
$eventCounts = [];
foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $eventCounts[$r['session_id']][$r['event_type']] = (int) $r['cnt'];
}

$requiredItemPlaceholders = placeholders(OPTIONAL_QUESTIONNAIRE_ITEM_IDS);

$requiredTotalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM questionnaire_item WHERE item_id NOT IN ($requiredItemPlaceholders)"
);
$requiredTotalStmt->execute(OPTIONAL_QUESTIONNAIRE_ITEM_IDS);
$requiredTotal = (int) $requiredTotalStmt->fetchColumn();

$answeredStmt = $pdo->prepare(
    "SELECT session_id, COUNT(DISTINCT item_id) AS answered
     FROM questionnaire_response
     WHERE session_id IN ($sessionPlaceholders)
       AND item_id NOT IN ($requiredItemPlaceholders)
     GROUP BY session_id"
);
$answeredStmt->execute(array_merge($sessionIds, OPTIONAL_QUESTIONNAIRE_ITEM_IDS));
$answeredCounts = [];
foreach ($answeredStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $answeredCounts[$r['session_id']] = (int) $r['answered'];
}

$sessionDetailStmt = $pdo->prepare(
    "SELECT sl.session_id, p.participant_code, sc.condition_name,
            sl.session_start, sl.session_end,
            sl.timeout_triggered_static, sl.timeout_triggered_adaptive, sl.error_flag
     FROM session_log sl
     JOIN participant p ON p.participant_id = sl.participant_id
     JOIN study_condition sc ON sc.condition_id = sl.condition_id
     WHERE sl.session_id IN ($sessionPlaceholders)
     ORDER BY sl.session_id"
);
$sessionDetailStmt->execute($sessionIds);
$sessionDetails = $sessionDetailStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryPath = $csvDir . 'session_completeness_summary.csv';
$fh = fopen($summaryPath, 'w');
fputcsv($fh, [
    'session_id', 'participant_code', 'condition_name',
    'session_start', 'session_end',
    'session_start_events', 'session_end_events', 'error_trigger_events',
    'required_items_answered', 'required_items_total',
    'timeout_triggered_static', 'timeout_triggered_adaptive', 'error_flag',
    'looks_complete',
]);

$incompleteCount = 0;
foreach ($sessionDetails as $s) {
    $sid = $s['session_id'];
    $startEvents = $eventCounts[$sid]['session_start'] ?? 0;
    $endEvents   = $eventCounts[$sid]['session_end'] ?? 0;
    $errorEvents = $eventCounts[$sid]['error_trigger'] ?? 0;
    $answered    = $answeredCounts[$sid] ?? 0;

    $looksComplete = ($startEvents === 1 && $endEvents === 1 && $errorEvents === 0 && $answered === $requiredTotal);
    if (!$looksComplete) {
        $incompleteCount++;
    }

    fputcsv($fh, [
        $sid, $s['participant_code'], $s['condition_name'],
        $s['session_start'], $s['session_end'],
        $startEvents, $endEvents, $errorEvents,
        $answered, $requiredTotal,
        $s['timeout_triggered_static'], $s['timeout_triggered_adaptive'], $s['error_flag'],
        $looksComplete ? 'yes' : 'no',
    ]);
}
fclose($fh);

logLine("Wrote session_completeness_summary.csv - " . count($sessionDetails) . " row(s), $incompleteCount flagged as not fully complete");

// ---------------------------------------------------------------------
// Step 7: manifest - what ran, when, against what
// ---------------------------------------------------------------------
$manifestPath = __DIR__ . '/export_manifest.txt';
$manifestLines = array_merge(
    ["Digital Museum full-study export", "Generated: " . date('Y-m-d H:i:s')],
    [""],
    $log
);
file_put_contents($manifestPath, implode("\n", $manifestLines) . "\n");

logLine("");
logLine("Manifest written to export_manifest.txt");
logLine("Done.");
