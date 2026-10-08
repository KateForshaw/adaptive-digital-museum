// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// js/heartbeat.js
//
// Heartbeat update (Phased Build Plan.docx > Phase 5 > "Connect adaptive
// engine > Heartbeat update - every 20s"; Adaptive Logic.docx > "Panel
// Update Frequency > Heartbeat Update - The panel updates every 20
// seconds during adaptive browsing. This ensures subtle, periodic
// adaptation without overwhelming the user.").
//
// Deliberately thin: a setInterval that calls
// MuseumAdaptiveEngine.triggerUpdate() with no artefact_id every 20
// seconds. All the actual work - calling api/trigger_rule.php, deciding
// whether the response is worth rendering, rendering the panel, logging
// panel_impression - already lives in js/adaptive_engine.js (shared with
// the event-driven modal open/close hooks), so a heartbeat tick and an
// event-driven trigger only ever differ in whether an artefact_id is
// passed.
//
// The freeze window (Adaptive Logic.docx > "Timeout Protection") is not
// handled here - MuseumAdaptiveEngine.triggerUpdate() already checks
// window.MuseumAdaptivePanel.isFrozen() itself before calling the
// server, so a tick that lands during the final 30 seconds simply
// no-ops rather than needing this file to track timing separately.
//
// Depends on: js/adaptive_engine.js (MuseumAdaptiveEngine). Load this
// file after it (adaptive.php already loads adaptive_engine.js first).
// =====================================================================

const MuseumHeartbeat = (() => {

    const HEARTBEAT_INTERVAL_MS = 20000; // 20 seconds

    let timerId = null;

    // Starts the heartbeat. Safe to call more than once - clears any
    // existing interval first, mirroring js/timeout.js's start()/stop()
    // pattern for consistency across the two "runs continuously through
    // the session" modules.
    function start() {
        stop();
        timerId = setInterval(() => {
            MuseumAdaptiveEngine.triggerUpdate();
        }, HEARTBEAT_INTERVAL_MS);
    }

    function stop() {
        if (timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
    }

    return {
        start,
        stop,
    };
})();

// Starts as soon as this file loads - adaptive.php loads it last, after
// window.MuseumAdaptivePanel and MuseumAdaptiveEngine already exist, and
// after MuseumTimeout.start() has already begun the 7-minute countdown.
MuseumHeartbeat.start();
