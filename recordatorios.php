<?php
/**
 * recordatorios.php — Recordatorios y avisos.
 *
 * GET  /recordatorios.php → Lista todos los recordatorios
 * POST /recordatorios.php → Crear recordatorio
 * PUT  /recordatorios.php → Marcar como completado/pendiente
 */

require_once 'config.php';
require_method('GET', 'POST', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $stmt = $db->query(
        'SELECT * FROM recordatorios ORDER BY completado ASC, fecha_aviso ASC'
    );
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data        = get_input();
    $titulo      = trim($data['titulo']      ?? '');
    $descripcion = trim($data['descripcion'] ?? '');
    $fecha_aviso = $data['fecha_aviso']      ?? null;
    $creado_por  = $data['creado_por']       ?? null;

    if ($titulo === '') {
        json_response(['error' => 'El título es requerido'], 400);
    }

    if ($fecha_aviso && !preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha_aviso)) {
        json_response(['error' => 'Formato de fecha_aviso inválido'], 400);
    }

    $id = uuid4();
    $db->prepare(
        'INSERT INTO recordatorios (id, titulo, descripcion, fecha_aviso, creado_por)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$id, $titulo, $descripcion, $fecha_aviso, $creado_por]);

    json_response(['success' => true, 'id' => $id], 201);
}

if ($method === 'PUT') {
    $data       = get_input();
    $id         = trim($data['id']         ?? '');
    $completado = $data['completado']      ?? null;

    if ($id === '' || $completado === null) {
        json_response(['error' => 'id y completado son requeridos'], 400);
    }

    $stmt = $db->prepare('UPDATE recordatorios SET completado = ? WHERE id = ?');
    $stmt->execute([$completado ? 1 : 0, $id]);

    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Recordatorio no encontrado'], 404);
    }

    json_response(['success' => true]);
}
