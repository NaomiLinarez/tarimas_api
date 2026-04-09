<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

$data = get_input();
$usuario  = trim($data['usuario']  ?? '');
$password = trim($data['password'] ?? '');

if (!$usuario || !$password) {
    json_response(['error' => 'Usuario y contraseña requeridos'], 400);
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, nombre, usuario, password_hash, activo FROM usuarios WHERE usuario = ? LIMIT 1");
$stmt->execute([$usuario]);
$user = $stmt->fetch();

if (!$user || !$user['activo']) {
    json_response(['error' => 'Usuario o contraseña incorrectos'], 401);
}

if (!password_verify($password, $user['password_hash'])) {
    json_response(['error' => 'Usuario o contraseña incorrectos'], 401);
}

$db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")->execute([$user['id']]);

json_response([
    'success' => true,
    'usuario' => [
        'id'      => $user['id'],
        'nombre'  => $user['nombre'],
        'usuario' => $user['usuario'],
    ]
]);
