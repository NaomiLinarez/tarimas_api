<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM v_inventario");
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data = get_input();
    $tipo         = $data['tipo']         ?? '';
    $stock_actual = $data['stock_actual'] ?? null;
    $usuario_id   = $data['usuario_id']   ?? null;

    if (!$tipo || $stock_actual === null) {
        json_response(['error' => 'tipo y stock_actual requeridos'], 400);
    }

    $stmt = $db->prepare("SELECT stock_actual FROM inventario WHERE tipo = ?");
    $stmt->execute([$tipo]);
    $antes = $stmt->fetchColumn();

    $db->prepare("UPDATE inventario SET stock_actual = ?, actualizado_por = ? WHERE tipo = ?")
       ->execute([$stock_actual, $usuario_id, $tipo]);

    $db->prepare("INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, cambiado_por)
                  VALUES (?, ?, ?, 'ajuste_manual', ?)")
       ->execute([$tipo, $antes, $stock_actual, $usuario_id]);

    json_response(['success' => true]);
}

if ($method === 'GET' && isset($_GET['madera'])) {
    $stmt = $db->query("SELECT * FROM inventario_madera");
    json_response($stmt->fetchAll());
}

json_response(['error' => 'Método no permitido'], 405);
