<?php
/**
 * ventas.php — Registro y consulta de ventas.
 * CORREGIDO v2:
 *  - Crea tabla historial_inventario al inicio (evita SQLSTATE al insertar historial)
 *  - Auto-crea cliente en tabla clientes si no existe (para que aparezca en la pantalla Clientes)
 *  - Descuenta stock correctamente con transacción
 *  - Manejo robusto de errores
 *
 * GET  /ventas.php                       → Ventas de hoy
 * GET  /ventas.php?fecha=YYYY-MM-DD      → Ventas de una fecha
 * GET  /ventas.php?nombre=Juan           → Ventas de un cliente por nombre
 * GET  /ventas.php?cliente_id=UUID       → Ventas de un cliente por ID
 * POST /ventas.php                       → Registrar nueva venta
 */

require_once 'config.php';
require_method('GET', 'POST', 'DELETE');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── Asegurar tablas ──────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ventas (
        id               VARCHAR(36) PRIMARY KEY,
        cliente_id       VARCHAR(36) DEFAULT NULL,
        nombre_cliente   VARCHAR(150) NOT NULL DEFAULT '',
        total            DECIMAL(10,2) NOT NULL DEFAULT 0,
        metodo_pago      VARCHAR(30) NOT NULL DEFAULT 'transferencia',
        monto_recibido   DECIMAL(10,2) DEFAULT NULL,
        estado_pago      VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        registrada_por   VARCHAR(36) DEFAULT NULL,
        medida_especial  VARCHAR(100) DEFAULT '',
        tipo_reparacion  VARCHAR(100) DEFAULT '',
        creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS detalle_ventas (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        venta_id    VARCHAR(36) NOT NULL,
        tipo        VARCHAR(50) NOT NULL,
        cantidad    INT NOT NULL DEFAULT 1,
        precio_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
        INDEX idx_venta_id (venta_id)
    )");
    // ✅ FIX: Crear historial_inventario aquí para evitar SQLSTATE al insertar
    $db->exec("CREATE TABLE IF NOT EXISTS historial_inventario (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        tipo          VARCHAR(50) NOT NULL,
        stock_antes   INT NOT NULL DEFAULT 0,
        stock_despues INT NOT NULL DEFAULT 0,
        motivo        VARCHAR(50) NOT NULL DEFAULT 'ajuste_manual',
        referencia_id VARCHAR(36) DEFAULT NULL,
        cambiado_por  VARCHAR(36) DEFAULT NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    // ✅ FIX: Asegurar tabla clientes también
    $db->exec("CREATE TABLE IF NOT EXISTS clientes (
        id        VARCHAR(36) PRIMARY KEY,
        nombre    VARCHAR(150) NOT NULL,
        telefono  VARCHAR(30)  DEFAULT '',
        direccion VARCHAR(250) DEFAULT '',
        notas     TEXT         DEFAULT NULL,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    // Vista para ventas de hoy
    $db->exec("CREATE OR REPLACE VIEW v_ventas_hoy AS
        SELECT v.*, GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit) SEPARATOR '|') AS detalle
          FROM ventas v
          LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
         WHERE DATE(v.creado_en) = CURDATE()
         GROUP BY v.id
         ORDER BY v.creado_en DESC");
    // ✅ FIX: Migrar columnas que pueden no existir en tablas ya creadas
    foreach ([
        "ALTER TABLE ventas ADD COLUMN medida_especial VARCHAR(100) DEFAULT '' AFTER registrada_por",
        "ALTER TABLE ventas ADD COLUMN tipo_reparacion VARCHAR(100) DEFAULT '' AFTER medida_especial",
    ] as $alterSql) {
        try { $db->exec($alterSql); } catch (\Throwable $ignored) {}
    }
} catch (\Throwable $e) { error_log('ventas init: ' . $e->getMessage()); }

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $fecha      = $_GET['fecha']      ?? null;
        $nombre     = trim($_GET['nombre'] ?? '');
        $cliente_id = trim($_GET['cliente_id'] ?? '');

        if ($nombre !== '') {
            $stmt = $db->prepare("
                SELECT v.*,
                       GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit) SEPARATOR '|') AS detalle
                  FROM ventas v
                  LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
                 WHERE v.nombre_cliente LIKE ?
                 GROUP BY v.id ORDER BY v.creado_en DESC LIMIT 100
            ");
            $stmt->execute(['%' . $nombre . '%']);
            json_response($stmt->fetchAll());
        }

        if ($cliente_id !== '') {
            $stmt = $db->prepare("
                SELECT v.*,
                       GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit) SEPARATOR '|') AS detalle
                  FROM ventas v
                  LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
                 WHERE v.cliente_id = ?
                 GROUP BY v.id ORDER BY v.creado_en DESC LIMIT 100
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
                       GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit) SEPARATOR '|') AS detalle
                  FROM ventas v
                  LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
                 WHERE DATE(v.creado_en) = ?
                 GROUP BY v.id ORDER BY v.creado_en DESC
            ");
            $stmt->execute([$fecha]);
        } else {
            $stmt = $db->query('SELECT * FROM v_ventas_hoy');
        }

        json_response($stmt->fetchAll());
    } catch (\Throwable $e) {
        error_log('GET ventas: ' . $e->getMessage());
        json_response(['error' => 'Error al obtener ventas: ' . $e->getMessage()], 500);
    }
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data            = get_input();
    $nombre_cliente  = trim($data['nombre_cliente']  ?? '');
    $cliente_id      = $data['cliente_id']       ?? null;
    $total           = $data['total']            ?? 0;
    $metodo_pago     = $data['metodo_pago']      ?? 'transferencia';
    $monto_recibido  = $data['monto_recibido']   ?? null;
    $estado_pago     = $data['estado_pago']      ?? 'pendiente';
    $registrada_por  = $data['registrada_por']   ?? null;
    $medida_especial = trim($data['medida_especial'] ?? '');
    $tipo_reparacion = trim($data['tipo_reparacion']  ?? '');
    $detalle         = $data['detalle']          ?? [];

    if (empty($total) || empty($detalle)) {
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

    // ✅ FIX: Auto-buscar cliente_id por nombre; si no existe, CREAR el cliente
    if (!$cliente_id && $nombre_cliente !== '') {
        try {
            $s = $db->prepare('SELECT id FROM clientes WHERE nombre = ? AND activo = 1 LIMIT 1');
            $s->execute([$nombre_cliente]);
            $row = $s->fetch();
            if ($row) {
                $cliente_id = $row['id'];
            } else {
                // Crear cliente nuevo automáticamente para que aparezca en la pantalla Clientes
                $cliente_id = uuid4();
                $db->prepare('INSERT INTO clientes (id, nombre, telefono, direccion, notas) VALUES (?, ?, ?, ?, ?)')
                   ->execute([$cliente_id, $nombre_cliente, '', '', '']);
            }
        } catch (\Throwable $e) {
            error_log('auto-crear cliente: ' . $e->getMessage());
        }
    }

    // Verificar stock antes de iniciar transacción
    try {
        $stmtCheckStock = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
        $labels = [
            'tarima_nueva'=>'Tarima nueva','estandar'=>'Tarima estándar',
            'encachetada'=>'Tarima encachetada','barrote'=>'Tarima de barrote',
            'tacon'=>'Tarima de tacón','especial'=>'Medida especial','reparacion'=>'Reparación',
        ];
        foreach ($detalle as $item) {
            $stmtCheckStock->execute([$item['tipo']]);
            $row = $stmtCheckStock->fetch();
            if ($row === false) continue;
            $stockDisponible = (int) $row['stock_actual'];
            $cantSolicitada  = (int) $item['cantidad'];
            if ($stockDisponible < $cantSolicitada) {
                $label = $labels[$item['tipo']] ?? $item['tipo'];
                json_response([
                    'error' => "Stock insuficiente de {$label}. Disponibles: {$stockDisponible}, solicitados: {$cantSolicitada}"
                ], 409);
            }
        }
    } catch (\Throwable $e) {
        json_response(['error' => 'Error al verificar stock: ' . $e->getMessage()], 500);
    }

    $db->beginTransaction();
    try {
        $venta_id = uuid4();

        // Detectar qué columnas opcionales existen para no fallar si la DB es antigua
        $colsExist = [];
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM ventas LIKE 'medida_especial'");
            if ($colCheck && $colCheck->rowCount() > 0) $colsExist[] = 'medida_especial';
            $colCheck2 = $db->query("SHOW COLUMNS FROM ventas LIKE 'tipo_reparacion'");
            if ($colCheck2 && $colCheck2->rowCount() > 0) $colsExist[] = 'tipo_reparacion';
        } catch (\Throwable $ignored) {}

        $cols   = 'id, cliente_id, nombre_cliente, total, metodo_pago, monto_recibido, estado_pago, registrada_por';
        $placeholders = '?, ?, ?, ?, ?, ?, ?, ?';
        $vals   = [$venta_id, $cliente_id, $nombre_cliente, $total, $metodo_pago, $monto_recibido, $estado_pago, $registrada_por];
        if (in_array('medida_especial', $colsExist)) { $cols .= ', medida_especial'; $placeholders .= ', ?'; $vals[] = $medida_especial; }
        if (in_array('tipo_reparacion', $colsExist)) { $cols .= ', tipo_reparacion'; $placeholders .= ', ?'; $vals[] = $tipo_reparacion; }

        $db->prepare("INSERT INTO ventas ($cols) VALUES ($placeholders)")->execute($vals);

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
                try {
                    $stmtHistorial->execute([$item['tipo'], $stock_antes, $stock_nuevo, $venta_id, $registrada_por]);
                } catch (\Throwable $ignored) {}
            }
        }

        $db->commit();

        // La notificación al admin la maneja la app directamente vía /notificar_admin.php (FCM V1)
        json_response(['success' => true, 'venta_id' => $venta_id], 201);

    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('POST ventas: ' . $e->getMessage());
        json_response(['error' => 'Error al registrar la venta: ' . $e->getMessage()], 500);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $venta_id = trim($_GET['id'] ?? '');
    if (empty($venta_id)) {
        $data = get_input();
        $venta_id = trim($data['venta_id'] ?? '');
    }
    if (empty($venta_id)) {
        json_response(['error' => 'id de venta requerido'], 400);
    }
    $db->beginTransaction();
    try {
        // Restaurar stock
        $stmtDet  = $db->prepare('SELECT tipo, cantidad FROM detalle_ventas WHERE venta_id = ?');
        $stmtDet->execute([$venta_id]);
        $items = $stmtDet->fetchAll();
        foreach ($items as $item) {
            $db->prepare('UPDATE inventario SET stock_actual = stock_actual + ?, actualizado_en = NOW() WHERE tipo = ?')
               ->execute([(int)$item['cantidad'], $item['tipo']]);
        }
        $db->prepare('DELETE FROM detalle_ventas WHERE venta_id = ?')->execute([$venta_id]);
        $db->prepare('DELETE FROM ventas WHERE id = ?')->execute([$venta_id]);
        $db->commit();
        json_response(['success' => true]);
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('DELETE ventas: ' . $e->getMessage());
        json_response(['error' => 'Error al eliminar la venta: ' . $e->getMessage()], 500);
    }
}
