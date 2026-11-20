<?php
declare(strict_types=1);

// Nómina: 19 equipos, teléfono y rol (migración 2). SPEC-v2 §3.1 y §3.2.

prueba('Equipos: se siembran los 19 (incluye Oncología infantil y Neurología infantil)', function () {
    $db = nuevaDb();
    $n = array_column($db->query('SELECT nombre FROM equipos')->fetchAll(), 'nombre');
    chequear(count($n) === 19, '19 equipos, hay ' . count($n));
    chequear(in_array('Oncología infantil', $n, true) && in_array('Neurología infantil', $n, true), 'incluye los dos equipos infantiles');
});

prueba('Migración 2: funcionarios tiene teléfono y rol (por defecto «funcionario»)', function () {
    $db = nuevaDb();
    $cols = array_column($db->query('PRAGMA table_info(funcionarios)')->fetchAll(), 'dflt_value', 'name');
    chequear(array_key_exists('telefono', $cols) && array_key_exists('rol', $cols), 'columnas telefono y rol');
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    chequear($db->query("SELECT rol FROM funcionarios WHERE id=$a")->fetchColumn() === 'funcionario', 'rol por defecto funcionario');
    $rechazado = false;
    try { $db->exec("UPDATE funcionarios SET rol='jefatura' WHERE id=$a"); } catch (PDOException $e) { $rechazado = true; }
    chequear($rechazado, 'la base rechaza roles que no existen (solo funcionario y coordinacion)');
});

prueba('Teléfono: se normaliza a +56… y se rechaza lo que no es un teléfono', function () {
    chequear(telefono_normalizar('9 1234 5678') === '+56912345678', 'celular de 9 dígitos');
    chequear(telefono_normalizar('+56 9 1234-5678') === '+56912345678', 'con +56, espacios y guion');
    chequear(telefono_normalizar('56912345678') === '+56912345678', 'con 56 sin +');
    chequear(telefono_normalizar('(65) 236 2000') === '+56652362000', 'fijo de Puerto Montt con código de área');
    chequear(telefono_normalizar('') === null, 'vacío = sin teléfono');
    lanza(fn() => telefono_normalizar('llámame'), 422, 'teléfono');
    lanza(fn() => telefono_normalizar('123'), 422, 'teléfono');
});

