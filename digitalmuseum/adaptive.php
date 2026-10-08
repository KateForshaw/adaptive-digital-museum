<?php
// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// adaptive.php
//
// Adaptive Prototype Wireframe (Wireframes.docx > "Adaptive Prototype
// Wireframe"). The experimental condition: identical artefact grid and
// detail modal to static.php, plus a right-side adaptive panel with 4
// suggestion slots that update as the participant browses.
//
// This file covers Phased Build Plan.docx > Phase 5 > "Build adaptive
// panel UI" only (the UI shell). It intentionally does NOT wire up the
// heartbeat/event-driven update logic yet - js/heartbeat.js and
// js/adaptive_engine.js are still empty stubs and are the next Phase 5
// steps ("Connect adaptive engine"). This page loads them by <script>
// tag so they can attach to window.MuseumAdaptivePanel below without
// adaptive.php needing to change again.
//
// This page must lead to
// transition1.php if this is their first browsing task, or
// transition2.php if it's their second - which one depends on whether
// the OTHER version has already timed out for this session, not on a
// hardcoded destination. 
// =====================================================================

session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/artefact_order.php';

// ---------------------------------------------------------------------
// Guard: the database itself must be reachable (FB2), and there must be
// an active session (FB1) - mirrors static.php's guard, redirecting to
// fallback.php in both cases.
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

// Anchor for api/trigger_rule.php's freeze-window calculation: the moment
// THIS adaptive task actually started rendering. Needed because when
// adaptive is the FIRST task (condition_name = adaptive_first),
// trigger_rule.php previously had no per-task start time to fall back on
// and used session_log.session_start instead - which is set back on
// index.php, before the participant even reaches this page (researcher
// setup, landing screen, any navigation in between). That gap ate
// straight into the 30s freeze window, so the panel froze noticeably
// before the real 6:30 mark. Only set on first load (not overwritten on
// a mid-task refresh) so a refresh can't push the freeze window back out.
if (!isset($_SESSION['museum_adaptive_task_start'])) {
    $_SESSION['museum_adaptive_task_start'] = date('Y-m-d H:i:s');
}

$stmt = $pdo->prepare(
    'SELECT participant_id, timeout_triggered_static, timeout_triggered_adaptive FROM session_log WHERE session_id = :id'
);
$stmt->execute([':id' => $sessionId]);
$flags = $stmt->fetch();

if (!$flags || (bool)$flags['timeout_triggered_adaptive']) {
    header('Location: fallback.php?reason=wrong_navigation');
    exit;
}

// This session's OTHER version already timed out -> adaptive is the
// second task -> its own timeout must lead to transition2.php
// (questionnaire), not back through transition1.php's "first task"
// logic. Otherwise this is the first task -> transition1.php as usual.
$isSecondTask    = (bool)$flags['timeout_triggered_static'];
$timeoutRedirect = $isSecondTask ? 'transition2.php' : 'transition1.php';
$participantId   = (int)$flags['participant_id'];

// ---------------------------------------------------------------------
// Phase 9 timing fix (2026-08-01): the client-side countdown
// (js/timeout.js) needs the exact same "task start" anchor
// api/trigger_rule.php already uses for its freeze-window calculation,
// so the on-screen countdown/freeze moment matches what the server is
// actually doing, instead of drifting from it on a slow page load.
// Deliberately mirrors that endpoint's own $staticTimeoutAt /
// museum_adaptive_task_start derivation (see its comment block for the
// full rationale) rather than changing that file - this is a read-only
// echo of the same anchor for display purposes, so it cannot change
// anything about freeze timing or suggestion behaviour itself.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT MAX(timeout_timestamp) FROM timeout WHERE session_id = :id AND version_name = 'static'"
);
$stmt->execute([':id' => $sessionId]);
$staticTimeoutAt = $stmt->fetchColumn();

$taskStartTimestamp = $staticTimeoutAt ?: $_SESSION['museum_adaptive_task_start'];
$taskStartMs         = strtotime($taskStartTimestamp) * 1000;

