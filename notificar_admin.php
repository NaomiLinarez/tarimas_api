<?php
/**
 * notificar_admin.php — Envía una notificación push FCM a todos los admins.
 *
 * POST /notificar_admin.php
 * Body: {
 *   "titulo":   "Texto del título",
 *   "cuerpo":   "Texto del cuerpo",
 *   "venta_id": "uuid (opcional)",
 *   "tipo":     "nuevo_pedido (opcional)",
 *   "destino":  "admin"
 * }
 *
 * Requiere variable de entorno FCM_SERVER_KEY configurada en el servidor.
 */

require_once 'config.php';
require_method('POST');

$data           = get_input();
$titulo         = trim($data['titulo']          ?? '🛒 Nuevo pedido');
$cuerpo         = trim($data['cuerpo']          ?? 'Se registró un nuevo pedido');
$ventaId        = trim($data['venta_id']        ?? '');
$tipo           = trim($data['tipo']            ?? 'nuevo_pedido');
$destino        = trim($data['destino']         ?? 'admin');
$medidaEspecial = trim($data['medida_especial'] ?? '');
$tipoReparacion = trim($data['tipo_reparacion'] ?? '');

if ($destino !== 'admin') {
    json_response(['error' => 'destino no válido'], 400);
}

$serverKey = getenv('FCM_SERVER_KEY');
if (!$serverKey) {
    error_log('FCM_SERVER_KEY no configurada');
    json_response(['error' => 'Servicio de notificaciones no configurado'], 500);
}

// ── Obtener tokens FCM de todos los admins activos ────────────────────────────

$db   = getDB();
$stmt = $db->prepare("
    SELECT ft.token
      FROM fcm_tokens ft
      JOIN usuarios u ON u.id = ft.usuario_id
     WHERE u.rol = 'admin'
       AND ft.activo = 1
");
$stmt->execute();
$tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tokens)) {
    json_response(['success' => true, 'enviados' => 0, 'mensaje' => 'Sin admins registrados']);
}

// ── Enviar notificación FCM (Legacy HTTP API) ─────────────────────────────────

$payload = [
    'registration_ids' => $tokens,
    'notification'     => [
        'title' => $titulo,
        'body'  => $cuerpo,
        'sound' => 'default',
    ],
    'data' => [
        'tipo'            => $tipo,
        'venta_id'        => $ventaId,
        'titulo'          => $titulo,
        'cuerpo'          => $cuerpo,
        'medida_especial' => $medidaEspecial,
        'tipo_reparacion' => $tipoReparacion,
    ],
    'priority' => 'high',
];

$ch = curl_init('https://fcm.googleapis.com/fcm/send');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: key=' . $serverKey,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr) {
    error_log('FCM curl error: ' . $curlErr);
    json_response(['error' => 'Error al conectar con FCM'], 500);
}

$fcmResult = json_decode($response, true);

if ($httpCode !== 200) {
    error_log('FCM HTTP error ' . $httpCode . ': ' . $response);
    json_response(['error' => 'FCM respondió con error ' . $httpCode], 500);
}

json_response([
    'success'  => true,
    'enviados' => count($tokens),
    'fcm'      => [
        'success' => $fcmResult['success'] ?? 0,
        'failure' => $fcmResult['failure'] ?? 0,
    ],
]);