prueba('CSV de nómina: acepta la columna teléfono (opcional) y la guarda', function () {
    $db = nuevaDb();
    $csv = "nombre,rut,jornada,equipo,calidad,telefono\nAna Uno,12345678-5,diurno,H7,titular,9 1234 5678\nCarla Tres,13456789-9,turnante,,contrata,\nDora Cuatro,14567890-0,diurno,H7,titular,no tengo\n";
    $r = h_coord_nomina_importar($db, [], ['csv' => $csv]);
    chequear($r['creados'] === 2 && count($r['errores']) === 1, 'creados 2 y 1 error (teléfono inválido): ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    $tel = $db->query("SELECT telefono FROM funcionarios WHERE rut='12345678-5'")->fetchColumn();
    chequear($tel === '+56912345678', 'teléfono guardado normalizado: ' . var_export($tel, true));
    chequear($db->query("SELECT telefono FROM funcionarios WHERE rut='13456789-9'")->fetchColumn() === null, 'sin teléfono queda vacío');
    // un CSV antiguo, sin la columna teléfono, sigue funcionando y no borra el teléfono ya guardado
    $r = h_coord_nomina_importar($db, [], ['csv' => "nombre,rut,jornada,equipo,calidad\nAna Uno,12345678-5,diurno,H6,titular\n"]);
    chequear($r['actualizados'] === 1 && count($r['errores']) === 0, 'CSV sin columna teléfono funciona');
    chequear($db->query("SELECT telefono FROM funcionarios WHERE rut='12345678-5'")->fetchColumn() === '+56912345678', 'no borra el teléfono existente');
});

prueba('Edición de nómina: guarda el teléfono; el rol no se cambia desde ahí', function () {
    $db = nuevaDb();
    $r = h_coord_nomina_guardar($db, [], ['nombre' => 'Beto Dos', 'rut' => '12345678-5', 'jornada' => 'diurno', 'equipo_id' => eid($db, 'H7'),
                                         'calidad' => 'titular', 'telefono' => '912345678', 'rol' => 'coordinacion']);
    $f = $db->query('SELECT telefono, rol FROM funcionarios WHERE id=' . $r['id'])->fetch();
    chequear($f['telefono'] === '+56912345678', 'teléfono guardado');
    chequear($f['rol'] === 'funcionario', 'el rol no se asigna por esta vía (se gestiona en la etapa 2)');
    lanza(fn() => h_coord_nomina_guardar($db, [(string)$r['id'], $r['id']], ['nombre' => 'Beto Dos', 'rut' => '12345678-5', 'jornada' => 'diurno',
        'equipo_id' => eid($db, 'H7'), 'calidad' => 'titular', 'telefono' => 'abc']), 422, 'teléfono');
    $l = h_coord_nomina_listar($db, [], []);
    chequear(($l['funcionarios'][0]['telefono'] ?? null) === '+56912345678', 'coordinación ve el teléfono en el listado');
});

prueba('Privacidad: la búsqueda de nómina y el calendario nunca devuelven teléfono, RUT ni calidad', function () {
    $db = nuevaDb();
    h_coord_nomina_guardar($db, [], ['nombre' => 'Beto Dos', 'rut' => '12345678-5', 'jornada' => 'diurno', 'equipo_id' => eid($db, 'H7'),
                                    'calidad' => 'honorarios', 'telefono' => '912345678']);
    $_GET['q'] = 'beto';
    $b = h_nomina_buscar($db, [], []);
    unset($_GET['q']);
    $txt = json_encode($b, JSON_UNESCAPED_UNICODE);
    chequear(count($b['resultados']) === 1, 'encuentra a la persona');
    chequear(!str_contains($txt, '912345678') && !str_contains($txt, '12345678-5') && !str_contains($txt, 'honorarios'), 'sin teléfono, RUT ni calidad: ' . $txt);
    // Calendario (SPEC-v2 §9): ni RUT, calidad, teléfono, comentarios ni justificaciones de sobre cupo
    $beto = (int)$db->query("SELECT id FROM funcionarios WHERE rut='12345678-5'")->fetchColumn();
    $c1 = mk($db, 'Uno Lleno', 'diurno', 'H7'); $c2 = mk($db, 'Dos Lleno', 'diurno', 'H7');
    crear($db, ['funcionario_id' => $c1, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    crear($db, ['funcionario_id' => $c2, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    $r = crear($db, ['funcionario_id' => $beto, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo',
                     'acepta_sobre_cupo' => true, 'justificacion' => 'JUSTIFICACION-SECRETA', 'comentario' => 'COMENTARIO-PRIVADO']);
    chequear(!empty($r['sobre_cupo']), 'quedó sobre cupo con justificación');
    $_GET['mes'] = '2026-12';
    $cal = json_encode(h_calendario($db, [], []), JSON_UNESCAPED_UNICODE);
    unset($_GET['mes']);
    chequear(str_contains($cal, 'Beto Dos'), 'el calendario muestra a la persona');
    foreach (['912345678', '12345678-5', 'honorarios', 'JUSTIFICACION-SECRETA', 'COMENTARIO-PRIVADO', '1.2.3.4'] as $x) {
        chequear(!str_contains($cal, $x), "el calendario no expone «{$x}»");
    }
});

prueba('Bug (encontrado en la etapa 1): los mensajes de error del CSV y de feriados muestran el dato que falló', function () {
    $db = nuevaDb();
    $e = lanza(fn() => h_coord_nomina_importar($db, [], ['csv' => "nombre,rut,jornada,equipo\nAna,12345678-5,diurno,H7\n"]), 422);
    chequear($e !== null && str_contains($e->getMessage(), '«calidad»'), 'nombra la columna que falta: ' . ($e ? $e->getMessage() : ''));
    $e = lanza(fn() => h_coord_feriados_guardar($db, [], ['texto' => "esto no es una fecha"]), 422);
    chequear($e !== null && str_contains($e->getMessage(), '«esto no es una fecha»'), 'repite la línea que no entendió: ' . ($e ? $e->getMessage() : ''));
});
