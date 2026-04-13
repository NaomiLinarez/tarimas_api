<?php
/**
 * notificaciones.php — Gestión de notificaciones del admin.
 *
 * GET  /notificaciones.php              → Lista notificaciones + conteo no leídas (badge)
 * GET  /notificaciones.php?badge=1      → Solo devuelve el número de no leídas
 * POST /notificaciones.php              → Marcar notificaciones como leídas
 *   Body: {}                            → Marca TODAS como leídas
 *   Body: { "id": "uuid" }             → Marca UNA como leída
 */

require_once 'config.php';
require_method('GET', 'POST');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Asegurar tabla
try {
    $db->exec("CREATE TABLE IF NOT EXISTS admin_notificaciones (
        id             CHAR(36)     NOT NULL PRIMARY KEY,
        tipo           VARCHAR(50)  NOT NULL DEFAULT 'nuevo_pedido',
        titulo         VARCHAR(200) NOT NULL DEFAULT '',
        cuerpo         TEXT         NOT NULL,
        venta_id       VARCHAR(36)  DEFAULT NULL,
        cliente_id     VARCHAR(36)  DEFAULT NULL,
        nombre_cliente VARCHAR(150) DEFAULT NULL,
        leida          TINYINT(1)   NOT NULL DEFAULT 0,
        creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_leida (leida)
    )");
    try { $db->exec("ALTER TABLE admin_notificaciones ADD COLUMN cliente_id VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $ignored) {}
    try { $db->exec("ALTER TABLE admin_notificaciones ADD COLUMN nombre_cliente VARCHAR(150) DEFAULT NULL"); } catch (\Throwable $ignored) {}
} catch (\Throwable $e) {}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Solo badge count
    if (isset($_GET['badge'])) {
        try {
            $r = $db->query("SELECT COUNT(*) FROM admin_notificaciones WHERE leida = 0");
            json_response(['badge' => (int) $r->fetchColumn()]);
        } catch (\Throwable $e) {
            json_response(['badge' => 0]);
        }
        return;
    }

    // Lista completa con badge
    try {
        $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
        $stmt = $db->prepare(
            "SELECT id, tipo, titulo, cuerpo, venta_id, cliente_id, nombre_cliente, leida, creado_en
               FROM admin_notificaciones
              ORDER BY creado_en DESC
              LIMIT ?"
        );
        $stmt->execute([$limite]);
        $notifs = $stmt->fetchAll();

        $r     = $db->query("SELECT COUNT(*) FROM admin_notificaciones WHERE leida = 0");
        $badge = (int) $r->fetchColumn();

        json_response([
            'badge'          => $badge,
            'notificaciones' => $notifs,
            'total'          => count($notifs),
        ]);
    } catch (\Throwable $e) {
        error_log('GET notificaciones: ' . $e->getMessage());
        json_response(['error' => 'Error al obtener notificaciones: ' . $e->getMessage()], 500);
    }
}

// ── POST — marcar como leídas ────────────────────────────────────────────────
if ($method === 'POST') {
    $data = get_input();
    $id   = trim($data['id'] ?? '');

    try {
        if ($id !== '') {
            // Marcar una sola
            $db->prepare("UPDATE admin_notificaciones SET leida = 1 WHERE id = ?")
               ->execute([$id]);
        } else {
            // Marcar todas
            $db->exec("UPDATE admin_notificaciones SET leida = 1 WHERE leida = 0");
        }

        $r     = $db->query("SELECT COUNT(*) FROM admin_notificaciones WHERE leida = 0");
        $badge = (int) $r->fetchColumn();

        json_response(['success' => true, 'badge' => $badge]);
    } catch (\Throwable $e) {
        error_log('POST notificaciones: ' . $e->getMessage());
        json_response(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
    }
}
