<?php
declare(strict_types=1);

// Turnantes piden sobre sus turnos cargados, con reemplazo de la nómina (SPEC-v2 §4.3, §12.1 y §12.2; plan 4.2).

prueba('ADM de turnante: sobre un turno propio, con reemplazo elegido de la nómina', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null);
    $tid = turno($db, '2026-12-10', 'noche', 'H7', $t);
    $s = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'primera_mitad', 'turno_id' => $tid, 'reemplazo_id' => $r]);
    $d = $db->query('SELECT fecha, turno, unidad_turno_id, turno_id, reemplazo_id, reemplazo_nombre FROM solicitud_dias WHERE solicitud_id=' . $s['id'])->fetch();
    chequear($d['fecha'] === '2026-12-10' && $d['turno'] === 'noche' && (int)$d['turno_id'] === $tid && (int)$d['reemplazo_id'] === $r && $d['reemplazo_nombre'] === 'Rita Reemplazo',
             'el día queda ligado al turno y al reemplazo (con su nombre): ' . json_encode($d));
    chequear(saldo_estado($db, $t, 2026)['ADM']['disponible'] === 5.5, 'media jornada descuenta 0,5');
});

prueba('Se exige turno cargado: sin turno, con turno ajeno o sin reemplazo, se rechaza', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $o = mk($db, 'Otro Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null);
    $ajeno = turno($db, '2026-12-10', 'largo', 'H7', $o);
    $mio = turno($db, '2026-12-11', 'largo', 'H7', $t);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'reemplazo_id' => $r]), 422, 'turno');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $ajeno, 'reemplazo_id' => $r]), 422, 'tuyo');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $mio]), 422, 'cubre');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $mio, 'reemplazo_id' => $t]), 422, 'otra persona');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'dias' => [['fecha' => '2026-12-11', 'turno' => 'largo', 'unidad_id' => eid($db, 'H7'), 'reemplazo' => 'X']]]), 422, 'turnos cargados');
});

prueba('FL de turnante: rango; se cubren todos sus turnos del rango; saldo = días hábiles del rango', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r1 = mk($db, 'Rita Uno', 'turnante', null); $r2 = mk($db, 'Dani Diurno', 'diurno', 'H7');
    $a = turno($db, '2026-12-12', 'largo', 'H7', $t); $b = turno($db, '2026-12-15', 'noche', 'H7', $t);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-16', 'reemplazos' => [$a => $r1]]), 422, 'cubre');
    $s = crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-16', 'reemplazos' => [$a => $r1, $b => $r2]]);
    chequear($s['habiles'] === 2, 'dos turnos en la solicitud');
    chequear(saldo_estado($db, $t, 2026)['FL']['disponible'] === 12.0, 'saldo: hábiles del 12 al 16 = 14, 15, 16 → 3 días');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-20', 'fecha_fin' => '2026-12-22']), 422, 'No tienes turnos');
});

prueba('Cambio de turno: cede un turno propio a un compañero; la devolución es un turno del compañero', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $c = mk($db, 'Coni Compa', 'turnante', null); $o = mk($db, 'Otro Turno', 'turnante', null);
    $mio = turno($db, '2026-12-10', 'largo', 'H7', $t);
    $suyo = turno($db, '2026-12-17', 'noche', 'H7', $c);
    $deOtro = turno($db, '2026-12-18', 'noche', 'H7', $o);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $mio]), 422, 'con quién');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $mio, 'cambio_con_id' => $c, 'devolucion_turno_id' => $deOtro]), 422, 'devolución');
    $s = crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $mio, 'cambio_con_id' => $c, 'devolucion_turno_id' => $suyo]);
    $f = $db->query('SELECT cambio_con, cambio_con_id, devolucion_turno_id, devolucion_fecha, devolucion_turno FROM solicitudes WHERE id=' . $s['id'])->fetch();
    chequear($f['cambio_con'] === 'Coni Compa' && (int)$f['cambio_con_id'] === $c && (int)$f['devolucion_turno_id'] === $suyo
             && $f['devolucion_fecha'] === '2026-12-17' && $f['devolucion_turno'] === 'noche', 'guarda con quién y la devolución: ' . json_encode($f));
});

prueba('Superposición: el mismo turno no puede estar en dos solicitudes activas', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null);
    $tid = turno($db, '2026-12-10', 'largo', 'H7', $t);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $tid, 'reemplazo_id' => $r]);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-10', 'fecha_fin' => '2026-12-10', 'reemplazos' => [$tid => $r]]), 409);
});
