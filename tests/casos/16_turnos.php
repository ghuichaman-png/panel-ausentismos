<?php
declare(strict_types=1);

// Calendario de turnos (SPEC-v2 §3.7; plan 4.1).

function turno(PDO $db, string $fecha, string $tipo, string $unidad, int $titular): int
{
    return h_coord_turno_guardar($db, [], ['fecha' => $fecha, 'tipo' => $tipo, 'unidad_id' => eid($db, $unidad), 'titular_id' => $titular])['id'];
}

prueba('Turnos: se crean por unidad, fecha y tipo; no se duplican; el titular es turnante', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null);
    $d = mk($db, 'Dani Diurno', 'diurno', 'H7');
    $id = turno($db, '2026-12-10', 'largo', 'H7', $t);
    chequear($id > 0, 'creado');
    lanza(fn() => turno($db, '2026-12-10', 'largo', 'H7', $t), 409, 'ya tiene');
    lanza(fn() => turno($db, '2026-12-11', 'largo', 'H7', $d), 422, 'turnante');
    lanza(fn() => turno($db, '2026-12-11', 'largo', 'Traumatología', $t), 422, 'unidad');
    lanza(fn() => turno($db, '2026-12-11', 'tarde', 'H7', $t), 422, 'largo o noche');
    chequear(turno($db, '2026-12-10', 'noche', 'H7', $t) > 0, 'el mismo día puede tener turno largo y de noche');
});

prueba('Carga en lote con patrón (largo, noche, libre, libre): repetible sin duplicar', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null);
    $in = ['unidad_id' => eid($db, 'UTI H5'), 'titular_id' => $t, 'desde' => '2026-12-01', 'hasta' => '2026-12-08', 'patron' => ['largo', 'noche', '', '']];
    $r = h_coord_turnos_lote($db, [], $in);
    chequear($r['creados'] === 4 && $r['omitidos'] === 0, '4 turnos: 1 L, 2 N, 5 L, 6 N: ' . json_encode($r));
    $f = $db->query('SELECT fecha || tipo FROM turnos ORDER BY fecha, tipo')->fetchAll(PDO::FETCH_COLUMN);
    chequear($f === ['2026-12-01largo', '2026-12-02noche', '2026-12-05largo', '2026-12-06noche'], 'fechas correctas: ' . implode(',', $f));
    $r = h_coord_turnos_lote($db, [], $in);
    chequear($r['creados'] === 0 && $r['omitidos'] === 4, 'la segunda vez no duplica');
    lanza(fn() => h_coord_turnos_lote($db, [], ['patron' => ['x']] + $in), 422);
});

prueba('Turnos: listar por mes y unidad; editar; eliminar un turno sin solicitudes', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $t2 = mk($db, 'Toño Turno', 'turnante', null);
    $id = turno($db, '2026-12-10', 'largo', 'H7', $t);
    turno($db, '2026-12-11', 'noche', 'H6', $t);
    $_GET = ['mes' => '2026-12', 'unidad_id' => (string)eid($db, 'H7')];
    $l = h_coord_turnos($db, [], []);
    $_GET = [];
    chequear(count($l['turnos']) === 1 && $l['turnos'][0]['titular'] === 'Tati Turno', 'filtra por unidad y trae el nombre del titular');
    h_coord_turno_guardar($db, ['', $id], ['fecha' => '2026-12-10', 'tipo' => 'largo', 'unidad_id' => eid($db, 'H7'), 'titular_id' => $t2]);
    chequear((int)$db->query("SELECT titular_id FROM turnos WHERE id=$id")->fetchColumn() === $t2, 'cambia el titular');
    h_coord_turno_eliminar($db, ['', $id], []);
    chequear((int)$db->query("SELECT COUNT(*) FROM turnos WHERE id=$id")->fetchColumn() === 0, 'eliminado');
});

prueba('Mis turnos: cada turnante ve los suyos desde hoy', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $t2 = mk($db, 'Toño Turno', 'turnante', null);
    turno($db, '2026-11-20', 'largo', 'H7', $t);   // pasado (hoy simulado = 01-12)
    turno($db, '2026-12-10', 'largo', 'H7', $t);
    turno($db, '2026-12-10', 'largo', 'H7', $t2);
    $m = turnos_de($db, $t, '2026-12-01', '2027-01-31');
    chequear(count($m) === 1 && $m[0]['fecha'] === '2026-12-10' && $m[0]['unidad'] === 'H7', 'solo sus turnos futuros, con la unidad');
});
