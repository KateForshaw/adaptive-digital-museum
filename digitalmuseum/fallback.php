<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// fallback.php
//
// Error/Fallback Screen (Testing Plans.docx > Phase 7 - FB1-FB4).
// The single recovery point for every failure state in the system: a
// missing/expired PHP session, an unreachable database, or a
// participant reaching a page out of the intended flow order.
//
// Design goals (Phase 7 Testing Plan - "Ensure system recovers
// gracefully from errors and preserves study flow"):
//   - Must always render, even if the database is completely
//     unreachable. config/db.php die()s with a raw PHP error on
//     connection failure, which is correct for the JSON API endpoints
//     but wrong for a participant-facing screen (FB2), so this file
//     uses config/db_connect.php's safe_db_connect() instead - same
//     helper the other participant-facing pages now use, so a broken
//     DB never crashes any page in the study, this one included.
//   - Logs an 'error_trigger' event_log row and flips
//     session_log.error_flag when it can - best-effort only, since a
//     DB failure is one of the reasons this page exists. A logging
//     failure must never block the fallback screen itself.
//   - Provides the one safe way out: a single "Return to Home" button
//     (FB4) that performs session reset logic - closes out any open
//     DB session, then clears the local PHP session - and redirects
//     to index.php. No other links, no dead ends.
//   - Never shows raw PDO/PHP error detail to the participant - only
//     a calm, plain-English message picked via an optional ?reason=
//     query param (set by the guard clauses below, or by the Phase 7
//     tests).
//
// Reached from: static.php / adaptive.php / transition1.php /
// transition2.php / questionnaire.php guard clauses, or hit directly.
// =====================================================================

require_once __DIR__ . '/config/db_connect.php';

session_start();

// ---------------------------------------------------------------------
// 1. Work out why we're here (diagnostic only - never shown verbatim,
//    only used to pick which calm message to display)
// ---------------------------------------------------------------------
const KNOWN_REASONS = ['missing_session', 'broken_db', 'wrong_navigation', 'manual'];

$reason = $_GET['reason'] ?? ($_POST['reason'] ?? null);
if (!in_array($reason, KNOWN_REASONS, true)) {
    $reason = 'unknown';
}

$messages = [
    'missing_session'  => "We couldn't find an active session. This can happen if this page was opened directly, or if your session expired.",
    'broken_db'        => "We're having trouble reaching the study database right now.",
    'wrong_navigation' => "That page isn't available yet at this point in the study.",
    'manual'           => "Something interrupted your session.",
    'unknown'          => "Something went wrong.",
];
$displayMessage = $messages[$reason];

// ---------------------------------------------------------------------
// 3. error_trigger logging (best-effort, once per visit to this page -
//    session_log.error_flag/event_log both require a real session_id,
//    so there is nothing to log for a true FB1 "no session at all"
//    case; that's expected, not a bug).
// ---------------------------------------------------------------------
function fallback_log_error_trigger(PDO $pdo, int $sessionId, string $reason): void
{
    $timestamp = date('Y-m-d H:i:s');

    $pdo->prepare("
        INSERT INTO event_log (session_id, artefact_id, event_type, `timestamp`)
        VALUES (:session_id, NULL, 'error_trigger', :timestamp)
    ")->execute([
        ':session_id' => $sessionId,
        ':timestamp'  => $timestamp,
    ]);

    $pdo->prepare('UPDATE session_log SET error_flag = TRUE WHERE session_id = :id')
        ->execute([':id' => $sessionId]);
}

$sessionId = isset($_SESSION['museum_session_id']) ? (int)$_SESSION['museum_session_id'] : null;

// Only log once per fallback visit, even if the participant/researcher
// refreshes this page - avoids spamming event_log with duplicate rows
// for the same underlying error.
if ($sessionId !== null && empty($_SESSION['museum_fallback_logged']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = safe_db_connect();
    if ($pdo !== null) {
        try {
            fallback_log_error_trigger($pdo, $sessionId, $reason);
            $_SESSION['museum_fallback_logged'] = true;
        } catch (Throwable $e) {
            // Swallow - logging failure must never block the fallback screen.
        }
    }
}

// ---------------------------------------------------------------------
// 4. Session reset logic (FB4 - "Click Return to Home -> Home loads
//    cleanly"). Mirrors index.php's emergency "End session" control:
//    best-effort close the DB session_log row, then always clear the
//    local PHP session and redirect. Never touches condition_name/
//    inserts a new session, so counterbalancing for THIS session is
//    simply closed out - a fresh researcher setup on index.php starts
//    a separate, clean session rather than resuming this one.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    if ($sessionId !== null) {
        $pdo = safe_db_connect();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare('SELECT session_end FROM session_log WHERE session_id = :id');
                $stmt->execute([':id' => $sessionId]);
                $row = $stmt->fetch();

                if ($row && $row['session_end'] === null) {
                    $pdo->prepare('UPDATE session_log SET session_end = :now WHERE session_id = :id')
                        ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $sessionId]);
                }
            } catch (Throwable $e) {
                // Swallow - a failed cleanup must never block getting back
                // to a clean home screen.
            }
        }
    }

    unset(
        $_SESSION['museum_session_id'],
        $_SESSION['museum_participant_id'],
        $_SESSION['museum_condition_name'],
        $_SESSION['museum_fallback_logged'],
        $_SESSION['museum_adaptive_task_start']
    );

    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something Went Wrong</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="fallback-page">

<header class="fallback-header">
    <h1>Something Went Wrong</h1>
</header>

<main class="fallback-message">
    <p><?= htmlspecialchars($displayMessage) ?></p>
    <p>No progress has been lost. Please return to the home screen to continue.</p>

    <form method="post" action="fallback.php">
        <input type="hidden" name="reason" value="<?= htmlspecialchars($reason) ?>">
        <button type="submit" name="reset" value="1" class="nav-card--primary">
            Return to Home
        </button>
    </form>
</main>

<footer class="fallback-footer">
    <p>Study ID: DC_25259628</p>
</footer>

</body>
</html>
