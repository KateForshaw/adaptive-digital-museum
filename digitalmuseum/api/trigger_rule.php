<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// api/trigger_rule.php
//
// Endpoint: POST /adaptive/trigger   (API Design.docx > Adaptive System
// API > "Used by adaptive engine to fire a rule.")
//
// The one endpoint js/heartbeat.js and js/adaptive_engine.js call to
// re-evaluate the adaptive engine and, if warranted, refresh the panel.
// Wraps the already-built Phase 4 engine (includes/adaptive_rules.php,
// adaptive_scoring.php, adaptive_suggestions.php) with the two things
// Phase 4 explicitly left for "the Phase 5 caller that runs continuously
// through a session" (adaptive_suggestions.php's file header): deciding
// WHEN signals are current/available, and turning a chosen suggestion
// set into persisted rows + a logged trigger.
//
// Persists: 4 rows in adaptive_suggestion (one per suggestion), 1
// event_log + adaptive_event row (event_type = adaptive_trigger - the
// engine firing itself, not tied to one artefact/suggestion). The panel
// impressions and clicks for the 4 returned suggestions are logged
// separately by the client (js/adaptive_engine.js, adaptive.php) via the
// already-built api/log_adaptive_event.php, once they're actually
// rendered/clicked - not assumed here.
// =====================================================================

// Needed to read $_SESSION['museum_adaptive_task_start'] (set by
// adaptive.php on load) below - same PHP session the browser's fetch()
// calls already carry via cookie, since this endpoint is only ever
// called from a page that's already gone through session_start() itself.
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/adaptive_rules.php';
require_once __DIR__ . '/../includes/adaptive_scoring.php';
require_once __DIR__ . '/../includes/adaptive_suggestions.php';

header('Content-Type: application/json');

const FREEZE_WINDOW_SECONDS  = 30;  // Adaptive Logic.docx > "Timeout Protection"
const SESSION_DURATION_SECONDS = 420; // 7 minutes - js/timeout.js's SESSION_DURATION_SECONDS

// Fixed artefact_popularity buckets (see design note 5 above)
const POPULARITY_LOW_MAX    = 2;
const POPULARITY_MEDIUM_MAX = 6;

// Adaptive Logic.docx > "Serendipity Frequency Control": "Maximum of 1
// serendipity update every 20 seconds" + "Minimum of 10 seconds between
// serendipity triggers" - 20s is the binding constraint (it already
// satisfies the weaker 10s minimum), so one gap enforces both.
//
// Added during Phase 10 pilot testing (PT2 - tests/phase10/test_check_
// adaptive_behaviour.php): this guard didn't exist anywhere before -
// adaptive_suggestions.php's own header comment flagged it as deferred
// to "the Phase 5 caller that runs continuously through a session", but
// nothing ever built it. Real pilot data (PILOT1, session 47) showed why
// it's needed: theme_switches/subtheme_switches (deriveSessionSignals()
// below) are cumulative for the whole session and only ever increase, so
// once they cross a compound serendipity rule's threshold, that same
// rule re-fires on every single subsequent modal close - PILOT1 hit it
// 10 times inside one second.
const SERENDIPITY_MIN_GAP_SECONDS = 20;

// ---------------------------------------------------------------------
// 1. Method check
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST requests are accepted.']);
    exit;
}

// ---------------------------------------------------------------------
// 2. Parse + validate input
// ---------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Request body must be valid JSON.']);
    exit;
}

$sessionId  = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
$artefactId = array_key_exists('artefact_id', $input) && $input['artefact_id'] !== null
    ? filter_var($input['artefact_id'], FILTER_VALIDATE_INT)
    : null;

$errors = [];
if ($sessionId === false || $sessionId === null) {
    $errors[] = 'session_id is required and must be an integer.';
}
if (array_key_exists('artefact_id', $input) && $input['artefact_id'] !== null && $artefactId === false) {
    $errors[] = 'artefact_id must be an integer when provided.';
}
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

