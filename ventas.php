<?php
/**
 * ventas.php — Registro y consulta de ventas.
 *
 * GET  /ventas.php                       → Ventas de hoy
 * GET  /ventas.php?fecha=YYYY-MM-DD      → Ventas de una fecha
 * GET  /ventas.php?nombre=Juan           → Ventas de un cliente por nombre
 * GET  /ventas.php?cliente_id=UUID       → Ventas de un cliente por ID
 * POST /ventas.php                       → Registrar nueva venta
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $fecha      = $_GET['fecha']      ?? null;
    $nombre     = trim($_GET['nombre'] ?? '');
    $cliente_id = trim($_GET['cliente_id'] ?? '');

    // Buscar ventas de un cliente por nombre
    if ($nombre !== '') {
        $stmt = $db->prepare("
            SELECT v.*,
                   GROUP_CONCAT(
                       CONCAT(dv.tipo, ':', dv.cantidad, ':', dv.precio_unit)
                       SEPARATOR '|'
                   ) AS detalle
              FROM ventas v
              LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
             WHERE v.nombre_cliente LIKE ?
             GROUP BY v.id
             ORDER BY v.creado_en DESC
             LIMIT 100
        ");
        $stmt->execute(['%' . $nombre . '%']);
        json_response($stmt->fetchAll());
    }

    // Buscar ventas de un cliente por ID
    if ($cliente_id !== '') {
        $stmt = $db->prepare("
            SELECT v.*,
                   GROUP_CONCAT(
                       CONCAT(dv.tipo, ':', dv.cantidad, ':', dv.precio_unit)
                       SEPARATOR '|'
                   ) AS detalle
              FROM ventas v
              LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
             WHERE v.cliente_id = ?
             GROUP BY v.id
             ORDER BY v.creado_en DESC
             LIMIT 100
        ");
        $stmt->execute([$cliente_id]);
        json_response($stmt->fetchAll());
    }

    if ($fecha) {
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

if ($method === 'POST') {
    $data            = get_input();
    $nombre_cliente  = trim($data['nombre_cliente']   ?? '');
    $cliente_id      = $data['cliente_id']       ?? null;
    $total           = $data['total']            ?? 0;
    $metodo_pago     = $data['metodo_pago']      ?? 'transferencia';
    $monto_recibido  = $data['monto_recibido']   ?? null;
    $estado_pago     = $data['estado_pago']      ?? 'pendiente';
    $registrada_por  = $data['registrada_por']   ?? null;
    $medida_especial = trim($data['medida_especial'] ?? '');
    $tipo_reparacion = trim($data['tipo_reparacion']  ?? '');
    $detalle         = $data['detalle']          ?? [];

    if (!$total || empty($detalle)) {
        json_response(['error' => 'total y detalle son requeridos'], 400);
    }
    if (!is_array($detalle)) {
        json_response(['error' => 'detalle debe ser un arreglo'], 400);
    }
    if ($nombre_cliente === '') {
        json_response(['error' => 'nombre_cliente es requerido'], 400);
    }

    foreach ($detalle as $i => $item) {
        if (empty($item['tipo']) || empty($item['cantidad']) || !isset($item['precio_unit'])) {
            json_response(['error' => "Item $i: tipo, cantidad y precio_unit son requeridos"], 400);
        }
    }

    $stmtCheckStock = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
    foreach ($detalle as $item) {
        $stmtCheckStock->execute([$item['tipo']]);
        $row = $stmtCheckStock->fetch();
        if ($row === false) continue;
        $stockDisponible = (int) $row['stock_actual'];
        $cantSolicitada  = (int) $item['cantidad'];
        if ($stockDisponible < $cantSolicitada) {
            $labels = [
                'tarima_nueva' => 'Tarima nueva', 'estandar' => 'Tarima estándar',
                'encachetada'  => 'Tarima encachetada', 'barrote' => 'Tarima de barrote',
                'tacon'        => 'Tarima de tacón', 'especial' => 'Medida especial',
                'reparacion'   => 'Reparación',
            ];
            $label = $labels[$item['tipo']] ?? $item['tipo'];
            json_response(['error' => "Stock insuficiente de {$label}. Disponibles: {$stockDisponible}, solicitados: {$cantSolicitada}"], 409);
        }
    }

    $db->beginTransaction();
    try {
        $venta_id = uuid4();

        try {
            $db->prepare(
                'INSERT INTO ventas (id, cliente_id, nombre_cliente, total, metodo_pago, monto_recibido, estado_pago, registrada_por, medida_especial, tipo_reparacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$venta_id, $cliente_id, $nombre_cliente, $total, $metodo_pago, $monto_recibido, $estado_pago, $registrada_por, $medida_especial, $tipo_reparacion]);
        } catch (\PDOException $colErr) {
            $db->prepare(
                'INSERT INTO ventas (id, cliente_id, nombre_cliente, total, metodo_pago, monto_recibido, estado_pago, registrada_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$venta_id, $cliente_id, $nombre_cliente, $total, $metodo_pago, $monto_recibido, $estado_pago, $registrada_por]);
        }

        $stmtDetalle   = $db->prepare('INSERT INTO detalle_ventas (venta_id, tipo, cantidad, precio_unit) VALUES (?, ?, ?, ?)');
        $stmtStock     = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
        $stmtUpdate    = $db->prepare('UPDATE inventario SET stock_actual = ?, actualizado_en = NOW() WHERE tipo = ?');
        $stmtHistorial = $db->prepare("INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, referencia_id, cambiado_por) VALUES (?, ?, ?, 'venta', ?, ?)");

        foreach ($detalle as $item) {
            $stmtDetalle->execute([$venta_id, $item['tipo'], (int)$item['cantidad'], (float)$item['precio_unit']]);
            $stmtStock->execute([$item['tipo']]);
            $stockRow = $stmtStock->fetch();
            if ($stockRow !== false) {
                $stock_antes = (int) $stockRow['stock_actual'];
                $stock_nuevo = max(0, $stock_antes - (int)$item['cantidad']);
                $stmtUpdate->execute([$stock_nuevo, $item['tipo']]);
                $stmtHistorial->execute([$item['tipo'], $stock_antes, $stock_nuevo, $venta_id, $registrada_por]);
            }
        }

        $db->commit();
        notificar_admin_nuevo_pedido($db, $venta_id, $nombre_cliente, (float)$total, $metodo_pago, $medida_especial, $tipo_reparacion, $detalle);
        json_response(['success' => true, 'venta_id' => $venta_id], 201);

    } catch (Exception $e) {
        $db->rollBack();
        error_log('Error en ventas POST: ' . $e->getMessage());
        json_response(['error' => 'Error al registrar la venta: ' . $e->getMessage()], 500);
    }
}

function notificar_admin_nuevo_pedido(PDO $db, string $venta_id, string $cliente, float $total, string $metodo, string $medida_especial = '', string $tipo_reparacion = '', array $detalle = []): void {
    try {
        $stmt = $db->prepare("SELECT ft.token FROM fcm_tokens ft JOIN usuarios u ON u.id = ft.usuario_id WHERE u.rol = 'admin' AND ft.activo = 1");
        $stmt->execute();
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tokens)) return;
        $nombre = $cliente !== '' ? $cliente : 'Cliente';
        $monto  = number_format($total, 2);
        $labels = ['tarima_nueva'=>'Tarima nueva','estandar'=>'Estándar','encachetada'=>'Encachetada','barrote'=>'Barrote','tacon'=>'Tacón','especial'=>'Medida especial','reparacion'=>'Reparación'];
        $lineas = [];
        foreach ($detalle as $item) {
            $tipo = $item['tipo'] ?? ''; $cantidad = $item['cantidad'] ?? 0;
            $label = $labels[$tipo] ?? $tipo; $linea = "• {$label}: {$cantidad}";
            if ($tipo === 'especial'   && $medida_especial !== '') $linea .= " (medida: {$medida_especial})";
            if ($tipo === 'reparacion' && $tipo_reparacion !== '') $linea .= " (tipo: {$tipo_reparacion})";
            $lineas[] = $linea;
        }
        $cuerpo = "Cliente: {$nombre}\nTotal: \${$monto} | TRANSFERENCIA";
        if (!empty($lineas)) $cuerpo .= "\n\nPedido:\n" . implode("\n", $lineas);
        $serverKey = getenv('FCM_SERVER_KEY');
        if (!$serverKey) return;
        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: key='.$serverKey], CURLOPT_POSTFIELDS=>json_encode(['registration_ids'=>$tokens,'notification'=>['title'=>'🛒 Nuevo pedido recibido','body'=>$cuerpo,'sound'=>'default'],'data'=>['tipo'=>'nuevo_pedido','venta_id'=>$venta_id,'cliente'=>$nombre,'total'=>$monto,'medida_especial'=>$medida_especial,'tipo_reparacion'=>$tipo_reparacion],'priority'=>'high']), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5]);
        curl_exec($ch); curl_close($ch);
    } catch (Exception $e) { error_log('Error al notificar admin FCM: ' . $e->getMessage()); }
}
