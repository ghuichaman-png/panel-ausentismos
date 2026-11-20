<?php
declare(strict_types=1);

// Vistas de coordinación «Hoy» y «Registro de cambios» (SPEC-v2 §6.2.10 y §6.2.11; plan 5.5).

prueba('Hoy: ausentes de hoy, turnos sin cubrir y pendientes que vencen pronto o ya vencieron', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora', 'diurno', 'H7');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7'); $b = mk($db, 'Beto Dos', 'diurno', 'H6'); $t = mk($db, 'Tati Turno', 'turnante', null);
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-01', 'fraccion' => 'am']);   // hoy (simulado: 01-12)
    reg_resolver($db, $r['id'], 'autorizar', '', 'JT');
    crear($db, ['funcionario_id' => $b, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-03', 'fraccion' => 'completo']);  // pendiente que vence pronto
    crear($db, ['funcionario_id' => $b, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-21', 'fraccion' => 'completo']);  // pendiente lejana
    turno($db, '2026-12-02', 'largo', 'H7', $t);
    reg_crear($db, ['funcionario_id' => $t, 'tipo' => 'LIC', 'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-04'], ['cfg' => cfg_defaults(), 'creada_por' => $k, 'por_coordinacion' => true]);
    $h = h_coord_hoy($db, [], []);
    $aus = array_column($h['ausentes'], 'etiqueta', 'nombre');
    chequear(($aus['Ana Uno'] ?? '') === 'ADM ½ AM' && ($aus['Tati Turno'] ?? '') === 'Licencia' && !isset($aus['Beto Dos']), 'ausentes de hoy con su etiqueta: ' . json_encode($aus, JSON_UNESCAPED_UNICODE));
    chequear(count($h['sin_cubrir']) === 1 && $h['sin_cubrir'][0]['fecha'] === '2026-12-02', 'el turno de la licencia aparece sin cubrir');
    chequear(count($h['pendientes_pronto']) === 1 && $h['pendientes_pronto'][0]['fecha_inicio'] === '2026-12-03', 'solo la pendiente de los próximos 3 días');
});

prueba('Registro de cambios: muestra quién hizo cada acción, con filtro de texto', function () {
    $db = nuevaDb();
    $k = mk($db, 'Coordinadora Prueba', 'diurno', 'H7');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    reg_resolver($db, $r['id'], 'autorizar', '', 'Coordinadora Prueba', '1.1.1.1', $k);
    saldo_ajustar($db, $a, 2026, 'FL', 2, 'Antigüedad', $k);
    $l = h_coord_auditoria($db, [], []);
    $acc = array_column($l['registros'], 'quien', 'accion');
    chequear(($acc['autorizar'] ?? '') === 'Coordinadora Prueba' && ($acc['crear'] ?? '') === 'Ana Uno' && ($acc['saldo_ajuste'] ?? '') === 'Coordinadora Prueba', 'acciones con su autor: ' . json_encode($acc, JSON_UNESCAPED_UNICODE));
    $_GET = ['q' => 'antigüedad'];
    $f = h_coord_auditoria($db, [], []);
    $_GET = [];
    chequear(count($f['registros']) === 1 && $f['registros'][0]['accion'] === 'saldo_ajuste', 'filtra por texto (sin importar tildes)');
});
