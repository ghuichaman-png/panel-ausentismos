<?php
declare(strict_types=1);

// Reglas de negocio de SPEC §4 (cupo, FL, turnantes, estados, anulación, superposición, nómina).

prueba('RUT: dígito verificador y normalización', function () {
    chequear(rut_normalizar('12.345.678-5') === '12345678-5', 'acepta puntos');
    chequear(rut_normalizar('123456785') === '12345678-5', 'acepta sin guion');
    chequear(rut_normalizar('12345678-4') === null, 'rechaza DV malo');
    chequear(rut_normalizar('abc') === null, 'rechaza basura');
});

prueba('Diurno ADM completo, cupo 2: ocupa 1; el tercero es rechazado; con justificación queda sobre_cupo', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7'); $b = mk($db, 'Beto Dos', 'diurno', 'H7'); $c = mk($db, 'Carla Tres', 'diurno', 'H7');
    $in = fn($id) => ['funcionario_id' => $id, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo'];
    $r = crear($db, $in($a));
    chequear(ocup($db, 'H7', '2026-12-10') === 1.0, 'tras el 1.º ocupa 1');
    chequear($r['sobre_cupo'] === false, 'el 1.º no es sobre cupo');
    crear($db, $in($b));
    chequear(ocup($db, 'H7', '2026-12-10') === 2.0, 'tras el 2.º ocupa 2');
    $e = lanza(fn() => crear($db, $in($c)), 409);
    chequear(($e->extra['codigo'] ?? '') === 'sin_cupo' && count($e->extra['dias_llenos'] ?? []) === 1, 'informa el día lleno');
    lanza(fn() => crear($db, $in($c) + ['acepta_sobre_cupo' => true]), 422, 'justificación');
    $r3 = crear($db, $in($c) + ['acepta_sobre_cupo' => true, 'justificacion' => 'Control médico de mi hijo']);
    chequear($r3['sobre_cupo'] === true, 'queda marcada sobre cupo');
    $fila = $db->query('SELECT sobre_cupo, justificacion_sobre_cupo FROM solicitudes WHERE id=' . $r3['id'])->fetch();
    chequear((int)$fila['sobre_cupo'] === 1 && $fila['justificacion_sobre_cupo'] !== null, 'se guarda la justificación');
});

prueba('Dos ADM medio día (AM + PM): cada uno ocupa 1 en su bloque (SPEC-v2 §4.2; antes «suman 1»)', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H6'); $b = mk($db, 'Beto Dos', 'diurno', 'H6');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    crear($db, ['funcionario_id' => $b, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'pm']);
    $o = reg_ocupacion_bloques($db, eid($db, 'H6'), '2026-12-10', '2026-12-10')['2026-12-10'];
    chequear($o === ['am' => 1.0, 'pm' => 1.0], 'mañana 1 y tarde 1');
});

prueba('FL de 4 días hábiles de titular consume 1 por día', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7', 'titular');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-17']);
    chequear($r['habiles'] === 4 && $r['consumo_total'] === 4.0, '4 días, consumo 4');
    foreach (['2026-12-14', '2026-12-15', '2026-12-16', '2026-12-17'] as $f) chequear(ocup($db, 'H7', $f) === 1.0, "ocupa 1 el $f");
});

prueba('FL de 5 días hábiles de titular/contrata consume 0 pero aparece en el calendario', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7', 'titular'); $b = mk($db, 'Beto Dos', 'diurno', 'H7', 'contrata');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);
    crear($db, ['funcionario_id' => $b, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18']);
    chequear($r['habiles'] === 5 && $r['consumo_total'] === 0.0, 'consumo total 0');
    chequear(ocup($db, 'H7', '2026-12-16') === 0.0, 'no ocupa cupo');
    $cal = cal_datos($db, '2026-12', false);
    $nombres = array_column(array_filter($cal['ausencias'], fn($x) => $x['fecha'] === '2026-12-16'), 'nombre');
    chequear(in_array('Ana Uno', $nombres, true) && in_array('Beto Dos', $nombres, true), 'ambos aparecen en el calendario');
    chequear(!array_key_exists('rut', $cal['ausencias'][0]) && !array_key_exists('calidad', $cal['ausencias'][0]), 'el calendario público no expone RUT ni calidad');
});

prueba('FL de 10 días hábiles de honorarios consume 1 por día', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7', 'honorarios');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-25']);
    // 14–18 (5) + 21–25 (5) = 10 hábiles; el 25 de dic. no está cargado como feriado en la prueba
    chequear($r['habiles'] === 10 && $r['consumo_total'] === 10.0, '10 días, consumo 10');
    chequear(ocup($db, 'H7', '2026-12-22') === 1.0, 'ocupa 1 un día intermedio');
});

