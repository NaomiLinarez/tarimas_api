<?php
/**
 * registro.php — Creación de nuevos usuarios.
 *
 * POST /registro.php
 * Body: {
 *   "nombre":   "Juan Pérez",
 *   "usuario":  "juanp",
 *   "password": "contraseña123",
 *   "rol":      "cajero"   // opcional, default: cajero
 * }
 *
 * Respuesta exitosa:
 * { "success": true, "usuario_id": "uuid" }
 */

require_once 'config.php';
require_method('POST');

$data     = get_input();
$nombre   = trim($data['nombre']   ?? '');
$usuario  = trim($data['usuario']  ?? '');
$password = trim($data['password'] ?? '');
$rol      = trim($data['rol']      ?? 'cajero');

// ── Validaciones ─────────────────────────────────────────────────────────────

if ($nombre === '' || $usuario === '' || $password === '') {
    json_response(['error' => 'nombre, usuario y password son requeridos'], 400);
}

if (strlen($password) < 6) {
    json_response(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}

$rolesPermitidos = ['admin', 'cajero', 'vendedor'];
if (!in_array($rol, $rolesPermitidos, true)) {
    json_response(['error' => 'Rol no válido. Usa: admin, cajero o vendedor'], 400);
}

// Solo letras, números y guion bajo para el nombre de usuario
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $usuario)) {
    json_response(['error' => 'El usuario solo puede tener letras, números y _ (3–30 caracteres)'], 400);
}

$db = getDB();

// ── Verificar que el usuario no exista ───────────────────────────────────────

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
