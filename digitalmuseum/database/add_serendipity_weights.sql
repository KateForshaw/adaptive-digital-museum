-- =====================================================================
-- Digital Museum Research Project
-- Phase 5 - Adaptive Prototype
-- add_serendipity_weights.sql
--
-- Adds the missing "weight" key to the 4 compound serendipity rules'
-- trigger_condition JSON (adaptive_rule.rule_id 24-27). Values match
-- Adaptive Logic.docx > "Adaptive Rule Weighting Scoring Model > Signal
-- Weight Tables > Serendipity Weights" (added alongside this file) and
-- database/insert_rules.sql (updated alongside this file too, so a
-- fresh re-seed from scratch already includes these weights - this
-- script is for updating a database that was seeded before that change,
-- without having to DELETE FROM adaptive_rule and re-insert everything,
-- which would fail once any adaptive_suggestion/adaptive_event rows
-- exist (both reference adaptive_rule with ON DELETE RESTRICT).
--
-- Before this fix, includes/adaptive_rules.php's evaluateAllRules()
-- read $condition['weight'] ?? 0.0 - since the compound rules had no
-- "weight" key at all, every one of them silently contributed 0.0 to
-- calculateInterestScore() whenever it fired, diluting the interest
-- score average instead of reinforcing it (found while building the
-- AP3 "dwell > 30s" test - Testing Plans.docx).
--
-- JSON_SET only touches the "weight" key - every other field in each
-- rule's trigger_condition (conditions, priority, priority_multiplier,
-- effect) is left exactly as insert_rules.sql seeded it.
-- =====================================================================

USE digitalmuseum;

UPDATE adaptive_rule
SET trigger_condition = JSON_SET(trigger_condition, '$.weight', 1.9)
WHERE rule_id = 24; -- serendipity_immersive_rare (very_high)

UPDATE adaptive_rule
SET trigger_condition = JSON_SET(trigger_condition, '$.weight', 1.9)
WHERE rule_id = 25; -- serendipity_theme_subtheme_switch (very_high)

UPDATE adaptive_rule
SET trigger_condition = JSON_SET(trigger_condition, '$.weight', 1.4)
WHERE rule_id = 26; -- serendipity_navdepth_revisit (high)

UPDATE adaptive_rule
SET trigger_condition = JSON_SET(trigger_condition, '$.weight', 1.9)
WHERE rule_id = 27; -- serendipity_subtheme_revisit (very_high)
