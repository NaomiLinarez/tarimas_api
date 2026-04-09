<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $estado = $_GET['estado'] ?? null;
    if ($estado) {
        $stmt = $db->prepare("SELECT * FROM v_pedidos_activos WHERE estado = ?");
        $stmt->execute([$estado]);
    } else {
        $stmt = $db->query("SELECT * FROM v_pedidos_activos");
    }
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data          = get_input();
    $nombre_cliente = $data['nombre_cliente'] ?? '';
    $cliente_id     = $data['cliente_id']     ?? null;
    $telefono       = $data['telefono']       ?? '';
    $direccion      = $data['direccion']      ?? '';
    $tipo           = $data['tipo']           ?? '';
    $cantidad       = $data['cantidad']       ?? 0;
    $costo_unit     = $data['costo_unit']     ?? 0;
    $fecha_entrega  = $data['fecha_entrega']  ?? null;
    $notas          = $data['notas']          ?? '';
    $registrado_por = $data['registrado_por'] ?? null;

    if (!$tipo || !$cantidad || !$costo_unit) {
        json_response(['error' => 'tipo, cantidad y costo_unit requeridos'], 400);
    }

    $pedido_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $db->prepare("INSERT INTO pedidos (id, cliente_id, nombre_cliente, telefono, direccion, tipo, cantidad, costo_unit, fecha_entrega, notas, registrado_por)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$pedido_id, $cliente_id, $nombre_cliente, $telefono, $direccion, $tipo, $cantidad, $costo_unit, $fecha_entrega, $notas, $registrado_por]);

    json_response(['success' => true, 'pedido_id' => $pedido_id]);
}

if ($method === 'PUT') {
    $data   = get_input();
    $id     = $data['id']     ?? '';
    $estado = $data['estado'] ?? '';

    if (!$id || !$estado) {
        json_response(['error' => 'id y estado requeridos'], 400);
    }

    $db->prepare("UPDATE pedidos SET estado = ? WHERE id = ?")
       ->execute([$estado, $id]);

    json_response(['success' => true]);
}

json_response(['error' => 'Método no permitido'], 405);
