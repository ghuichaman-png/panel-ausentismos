<?php
declare(strict_types=1);

// Tipos de ausentismo configurables (SPEC-v2 §3.5 y §4.9; plan 3.1).

function tipo(PDO $db, string $sigla): array
{
    $st = $db->prepare('SELECT * FROM tipos_ausentismo WHERE sigla=?');
    $st->execute([$sigla]);
    return $st->fetch() ?: [];
}

prueba('Tipos precargados: FL, ADM, CT y LIC con las reglas de SPEC-v2 §3.5', function () {
    $db = nuevaDb();
    $fl = tipo($db, 'FL'); $adm = tipo($db, 'ADM'); $ct = tipo($db, 'CT'); $lic = tipo($db, 'LIC');
    chequear($fl && (int)$fl['consume_cupo'] === 1 && $fl['regla_cupo'] === 'fl' && $fl['saldo'] === 'FL' && $fl['rango'] === 'rango' && (int)$fl['medio_dia'] === 0, 'FL');
    chequear($adm && $adm['saldo'] === 'ADM' && (int)$adm['medio_dia'] === 1 && $adm['rango'] === 'un_dia', 'ADM');
    chequear($ct && (int)$ct['es_cambio_turno'] === 1 && (int)$ct['consume_cupo'] === 0 && $ct['saldo'] === null, 'CT');
    chequear($lic && $lic['lo_pide'] === 'coordinacion' && (int)$lic['consume_cupo'] === 0 && $lic['saldo'] === null && $lic['etiqueta_publica'] === 'Licencia', 'LIC');
    chequear((int)$fl['precargado'] === 1 && (int)$lic['precargado'] === 1, 'marcados como precargados');
});

prueba('Migración 4: reconstruir solicitudes y días conserva todo (ids, datos, días) y admite tipos nuevos', function () {
    global $tmp;
    $p = $tmp . DIRECTORY_SEPARATOR . 'mig4.sqlite';
    $db = db_open($p);
    db_migrar($db, null, array_slice(migraciones(), 0, 3, true));        // base en versión 3 (la publicada antes de esta etapa)
    db_sembrar_equipos($db);
    $db->exec("INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad) VALUES ('Ana Uno','ana uno','12345678-5','diurno',1,'titular')");
    $a = (int)$db->lastInsertId();
    // Filas como las dejaría la versión publicada (SQL directo: el código actual ya espera la tabla de tipos).
    $ins = $db->prepare("INSERT INTO solicitudes (codigo,funcionario_id,nombre,nombre_norm,rut,jornada,equipo_id,calidad,tipo,fecha_inicio,fecha_fin,fraccion,estado,creado_en,creada_por)
                         VALUES (?,?,'Ana Uno','ana uno','12345678-5','diurno',1,'titular',?,?,?,?,?, '2026-12-01 09:00:00', ?)");
    $ins->execute(['AUS-2026-0001', $a, 'FL', '2026-12-14', '2026-12-16', 'completo', 'autorizada', $a]);
    $s1 = ['id' => (int)$db->lastInsertId()];
    $ins->execute(['AUS-2026-0002', $a, 'ADM', '2026-12-18', '2026-12-18', 'am', 'pendiente', $a]);
    $s2 = ['id' => (int)$db->lastInsertId()];
    $d = $db->prepare('INSERT INTO solicitud_dias (solicitud_id,fecha,equipo_id,consumo,fraccion) VALUES (?,?,1,?,?)');
    foreach (['2026-12-14', '2026-12-15', '2026-12-16'] as $f) $d->execute([$s1['id'], $f, 1.0, 'completo']);
    $d->execute([$s2['id'], '2026-12-18', 0.5, 'am']);
    $antesS = $db->query('SELECT * FROM solicitudes ORDER BY id')->fetchAll();
    $antesD = $db->query('SELECT solicitud_id, fecha, consumo, fraccion FROM solicitud_dias ORDER BY id')->fetchAll();
    chequear(db_version($db) === 3 && count($antesD) === 4, 'parte en versión 3 con 2 solicitudes y 4 días');

    db_migrar($db, $p);
    $despuesS = $db->query('SELECT * FROM solicitudes ORDER BY id')->fetchAll();
    $despuesD = $db->query('SELECT solicitud_id, fecha, consumo, fraccion FROM solicitud_dias ORDER BY id')->fetchAll();
    $comunes = fn(array $filas) => array_map(fn($f) => array_intersect_key($f, $antesS[0]), $filas);
    chequear($comunes($despuesS) == $antesS, 'las solicitudes quedan idénticas (mismos ids y datos)');
    chequear($despuesD == $antesD, 'los días quedan idénticos (no se borraron en cascada)');
    chequear((int)$db->query('PRAGMA foreign_key_check')->fetchColumn() === 0, 'sin referencias rotas');
    $db->exec("INSERT INTO tipos_ausentismo (sigla,nombre,etiqueta_publica) VALUES ('CAP','Capacitación','Capacitación')");
    $s3 = reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'CAP', 'fecha_inicio' => '2026-12-21', 'fecha_fin' => '2026-12-21'], ['cfg' => cfg_defaults()]);
    chequear($s3['id'] > $s2['id'], 'acepta un tipo nuevo y el id sigue creciendo');
    $db = null;
});

