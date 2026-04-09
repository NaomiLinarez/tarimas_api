<?php


define('DB_HOST',     getenv('MYSQLHOST'));
define('DB_PORT',     getenv('MYSQLPORT')     ?: '3306');
define('DB_NAME',     getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE'));
define('DB_USER',     getenv('MYSQLUSER'));
define('DB_PASSWORD', getenv('MYSQLPASSWORD'));


header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}



function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;   // reutilizar conexión dentro de la misma petición

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        json_response(['error' => 'Error de base de datos'], 500);
    }
}



function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}



function get_input(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}



function require_method(string ...$allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        json_response(['error' => 'Método no permitido'], 405);
    }
}



function uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