prueba('Rango que cruza fin de semana y feriado: se omiten y no se registran', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7', 'honorarios');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-07', 'fecha_fin' => '2026-12-14']);
    chequear($r['habiles'] === 5, '5 hábiles (7, 9, 10, 11, 14)');
    $motivos = array_column($r['omitidos'], 'motivo', 'fecha');
    chequear(($motivos['2026-12-08'] ?? '') === 'feriado' && ($motivos['2026-12-12'] ?? '') === 'fin de semana', 'omitidos informados');
    $n = (int)$db->query("SELECT COUNT(*) FROM solicitud_dias WHERE solicitud_id={$r['id']}")->fetchColumn();
    chequear($n === 5, 'solo 5 filas en solicitud_dias');
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-08']), 422, 'hábiles');
});

prueba('Turnantes: FL, ADM y CT nunca consumen cupo; reemplazo obligatorio (sobre turnos cargados, SPEC-v2 §4.3)', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null);
    $p = mk($db, 'Pedro Reemplazo', 'turnante', null); $l = mk($db, 'Luis Colega', 'turnante', null);
    $t10 = turno($db, '2026-12-10', 'largo', 'H7', $t); $t11 = turno($db, '2026-12-11', 'largo', 'H7', $t);
    $t20 = turno($db, '2026-12-20', 'largo', 'H7', $t); $t22 = turno($db, '2026-12-22', 'largo', 'H7', $t);
    $t15 = turno($db, '2026-12-15', 'largo', 'H7', $t); $t16 = turno($db, '2026-12-16', 'largo', 'H7', $t);
    $dev = turno($db, '2026-12-29', 'noche', 'H7', $l);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-10', 'fecha_fin' => '2026-12-11', 'reemplazos' => [$t10 => $p, $t11 => $p]]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'primera_mitad', 'turno_id' => $t20, 'reemplazo_id' => $p]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $t22, 'cambio_con_id' => $l, 'devolucion_turno_id' => $dev]);
    $suma = (float)$db->query('SELECT COALESCE(SUM(consumo),0) FROM solicitud_dias')->fetchColumn();
    chequear($suma === 0.0, 'consumo total de turnantes = 0');
    chequear(ocup($db, 'H7', '2026-12-10') === 0.0, 'no ocupa cupo del equipo');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-15', 'fecha_fin' => '2026-12-15']), 422, 'cubre');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'turno_id' => $t16]), 422, 'con quién');
    // un diurno no puede pedir CT
    $d = mk($db, 'Dani Diurno', 'diurno', 'H7');
    lanza(fn() => crear($db, ['funcionario_id' => $d, 'tipo' => 'CT', 'fecha_inicio' => '2026-12-16']), 422, 'turnantes');
});

