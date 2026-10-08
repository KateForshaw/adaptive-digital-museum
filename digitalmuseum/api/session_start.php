<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// api/session_start.php
//
// Endpoint: POST /session/start   (API Design.docx > Session Management API)
// Table:    session_log            (Database Design.docx)
//
// Called once, when a participant loads the home screen (index.php).
// The researcher enters the participant code and the counterbalanced
// condition assigned on paper (Study Protocol Design.docx). This
// endpoint finds-or-creates the participant, resolves the condition,
// opens a new session_log row, and records a matching 'session_start'
// event in event_log so the session-start moment sits in the same
// behavioural timeline as every other event.
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

$participantCode = trim($input['participant_code'] ?? '');
$conditionName    = trim($input['condition_name'] ?? '');

$errors = [];
if ($participantCode === '') {
    $errors[] = 'participant_code is required.';
}
if (!in_array($conditionName, ['static_first', 'adaptive_first'], true)) {
    $errors[] = "condition_name is required and must be 'static_first' or 'adaptive_first'.";
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 3. Create session (find-or-create participant, resolve condition,
//    insert session_log, insert matching event_log row)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // Find-or-create participant by participant_code
    $stmt = $pdo->prepare('SELECT participant_id FROM participant WHERE participant_code = :code');
    $stmt->execute([':code' => $participantCode]);
    $participantId = $stmt->fetchColumn();

    if ($participantId === false) {
        $stmt = $pdo->prepare(
            'INSERT INTO participant (participant_code) VALUES (:code)'
        );
        $stmt->execute([':code' => $participantCode]);
        $participantId = (int)$pdo->lastInsertId();
    } else {
        $participantId = (int)$participantId;
    }

    // Resolve condition_id from condition_name (validates FK integrity -
    // rejects the request if study_condition has not been seeded)
    $stmt = $pdo->prepare('SELECT condition_id FROM study_condition WHERE condition_name = :name');
    $stmt->execute([':name' => $conditionName]);
    $conditionId = $stmt->fetchColumn();

    if ($conditionId === false) {
        throw new RuntimeException(
            "Unknown condition_name '{$conditionName}' - has insert_conditions.sql been run?"
        );
    }
    $conditionId = (int)$conditionId;

    // Insert the session
    $sessionStart = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag)
        VALUES
            (:participant_id, :condition_id, :session_start, FALSE, FALSE, FALSE)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => $sessionStart,
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // Log the matching session_start event (event_log.artefact_id is
    // nullable for session-level events - see create_tables.sql note)
    $stmt = $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, NULL, 'session_start', :timestamp)
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':timestamp'  => $sessionStart,
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success'        => true,
        'session_id'     => $sessionId,
        'participant_id' => $participantId,
        'condition_id'   => $conditionId,
        'session_start'  => $sessionStart,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
