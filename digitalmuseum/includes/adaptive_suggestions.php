<?php
// =====================================================================
// Digital Museum Research Project
// Phase 4 - Adaptive Logic Engine
// includes/adaptive_suggestions.php
//
// Suggestion selection (Phased Build Plan.docx > Phase 4 > "Implement
// suggestion selection": Reinforcement mode, Deep-dive mode, Exploratory
// drift, Serendipity mode, Balanced browsing).
//
// Where the previous two files stop at "which rules fired" and "what
// interest score does that add up to", this file turns that into an
// actual set of 4 artefacts, per Adaptive Logic.docx's Ranking Process:
// pick a panel composition mode, fetch candidates for each slot type,
// exclude duplicates and anything shown in the last 30 seconds, and
// return exactly 4 artefacts (Adaptive Logic.docx > "Number of Artefacts
// Displayed").
//
// Requires adaptive_rules.php for resolveHighestPriorityRule() - mode
// selection reuses the same serendipity-first/priority-rank logic
// already established there rather than re-implementing it.
//
// Implementation note on "Ranking Logic": the *one* interest score from includes/adaptive_scoring.php
// (representing "how strongly is the user engaged right now") picks a
// composition mode, and each slot in that mode is filled with a
// targeted SQL query (same subtheme / different subtheme / low global
// popularity / random) rather than scoring and sorting all 60 artefacts
// on every update. Same rules, same diversity/duplicate guarantees,
// far less work per panel update.
//
// Explicitly NOT handled here (belongs to the Phase 5 caller that runs
// continuously through a session, not a single stateless function call):
// Serendipity Frequency Control (Adaptive Logic.docx > "max 1 serendipity
// update per 20s, min 10s between triggers") and Panel Reset Behaviour's
// "resets to balanced mode 40s after strong serendipity" - both need to
// remember timing across multiple calls.
// =====================================================================

require_once __DIR__ . '/adaptive_rules.php';

// ---------------------------------------------------------------------
// Panel composition (Adaptive Logic.docx > "Panel Composition Rules")
// ---------------------------------------------------------------------

const PANEL_COMPOSITION = [
    'reinforcement'     => ['related' => 3, 'neutral' => 1],
    'deep_dive'         => ['deeper' => 2, 'related' => 1, 'neutral' => 1],
    'exploratory_drift' => ['related' => 2, 'cross_subtheme' => 2],
    'serendipity'       => ['serendipity' => 2, 'related' => 1, 'neutral' => 1],
    'balanced_browsing' => ['related' => 1, 'deeper' => 1, 'cross_subtheme' => 1, 'serendipity' => 1],
];

// ---------------------------------------------------------------------
// Mode selection
// ---------------------------------------------------------------------

/**
 * Picks which of the 5 modes above should drive this panel update.
 *
 *   1. A score past the serendipity threshold (adaptive_scoring.php >
 *      meetsSerendipityThreshold()) wins outright, regardless of which
 *      individual rule fired - Adaptive Logic.docx > "Cumulative Score
 *      Trigger".
 *   2. Otherwise, the single highest-priority fired rule (same
 *      resolveHighestPriorityRule() used for conflict resolution in
 *      adaptive_rules.php) decides. A serendipity-named rule (rule_name
 *      starting "serendipity_" - the 4 compound rules, insert_rules.sql
 *      24-27) always means serendipity mode, matching
 *      resolveHighestPriorityRule()'s own "serendipity rules override all
 *      others" check - not every compound rule's effect string happens to
 *      contain a recognisable keyword (serendipity_immersive_rare's is
 *      "show_rare_unique_artefacts", which the effect-string matching
 *      below wouldn't otherwise catch, silently falling through to
 *      balanced_browsing instead - found while testing AP3 "dwell > 30s",
 *      where this rule and dwell_immersive routinely fire together).
 *      Every other rule decides by matching its "effect" string against
 *      the mode it describes (e.g. "trigger_deep_dive_suggestions" ->
 *      deep_dive).
 *   3. If nothing fired, or what fired was only a cumulative/low-priority
 *      signal (e.g. dwell_light's add_weak_interest_weight_subtheme,
 *      which Adaptive Logic.docx explicitly says "does not trigger
 *      update" on its own), fall back to balanced_browsing - the
 *      one-of-everything composition, and also what a session opens
 *      with (Adaptive Logic.docx > "Session Start: Panel begins
 *      neutral").
 */
