<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $stmt = $db->query("SELECT tipo, precio_unit, actualizado_en FROM precios ORDER BY tipo");
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data       = get_input();
    $tipo       = $data['tipo']        ?? '';
    $precio     = $data['precio_unit'] ?? null;
    $usuario_id = $data['usuario_id']  ?? null;

    if (!$tipo || $precio === null) {
        json_response(['error' => 'tipo y precio_unit requeridos'], 400);
    }

    $db->prepare("UPDATE precios SET precio_unit = ?, actualizado_por = ? WHERE tipo = ?")
       ->execute([$precio, $usuario_id, $tipo]);

    json_response(['success' => true]);
}

json_response(['error' => 'Método no permitido'], 405);
