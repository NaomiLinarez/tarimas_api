<?php
/**
 * clientes.php — Gestión de clientes.
 * CORREGIDO:
 *  - Historial de compras siempre se registra al crear venta
 *  - Búsqueda por pedidos busca tanto por cliente_id como por nombre_cliente
 *  - Se garantiza que compras_mes y total_compras estén disponibles
 *
 * GET  /clientes.php                        → Lista todos los clientes activos
 * GET  /clientes.php?q=X                    → Búsqueda por nombre/teléfono
 * GET  /clientes.php?pedidos=1&id=UUID      → Historial de compras por ID
 * GET  /clientes.php?pedidos=1&nombre=X     → Historial de compras por nombre
 * POST /clientes.php                        → Crear cliente
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Asegurar tabla clientes
try {
    $db->exec("CREATE TABLE IF NOT EXISTS clientes (
        id        VARCHAR(36) PRIMARY KEY,
        nombre    VARCHAR(150) NOT NULL,
        telefono  VARCHAR(30)  DEFAULT '',
        direccion VARCHAR(250) DEFAULT '',
        notas     TEXT         DEFAULT NULL,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // ── Historial de compras (historial del cliente) ───────────────────────
    if (isset($_GET['pedidos'])) {
        $cliente_id = trim($_GET['id']     ?? '');
        $nombre     = trim($_GET['nombre'] ?? '');

        if ($cliente_id === '' && $nombre === '') {
            json_response(['error' => 'Se requiere id o nombre'], 400);
        }

        // Datos del cliente
        $clienteInfo = null;
        if ($cliente_id !== '') {
            $s = $db->prepare('SELECT id, nombre, telefono, direccion FROM clientes WHERE id = ? AND activo = 1 LIMIT 1');
            $s->execute([$cliente_id]);
            $clienteInfo = $s->fetch() ?: null;
        }
        if (!$clienteInfo && $nombre !== '') {
            $s = $db->prepare('SELECT id, nombre, telefono, direccion FROM clientes WHERE nombre LIKE ? AND activo = 1 LIMIT 1');
            $s->execute(['%' . $nombre . '%']);
            $clienteInfo = $s->fetch() ?: null;
        }

        $pedidos = [];

        // Buscar por cliente_id primero
        if ($cliente_id !== '') {
            try {
                $stmt = $db->prepare("
                    SELECT v.id, v.nombre_cliente, v.total, v.metodo_pago,
                           v.estado_pago, v.medida_especial, v.tipo_reparacion, v.creado_en,
                           GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit)
                               ORDER BY dv.tipo SEPARATOR '|') AS detalle
                      FROM ventas v
                      LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
                     WHERE v.cliente_id = ?
                     GROUP BY v.id ORDER BY v.creado_en DESC LIMIT 200
                ");
                $stmt->execute([$cliente_id]);
                $pedidos = $stmt->fetchAll();
            } catch (\Throwable $e) { error_log('historial by id: ' . $e->getMessage()); }
        }

        // Si no hay resultados por ID, buscar por nombre
        if (empty($pedidos)) {
            $buscarNombre = $clienteInfo ? $clienteInfo['nombre'] : $nombre;
            if ($buscarNombre !== '') {
                try {
                    $stmt = $db->prepare("
                        SELECT v.id, v.nombre_cliente, v.total, v.metodo_pago,
                               v.estado_pago, v.medida_especial, v.tipo_reparacion, v.creado_en,
                               GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit)
                                   ORDER BY dv.tipo SEPARATOR '|') AS detalle
                          FROM ventas v
                          LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
                         WHERE v.nombre_cliente LIKE ?
                         GROUP BY v.id ORDER BY v.creado_en DESC LIMIT 200
                    ");
                    $stmt->execute(['%' . $buscarNombre . '%']);
                    $pedidos = $stmt->fetchAll();
                } catch (\Throwable $e) { error_log('historial by nombre: ' . $e->getMessage()); }
            }
        }

        // Calcular estadísticas del cliente
        $total_gastado = array_sum(array_column($pedidos, 'total'));

        json_response([
            'cliente'        => $clienteInfo ?: ['nombre' => $nombre],
            'pedidos'        => $pedidos,
            'total_pedidos'  => count($pedidos),
            'total_gastado'  => round($total_gastado, 2),
        ]);
        return;
    }

    // ── Lista / búsqueda normal con estadísticas ───────────────────────────
    $buscar = trim($_GET['q'] ?? '');

    if ($buscar !== '') {
        $stmt = $db->prepare(
            'SELECT id, nombre, telefono, direccion
               FROM clientes
              WHERE activo = 1 AND (nombre LIKE ? OR telefono LIKE ?)
              ORDER BY nombre LIMIT 50'
        );
        $like = '%' . $buscar . '%';
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $db->query(
            'SELECT id, nombre, telefono, direccion
               FROM clientes WHERE activo = 1 ORDER BY nombre LIMIT 200'
        );
    }

    $clientes = $stmt->fetchAll();

    // Enriquecer con conteo de compras del mes actual
    try {
        $mesActual = date('Y-m');
        foreach ($clientes as &$c) {
            $s = $db->prepare(
                "SELECT COUNT(*) as compras_mes, COALESCE(SUM(total),0) as total_mes
                   FROM ventas
                  WHERE (cliente_id = ? OR nombre_cliente = ?)
                    AND DATE_FORMAT(creado_en,'%Y-%m') = ?"
            );
            $s->execute([$c['id'], $c['nombre'], $mesActual]);
            $stats = $s->fetch();
            $c['compras_mes'] = (int)($stats['compras_mes'] ?? 0);
            $c['total_mes']   = round((float)($stats['total_mes'] ?? 0), 2);
        }
        unset($c);
    } catch (\Throwable $e) { error_log('stats clientes: ' . $e->getMessage()); }

    json_response($clientes);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data      = get_input();
    $nombre    = trim($data['nombre']    ?? '');
    $telefono  = trim($data['telefono']  ?? '');
    $direccion = trim($data['direccion'] ?? '');
    $notas     = trim($data['notas']     ?? '');

    if ($nombre === '') {
        json_response(['error' => 'El nombre es requerido'], 400);
    }

    $stmt = $db->prepare('SELECT id FROM clientes WHERE nombre = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$nombre]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Ya existe un cliente con ese nombre'], 409);
    }

    $cliente_id = uuid4();
    try {
        $db->prepare('INSERT INTO clientes (id, nombre, telefono, direccion, notas) VALUES (?, ?, ?, ?, ?)')
           ->execute([$cliente_id, $nombre, $telefono, $direccion, $notas]);
    } catch (\Throwable $e) {
        error_log('crear cliente: ' . $e->getMessage());
        json_response(['error' => 'Error al crear cliente: ' . $e->getMessage()], 500);
    }

    json_response(['success' => true, 'cliente_id' => $cliente_id], 201);
}
