<?php
declare(strict_types=1);

// Resolución y errores (SPEC-v2 §4.8; plan 3.5).

prueba('Bug B4 (app anterior): si no se guarda, la respuesta es un error y no queda nada a medias', function () {
    [$app, , , $rf, $cf] = appConPersonas();
    $f = entrar($app, $rf, $cf, '482915');
    $r = $f->pedir('POST', 'solicitudes', ['tipo' => 'FL', 'fecha_inicio' => manana(14), 'fecha_fin' => manana(60)]);   // más de 15 días hábiles
    chequear(!isset($r['json']['ok']) && ($r['json']['codigo'] ?? '') === 'sin_saldo', 'responde error sin_saldo, nunca «ok»: ' . substr($r['raw'], 0, 120));
    $db = db_open($app . '/datos.sqlite');
    chequear((int)$db->query('SELECT COUNT(*) FROM solicitudes')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM solicitud_dias')->fetchColumn() === 0
             && (int)$db->query('SELECT COUNT(*) FROM solicitud_saldo')->fetchColumn() === 0, 'no quedó ninguna fila');
    $db = null;
});

prueba('No existe «volver a pendiente» ni borrar solicitudes: solo autorizar, rechazar, revertir y anular', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    reg_resolver($db, $r['id'], 'autorizar', '', 'JT');
    lanza(fn() => reg_resolver($db, $r['id'], 'pendiente', '', 'JT'), 409);
    $rutas = file_get_contents(dirname(TESTS_DIR) . '/public/api.php');
    chequear(!preg_match('#solicitudes/\(\\\\d\+\)/(pendiente|eliminar|borrar)#', $rutas) && !str_contains($rutas, "'DELETE'"), 'la API no tiene rutas para borrar ni volver a pendiente');
    chequear(!preg_match('/DELETE\s+FROM\s+solicitudes/i', file_get_contents(dirname(TESTS_DIR) . '/lib/reglas.php') . file_get_contents(dirname(TESTS_DIR) . '/lib/handlers_coord.php')), 'ningún código borra solicitudes');
});

prueba('HTTP: revertir a autorizada sin saldo falla con un mensaje claro', function () {
    [$app, $rc, $cc, $rf, $cf] = appConPersonas();
    $f = entrar($app, $rf, $cf, '482915');
    $k = entrar($app, $rc, $cc, '739104');
    $id = $f->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'completo'])['json']['id'] ?? 0;
    $k->pedir('POST', "coord/solicitudes/$id/resolver", ['accion' => 'rechazar', 'motivo' => 'Sin cobertura']);
    $fid = (int)(db_open($app . '/datos.sqlite')->query("SELECT id FROM funcionarios WHERE nombre='Funcionaria Prueba'")->fetchColumn());
    $k->pedir('POST', 'coord/saldos/ajustar', ['funcionario_id' => $fid, 'anio' => (int)substr(manana(), 0, 4), 'saldo' => 'ADM', 'dias' => -5.5, 'motivo' => 'Usados en RR.HH.']);
    $r = $k->pedir('POST', "coord/solicitudes/$id/revertir", []);
    chequear(($r['json']['codigo'] ?? '') === 'sin_saldo' && str_contains((string)($r['json']['error'] ?? ''), 'administrativos'), 'error sin_saldo con explicación: ' . $r['raw']);
});