try {
    // -------------------------------------------------------------------
    // 3. Session integrity + current-task time window (design note 3)
    // -------------------------------------------------------------------
    $stmt = $pdo->prepare(
        'SELECT session_start, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
    );
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "session_id {$sessionId} does not exist."]);
        exit;
    }
    if ((bool)$session['timeout_triggered_adaptive']) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'The adaptive task for this session has already ended.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT MAX(timeout_timestamp) FROM timeout WHERE session_id = :id AND version_name = 'static'"
    );
    $stmt->execute([':id' => $sessionId]);
    $staticTimeoutAt = $stmt->fetchColumn();

    // When adaptive runs second, $staticTimeoutAt (static's own timeout
    // moment) is the correct anchor - unchanged. When adaptive runs FIRST,
    // fall back to $_SESSION['museum_adaptive_task_start'] (set by
    // adaptive.php the moment this task's page actually loaded) rather
    // than session_log.session_start, which is set back on index.php and
    // can sit well before this task begins. session_start is kept only as
    // a last-resort default (e.g. tests/phase5/test_panel_freeze.php calls
    // this endpoint directly over HTTP with no browser session cookie, so
    // it intentionally exercises this final fallback).
    $taskStart = $staticTimeoutAt
        ?: ($_SESSION['museum_adaptive_task_start'] ?? $session['session_start']);

    // -------------------------------------------------------------------
    // 4. Freeze window (design note above; Adaptive Logic.docx > "Timeout
    //    Protection" + AP5 "Test freeze-before-timeout")
    // -------------------------------------------------------------------
    $elapsedSeconds = time() - strtotime($taskStart);

    if ($elapsedSeconds >= (SESSION_DURATION_SECONDS - FREEZE_WINDOW_SECONDS)) {
        echo json_encode(['success' => true, 'updated' => false, 'reason' => 'frozen']);
        exit;
    }

    // -------------------------------------------------------------------
    // 5. Derive current signals from event_log (design notes 1-3)
    // -------------------------------------------------------------------
    $signals = deriveSessionSignals($pdo, $sessionId, $taskStart);

    if ($artefactId !== null) {
        $signals = array_merge($signals, deriveArtefactSignals($pdo, $sessionId, $taskStart, $artefactId));
    }

    // -------------------------------------------------------------------
    // 6. Run the Phase 4 engine
    // -------------------------------------------------------------------
    $rules       = loadActiveRules($pdo);
    $firedRules  = evaluateAllRules($rules, $signals);
    $scoreResult = scoreSignals($firedRules);
    $mode        = selectSuggestionMode($firedRules, $scoreResult);

    // -------------------------------------------------------------------
    // 7. Update decision (design note 4)
    // -------------------------------------------------------------------
    if (empty($firedRules) || $scoreResult['interest_category'] === 'weak') {
        echo json_encode([
            'success'        => true,
            'updated'        => false,
            'reason'         => 'no_qualifying_signal',
            'interest_score' => $scoreResult['interest_score'],
        ]);
        exit;
    }

    // Serendipity Frequency Control (SERENDIPITY_MIN_GAP_SECONDS above).
    // Checked after the weak-score short-circuit, so a genuinely weak
    // signal still reports 'no_qualifying_signal' rather than being
    // masked by this reason - only an otherwise-qualifying serendipity
    // update gets held back.
    if ($mode === 'serendipity') {
        $lastSerendipityAt = getLastSerendipityTriggerTimestamp($pdo, $sessionId);
        if ($lastSerendipityAt !== null
            && (time() - strtotime($lastSerendipityAt)) < SERENDIPITY_MIN_GAP_SECONDS
        ) {
            echo json_encode([
                'success'        => true,
                'updated'        => false,
                'reason'         => 'serendipity_rate_limited',
                'interest_score' => $scoreResult['interest_score'],
            ]);
            exit;
        }
    }

    $topRule = resolveHighestPriorityRule($firedRules);
    $ruleId  = $topRule['rule_id'];

    $suggestions = generateSuggestions($pdo, $sessionId, $firedRules, $scoreResult);

    // -------------------------------------------------------------------
    // 8. Persist suggestions + log the adaptive_trigger event (one
    //    transaction - either the whole update lands, or none of it does)
    // -------------------------------------------------------------------
    $pdo->beginTransaction();

    $timestamp = date('Y-m-d H:i:s');
    $panelRows = [];

    foreach (array_values($suggestions) as $panelPosition => $suggestion) {
        $stmt = $pdo->prepare("
            INSERT INTO adaptive_suggestion
                (rule_id, session_id, artefact_id, interest_score, suggestion_timestamp, panel_position, clicked_flag)
            VALUES
                (:rule_id, :session_id, :artefact_id, :interest_score, :suggestion_timestamp, :panel_position, FALSE)
        ");
        $stmt->execute([
            ':rule_id'              => $ruleId,
            ':session_id'           => $sessionId,
            ':artefact_id'          => $suggestion['artefact_id'],
            ':interest_score'       => $scoreResult['interest_score'],
            ':suggestion_timestamp' => $timestamp,
            ':panel_position'       => $panelPosition,
        ]);
        $suggestionId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT a.artefact_title, a.image_url, t.theme_name, s.subtheme_name
            FROM artefact a
            JOIN theme t ON a.theme_id = t.theme_id
            JOIN subtheme s ON a.subtheme_id = s.subtheme_id
            WHERE a.artefact_id = :artefact_id
        ");
        $stmt->execute([':artefact_id' => $suggestion['artefact_id']]);
        $meta = $stmt->fetch();

        $panelRows[] = [
            'suggestion_id'  => $suggestionId,
            'artefact_id'    => $suggestion['artefact_id'],
            'artefact_title' => $meta['artefact_title'] ?? null,
            'image_url'      => $meta['image_url'] ?? null,
            'theme_name'     => $meta['theme_name'] ?? null,
            'subtheme_name'  => $meta['subtheme_name'] ?? null,
            'slot_type'      => $suggestion['slot_type'],
            'panel_position' => $panelPosition,
        ];
    }

    // adaptive_trigger - the engine firing itself (session-level, not
    // tied to one artefact/suggestion - Behaviour Logging Map.docx >
    // "Adaptive Trigger Fired")
    $stmt = $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, rule_id, event_type, `timestamp`)
        VALUES (:session_id, :artefact_id, :rule_id, 'adaptive_trigger', :timestamp)
    ");
    $stmt->execute([
        ':session_id'  => $sessionId,
        ':artefact_id' => $artefactId, // null for heartbeat-driven triggers
        ':rule_id'     => $ruleId,
        ':timestamp'   => $timestamp,
    ]);
    $eventId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO adaptive_event
            (event_id, session_id, rule_id, suggestion_id, trigger_timestamp, trigger_confidence)
        VALUES
            (:event_id, :session_id, :rule_id, NULL, :trigger_timestamp, :trigger_confidence)
    ");
    $stmt->execute([
        ':event_id'           => $eventId,
        ':session_id'         => $sessionId,
        ':rule_id'            => $ruleId,
        ':trigger_timestamp'  => $timestamp,
        ':trigger_confidence' => $scoreResult['interest_score'],
    ]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'updated'        => true,
        'mode'           => $mode,
        'rule_id'        => $ruleId,
        'rule_name'      => $topRule['rule_name'],
        'interest_score' => $scoreResult['interest_score'],
        'suggestions'    => $panelRows,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

// =========================================================================
// Serendipity Frequency Control helper (SERENDIPITY_MIN_GAP_SECONDS above)
// =========================================================================

/**
 * Finds this session's most recent trigger that resolved to serendipity
 * mode, scanning past adaptive_event rows newest-first and returning the
 * first match's trigger_timestamp (or null if none). "Was serendipity"
 * is judged the same way selectSuggestionMode() (adaptive_suggestions.php)
 * decides it live: either the winning rule's name starts with
 * 'serendipity_', or its trigger_confidence (== the interest_score at
 * the time) was past ADAPTIVE_SERENDIPITY_THRESHOLD - adaptive_event
 * doesn't persist the resolved mode itself, so this is the only way to
 * reconstruct it after the fact.
 */
function getLastSerendipityTriggerTimestamp(PDO $pdo, int $sessionId): ?string
{
    $stmt = $pdo->prepare("
        SELECT ae.trigger_timestamp, ae.trigger_confidence, r.rule_name
        FROM adaptive_event ae
        JOIN adaptive_rule r ON r.rule_id = ae.rule_id
        WHERE ae.session_id = :session_id
        ORDER BY ae.trigger_timestamp DESC
    ");
    $stmt->execute([':session_id' => $sessionId]);

    foreach ($stmt->fetchAll() as $row) {
        $isSerendipityNamed = strpos((string)$row['rule_name'], 'serendipity_') === 0;
        $wasAboveThreshold  = (float)$row['trigger_confidence'] > ADAPTIVE_SERENDIPITY_THRESHOLD;
        if ($isSerendipityNamed || $wasAboveThreshold) {
            return $row['trigger_timestamp']; // newest-first scan, so first match is the most recent
        }
    }
    return null;
}

// =========================================================================
// Signal derivation helpers (design notes 1-3, 5 above)
// =========================================================================

/**
 * Session-level signals available regardless of which artefact (if any)
 * prompted this call: navigation_depth, theme_switches, subtheme_switches,
 * branching_factor - all derivable purely from this task's 'click' /
 * 'navigation_depth' event_log rows.
 */
function deriveSessionSignals(PDO $pdo, int $sessionId, string $taskStart): array
{
    $stmt = $pdo->prepare("
        SELECT MAX(navigation_depth) FROM event_log
        WHERE session_id = :session_id AND event_type = 'navigation_depth' AND `timestamp` >= :task_start
    ");
    $stmt->execute([':session_id' => $sessionId, ':task_start' => $taskStart]);
    $navigationDepth = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT a.theme_id, a.subtheme_id
        FROM event_log e
        JOIN artefact a ON a.artefact_id = e.artefact_id
        WHERE e.session_id = :session_id AND e.event_type = 'click' AND e.`timestamp` >= :task_start
        ORDER BY e.`timestamp` ASC
    ");
    $stmt->execute([':session_id' => $sessionId, ':task_start' => $taskStart]);
    $clicks = $stmt->fetchAll();

    $themeSwitches    = 0;
    $subthemeSwitches = 0;
    $subthemesSeen    = [];
    $previousTheme    = null;
    $previousSubtheme = null;

    foreach ($clicks as $click) {
        $themeId    = (int)$click['theme_id'];
        $subthemeId = (int)$click['subtheme_id'];

        if ($previousTheme !== null && $themeId !== $previousTheme) {
            $themeSwitches++;
        }
        if ($previousSubtheme !== null && $subthemeId !== $previousSubtheme) {
            $subthemeSwitches++;
        }
        $subthemesSeen[$subthemeId] = true;

        $previousTheme    = $themeId;
        $previousSubtheme = $subthemeId;
    }

    return [
        'navigation_depth'  => $navigationDepth !== null ? (int)$navigationDepth : null,
        'theme_switches'    => $themeSwitches,
        'subtheme_switches' => $subthemeSwitches,
        'branching_factor'  => count($subthemesSeen),
    ];
}

/**
 * Signals scoped to one specific artefact - only meaningful for
 * event-driven calls where adaptive.php passes the artefact whose modal
 * just closed: dwell_seconds, revisit_count, revisit_interval,
 * artefact_popularity.
 */
function deriveArtefactSignals(PDO $pdo, int $sessionId, string $taskStart, int $artefactId): array
{
    $stmt = $pdo->prepare("
        SELECT dwell_duration FROM event_log
        WHERE session_id = :session_id AND artefact_id = :artefact_id
          AND event_type = 'dwell_end' AND `timestamp` >= :task_start
        ORDER BY `timestamp` DESC LIMIT 1
    ");
    $stmt->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':task_start' => $taskStart]);
    $dwellSeconds = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT `timestamp` FROM event_log
        WHERE session_id = :session_id AND artefact_id = :artefact_id
          AND event_type = 'click' AND `timestamp` >= :task_start
        ORDER BY `timestamp` DESC LIMIT 2
    ");
    $stmt->execute([':session_id' => $sessionId, ':artefact_id' => $artefactId, ':task_start' => $taskStart]);
    $recentClicks = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $revisitCount    = count($recentClicks);
    $revisitInterval = null;
    if (count($recentClicks) === 2) {
        $revisitInterval = strtotime($recentClicks[0]) - strtotime($recentClicks[1]);
    }

    // Global popularity - across all sessions, not just this one
    // (design note 5: fixed buckets, no spec-defined thresholds)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM event_log WHERE artefact_id = :artefact_id AND event_type = 'click'
    ");
    $stmt->execute([':artefact_id' => $artefactId]);
    $globalClicks = (int)$stmt->fetchColumn();

    if ($globalClicks <= POPULARITY_LOW_MAX) {
        $popularity = 'low';
    } elseif ($globalClicks <= POPULARITY_MEDIUM_MAX) {
        $popularity = 'medium';
    } else {
        $popularity = 'high';
    }

    return [
        'dwell_seconds'       => $dwellSeconds !== null ? (float)$dwellSeconds : null,
        'revisit_count'       => $revisitCount > 0 ? $revisitCount : null,
        'revisit_interval'    => $revisitInterval,
        'artefact_popularity' => $popularity,
    ];
}
