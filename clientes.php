<?php
/**
 * clientes.php — Gestión de clientes.
 *
 * GET  /clientes.php      → Lista todos los clientes activos
 * GET  /clientes.php?q=X  → Búsqueda por nombre
 * POST /clientes.php      → Crear cliente
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET ───────────────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $buscar = trim($_GET['q'] ?? '');

    if ($buscar !== '') {
        $stmt = $db->prepare(
            'SELECT id, nombre, telefono, direccion
               FROM clientes
              WHERE activo = 1
                AND (nombre LIKE ? OR telefono LIKE ?)
              ORDER BY nombre
              LIMIT 50'
        );
        $like = '%' . $buscar . '%';
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $db->query(
            'SELECT id, nombre, telefono, direccion
               FROM clientes
              WHERE activo = 1
              ORDER BY nombre
              LIMIT 200'
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

    // Verificar duplicado por nombre exacto (case-insensitive en MySQL con collation ci)
    $stmt = $db->prepare('SELECT id FROM clientes WHERE nombre = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$nombre]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Ya existe un cliente con ese nombre'], 409);
    }

    $cliente_id = uuid4();
    $db->prepare(
        'INSERT INTO clientes (id, nombre, telefono, direccion, notas) VALUES (?, ?, ?, ?, ?)'
    )->execute([$cliente_id, $nombre, $telefono, $direccion, $notas]);

    json_response(['success' => true, 'cliente_id' => $cliente_id], 201);
}
