<?php
/**
 * setup_db.php — Script de inicialización / migración de base de datos.
 * Ejecutar UNA VEZ al desplegar o cuando la base de datos esté vacía.
 * GET /setup_db.php?token=ADMIN_SECRET
 */

require_once 'config.php';
require_method('GET');

$token  = trim($_GET['token'] ?? '');
$secret = getenv('ADMIN_SECRET') ?: 'tarimas_admin_2024';

if ($token !== $secret) {
    json_response(['error' => 'Token inválido'], 403);
}

$db  = getDB();
$log = [];

$sqls = [
    // usuarios
    "CREATE TABLE IF NOT EXISTS usuarios (
        id            VARCHAR(36) PRIMARY KEY,
        nombre        VARCHAR(100) NOT NULL,
        usuario       VARCHAR(30) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        rol           ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal',
        activo        TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_acceso DATETIME DEFAULT NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    // clientes
    "CREATE TABLE IF NOT EXISTS clientes (
        id        VARCHAR(36) PRIMARY KEY,
        nombre    VARCHAR(150) NOT NULL,
        telefono  VARCHAR(30) DEFAULT '',
        direccion VARCHAR(250) DEFAULT '',
        notas     TEXT DEFAULT NULL,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    // inventario tarimas
    "CREATE TABLE IF NOT EXISTS inventario (
        tipo            VARCHAR(50) PRIMARY KEY,
        stock_actual    INT NOT NULL DEFAULT 0,
        stock_minimo    INT NOT NULL DEFAULT 0,
        unidad          VARCHAR(20) NOT NULL DEFAULT 'piezas',
        actualizado_por VARCHAR(36) DEFAULT NULL,
        actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    // inventario madera
    "CREATE TABLE IF NOT EXISTS inventario_madera (
        tipo            VARCHAR(50) PRIMARY KEY,
        stock_actual    INT NOT NULL DEFAULT 0,
        stock_minimo    INT NOT NULL DEFAULT 0,
        unidad          VARCHAR(20) NOT NULL DEFAULT 'tablas',
        actualizado_por VARCHAR(36) DEFAULT NULL,
        actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    // historial inventario
    "CREATE TABLE IF NOT EXISTS historial_inventario (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        tipo          VARCHAR(50) NOT NULL,
        stock_antes   INT NOT NULL DEFAULT 0,
        stock_despues INT NOT NULL DEFAULT 0,
        motivo        VARCHAR(50) NOT NULL DEFAULT 'ajuste_manual',
        referencia_id VARCHAR(36) DEFAULT NULL,
        cambiado_por  VARCHAR(36) DEFAULT NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    // ventas
    "CREATE TABLE IF NOT EXISTS ventas (
        id               VARCHAR(36) PRIMARY KEY,
        cliente_id       VARCHAR(36) DEFAULT NULL,
        nombre_cliente   VARCHAR(150) NOT NULL DEFAULT '',
        total            DECIMAL(10,2) NOT NULL DEFAULT 0,
        metodo_pago      VARCHAR(30) NOT NULL DEFAULT 'transferencia',
        monto_recibido   DECIMAL(10,2) DEFAULT NULL,
        estado_pago      VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        registrada_por   VARCHAR(36) DEFAULT NULL,
        medida_especial  VARCHAR(100) DEFAULT '',
        tipo_reparacion  VARCHAR(100) DEFAULT '',
        creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    // detalle ventas
    "CREATE TABLE IF NOT EXISTS detalle_ventas (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        venta_id    VARCHAR(36) NOT NULL,
        tipo        VARCHAR(50) NOT NULL,
        cantidad    INT NOT NULL DEFAULT 1,
        precio_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
        INDEX idx_venta_id (venta_id)
    )",
    // pedidos
    "CREATE TABLE IF NOT EXISTS pedidos (
        id              VARCHAR(36) PRIMARY KEY,
        cliente_id      VARCHAR(36) DEFAULT NULL,
        nombre_cliente  VARCHAR(150) DEFAULT '',
        telefono        VARCHAR(30) DEFAULT '',
        direccion       VARCHAR(250) DEFAULT '',
        tipo            VARCHAR(50) NOT NULL,
        cantidad        INT NOT NULL DEFAULT 0,
        costo_unit      DECIMAL(10,2) NOT NULL DEFAULT 0,
        estado          VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        fecha_entrega   DATE DEFAULT NULL,
        notas           TEXT DEFAULT NULL,
        registrado_por  VARCHAR(36) DEFAULT NULL,
        creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    // precios
    "CREATE TABLE IF NOT EXISTS precios (
        tipo        VARCHAR(50) PRIMARY KEY,
        precio      DECIMAL(10,2) NOT NULL DEFAULT 0,
        descripcion VARCHAR(200) DEFAULT '',
        activo      TINYINT(1) NOT NULL DEFAULT 1,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    // fcm tokens
    "CREATE TABLE IF NOT EXISTS fcm_tokens (
        id         BIGINT AUTO_INCREMENT PRIMARY KEY,
        usuario_id VARCHAR(36) NOT NULL,
        token      VARCHAR(300) NOT NULL,
        activo     TINYINT(1) NOT NULL DEFAULT 1,
        creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token(100))
    )",
];

