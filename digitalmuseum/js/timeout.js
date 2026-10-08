// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// js/timeout.js
//
// Client-side 7-minute browsing session timer (Study Protocol
// Design.docx > "Timing Structure"):
//   - Each browsing session (static or adaptive) ends at 7 minutes
//   - The system redirects automatically to the correct transition page
//   - The timeout is logged in the timeout table (via log_timeout.php)
//
// Depends on MuseumLogging (js/logging.js) - load logging.js first.
//
// This module only owns the countdown + firing the timeout. It does
// not decide which transition page to redirect to - that depends on
// counterbalancing order (Study Protocol Design.docx > "Condition A" /
// "Condition B"), which is a Phase 3 page-flow decision. The caller
// passes the redirect URL in when it starts the timer.
//
// Phase 9 timing fix (2026-08-01): secondsRemaining used to be a plain
// decrement-by-1-per-tick counter. That drifts behind real elapsed time
// whenever a tick's setInterval callback fires late - worse under
// heavier main-thread load (e.g. adaptive's fetch/render work on
// slower or unstable wifi), which is why the on-screen countdown was
// observed falling further behind a stopwatch on worse connections.
// Fixed by recomputing secondsRemaining from a fixed anchor timestamp
// (Date.now() - anchorMs) on every tick instead of decrementing - this
// is self-correcting even if a tick fires late, since it always
// measures against real wall-clock time rather than counting ticks.
// start() additionally accepts an optional server-supplied
// startTimestampMs (see adaptive.php) so the client's displayed
// countdown/freeze moment matches api/trigger_rule.php's own
// server-side elapsed-time calculation, rather than the client's local
// "whenever this script happened to start running" moment - which can
// itself lag the true task start by a few seconds on a slow initial
// page load. This file only changes what is DISPLAYED and when the
// freeze/timeout callbacks FIRE on the client - it does not change
// api/trigger_rule.php's own freeze decision, which was already
// computed server-side from a real timestamp and is unaffected by any
// of this.
// =====================================================================

const MuseumTimeout = (() => {

    const SESSION_DURATION_SECONDS = 420; // 7 minutes
    const FREEZE_WINDOW_SECONDS    = 30;  // final 30s - adaptive panel stops updating (Adaptive Logic.docx)

    let timerId            = null;
    let anchorMs            = null; // wall-clock timestamp (ms) the countdown is measured from
    let durationSecondsVal = SESSION_DURATION_SECONDS;
    let secondsRemaining   = SESSION_DURATION_SECONDS;
    let versionName         = null;
    let redirectUrl          = null;
    let onTick              = null;
    let onFreezeWindow      = null;
    let onTimeout            = null;
    let hasFired            = false;
    let hasFrozen            = false;

    // Starts (or restarts) the countdown.
    //   version           - 'static' | 'adaptive' (passed straight to log_timeout.php)
    //   redirectTo        - URL of the transition page to redirect to on timeout
    //   tickCallback      - optional, called every second with secondsRemaining (e.g. to update a display)
    //   freezeCallback    - optional, called once when the final 30s window begins
    //   timeoutCallback   - optional, called once the timeout has been logged, before redirecting
    //   durationSeconds   - optional override, defaults to 420 (useful for manual testing)
    //   startTimestampMs  - optional, defaults to Date.now(). Pass a server-supplied
    //                       epoch-ms timestamp (see adaptive.php) so this countdown is
    //                       anchored to the same moment the server treats as this
    //                       task's start, rather than to whenever this script happens
    //                       to run.
    function start({
        version,
        redirectTo,
        tickCallback = null,
        freezeCallback = null,
        timeoutCallback = null,
        durationSeconds = SESSION_DURATION_SECONDS,
        startTimestampMs = null,
    }) {
        stop(); // clear any existing timer before starting a new one

        versionName        = version;
        redirectUrl          = redirectTo;
        onTick              = tickCallback;
        onFreezeWindow      = freezeCallback;
        onTimeout            = timeoutCallback;
        durationSecondsVal = durationSeconds;
        anchorMs            = startTimestampMs !== null ? startTimestampMs : Date.now();
        hasFired            = false;
        hasFrozen            = false;

        secondsRemaining = computeSecondsRemaining();
        if (typeof onTick === 'function') {
            onTick(secondsRemaining); // render the true starting value immediately, rather than waiting a full second for the first tick
        }

        timerId = setInterval(tick, 1000);
    }

    // Recomputed from the anchor timestamp on every tick (rather than
    // decremented) so a late-firing tick self-corrects instead of
    // compounding drift - see file header.
    function computeSecondsRemaining() {
        const elapsedSeconds = Math.floor((Date.now() - anchorMs) / 1000);
        return Math.max(0, durationSecondsVal - elapsedSeconds);
    }

    function tick() {
        secondsRemaining = computeSecondsRemaining();

        if (typeof onTick === 'function') {
            onTick(secondsRemaining);
        }

        // Threshold crossing rather than an exact-value match - a tick
        // that fires late can otherwise skip straight past the single
        // second where secondsRemaining === FREEZE_WINDOW_SECONDS and
        // never fire this at all. hasFrozen guards against firing again
        // on every subsequent tick once inside the window.
        if (!hasFrozen && secondsRemaining <= FREEZE_WINDOW_SECONDS) {
            hasFrozen = true;
            if (typeof onFreezeWindow === 'function') {
                onFreezeWindow();
            }
        }

        if (secondsRemaining <= 0) {
            fireTimeout();
        }
    }

    // Logs the timeout event and redirects. Exposed publicly so the
    // Phase 2 test page can trigger a timeout manually (Testing
    // Plans.docx > BL6 "Trigger timeout manually") without waiting the
    // full 7 minutes.
    async function fireTimeout() {
        if (hasFired) return; // guard against firing twice (e.g. manual trigger + natural countdown)
        hasFired = true;
        stop();

        try {
            await MuseumLogging.logTimeout(versionName, true);
        } catch (err) {
            console.error('[timeout] failed to log timeout event:', err);
        }

        if (typeof onTimeout === 'function') {
            onTimeout();
        }

        if (redirectUrl) {
            window.location.href = redirectUrl;
        }
    }

    function stop() {
        if (timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
    }

    function getSecondsRemaining() {
        return secondsRemaining;
    }

    return {
        start,
        stop,
        fireTimeout,
        getSecondsRemaining,
    };
})();
