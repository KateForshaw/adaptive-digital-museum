<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// api/get_questionnaire_responses.php
//
// Endpoint: GET /questionnaire/responses?session_id=   (API Design.docx
//           > Questionnaire API)
// Tables:   questionnaire_response, questionnaire_item (Database
//           Design.docx)
//
// Not called by questionnaire.php itself - that page only writes
// responses (via post_questionnaire_response.php), it never needs to
// read them back. This endpoint is for later phases instead: Phase 8's
// full-simulation data-integrity check ("Are all events present?") and
// Phase 9 pilot review both need a way to pull one participant's
// answers back out, and a researcher spot-checking a session in the
// browser is easier than writing a SQL join by hand each time.
//
// Joins in item_text/item_type/item_order from questionnaire_item so
// the response is human-readable on its own, rather than just a list
// of item_id/value pairs.
// =====================================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// 1. Method check
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET requests are accepted.']);
    exit;
}

// ---------------------------------------------------------------------
// 2. Parse + validate session_id
// ---------------------------------------------------------------------
$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT);

if ($sessionId === false || $sessionId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id is required and must be an integer.']);
    exit;
}

// ---------------------------------------------------------------------
// 3. Fetch the session's responses, in questionnaire order
// ---------------------------------------------------------------------
try {
    // session_id integrity - confirm the session exists before
    // reporting an (accurate but potentially misleading) empty list
    $stmt = $pdo->prepare('SELECT session_id, session_end FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();

    if ($session === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => "session_id {$sessionId} does not exist."]);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT
            qr.item_id,
            qi.item_text,
            qi.item_type,
            qi.item_order,
            qr.response_value,
            qr.response_timestamp
        FROM questionnaire_response qr
        JOIN questionnaire_item qi ON qi.item_id = qr.item_id
        WHERE qr.session_id = :session_id
        ORDER BY qi.item_order
    ');
    $stmt->execute([':session_id' => $sessionId]);
    $responses = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success'      => true,
        'session_id'   => $sessionId,
        'session_end'  => $session['session_end'],
        'count'        => count($responses),
        'responses'    => $responses,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
