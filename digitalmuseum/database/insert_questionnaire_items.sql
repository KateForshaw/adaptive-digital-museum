-- =====================================================================
-- Digital Museum Research Project
-- Phase 1 - Database Foundation
-- insert_questionnaire_items.sql
--
-- Populates questionnaire_item with the 14 items from the Post-Study
-- Questionnaire (order-of-completion check, 4 Likert dimensions x
-- 2 versions + optional comment per version, comparative preference,
-- and final comments).
-- Source: /Participant Materials/Post Study Questionnaire.docx
-- =====================================================================

USE digitalmuseum;

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM questionnaire_response;
ALTER TABLE questionnaire_response AUTO_INCREMENT = 1;
DELETE FROM questionnaire_item;
ALTER TABLE questionnaire_item AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- QUESTIONNAIRE ITEM  (14 rows)
-- =====================================================================

INSERT INTO questionnaire_item
    (item_id, item_text, item_type, item_order, scale_min_label, scale_max_label, item_notes)
VALUES
    -- About the Study Session
    (1, 'Which version did you complete first?', 'multiple_choice', 1, NULL, NULL, 'Formal name: order_completed_first. Response options: Static version, Adaptive version.'),

    -- Static Prototype Feedback
    (2, 'The artefacts shown in static version felt relevant to my interests.', 'likert', 2, 'Strongly disagree', 'Strongly agree', 'Formal name: static_relevance. Dimension: Perceived Relevance (static version).'),
    (3, 'I encountered artefacts in the static version that were unexpected or surprising.', 'likert', 3, 'Strongly disagree', 'Strongly agree', 'Formal name: static_surprise. Dimension: Perceived Surprise (static version).'),
    (4, 'The static version allowed me to explore freely without feeling restricted.', 'likert', 4, 'Strongly disagree', 'Strongly agree', 'Formal name: static_freedom. Dimension: Exploratory Freedom (static version).'),
    (5, 'Overall, my experience using the static version was positive.', 'likert', 5, 'Strongly disagree', 'Strongly agree', 'Formal name: static_experience. Dimension: Overall Experience (static version).'),
    (6, 'Is there anything you would like to share about your experience with the static version?', 'free_text', 6, NULL, NULL, 'Formal name: static_comment. Optional comment.'),

    -- Adaptive Prototype Feedback
    (7, 'The artefacts shown in adaptive version felt relevant to my interests.', 'likert', 7, 'Strongly disagree', 'Strongly agree', 'Formal name: adaptive_relevance. Dimension: Perceived Relevance (adaptive version).'),
    (8, 'I encountered artefacts in the adaptive version that were unexpected or surprising.', 'likert', 8, 'Strongly disagree', 'Strongly agree', 'Formal name: adaptive_surprise. Dimension: Perceived Surprise (adaptive version).'),
    (9, 'The adaptive version allowed me to explore freely without feeling restricted.', 'likert', 9, 'Strongly disagree', 'Strongly agree', 'Formal name: adaptive_freedom. Dimension: Exploratory Freedom (adaptive version).'),
    (10, 'Overall, my experience using the adaptive version was positive.', 'likert', 10, 'Strongly disagree', 'Strongly agree', 'Formal name: adaptive_experience. Dimension: Overall Experience (adaptive version).'),
    (11, 'Is there anything you would like to share about your experience with the adaptive version?', 'free_text', 11, NULL, NULL, 'Formal name: adaptive_comment. Optional comment.'),

    -- Comparative Reflection
    (12, 'Which version did you prefer overall?', 'multiple_choice', 12, NULL, NULL, 'Formal name: preferred_version. Response options: Static version, Adaptive version, No preference. Used as a grouping variable and descriptive outcome.'),
    (13, 'Why did you prefer this version?', 'free_text', 13, NULL, NULL, 'Formal name: preference_reason. Qualitative data for thematic coding.'),

    -- Final Thoughts
    (14, 'Any final comments about your experience today?', 'free_text', 14, NULL, NULL, 'Formal name: final_comments. Qualitative data for thematic coding.');
