<?php
declare(strict_types=1);

prueba('Migraciones: base nueva queda en la última versión y repetir no cambia nada', function () {
    global $tmp;
    $p = $tmp . DIRECTORY_SEPARATOR . 'mig_nueva.sqlite';
    $db = db_open($p);
    $aplicadas = db_migrar($db);
    $ultima = max(array_keys(migraciones()));
    chequear(db_version($db) === $ultima, 'versión = última (' . $ultima . ')');
    chequear(count($aplicadas) === $ultima, 'aplicó todas las migraciones');
    chequear(db_migrar($db) === [], 'la segunda vez no aplica nada');
    chequear((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='solicitudes'")->fetchColumn() === 1, 'existe la tabla solicitudes');
});

prueba('Migraciones: una base antigua (sin versión) se migra sin perder datos y con respaldo previo', function () {
    global $tmp;
    $p = $tmp . DIRECTORY_SEPARATOR . 'mig_antigua.sqlite';
    $db = db_open($p);
    db_esquema_v1($db);                       // como la crea la versión publicada hoy (user_version = 0)
    db_sembrar_equipos($db);
    $db->exec("INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad) VALUES ('Ana Uno','ana uno','12345678-5','diurno',1,'titular')");
    chequear(db_version($db) === 0, 'parte en versión 0');
    $aplicadas = db_migrar($db, $p);
    chequear(db_version($db) === max(array_keys(migraciones())) && $aplicadas !== [], 'quedó en la última versión');
    chequear((int)$db->query('SELECT COUNT(*) FROM funcionarios')->fetchColumn() === 1, 'conserva los funcionarios');
    chequear((int)$db->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 19, 'conserva los equipos');
    $resp = glob($p . '.antes-v*.sqlite') ?: [];
    chequear(count($resp) === 1, 'dejó un respaldo antes de migrar: ' . implode(', ', array_map('basename', $resp)));
    if ($resp) {
        $r = db_open($resp[0]);
        chequear((int)$r->query('SELECT COUNT(*) FROM funcionarios')->fetchColumn() === 1 && db_version($r) === 0, 'el respaldo es la base tal como estaba');
        $r = null;
    }
});

prueba('Migraciones: si una falla, se revierte y la versión no avanza', function () {
    global $tmp;
    $p = $tmp . DIRECTORY_SEPARATOR . 'mig_falla.sqlite';
    $db = db_open($p);
    $lista = [1 => 'db_esquema_v1', 2 => function (PDO $d) { $d->exec('CREATE TABLE prueba_x (id INTEGER)'); throw new RuntimeException('falla simulada'); }];
    try { db_migrar($db, null, $lista); chequear(false, 'debía lanzar la excepción'); } catch (RuntimeException $e) { chequear(str_contains($e->getMessage(), 'falla simulada'), 'propaga el error'); }
    chequear(db_version($db) === 1, 'quedó en la versión 1, no en la 2');
    chequear((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='prueba_x'")->fetchColumn() === 0, 'la migración fallida no dejó restos');
});

prueba('Migraciones: la app migra sola al abrir una base antigua (sin SSH en DreamHost)', function () {
    $app = appTemporal([]);
    $p = $app . '/datos.sqlite';
    @unlink($p);
    $db = db_open($p); db_esquema_v1($db); db_sembrar_equipos($db); $db = null;   // base "publicada" sin versión
    $r = json_decode(pedirApi($app, 'GET', 'auth/sesion'), true);   // cualquier petición abre la base
    chequear(is_array($r) && array_key_exists('autenticado', $r), 'la API responde normal');
    $db = db_open($p);
    chequear(db_version($db) === max(array_keys(migraciones())), 'la base quedó migrada a la última versión');
    $db = null;
});