// ---------------------------------------------------------------------
// Fetch all artefacts for the grid (identical query to static.php - the
// adaptive panel picks its 4 suggestions from this same catalogue, keyed
// by artefact_id, so one shared ARTEFACTS_BY_ID lookup on the client
// covers both the grid and the panel), then reorder them for this
// participant (Phase 8 - Artefact Order / includes/artefact_order.php) -
// same participant_id seed as static.php, so a participant who gets
// both tasks sees the same grid order in each (Testing Plans.docx >
// AO2). `ORDER BY a.artefact_id` is kept as a stable base order for the
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
// database/add_artefact_order.sql) - same write as static.php (see
// that file's comment for the full rationale). Since AO2 requires the
// same participant_id to produce the same order on both pages, this
// UPDATE overwrites the value static.php already wrote with an
// identical one when both are loaded in the same session - not a
// conflict, just the same deterministic result written twice.
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
<title>Adaptive Browsing Interface</title>
<link rel="stylesheet" href="/digitalmuseum/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="/digitalmuseum/css/layout.css?v=<?= filemtime(__DIR__ . '/css/layout.css') ?>">
</head>
<body class="adaptive-page">

<header class="version-header">
    <h1>Adaptive Browsing Interface</h1>
    <p class="version-header__subtitle">This version updates artefact suggestions based on your browsing behaviour.</p>
    <p class="version-header__small">Explore freely. Suggestions will appear in the adaptive panel.</p>
</header>

<div class="adaptive-layout">

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

