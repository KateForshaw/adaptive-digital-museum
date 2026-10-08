<?php
// =====================================================================
// Digital Museum Research Project
// Phase 1 - Database Foundation
// tests/phase1/test_theme_subtheme_join.php
//
// Test case DF2 (Testing Plans.docx > Phase 1 Test Cases)
//   Description:     Verify theme/subtheme mapping
//   Steps:            Run join query linking artefact -> subtheme -> theme
//   Expected Result:  Correct theme/subtheme returned for each artefact
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$expectedCount = 60;
$errors = [];

$sql = "
    SELECT
        a.artefact_id,
        a.artefact_title,
        a.theme_id    AS artefact_theme_id,
        a.subtheme_id AS artefact_subtheme_id,
        s.theme_id    AS subtheme_theme_id,
        s.subtheme_name,
        t.theme_id    AS theme_theme_id,
        t.theme_name
    FROM artefact a
    JOIN subtheme s ON a.subtheme_id = s.subtheme_id
    JOIN theme t    ON a.theme_id = t.theme_id
    ORDER BY a.artefact_id
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

$actualCount = count($rows);
if ($actualCount !== $expectedCount) {
    $errors[] = "Expected {$expectedCount} joined rows, found {$actualCount}. "
        . "This means some artefacts have a theme_id/subtheme_id that does not "
        . "match a real theme/subtheme row.";
}

foreach ($rows as $row) {
    $id = $row['artefact_id'];

    // The artefact's own theme_id must match the theme that its subtheme
    // actually belongs to - catches artefacts filed under a subtheme from
    // the "wrong" theme.
    if ((int)$row['artefact_theme_id'] !== (int)$row['subtheme_theme_id']) {
        $errors[] = "Artefact {$id} ('{$row['artefact_title']}'): theme_id "
            . "({$row['artefact_theme_id']}) does not match its subtheme's "
            . "theme_id ({$row['subtheme_theme_id']}) - subtheme "
            . "'{$row['subtheme_name']}' belongs to a different theme.";
    }

    if (empty($row['theme_name']) || empty($row['subtheme_name'])) {
        $errors[] = "Artefact {$id}: missing theme_name or subtheme_name in join result.";
    }
}

echo "DF2 - Verify theme/subtheme mapping\n";
echo str_repeat('-', 50) . "\n";
echo "Joined rows returned: {$actualCount} (expected {$expectedCount})\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "All {$actualCount} artefacts joined correctly to a subtheme and theme, "
        . "and each artefact's theme_id matches its subtheme's parent theme.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
