<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// api/session_end.php
//
// Endpoint: POST /session/end   (API Design.docx > Session Management API)
// Table:    session_log          (Database Design.docx)
//
// Called once, when a participant completes the questionnaire (or
// otherwise exits the system - Study Protocol Design.docx > "End of
// Session"). Marks the existing session_log row as complete and
// records a matching 'session_end' event in event_log so the
// session-end moment sits in the same behavioural timeline as every
// other event.
// =====================================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

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

$sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);

if ($sessionId === false || $sessionId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id is required and must be an integer.']);
    exit;
}

// ---------------------------------------------------------------------
// 3. Close the session (session_id integrity, update session_log,
//    insert matching event_log row)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // session_id integrity - the session must already exist
    $stmt = $pdo->prepare('SELECT session_id, session_end FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();

    if ($session === false) {
        throw new RuntimeException("session_id {$sessionId} does not exist.");
    }

    if ($session['session_end'] !== null) {
        // Already closed - not an error, just report the existing value
        // so repeated calls (e.g. a retried fetch) stay idempotent.
        $pdo->commit();
        http_response_code(200);
        echo json_encode([
            'success'     => true,
            'session_id'  => $sessionId,
            'session_end' => $session['session_end'],
            'note'        => 'Session was already closed.',
        ]);
        exit;
    }

    $sessionEnd = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('UPDATE session_log SET session_end = :session_end WHERE session_id = :id');
    $stmt->execute([
        ':session_end' => $sessionEnd,
        ':id'          => $sessionId,
    ]);

    // Log the matching session_end event (event_log.artefact_id is
    // nullable for session-level events - see create_tables.sql note)
    $stmt = $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, NULL, 'session_end', :timestamp)
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':timestamp'  => $sessionEnd,
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success'     => true,
        'session_id'  => $sessionId,
        'session_end' => $sessionEnd,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
