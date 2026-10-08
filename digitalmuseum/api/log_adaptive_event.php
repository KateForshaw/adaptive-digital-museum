<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// api/log_adaptive_event.php
//
// Endpoint: POST /adaptive-event   (API Design.docx > Behaviour Logging API)
// Tables:   event_log, adaptive_event  (Database Design.docx)
//
// Logs the adaptive-only events fired from the adaptive panel and
// adaptive trigger engine (Behaviour Logging Map.docx > "Adaptive-Only
// Events"): adaptive_trigger, panel_impression, panel_click.
//
// Every adaptive event is recorded twice, in one transaction:
//   1. event_log  - keeps it in the same behavioural timeline as core
//                    events (click, dwell, revisit, navigation_depth).
//   2. adaptive_event - the adaptive-specific detail row (trigger
//                    confidence, linked back to event_log via event_id).
// A panel_click additionally flips adaptive_suggestion.clicked_flag for
// the referenced suggestion_id (Database Design.docx defines this column
// specifically to answer "was this shown suggestion ever clicked?" -
// found missing while building Phase 5's AP4 test: every suggestion is
// created with clicked_flag = FALSE - see api/trigger_rule.php - but
// nothing was ever setting it back to TRUE, so the column could never
// have reflected reality even though event_log/adaptive_event were
// already logging every click correctly).
//
// This endpoint is not used until the adaptive engine exists
// (Phase 4/5), but the logging pipeline is built now so it is ready
// to be called as soon as the engine fires its first rule.
// =====================================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

const ADAPTIVE_EVENT_TYPES = ['adaptive_trigger', 'panel_impression', 'panel_click'];

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
$eventType         = trim($input['event_type'] ?? '');
$ruleId            = filter_var($input['rule_id'] ?? null, FILTER_VALIDATE_INT);

// Optional - a suggestion may not exist yet when adaptive_trigger first
// fires (the engine creates the suggestion as a result of the trigger)
$suggestionId      = array_key_exists('suggestion_id', $input) && $input['suggestion_id'] !== null
    ? filter_var($input['suggestion_id'], FILTER_VALIDATE_INT)
    : null;

// Optional - relevant for panel_impression/panel_click, which relate
// to a specific suggested artefact
$artefactId        = array_key_exists('artefact_id', $input) && $input['artefact_id'] !== null
    ? filter_var($input['artefact_id'], FILTER_VALIDATE_INT)
    : null;

// Optional - strength/confidence of the rule firing
$triggerConfidence = array_key_exists('trigger_confidence', $input) && $input['trigger_confidence'] !== null
    ? filter_var($input['trigger_confidence'], FILTER_VALIDATE_FLOAT)
    : null;

$errors = [];

if ($sessionId === false || $sessionId === null) {
    $errors[] = 'session_id is required and must be an integer.';
}
if (!in_array($eventType, ADAPTIVE_EVENT_TYPES, true)) {
    $errors[] = 'event_type is required and must be one of: ' . implode(', ', ADAPTIVE_EVENT_TYPES) . '.';
}
if ($ruleId === false || $ruleId === null) {
    $errors[] = 'rule_id is required and must be an integer.';
}
if (array_key_exists('suggestion_id', $input) && $input['suggestion_id'] !== null && $suggestionId === false) {
    $errors[] = 'suggestion_id must be an integer when provided.';
}
if (array_key_exists('artefact_id', $input) && $input['artefact_id'] !== null && $artefactId === false) {
    $errors[] = 'artefact_id must be an integer when provided.';
}
if (array_key_exists('trigger_confidence', $input) && $input['trigger_confidence'] !== null && $triggerConfidence === false) {
    $errors[] = 'trigger_confidence must be numeric when provided.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ---------------------------------------------------------------------
// 3. Log the adaptive event (session/rule/suggestion/artefact
//    integrity, insert event_log row, insert adaptive_event row)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // session_id integrity
    $stmt = $pdo->prepare('SELECT session_id FROM session_log WHERE session_id = :id');
    $stmt->execute([':id' => $sessionId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("session_id {$sessionId} does not exist.");
    }

    // rule_id integrity
    $stmt = $pdo->prepare('SELECT rule_id FROM adaptive_rule WHERE rule_id = :id');
    $stmt->execute([':id' => $ruleId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("rule_id {$ruleId} does not exist.");
    }

    // suggestion_id integrity (only if provided)
    if ($suggestionId !== null) {
        $stmt = $pdo->prepare('SELECT suggestion_id FROM adaptive_suggestion WHERE suggestion_id = :id');
        $stmt->execute([':id' => $suggestionId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("suggestion_id {$suggestionId} does not exist.");
        }
    }

    // artefact_id integrity (only if provided)
    if ($artefactId !== null) {
        $stmt = $pdo->prepare('SELECT artefact_id FROM artefact WHERE artefact_id = :id');
        $stmt->execute([':id' => $artefactId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("artefact_id {$artefactId} does not exist.");
        }
    }

    // Timestamp is always generated server-side, never trusted from the client
    $timestamp = date('Y-m-d H:i:s');

    // 1. event_log - keeps this in the same behavioural timeline as core events
    $stmt = $pdo->prepare("
        INSERT INTO event_log
            (session_id, artefact_id, suggestion_id, rule_id, event_type, `timestamp`)
        VALUES
            (:session_id, :artefact_id, :suggestion_id, :rule_id, :event_type, :timestamp)
    ");
    $stmt->execute([
        ':session_id'    => $sessionId,
        ':artefact_id'   => $artefactId,
        ':suggestion_id' => $suggestionId,
        ':rule_id'       => $ruleId,
        ':event_type'    => $eventType,
        ':timestamp'     => $timestamp,
    ]);
    $eventId = (int)$pdo->lastInsertId();

    // 2. adaptive_event - the adaptive-specific detail row
    $stmt = $pdo->prepare("
        INSERT INTO adaptive_event
            (event_id, session_id, rule_id, suggestion_id, trigger_timestamp, trigger_confidence)
        VALUES
            (:event_id, :session_id, :rule_id, :suggestion_id, :trigger_timestamp, :trigger_confidence)
    ");
    $stmt->execute([
        ':event_id'           => $eventId,
        ':session_id'         => $sessionId,
        ':rule_id'            => $ruleId,
        ':suggestion_id'      => $suggestionId,
        ':trigger_timestamp'  => $timestamp,
        ':trigger_confidence' => $triggerConfidence,
    ]);
    $adaptiveEventId = (int)$pdo->lastInsertId();

    // 3. panel_click - mark the suggestion as clicked. Only meaningful
    // (and only possible) when a suggestion_id was actually supplied;
    // panel_click always carries one in practice (js/adaptive_engine.js
    // only fires it for a filled slot), but this doesn't assume that.
    if ($eventType === 'panel_click' && $suggestionId !== null) {
        $pdo->prepare('UPDATE adaptive_suggestion SET clicked_flag = TRUE WHERE suggestion_id = :id')
            ->execute([':id' => $suggestionId]);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success'            => true,
        'adaptive_event_id'  => $adaptiveEventId,
        'event_id'           => $eventId,
        'session_id'         => $sessionId,
        'rule_id'            => $ruleId,
        'suggestion_id'      => $suggestionId,
        'artefact_id'        => $artefactId,
        'event_type'         => $eventType,
        'timestamp'          => $timestamp,
        'trigger_confidence' => $triggerConfidence,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
