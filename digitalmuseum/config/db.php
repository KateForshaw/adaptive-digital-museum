<?php
// =====================================================================
// Digital Museum Research Project
// config/db.php
//
// Shared PDO connection used by the test scripts (and later, the app
// pages). 
// =====================================================================

$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'digitalmuseum';
$DB_USER = 'root';
$DB_PASS = 'root';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage() . "\n");
}
