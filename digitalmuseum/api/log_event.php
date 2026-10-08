<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// api/log_event.php
//
// Endpoint: POST /event   (API Design.docx > Behaviour Logging API)
// Table:    event_log      (Database Design.docx)
//
// Logs the core, artefact-level behavioural events fired from the
// artefact grid and artefact detail modal on both the static and
// adaptive versions (Behaviour Logging Map.docx):
//   click, dwell_start, dwell_end, revisit, navigation_depth
//
// Adaptive-only events, timeout events, and session start/end each
// have their own dedicated endpoint (log_adaptive_event.php,
// log_timeout.php, session_start.php, session_end.php) and are
// rejected here.
// =====================================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

const CORE_EVENT_TYPES = ['click', 'dwell_start', 'dwell_end', 'revisit', 'navigation_depth'];

// ---------------------------------------------------------------------
// 1. Method check
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST requests are accepted.']);
    exit;
}

// ---------------------------------------------------------------------
// 2. Parse + validate required fields
// ---------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Request body must be valid JSON.']);
    exit;
}

$sessionId       = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
$artefactId      = filter_var($input['artefact_id'] ?? null, FILTER_VALIDATE_INT);
$eventType       = trim($input['event_type'] ?? '');
$dwellDuration   = $input['dwell_duration'] ?? null;
$navigationDepth = $input['navigation_depth'] ?? null;

$errors = [];

if ($sessionId === false || $sessionId === null) {
    $errors[] = 'session_id is required and must be an integer.';
}
if ($artefactId === false || $artefactId === null) {
    $errors[] = 'artefact_id is required and must be an integer.';
}
if (!in_array($eventType, CORE_EVENT_TYPES, true)) {
    $errors[] = 'event_type is required and must be one of: ' . implode(', ', CORE_EVENT_TYPES) . '.';
}

// dwell_duration is required (and only meaningful) for dwell_end events
if ($eventType === 'dwell_end') {
    $dwellDuration = filter_var($dwellDuration, FILTER_VALIDATE_INT);
    if ($dwellDuration === false || $dwellDuration < 0) {
        $errors[] = 'dwell_duration is required for dwell_end events and must be a non-negative integer.';
    }
} else {
    $dwellDuration = null;
}

// navigation_depth is required (and only meaningful) for navigation_depth events
if ($eventType === 'navigation_depth') {
    $navigationDepth = filter_var($navigationDepth, FILTER_VALIDATE_INT);
    if ($navigationDepth === false || $navigationDepth < 1) {
        $errors[] = 'navigation_depth is required for navigation_depth events and must be a positive integer.';
    }
} else {
    $navigationDepth = null;
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 3. Log the event (session_id integrity, artefact_id integrity,
//    server-generated timestamp, insert event_log row)
// ---------------------------------------------------------------------
try {
    // session_id integrity - the session must already exist
    $stmt = $pdo->prepare('SELECT session_id FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("session_id {$sessionId} does not exist.");
    }

    // artefact_id integrity - the artefact must already exist
    $stmt = $pdo->prepare('SELECT artefact_id FROM artefact WHERE artefact_id = :id');
    $stmt->execute([':id' => $artefactId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("artefact_id {$artefactId} does not exist.");
    }

    // Timestamp is always generated server-side, never trusted from the client
    $timestamp = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO event_log
            (session_id, artefact_id, event_type, `timestamp`, dwell_duration, navigation_depth)
        VALUES
            (:session_id, :artefact_id, :event_type, :timestamp, :dwell_duration, :navigation_depth)
    ");
    $stmt->execute([
        ':session_id'       => $sessionId,
        ':artefact_id'      => $artefactId,
        ':event_type'       => $eventType,
        ':timestamp'        => $timestamp,
        ':dwell_duration'   => $dwellDuration,
        ':navigation_depth' => $navigationDepth,
    ]);
    $eventId = (int)$pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'success'          => true,
        'event_id'         => $eventId,
        'session_id'       => $sessionId,
        'artefact_id'      => $artefactId,
        'event_type'       => $eventType,
        'timestamp'        => $timestamp,
        'dwell_duration'   => $dwellDuration,
        'navigation_depth' => $navigationDepth,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
