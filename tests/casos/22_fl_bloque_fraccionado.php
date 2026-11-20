<?php
declare(strict_types=1);

// FL: bloque de vacaciones y días fraccionados (pedido del 20-11-2026, reemplaza SPEC-v2 §12.1).
// - Un FL con 10 o más días hábiles en su rango es un BLOQUE: descuenta los días hábiles (lunes a viernes sin festivos),
//   también para turnantes, aunque sus turnos caigan sábado o domingo.
// - Un FL más corto es FRACCIONADO: el diurno descuenta sus días hábiles; el turnante, 1 por cada turno que pide
//   (aunque sea sábado o domingo).
// - Los fraccionados son como máximo 5 días por año (pendientes + autorizados). Vale para diurnos y turnantes.
// Hoy simulado: martes 2026-12-01. Feriado de prueba: martes 2026-12-08.

function frac(PDO $db, int $fid, int $anio = 2026): float { return fl_fraccionado_usado($db, $fid, $anio); }

prueba('FL fraccionado de turnante: un turno de sábado descuenta 1 (antes descontaba 0)', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tito Turno', 'turnante', null); $p = mk($db, 'Pía Reemplazo', 'turnante', null);
    $s = turno($db, '2026-12-12', 'largo', 'H7', $t);
    $r = crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-12', 'reemplazos' => [$s => $p]]);
    chequear(disp($db, $t, 'FL') === 14.0, 'sábado con turno: descuenta 1, quedan ' . disp($db, $t, 'FL'));
    chequear($db->query('SELECT fl_modalidad FROM solicitudes WHERE id=' . $r['id'])->fetchColumn() === 'fraccionado', 'queda como fraccionado');
    chequear(frac($db, $t) === 1.0, 'usa 1 de los 5 fraccionados');
});

prueba('FL fraccionado de turnante: descuenta 1 por turno del rango, no los días hábiles', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tito Turno', 'turnante', null); $p = mk($db, 'Pía Reemplazo', 'turnante', null);
    // sábado 12 (largo) y domingo 13 (noche): 2 turnos; el rango sáb 12 a mié 16 tiene 3 días hábiles
    $a = turno($db, '2026-12-12', 'largo', 'H7', $t); $b = turno($db, '2026-12-13', 'noche', 'H7', $t);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-16', 'reemplazos' => [$a => $p, $b => $p]]);
    chequear(disp($db, $t, 'FL') === 13.0, '2 turnos → 2 días, quedan ' . disp($db, $t, 'FL'));
});

prueba('FL en bloque de turnante: 10+ días hábiles descuentan solo de lunes a viernes sin festivos', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tito Turno', 'turnante', null); $p = mk($db, 'Pía Reemplazo', 'turnante', null);
    // lun 7 a lun 21 de diciembre: 11 días de lunes a viernes, menos el feriado del 8 = 10 hábiles
    $ids = [];
    foreach (['2026-12-07', '2026-12-12', '2026-12-13', '2026-12-17', '2026-12-19', '2026-12-20'] as $f) $ids[] = turno($db, $f, 'largo', 'H7', $t);
    $r = crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-07', 'fecha_fin' => '2026-12-21',
                     'reemplazos' => array_fill_keys($ids, $p)]);
    chequear(disp($db, $t, 'FL') === 5.0, 'bloque: descuenta 10 (no 6 turnos), quedan ' . disp($db, $t, 'FL'));
    chequear($db->query('SELECT fl_modalidad FROM solicitudes WHERE id=' . $r['id'])->fetchColumn() === 'bloque', 'queda como bloque');
    chequear(frac($db, $t) === 0.0, 'no gasta fraccionados');
});

