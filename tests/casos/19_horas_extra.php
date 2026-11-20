<?php
declare(strict_types=1);

// Horas extra (SPEC-v2 §4.7, portado de utils/extraHours.ts de la app anterior; plan 4.6).
// Festivo = sábado, domingo o feriado de la tabla. Feriado de prueba: martes 08-12-2026.

function hx(string $f, string $tipo, bool $diurno = false, ?float $ov = null, ?string $mitad = null): array
{
    $r = horas_turno($f, $tipo, $diurno, $ov, $mitad, ['2026-12-08' => true]);
    return [$r['diurnas'], $r['festivas']];
}

prueba('Horas por turno: cada fila de la tabla de SPEC-v2 §4.7', function () {
    chequear(hx('2026-12-09', 'largo') === [12.0, 0.0], 'largo en día hábil (miércoles): 12 diurnas');
    chequear(hx('2026-12-12', 'largo') === [0.0, 11.0], 'largo en sábado: 11 festivas');
    chequear(hx('2026-12-08', 'largo') === [0.0, 11.0], 'largo en feriado: 11 festivas');
    chequear(hx('2026-12-09', 'largo', true) === [3.0, 0.0], 'diurno cubre largo de lunes a jueves: 3');
    chequear(hx('2026-12-11', 'largo', true) === [4.0, 0.0], 'diurno cubre largo el viernes: 4');
    chequear(hx('2026-12-09', 'largo', true, null, 'am') === [0.0, 0.0], 'diurno cubre ½ AM: 0');
    chequear(hx('2026-12-09', 'largo', true, null, 'pm') === [3.0, 0.0], 'diurno cubre ½ PM: 3');
    chequear(hx('2026-12-09', 'noche') === [2.0, 10.0], 'noche ordinaria (miércoles a jueves): 2 + 10 = 12');
    chequear(hx('2026-12-11', 'noche') === [1.0, 12.0], 'noche hacia festivo (viernes a sábado): 1 + 12 = 13');
    chequear(hx('2026-12-07', 'noche') === [1.0, 12.0], 'noche hacia feriado (lunes a martes feriado): 13');
    chequear(hx('2026-12-13', 'noche') === [1.0, 11.0], 'noche desde festivo a hábil (domingo a lunes): 1 + 11 = 12');
    chequear(hx('2026-12-12', 'noche') === [0.0, 13.0], 'noche entre festivos (sábado a domingo): 0 + 13');
    chequear(hx('2026-12-09', 'largo', false, null, 'am') === [6.0, 0.0], 'medio turno largo hábil: la mitad (6)');
    chequear(hx('2026-12-12', 'largo', false, null, 'pm') === [0.0, 5.5], 'medio turno largo festivo: 5,5');
    chequear(hx('2026-12-11', 'noche', false, null, 'am') === [0.5, 6.0], 'media noche hacia festivo: la mitad de 1 + 12');
});

prueba('Horas por turno: corrección manual repartida con las mismas reglas', function () {
    chequear(hx('2026-12-09', 'largo', false, 8.0) === [8.0, 0.0], 'largo hábil con 8 h manuales: diurnas');
    chequear(hx('2026-12-12', 'largo', false, 8.0) === [0.0, 8.0], 'largo festivo con 8 h: festivas');
    chequear(hx('2026-12-09', 'noche', false, 10.0) === [2.0, 8.0], 'noche ordinaria con 10 h: hasta 2 diurnas, resto festivas');
    chequear(hx('2026-12-11', 'noche', false, 10.0) === [1.0, 9.0], 'noche hacia festivo con 10 h: 1 diurna');
    chequear(hx('2026-12-12', 'noche', false, 10.0) === [0.0, 10.0], 'noche entre festivos con 10 h: todas festivas');
});

prueba('Resumen del mes: cuenta los turnos que se cubren como extra y los cargados como extra', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null); $d = mk($db, 'Dani Diurno', 'diurno', 'H7');
    $a = turno($db, '2026-12-09', 'largo', 'H7', $t); $b = turno($db, '2026-12-11', 'noche', 'H7', $t); $c = turno($db, '2026-12-14', 'largo', 'H7', $t);
    $s1 = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $a, 'reemplazo_id' => $d]);       // diurno cubre largo hábil: 3
    $s2 = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $b, 'reemplazo_id' => $r]);       // turnante cubre noche hacia festivo: 13
    $s3 = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $c, 'reemplazo_id' => $r]);       // autorizado NO extra
    reg_resolver($db, $s1['id'], 'autorizar', '', 'JT'); reg_resolver($db, $s2['id'], 'autorizar', '', 'JT');
    reg_resolver($db, $s3['id'], 'autorizar', '', 'JT', '', null, false);
    $x = turno($db, '2026-12-19', 'largo', 'H7', $r);
    $db->exec("UPDATE turnos SET es_extra=1 WHERE id=$x");                                                      // turno cargado como extra: 11 festivas
    $res = horas_extra_mes($db, '2026-12');
    $por = array_column($res['personas'], null, 'nombre');
    chequear(($por['Dani Diurno']['diurnas'] ?? null) === 3.0 && ($por['Dani Diurno']['festivas'] ?? null) === 0.0, 'Dani: 3 diurnas');
    chequear(($por['Rita Reemplazo']['diurnas'] ?? null) === 1.0 && ($por['Rita Reemplazo']['festivas'] ?? null) === 23.0 && count($por['Rita Reemplazo']['turnos']) === 2,
             'Rita: noche (1 + 12) + sábado extra (11) = 1 diurna y 23 festivas; el turno no extra no cuenta: ' . json_encode($por['Rita Reemplazo'] ?? null));
    chequear(!isset($por['Tati Turno']), 'la titular ausente no suma horas extra');
});

prueba('HTTP: coordinación ve el resumen y exporta CSV; un funcionario no', function () {
    [$app, $rc, $cc, $rf, $cf] = appConPersonas();
    $k = entrar($app, $rc, $cc, '739104');
    $r = $k->pedir('GET', 'coord/horas-extra', null, ['mes' => '2026-12']);
    chequear(isset($r['json']['personas']) && ($r['json']['mes'] ?? '') === '2026-12', 'resumen: ' . substr($r['raw'], 0, 80));
    $csv = $k->pedir('GET', 'coord/horas-extra/exportar', null, ['mes' => '2026-12'])['raw'];
    chequear(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, ';"Horas diurnas";"Horas festivas";Total'), 'CSV con BOM y columnas para Excel: ' . substr($csv, 0, 160));
    $f = entrar($app, $rf, $cf, '482915');
    chequear(($f->pedir('GET', 'coord/horas-extra')['json']['codigo'] ?? '') === 'sin_permiso', 'un funcionario no accede');
});