<!--
    Adaptive Panel (Wireframes.docx > "Adaptive Panel - right side").
    Starts neutral - no suggestions (Adaptive Logic.docx > "Panel Reset
    Behaviour > Session Start"). js/adaptive_engine.js fills the 4 slots
    below in place (setting each slot's data-artefact-id/data-suggestion-id/
    data-rule-id and its image/title/subtheme content) and hides
    #adaptivePanelEmpty once the first suggestions arrive - the slot
    structure itself never changes, only its contents (Adaptive Logic.docx
    > "Non-Intrusive Principles > Stable layout").
-->
<aside class="adaptive-panel" id="adaptivePanel" aria-label="Suggested artefacts">
    <h2 class="adaptive-panel__header">Suggested for You</h2>
    <p class="adaptive-panel__subheader">Based on your browsing</p>

    <p class="adaptive-panel__empty" id="adaptivePanelEmpty">Suggestions will appear here as you explore.</p>

    <div class="adaptive-panel__slots">
        <?php for ($i = 0; $i < 4; $i++): ?>
            <button
                type="button"
                class="adaptive-panel__slot adaptive-panel__slot--empty"
                data-slot-index="<?= $i ?>"
                data-artefact-id=""
                data-suggestion-id=""
                data-rule-id=""
                hidden
            >
                <img class="adaptive-panel__slot-image" alt="">
                <span class="adaptive-panel__slot-title"></span>
                <span class="adaptive-panel__slot-subtheme"></span>
            </button>
        <?php endfor; ?>
    </div>
</aside>

</div>

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
    <p>Adaptive Version - Suggestions update based on your behaviour</p>
    <a href="index.php" class="nav-link">Return to Home</a>
    <p class="version-footer__timer" id="debugCountdown" aria-hidden="true"></p>
</footer>

<script src="/digitalmuseum/js/logging.js"></script>
<script src="/digitalmuseum/js/timeout.js"></script>
<script>
    const ARTEFACTS = <?= json_encode($artefacts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const ARTEFACTS_BY_ID = Object.fromEntries(ARTEFACTS.map(a => [String(a.artefact_id), a]));

    MuseumLogging.resumeSession(<?= $sessionId ?>);

    // ---- Modal (identical to static.php - see that file's comments for
    // the full behaviour rationale; duplicated here rather than shared
    // via modal.js/ui.js, which were removed as unused) ----------------

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

        if (currentArtefactId === artefactId) return;

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
        document.body.classList.add('modal-open');
        modalCloseBtn.focus();

        // Click behaviour, then navigation_depth/revisit/dwell_start
        // (Wireframes.docx > Interaction Behaviour) - same core events
        // regardless of whether the click came from the grid or the
        // adaptive panel.
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
        if (event.target === modalOverlay) closeModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && currentArtefactId !== null) {
            closeModal();
        }
    });

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

    // ---- Adaptive panel: click delegation only (Build step here is UI
    // only - see file header). A slot is inert until adaptive_engine.js
    // (next Phase 5 step) populates its data-artefact-id/data-suggestion-id/
    // data-rule-id and un-hides it, so the guard below just no-ops on
    // still-empty slots. -------------------------------------------------

    const adaptivePanel = document.getElementById('adaptivePanel');

    adaptivePanel.addEventListener('click', (event) => {
        const slot = event.target.closest('.adaptive-panel__slot');
        if (!slot || !slot.dataset.artefactId) return; // empty slot - nothing to open

        const artefactId   = parseInt(slot.dataset.artefactId, 10);
        const suggestionId = slot.dataset.suggestionId ? parseInt(slot.dataset.suggestionId, 10) : null;
        const ruleId        = slot.dataset.ruleId ? parseInt(slot.dataset.ruleId, 10) : null;

        openModal(artefactId);

        // Adaptive-only event, logged separately from the core click
        // above (Behaviour Logging Map.docx > "Adaptive Panel Click").
        if (ruleId !== null) {
            MuseumLogging.logAdaptiveEvent({
                eventType:    'panel_click',
                ruleId,
                suggestionId,
                artefactId,
            });
        }
    });

    // Exposed so js/adaptive_engine.js and js/heartbeat.js (Phase 5's
    // next steps) can render suggestions into the panel and check the
    // freeze state, without adaptive.php needing to change again.
    window.MuseumAdaptivePanel = {
        sessionId: <?= $sessionId ?>,
        panelEl: adaptivePanel,
        emptyStateEl: document.getElementById('adaptivePanelEmpty'),
        slotEls: Array.from(adaptivePanel.querySelectorAll('.adaptive-panel__slot')),
        artefactsById: ARTEFACTS_BY_ID,
        isFrozen: () => panelFrozen,
    };

    // ---- Timeout (7 minutes; freeze window flagged for the panel to
    // read via MuseumAdaptivePanel.isFrozen(), per Adaptive Logic.docx >
    // "Timeout Protection - no updates in the final 30 seconds") --------

    let panelFrozen = false;

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
    const TASK_START_MS = <?= json_encode($taskStartMs) ?>; // server-anchored task start (Phase 9 timing fix, 2026-08-01)
    console.log(`[timeout debug] adaptive timer anchored at ${new Date(TASK_START_MS).toLocaleTimeString()} (server-derived task start), expected freeze at ${new Date(TASK_START_MS + 390000).toLocaleTimeString()}, expected redirect at ${new Date(TASK_START_MS + 420000).toLocaleTimeString()}`);

    MuseumTimeout.start({
        version: 'adaptive',
        redirectTo: <?= json_encode($timeoutRedirect) ?>,
        startTimestampMs: TASK_START_MS,
        tickCallback: (secondsRemaining) => {
            renderDebugCountdown(secondsRemaining);
            if (secondsRemaining <= 0) {
                console.log(`[timeout debug] adaptive timer hit zero at ${new Date().toLocaleTimeString()}`);
            }
        },
        freezeCallback: () => {
            panelFrozen = true;
            adaptivePanel.classList.add('adaptive-panel--frozen');
            console.log(`[timeout debug] adaptive panel froze at ${new Date().toLocaleTimeString()}`);
        },
    });
</script>

<!-- Phase 5 next steps: heartbeat (20s) + event-driven adaptive engine.
     Loaded now so they can attach to window.MuseumAdaptivePanel above;
     still empty stubs until the next two Phase 5 files are built. -->
<script src="/digitalmuseum/js/adaptive_engine.js"></script>
<script src="/digitalmuseum/js/heartbeat.js"></script>

</body>
</html>
