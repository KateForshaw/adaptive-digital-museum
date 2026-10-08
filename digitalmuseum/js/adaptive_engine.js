// =====================================================================
// Digital Museum Research Project
// Phase 5 - Adaptive Prototype
// js/adaptive_engine.js
//
// Event-driven adaptive updates (Phased Build Plan.docx > Phase 5 >
// "Connect adaptive engine > Event-driven updates - modal close,
// revisit, switching, deep dive"; Adaptive Logic.docx > "Trigger
// Timing" / "Panel Update Frequency").
//
// Only two DOM-level hooks are needed to cover every trigger moment in
// Adaptive Logic.docx's "Trigger Timing" list:
//   - modal open  -> if this is a revisit, trigger immediately
//                    ("Revisit Update: updates instantly")
//   - modal close -> always trigger ("Modal-Close Update: evaluates all
//                    signals and may update")
// Every other moment in that list - dwell threshold, navigation depth
// threshold, switching threshold, serendipity - isn't a separate DOM
// event: api/trigger_rule.php re-derives ALL session-level signals
// (navigation_depth, theme/subtheme switches, branching_factor) plus,
// when an artefact_id is supplied, that artefact's dwell/revisit/
// popularity signals, and evaluates all 27 rules against whichever of
// those apply - every time it's called. So hooking modal open/close is
// enough; there's nothing extra to wire up per rule category, and the
// server (not this file) decides whether any of it actually clears the
// bar for a panel update.
//
// Hooks in by wrapping MuseumLogging.openArtefact/closeArtefact rather
// than editing adaptive.php's inline modal script - keeps this file
// fully self-contained, and keeps static.php (which never loads this
// file) completely unaffected.
//
// Depends on: js/logging.js (MuseumLogging) and window.MuseumAdaptivePanel
// (set up by adaptive.php's inline script - panel/slot DOM references,
// sessionId, isFrozen()). Load this file after both.
// =====================================================================

const MuseumAdaptiveEngine = (() => {

    const TRIGGER_ENDPOINT = '/digitalmuseum/api/trigger_rule.php';

    let updateInFlight = false;

    // Local mirror of "has this artefact been opened this task" - only
    // used to decide WHEN to call the engine (i.e. "was this open a
    // revisit"). MuseumLogging tracks the same thing internally for its
    // own revisit-event logging, but keeps it private to its closure, so
    // this is a second, independent tracker rather than reaching into
    // logging.js's internals. The server never trusts this - api/trigger_rule.php
    // re-derives the real revisit_count from event_log itself.
    const openedThisTask = new Set();

    // ---- Panel rendering ---------------------------------------------

    function renderSuggestions(payload) {
        const panel = window.MuseumAdaptivePanel;
        if (!panel) return;

        panel.emptyStateEl.hidden = true;

        payload.suggestions.forEach((suggestion) => {
            const slot = panel.slotEls[suggestion.panel_position];
            if (!slot) return;

            slot.dataset.artefactId   = suggestion.artefact_id;
            slot.dataset.suggestionId = suggestion.suggestion_id;
            slot.dataset.ruleId       = payload.rule_id;

            const image    = slot.querySelector('.adaptive-panel__slot-image');
            const title    = slot.querySelector('.adaptive-panel__slot-title');
            const subtheme = slot.querySelector('.adaptive-panel__slot-subtheme');

            image.src = suggestion.image_url;
            image.alt = suggestion.artefact_title;
            title.textContent    = suggestion.artefact_title;
            subtheme.textContent = suggestion.subtheme_name;

            slot.hidden = false;
            slot.classList.remove('adaptive-panel__slot--empty');

            // Soft-transition hook only (Adaptive Logic.docx > "Non-Intrusive
            // Principles > Soft transitions" - thumbnails fade gently, no
            // flashing/bouncing/sliding). css/style.css applies the actual
            // fade via this class; it's just toggled briefly here.
            slot.classList.add('adaptive-panel__slot--updating');
            setTimeout(() => slot.classList.remove('adaptive-panel__slot--updating'), 600);
        });

        // Panel Impression - logged once per suggestion actually rendered
        // (Behaviour Logging Map.docx > "Adaptive Panel Impression"),
        // separate from the adaptive_trigger event api/trigger_rule.php
        // already logged server-side for the rule firing itself.
        payload.suggestions.forEach((suggestion) => {
            MuseumLogging.logAdaptiveEvent({
                eventType:          'panel_impression',
                ruleId:             payload.rule_id,
                suggestionId:       suggestion.suggestion_id,
                artefactId:         suggestion.artefact_id,
                triggerConfidence:  payload.interest_score,
            });
        });
    }

    // ---- Engine call ---------------------------------------------------

    /**
     * Calls api/trigger_rule.php and renders the panel if it returns an
     * update. Exposed (see return statement below) so js/heartbeat.js
     * reuses this exact call/render pipeline for its 20s ticks, passing
     * no artefactId - the only difference between a heartbeat update and
     * an event-driven one.
     */
    async function triggerUpdate(artefactId = null) {
        const panel = window.MuseumAdaptivePanel;
        if (!panel || panel.isFrozen()) return; // Timeout Protection - no updates in the final 30s

        if (updateInFlight) return; // avoid overlapping trigger_rule.php calls racing each other
        updateInFlight = true;

        try {
            const response = await fetch(TRIGGER_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: panel.sessionId,
                    artefact_id: artefactId,
                }),
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                console.warn('[adaptive_engine] trigger_rule.php returned an error:', data.error || response.status);
                return;
            }

            if (data.updated) {
                renderSuggestions(data);
            }
            // data.updated === false (frozen, or no qualifying signal) is a
            // normal, expected outcome, not an error - the panel simply
            // stays as it is (Adaptive Logic.docx > "Low-Priority Triggers
            // - does not trigger update").
        } catch (err) {
            console.warn('[adaptive_engine] trigger_rule.php request failed:', err.message);
        } finally {
            updateInFlight = false;
        }
    }

    // ---- Hooks: wrap MuseumLogging's artefact open/close ----------------

    const originalOpenArtefact = MuseumLogging.openArtefact.bind(MuseumLogging);
    MuseumLogging.openArtefact = function (artefactId) {
        const isRevisit = openedThisTask.has(artefactId);
        openedThisTask.add(artefactId);

        const result = originalOpenArtefact(artefactId);

        if (isRevisit) {
            triggerUpdate(artefactId); // Revisit Update - "updates instantly"
        }
        return result;
    };

    const originalCloseArtefact = MuseumLogging.closeArtefact.bind(MuseumLogging);
    MuseumLogging.closeArtefact = function (artefactId) {
        const result = originalCloseArtefact(artefactId);
        triggerUpdate(artefactId); // Modal-Close Update
        return result;
    };

    return {
        triggerUpdate,
    };
})();
