<?php
// =====================================================================
// Digital Museum Research Project
// Phase 3 - Static Prototype
// index.php
//
// Home/Landing Screen (Wireframes.docx > "Home/Landing Screen").
// Entry point for the entire study.
//
// The wireframe shows only 3 clean participant-facing buttons (Static
// Version / Adaptive Version / Post-Study Questionnaire) with no
// participant code field - the researcher assigns the participant code
// and condition on paper before the session (Study Protocol
// Design.docx > "Participant Arrival & Setup"). So this page adds one
// extra element beyond the pure wireframe: a small "Researcher Setup"
// control at the very top, visually separate from the participant-
// facing area, where the researcher enters that code once per
// participant. Everything below that line matches the wireframe.
//
// Submitting the researcher setup form calls api/session_start.php
// server-side (the same endpoint the Phase 2 tests exercise - BL5),
// then stores session_id/participant_id/condition_name in the native
// PHP session so every later page (static.php, adaptive.php,
// transition pages, questionnaire.php) can read it without needing to
// pass it through URLs.
//
// To prevent accidental breaks in counterbalancing (a recurring theme
// across every wireframe screen), only the version matching the
// participant's assigned condition_name is enabled first; the other
// version and the questionnaire stay disabled until it's their turn.
// =====================================================================

session_start();

$errors = [];

// ---------------------------------------------------------------------
// Handle researcher setup form submission (POST-redirect-GET so a page
// refresh never resubmits the form)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_session'])) {
    $participantCode = trim($_POST['participant_code'] ?? '');
    $conditionName    = trim($_POST['condition_name'] ?? '');

    if ($participantCode === '' || strlen($participantCode) > 20) {
        $errors[] = 'Enter a participant code (1-20 characters).';
    }
    if (!in_array($conditionName, ['static_first', 'adaptive_first'], true)) {
        $errors[] = 'Select a condition.';
    }

    if (empty($errors)) {
        $baseUrl  = 'http://' . $_SERVER['HTTP_HOST'];
        $endpoint = $baseUrl . '/digitalmuseum/api/session_start.php';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'participant_code' => $participantCode,
                'condition_name'   => $conditionName,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($body, true);

        if ($httpCode === 201 && ($data['success'] ?? false) === true) {
            $_SESSION['museum_session_id']     = $data['session_id'];
            $_SESSION['museum_participant_id'] = $data['participant_id'];
            $_SESSION['museum_condition_name'] = $conditionName;

            header('Location: index.php');
            exit;
        }

        $errors[] = 'Could not start the session: ' . ($data['error'] ?? 'unknown error.');
    }
}

// ---------------------------------------------------------------------
// Handle emergency end-session (researcher-only "get out of jail free"
// control - Study Protocol Design.docx doesn't specify this, but a
// running session with no way to stop it early is a real risk during
// live testing, e.g. equipment issue, participant distress, researcher
// error in setup). Reuses api/session_end.php (already covered by
// Phase 2 test BL7) so this is the exact same close-out path as a
// normal questionnaire completion - no separate logic to keep in sync.
// POST-redirect-GET again, so a refresh never re-fires it.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_session'])) {
    if (isset($_SESSION['museum_session_id'])) {
        $baseUrl  = 'http://' . $_SERVER['HTTP_HOST'];
        $endpoint = $baseUrl . '/digitalmuseum/api/session_end.php';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['session_id' => $_SESSION['museum_session_id']]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
        // Best-effort: whether or not the API call succeeds, still clear
        // the local session below. The point of an emergency control is
        // to get the researcher back to a clean screen even if something
        // else (e.g. the DB) is misbehaving.
    }

    unset(
        $_SESSION['museum_session_id'],
        $_SESSION['museum_participant_id'],
        $_SESSION['museum_condition_name'],
        $_SESSION['museum_adaptive_task_start']
    );

    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------
// Determine current state for this browser session
// ---------------------------------------------------------------------
$sessionActive   = isset($_SESSION['museum_session_id']);
$conditionName    = $_SESSION['museum_condition_name'] ?? null;
$firstVersion      = $conditionName === 'adaptive_first' ? 'adaptive' : 'static';

