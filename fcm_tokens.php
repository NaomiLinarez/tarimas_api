<?php
/**
 * fcm_tokens.php — Registro y gestión de tokens FCM para notificaciones push.
 *
 * POST /fcm_tokens.php
 * Body: {
 *   "usuario_id": "uuid",
 *   "token":      "fcm-token-del-dispositivo",
 *   "plataforma": "android"
 * }
 */

require_once 'config.php';
require_method('POST');

$data       = get_input();
$usuario_id = trim($data['usuario_id'] ?? '');
$token      = trim($data['token']      ?? '');
$plataforma = trim($data['plataforma'] ?? 'android');

if ($usuario_id === '' || $token === '') {
    json_response(['error' => 'usuario_id y token son requeridos'], 400);
}
if (!in_array($plataforma, ['android', 'ios'], true)) {
    $plataforma = 'android';
}

$db = getDB();

// Migrar tabla si le faltan columnas (compatible con estructura antigua de setup_db.php)
try {
    $cols = $db->query("SHOW COLUMNS FROM fcm_tokens")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('plataforma', $cols)) {
        $db->exec("ALTER TABLE fcm_tokens ADD COLUMN plataforma VARCHAR(10) NOT NULL DEFAULT 'android'");
    }
    if (!in_array('actualizado', $cols)) {
        $db->exec("ALTER TABLE fcm_tokens ADD COLUMN actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    // Asegurar que activo exista
    if (!in_array('activo', $cols)) {
        $db->exec("ALTER TABLE fcm_tokens ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
    }
} catch (\Throwable $e) {
    error_log('fcm_tokens migrate: ' . $e->getMessage());
}

// Upsert: buscar por token
$stmt = $db->prepare('SELECT id FROM fcm_tokens WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare(
        'UPDATE fcm_tokens SET usuario_id = ?, plataforma = ?, activo = 1 WHERE token = ?'
    )->execute([$usuario_id, $plataforma, $token]);
} else {
    // id puede ser BIGINT AUTO_INCREMENT o VARCHAR — insertamos sin id para que funcione en ambos casos
    try {
        $db->prepare(
            'INSERT INTO fcm_tokens (usuario_id, token, plataforma, activo) VALUES (?, ?, ?, 1)'
        )->execute([$usuario_id, $token, $plataforma]);
    } catch (\Throwable $e) {
        // Si falla por id NOT NULL (estructura antigua), intentar con uuid
        $db->prepare(
            'INSERT INTO fcm_tokens (id, usuario_id, token, plataforma, activo) VALUES (?, ?, ?, ?, 1)'
        )->execute([uuid4(), $usuario_id, $token, $plataforma]);
    }
}

json_response(['success' => true]);
