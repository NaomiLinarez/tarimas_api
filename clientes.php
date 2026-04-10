<?php
/**
 * clientes.php — Gestión de clientes.
 *
 * GET  /clientes.php                        → Lista todos los clientes activos
 * GET  /clientes.php?q=X                    → Búsqueda por nombre/teléfono
 * GET  /clientes.php?pedidos=1&id=UUID      → Pedidos de un cliente por ID
 * GET  /clientes.php?pedidos=1&nombre=X     → Pedidos de un cliente por nombre
 * POST /clientes.php                        → Crear cliente
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // ── Pedidos de un cliente (para panel de admin) ────────────────────────
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

        // Pedidos (ventas) del cliente — busca por cliente_id Y por nombre_cliente
        $pedidos = [];
        if ($cliente_id !== '') {
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
        }

        // Si no hay pedidos por ID o solo tenemos nombre, buscar por nombre_cliente
        if (empty($pedidos) && $nombre !== '') {
            $buscarNombre = $clienteInfo ? $clienteInfo['nombre'] : $nombre;
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
        }

        json_response([
            'cliente'       => $clienteInfo ?: ['nombre' => $nombre],
            'pedidos'       => $pedidos,
            'total_pedidos' => count($pedidos),
        ]);
        return;
    }

    // ── Lista / búsqueda normal ────────────────────────────────────────────
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

    json_response($stmt->fetchAll());
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
    $db->prepare('INSERT INTO clientes (id, nombre, telefono, direccion, notas) VALUES (?, ?, ?, ?, ?)')
       ->execute([$cliente_id, $nombre, $telefono, $direccion, $notas]);

    json_response(['success' => true, 'cliente_id' => $cliente_id], 201);
}
