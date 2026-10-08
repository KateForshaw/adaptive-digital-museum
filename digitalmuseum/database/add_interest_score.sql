-- =====================================================================
-- Digital Museum Research Project
-- Phase 4 - Adaptive Logic Engine
-- add_interest_score.sql
--
-- Adds interest_score to adaptive_suggestion so the computed interest
-- score behind each suggestion (Adaptive Logic.docx > "Score
-- Normalisation Formula": Interest Score = (Signal Weight * Priority
-- Multiplier) / Number of Signals, range 0.0-2.0+) is persisted
-- alongside the suggestion it produced, not just used in-memory to
-- rank artefacts. Lets later analysis ask *why* a suggestion was
-- shown, not just that it was shown.
--
-- Safe to run at any point - adaptive_suggestion has no rows yet
-- (nothing writes to it until Phase 4/5 code exists), and no
-- Phase 1-3 code references this table's column list, so this is a
-- pure addition with no knock-on changes elsewhere.
-- =====================================================================

USE digitalmuseum;

ALTER TABLE adaptive_suggestion
    ADD COLUMN interest_score DECIMAL(4,2) NULL AFTER artefact_id;
