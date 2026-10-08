-- =====================================================================
-- Digital Museum Research Project
-- Phase 1 - Database Foundation
-- insert_conditions.sql
--
-- Populates study_condition with the 2 counterbalanced condition orders
-- used in the within-subjects design (each participant completes both
-- prototypes; the order is counterbalanced via this table).
-- Source: /Participant Materials/Post Study Questionnaire.docx
--         ("Order effects... Use study_condition (static_first,
--         adaptive_first) as a factor"), /System Design/Study Protocol
--         Design.docx
-- =====================================================================

USE digitalmuseum;

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM study_condition;
ALTER TABLE study_condition AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- STUDY_CONDITION  (2 rows)
-- =====================================================================

INSERT INTO study_condition
    (condition_id, condition_name, condition_description)
VALUES
    (1, 'static_first', 'Participant completes the static (non-adaptive) prototype first, then the adaptive prototype. Counterbalances order effects in the within-subjects design.'),
    (2, 'adaptive_first', 'Participant completes the adaptive prototype first, then the static (non-adaptive) prototype. Counterbalances order effects in the within-subjects design.');
