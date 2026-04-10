<?php
/**
 * inventario.php — Consulta y ajuste de inventario.
 *
 * GET /inventario.php         → Inventario general (todos los tipos)
 * GET /inventario.php?madera  → Inventario de madera
 * PUT /inventario.php         → Ajuste manual de stock
 */

require_once 'config.php';
require_method('GET', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Tipos de tarima que maneja el sistema
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
    if (isset($_GET['madera'])) {
        $stmt = $db->query('SELECT * FROM inventario_madera');
    } else {
        // Devolver todos los tipos, incluyendo los nuevos
        $stmt = $db->query(
            "SELECT tipo, stock_actual, stock_minimo, unidad, actualizado_en
               FROM inventario
              ORDER BY tipo"
        );
    }
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data         = get_input();
    $tipo         = trim($data['tipo']         ?? '');
    $stock_actual = $data['stock_actual']      ?? null;
    $usuario_id   = $data['usuario_id']        ?? null;

    if ($tipo === '' || $stock_actual === null) {
        json_response(['error' => 'tipo y stock_actual son requeridos'], 400);
    }

    if (!is_numeric($stock_actual) || (int)$stock_actual < 0) {
        json_response(['error' => 'stock_actual debe ser un número mayor o igual a 0'], 400);
    }

    // Verificar que el tipo exista
    $stmt = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
    $stmt->execute([$tipo]);
    $antes = $stmt->fetchColumn();

    if ($antes === false) {
        json_response(['error' => "Tipo '$tipo' no encontrado en inventario"], 404);
    }

    $db->prepare('UPDATE inventario SET stock_actual = ?, actualizado_por = ?, actualizado_en = NOW() WHERE tipo = ?')
       ->execute([(int)$stock_actual, $usuario_id, $tipo]);

    $db->prepare(
        "INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, cambiado_por)
         VALUES (?, ?, ?, 'ajuste_manual', ?)"
    )->execute([$tipo, (int)$antes, (int)$stock_actual, $usuario_id]);

    json_response(['success' => true]);
}
