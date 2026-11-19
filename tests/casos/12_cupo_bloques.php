<?php
declare(strict_types=1);

// Cupo por bloque AM/PM (SPEC-v2 §4.2; plan 3.2). Decisión del 19-11-2026: mañana y tarde tienen cada una el cupo del equipo.

function ocupB(PDO $db, string $eq, string $f): array
{
    $o = reg_ocupacion_bloques($db, eid($db, $eq), $f, $f)[$f] ?? ['am' => 0.0, 'pm' => 0.0];
    return [$o['am'], $o['pm']];
}

prueba('Bloques: con cupo 2, el tercer medio día de la MAÑANA queda sin cupo (antes cabían 4)', function () {
    $db = nuevaDb();
    $p = array_map(fn($i) => mk($db, "Persona $i", 'diurno', 'H7'), range(1, 4));
    $am = fn($id) => ['funcionario_id' => $id, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am'];
    crear($db, $am($p[0])); crear($db, $am($p[1]));
    chequear(ocupB($db, 'H7', '2026-12-10') === [2.0, 0.0], 'mañana 2 de 2, tarde libre');
    $e = lanza(fn() => crear($db, $am($p[2])), 409);
    chequear(($e->extra['dias_llenos'][0]['bloques'] ?? null) === ['am'], 'informa que se llenó la mañana: ' . json_encode($e->extra['dias_llenos'] ?? null));
    crear($db, ['funcionario_id' => $p[2], 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'pm']);
    chequear(ocupB($db, 'H7', '2026-12-10') === [2.0, 1.0], 'la tarde sí tiene cupo');
});

prueba('Bloques: un día completo ocupa mañana y tarde', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7'); $b = mk($db, 'Beto Dos', 'diurno', 'H7'); $c = mk($db, 'Carla Tres', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    crear($db, ['funcionario_id' => $b, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    chequear(ocupB($db, 'H7', '2026-12-10') === [2.0, 1.0], 'completo + AM = mañana 2, tarde 1');
    lanza(fn() => crear($db, ['funcionario_id' => $c, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']), 409);
    crear($db, ['funcionario_id' => $c, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'pm']);
    chequear(ocupB($db, 'H7', '2026-12-10') === [2.0, 2.0], 'un PM completa la tarde');
});

prueba('Bloques: FL ≥ 5 hábiles de titular no ocupa ningún bloque; FL de honorarios ocupa ambos', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7', 'titular'); $h = mk($db, 'Hono Rario', 'diurno', 'H7', 'honorarios');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);
    chequear(ocupB($db, 'H7', '2026-12-16') === [0.0, 0.0], 'FL largo de titular: 0 y 0');
    crear($db, ['funcionario_id' => $h, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);
    chequear(ocupB($db, 'H7', '2026-12-16') === [1.0, 1.0], 'FL de honorarios: 1 y 1');
});

prueba('Migración 5: los días ya guardados pasan a bloques según su fracción', function () {
    global $tmp;
    $p = $tmp . DIRECTORY_SEPARATOR . 'mig5.sqlite';
    $db = db_open($p);
    db_migrar($db, null, array_slice(migraciones(), 0, 4, true));
    db_sembrar_equipos($db);
    $db->exec("INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad) VALUES ('Ana Uno','ana uno','12345678-5','diurno',1,'titular')");
    $db->exec("INSERT INTO solicitudes (codigo,funcionario_id,nombre,nombre_norm,rut,jornada,equipo_id,calidad,tipo,fecha_inicio,fecha_fin,estado,creado_en)
               VALUES ('AUS-2026-0001',1,'Ana Uno','ana uno','12345678-5','diurno',1,'titular','ADM','2026-12-10','2026-12-12','pendiente','2026-12-01')");
    $ins = $db->prepare('INSERT INTO solicitud_dias (solicitud_id,fecha,equipo_id,consumo,fraccion) VALUES (1,?,1,?,?)');
    $ins->execute(['2026-12-10', 1.0, 'completo']); $ins->execute(['2026-12-11', 0.5, 'am']); $ins->execute(['2026-12-12', 0.5, 'pm']); $ins->execute(['2026-12-13', 0.0, 'completo']);
    db_migrar($db, $p);
    $r = $db->query('SELECT fecha, am, pm FROM solicitud_dias ORDER BY fecha')->fetchAll(PDO::FETCH_NUM);
    chequear($r == [['2026-12-10', 1.0, 1.0], ['2026-12-11', 1.0, 0.0], ['2026-12-12', 0.0, 1.0], ['2026-12-13', 0.0, 0.0]], 'completo→1/1, am→1/0, pm→0/1, sin consumo→0/0: ' . json_encode($r));
    $db = null;
});

prueba('Concurrencia por bloque: cuatro procesos piden la última mañana → solo uno la obtiene', function () {
    $db = nuevaDb();
    $db->exec("UPDATE equipos SET cupo_diurno=1 WHERE nombre='H7'");
    $ids = array_map(fn($i) => mk($db, "Persona $i", 'diurno', 'H7'), range(1, 4));
    $db = null;
    global $dbpath;
    $inicio = microtime(true) + 1.5;
    $procs = [];
    foreach ($ids as $id) {
        $procs[] = [proc_open([PHP_BINARY, TESTS_DIR . '/worker.php', $dbpath, (string)$id, '2026-12-10', (string)$inicio, 'am'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp), $pp];
    }
    $res = [];
    foreach ($procs as [$pr, $pp]) { $res[] = trim((string)stream_get_contents($pp[1])) . trim((string)stream_get_contents($pp[2])); proc_close($pr); }
    $oks = count(array_filter($res, fn($r) => str_starts_with($r, 'OK')));
    chequear($oks === 1 && count(array_filter($res, fn($r) => str_starts_with($r, 'SIN_CUPO'))) === 3, '1 OK y 3 SIN_CUPO: ' . implode(' | ', $res));
    $db = db_open($dbpath);
    chequear(ocupB($db, 'H7', '2026-12-10') === [1.0, 0.0], 'mañana exactamente 1, tarde 0');
    $db = null;
});

prueba('Calendario y bandeja informan la ocupación por bloque', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    $cal = cal_datos($db, '2026-12', false);
    chequear(($cal['ausencias'][0]['am'] ?? null) === 1.0 && ($cal['ausencias'][0]['pm'] ?? null) === 0.0, 'cada ausencia trae am y pm');
    $_GET = ['estado' => 'pendiente'];
    $l = h_coord_solicitudes($db, [], []);
    $_GET = [];
    $o = $l['solicitudes'][0]['ocupacion'][0] ?? [];
    chequear(($o['am'] ?? null) === 1.0 && ($o['pm'] ?? null) === 0.0 && ($o['cupo'] ?? null) === 2.0, 'la bandeja muestra mañana y tarde: ' . json_encode($o));
});
