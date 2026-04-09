<?php
/**
 * reporte.php — Reporte mensual de ventas.
 *
 * GET /reporte.php         → Últimos 12 meses
 * GET /reporte.php?anio=X  → Año específico
 */

require_once 'config.php';
require_method('GET');

$db   = getDB();
$anio = $_GET['anio'] ?? null;

if ($anio !== null) {
    if (!preg_match('/^\d{4}$/', $anio)) {
        json_response(['error' => 'Formato de año inválido'], 400);
    }
    $stmt = $db->prepare('SELECT * FROM v_reporte_mensual WHERE anio = ? ORDER BY mes DESC');
    $stmt->execute([$anio]);
} else {
    $stmt = $db->query('SELECT * FROM v_reporte_mensual ORDER BY anio DESC, mes DESC LIMIT 12');
}

json_response($stmt->fetchAll());
