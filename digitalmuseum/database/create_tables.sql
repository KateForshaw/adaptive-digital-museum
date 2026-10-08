-- =====================================================================
-- Digital Museum Research Project
-- Phase 1 - Database Foundation
-- create_tables.sql
--
-- Source: /Planning Documents/Phased Build Plan.docx
--         /System Design/Database Design.docx
--         /System Design/Metadata Strategy.docx
--
-- Creates the 'digitalmuseum' database if it does not already exist and
-- selects it, so this script (and the insert_*.sql scripts that follow)
-- always run against the same place regardless of what database was
-- previously selected in phpMyAdmin/MySQL Workbench.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS digitalmuseum
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE digitalmuseum;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS questionnaire_response;
DROP TABLE IF EXISTS questionnaire_item;
DROP TABLE IF EXISTS timeout;
DROP TABLE IF EXISTS adaptive_event;
DROP TABLE IF EXISTS event_log;
DROP TABLE IF EXISTS adaptive_suggestion;
DROP TABLE IF EXISTS adaptive_rule;
DROP TABLE IF EXISTS session_log;
DROP TABLE IF EXISTS study_condition;
DROP TABLE IF EXISTS participant;
DROP TABLE IF EXISTS artefact;
DROP TABLE IF EXISTS subtheme;
DROP TABLE IF EXISTS theme;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- METADATA TABLES  (theme > subtheme > artefact)
-- Two themes, 12 subthemes (6 per theme), 60 artefacts (5 per subtheme)
-- =====================================================================

