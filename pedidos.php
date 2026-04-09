<?php
/**
 * pedidos.php — Gestión de pedidos.
 *
 * GET  /pedidos.php             → Todos los pedidos activos
 * GET  /pedidos.php?estado=X    → Filtrar por estado
 * POST /pedidos.php             → Crear pedido
 * PUT  /pedidos.php             → Actualizar estado del pedido
 */

require_once 'config.php';
require_method('GET', 'POST', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET ───────────────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $estado = $_GET['estado'] ?? null;

    $estadosValidos = ['pendiente', 'en_proceso', 'entregado', 'cancelado'];

    if ($estado !== null && !in_array($estado, $estadosValidos, true)) {
        json_response(['error' => 'Estado no válido'], 400);
    }

    if ($estado) {
        $stmt = $db->prepare('SELECT * FROM v_pedidos_activos WHERE estado = ?');
        $stmt->execute([$estado]);
    } else {
        $stmt = $db->query('SELECT * FROM v_pedidos_activos');
    }

    json_response($stmt->fetchAll());
}

// ── POST ──────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $data           = get_input();
    $nombre_cliente = trim($data['nombre_cliente'] ?? '');
    $cliente_id     = $data['cliente_id']     ?? null;
    $telefono       = trim($data['telefono']   ?? '');
    $direccion      = trim($data['direccion']  ?? '');
    $tipo           = trim($data['tipo']       ?? '');
    $cantidad       = (int)   ($data['cantidad']    ?? 0);
    $costo_unit     = (float) ($data['costo_unit']  ?? 0);
    $fecha_entrega  = $data['fecha_entrega']   ?? null;
    $notas          = trim($data['notas']      ?? '');
    $registrado_por = $data['registrado_por']  ?? null;

    if (!$tipo || $cantidad <= 0 || $costo_unit <= 0) {
        json_response(['error' => 'tipo, cantidad y costo_unit son requeridos y deben ser mayores a 0'], 400);
    }

    // Validar formato de fecha de entrega si se proporcionó
    if ($fecha_entrega && !preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha_entrega)) {
        json_response(['error' => 'Formato de fecha_entrega inválido'], 400);
    }

    $pedido_id = uuid4();

    $db->prepare(
        'INSERT INTO pedidos
           (id, cliente_id, nombre_cliente, telefono, direccion, tipo, cantidad,
            costo_unit, fecha_entrega, notas, registrado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $pedido_id, $cliente_id, $nombre_cliente, $telefono, $direccion,
        $tipo, $cantidad, $costo_unit, $fecha_entrega, $notas, $registrado_por,
    ]);

    json_response(['success' => true, 'pedido_id' => $pedido_id], 201);
}

// ── PUT ───────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    $data   = get_input();
    $id     = trim($data['id']     ?? '');
    $estado = trim($data['estado'] ?? '');

    if (!$id || !$estado) {
        json_response(['error' => 'id y estado son requeridos'], 400);
    }

    $estadosValidos = ['pendiente', 'en_proceso', 'entregado', 'cancelado'];
    if (!in_array($estado, $estadosValidos, true)) {
        json_response(['error' => 'Estado no válido'], 400);
    }

    $stmt = $db->prepare('UPDATE pedidos SET estado = ?, actualizado_en = NOW() WHERE id = ?');
    $stmt->execute([$estado, $id]);

    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Pedido no encontrado'], 404);
    }

    json_response(['success' => true]);
}