foreach ($sqls as $sql) {
    try {
        $db->exec($sql);
        $log[] = '✅ OK: ' . substr(trim($sql), 0, 60) . '...';
    } catch (\Throwable $e) {
        $log[] = '❌ ERROR: ' . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 60);
    }
}

// Datos iniciales
$inserts = [
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('tarima_nueva',0,0,'piezas')", 'inventario tarima_nueva'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('estandar',0,0,'piezas')", 'inventario estandar'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('encachetada',0,0,'piezas')", 'inventario encachetada'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('barrote',0,0,'piezas')", 'inventario barrote'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('tacon',0,0,'piezas')", 'inventario tacon'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('especial',0,0,'piezas')", 'inventario especial'],
    ["INSERT IGNORE INTO inventario (tipo,stock_actual,stock_minimo,unidad) VALUES ('reparacion',0,0,'piezas')", 'inventario reparacion'],
    ["INSERT IGNORE INTO inventario_madera (tipo,stock_actual,stock_minimo,unidad) VALUES ('pino',0,50,'tablas')", 'madera pino'],
    ["INSERT IGNORE INTO inventario_madera (tipo,stock_actual,stock_minimo,unidad) VALUES ('oyamel',0,50,'tablas')", 'madera oyamel'],
];

foreach ($inserts as [$sql, $desc]) {
    try {
        $db->exec($sql);
        $log[] = "✅ Dato inicial: $desc";
    } catch (\Throwable $e) {
        $log[] = "⚠️  Dato inicial $desc: " . $e->getMessage();
    }
}

// Vistas
$views = [
    "CREATE OR REPLACE VIEW v_ventas_hoy AS
        SELECT v.*, GROUP_CONCAT(CONCAT(dv.tipo,':',dv.cantidad,':',dv.precio_unit) SEPARATOR '|') AS detalle
          FROM ventas v
          LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
         WHERE DATE(v.creado_en) = CURDATE()
         GROUP BY v.id ORDER BY v.creado_en DESC",
    "CREATE OR REPLACE VIEW v_pedidos_activos AS
        SELECT * FROM pedidos WHERE estado NOT IN ('entregado','cancelado') ORDER BY creado_en DESC",
    "CREATE OR REPLACE VIEW v_reporte_mensual AS
        SELECT YEAR(creado_en) AS anio, MONTH(creado_en) AS mes,
               COUNT(*) AS total_ventas, SUM(total) AS monto_total
          FROM ventas GROUP BY YEAR(creado_en), MONTH(creado_en)",
];

foreach ($views as $sql) {
    try {
        $db->exec($sql);
        $log[] = '✅ Vista creada';
    } catch (\Throwable $e) {
        $log[] = '⚠️  Vista: ' . $e->getMessage();
    }
}

// Migración: quitar rol cajero si existe
try {
    $db->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','usuario_normal') NOT NULL DEFAULT 'usuario_normal'");
    $log[] = '✅ Migración rol ENUM (sin cajero)';
} catch (\Throwable $e) {
    $log[] = 'ℹ️  Migración rol: ' . $e->getMessage();
}

json_response(['success' => true, 'log' => $log]);
