<?php
/**
 * inventario.php — Consulta y ajuste de inventario.
 *
 * GET /inventario.php         → Inventario general (todos los tipos de tarima)
 * GET /inventario.php?madera  → Inventario de madera (pino, oyamel)
 * PUT /inventario.php         → Ajuste manual de stock (tarimas o madera)
 */

require_once 'config.php';
require_method('GET', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Todos los tipos de tarima que maneja el sistema
$tiposValidos = [
    'tarima_nueva',
    'estandar',
    'encachetada',
    'barrote',
    'tacon',
    'especial',
    'reparacion',
];

// Tipos de madera (en tabla separada inventario_madera)
$tiposMadera = ['pino', 'oyamel'];

if ($method === 'GET') {
    if (isset($_GET['madera'])) {
        $stmt = $db->query('SELECT tipo, stock_actual, stock_minimo, unidad, actualizado_en FROM inventario_madera ORDER BY tipo');
    } else {
        // Devolver todos los tipos de tarima con sus etiquetas legibles
        $stmt = $db->query(
            "SELECT tipo, stock_actual, stock_minimo, unidad, actualizado_en
               FROM inventario
              ORDER BY FIELD(tipo,
                'tarima_nueva','estandar','encachetada','barrote','tacon','especial','reparacion'
              )"
        );
    }
    json_response($stmt->fetchAll());
}

if ($method === 'PUT') {
    $data         = get_input();
    $tipo         = trim($data['tipo']         ?? '');
    $stock_actual = $data['stock_actual']      ?? null;
    $usuario_id   = $data['usuario_id']        ?? null;
    $esMadera     = !empty($data['madera']);

    if ($tipo === '' || $stock_actual === null) {
        json_response(['error' => 'tipo y stock_actual son requeridos'], 400);
    }

    if (!is_numeric($stock_actual) || (int)$stock_actual < 0) {
        json_response(['error' => 'stock_actual debe ser un número mayor o igual a 0'], 400);
    }

    // Determinar automáticamente si es madera aunque no se envíe la bandera
    if (!$esMadera && in_array($tipo, $tiposMadera, true)) {
        $esMadera = true;
    }

    // Validar que el tipo sea reconocido
    if (!$esMadera && !in_array($tipo, $tiposValidos, true)) {
        json_response(['error' => "Tipo '$tipo' no reconocido. Tipos válidos: " . implode(', ', array_merge($tiposValidos, $tiposMadera))], 400);
    }

    if ($esMadera) {
        // ── Ajuste de madera ──────────────────────────────────────────────────
        $stmt = $db->prepare('SELECT stock_actual FROM inventario_madera WHERE tipo = ?');
        $stmt->execute([$tipo]);
        $antes = $stmt->fetchColumn();

        if ($antes === false) {
            // Insertar si no existe el tipo de madera
            $db->prepare(
                'INSERT INTO inventario_madera (tipo, stock_actual, stock_minimo, unidad, actualizado_por, actualizado_en)
                 VALUES (?, ?, 0, "tablas", ?, NOW())'
            )->execute([$tipo, (int)$stock_actual, $usuario_id]);
            $antes = 0;
        } else {
            $db->prepare(
                'UPDATE inventario_madera SET stock_actual = ?, actualizado_por = ?, actualizado_en = NOW() WHERE tipo = ?'
            )->execute([(int)$stock_actual, $usuario_id, $tipo]);
        }

        // Historial (si existe la tabla)
        try {
            $db->prepare(
                "INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, cambiado_por)
                 VALUES (?, ?, ?, 'ajuste_manual_madera', ?)"
            )->execute([$tipo, (int)$antes, (int)$stock_actual, $usuario_id]);
        } catch (\PDOException $ignored) {}

    } else {
        // ── Ajuste de tarima ──────────────────────────────────────────────────
        $stmt = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
        $stmt->execute([$tipo]);
        $antes = $stmt->fetchColumn();

        if ($antes === false) {
            // Insertar el tipo si no existe (útil para tipos nuevos)
            $db->prepare(
                'INSERT INTO inventario (tipo, stock_actual, stock_minimo, unidad, actualizado_por, actualizado_en)
                 VALUES (?, ?, 0, "piezas", ?, NOW())'
            )->execute([$tipo, (int)$stock_actual, $usuario_id]);
            $antes = 0;
        } else {
            $db->prepare(
                'UPDATE inventario SET stock_actual = ?, actualizado_por = ?, actualizado_en = NOW() WHERE tipo = ?'
            )->execute([(int)$stock_actual, $usuario_id, $tipo]);
        }

        // Historial
        try {
            $db->prepare(
                "INSERT INTO historial_inventario (tipo, stock_antes, stock_despues, motivo, cambiado_por)
                 VALUES (?, ?, ?, 'ajuste_manual', ?)"
            )->execute([$tipo, (int)$antes, (int)$stock_actual, $usuario_id]);
        } catch (\PDOException $ignored) {}
    }

    json_response(['success' => true, 'tipo' => $tipo, 'stock_nuevo' => (int)$stock_actual]);
}
