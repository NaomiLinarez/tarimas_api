<?php
/**
 * precios.php — Consulta y actualización de precios por tipo de tarima.
 *
 * GET /precios.php         → Lista todos los precios
 * PUT /precios.php         → Actualiza precio de un tipo
 */

require_once 'config.php';
require_method('GET', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Tipos de tarima válidos
$tiposValidos = [
    'tarima_nueva',
    'estandar',
    'encachetada',
    'barrote',
    'tacon',
    'especial',
    'reparacion',
];

if ($method === 'GET') {
    $stmt = $db->query('SELECT tipo, precio_unit FROM precios ORDER BY tipo');
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data       = get_input();
    $tipo       = trim($data['tipo']       ?? '');
    $precio     = $data['precio_unit']     ?? null;
    $usuario_id = $data['usuario_id']      ?? null;

    if ($tipo === '' || $precio === null) {
        json_response(['error' => 'tipo y precio_unit son requeridos'], 400);
    }

    if (!is_numeric($precio) || (float)$precio < 0) {
        json_response(['error' => 'precio_unit debe ser un número mayor o igual a 0'], 400);
    }

    // Verificar que el tipo exista
    $stmt = $db->prepare('SELECT tipo FROM precios WHERE tipo = ?');
    $stmt->execute([$tipo]);
    if (!$stmt->fetch()) {
        json_response(['error' => "Tipo '$tipo' no encontrado en precios"], 404);
    }

    $db->prepare('UPDATE precios SET precio_unit = ? WHERE tipo = ?')
       ->execute([(float)$precio, $tipo]);

    json_response(['success' => true]);
}