CREATE TABLE theme (
    theme_id            INT AUTO_INCREMENT PRIMARY KEY,
    theme_name          ENUM('Animals','Everyday Objects') NOT NULL,
    theme_description   VARCHAR(255) NULL,
    UNIQUE KEY uq_theme_name (theme_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subtheme (
    subtheme_id          INT AUTO_INCREMENT PRIMARY KEY,
    theme_id             INT NOT NULL,
    subtheme_name        ENUM(
                             'Mammals','Birds','Fossils','Reptiles','Insects','Marine Life',
                             'Tools','Writing','Clothing','Food-related','Household Items','Toys'
                         ) NOT NULL,
    subtheme_description VARCHAR(255) NULL,
    UNIQUE KEY uq_subtheme_name (subtheme_name),
    CONSTRAINT fk_subtheme_theme
        FOREIGN KEY (theme_id) REFERENCES theme(theme_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_subtheme_theme (theme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE artefact (
    artefact_id          INT AUTO_INCREMENT PRIMARY KEY,
    theme_id             INT NOT NULL,
    subtheme_id          INT NOT NULL,
    external_id          VARCHAR(50) NOT NULL,   -- Smithsonian object ID
    artefact_title       VARCHAR(255) NOT NULL,
    artefact_description TEXT NOT NULL,
    image_url            VARCHAR(500) NOT NULL,
    source_url           VARCHAR(500) NOT NULL,
    tags                 VARCHAR(255) NULL,
    UNIQUE KEY uq_artefact_external_id (external_id),
    CONSTRAINT fk_artefact_theme
        FOREIGN KEY (theme_id) REFERENCES theme(theme_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_artefact_subtheme
        FOREIGN KEY (subtheme_id) REFERENCES subtheme(subtheme_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_artefact_theme (theme_id),
    INDEX idx_artefact_subtheme (subtheme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SYSTEM CONTROL TABLES
-- =====================================================================

CREATE TABLE participant (
    participant_id     INT AUTO_INCREMENT PRIMARY KEY,
    participant_code   VARCHAR(20) NOT NULL,   -- anonymous participant code
    participant_notes  TEXT NULL,
    UNIQUE KEY uq_participant_code (participant_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE study_condition (
    condition_id          INT AUTO_INCREMENT PRIMARY KEY,
    condition_name        ENUM('static_first','adaptive_first') NOT NULL,
    condition_description VARCHAR(255) NULL,
    UNIQUE KEY uq_condition_name (condition_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- BEHAVIOUR LOGGING TABLES
-- =====================================================================

CREATE TABLE session_log (
    session_id                 INT AUTO_INCREMENT PRIMARY KEY,
    participant_id             INT NOT NULL,
    condition_id               INT NOT NULL,
    session_start              DATETIME NOT NULL,
    session_end                DATETIME NULL,
    timeout_triggered_static   BOOLEAN NOT NULL DEFAULT FALSE,
    timeout_triggered_adaptive BOOLEAN NOT NULL DEFAULT FALSE,
    error_flag                 BOOLEAN NOT NULL DEFAULT FALSE,
    artefact_order             TEXT NULL, -- JSON-encoded array of artefact_id in the
                               -- order includes/artefact_order.php's
                               -- orderArtefactsForParticipant() produced
                               -- for this session (Phase 8) - see
                               -- add_artefact_order.sql
    session_notes              TEXT NULL,
    CONSTRAINT fk_session_participant
        FOREIGN KEY (participant_id) REFERENCES participant(participant_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_session_condition
        FOREIGN KEY (condition_id) REFERENCES study_condition(condition_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_session_participant (participant_id),
    INDEX idx_session_condition (condition_id),
    INDEX idx_session_start (session_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ADAPTIVE SYSTEM TABLES
-- Created before event_log / adaptive_event because both reference them.
-- =====================================================================

CREATE TABLE adaptive_rule (
    rule_id           INT AUTO_INCREMENT PRIMARY KEY,
    rule_name         VARCHAR(100) NOT NULL,
    rule_description  VARCHAR(255) NULL,
    trigger_condition TEXT NULL,   -- JSON describing the behavioural threshold
    active_flag       BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE adaptive_suggestion (
    suggestion_id        INT AUTO_INCREMENT PRIMARY KEY,
    rule_id              INT NOT NULL,
    session_id           INT NOT NULL,
    artefact_id          INT NOT NULL,
    interest_score       DECIMAL(4,2) NULL, -- computed interest score behind this
                                             -- suggestion (Adaptive Logic.docx > "Score
                                             -- Normalisation Formula") - see
                                             -- add_interest_score.sql
    suggestion_timestamp DATETIME NOT NULL,
    panel_position       INT NULL,
    clicked_flag         BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_suggestion_rule
        FOREIGN KEY (rule_id) REFERENCES adaptive_rule(rule_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_suggestion_session
        FOREIGN KEY (session_id) REFERENCES session_log(session_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_suggestion_artefact
        FOREIGN KEY (artefact_id) REFERENCES artefact(artefact_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_suggestion_rule (rule_id),
    INDEX idx_suggestion_session (session_id),
    INDEX idx_suggestion_artefact (artefact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- EVENT LOGGING
-- =====================================================================

CREATE TABLE event_log (
    event_id          INT AUTO_INCREMENT PRIMARY KEY,
    session_id        INT NOT NULL,
    artefact_id       INT NULL,   -- null for session-level events (session_start/end, timeout_redirect, error_trigger)
    suggestion_id     INT NULL,   -- null for static version events
    rule_id           INT NULL,
    event_type        ENUM(
                          'click','dwell_start','dwell_end','revisit','navigation_depth',
                          'adaptive_trigger','panel_impression','panel_click',
                          'session_start','session_end','timeout_redirect','error_trigger'
                      ) NOT NULL,
    `timestamp`       DATETIME NOT NULL,
    dwell_duration    INT NULL,   -- seconds, only for dwell_end events
    navigation_depth  INT NULL,   -- only when a modal is opened
    CONSTRAINT fk_event_session
        FOREIGN KEY (session_id) REFERENCES session_log(session_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_event_artefact
        FOREIGN KEY (artefact_id) REFERENCES artefact(artefact_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_event_suggestion
        FOREIGN KEY (suggestion_id) REFERENCES adaptive_suggestion(suggestion_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_event_rule
        FOREIGN KEY (rule_id) REFERENCES adaptive_rule(rule_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_event_session (session_id),
    INDEX idx_event_artefact (artefact_id),
    INDEX idx_event_suggestion (suggestion_id),
    INDEX idx_event_rule (rule_id),
    INDEX idx_event_type (event_type),
    INDEX idx_event_timestamp (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Database Design.docx marks artefact_id as NOT NULL, but it's
-- made nullable here since session-level events (session_start,
-- session_end, timeout_redirect, error_trigger) have no artefact
-- context. 

CREATE TABLE adaptive_event (
    adaptive_event_id  INT AUTO_INCREMENT PRIMARY KEY,
    event_id           INT NOT NULL,
    session_id         INT NOT NULL,
    rule_id            INT NOT NULL,
    suggestion_id      INT NULL,
    trigger_timestamp  DATETIME NOT NULL,
    trigger_confidence DECIMAL(5,2) NULL,
    CONSTRAINT fk_adaptive_event_event
        FOREIGN KEY (event_id) REFERENCES event_log(event_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_adaptive_event_session
        FOREIGN KEY (session_id) REFERENCES session_log(session_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_adaptive_event_rule
        FOREIGN KEY (rule_id) REFERENCES adaptive_rule(rule_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_adaptive_event_suggestion
        FOREIGN KEY (suggestion_id) REFERENCES adaptive_suggestion(suggestion_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_adaptive_event_event (event_id),
    INDEX idx_adaptive_event_session (session_id),
    INDEX idx_adaptive_event_rule (rule_id),
    INDEX idx_adaptive_event_suggestion (suggestion_id),
    INDEX idx_adaptive_event_timestamp (trigger_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE timeout (
    timeout_id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id         INT NOT NULL,
    version_name       ENUM('static','adaptive') NOT NULL,
    timeout_timestamp  DATETIME NOT NULL,
    auto_redirect_flag BOOLEAN NOT NULL,
    timeout_notes      TEXT NULL,
    CONSTRAINT fk_timeout_session
        FOREIGN KEY (session_id) REFERENCES session_log(session_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_timeout_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- QUESTIONNAIRE TABLES
-- =====================================================================

CREATE TABLE questionnaire_item (
    item_id          INT AUTO_INCREMENT PRIMARY KEY,
    item_text        VARCHAR(500) NOT NULL,
    item_type        ENUM('multiple_choice','likert','free_text') NOT NULL,
    item_order       INT NOT NULL,
    scale_min_label  VARCHAR(100) NULL,
    scale_max_label  VARCHAR(100) NULL,
    item_notes       TEXT NULL,
    UNIQUE KEY uq_item_order (item_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questionnaire_response (
    response_id        INT AUTO_INCREMENT PRIMARY KEY,
    session_id          INT NOT NULL,
    participant_id      INT NOT NULL,
    item_id             INT NOT NULL,
    response_value      VARCHAR(500) NOT NULL,
    response_timestamp  DATETIME NOT NULL,
    response_notes      TEXT NULL,
    CONSTRAINT fk_response_session
        FOREIGN KEY (session_id) REFERENCES session_log(session_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_response_participant
        FOREIGN KEY (participant_id) REFERENCES participant(participant_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_response_item
        FOREIGN KEY (item_id) REFERENCES questionnaire_item(item_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_response_session (session_id),
    INDEX idx_response_participant (participant_id),
    INDEX idx_response_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
