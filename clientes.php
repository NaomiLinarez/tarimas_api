<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $buscar = $_GET['q'] ?? '';
    if ($buscar) {
        $stmt = $db->prepare("SELECT id, nombre, telefono, direccion FROM clientes WHERE activo = 1 AND nombre LIKE ? ORDER BY nombre");
        $stmt->execute(['%' . $buscar . '%']);
    } else {
        $stmt = $db->query("SELECT id, nombre, telefono, direccion FROM clientes WHERE activo = 1 ORDER BY nombre");
    }
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data     = get_input();
    $nombre   = trim($data['nombre']   ?? '');
    $telefono = trim($data['telefono'] ?? '');
    $direccion = trim($data['direccion'] ?? '');
    $notas    = trim($data['notas']    ?? '');

    if (!$nombre) {
        json_response(['error' => 'El nombre es requerido'], 400);
    }

    $cliente_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $db->prepare("INSERT INTO clientes (id, nombre, telefono, direccion, notas) VALUES (?, ?, ?, ?, ?)")
       ->execute([$cliente_id, $nombre, $telefono, $direccion, $notas]);

    json_response(['success' => true, 'cliente_id' => $cliente_id]);
}

json_response(['error' => 'Método no permitido'], 405);
