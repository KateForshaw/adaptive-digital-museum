<?php
// =====================================================================
// Digital Museum Research Project
// Phase 3 - Static Prototype
// transition1.php
//
// Transition Page 1 (Wireframes.docx > "Transition Page 1"). Shown
// automatically after the first 7-minute browsing session times out. 
// Confirms completion of the first task and points the participant to 
// whichever version they haven't done yet, based on the condition_name assigned on index.php -
// assigned on index.php - this is the counterbalancing logic in action.
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
// active session (FB1), and the first task (per the assigned condition)
// must have actually timed out - otherwise this page was reached out of
// order (FB3). fallback.php is the real destination for all three, now
// that Phase 7 has built it properly.
// ---------------------------------------------------------------------
$pdo = safe_db_connect();
if ($pdo === null) {
    header('Location: fallback.php?reason=broken_db');
    exit;
}

if (!isset($_SESSION['museum_session_id'], $_SESSION['museum_condition_name'])) {
    header('Location: fallback.php?reason=missing_session');
    exit;
}

$sessionId     = (int)$_SESSION['museum_session_id'];
$conditionName = $_SESSION['museum_condition_name'];
$firstVersion  = $conditionName === 'adaptive_first' ? 'adaptive' : 'static';
$nextVersion   = $firstVersion === 'static' ? 'adaptive' : 'static';

$stmt = $pdo->prepare(
    'SELECT timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
);
$stmt->execute([':id' => $sessionId]);
$flags = $stmt->fetch();

$firstTaskDone = $flags && (bool)$flags['timeout_triggered_' . $firstVersion];

if (!$firstTaskDone) {
    header('Location: fallback.php?reason=wrong_navigation');
    exit;
}

$nextVersionLabel = $nextVersion === 'adaptive' ? 'Adaptive Version' : 'Static Version';
$nextVersionUrl   = $nextVersion === 'adaptive' ? 'adaptive.php' : 'static.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>First Browsing Task Complete</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="transition-page">

<header class="transition-header">
    <h1>First Browsing Task Complete</h1>
    <p class="transition-header__subtitle">You will now continue to the next version of the digital museum prototype.</p>
</header>

<main class="transition-message">
    <p>Thank you for completing the first browsing session.</p>
    <p>Click the button below when you are ready to continue.</p>

    <a href="<?= htmlspecialchars($nextVersionUrl) ?>" class="nav-card nav-card--primary">
        Continue to <?= htmlspecialchars($nextVersionLabel) ?>
    </a>
</main>

<footer class="transition-footer">
    <p>Please explore freely in the next session.</p>
    <p>Each browsing session lasts approximately 7 minutes.</p>
</footer>

</body>
</html>
