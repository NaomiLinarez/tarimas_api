<?php
/**
 * registro.php — Creación de nuevos usuarios.
 * El rol siempre se crea como "usuario_normal".
 */

require_once 'config.php';
require_method('POST');

$data     = get_input();
$nombre   = trim($data['nombre']   ?? '');
$usuario  = trim($data['usuario']  ?? '');
$password = trim($data['password'] ?? '');

// Rol fijo — el cliente no puede escoger su propio rol
$rol = 'usuario_normal';

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

// ── Asegurarse de que la columna rol acepte usuario_normal y admin ────────────
// (Ejecutar solo si es necesario — idempotente)
try {
    $db->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal'");
} catch (\Throwable $e) {
    // Si ya está bien o no es un ENUM, continuar sin interrumpir
}

// ── Verificar usuario único ───────────────────────────────────────────────────
$stmt = $db->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
$stmt->execute([$usuario]);
if ($stmt->fetch()) {
    json_response(['error' => 'Ese nombre de usuario ya está en uso'], 409);
}

// ── Crear usuario ─────────────────────────────────────────────────────────────
$id            = uuid4();
$password_hash = password_hash($password, PASSWORD_BCRYPT);

$db->prepare(
    'INSERT INTO usuarios (id, nombre, usuario, password_hash, rol, activo, creado_en)
     VALUES (?, ?, ?, ?, ?, 1, NOW())'
)->execute([$id, $nombre, $usuario, $password_hash, $rol]);

json_response(['success' => true, 'usuario_id' => $id], 201);
