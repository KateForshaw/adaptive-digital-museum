<?php
// =====================================================================
// Digital Museum Research Project
// Phase 3 - Static Prototype
// static.php
//
// Static Prototype Wireframe (Wireframes.docx > "Static Prototype
// Wireframe"). The baseline, non-adaptive browsing condition: a
// scrollable artefact grid, a detail modal, a 7-minute timeout, and
// nothing else - no panel, no dynamic updates, no reordering.
//
// Guarded by the session created on index.php (via $_SESSION) rather
// than re-creating a session here. Artefacts are queried directly from
// the database (api/get_artefacts.php is Phase 3-scoped for the
// artefact API but isn't part of the enumerated build steps for this
// page - static.php reads the DB directly, same as index.php already
// does for its timeout-flag check).
//
// Timeout redirect (fixed alongside adaptive.php, Phase 5): this page's
// own timeout must lead to transition1.php if static is this session's
// first browsing task, or transition2.php if it's the second - which
// one depends on whether the OTHER version (adaptive) has already
// timed out, not a fixed destination. Previously hardcoded to
// transition1.php, which mis-routed adaptive_first participants (static
// is their second task) back into transition1.php's "first task done"
// branch instead of on to transition2.php -> questionnaire.
//
// Interaction Behaviour (Wireframes.docx):
//   Click       -> opens modal; logs click, then navigation_depth,
//                  (revisit if already seen this task), then dwell_start
//   Modal close -> logs dwell_end with the elapsed dwell time
//   Scrolling   -> allowed, not logged
//   7-minute timeout -> logs timeout_redirect, redirects to transition1.php
// =====================================================================

session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/artefact_order.php';

// ---------------------------------------------------------------------
// Guard: the database itself must be reachable (FB2), and there must be
// an active session (FB1) - Wireframes.docx > Error/Fallback Screen
// exists for exactly this class of problem, and Phase 7 built
// fallback.php as the real destination for both.
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
    'SELECT participant_id, timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
);
$stmt->execute([':id' => $sessionId]);
$flags = $stmt->fetch();

if (!$flags || (bool)$flags['timeout_triggered_static']) {
    header('Location: fallback.php?reason=wrong_navigation');
    exit;
}

// This session's OTHER version already timed out -> static is the
// second task -> its own timeout must lead to transition2.php
// (questionnaire), not back through transition1.php's "first task"
// logic. Otherwise this is the first task -> transition1.php as usual.
$isSecondTask    = (bool)$flags['timeout_triggered_adaptive'];
$timeoutRedirect = $isSecondTask ? 'transition2.php' : 'transition1.php';
$participantId   = (int)$flags['participant_id'];

