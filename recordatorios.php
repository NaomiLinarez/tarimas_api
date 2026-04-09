<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM recordatorios ORDER BY completado ASC, fecha_aviso ASC");
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data       = get_input();
    $titulo     = trim($data['titulo']      ?? '');
    $descripcion = trim($data['descripcion'] ?? '');
    $fecha_aviso = $data['fecha_aviso']     ?? null;
    $creado_por  = $data['creado_por']      ?? null;

    if (!$titulo) {
        json_response(['error' => 'El título es requerido'], 400);
    }

    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $db->prepare("INSERT INTO recordatorios (id, titulo, descripcion, fecha_aviso, creado_por) VALUES (?, ?, ?, ?, ?)")
       ->execute([$id, $titulo, $descripcion, $fecha_aviso, $creado_por]);

    json_response(['success' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $data       = get_input();
    $id         = $data['id']         ?? '';
    $completado = $data['completado'] ?? null;

    if (!$id || $completado === null) {
        json_response(['error' => 'id y completado requeridos'], 400);
    }

    $db->prepare("UPDATE recordatorios SET completado = ? WHERE id = ?")
       ->execute([$completado ? 1 : 0, $id]);

    json_response(['success' => true]);
}

json_response(['error' => 'Método no permitido'], 405);
