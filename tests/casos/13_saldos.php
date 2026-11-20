<?php
declare(strict_types=1);

// Saldos FL y ADM como libro de movimientos (SPEC-v2 §3.8 y §4.5; plan 3.3). Regla fija: FL 15 y ADM 6 por año,
// sin arrastre; se bloquea si no alcanza; las pendientes reservan; ajustes con motivo.

function disp(PDO $db, int $fid, string $saldo, int $anio = 2026): float { return saldo_estado($db, $fid, $anio)[$saldo]['disponible']; }

prueba('Asignación anual FL 15 y ADM 6: automática y sin duplicarse', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    saldos_asegurar_asignacion($db, 2026); saldos_asegurar_asignacion($db, 2026);
    chequear((int)$db->query("SELECT COUNT(*) FROM saldo_movimientos WHERE funcionario_id=$a AND anio=2026")->fetchColumn() === 2, 'dos asignaciones (FL y ADM), no cuatro');
    $e = saldo_estado($db, $a, 2026);
    chequear($e['FL']['asignado'] === 15.0 && $e['ADM']['asignado'] === 6.0 && $e['FL']['disponible'] === 15.0, 'FL 15 y ADM 6 disponibles');
});

prueba('Bug B7 (app anterior): un FL de lunes a domingo descuenta 5 días hábiles, no 7', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-20']);
    chequear(disp($db, $a, 'FL') === 10.0, 'quedan 10: ' . disp($db, $a, 'FL'));
});

prueba('ADM medio día descuenta 0,5; las pendientes reservan saldo', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-11', 'fraccion' => 'completo']);
    $e = saldo_estado($db, $a, 2026)['ADM'];
    chequear($e['pendiente'] === 1.5 && $e['disponible'] === 4.5, 'ADM: 1,5 pendiente y 4,5 disponibles');
});

prueba('Bloqueo: si no alcanza el saldo, no se puede enviar; la simulación lo avisa', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    saldo_ajustar($db, $a, 2026, 'ADM', -5.5, 'Usados en el sistema de RR.HH. antes de esta app', null);
    $sim = reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo'], ['cfg' => cfg_defaults()], true);
    chequear(($sim['saldo'][0]['insuficiente'] ?? false) === true && ($sim['saldo'][0]['disponible'] ?? -1) === 0.5, 'la simulación avisa: ' . json_encode($sim['saldo'] ?? null));
    $e = lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']), 409, 'saldo');
    chequear(($e->extra['codigo'] ?? '') === 'sin_saldo', 'código sin_saldo');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    chequear(disp($db, $a, 'ADM') === 0.0, 'medio día sí alcanza y deja 0');
});

prueba('Bug B5 (app anterior): autorizar → rechazar → autorizar no descuenta dos veces; anular devuelve', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);
    reg_resolver($db, $r['id'], 'autorizar', '', 'JT');
    chequear(disp($db, $a, 'FL') === 10.0, 'autorizada: 10');
    reg_revertir($db, $r['id'], 'Falta cobertura', 'JT');
    chequear(disp($db, $a, 'FL') === 15.0, 'rechazada: vuelve a 15');
    reg_revertir($db, $r['id'], '', 'JT');
    chequear(disp($db, $a, 'FL') === 10.0, 'otra vez autorizada: 10 (no 5)');
    reg_anular($db, $r['id'], $a, true, 'JT');
    chequear(disp($db, $a, 'FL') === 15.0, 'anulada: 15');
});

prueba('Revertir a autorizada vuelve a verificar el saldo', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    reg_resolver($db, $r['id'], 'rechazar', 'No', 'JT');
    saldo_ajustar($db, $a, 2026, 'ADM', -5.5, 'Prueba', null);
    $e = lanza(fn() => reg_revertir($db, $r['id'], '', 'JT'), 409, 'saldo');
    chequear(($e->extra['codigo'] ?? '') === 'sin_saldo', 'sin saldo no se puede volver a autorizar');
});

prueba('Ajustes: suman o restan con motivo obligatorio (p. ej. +5 FL por antigüedad)', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    saldo_ajustar($db, $a, 2026, 'FL', 5, 'Antigüedad: 20 días de FL', null);
    chequear(disp($db, $a, 'FL') === 20.0, 'FL 20');
    lanza(fn() => saldo_ajustar($db, $a, 2026, 'FL', 1, '', null), 422, 'motivo');
    lanza(fn() => saldo_ajustar($db, $a, 2026, 'FL', 0, 'x', null), 422);
    lanza(fn() => saldo_ajustar($db, $a, 2026, 'OTRO', 1, 'x', null), 422);
});