function selectSuggestionMode(array $firedRules, array $scoreResult): string
{
    if (!empty($scoreResult['serendipity_triggered'])) {
        return 'serendipity';
    }

    $topRule = resolveHighestPriorityRule($firedRules);
    if ($topRule === null) {
        return 'balanced_browsing';
    }

    if (strpos((string)($topRule['rule_name'] ?? ''), 'serendipity_') === 0) {
        return 'serendipity';
    }

    $effect = (string)($topRule['effect'] ?? '');

    if (strpos($effect, 'serendipity') !== false || strpos($effect, 'unexpected') !== false) {
        return 'serendipity';
    }
    if (strpos($effect, 'deep_dive') !== false || strpos($effect, 'deeper') !== false) {
        return 'deep_dive';
    }
    if (strpos($effect, 'cross_theme') !== false || strpos($effect, 'cross_subtheme') !== false
        || strpos($effect, 'bridging') !== false || strpos($effect, 'thematic_clusters') !== false
        || strpos($effect, 'related_subthemes') !== false
    ) {
        return 'exploratory_drift';
    }
    if (strpos($effect, 'reinforce') !== false || strpos($effect, 'closely_related') !== false) {
        return 'reinforcement';
    }

    return 'balanced_browsing';
}

// ---------------------------------------------------------------------
// Session context (what has this session already seen?)
// ---------------------------------------------------------------------

/**
 * Derives the working context slot selection needs: every artefact
 * opened this session (to avoid pointless repeats and to identify
 * "deeper" candidates), which subtheme/theme the participant is
 * currently most engaged with (most-clicked so far this session), and
 * which artefacts were suggested in the last 30 seconds (Adaptive
 * Logic.docx > Ranking Process > "no artefacts already shown in the
 * last 30 seconds").
 */