prueba('FL de diurno: hasta 5 días fraccionados al año; el 6.º se bloquea y el bloque de 10 sigue permitido', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    // 6 días hábiles (lun 14 a lun 21): fraccionado de 6 → no
    $e = lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-21']), 422);
    chequear(($e->extra['codigo'] ?? '') === 'fl_fraccionado', 'código fl_fraccionado: ' . ($e ? $e->getMessage() : ''));
    chequear($e !== null && str_contains($e->getMessage(), '10 días hábiles'), 'el mensaje explica el bloque de 10');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-16']);   // 3
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-03', 'fecha_fin' => '2026-12-04']);   // 2 → 5
    chequear(frac($db, $a) === 5.0, 'lleva 5 fraccionados (cuentan las pendientes)');
    $e = lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-17', 'fecha_fin' => '2026-12-17']), 422);
    chequear(($e->extra['codigo'] ?? '') === 'fl_fraccionado', 'un 6.º día suelto se bloquea');
    // bloque de 10 hábiles en enero 2027 (lun 4 a vie 15)
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2027-01-04', 'fecha_fin' => '2027-01-15']);
    chequear(disp($db, $a, 'FL', 2027) === 5.0, 'bloque 2027: quedan 5');
    chequear(frac($db, $a, 2027) === 0.0, 'el bloque no gasta fraccionados de 2027');
});

prueba('Fraccionados: anular o rechazar los libera; revertir a autorizada vuelve a revisar el límite', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r1 = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);   // 5
    reg_resolver($db, $r1['id'], 'rechazar', 'No', 'JT');
    chequear(frac($db, $a) === 0.0, 'rechazada: libera los 5');
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-03', 'fecha_fin' => '2026-12-03']);   // 1
    $e = lanza(fn() => reg_revertir($db, $r1['id'], '', 'JT'), 422);
    chequear(($e->extra['codigo'] ?? '') === 'fl_fraccionado', 'no se puede volver a autorizar: 1 + 5 > 5');
    reg_anular($db, $r2['id'], $a, false);
    reg_revertir($db, $r1['id'], '', 'JT');
    chequear(frac($db, $a) === 5.0, 'tras anular la otra, sí se puede: 5');
});

prueba('FL que cruza de año: los fraccionados se cuentan en el año de cada día', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-30', 'fecha_fin' => '2027-01-05']);   // 30, 31 dic + 1, 4, 5 ene = 5 hábiles
    chequear(frac($db, $a, 2026) === 2.0 && frac($db, $a, 2027) === 3.0, '2 en 2026 y 3 en 2027');
});

prueba('Simular: informa si es bloque o fraccionado y cuántos fraccionados quedan', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-03', 'fecha_fin' => '2026-12-04']);   // 2
    $s = reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-15'], ['cfg' => cfg_defaults()], true);
    chequear(($s['fl']['modalidad'] ?? '') === 'fraccionado', 'fraccionado');
    chequear(($s['fl']['fraccionado'][0]['usado'] ?? null) === 2.0 && ($s['fl']['fraccionado'][0]['usa'] ?? null) === 2.0
             && ($s['fl']['fraccionado'][0]['maximo'] ?? null) === 5.0, 'lleva 2, usa 2, máximo 5: ' . json_encode($s['fl'] ?? null));
    $s = reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2027-01-04', 'fecha_fin' => '2027-01-15'], ['cfg' => cfg_defaults()], true);
    chequear(($s['fl']['modalidad'] ?? '') === 'bloque', 'bloque');
    $s = reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-14', 'fraccion' => 'completo'], ['cfg' => cfg_defaults()], true);
    chequear(!isset($s['fl']), 'ADM no trae información de FL');
});

prueba('Migración 9: marca las FL que ya existían como bloque o fraccionado, sin recalcular su saldo', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r1 = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-16']);
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2027-01-04', 'fecha_fin' => '2027-01-15']);
    $db->exec('UPDATE solicitudes SET fl_modalidad = NULL');
    mig_009_fl_modalidad_datos($db);
    $m = $db->query('SELECT id, fl_modalidad FROM solicitudes')->fetchAll(PDO::FETCH_KEY_PAIR);
    chequear($m[$r1['id']] === 'fraccionado' && $m[$r2['id']] === 'bloque', 'fraccionado y bloque: ' . json_encode($m));
    chequear(disp($db, $a, 'FL') === 12.0, 'el saldo no cambia');
});
