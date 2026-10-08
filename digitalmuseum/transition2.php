<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// transition2.php
//
// Transition Page 2 (Wireframes.docx > "Transition Page 2"). Shown
// automatically after the second 7-minute browsing session times out -
// static.php or adaptive.php's MuseumTimeout redirects here once it
// detects the OTHER version has already timed out for this session (see
// their timeoutRedirect logic). Always leads to questionnaire.php -
// there is no "next version" to compute here, unlike transition1.php.
//
// Interaction Behaviour (Wireframes.docx):
//   - No 'Return to Home' button (would let a participant skip back and
//     re-enter a finished task, breaking counterbalancing)
//   - No adaptive behaviour
//   - No logging required
// =====================================================================

session_start();
require_once __DIR__ . '/config/db_connect.php';

// ---------------------------------------------------------------------
// Guard: the database itself must be reachable (FB2), there must be an
// active session (FB1), and BOTH tasks must actually be done - otherwise
// this page was reached out of order, e.g. directly after only the
// first task (FB3). fallback.php is the real destination for all three
// (same pattern as transition1.php and static.php/adaptive.php's own
// guards).
// ---------------------------------------------------------------------
$pdo = safe_db_connect();
if ($pdo === null) {
    header('Location: fallback.php?reason=broken_db');
    exit;
}

if (!isset($_SESSION['museum_session_id'])) {
    header('Location: fallback.php?reason=missing_session');
    exit;
}

$sessionId = (int)$_SESSION['museum_session_id'];

$stmt = $pdo->prepare(
    'SELECT timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
);
$stmt->execute([':id' => $sessionId]);
$flags = $stmt->fetch();

$bothTasksDone = $flags
    && (bool)$flags['timeout_triggered_static']
    && (bool)$flags['timeout_triggered_adaptive'];

if (!$bothTasksDone) {
    header('Location: fallback.php?reason=wrong_navigation');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Browsing Sessions Complete</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="transition-page">

<header class="transition-header">
    <h1>Browsing Sessions Complete</h1>
    <p class="transition-header__subtitle">You will now complete a short questionnaire about your experience.</p>
</header>

<main class="transition-message">
    <p>Thank you for completing both browsing sessions.</p>
    <p>Please continue to the questionnaire when you are ready.</p>

    <a href="questionnaire.php" class="nav-card nav-card--primary">
        Continue to Questionnaire
    </a>
</main>

<footer class="transition-footer">
    <p>Your responses will help us understand how people explore digital museum collections.</p>
    <p>Thank you for taking part.</p>
</footer>

</body>
</html>
