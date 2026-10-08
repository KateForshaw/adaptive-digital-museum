<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// config/db_connect.php
//
// A non-dying alternative to config/db.php, used only by the
// participant-facing pages (static.php, adaptive.php, transition1.php,
// transition2.php, questionnaire.php, fallback.php).
//
// config/db.php intentionally die()s with a raw PHP error message on
// connection failure - that's the right behaviour for the JSON API
// endpoints and the Phase 1-6 test scripts, which all want a loud,
// visible failure rather than limping on with no connection. But a
// participant-facing page must never show that raw error screen
// (Testing Plans.docx FB2 - "Test broken DB connection... Fallback
// screen appears"), so safe_db_connect() below returns null instead of
// dying, letting the calling page redirect to fallback.php itself.
//
// api/*.php and tests/*.php should keep using config/db.php - this file
// is deliberately NOT a drop-in replacement for it.
// =====================================================================

function safe_db_connect(): ?PDO
{
    try {
        return new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=digitalmuseum;charset=utf8mb4',
            'root',
            'root',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 2,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}
