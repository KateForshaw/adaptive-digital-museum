-- =====================================================================
-- Digital Museum Research Project
-- Phase 1 - Database Foundation
-- insert_rules.sql
--
-- Populates adaptive_rule with the 27 behavioural rules defined in the
-- Adaptive Rule Specification (dwell, revisit, navigation depth, theme
-- switching, subtheme switching, branching factor, and serendipity).
-- Source: /System Design/Adaptive Logic.docx
-- =====================================================================

USE digitalmuseum;

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM adaptive_rule;
ALTER TABLE adaptive_rule AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- ADAPTIVE_RULE  (27 rows)
-- =====================================================================

INSERT INTO adaptive_rule
    (rule_id, rule_name, rule_description, trigger_condition, active_flag)
VALUES

    -- Dwell-Based Adaptive Rules  (Adaptive Logic.docx: 'Dwell-Based Adaptive Rules')
    (1, 'dwell_light', 'Light dwell (3-8s) adds a weak interest weight to the artefact''s subtheme. No panel update yet; contributes to the cumulative interest score.', '{"signal": "dwell_time", "category": "light_dwell", "min_seconds": 3, "max_seconds": 8, "weight": 0.4, "priority": "low", "priority_multiplier": 1.0, "effect": "add_weak_interest_weight_subtheme"}', TRUE),
    (2, 'dwell_engaged', 'Engaged dwell (8-15s) adds a moderate interest weight to the artefact''s subtheme. Panel may update once the cumulative interest score passes threshold.', '{"signal": "dwell_time", "category": "engaged_dwell", "min_seconds": 8, "max_seconds": 15, "weight": 0.8, "priority": "medium", "priority_multiplier": 1.2, "effect": "add_moderate_interest_weight_subtheme"}', TRUE),
    (3, 'dwell_deep', 'Deep dwell (15-30s) triggers theme reinforcement. Panel updates with 1-2 artefacts from the same subtheme.', '{"signal": "dwell_time", "category": "deep_dwell", "min_seconds": 15, "max_seconds": 30, "weight": 1.2, "priority": "high", "priority_multiplier": 1.5, "effect": "trigger_theme_reinforcement"}', TRUE),
    (4, 'dwell_immersive', 'Immersive dwell (>30s) triggers deep-dive or serendipity mode. Panel shows deeper subtheme items plus 1 serendipity artefact.', '{"signal": "dwell_time", "category": "immersive_dwell", "min_seconds": 30, "max_seconds": null, "weight": 1.8, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_deep_dive_or_serendipity"}', TRUE),

    -- Revisit-Based Adaptive Rules  (Adaptive Logic.docx: 'Revisit-Based Adaptive Rules')
    (5, 'revisit_single', 'A single revisit (2 views of the same artefact) reinforces the subtheme. Panel shows 1 related artefact.', '{"signal": "revisit_count", "category": "single_revisit", "min": 2, "max": 2, "weight": 0.7, "priority": "medium", "priority_multiplier": 1.2, "effect": "reinforce_subtheme"}', TRUE),
    (6, 'revisit_multiple', 'Multiple revisits (3+ views) signal strong attraction. Panel shows 2-3 artefacts from the same subtheme.', '{"signal": "revisit_count", "category": "multiple_revisits", "min": 3, "max": null, "weight": 1.3, "priority": "high", "priority_multiplier": 1.5, "effect": "show_closely_related_artefacts"}', TRUE),
    (7, 'revisit_rapid', 'A revisit occurring within <20 seconds signals a curiosity spike and triggers a serendipity suggestion. Panel shows 1 unexpected artefact from a different subtheme.', '{"signal": "revisit_interval", "category": "rapid_revisit", "max_seconds": 20, "weight": 1.8, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_serendipity_suggestion"}', TRUE),
    (8, 'revisit_delayed', 'A revisit occurring after >1 minute signals renewed interest and suggests deeper thematic items. Panel shows deeper items within the same subtheme.', '{"signal": "revisit_interval", "category": "delayed_revisit", "min_seconds": 60, "weight": 1.0, "priority": "medium", "priority_multiplier": 1.2, "effect": "suggest_deeper_thematic_items"}', TRUE),

    -- Navigation Depth Adaptive Rules  (Adaptive Logic.docx: 'Navigation Depth Adaptive Rules')
    (9, 'navdepth_moderate', 'Moderate navigation depth (4-7 artefacts) suggests related items. Panel shows 1 artefact from recently explored subthemes.', '{"signal": "navigation_depth", "category": "moderate", "min": 4, "max": 7, "weight": 0.7, "priority": "medium", "priority_multiplier": 1.2, "effect": "suggest_related_items"}', TRUE),
    (10, 'navdepth_deep', 'Deep navigation (8-12 artefacts) triggers deep-dive suggestions. Panel shows deeper items from the dominant subtheme.', '{"signal": "navigation_depth", "category": "deep", "min": 8, "max": 12, "weight": 1.3, "priority": "high", "priority_multiplier": 1.5, "effect": "trigger_deep_dive_suggestions"}', TRUE),
    (11, 'navdepth_verydeep', 'Very deep navigation (>12 artefacts) triggers serendipity plus thematic clusters. Panel shows 2 related artefacts plus 1 serendipity artefact.', '{"signal": "navigation_depth", "category": "very_deep", "min": 13, "max": null, "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_serendipity_and_thematic_clusters"}', TRUE),

    -- Theme Switching Adaptive Rules  (Adaptive Logic.docx: 'Theme Switching Adaptive Rules')
    (12, 'theme_switch_none', 'No theme switching (stays within 1 theme) reinforces focused interest. Panel shows items from the same theme.', '{"signal": "theme_switches", "category": "no_switching", "min": 0, "max": 0, "weight": 0.4, "priority": "medium", "priority_multiplier": 1.2, "effect": "reinforce_current_theme"}', TRUE),
    (13, 'theme_switch_single', 'A single theme switch signals exploratory drift and suggests cross-theme artefacts. Panel shows 1 artefact from the other theme.', '{"signal": "theme_switches", "category": "single_switch", "min": 1, "max": 1, "weight": 0.7, "priority": "medium", "priority_multiplier": 1.2, "effect": "suggest_cross_theme_artefacts"}', TRUE),
    (14, 'theme_switch_oscillation', 'Oscillation between themes (A-B-A or B-A-B) signals uncertain interest and suggests bridging artefacts. Panel shows artefacts linking both themes.', '{"signal": "theme_switches", "category": "oscillation", "min": 2, "max": 2, "weight": 1.2, "priority": "high", "priority_multiplier": 1.5, "effect": "suggest_bridging_artefacts"}', TRUE),
    (15, 'theme_switch_rapid', 'Rapid theme switching (3+ switches) signals serendipitous browsing and triggers serendipity suggestions. Panel shows unexpected cross-theme artefacts.', '{"signal": "theme_switches", "category": "rapid_switching", "min": 3, "max": null, "weight": 1.8, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_serendipity_suggestions"}', TRUE),

    -- Subtheme Switching Adaptive Rules  (Adaptive Logic.docx: 'Subtheme Switching Adaptive Rules')
    (16, 'subtheme_switch_low', 'Low subtheme switching (1-2 subthemes) reinforces focused exploration. Panel shows items from the same subtheme.', '{"signal": "subtheme_switches", "category": "low_switching", "min": 1, "max": 2, "weight": 0.4, "priority": "medium", "priority_multiplier": 1.2, "effect": "reinforce_subtheme"}', TRUE),
    (17, 'subtheme_switch_moderate', 'Moderate subtheme switching (3-4 subthemes) signals exploratory drift and suggests related subthemes. Panel shows items from adjacent subthemes.', '{"signal": "subtheme_switches", "category": "moderate_switching", "min": 3, "max": 4, "weight": 0.8, "priority": "medium", "priority_multiplier": 1.2, "effect": "suggest_related_subthemes"}', TRUE),
    (18, 'subtheme_switch_high', 'High subtheme switching (5-7 subthemes) signals broad exploration and suggests thematic clusters. Panel shows items from multiple subthemes.', '{"signal": "subtheme_switches", "category": "high_switching", "min": 5, "max": 7, "weight": 1.3, "priority": "high", "priority_multiplier": 1.5, "effect": "suggest_thematic_clusters"}', TRUE),
    (19, 'subtheme_switch_rapid', 'Rapid subtheme switching (8+ subthemes) signals serendipitous mode and triggers unexpected artefacts. Panel shows 1-2 serendipity artefacts.', '{"signal": "subtheme_switches", "category": "rapid_switching", "min": 8, "max": null, "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_unexpected_artefacts"}', TRUE),

    -- Branching Factor Adaptive Rules  (Adaptive Logic.docx: 'Branching Factor Adaptive Rules')
    (20, 'branching_low', 'Low branching factor (1-2 subthemes) reinforces narrow interest. Panel shows deeper items from the same subtheme.', '{"signal": "branching_factor", "category": "low", "min": 1, "max": 2, "weight": 0.4, "priority": "medium", "priority_multiplier": 1.2, "effect": "reinforce_subtheme"}', TRUE),
    (21, 'branching_moderate', 'Moderate branching factor (3-5 subthemes) signals balanced exploration and suggests deeper related artefacts. Panel shows items from the most-explored subthemes.', '{"signal": "branching_factor", "category": "moderate", "min": 3, "max": 5, "weight": 0.9, "priority": "medium", "priority_multiplier": 1.2, "effect": "suggest_deeper_related_artefacts"}', TRUE),
    (22, 'branching_high', 'High branching factor (6-9 subthemes) signals broad exploration and suggests cross-subtheme clusters. Panel shows items from multiple subthemes.', '{"signal": "branching_factor", "category": "high", "min": 6, "max": 9, "weight": 1.4, "priority": "high", "priority_multiplier": 1.5, "effect": "suggest_cross_subtheme_clusters"}', TRUE),
    (23, 'branching_veryhigh', 'Very high branching factor (10+ subthemes) signals serendipitous browsing and triggers serendipity suggestions. Panel shows unexpected artefacts.', '{"signal": "branching_factor", "category": "very_high", "min": 10, "max": null, "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_serendipity_suggestions"}', TRUE),

    -- Serendipity Adaptive Rules  (Adaptive Logic.docx: 'Serendipity Adaptive Rules')
    -- weight values added per Adaptive Logic.docx > "Adaptive Rule
    -- Weighting Scoring Model > Signal Weight Tables > Serendipity
    -- Weights" (Phase 5 - previously missing entirely, so every compound
    -- rule contributed 0.0 to calculateInterestScore() whenever it fired,
    -- silently diluting the interest score average instead of
    -- reinforcing it - found while building AP3's dwell-trigger test).
    (24, 'serendipity_immersive_rare', 'Immersive dwell (>30s) on a low-popularity artefact shows rare or unusual artefacts. Panel displays 1-2 rare artefacts from low-frequency subthemes.', '{"signal": "compound", "conditions": [{"signal": "dwell_time", "category": "immersive_dwell", "min_seconds": 30}, {"signal": "artefact_popularity", "category": "low"}], "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "show_rare_unique_artefacts"}', TRUE),
    (25, 'serendipity_theme_subtheme_switch', '2+ theme switches combined with 5+ subtheme switches triggers serendipity mode. Panel mixes 1 unexpected with 1 unrelated artefact.', '{"signal": "compound", "conditions": [{"signal": "theme_switches", "min": 2}, {"signal": "subtheme_switches", "min": 5}], "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "trigger_serendipity_mode"}', TRUE),
    (26, 'serendipity_navdepth_revisit', 'Navigation depth of 8+ combined with 2+ revisits shows deeper thematic items. Panel displays deeper items from the dominant subtheme.', '{"signal": "compound", "conditions": [{"signal": "navigation_depth", "min": 8}, {"signal": "revisit_count", "min": 2}], "weight": 1.4, "priority": "high", "priority_multiplier": 1.5, "effect": "show_deeper_thematic_items"}', TRUE),
    (27, 'serendipity_subtheme_revisit', '8+ subtheme switches combined with 1+ revisits shows unexpected artefacts from distant subthemes.', '{"signal": "compound", "conditions": [{"signal": "subtheme_switches", "min": 8, "source_doc_value": 18}, {"signal": "revisit_count", "min": 1}], "weight": 1.9, "priority": "very_high", "priority_multiplier": 2.0, "effect": "show_unexpected_artefacts"}', TRUE);
