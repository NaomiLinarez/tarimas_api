<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $fecha = $_GET['fecha'] ?? null;
    if ($fecha) {
        $stmt = $db->prepare("
            SELECT v.*, GROUP_CONCAT(
                CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit)
                SEPARATOR '|'
            ) AS detalle
            FROM ventas v
            LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
            WHERE DATE(v.creado_en) = ?
            GROUP BY v.id
            ORDER BY v.creado_en DESC
        ");
        $stmt->execute([$fecha]);
    } else {
        $stmt = $db->query("SELECT * FROM v_ventas_hoy");
    }
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data          = get_input();
    $nombre_cliente = $data['nombre_cliente'] ?? '';
    $cliente_id     = $data['cliente_id']     ?? null;
    $total          = $data['total']          ?? 0;
    $metodo_pago    = $data['metodo_pago']    ?? 'efectivo';
    $monto_recibido = $data['monto_recibido'] ?? null;
    $estado_pago    = $data['estado_pago']    ?? 'pagado';
    $registrada_por = $data['registrada_por'] ?? null;
    $detalle        = $data['detalle']        ?? [];

    if (!$total || empty($detalle)) {
        json_response(['error' => 'total y detalle requeridos'], 400);
    }

    $db->beginTransaction();
    try {
        $venta_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        $db->prepare("INSERT INTO ventas (id, cliente_id, nombre_cliente, total, metodo_pago, monto_recibido, estado_pago, registrada_por)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$venta_id, $cliente_id, $nombre_cliente, $total, $metodo_pago, $monto_recibido, $estado_pago, $registrada_por]);

        foreach ($detalle as $item) {
            $db->prepare("INSERT INTO detalle_ventas (venta_id, tipo, cantidad, precio_unit) VALUES (?, ?, ?, ?)")
               ->execute([$venta_id, $item['tipo'], $item['cantidad'], $item['precio_unit']]);

            $stmt = $db->prepare("SELECT stock_actual FROM inventario WHERE tipo = ?");
            $stmt->execute([$item['tipo']]);
            $stock_antes = $stmt->fetchColumn();
            $stock_nuevo = max(0, $stock_antes - $item['cantidad']);

            $db->prepare("UPDATE inventario SET stock_actual = ? WHERE tipo = ?")
               ->execute([$stock_nuevo, $item['tipo']]);

            $db->prepare("INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, referencia_id, cambiado_por)
                          VALUES (?, ?, ?, 'venta', ?, ?)")
               ->execute([$item['tipo'], $stock_antes, $stock_nuevo, $venta_id, $registrada_por]);
        }

        $db->commit();
        json_response(['success' => true, 'venta_id' => $venta_id]);

    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Error al registrar la venta'], 500);
    }
}

json_response(['error' => 'Método no permitido'], 405);
