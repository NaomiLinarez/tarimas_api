<?php
/**
 * notificar_admin.php — Envía notificación push FCM V1 a todos los admins.
 * Usa la API V1 (OAuth2 con cuenta de servicio) en lugar de la legacy.
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

// ── Leer cuenta de servicio desde variable de entorno ─────────────────────────
$serviceAccountJson = getenv('FCM_SERVICE_ACCOUNT_JSON');
if (!$serviceAccountJson) {
    error_log('FCM_SERVICE_ACCOUNT_JSON no configurada');
    json_response(['success' => true, 'enviados' => 0, 'mensaje' => 'Notificaciones no configuradas']);
}

$serviceAccount = json_decode($serviceAccountJson, true);
if (!$serviceAccount) {
    error_log('FCM_SERVICE_ACCOUNT_JSON inválido');
    json_response(['success' => true, 'enviados' => 0, 'mensaje' => 'Config inválida']);
}

$projectId = $serviceAccount['project_id'] ?? '';
if (!$projectId) {
    json_response(['error' => 'project_id no encontrado en la cuenta de servicio'], 500);
}

// ── Obtener tokens FCM de todos los admins activos ────────────────────────────
$db   = getDB();

// Crear tabla si no existe
try {
    $db->exec("CREATE TABLE IF NOT EXISTS fcm_tokens (
        id          CHAR(36)    NOT NULL PRIMARY KEY,
        usuario_id  CHAR(36)    NOT NULL,
        token       TEXT        NOT NULL,
        plataforma  VARCHAR(10) NOT NULL DEFAULT 'android',
        activo      TINYINT(1)  NOT NULL DEFAULT 1,
        creado_en   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token(255)),
        INDEX idx_usuario (usuario_id)
    )");
} catch (\Throwable $e) {}

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
    json_response(['success' => true, 'enviados' => 0, 'mensaje' => 'Sin admins con token registrado']);
}

// ── Obtener Access Token OAuth2 ───────────────────────────────────────────────
function getAccessToken(array $serviceAccount): string {
    $now = time();
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode([
        'iss'   => $serviceAccount['client_email'],
        'sub'   => $serviceAccount['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    ]));

    $header  = str_replace(['+', '/', '='], ['-', '_', ''], $header);
    $payload = str_replace(['+', '/', '='], ['-', '_', ''], $payload);

    $signingInput = "$header.$payload";
    $privateKey   = $serviceAccount['private_key'];

    openssl_sign($signingInput, $signature, $privateKey, 'SHA256');
    $sig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = "$signingInput.$sig";

    // Intercambiar JWT por access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return $data['access_token'] ?? '';
}

$accessToken = getAccessToken($serviceAccount);
if (!$accessToken) {
    error_log('No se pudo obtener access token de FCM');
    json_response(['success' => true, 'enviados' => 0, 'mensaje' => 'Error de autenticación FCM']);
}

// ── Enviar notificación a cada token (API V1 — 1 request por token) ───────────
$enviados = 0;
$errores  = 0;
$fcmUrl   = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

foreach ($tokens as $token) {
    $body = json_encode([
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $titulo,
                'body'  => $cuerpo,
            ],
            'data' => [
                'tipo'            => $tipo,
                'venta_id'        => $ventaId,
                'medida_especial' => $medidaEspecial,
                'tipo_reparacion' => $tipoReparacion,
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound'        => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
        ],
    ]);

    $ch = curl_init($fcmUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $enviados++;
    } else {
        $errores++;
        error_log("FCM V1 error token={$token} code={$httpCode} resp={$resp}");

        // Si el token es inválido, desactivarlo
        $result = json_decode($resp, true);
        $status = $result['error']['status'] ?? '';
        if (in_array($status, ['INVALID_ARGUMENT', 'NOT_FOUND', 'UNREGISTERED'], true)) {
            try {
                $db->prepare("UPDATE fcm_tokens SET activo = 0 WHERE token = ?")
                   ->execute([$token]);
            } catch (\Throwable $ignored) {}
        }
    }
}

json_response([
    'success'  => true,
    'enviados' => $enviados,
    'errores'  => $errores,
]);
