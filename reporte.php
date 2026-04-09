<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método no permitido'], 405);
}

$db   = getDB();
$stmt = $db->query("SELECT * FROM v_reporte_mensual LIMIT 12");
json_response($stmt->fetchAll());