prueba('Rechazo y anulación liberan cupo; rechazada → autorizada re-verifica cupo', function () {
    $db = nuevaDb();
    $db->exec("UPDATE equipos SET cupo_diurno=1 WHERE nombre='H7'");
    $a = mk($db, 'Ana Uno', 'diurno', 'H7'); $b = mk($db, 'Beto Dos', 'diurno', 'H7');
    $in = fn($id) => ['funcionario_id' => $id, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo'];
    $ra = crear($db, $in($a));
    lanza(fn() => crear($db, $in($b)), 409);
    reg_resolver($db, $ra['id'], 'rechazar', 'Sin cobertura', 'JT');
    chequear(ocup($db, 'H7', '2026-12-10') === 0.0, 'el rechazo libera el cupo');
    $rb = crear($db, $in($b));
    chequear(ocup($db, 'H7', '2026-12-10') === 1.0, 'B toma el cupo liberado');
    $e = lanza(fn() => reg_revertir($db, $ra['id'], '', 'JT'), 409);
    chequear(($e->extra['codigo'] ?? '') === 'sin_cupo', 'revertir a autorizada re-verifica y avisa sin cupo');
    lanza(fn() => reg_revertir($db, $ra['id'], '', 'JT', true, ''), 422, 'justificación');
    $r = reg_revertir($db, $ra['id'], '', 'JT', true, 'Caso excepcional autorizado por jefatura');
    chequear($r['estado'] === 'autorizada' && $r['sobre_cupo'] === true, 'autorizada como sobre cupo');
    chequear(ocup($db, 'H7', '2026-12-10') === 2.0, 'ahora ocupa 2 de 1');
    reg_anular($db, $rb['id'], null, true, 'JT');
    chequear(ocup($db, 'H7', '2026-12-10') === 1.0, 'la anulación libera el cupo');
    lanza(fn() => reg_resolver($db, $rb['id'], 'autorizar', '', 'JT'), 409);
    lanza(fn() => reg_resolver($db, $ra['id'], 'rechazar', '', 'JT'), 409); // ya autorizada
});

prueba('Anulación: otra persona no puede; pasado el plazo falla; en plazo funciona; coordinación siempre', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $b = mk($db, 'Beto Dos', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02', 'fraccion' => 'completo']);
    // Desde la etapa 2 se identifica por la sesión (antes: por RUT). Nadie anula la de otra persona.
    lanza(fn() => reg_anular($db, $r['id'], $b, false), 403, 'propias');
    lanza(fn() => reg_anular($db, $r['id'], null, false), 403, 'propias');
    // 2026-12-02 es la primera fecha; hoy = 12-01 → aún en plazo (hasta 23:59 de hoy)
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-04', 'fraccion' => 'completo']);
    reg_anular($db, $r2['id'], $a, false);
    chequear($db->query("SELECT estado FROM solicitudes WHERE id={$r2['id']}")->fetchColumn() === 'anulada', 'anulada en plazo por su dueña');
    // avanzamos el reloj al día de la primera fecha: ya no se puede
    Reloj::$fijo = new DateTimeImmutable('2026-12-02 00:05:00', new DateTimeZone('America/Santiago'));
    lanza(fn() => reg_anular($db, $r['id'], $a, false), 409, 'plazo');
    reg_anular($db, $r['id'], $b, true, 'JT'); // coordinación sí puede
    chequear($db->query("SELECT estado FROM solicitudes WHERE id={$r['id']}")->fetchColumn() === 'anulada', 'coordinación anula sin plazo');
    Reloj::$fijo = new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('America/Santiago'));
});

prueba('Solicitudes superpuestas de la misma persona se rechazan', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'FL', 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-16']);
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-15', 'fraccion' => 'am']), 409, 'coincide');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-17', 'fraccion' => 'am']);
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-17', 'fraccion' => 'pm']);
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-17', 'fraccion' => 'am']), 409);
    // turnante: el mismo turno choca; otro turno del mismo día, no
    $t = mk($db, 'Tati Turno', 'turnante', null); $p = mk($db, 'Pedro Reemplazo', 'turnante', null);
    $l12 = turno($db, '2026-12-12', 'largo', 'H7', $t); $n12 = turno($db, '2026-12-12', 'noche', 'H7', $t);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $l12, 'reemplazo_id' => $p]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'turno_id' => $n12, 'reemplazo_id' => $p]);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'completo', 'turno_id' => $l12, 'reemplazo_id' => $p]), 409);
});

prueba('Validaciones: fecha pasada, persona obligatoria (ya no hay «fuera de nómina») y código correlativo', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-11-30', 'fraccion' => 'completo']), 422, 'pasadas');
    lanza(fn() => crear($db, ['rut' => '16000000-' . rut_dv('16000000'), 'nombre' => 'Persona Externa', 'jornada' => 'diurno',
                              'equipo_id' => eid($db, 'H8'), 'calidad' => 'contrata', 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10']), 422, 'persona');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    chequear(preg_match('/^AUS-2026-0001$/', $r['codigo']) === 1, 'código AUS-2026-0001: ' . $r['codigo']);
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'pm']);
    chequear($r2['codigo'] === 'AUS-2026-0002', 'código correlativo');
});

prueba('CSV de nómina: upsert por RUT y reporte de errores', function () {
    $db = nuevaDb();
    $db = $db; // usa la API interna
    global $dbpath;
    $csv = "nombre,rut,jornada,equipo,calidad\nAna Uno,12345678-5,diurno,H7,titular\nBeto,12345678-9,diurno,H7,titular\nCarla Tres,13456789-9,turnante,,contrata\nDora,14567890-0,diurno,Equipo Fantasma,titular\n";
    $r = (function () use ($db, $csv) {
        $GLOBALS['__db'] = $db;
        return h_coord_nomina_importar($db, [], ['csv' => $csv]);
    })();
    chequear($r['creados'] === 2 && $r['actualizados'] === 0 && count($r['errores']) === 2, 'creados 2, errores 2: ' . json_encode($r));
    $r = h_coord_nomina_importar($db, [], ['csv' => $csv]);
    chequear($r['creados'] === 0 && $r['actualizados'] === 0 && $r['sin_cambios'] === 2, 'segunda pasada no duplica ni cambia nada: ' . json_encode($r));
});

