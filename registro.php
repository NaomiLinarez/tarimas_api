<?php
/**
 * registro.php — Creación de nuevos usuarios.
 * CORREGIDO:
 *  - Solo hay 2 roles: admin y usuario_normal (sin cajero)
 *  - Token admin viene de env ADMIN_SECRET
 *  - Se muestra token en respuesta de éxito para admin (solo para referencia)
 *  - Validaciones robustas
 */

require_once 'config.php';
require_method('POST');

$data      = get_input();
$nombre    = trim($data['nombre']      ?? '');
$usuario   = trim($data['usuario']     ?? '');
$password  = trim($data['password']    ?? '');
$rolPedido = trim($data['rol']         ?? 'usuario_normal');
$token     = trim($data['admin_token'] ?? '');

// ── Roles válidos ─────────────────────────────────────────────────────────────
$rolesValidos = ['admin', 'usuario_normal'];
if (!in_array($rolPedido, $rolesValidos, true)) {
    json_response(['error' => "Rol '$rolPedido' no válido. Use: admin o usuario_normal"], 400);
}

// ── Validar token admin ───────────────────────────────────────────────────────
$adminSecret = getenv('ADMIN_SECRET') ?: 'tarimas_admin_2024';

if ($rolPedido === 'admin') {
    if ($token === '') {
        json_response(['error' => 'Se requiere admin_token para crear una cuenta de administrador', 'token_requerido' => true], 403);
    }
    if ($token !== $adminSecret) {
        json_response(['error' => 'Token de administrador inválido'], 403);
    }
    $rol = 'admin';
} else {
    $rol = 'usuario_normal';
}

// ── Validaciones de campos ────────────────────────────────────────────────────
if ($nombre === '' || $usuario === '' || $password === '') {
    json_response(['error' => 'Todos los campos son requeridos: nombre, usuario, password'], 400);
}
if (strlen($password) < 6) {
    json_response(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $usuario)) {
    json_response(['error' => 'El usuario solo puede tener letras, números y _ (3–30 caracteres)'], 400);
}

$db = getDB();

// ── Asegurar tabla usuarios existe con ENUM correcto ─────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id            VARCHAR(36) PRIMARY KEY,
        nombre        VARCHAR(100) NOT NULL,
        usuario       VARCHAR(30) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        rol           ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal',
        activo        TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_acceso DATETIME DEFAULT NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

// Migrar ENUM si existe cajero (legacy)
try {
    $db->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal'");
} catch (\Throwable $e) {}

// ── Verificar usuario único ───────────────────────────────────────────────────
$stmt = $db->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
$stmt->execute([$usuario]);
if ($stmt->fetch()) {
    json_response(['error' => 'Ese nombre de usuario ya está en uso'], 409);
}

// ── Crear usuario ─────────────────────────────────────────────────────────────
$id            = uuid4();
$password_hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $db->prepare(
        'INSERT INTO usuarios (id, nombre, usuario, password_hash, rol, activo, creado_en)
         VALUES (?, ?, ?, ?, ?, 1, NOW())'
    )->execute([$id, $nombre, $usuario, $password_hash, $rol]);
} catch (\Throwable $e) {
    error_log('registro error: ' . $e->getMessage());
    json_response(['error' => 'Error al crear usuario: ' . $e->getMessage()], 500);
}

$response = ['success' => true, 'usuario_id' => $id, 'rol' => $rol];
// Informar al admin el token que se usó para crear la cuenta
if ($rol === 'admin') {
    $response['nota'] = 'Cuenta de administrador creada. Guarda el admin_token de forma segura.';
}
json_response($response, 201);
