// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// js/logging.js
//
// Frontend logging client (Behaviour Logging Map.docx > "How Events
// are Sent to the Backend"):
//   - Every event is sent as a JSON payload via the Fetch API
//   - Sent immediately (no batching) for accurate timestamps and
//     clean event ordering
//   - On failure: retry once, then fall back to a local queue and
//     flush it automatically once a send succeeds again
//
// Exposes a single global object, MuseumLogging, used by the Phase 2
// test page now and by static.php / adaptive.php from Phase 3 onwards.
//
// Paths are absolute from the site root (not relative to the calling
// page) so this file works identically whether it's loaded from
// index.php, static.php/adaptive.php, or a nested test page under
// tests/phase2/.
// =====================================================================

const MuseumLogging = (() => {

    const ENDPOINTS = {
        sessionStart:  '/digitalmuseum/api/session_start.php',
        sessionEnd:    '/digitalmuseum/api/session_end.php',
        event:         '/digitalmuseum/api/log_event.php',
        adaptiveEvent: '/digitalmuseum/api/log_adaptive_event.php',
        timeout:       '/digitalmuseum/api/log_timeout.php',
    };

    const QUEUE_KEY = 'museumLoggingQueue';

    // ---- session state ---------------------------------------------------
    let sessionId = null;
    let navigationDepth = 0;
    const visitedArtefacts = new Set();   // artefact_ids opened this session (revisit detection)
    const dwellStartTimes  = new Map();   // artefact_id -> ms timestamp (dwell duration calc)

    // ---- low-level send: retry once, then queue locally -------------------
    async function send(endpoint, payload, { retry = true } = {}) {
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                throw new Error(data.error || `Request to ${endpoint} failed (${response.status}).`);
            }

            console.debug('[logging]', endpoint, payload, '->', data);
            flushQueue(); // a successful send means we're back online - clear any backlog
            return data;
        } catch (err) {
            if (retry) {
                console.warn('[logging] send failed, retrying once:', endpoint, err.message);
                return send(endpoint, payload, { retry: false });
            }
            console.error('[logging] send failed twice, queued for later:', endpoint, err.message);
            queueEvent(endpoint, payload);
            return null;
        }
    }

    function readQueue() {
        try {
            return JSON.parse(sessionStorage.getItem(QUEUE_KEY)) || [];
        } catch {
            return [];
        }
    }

    function queueEvent(endpoint, payload) {
        const queue = readQueue();
        queue.push({ endpoint, payload, queuedAt: Date.now() });
        sessionStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    }

    async function flushQueue() {
        const queue = readQueue();
        if (queue.length === 0) return;
        sessionStorage.removeItem(QUEUE_KEY); // clear first - failed sends will re-queue themselves
        for (const { endpoint, payload } of queue) {
            await send(endpoint, payload, { retry: false });
        }
    }

    function requireSession() {
        if (!sessionId) {
            throw new Error('[logging] No active session - call MuseumLogging.startSession() first.');
        }
    }

    // ---- session lifecycle -------------------------------------------------

    async function startSession(participantCode, conditionName) {
        const data = await send(ENDPOINTS.sessionStart, {
            participant_code: participantCode,
            condition_name: conditionName,
        });
        if (data) {
            sessionId = data.session_id;
            navigationDepth = 0;
            visitedArtefacts.clear();
            dwellStartTimes.clear();
        }
        return data;
    }

    async function endSession() {
        requireSession();
        return send(ENDPOINTS.sessionEnd, { session_id: sessionId });
    }

    // Adopts a session_id that was already created server-side (e.g. by
    // index.php's researcher setup form, stored in PHP's $_SESSION and
    // embedded into the page). No API call - just local state, so this
    // never creates a duplicate session_log row. Resets the per-task
    // counters (navigation depth, revisit tracking, dwell timers) so
    // each browsing task - static then adaptive - starts with a clean
    // slate, even though both share the same underlying session_id.
    function resumeSession(existingSessionId) {
        sessionId = existingSessionId;
        navigationDepth = 0;
        visitedArtefacts.clear();
        dwellStartTimes.clear();
    }

    // ---- core artefact-level events -----------------------------------------

    function logClick(artefactId) {
        requireSession();
        return send(ENDPOINTS.event, {
            session_id: sessionId,
            artefact_id: artefactId,
            event_type: 'click',
        });
    }

    // Call when an artefact modal opens. Increments navigation depth,
    // detects revisits, and starts the dwell timer - all in one place
    // so the grid/modal code only has to make one call.
    function openArtefact(artefactId) {
        requireSession();

        navigationDepth += 1;
        send(ENDPOINTS.event, {
            session_id: sessionId,
            artefact_id: artefactId,
            event_type: 'navigation_depth',
            navigation_depth: navigationDepth,
        });

        if (visitedArtefacts.has(artefactId)) {
            send(ENDPOINTS.event, {
                session_id: sessionId,
                artefact_id: artefactId,
                event_type: 'revisit',
            });
        }
        visitedArtefacts.add(artefactId);

        dwellStartTimes.set(artefactId, Date.now());
        return send(ENDPOINTS.event, {
            session_id: sessionId,
            artefact_id: artefactId,
            event_type: 'dwell_start',
        });
    }

    // Call when an artefact modal closes. Computes dwell duration (in
    // seconds) from the timestamp recorded by openArtefact().
    function closeArtefact(artefactId) {
        requireSession();

        const startedAt = dwellStartTimes.get(artefactId);
        const dwellDuration = startedAt ? Math.round((Date.now() - startedAt) / 1000) : 0;
        dwellStartTimes.delete(artefactId);

        return send(ENDPOINTS.event, {
            session_id: sessionId,
            artefact_id: artefactId,
            event_type: 'dwell_end',
            dwell_duration: dwellDuration,
        });
    }

    // ---- adaptive + timeout events (wired in from Phase 4/5 onwards) --------

    function logAdaptiveEvent({ eventType, ruleId, suggestionId = null, artefactId = null, triggerConfidence = null }) {
        requireSession();
        return send(ENDPOINTS.adaptiveEvent, {
            session_id: sessionId,
            event_type: eventType,
            rule_id: ruleId,
            suggestion_id: suggestionId,
            artefact_id: artefactId,
            trigger_confidence: triggerConfidence,
        });
    }

    function logTimeout(versionName, autoRedirectFlag = true, timeoutNotes = null) {
        requireSession();
        return send(ENDPOINTS.timeout, {
            session_id: sessionId,
            version_name: versionName,
            auto_redirect_flag: autoRedirectFlag,
            timeout_notes: timeoutNotes,
        });
    }

    // ---- accessors -----------------------------------------------------------

    function getSessionId() {
        return sessionId;
    }

    function getNavigationDepth() {
        return navigationDepth;
    }

    return {
        startSession,
        resumeSession,
        endSession,
        logClick,
        openArtefact,
        closeArtefact,
        logAdaptiveEvent,
        logTimeout,
        getSessionId,
        getNavigationDepth,
    };
})();