function getSessionContext(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare("
        SELECT a.artefact_id, a.theme_id, a.subtheme_id
        FROM event_log e
        JOIN artefact a ON a.artefact_id = e.artefact_id
        WHERE e.session_id = :session_id AND e.event_type = 'click'
    ");
    $stmt->execute([':session_id' => $sessionId]);

    $visitedArtefactIds = [];
    $subthemeCounts     = [];
    $themeCounts        = [];
    foreach ($stmt->fetchAll() as $row) {
        $visitedArtefactIds[(int)$row['artefact_id']] = true;
        $subthemeId = (int)$row['subtheme_id'];
        $themeId    = (int)$row['theme_id'];
        $subthemeCounts[$subthemeId] = ($subthemeCounts[$subthemeId] ?? 0) + 1;
        $themeCounts[$themeId]       = ($themeCounts[$themeId] ?? 0) + 1;
    }

    arsort($subthemeCounts);
    arsort($themeCounts);

    $dominantSubthemeId = array_key_first($subthemeCounts);
    $dominantThemeId    = array_key_first($themeCounts);

    $stmt = $pdo->prepare("
        SELECT artefact_id
        FROM adaptive_suggestion
        WHERE session_id = :session_id
          AND suggestion_timestamp >= (NOW() - INTERVAL 30 SECOND)
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $recentlyShownIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return [
        'visited_artefact_ids' => array_keys($visitedArtefactIds),
        'dominant_subtheme_id' => $dominantSubthemeId !== null ? (int)$dominantSubthemeId : null,
        'dominant_theme_id'    => $dominantThemeId !== null ? (int)$dominantThemeId : null,
        'recently_shown_ids'   => $recentlyShownIds,
    ];
}

// ---------------------------------------------------------------------
// Candidate fetching, one function per slot type
// ---------------------------------------------------------------------

/**
 * Shared "N random artefacts matching $whereClause, excluding
 * $excludeIds" query used by every slot type below except serendipity
 * (which additionally ranks by popularity - see fetchSerendipityArtefacts).
 */
function fetchArtefacts(PDO $pdo, string $whereClause, array $params, array $excludeIds, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $exclude = array_values(array_unique(array_map('intval', $excludeIds)));
    $excludeSql = '';
    foreach ($exclude as $i => $id) {
        $key = ":exclude{$i}";
        $params[$key] = $id;
        $excludeSql .= ($excludeSql === '' ? "AND artefact_id NOT IN ({$key}" : ", {$key}");
    }
    if ($excludeSql !== '') {
        $excludeSql .= ')';
    }

    $limit = max(0, (int)$limit); // never trust $limit into raw SQL as anything but an int
    $sql = "
        SELECT artefact_id, theme_id, subtheme_id
        FROM artefact
        WHERE {$whereClause} {$excludeSql}
        ORDER BY RAND()
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * "Related" - same subtheme the participant is currently engaged with,
 * revisits allowed (reinforcement is allowed to show something they've
 * already opened again).
 */
function fetchRelatedArtefacts(PDO $pdo, ?int $subthemeId, array $excludeIds, int $limit): array
{
    if ($subthemeId === null) {
        return [];
    }
    return fetchArtefacts($pdo, 'subtheme_id = :subtheme_id', [':subtheme_id' => $subthemeId], $excludeIds, $limit);
}

/**
 * "Deeper" - same subtheme, but only artefacts not yet opened this
 * session (pushing further into an interest the participant has
 * already shown, rather than repeating it).
 */
function fetchDeeperArtefacts(PDO $pdo, ?int $subthemeId, array $visitedArtefactIds, array $excludeIds, int $limit): array
{
    if ($subthemeId === null) {
        return [];
    }
    return fetchArtefacts(
        $pdo,
        'subtheme_id = :subtheme_id',
        [':subtheme_id' => $subthemeId],
        array_merge($excludeIds, $visitedArtefactIds),
        $limit
    );
}

/**
 * "Cross-subtheme" - a different subtheme within the theme the
 * participant is currently in (Exploratory drift's "2 related + 2
 * cross-subtheme" composition). Deliberately narrower than a full
 * cross-theme jump, which is reserved for serendipity below.
 */
function fetchCrossSubthemeArtefacts(PDO $pdo, ?int $themeId, ?int $excludeSubthemeId, array $excludeIds, int $limit): array
{
    if ($themeId === null) {
        return [];
    }
    $where  = 'theme_id = :theme_id';
    $params = [':theme_id' => $themeId];
    if ($excludeSubthemeId !== null) {
        $where .= ' AND subtheme_id != :exclude_subtheme_id';
        $params[':exclude_subtheme_id'] = $excludeSubthemeId;
    }
    return fetchArtefacts($pdo, $where, $params, $excludeIds, $limit);
}

/**
 * "Serendipity" (Adaptive Logic.docx > "Serendipity Artefact
 * Selection"): low-popularity artefacts from subthemes the participant
 * hasn't explored this session - i.e. rare/distant/cross-theme by
 * construction, since excluding every visited subtheme naturally
 * favours the opposite theme once one theme has been explored.
 * Popularity is approximated as global click count across all
 * sessions (event_log), ascending - there's no dedicated popularity
 * column, and total clicks is a reasonable, always-available proxy.
 */
function fetchSerendipityArtefacts(PDO $pdo, array $excludeSubthemeIds, array $excludeIds, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $params = [];

    $exclude = array_values(array_unique(array_map('intval', $excludeIds)));
    $excludeSql = '';
    foreach ($exclude as $i => $id) {
        $key = ":exclude{$i}";
        $params[$key] = $id;
        $excludeSql .= ($excludeSql === '' ? "AND a.artefact_id NOT IN ({$key}" : ", {$key}");
    }
    if ($excludeSql !== '') {
        $excludeSql .= ')';
    }

    $subthemeIds = array_values(array_unique(array_map('intval', $excludeSubthemeIds)));
    $subthemeExcludeSql = '';
    foreach ($subthemeIds as $i => $id) {
        $key = ":subtheme{$i}";
        $params[$key] = $id;
        $subthemeExcludeSql .= ($subthemeExcludeSql === '' ? "AND a.subtheme_id NOT IN ({$key}" : ", {$key}");
    }
    if ($subthemeExcludeSql !== '') {
        $subthemeExcludeSql .= ')';
    }

    $limit = max(0, (int)$limit);
    $sql = "
        SELECT a.artefact_id, a.theme_id, a.subtheme_id, COUNT(e.event_id) AS click_count
        FROM artefact a
        LEFT JOIN event_log e
               ON e.artefact_id = a.artefact_id AND e.event_type = 'click'
        WHERE 1=1 {$excludeSql} {$subthemeExcludeSql}
        GROUP BY a.artefact_id, a.theme_id, a.subtheme_id
        ORDER BY click_count ASC, RAND()
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * "Neutral" - no particular targeting, just something not already
 * excluded. Also the fallback used to top up a slot that came up short.
 */
function fetchNeutralArtefacts(PDO $pdo, array $excludeIds, int $limit): array
{
    return fetchArtefacts($pdo, '1=1', [], $excludeIds, $limit);
}

// ---------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------

/**
 * Fills every slot in $mode's composition, tracking chosen artefact_ids
 * as it goes so later slots in the same build can't re-pick an artefact
 * an earlier slot already chose (on top of excluding session_context's
 * recently-shown list) - Adaptive Logic.docx > Ranking Process >
 * "Ensure no duplicates". Tops up with neutral picks if any slot came
 * up short, so this always returns exactly 4 artefacts (or fewer only
 * if the whole 60-artefact catalogue is somehow exhausted).
 */
function buildSuggestions(PDO $pdo, int $sessionId, string $mode, ?float $interestScore = null): array
{
    $composition = PANEL_COMPOSITION[$mode] ?? PANEL_COMPOSITION['balanced_browsing'];
    $context     = getSessionContext($pdo, $sessionId);

    $excludeIds  = $context['recently_shown_ids'];
    $suggestions = [];

    $addSlot = function (string $slotType, array $rows) use (&$suggestions, &$excludeIds, $interestScore): void {
        foreach ($rows as $row) {
            $suggestions[] = [
                'artefact_id'    => (int)$row['artefact_id'],
                'theme_id'       => (int)$row['theme_id'],
                'subtheme_id'    => (int)$row['subtheme_id'],
                'slot_type'      => $slotType,
                'interest_score' => $interestScore,
            ];
            $excludeIds[] = (int)$row['artefact_id'];
        }
    };

    if (!empty($composition['related'])) {
        $addSlot('related', fetchRelatedArtefacts(
            $pdo, $context['dominant_subtheme_id'], $excludeIds, $composition['related']
        ));
    }
    if (!empty($composition['deeper'])) {
        $addSlot('deeper', fetchDeeperArtefacts(
            $pdo, $context['dominant_subtheme_id'], $context['visited_artefact_ids'], $excludeIds, $composition['deeper']
        ));
    }
    if (!empty($composition['cross_subtheme'])) {
        $addSlot('cross_subtheme', fetchCrossSubthemeArtefacts(
            $pdo, $context['dominant_theme_id'], $context['dominant_subtheme_id'], $excludeIds, $composition['cross_subtheme']
        ));
    }
    if (!empty($composition['serendipity'])) {
        $exploredSubthemeIds = $context['dominant_subtheme_id'] !== null ? [$context['dominant_subtheme_id']] : [];
        $addSlot('serendipity', fetchSerendipityArtefacts(
            $pdo, $exploredSubthemeIds, $excludeIds, $composition['serendipity']
        ));
    }
    if (!empty($composition['neutral'])) {
        $addSlot('neutral', fetchNeutralArtefacts($pdo, $excludeIds, $composition['neutral']));
    }

    // Graceful degradation - always try to reach 4 (Adaptive Logic.docx
    // > "Number of Artefacts Displayed"), even if a targeted slot above
    // came up short (e.g. a subtheme nearly exhausted late in a session).
    $shortfall = 4 - count($suggestions);
    if ($shortfall > 0) {
        $addSlot('neutral', fetchNeutralArtefacts($pdo, $excludeIds, $shortfall));
    }

    return array_slice($suggestions, 0, 4);
}

/**
 * The single entry point everything else in this project should call:
 * fired rules + score in, mode picked, 4 artefacts out. What the Phase 4
 * test harness (Testing Plans.docx > AE5 "suggestion diversity") and,
 * from Phase 5 onwards, api/trigger_rule.php will actually use.
 */
function generateSuggestions(PDO $pdo, int $sessionId, array $firedRules, array $scoreResult): array
{
    $mode = selectSuggestionMode($firedRules, $scoreResult);
    return buildSuggestions($pdo, $sessionId, $mode, $scoreResult['interest_score'] ?? null);
}
