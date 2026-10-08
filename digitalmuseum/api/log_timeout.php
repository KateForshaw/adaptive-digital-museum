<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// api/log_timeout.php
//
// Endpoint: POST /timeout   (API Design.docx > Behaviour Logging API)
// Table:    timeout          (Database Design.docx)
//
// Called when a 7-minute browsing session (static or adaptive) times
// out and the system auto-redirects to the correct transition page
// (Study Protocol Design.docx > "Timing Structure"). Inserts the
// timeout record, flips the matching timeout_triggered_* flag on
// session_log, and records a 'timeout_redirect' event in event_log so
// the timeout sits in the same behavioural timeline as every other
// event.
// =====================================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

const TIMEOUT_VERSIONS = ['static', 'adaptive'];

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

$sessionId         = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
$versionName       = trim($input['version_name'] ?? '');
$autoRedirectInput = $input['auto_redirect_flag'] ?? true; // assume success unless told otherwise
$timeoutNotes      = isset($input['timeout_notes']) ? trim($input['timeout_notes']) : null;

$errors = [];

if ($sessionId === false || $sessionId === null) {
    $errors[] = 'session_id is required and must be an integer.';
}
if (!in_array($versionName, TIMEOUT_VERSIONS, true)) {
    $errors[] = 'version_name is required and must be one of: ' . implode(', ', TIMEOUT_VERSIONS) . '.';
}

$autoRedirectFlag = filter_var($autoRedirectInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($autoRedirectFlag === null) {
    $errors[] = 'auto_redirect_flag must be a boolean.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 3. Log the timeout (session_id integrity, insert timeout row, flip
//    the matching session_log flag, insert matching event_log row)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // session_id integrity - the session must already exist
    $stmt = $pdo->prepare('SELECT session_id FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("session_id {$sessionId} does not exist.");
    }

    // Timestamp is always generated server-side, never trusted from the client
    $timeoutTimestamp = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO timeout
            (session_id, version_name, timeout_timestamp, auto_redirect_flag, timeout_notes)
        VALUES
            (:session_id, :version_name, :timeout_timestamp, :auto_redirect_flag, :timeout_notes)
    ");
    $stmt->execute([
        ':session_id'         => $sessionId,
        ':version_name'       => $versionName,
        ':timeout_timestamp'  => $timeoutTimestamp,
        ':auto_redirect_flag' => $autoRedirectFlag,
        ':timeout_notes'      => $timeoutNotes,
    ]);
    $timeoutId = (int)$pdo->lastInsertId();

    // Flip the matching timeout_triggered_* flag on session_log
    $flagColumn = $versionName === 'static' ? 'timeout_triggered_static' : 'timeout_triggered_adaptive';
    $pdo->prepare("UPDATE session_log SET {$flagColumn} = TRUE WHERE session_id = :id")
        ->execute([':id' => $sessionId]);

    // Log the matching timeout_redirect event (event_log.artefact_id is
    // nullable for session-level events - see create_tables.sql note)
    $stmt = $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, NULL, 'timeout_redirect', :timestamp)
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':timestamp'  => $timeoutTimestamp,
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success'             => true,
        'timeout_id'          => $timeoutId,
        'session_id'          => $sessionId,
        'version_name'        => $versionName,
        'timeout_timestamp'   => $timeoutTimestamp,
        'auto_redirect_flag'  => $autoRedirectFlag,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
