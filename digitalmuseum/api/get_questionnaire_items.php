<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// api/get_questionnaire_items.php
//
// Endpoint: GET /questionnaire/items   (API Design.docx > Questionnaire API)
// Table:    questionnaire_item          (Database Design.docx)
//
// Called by questionnaire.php when the post-study questionnaire screen
// loads. Returns all 14 items (Post Study Questionnaire.docx), ordered
// so the page can render them as a native form - multiple_choice items
// as radio options, likert items as a 1-5 scale, free_text items as a
// textarea. item_notes is left out of the response deliberately; it
// only holds internal formal variable names for analysis and isn't
// meant to reach the participant-facing screen.
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
// 2. Fetch all questionnaire items, in display order
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->query('
        SELECT item_id, item_text, item_type, item_order,
               scale_min_label, scale_max_label
        FROM questionnaire_item
        ORDER BY item_order
    ');
    $items = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count'   => count($items),
        'items'   => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
