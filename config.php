<?php
define('DB_HOST',     getenv('MYSQLHOST'));
define('DB_PORT',     getenv('MYSQLPORT')     ?: '3306');
define('DB_NAME',     getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE'));
define('DB_USER',     getenv('MYSQLUSER'));
define('DB_PASSWORD', getenv('MYSQLPASSWORD'));

function getDB() {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
 echo json_encode(['error' => $e->getMessage()]); // en lugar del mensaje genérico
        exit;
    }
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_input() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

