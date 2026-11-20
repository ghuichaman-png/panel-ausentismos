<?php
declare(strict_types=1);

// Licencias (SPEC-v2 §4.6 y §12.5; plan 3.4): las registra coordinación, nacen autorizadas, no usan cupo ni saldo
// y en el calendario se ven como «Licencia».

function enNombreDe(PDO $db, int $coord, array $in): array
{
    return reg_crear($db, $in, ['ip' => '1.2.3.4', 'ua' => 'test', 'cfg' => cfg_defaults(), 'creada_por' => $coord, 'por_coordinacion' => true]);
}

prueba('Licencia de un diurno: nace autorizada, firmada por coordinación, sin cupo ni saldo', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = enNombreDe($db, $k, ['funcionario_id' => $a, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-20']);
    $s = $db->query('SELECT estado, resuelta_por_id, creada_por, resuelto_por FROM solicitudes WHERE id=' . $r['id'])->fetch();
    chequear($s['estado'] === 'autorizada' && (int)$s['resuelta_por_id'] === $k && (int)$s['creada_por'] === $k, 'autorizada y firmada por quien la registró');
    chequear($r['habiles'] === 5 && ocup($db, 'H7', '2026-12-16') === 0.0, '5 días hábiles, no ocupa cupo');
    chequear(saldo_estado($db, $a, 2026)['FL']['disponible'] === 15.0 && saldo_estado($db, $a, 2026)['ADM']['disponible'] === 6.0, 'no toca los saldos');
    $cal = cal_datos($db, '2026-12', false);
    $et = array_unique(array_column(array_filter($cal['ausencias'], fn($x) => $x['nombre'] === 'Ana Uno'), 'etiqueta'));
    chequear($et === ['Licencia'], 'en el calendario se ve «Licencia»');
});

prueba('Licencia de un turnante: rango de fechas sin turnos ni reemplazo', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $t = mk($db, 'Tati Turno', 'turnante', null);
    $r = enNombreDe($db, $k, ['funcionario_id' => $t, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-12', 'fecha_fin' => '2026-12-14']);
    $n = $db->query('SELECT fecha, turno FROM solicitud_dias WHERE solicitud_id=' . $r['id'] . ' ORDER BY fecha')->fetchAll(PDO::FETCH_NUM);
    chequear($n === [['2026-12-12', null], ['2026-12-13', null], ['2026-12-14', null]], 'un día por fecha (incluye fin de semana: el turnante puede tener turno), sin turno');
});

prueba('Coordinación puede registrar licencias con fechas pasadas; un funcionario no puede pedir fechas pasadas', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = enNombreDe($db, $k, ['funcionario_id' => $a, 'tipo' => 'LIC', 'fecha_inicio' => '2026-11-25', 'fecha_fin' => '2026-11-27']);
    chequear($r['habiles'] === 3, 'licencia pasada registrada');
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-11-30', 'fraccion' => 'completo']), 422, 'pasadas');
});

prueba('Licencia: coordinación puede anularla; si choca con otra solicitud lo dice con su código', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $fl = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-15']);
    $e = lanza(fn() => enNombreDe($db, $k, ['funcionario_id' => $a, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-15', 'fecha_fin' => '2026-12-18']), 409);
    chequear($e !== null && str_contains($e->getMessage(), $fl['codigo']), 'indica la solicitud con la que choca (para anularla primero)');
    $r = enNombreDe($db, $k, ['funcionario_id' => $a, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-16', 'fecha_fin' => '2026-12-18']);
    reg_anular($db, $r['id'], $k, true, 'Coordinadora');
    chequear($db->query('SELECT estado FROM solicitudes WHERE id=' . $r['id'])->fetchColumn() === 'anulada', 'anulada');
});