$bothTimeoutsDone = false;
if ($sessionActive) {
    require_once __DIR__ . '/config/db.php';
    $stmt = $pdo->prepare(
        'SELECT timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
    );
    $stmt->execute([':id' => $_SESSION['museum_session_id']]);
    $flags = $stmt->fetch();
    $bothTimeoutsDone = $flags && (bool)$flags['timeout_triggered_static'] && (bool)$flags['timeout_triggered_adaptive'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Digital Museum Prototype Study</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="home-page">

<?php if (!$sessionActive): ?>
<section class="researcher-setup" aria-label="Researcher setup">
    <p class="researcher-setup__label">Researcher setup</p>
    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" action="index.php" class="researcher-setup__form">
        <label for="participant_code">Participant code</label>
        <input type="text" id="participant_code" name="participant_code" maxlength="20" required>

        <label for="condition_name">Condition</label>
        <select id="condition_name" name="condition_name" required>
            <option value="">Select...</option>
            <option value="static_first">Static first</option>
            <option value="adaptive_first">Adaptive first</option>
        </select>

        <button type="submit" name="start_session" value="1">Start Session</button>
    </form>
</section>
<?php else: ?>
<section class="researcher-setup researcher-setup--active" aria-label="Session status">
    <p class="researcher-setup__label">
        Session active - Participant: <?= htmlspecialchars($_SESSION['museum_participant_id']) ?>
        (session #<?= htmlspecialchars($_SESSION['museum_session_id']) ?>)
    </p>
    <form method="post" action="index.php" class="researcher-setup__end-form"
          onsubmit="return confirm('End this session now?\n\nThis is for the researcher only - not part of the participant task. It will immediately close session #<?= (int)$_SESSION['museum_session_id'] ?>.');">
        <button type="submit" name="end_session" value="1" class="end-session-btn"
                title="Researcher use only - immediately ends the current session">
            End session (researcher only)
        </button>
    </form>
</section>
<?php endif; ?>

<header class="home-header">
    <h1>Digital Museum Prototype Study</h1>
    <p class="home-header__subtitle">Exploring how people browse and discover artefacts.</p>
    <p class="home-header__small">This study includes two browsing tasks followed by a short questionnaire.</p>
</header>

<main class="home-nav">
    <div class="home-nav__button-group">
        <a class="nav-card <?= (!$sessionActive || $firstVersion !== 'static') ? 'nav-card--disabled' : '' ?>"
           href="<?= $sessionActive && $firstVersion === 'static' ? 'static.php' : '#' ?>"
           <?= (!$sessionActive || $firstVersion !== 'static') ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <span class="nav-card__title">Static Version</span>
            <span class="nav-card__description">Browse a collection of artefacts in a non-adaptive interface.</span>
        </a>

        <a class="nav-card <?= (!$sessionActive || $firstVersion !== 'adaptive') ? 'nav-card--disabled' : '' ?>"
           href="<?= $sessionActive && $firstVersion === 'adaptive' ? 'adaptive.php' : '#' ?>"
           <?= (!$sessionActive || $firstVersion !== 'adaptive') ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <span class="nav-card__title">Adaptive Version</span>
            <span class="nav-card__description">Browse a collection where artefacts update based on your interactions.</span>
        </a>

        <a class="nav-card <?= !$bothTimeoutsDone ? 'nav-card--disabled' : '' ?>"
           href="<?= $bothTimeoutsDone ? 'questionnaire.php' : '#' ?>"
           <?= !$bothTimeoutsDone ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <span class="nav-card__title">Post-Study Questionnaire</span>
            <span class="nav-card__description">Share your experience after completing both versions.</span>
        </a>
    </div>

    <div class="home-instructions">
        <p>You will complete both versions in the order designated to you.</p>
        <p>Please explore freely and click on any artefacts that interest you.</p>
        <p>When finished, complete the questionnaire.</p>
    </div>
</main>

<footer class="home-footer">
    <p>Study ID: DC_25259628</p>
    <p>Researcher: Kate Forshaw</p>
    <p>Edge Hill University</p>
    <p>Ethics approval: Confirmed</p>
</footer>

</body>
</html>
