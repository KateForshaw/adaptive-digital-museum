<?php
// =====================================================================
// Digital Museum Research Project
// Phase 6 - Questionnaire Integration
// questionnaire.php
//
// Post-Study Questionnaire Screen.
//
// *** DESIGN DEVIATION - READ BEFORE COMPARING TO WIREFRAMES.DOCX ***
// Wireframes.docx > "Questionnaire-Screen Wireframe" and the original
// Phased Build Plan describe this screen as an embedded Microsoft Form
// (<iframe>), with "no behavioural logging" and submission/storage
// handled entirely by Microsoft. That was superseded once Database
// Design.docx and API Design.docx were written: they define
// questionnaire_item / questionnaire_response tables and a dedicated
// GET /questionnaire/items, POST /questionnaire/response,
// GET /questionnaire/responses API, all clearly built for a native
// in-system form, not an external one. This was an intentional move 
// away from the Microsoft Form design so every participant response 
// lives in the same MySQL database as the behavioural logs, rather than 
// being split across two systems. This file is therefore a fully custom 
// PHP/HTML form, not an iframe. Wireframes.docx and the Build Plan's "Embed
// Microsoft Form" step are now out of date and should be updated to
// match this file.
//
// Reached from transition2.php once both 7-minute browsing tasks are
// done (Wireframes.docx > "Transition Page 2"). Loads the 14 items
// from questionnaire_item (Post Study Questionnaire.docx) via
// api/get_questionnaire_items.php, renders them as a native form, and
// on submission calls api/post_questionnaire_response.php, which
// stores each answer in questionnaire_response AND closes out the
// session (session_end) in one transaction - see that file for why
// those two things happen together.
//
// Interaction Behaviour:
//   - No adaptive behaviour, no artefact grid, no modal
//   - Optional 'Return to Home' link (safe here - both tasks are
//     already complete, so it can't break counterbalancing)
//   - Once session_log.session_end is set, reloading this page shows
//     a completion screen instead of the form again, so a refresh
//     can never insert duplicate responses
// =====================================================================

session_start();
require_once __DIR__ . '/config/db_connect.php';

// ---------------------------------------------------------------------
// Guard: the database itself must be reachable (FB2), there must be an
// active session (FB1), and BOTH browsing tasks must be done - otherwise
// this page was reached out of order (FB3). fallback.php is the real
// destination for all three (same pattern as transition1.php/
// transition2.php's own guards).
// ---------------------------------------------------------------------
$pdo = safe_db_connect();
if ($pdo === null) {
    header('Location: fallback.php?reason=broken_db');
    exit;
}

if (!isset($_SESSION['museum_session_id'], $_SESSION['museum_participant_id'], $_SESSION['museum_condition_name'])) {
    header('Location: fallback.php?reason=missing_session');
    exit;
}

$sessionId     = (int)$_SESSION['museum_session_id'];
$participantId = (int)$_SESSION['museum_participant_id'];

$stmt = $pdo->prepare(
    'SELECT timeout_triggered_static, timeout_triggered_adaptive, session_end
     FROM session_log WHERE session_id = :id'
);
$stmt->execute([':id' => $sessionId]);
$sessionRow = $stmt->fetch();

$bothTasksDone = $sessionRow
    && (bool)$sessionRow['timeout_triggered_static']
    && (bool)$sessionRow['timeout_triggered_adaptive'];

if (!$bothTasksDone) {
    header('Location: fallback.php?reason=wrong_navigation');
    exit;
}

// session_end already set -> this participant already submitted. Show
// the completion screen instead of the form so a page refresh (or the
// back button) can never insert a second set of responses.
$alreadyComplete = $sessionRow['session_end'] !== null;

// ---------------------------------------------------------------------
// Small helper for calling this app's own API endpoints server-side -
// same curl-to-self pattern index.php already uses for session_start.php
// / session_end.php, just generalised to also support GET.
// ---------------------------------------------------------------------
function callLocalApi(string $method, string $path, ?array $payload = null): array
{
    $endpoint = 'http://' . $_SERVER['HTTP_HOST'] . '/digitalmuseum/api/' . $path;

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, $options);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, json_decode($body, true)];
}

$items           = [];
$errors          = [];
$submittedValues = [];

// questionnaire_item has no column for structured multiple_choice
// answer options (item_notes is free text, meant for internal
// documentation only - see get_questionnaire_items.php). There are
// only two multiple_choice items, so their options are defined here
// to match Post Study Questionnaire.docx exactly, rather than parsing
// item_notes or adding a schema column for just two rows.
$multipleChoiceOptions = [
    1  => ['Static version', 'Adaptive version'],
    12 => ['Static version', 'Adaptive version', 'No preference'],
];

// Section headings, keyed by the item_order that starts each section
// (Post Study Questionnaire.docx's own grouping).
$sectionHeadings = [
    1  => 'About the Study Session',
    2  => 'Static Prototype Feedback',
    7  => 'Adaptive Prototype Feedback',
    12 => 'Comparative Reflection',
    14 => 'Final Thoughts',
];

// The two explicitly-marked "Optional comment" free-text items
// (item_notes in insert_questionnaire_items.sql). The other two
// free-text items (13 - preference_reason, 14 - final_comments) are
// also treated as optional here: forcing open-text answers tends to
// hurt response quality, and only likert/multiple_choice items are
// required to keep the completion bar low for participants.
$optionalItemIds = [6, 11, 13, 14];

