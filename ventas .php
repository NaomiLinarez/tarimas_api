<?php
/**
 * ventas.php — Registro y consulta de ventas.
 *
 * GET  /ventas.php              → Ventas de hoy
 * GET  /ventas.php?fecha=YYYY-MM-DD → Ventas de una fecha
 * POST /ventas.php              → Registrar nueva venta
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET ───────────────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $fecha = $_GET['fecha'] ?? null;

    if ($fecha) {
        // Validar formato de fecha antes de la consulta
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_response(['error' => 'Formato de fecha inválido. Usa YYYY-MM-DD'], 400);
        }

        $stmt = $db->prepare("
            SELECT v.*,
                   GROUP_CONCAT(
                       CONCAT(dv.tipo, ':', dv.cantidad, ':', dv.precio_unit)
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
        $stmt = $db->query('SELECT * FROM v_ventas_hoy');
    }

    json_response($stmt->fetchAll());
}

// ── POST ──────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $data           = get_input();
    $nombre_cliente = trim($data['nombre_cliente'] ?? '');
    $cliente_id     = $data['cliente_id']     ?? null;
    $total          = $data['total']          ?? 0;
    $metodo_pago    = $data['metodo_pago']    ?? 'efectivo';
    $monto_recibido = $data['monto_recibido'] ?? null;
    $estado_pago    = $data['estado_pago']    ?? 'pagado';
    $registrada_por = $data['registrada_por'] ?? null;
    $detalle        = $data['detalle']        ?? [];

    if (!$total || empty($detalle)) {
        json_response(['error' => 'total y detalle son requeridos'], 400);
    }

    if (!is_array($detalle)) {
        json_response(['error' => 'detalle debe ser un arreglo'], 400);
    }

    // Validar cada ítem del detalle antes de iniciar la transacción
    foreach ($detalle as $i => $item) {
        if (empty($item['tipo']) || empty($item['cantidad']) || empty($item['precio_unit'])) {
            json_response(['error' => "Item $i: tipo, cantidad y precio_unit son requeridos"], 400);
        }
    }

    // ── Verificar stock antes de iniciar la transacción ─────────────────────────
    $stmtCheckStock = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
    foreach ($detalle as $item) {
        $stmtCheckStock->execute([$item['tipo']]);
        $stockDisponible = (int) $stmtCheckStock->fetchColumn();
        $cantSolicitada  = (int) $item['cantidad'];
        if ($stockDisponible < $cantSolicitada) {
            $label = match($item['tipo']) {
                'tarima_nueva' => 'Tarima nueva',
                'reparacion'   => 'Reparación',
                'especial'     => 'Medida especial',
                default        => $item['tipo'],
            };
            json_response([
                'error' => "Stock insuficiente de {$label}. Disponibles: {$stockDisponible}, solicitados: {$cantSolicitada}"
            ], 409);
        }
    }

    $db->beginTransaction();
    try {
        $venta_id = uuid4();

        $db->prepare(
            'INSERT INTO ventas
               (id, cliente_id, nombre_cliente, total, metodo_pago, monto_recibido, estado_pago, registrada_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$venta_id, $cliente_id, $nombre_cliente, $total,
                    $metodo_pago, $monto_recibido, $estado_pago, $registrada_por]);

        $stmtDetalle   = $db->prepare(
            'INSERT INTO detalle_ventas (venta_id, tipo, cantidad, precio_unit) VALUES (?, ?, ?, ?)'
        );
        $stmtStock     = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
        $stmtUpdate    = $db->prepare('UPDATE inventario SET stock_actual = ? WHERE tipo = ?');
        $stmtHistorial = $db->prepare(
            'INSERT INTO historial_inventario
               (tipo, stock_antes, stock_despues, motivo, referencia_id, cambiado_por)
             VALUES (?, ?, ?, \'venta\', ?, ?)'
        );

        foreach ($detalle as $item) {
            $stmtDetalle->execute([$venta_id, $item['tipo'], $item['cantidad'], $item['precio_unit']]);

            $stmtStock->execute([$item['tipo']]);
            $stock_antes = (int) $stmtStock->fetchColumn();
            $stock_nuevo = max(0, $stock_antes - (int) $item['cantidad']);

            $stmtUpdate->execute([$stock_nuevo, $item['tipo']]);
            $stmtHistorial->execute([$item['tipo'], $stock_antes, $stock_nuevo, $venta_id, $registrada_por]);
        }

        $db->commit();

        // Notificar al admin sobre el nuevo pedido (no bloquea la respuesta)
        notificar_admin_nuevo_pedido($db, $venta_id, $nombre_cliente, $total, $metodo_pago);

        json_response(['success' => true, 'venta_id' => $venta_id], 201);

    } catch (Exception $e) {
        $db->rollBack();
        // No exponer detalles internos al cliente
        error_log('Error en ventas POST: ' . $e->getMessage());
        json_response(['error' => 'Error al registrar la venta'], 500);
    }
}

// ── Función: notificar al admin por FCM ───────────────────────────────────────

function notificar_admin_nuevo_pedido(PDO $db, string $venta_id, string $cliente, float $total, string $metodo): void {
    try {
        // Obtener todos los tokens FCM de usuarios con rol 'admin'
        $stmt = $db->prepare("
            SELECT ft.token
              FROM fcm_tokens ft
              JOIN usuarios u ON u.id = ft.usuario_id
             WHERE u.rol = 'admin'
               AND ft.activo = 1
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tokens)) return;

        $nombre  = $cliente !== '' ? $cliente : 'Cliente';
        $metodoL = strtoupper($metodo);
        $monto   = number_format($total, 2);

        $payload = json_encode([
            'message' => [
                'notification' => [
                    'title' => '🛒 Nuevo pedido recibido',
                    'body'  => "$nombre realizó un pedido por \$$monto — $metodoL",
                ],
                'data' => [
                    'tipo'     => 'nuevo_pedido',
                    'venta_id' => $venta_id,
                    'titulo'   => '🛒 Nuevo pedido recibido',
                    'cuerpo'   => "$nombre realizó un pedido por \$$monto — $metodoL",
                ],
                'tokens' => $tokens,   // Multicast: hasta 500 tokens
            ]
        ]);

        $serverKey = getenv('FCM_SERVER_KEY');
        if (!$serverKey) {
            error_log('FCM_SERVER_KEY no configurada — no se pudo notificar al admin');
            return;
        }

        // Llamada a la API de FCM (HTTP v1 usa OAuth2; esta versión usa la legacy API)
        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: key=' . $serverKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'registration_ids' => $tokens,
                'notification'     => [
                    'title' => '🛒 Nuevo pedido recibido',
                    'body'  => "$nombre realizó un pedido por \$$monto — $metodoL",
                ],
                'data' => [
                    'tipo'     => 'nuevo_pedido',
                    'venta_id' => $venta_id,
                ],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('FCM curl error: ' . curl_error($ch));
        }
        curl_close($ch);

    } catch (Exception $e) {
        error_log('Error al notificar admin FCM: ' . $e->getMessage());
        // No interrumpir el flujo principal
    }
}