prueba('FL que cruza de año: cada año descuenta sus propios días hábiles; sin arrastre', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-28', 'fecha_fin' => '2027-01-05']);
    chequear(disp($db, $a, 'FL', 2026) === 11.0, '2026: 28-31 dic = 4 días → quedan 11');
    chequear(disp($db, $a, 'FL', 2027) === 12.0, '2027: 1, 4 y 5 ene = 3 días → quedan 12 (el 2027 parte de 15, sin arrastre)');
});

prueba('Turnantes: FL fraccionado descuenta 1 por turno; ADM media jornada 0,5 (SPEC-v2 §12.2; FL según la regla del 20-11-2026)', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $p = mk($db, 'Pedro Reemplazo', 'turnante', null);
    $a = turno($db, '2026-12-12', 'largo', 'H7', $t); $b = turno($db, '2026-12-15', 'largo', 'H7', $t); $c = turno($db, '2026-12-20', 'largo', 'H7', $t);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-15', 'reemplazos' => [$a => $p, $b => $p]]);
    chequear(disp($db, $t, 'FL') === 13.0, 'rango sáb 12 a mar 15 con 2 turnos → 2 días');
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'primera_mitad', 'turno_id' => $c, 'reemplazo_id' => $p]);
    chequear(disp($db, $t, 'ADM') === 5.5, 'ADM media jornada: 0,5');
});

prueba('Tipos sin saldo (CT, LIC, tipos nuevos sin saldo) no descuentan nada', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $l = mk($db, 'Luis Colega', 'turnante', null);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => turno($db, '2026-12-22', 'largo', 'H7', $t), 'cambio_con_id' => $l]);
    $e = saldo_estado($db, $t, 2026);
    chequear($e['FL']['disponible'] === 15.0 && $e['ADM']['disponible'] === 6.0, 'saldos intactos');
});

prueba('Concurrencia de saldo: dos procesos usan el último día de ADM a la vez → solo uno lo obtiene', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    saldo_ajustar($db, $a, 2026, 'ADM', -5, 'Prueba de concurrencia', null);
    $db = null;
    global $dbpath;
    $inicio = microtime(true) + 1.5;
    $procs = [];
    foreach (['2026-12-10', '2026-12-11'] as $f) {
        $procs[] = [proc_open([PHP_BINARY, TESTS_DIR . '/worker.php', $dbpath, (string)$a, $f, (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp), $pp];
    }
    $res = [];
    foreach ($procs as [$pr, $pp]) { $res[] = trim((string)stream_get_contents($pp[1])) . trim((string)stream_get_contents($pp[2])); proc_close($pr); }
    chequear(count(array_filter($res, fn($r) => str_starts_with($r, 'OK'))) === 1 && count(array_filter($res, fn($r) => $r === 'SIN_SALDO')) === 1, '1 OK y 1 SIN_SALDO: ' . implode(' | ', $res));
    $db = db_open($dbpath);
    chequear(disp($db, $a, 'ADM') === 0.0, 'el saldo queda en 0, nunca negativo');
    $db = null;
});

prueba('HTTP: «Mi saldo» para cada persona; coordinación ve y ajusta saldos', function () {
    [$app, $rc, $cc, $rf, $cf] = appConPersonas();
    $f = entrar($app, $rf, $cf, '482915');
    $anio = (int)date('Y');
    $r = $f->pedir('GET', 'saldos/mios');
    chequear(($r['json']['saldos']['FL']['disponible'] ?? null) == 15 && ($r['json']['anio'] ?? 0) === $anio, 'saldos/mios: FL 15 disponibles: ' . $r['raw']);
    chequear(($r['json']['fl_fraccionado']['usado'] ?? null) == 0 && ($r['json']['fl_fraccionado']['maximo'] ?? null) == 5, 'saldos/mios: fraccionados usados 0 de 5');
    $k = entrar($app, $rc, $cc, '739104');
    $lista = $k->pedir('GET', 'coord/saldos')['json']['personas'] ?? [];
    $fila = array_values(array_filter($lista, fn($x) => $x['nombre'] === 'Funcionaria Prueba'))[0] ?? null;
    chequear($fila !== null && $fila['FL']['disponible'] == 15, 'coordinación ve el saldo de cada persona');
    $r = $k->pedir('POST', 'coord/saldos/ajustar', ['funcionario_id' => $fila['funcionario_id'] ?? 0, 'anio' => $anio, 'saldo' => 'FL', 'dias' => 5, 'motivo' => 'Antigüedad']);
    chequear(($r['json']['ok'] ?? false) === true, 'ajusta: ' . $r['raw']);
    chequear(($f->pedir('GET', 'saldos/mios')['json']['saldos']['FL']['disponible'] ?? null) == 20, 'la persona ve 20');
    chequear(($f->pedir('POST', 'coord/saldos/ajustar', ['funcionario_id' => 1, 'anio' => $anio, 'saldo' => 'FL', 'dias' => 5, 'motivo' => 'x'])['json']['codigo'] ?? '') === 'sin_permiso', 'un funcionario no puede ajustar');
});
