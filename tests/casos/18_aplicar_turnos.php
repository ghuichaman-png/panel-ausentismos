<?php
declare(strict_types=1);

// Efecto en el calendario de turnos al autorizar, revertir y anular (SPEC-v2 §4.3, §4.4, §4.6 y §12.4; plan 4.3–4.5).

function tu(PDO $db, int $id): array { return $db->query("SELECT * FROM turnos WHERE id=$id")->fetch(); }

prueba('Autorizar una ausencia: el turno queda cubierto por el reemplazo, como extra por defecto (§12.4)', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null);
    $a = turno($db, '2026-12-10', 'largo', 'H7', $t); $b = turno($db, '2026-12-11', 'largo', 'H7', $t);
    $s1 = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $a, 'reemplazo_id' => $r]);
    chequear(tu($db, $a)['cubierto_por_id'] === null, 'mientras está pendiente el turno no cambia');
    reg_resolver($db, $s1['id'], 'autorizar', '', 'JT');
    $x = tu($db, $a);
    chequear((int)$x['cubierto_por_id'] === $r && (int)$x['cubierto_extra'] === 1 && $x['origen'] === 'ausencia' && (int)$x['solicitud_id'] === $s1['id'], 'cubierto por Rita, extra');
    $s2 = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $b, 'reemplazo_id' => $r]);
    reg_resolver($db, $s2['id'], 'autorizar', '', 'JT', '', null, false);
    chequear((int)tu($db, $b)['cubierto_extra'] === 0, 'coordinación puede marcarlo como no extra');
});

prueba('Bug B6 (app anterior): si algo falla al autorizar, no queda nada a medias', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null);
    $a = turno($db, '2026-12-14', 'largo', 'H7', $t); $b = turno($db, '2026-12-15', 'largo', 'H7', $t);
    $s = crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-15', 'reemplazos' => [$a => $r, $b => $r]]);
    $db->exec("UPDATE turnos SET titular_id=$r WHERE id=$b");   // alguien cambió el segundo turno por fuera
    lanza(fn() => reg_resolver($db, $s['id'], 'autorizar', '', 'JT'), 409, '15-12-2026');
    chequear($db->query('SELECT estado FROM solicitudes WHERE id=' . $s['id'])->fetchColumn() === 'pendiente', 'la solicitud sigue pendiente');
    chequear(tu($db, $a)['cubierto_por_id'] === null && tu($db, $a)['solicitud_id'] === null, 'el primer turno no quedó modificado');
});

prueba('Bug B15 (app anterior): anular o rechazar restaura solo los turnos de ESA solicitud', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Rita Reemplazo', 'turnante', null); $q = mk($db, 'Quique Otro', 'turnante', null);
    $a = turno($db, '2026-12-14', 'largo', 'H7', $t); $b = turno($db, '2026-12-16', 'largo', 'H7', $t);
    $fl = crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-14', 'reemplazos' => [$a => $r]]);
    $adm = crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $b, 'reemplazo_id' => $q]);
    reg_resolver($db, $fl['id'], 'autorizar', '', 'JT'); reg_resolver($db, $adm['id'], 'autorizar', '', 'JT');
    reg_anular($db, $fl['id'], $t, true, 'JT');
    chequear(tu($db, $a)['cubierto_por_id'] === null && tu($db, $a)['origen'] === 'carga', 'el turno del FL vuelve a su titular');
    chequear((int)tu($db, $b)['cubierto_por_id'] === $q, 'el turno del ADM sigue cubierto');
    reg_revertir($db, $adm['id'], 'Cambio de planes', 'JT');
    chequear(tu($db, $b)['cubierto_por_id'] === null, 'pasar a rechazada lo restaura');
    reg_revertir($db, $adm['id'], '', 'JT');
    chequear((int)tu($db, $b)['cubierto_por_id'] === $q, 'volver a autorizar lo cubre de nuevo');
});

prueba('Cambio de turno autorizado: intercambia titulares (con y sin devolución); anular los restaura', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null); $c = mk($db, 'Coni Compa', 'turnante', null);
    $mio = turno($db, '2026-12-10', 'largo', 'H7', $t); $suyo = turno($db, '2026-12-17', 'noche', 'H7', $c);
    $s = crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $mio, 'cambio_con_id' => $c, 'devolucion_turno_id' => $suyo]);
    reg_resolver($db, $s['id'], 'autorizar', '', 'JT');
    chequear((int)tu($db, $mio)['titular_id'] === $c && (int)tu($db, $suyo)['titular_id'] === $t, 'Coni hace el 10, Tati hace el 17');
    chequear(tu($db, $mio)['origen'] === 'cambio' && (int)tu($db, $mio)['titular_original_id'] === $t, 'queda registrado el titular original');
    reg_anular($db, $s['id'], $t, true, 'JT');
    chequear((int)tu($db, $mio)['titular_id'] === $t && (int)tu($db, $suyo)['titular_id'] === $c && tu($db, $mio)['titular_original_id'] === null, 'anular deja todo como estaba');
    $mio2 = turno($db, '2026-12-11', 'largo', 'H7', $t);
    $s2 = crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $mio2, 'cambio_con_id' => $c]);
    reg_resolver($db, $s2['id'], 'autorizar', '', 'JT');
    chequear((int)tu($db, $mio2)['titular_id'] === $c, 'sin devolución: solo cede el turno');
});

prueba('Licencia de un turnante: sus turnos del rango quedan «sin cubrir»; coordinación asigna reemplazo; anular restaura', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $t = mk($db, 'Tati Turno', 'turnante', null); $r = mk($db, 'Dani Diurno', 'diurno', 'H7');
    $a = turno($db, '2026-12-14', 'largo', 'H7', $t); $b = turno($db, '2026-12-16', 'noche', 'H7', $t); $fuera = turno($db, '2026-12-20', 'largo', 'H7', $t);
    $lic = reg_crear($db, ['funcionario_id' => $t, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-13', 'fecha_fin' => '2026-12-17'],
                     ['cfg' => cfg_defaults(), 'creada_por' => $k, 'por_coordinacion' => true]);
    chequear(tu($db, $a)['origen'] === 'licencia' && tu($db, $b)['origen'] === 'licencia' && tu($db, $fuera)['origen'] === 'carga', 'los turnos del rango quedan sin cubrir; el de fuera no');
    $_GET = ['desde' => '2026-12-01', 'hasta' => '2026-12-31'];
    $sc = h_coord_turnos_sin_cubrir($db, [], []);
    $_GET = [];
    chequear(array_column($sc['turnos'], 'id') === [$a, $b], 'aparecen en «sin cubrir»');
    h_coord_turno_cubrir($db, ['', $a], ['funcionario_id' => $r, 'es_extra' => true]);
    chequear((int)tu($db, $a)['cubierto_por_id'] === $r && (int)tu($db, $a)['cubierto_extra'] === 1, 'coordinación asigna reemplazo (extra)');
    reg_anular($db, $lic['id'], $k, true, 'Coordinadora');
    chequear(tu($db, $a)['origen'] === 'carga' && tu($db, $a)['cubierto_por_id'] === null && tu($db, $b)['origen'] === 'carga', 'anular la licencia restaura sus turnos');
});
