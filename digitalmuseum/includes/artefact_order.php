<?php
// =====================================================================
// Digital Museum Research Project
// Phase 8 - Artefact Order
// includes/artefact_order.php
//
// Randomises the initial artefact grid order on static.php/adaptive.php
// (Phased Build Plan.docx > Phase 8 > "Implement artefact ordering
// function"). Both pages currently query with `ORDER BY a.artefact_id`,
// which groups artefacts by subtheme insertion block, so the first
// several grid cards a participant sees are always the same subtheme -
// this file replaces that fixed order.
//
// Input/output contract: orderArtefactsForParticipant() takes the exact
// $artefacts array static.php/adaptive.php already build (the fetchAll()
// result of their shared artefact/theme/subtheme JOIN query - each row
// needs an 'artefact_id' and a 'subtheme_name' key) and an int
// participant_id, and returns the same rows reordered. It doesn't touch
// the database itself - static.php/adaptive.php will call this after
// their existing $pdo->query(...)->fetchAll(), keeping
// `ORDER BY a.artefact_id` as a stable base order to shuffle from, then
// passing the result through this function. That wiring is the next
// Phase 8 step, not part of this file.
// =====================================================================

// ---------------------------------------------------------------------
// Seeded pseudo-random number generator
// ---------------------------------------------------------------------

/**
 * A small, self-contained linear congruential generator (LCG).
 *
 * PHP's own mt_rand()/mt_srand() are deliberately avoided here: seeding
 * them would reset the *global* RNG state for the whole request, and
 * PHP gives no way to save/restore whatever that state was beforehand.
 * A dedicated instance-scoped generator keeps this file's randomness
 * fully self-contained and, unlike relying on the engine's internal
 * Mersenne Twister implementation, guarantees the exact same sequence
 * of values for the same seed on any PHP version.
 *
 * Constants are the classic Numerical Recipes LCG parameters.
 */
class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        // 0 is a fixed point of this LCG (stays 0 forever) - fall back
        // to a non-zero seed so participant_id 0 still produces a
        // varied sequence rather than a degenerate all-zero one.
        $this->state = $seed === 0 ? 1 : abs($seed);
    }

    /** Next value as a float in [0, 1). */
    public function nextFloat(): float
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;
        return $this->state / 0x7FFFFFFF;
    }
}

/**
 * Fisher-Yates shuffle driven by a SeededRandom instead of PHP's
 * built-in shuffle() (which, like mt_rand(), isn't seedable per-call).
 * Preserves $items's original array keys' values but not their keys -
 * returns a re-indexed list, matching shuffle()'s own behaviour.
 */
function seededShuffle(array $items, SeededRandom $rng): array
{
    $items = array_values($items);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = (int) floor($rng->nextFloat() * ($i + 1));
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

// ---------------------------------------------------------------------
// Artefact ordering
// ---------------------------------------------------------------------

/**
 * Returns $artefacts reordered for one participant: seeded by
 * participant_id (reproducible across static/adaptive for the same
 * participant - AO2), subthemes interleaved round-robin rather than
 * blocked together (AO3), and both the subtheme visiting order and the
 * artefacts within each subtheme shuffled (AO1 - different participants
 * get different orders).
 *
 * Grouping key is 'subtheme_name' rather than subtheme_id: it's what
 * static.php/adaptive.php's existing query already selects, and
 * subtheme.subtheme_name is unique per subtheme (database/create_tables.sql
 * > uq_subtheme_name), so it's just as safe a grouping key.
 */
function orderArtefactsForParticipant(array $artefacts, int $participantId): array
{
    if (empty($artefacts)) {
        return [];
    }

    $rng = new SeededRandom($participantId);

    // Group by subtheme, preserving each artefact row as-is.
    $groups = [];
    foreach ($artefacts as $artefact) {
        $key = $artefact['subtheme_name'] ?? '';
        $groups[$key][] = $artefact;
    }

    // Shuffle the order in which subthemes are visited, and shuffle the
    // artefacts within each subtheme - both draws come from the same
    // $rng instance, so consuming one advances the sequence for the
    // other (still fully reproducible for a given participant_id, since
    // the draw order here is fixed).
    $subthemeKeys = seededShuffle(array_keys($groups), $rng);
    foreach ($groups as $key => $items) {
        $groups[$key] = seededShuffle($items, $rng);
    }

    // Round-robin interleave: take one artefact from each subtheme in
    // turn, looping until every subtheme's artefacts are exhausted.
    // Subthemes with fewer artefacts simply drop out of later rounds.
    $ordered   = [];
    $pointers  = array_fill_keys($subthemeKeys, 0);
    $remaining = count($artefacts);

    while ($remaining > 0) {
        foreach ($subthemeKeys as $key) {
            $idx = $pointers[$key];
            if (isset($groups[$key][$idx])) {
                $ordered[] = $groups[$key][$idx];
                $pointers[$key]++;
                $remaining--;
            }
        }
    }

    return $ordered;
}