// ---------------------------------------------------------------------
// Fetch all artefacts for the grid (theme/subtheme joined for display
// and for the modal's metadata section), then reorder them for this
// participant (Phase 8 - Artefact Order / includes/artefact_order.php).
// `ORDER BY a.artefact_id` is kept as a stable base order for the
// shuffle to start from, not as the order the grid actually renders in.
// ---------------------------------------------------------------------
$artefacts = $pdo->query("
    SELECT
        a.artefact_id, a.artefact_title, a.artefact_description,
        a.image_url, a.source_url, a.external_id,
        t.theme_name, s.subtheme_name
    FROM artefact a
    JOIN theme t ON a.theme_id = t.theme_id
    JOIN subtheme s ON a.subtheme_id = s.subtheme_id
    ORDER BY a.artefact_id
")->fetchAll();

$artefacts = orderArtefactsForParticipant($artefacts, $participantId);

// Persist the computed order on session_log.artefact_order (Phase 8 -
// database/add_artefact_order.sql) - a JSON-encoded array of
// artefact_id in display order, so later analysis/debugging can
// confirm what a participant actually saw (Testing Plans.docx > AO2)
// even if orderArtefactsForParticipant()'s algorithm changes later.
// Written on every load, including reloads - harmless (not just
// "first load only") since the order is deterministic per
// participant_id (AO4 - reloading never changes it), so this simply
// keeps the stored value in sync rather than needing extra logic to
// detect a first write.
$pdo->prepare('UPDATE session_log SET artefact_order = :order WHERE session_id = :id')
    ->execute([
        ':order' => json_encode(array_map('intval', array_column($artefacts, 'artefact_id'))),
        ':id'    => $sessionId,
    ]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Static Browsing Interface</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="static-page">

<header class="version-header">
    <h1>Static Browsing Interface</h1>
    <p class="version-header__subtitle">Explore the artefacts freely. This version does not adapt to your behaviour.</p>
    <p class="version-header__small">Click any artefact to view more details.</p>
</header>

<main class="artefact-grid" id="artefactGrid">
    <?php foreach ($artefacts as $artefact): ?>
        <button
            type="button"
            class="artefact-card"
            data-artefact-id="<?= (int)$artefact['artefact_id'] ?>"
        >
            <img
                class="artefact-card__image"
                src="<?= htmlspecialchars($artefact['image_url']) ?>"
                alt="<?= htmlspecialchars($artefact['artefact_title']) ?>"
                loading="lazy"
            >
            <span class="artefact-card__title"><?= htmlspecialchars($artefact['artefact_title']) ?></span>
            <span class="artefact-card__subtheme"><?= htmlspecialchars($artefact['subtheme_name']) ?></span>
        </button>
    <?php endforeach; ?>
</main>

<div class="modal-overlay" id="modalOverlay" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <button type="button" class="modal-box__close" id="modalCloseBtn" aria-label="Close">&times;</button>
        <img class="modal-box__image" id="modalImage" src="" alt="">
        <h2 id="modalTitle"></h2>
        <p class="modal-box__meta" id="modalMeta"></p>
        <p id="modalDescription"></p>
        <p class="modal-box__external-id" id="modalExternalId"></p>
        <a class="modal-box__source-link" id="modalSourceLink" href="#" target="_blank" rel="noopener noreferrer" hidden>Source</a>
        <button type="button" class="modal-box__close-btn" id="modalCloseBtnBottom">Close</button>
    </div>
</div>

<footer class="version-footer">
    <p>Static Version - No adaptive behaviour</p>
    <a href="index.php" class="nav-link">Return to Home</a>
    <p class="version-footer__timer" id="debugCountdown" aria-hidden="true"></p>
</footer>

<script src="/digitalmuseum/js/logging.js"></script>
<script src="/digitalmuseum/js/timeout.js"></script>
<script>
    const ARTEFACTS = <?= json_encode($artefacts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const ARTEFACTS_BY_ID = Object.fromEntries(ARTEFACTS.map(a => [String(a.artefact_id), a]));

    MuseumLogging.resumeSession(<?= $sessionId ?>);

    const modalOverlay        = document.getElementById('modalOverlay');
    const modalBox            = document.querySelector('.modal-box');
    const modalImage          = document.getElementById('modalImage');
    const modalTitle          = document.getElementById('modalTitle');
    const modalMeta           = document.getElementById('modalMeta');
    const modalDescription    = document.getElementById('modalDescription');
    const modalExternalId     = document.getElementById('modalExternalId');
    const modalSourceLink     = document.getElementById('modalSourceLink');
    const modalCloseBtn       = document.getElementById('modalCloseBtn');
    const modalCloseBtnBottom = document.getElementById('modalCloseBtnBottom');

    let currentArtefactId  = null;
    let lastFocusedElement = null;

    async function openModal(artefactId) {
        const artefact = ARTEFACTS_BY_ID[String(artefactId)];
        if (!artefact) return;

        // Re-clicking the artefact that's already open - no-op, avoids
        // firing a duplicate click/navigation_depth/dwell_start
        if (currentArtefactId === artefactId) return;

        // Switching straight from one open artefact to another (grid
        // cards remain reachable behind the overlay until layout.css
        // adds proper stacking) - close the first one properly so its
        // dwell_end still gets logged before the next dwell_start opens
        if (currentArtefactId !== null) {
            await MuseumLogging.closeArtefact(currentArtefactId);
            currentArtefactId = null;
        }

        lastFocusedElement = document.activeElement;
        currentArtefactId  = artefactId;

        modalImage.src = artefact.image_url;
        modalImage.alt = artefact.artefact_title;
        modalTitle.textContent = artefact.artefact_title;
        modalMeta.textContent = `${artefact.theme_name} - ${artefact.subtheme_name}`;
        modalDescription.textContent = artefact.artefact_description;
        modalExternalId.textContent = artefact.external_id ? `Object ID: ${artefact.external_id}` : '';

        if (artefact.source_url) {
            modalSourceLink.href = artefact.source_url;
            modalSourceLink.hidden = false;
        } else {
            modalSourceLink.hidden = true;
        }

        modalOverlay.classList.add('open');
        modalOverlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open'); // scroll-lock hook for layout.css
        modalCloseBtn.focus();

        // Click behaviour, then navigation_depth/revisit/dwell_start
        // (Wireframes.docx > Static Prototype > Interaction Behaviour)
        await MuseumLogging.logClick(artefactId);
        await MuseumLogging.openArtefact(artefactId);
    }

    async function closeModal() {
        if (currentArtefactId === null) return;

        modalOverlay.classList.remove('open');
        modalOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        await MuseumLogging.closeArtefact(currentArtefactId);
        currentArtefactId = null;

        if (lastFocusedElement) {
            lastFocusedElement.focus();
            lastFocusedElement = null;
        }
    }

    document.getElementById('artefactGrid').addEventListener('click', (event) => {
        const card = event.target.closest('.artefact-card');
        if (card) {
            openModal(parseInt(card.dataset.artefactId, 10));
        }
    });

    modalCloseBtn.addEventListener('click', closeModal);
    modalCloseBtnBottom.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (event) => {
        if (event.target === modalOverlay) closeModal(); // click on dimmed backdrop
    });

    // Esc closes the modal (accessibility - Wireframes.docx > Artefact
    // Detail Modal > Design Principles > "Accessible")
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && currentArtefactId !== null) {
            closeModal();
        }
    });

    // Simple focus trap - keeps Tab/Shift+Tab cycling within the modal
    // while it's open, rather than escaping into the grid behind it
    modalBox.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || currentArtefactId === null) return;
        const focusable = modalBox.querySelectorAll('button, a[href]:not([hidden])');
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last  = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // 7-minute timeout -> logs timeout_redirect (version: static) ->
    // redirects to transition1.php (Study Protocol Design.docx >
    // "Timing Structure")
    // Debug-only countdown display (see style.css > .version-footer__timer)
    // - written so it can be deleted along with that CSS rule and this
    // tickCallback once the Phase 9 timing investigation is done.
    const debugCountdownEl = document.getElementById('debugCountdown');
    function renderDebugCountdown(secondsRemaining) {
        if (!debugCountdownEl) return;
        const m = Math.floor(secondsRemaining / 60);
        const s = String(secondsRemaining % 60).padStart(2, '0');
        debugCountdownEl.textContent = `Time remaining: ${m}:${s}`;
    }
    renderDebugCountdown(420);
    console.log(`[timeout debug] static timer started at ${new Date().toLocaleTimeString()}, expected to fire at ${new Date(Date.now() + 420000).toLocaleTimeString()}`);

    MuseumTimeout.start({
        version: 'static',
        redirectTo: <?= json_encode($timeoutRedirect) ?>,
        tickCallback: (secondsRemaining) => {
            renderDebugCountdown(secondsRemaining);
            if (secondsRemaining <= 0) {
                console.log(`[timeout debug] static timer hit zero at ${new Date().toLocaleTimeString()}`);
            }
        },
    });
</script>

</body>
</html>
