<?php
/**
 * registro.php — Creación de nuevos usuarios.
 *
 * POST normal     → crea usuario_normal (sin privilegios)
 * POST con rol=admin + admin_token válido → crea admin
 *
 * El admin_token se lee de la variable de entorno ADMIN_SECRET.
 * Si no está definida, se usa el valor por defecto "tarimas_admin_2024"
 * (cámbialo en Railway → Variables).
 */

require_once 'config.php';
require_method('POST');

$data     = get_input();
$nombre   = trim($data['nombre']       ?? '');
$usuario  = trim($data['usuario']      ?? '');
$password = trim($data['password']     ?? '');
$rolPedido= trim($data['rol']          ?? 'usuario_normal');
$token    = trim($data['admin_token']  ?? '');

// ── Determinar rol real ───────────────────────────────────────────────────────
$adminSecret = getenv('ADMIN_SECRET') ?: 'tarimas_admin_2024';

if ($rolPedido === 'admin') {
    if ($token === '' || $token !== $adminSecret) {
        json_response(['error' => 'Token de administrador inválido'], 403);
    }
    $rol = 'admin';
} else {
    $rol = 'usuario_normal';
}

// ── Validaciones ──────────────────────────────────────────────────────────────
if ($nombre === '' || $usuario === '' || $password === '') {
    json_response(['error' => 'Todos los campos son requeridos'], 400);
}
if (strlen($password) < 6) {
    json_response(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $usuario)) {
    json_response(['error' => 'El usuario solo puede tener letras, números y _ (3–30 caracteres)'], 400);
}

$db = getDB();

// Asegurar ENUM correcto
try {
    $db->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal'");
} catch (\Throwable $e) {}

// Verificar usuario único
$stmt = $db->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
$stmt->execute([$usuario]);
if ($stmt->fetch()) {
    json_response(['error' => 'Ese nombre de usuario ya está en uso'], 409);
}

// Crear usuario
$id            = uuid4();
$password_hash = password_hash($password, PASSWORD_BCRYPT);

$db->prepare(
    'INSERT INTO usuarios (id, nombre, usuario, password_hash, rol, activo, creado_en)
     VALUES (?, ?, ?, ?, ?, 1, NOW())'
)->execute([$id, $nombre, $usuario, $password_hash, $rol]);

json_response(['success' => true, 'usuario_id' => $id, 'rol' => $rol], 201);
