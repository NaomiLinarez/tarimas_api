<?php
/**
 * precios.php — Consulta y actualización de precios.
 *
 * GET /precios.php → Lista precios de todos los tipos
 * PUT /precios.php → Actualiza precio de un tipo
 */

require_once 'config.php';
require_method('GET', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $stmt = $db->query('SELECT tipo, precio_unit, actualizado_en FROM precios ORDER BY tipo');
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data       = get_input();
    $tipo       = trim($data['tipo']        ?? '');
    $precio     = $data['precio_unit']      ?? null;
    $usuario_id = $data['usuario_id']       ?? null;

    if ($tipo === '' || $precio === null) {
        json_response(['error' => 'tipo y precio_unit son requeridos'], 400);
    }

    if (!is_numeric($precio) || (float) $precio <= 0) {
        json_response(['error' => 'precio_unit debe ser un número mayor a 0'], 400);
    }

    $stmt = $db->prepare('UPDATE precios SET precio_unit = ?, actualizado_por = ? WHERE tipo = ?');
    $stmt->execute([(float) $precio, $usuario_id, $tipo]);

    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Tipo de producto no encontrado'], 404);
    }

    json_response(['success' => true]);
}
