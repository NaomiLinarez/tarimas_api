<?php
/**
 * fcm_tokens.php — Registro y gestión de tokens FCM para notificaciones push.
 *
 * POST /fcm_tokens.php
 * Body: {
 *   "usuario_id": "uuid",
 *   "token":      "fcm-token-del-dispositivo",
 *   "plataforma": "android"   // android | ios
 * }
 *
 * La tabla que necesitas crear en MySQL:
 *
 *   CREATE TABLE fcm_tokens (
 *     id           CHAR(36)     NOT NULL PRIMARY KEY,
 *     usuario_id   CHAR(36)     NOT NULL,
 *     token        TEXT         NOT NULL,
 *     plataforma   VARCHAR(10)  NOT NULL DEFAULT 'android',
 *     activo       TINYINT(1)   NOT NULL DEFAULT 1,
 *     creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     actualizado  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                               ON UPDATE CURRENT_TIMESTAMP,
 *     UNIQUE KEY uq_token (token(255)),
 *     INDEX idx_usuario (usuario_id)
 *   );
 */

require_once 'config.php';
require_method('POST');

$data       = get_input();
$usuario_id = trim($data['usuario_id'] ?? '');
$token      = trim($data['token']      ?? '');
$plataforma = trim($data['plataforma'] ?? 'android');

// ── Validaciones ─────────────────────────────────────────────────────────────

if ($usuario_id === '' || $token === '') {
    json_response(['error' => 'usuario_id y token son requeridos'], 400);
}

if (!in_array($plataforma, ['android', 'ios'], true)) {
    $plataforma = 'android';
}

$db = getDB();

// ── Upsert: insertar o actualizar si el token ya existe ──────────────────────
// Esto maneja el caso donde el mismo dispositivo se re-registra o cambia de usuario.

$stmt = $db->prepare('SELECT id, usuario_id FROM fcm_tokens WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$existing = $stmt->fetch();

if ($existing) {
    // Actualizar el usuario asociado al token (puede haber cambiado)
    $db->prepare(
        'UPDATE fcm_tokens
            SET usuario_id  = ?,
                plataforma  = ?,
                activo      = 1,
                actualizado = NOW()
          WHERE token = ?'
    )->execute([$usuario_id, $plataforma, $token]);
} else {
    // Insertar nuevo token
    $db->prepare(
        'INSERT INTO fcm_tokens (id, usuario_id, token, plataforma)
         VALUES (?, ?, ?, ?)'
    )->execute([uuid4(), $usuario_id, $token, $plataforma]);
}

json_response(['success' => true]);