prueba('Tipo nuevo creado por coordinación: se comporta según su configuración', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7'); $b = mk($db, 'Beto Dos', 'diurno', 'H7');
    h_coord_tipo_guardar($db, [], ['sigla' => 'cap', 'nombre' => 'Capacitación', 'etiqueta_publica' => 'Capacitación',
        'consume_cupo' => true, 'regla_cupo' => 'normal', 'saldo' => null, 'medio_dia' => false, 'lo_pide' => 'funcionario',
        'exige_reemplazo' => true, 'rango' => 'rango']);
    h_coord_tipo_guardar($db, [], ['sigla' => 'REU', 'nombre' => 'Reunión gremial', 'etiqueta_publica' => 'Reunión',
        'consume_cupo' => false, 'saldo' => null, 'medio_dia' => true, 'lo_pide' => 'funcionario', 'exige_reemplazo' => false, 'rango' => 'un_dia']);
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'CAP', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-15']);
    chequear($r['habiles'] === 2 && ocup($db, 'H7', '2026-12-14') === 1.0, 'CAP: rango, consume 1 por día (sigla guardada en mayúsculas)');
    crear($db, ['funcionario_id' => $b, 'tipo' => 'REU', 'fecha_inicio' => '2026-12-14', 'fraccion' => 'am']);
    chequear(ocup($db, 'H7', '2026-12-14') === 1.0, 'REU: no consume cupo');
    lanza(fn() => crear($db, ['funcionario_id' => $b, 'tipo' => 'REU', 'fecha_inicio' => '2026-12-16', 'fecha_fin' => '2026-12-17']), null);
    chequear(count($db->query("SELECT * FROM solicitudes WHERE tipo='REU'")->fetchAll()) === 1, 'REU es de un solo día: el rango no crea otra');
    h_coord_tipo_guardar($db, [(string)0, (int)tipo($db, 'CAP')['id']], ['nombre' => 'Capacitación', 'etiqueta_publica' => 'Capacitación', 'activo' => false] + tipo($db, 'CAP'));
    lanza(fn() => crear($db, ['funcionario_id' => $b, 'tipo' => 'CAP', 'fecha_inicio' => '2026-12-21', 'fecha_fin' => '2026-12-21']), 422, 'tipo');
});

prueba('Tipos: sigla única e inmutable; los precargados solo cambian nombre, etiqueta y activo; nada se borra', function () {
    $db = nuevaDb();
    lanza(fn() => h_coord_tipo_guardar($db, [], ['sigla' => 'FL', 'nombre' => 'Otro FL', 'etiqueta_publica' => 'x']), 409);
    lanza(fn() => h_coord_tipo_guardar($db, [], ['sigla' => 'A B', 'nombre' => 'Prueba', 'etiqueta_publica' => 'x']), 422, 'sigla');
    $fl = tipo($db, 'FL');
    h_coord_tipo_guardar($db, ['', (int)$fl['id']], ['sigla' => 'FERIADO', 'nombre' => 'Feriado legal (vacaciones)', 'etiqueta_publica' => 'Vacaciones',
        'consume_cupo' => false, 'saldo' => null, 'activo' => true]);
    $fl2 = tipo($db, 'FL');
    chequear($fl2 && $fl2['nombre'] === 'Feriado legal (vacaciones)' && $fl2['etiqueta_publica'] === 'Vacaciones', 'cambia nombre y etiqueta');
    chequear((int)$fl2['consume_cupo'] === 1 && $fl2['saldo'] === 'FL', 'no cambia sus reglas ni su sigla');
});

prueba('Quién pide: el funcionario no puede pedir tipos que registra coordinación (licencia)', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    lanza(fn() => reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18'],
                            ['cfg' => cfg_defaults(), 'creada_por' => $a]), 403, 'coordinación');
});

prueba('Config y calendario informan los tipos activos y la etiqueta pública', function () {
    $db = nuevaDb();
    $c = h_config($db, [], []);
    chequear(in_array('LIC', array_column($c['tipos'], 'sigla'), true) && isset($c['tipos'][0]['medio_dia']), 'config trae los tipos con su configuración');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-15']);
    $cal = cal_datos($db, '2026-12', false);
    chequear(($cal['ausencias'][0]['etiqueta'] ?? '') === 'FL', 'cada ausencia trae su etiqueta pública');
});
