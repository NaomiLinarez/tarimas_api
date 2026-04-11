<?php
/**
 * inventario.php — Consulta y ajuste de inventario.
 * CORREGIDO: error en UPDATE stock, tipos faltantes, historial_inventario auto-create
 */

require_once 'config.php';
require_method('GET', 'PUT');

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

$tiposValidos = ['tarima_nueva','estandar','encachetada','barrote','tacon','especial','reparacion'];
$tiposMadera  = ['pino','oyamel'];

// ── Inicialización segura de tablas ──────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS inventario (
        tipo            VARCHAR(50) PRIMARY KEY,
        stock_actual    INT NOT NULL DEFAULT 0,
        stock_minimo    INT NOT NULL DEFAULT 0,
        unidad          VARCHAR(20) NOT NULL DEFAULT 'piezas',
        actualizado_por VARCHAR(36) DEFAULT NULL,
        actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $stmt = $db->prepare("INSERT IGNORE INTO inventario (tipo, stock_actual, stock_minimo, unidad) VALUES (?, 0, 0, 'piezas')");
    foreach ($tiposValidos as $t) { $stmt->execute([$t]); }
} catch (\Throwable $e) { error_log('inventario init: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS inventario_madera (
        tipo            VARCHAR(50) PRIMARY KEY,
        stock_actual    INT NOT NULL DEFAULT 0,
        stock_minimo    INT NOT NULL DEFAULT 0,
        unidad          VARCHAR(20) NOT NULL DEFAULT 'tablas',
        actualizado_por VARCHAR(36) DEFAULT NULL,
        actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $db->exec("INSERT IGNORE INTO inventario_madera (tipo,stock_actual,stock_minimo,unidad) VALUES ('pino',0,50,'tablas'),('oyamel',0,50,'tablas')");
} catch (\Throwable $e) { error_log('inventario_madera init: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS historial_inventario (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        tipo          VARCHAR(50) NOT NULL,
        stock_antes   INT NOT NULL DEFAULT 0,
        stock_despues INT NOT NULL DEFAULT 0,
        motivo        VARCHAR(50) NOT NULL DEFAULT 'ajuste_manual',
        referencia_id VARCHAR(36) DEFAULT NULL,
        cambiado_por  VARCHAR(36) DEFAULT NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) { error_log('historial init: ' . $e->getMessage()); }

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['madera'])) {
        try {
            $stmt = $db->query("SELECT tipo, stock_actual, stock_minimo, unidad, actualizado_en FROM inventario_madera ORDER BY tipo");
            json_response($stmt->fetchAll());
        } catch (\Throwable $e) {
            json_response([]);
        }
    } else {
        try {
            $stmt = $db->query(
                "SELECT tipo, stock_actual, stock_minimo, unidad, actualizado_en
                   FROM inventario
                  ORDER BY FIELD(tipo,'tarima_nueva','estandar','encachetada','barrote','tacon','especial','reparacion')"
            );
            $rows = $stmt->fetchAll();
            // Garantizar que TODOS los tipos aparezcan aunque tengan stock 0
            $tiposPresentes = array_column($rows, 'tipo');
            foreach ($tiposValidos as $t) {
                if (!in_array($t, $tiposPresentes, true)) {
                    $rows[] = ['tipo'=>$t,'stock_actual'=>0,'stock_minimo'=>0,'unidad'=>'piezas','actualizado_en'=>null];
                }
            }
            json_response($rows);
        } catch (\Throwable $e) {
            error_log('GET inventario: ' . $e->getMessage());
            json_response(['error' => 'Error al obtener inventario: ' . $e->getMessage()], 500);
        }
    }
}

// ── PUT ───────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $data       = get_input();
    $tipo       = trim($data['tipo']    ?? '');
    $stock_raw  = $data['stock_actual'] ?? null;
    $usuario_id = $data['usuario_id']   ?? null;
    $esMadera   = !empty($data['madera']) || in_array($tipo, $tiposMadera, true);

    if ($tipo === '' || $stock_raw === null) {
        json_response(['error' => 'tipo y stock_actual son requeridos'], 400);
    }
    if (!is_numeric($stock_raw) || (int)$stock_raw < 0) {
        json_response(['error' => 'stock_actual debe ser un número >= 0'], 400);
    }

    $nuevo = (int)$stock_raw;

    try {
        if ($esMadera) {
            if (!in_array($tipo, $tiposMadera, true)) {
                json_response(['error' => "Tipo de madera '$tipo' no válido. Use: pino, oyamel"], 400);
            }
            $stmt = $db->prepare('SELECT stock_actual FROM inventario_madera WHERE tipo = ?');
            $stmt->execute([$tipo]);
            $antes = $stmt->fetchColumn();
            if ($antes === false) {
                $db->prepare("INSERT INTO inventario_madera (tipo,stock_actual,stock_minimo,unidad,actualizado_por,actualizado_en) VALUES (?,?,0,'tablas',?,NOW())")
                   ->execute([$tipo, $nuevo, $usuario_id]);
                $antes = 0;
            } else {
                $db->prepare('UPDATE inventario_madera SET stock_actual=?, actualizado_por=?, actualizado_en=NOW() WHERE tipo=?')
                   ->execute([$nuevo, $usuario_id, $tipo]);
            }
            $motivo = 'ajuste_manual_madera';
        } else {
            if (!in_array($tipo, $tiposValidos, true)) {
                json_response(['error' => "Tipo '$tipo' no válido. Use: " . implode(', ', $tiposValidos)], 400);
            }
            $stmt = $db->prepare('SELECT stock_actual FROM inventario WHERE tipo = ?');
            $stmt->execute([$tipo]);
            $antes = $stmt->fetchColumn();
            if ($antes === false) {
                $db->prepare("INSERT INTO inventario (tipo,stock_actual,stock_minimo,unidad,actualizado_por,actualizado_en) VALUES (?,?,0,'piezas',?,NOW())")
                   ->execute([$tipo, $nuevo, $usuario_id]);
                $antes = 0;
            } else {
                $db->prepare('UPDATE inventario SET stock_actual=?, actualizado_por=?, actualizado_en=NOW() WHERE tipo=?')
                   ->execute([$nuevo, $usuario_id, $tipo]);
            }
            $motivo = 'ajuste_manual';
        }

        try {
            $db->prepare("INSERT INTO historial_inventario (tipo,stock_antes,stock_despues,motivo,cambiado_por) VALUES (?,?,?,?,?)")
               ->execute([$tipo, (int)$antes, $nuevo, $motivo, $usuario_id]);
        } catch (\Throwable $ignored) {}

        json_response(['success' => true, 'tipo' => $tipo, 'stock_nuevo' => $nuevo]);

    } catch (\Throwable $e) {
        error_log('PUT inventario: ' . $e->getMessage());
        json_response(['error' => 'Error al actualizar stock: ' . $e->getMessage()], 500);
    }
}
