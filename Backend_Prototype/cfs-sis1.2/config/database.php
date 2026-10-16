<?php
/**
 * Database connection (PDO).
 * Include this at the top of any API script: require '../../config/database.php';
 * It gives you a ready-to-use $pdo object.
 */

$DB_HOST = 'localhost';
$DB_NAME = 'cfs_sis';
$DB_USER = 'root';
$DB_PASS = '';          // default XAMPP root password is blank

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw real exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // fetch() returns ['col' => value] instead of a numeric+assoc mix
            PDO::ATTR_EMULATE_PREPARES   => false,                   // use REAL prepared statements, not PHP-side emulation
        ]
    );
} catch (PDOException $e) {
    // Never leak DB details (host, credentials) to the client in the error message.
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}
