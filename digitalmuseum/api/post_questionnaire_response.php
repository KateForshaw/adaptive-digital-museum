<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// api/post_questionnaire_response.php
//
// Endpoint: POST /questionnaire/response   (API Design.docx > Questionnaire API)
// Tables:   questionnaire_response, session_log, event_log
//           (Database Design.docx)
//
// Called once, by questionnaire.php's submit handler. See the design-
// deviation note at the top of questionnaire.php for why this is a
// native form POST rather than a Microsoft Form submission.
//
// Does two things in ONE transaction:
//   1. Inserts one questionnaire_response row per answered item.
//   2. Closes the session - sets session_log.session_end and logs a
//      'session_end' event, the same two writes api/session_end.php
//      already performs for the emergency "End session" control on
//      index.php.
// That logic is duplicated here rather than called over HTTP, on
// purpose: responses and session-close must succeed or fail together.
// If they were two separate requests, a crash/network failure between
// them could leave a session with saved responses but no session_end
// (or vice versa), and questionnaire.php's "already submitted" guard
// (checks session_end) would then let the participant land back on a
// blank form and submit a second, duplicate set of answers.
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
// 2. Parse + validate top-level fields
// ---------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Request body must be valid JSON.']);
    exit;
}

$sessionId      = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
$participantId  = filter_var($input['participant_id'] ?? null, FILTER_VALIDATE_INT);
$responsesInput = $input['responses'] ?? null;

$errors = [];
if ($sessionId === false || $sessionId === null) {
    $errors[] = 'session_id is required and must be an integer.';
}
if ($participantId === false || $participantId === null) {
    $errors[] = 'participant_id is required and must be an integer.';
}
if (!is_array($responsesInput) || count($responsesInput) === 0) {
    $errors[] = 'responses is required and must be a non-empty array.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 3. Validate each response row (item_id integrity, non-empty value,
//    fits response_value VARCHAR(500), no duplicate item_id)
// ---------------------------------------------------------------------
$cleanResponses = [];
$seenItemIds    = [];

foreach ($responsesInput as $i => $row) {
    if (!is_array($row)) {
        $errors[] = "responses[{$i}] must be an object with item_id and value.";
        continue;
    }

    $itemId = filter_var($row['item_id'] ?? null, FILTER_VALIDATE_INT);
    $value  = trim((string)($row['value'] ?? ''));

    if ($itemId === false || $itemId === null) {
        $errors[] = "responses[{$i}].item_id must be an integer.";
        continue;
    }
    if ($value === '') {
        $errors[] = "responses[{$i}].value must not be empty (item_id {$itemId}).";
        continue;
    }
    if (strlen($value) > 500) {
        $errors[] = "responses[{$i}].value exceeds the 500 character limit (item_id {$itemId}).";
        continue;
    }
    if (isset($seenItemIds[$itemId])) {
        $errors[] = "Duplicate response for item_id {$itemId}.";
        continue;
    }

    $seenItemIds[$itemId] = true;
    $cleanResponses[]     = ['item_id' => $itemId, 'value' => $value];
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 4. Save responses + close the session (session_id/participant_id/
//    item_id integrity, response insert, session_log update, matching
//    event_log row)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // session_id integrity - the session must already exist, and the
    // participant_id supplied must match the one it was opened with
    $stmt = $pdo->prepare(
        'SELECT session_id, participant_id, session_end FROM session_log WHERE session_id = :id'
    );
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();

    if ($session === false) {
        throw new RuntimeException("session_id {$sessionId} does not exist.");
    }
    if ((int)$session['participant_id'] !== $participantId) {
        throw new RuntimeException(
            "participant_id {$participantId} does not match session {$sessionId}."
        );
    }

    if ($session['session_end'] !== null) {
        // Already submitted - reject rather than insert a second set of
        // responses. questionnaire.php's own guard should prevent this
        // in normal use; this is the second line of defence.
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => 'This session has already submitted the questionnaire.',
        ]);
        exit;
    }

    // item_id integrity - every item_id must exist in questionnaire_item
    // (also enforced by the fk_response_item foreign key, but checked
    // up front so a bad item_id reports clearly instead of as a raw
    // constraint-violation error)
    $itemIds      = array_column($cleanResponses, 'item_id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt         = $pdo->prepare("SELECT item_id FROM questionnaire_item WHERE item_id IN ({$placeholders})");
    $stmt->execute($itemIds);
    $validItemIds   = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $unknownItemIds = array_diff($itemIds, $validItemIds);

    if (!empty($unknownItemIds)) {
        throw new RuntimeException(
            'Unknown item_id(s): ' . implode(', ', $unknownItemIds)
            . ' - has insert_questionnaire_items.sql been run?'
        );
    }

    $timestamp = date('Y-m-d H:i:s');

    // Insert one questionnaire_response row per answered item
    $insertStmt = $pdo->prepare('
        INSERT INTO questionnaire_response
            (session_id, participant_id, item_id, response_value, response_timestamp)
        VALUES
            (:session_id, :participant_id, :item_id, :response_value, :response_timestamp)
    ');
    foreach ($cleanResponses as $response) {
        $insertStmt->execute([
            ':session_id'         => $sessionId,
            ':participant_id'     => $participantId,
            ':item_id'            => $response['item_id'],
            ':response_value'     => $response['value'],
            ':response_timestamp' => $timestamp,
        ]);
    }

    // Close the session (mirrors api/session_end.php's own logic - see
    // the file header for why it's duplicated here instead of called
    // over HTTP)
    $stmt = $pdo->prepare('UPDATE session_log SET session_end = :session_end WHERE session_id = :id');
    $stmt->execute([
        ':session_end' => $timestamp,
        ':id'          => $sessionId,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, NULL, 'session_end', :timestamp)
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':timestamp'  => $timestamp,
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success'         => true,
        'session_id'      => $sessionId,
        'responses_saved' => count($cleanResponses),
        'session_end'     => $timestamp,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
