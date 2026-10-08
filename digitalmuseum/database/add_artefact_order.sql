-- =====================================================================
-- Digital Museum Research Project
-- Phase 8 - Artefact Order
-- add_artefact_order.sql
--
-- Adds artefact_order to session_log so the grid order
-- includes/artefact_order.php's orderArtefactsForParticipant() produced
-- for a session is persisted, not just computed in-memory on every
-- static.php/adaptive.php page load. Lets later analysis/debugging
-- confirm what order a participant actually saw (Testing Plans.docx >
-- AO2 "Same participant, same order twice" - a stored value to check
-- both sessions against, rather than only re-deriving the order from
-- participant_id and trusting the function stayed deterministic) and
-- keeps a record even if orderArtefactsForParticipant()'s algorithm
-- changes later.
--
-- Stored as TEXT holding a JSON-encoded array of artefact_id in
-- display order, matching the JSON-in-TEXT convention already used by
-- adaptive_rule.trigger_condition (create_tables.sql) rather than
-- MySQL's native JSON column type. NULL until a participant's
-- static.php/adaptive.php page load actually computes an order -
-- static.php/adaptive.php writing to this column is a later step, not
-- part of this migration.
--
-- Safe to run at any point - purely additive, no existing code
-- references session_log's column list positionally.
-- =====================================================================

USE digitalmuseum;

ALTER TABLE session_log
    ADD COLUMN artefact_order TEXT NULL AFTER error_flag;