if (!$alreadyComplete) {
    [$itemsHttpCode, $itemsData] = callLocalApi('GET', 'get_questionnaire_items.php');

    if ($itemsHttpCode === 200 && ($itemsData['success'] ?? false) === true) {
        $items = $itemsData['items'];
    } else {
        $errors[] = 'The questionnaire could not be loaded. Please let the researcher know.';
    }

    // -------------------------------------------------------------
    // Handle submission
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_questionnaire']) && !empty($items)) {
        $responses = [];

        foreach ($items as $item) {
            $itemId    = (int)$item['item_id'];
            $fieldName = 'item_' . $itemId;
            $value     = trim($_POST[$fieldName] ?? '');

            $submittedValues[$itemId] = $value;

            $isRequired = !in_array($itemId, $optionalItemIds, true);
            if ($isRequired && $value === '') {
                $errors[] = 'Please answer: "' . $item['item_text'] . '"';
                continue;
            }
            if ($value !== '') {
                $responses[] = ['item_id' => $itemId, 'value' => $value];
            }
        }

        if (empty($errors)) {
            [$postHttpCode, $postData] = callLocalApi('POST', 'post_questionnaire_response.php', [
                'session_id'     => $sessionId,
                'participant_id' => $participantId,
                'responses'      => $responses,
            ]);

            if ($postHttpCode === 200 && ($postData['success'] ?? false) === true) {
                // POST-redirect-GET so a page refresh never resubmits the
                // form. The redirect lands back here, sees session_end is
                // now set, and shows the completion screen instead.
                header('Location: questionnaire.php');
                exit;
            }

            $errors[] = 'Could not save your responses: ' . ($postData['error'] ?? 'unknown error.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Post-Study Questionnaire</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="questionnaire-page">

<header class="version-header">
    <h1>Post-Study Questionnaire</h1>
    <p class="questionnaire-header__subtitle">Please complete the short questionnaire about your experience.</p>
    <p class="text-small">Your responses help us understand how people explore digital museum collections.</p>
</header>

<main class="questionnaire-main">

<?php if ($alreadyComplete): ?>

    <section class="questionnaire-complete">
        <p>Thank you for completing the questionnaire.</p>
        <p>Your responses have been recorded. You may now close this window, or return to the home screen.</p>
        <a href="index.php" class="nav-card nav-card--primary">Return to Home</a>
    </section>

<?php else: ?>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors questionnaire-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($items)): ?>
        <form method="post" action="questionnaire.php" class="questionnaire-form" novalidate>

            <?php foreach ($items as $item):
                $itemId       = (int)$item['item_id'];
                $itemOrder    = (int)$item['item_order'];
                $fieldName    = 'item_' . $itemId;
                $currentValue = $submittedValues[$itemId] ?? '';
                $isOptional   = in_array($itemId, $optionalItemIds, true);
            ?>

                <?php if (isset($sectionHeadings[$itemOrder])): ?>
                    <h2 class="questionnaire-section__title"><?= htmlspecialchars($sectionHeadings[$itemOrder]) ?></h2>
                <?php endif; ?>

                <fieldset class="questionnaire-item">
                    <legend class="questionnaire-item__text">
                        <?= htmlspecialchars($item['item_text']) ?>
                        <?php if ($isOptional): ?><span class="text-small">(optional)</span><?php endif; ?>
                    </legend>

                    <?php if ($item['item_type'] === 'multiple_choice'): ?>
                        <div class="questionnaire-item__choices">
                            <?php foreach ($multipleChoiceOptions[$itemId] ?? [] as $option): ?>
                                <label class="questionnaire-item__choice">
                                    <input type="radio" name="<?= htmlspecialchars($fieldName) ?>"
                                           value="<?= htmlspecialchars($option) ?>"
                                           <?= $currentValue === $option ? 'checked' : '' ?>
                                           <?= !$isOptional ? 'required' : '' ?>>
                                    <?= htmlspecialchars($option) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($item['item_type'] === 'likert'): ?>
                        <div class="likert-scale">
                            <span class="likert-scale__label"><?= htmlspecialchars($item['scale_min_label'] ?? '') ?></span>
                            <?php for ($score = 1; $score <= 5; $score++): ?>
                                <label class="likert-scale__option">
                                    <input type="radio" name="<?= htmlspecialchars($fieldName) ?>"
                                           value="<?= $score ?>"
                                           <?= (string)$currentValue === (string)$score ? 'checked' : '' ?>
                                           <?= !$isOptional ? 'required' : '' ?>>
                                    <?= $score ?>
                                </label>
                            <?php endfor; ?>
                            <span class="likert-scale__label"><?= htmlspecialchars($item['scale_max_label'] ?? '') ?></span>
                        </div>

                    <?php else: // free_text ?>
                        <textarea name="<?= htmlspecialchars($fieldName) ?>" class="questionnaire-item__textarea"
                                  rows="3"><?= htmlspecialchars($currentValue) ?></textarea>
                    <?php endif; ?>
                </fieldset>

            <?php endforeach; ?>

            <div class="questionnaire-actions">
                <button type="submit" name="submit_questionnaire" value="1" class="btn-primary">Submit Questionnaire</button>
            </div>
        </form>

        <p class="text-small questionnaire-instructions">
            Please ensure you have completed both browsing tasks before submitting. Your responses will be anonymous.
        </p>
    <?php endif; ?>

<?php endif; ?>

</main>

<footer class="version-footer">
    <p>Thank you for taking part in this study.</p>
    <a href="index.php" class="nav-link">Return to Home</a>
</footer>

</body>
</html>
