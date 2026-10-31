<?php

$dbHost = 'localhost';
$dbName = 'smart_city_db';
$dbUser = 'root';
$dbPassword = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('CivicPulse database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Start MySQL in XAMPP and check config/database.php.');
}
