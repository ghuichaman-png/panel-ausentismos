<?php
declare(strict_types=1);

// Solicitudes con la persona de la sesión (SPEC-v2 §4.8, §5 y §6; plan 2.5).

/** App con coordinadora y dos funcionarias ya activadas. Devuelve [app, coord, ana, beto, idAna, idBeto]. */
function appTresPersonas(array $config = []): array
{
    [$app, $rc, $cc, $rf, $cf] = appConPersonas($config);
    $db = db_open($app . '/datos.sqlite');
    $eq = (int)$db->query("SELECT id FROM equipos WHERE nombre='H7'")->fetchColumn();
    $rb = '17000009-' . rut_dv('17000009');
    $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad) VALUES (?,?,?,?,?,?)')
       ->execute(['Beto Dos', 'beto dos', $rb, 'diurno', $eq, 'titular']);
    $idB = (int)$db->lastInsertId();
    Reloj::$fijo = null; $cb = cuenta_generar_codigo($db, $idB, null); horaNormal();
    $idA = (int)$db->query("SELECT id FROM funcionarios WHERE nombre='Funcionaria Prueba'")->fetchColumn();
    $db = null;
    return [$app, entrar($app, $rc, $cc, '739104'), entrar($app, $rf, $cf, '482915'), entrar($app, $rb, $cb, '518273'), $idA, $idB];
}

function manana(int $dias = 14): string
{
    $d = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $d = $d->modify("+$dias days");
    while ((int)$d->format('N') >= 6) $d = $d->modify('+1 day');      // día hábil
    return $d->format('Y-m-d');
}

prueba('Solicitudes: quedan a nombre de quien inició sesión, aunque el cuerpo diga otra persona', function () {
    [$app, , $ana, , $idA, $idB] = appTresPersonas();
    $r = $ana->pedir('POST', 'solicitudes', ['funcionario_id' => $idB, 'rut' => '1-9', 'nombre' => 'Otro', 'tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'completo']);
    chequear(($r['json']['ok'] ?? false) === true, 'se crea: ' . $r['raw']);
    $db = db_open($app . '/datos.sqlite');
    $f = $db->query('SELECT funcionario_id, nombre, creada_por, fuera_de_nomina FROM solicitudes')->fetch();
    chequear((int)$f['funcionario_id'] === $idA && $f['nombre'] === 'Funcionaria Prueba', 'quedó a nombre de Ana (la sesión), no de Beto');
    chequear((int)$f['creada_por'] === $idA && (int)$f['fuera_de_nomina'] === 0, 'creada_por = Ana; nunca «fuera de nómina»');
    $db = null;
});

prueba('Mis solicitudes: cada persona ve solo las suyas (también buscando por código)', function () {
    [, , $ana, $beto] = appTresPersonas();
    $ra = $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'am']);
    $beto->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'pm']);
    $mias = $ana->pedir('GET', 'solicitudes')['json']['solicitudes'] ?? [];
    chequear(count($mias) === 1 && $mias[0]['nombre'] === 'Funcionaria Prueba', 'Ana ve solo la suya');
    $r = $beto->pedir('GET', 'solicitudes', null, ['codigo' => $ra['json']['codigo'] ?? 'x']);
    chequear(($r['json']['solicitudes'] ?? null) === [], 'Beto no ve la de Ana ni con su código');
});

prueba('Anular: solo la dueña (sin RUT) o coordinación; nadie anula la de otra persona', function () {
    [, $coord, $ana, $beto] = appTresPersonas();
    $id1 = $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'am'])['json']['id'] ?? 0;
    $id2 = $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(15), 'fraccion' => 'pm'])['json']['id'] ?? 0;
    $r = $beto->pedir('POST', "solicitudes/$id1/anular", []);
    chequear(($r['json']['codigo'] ?? '') === 'no_es_tuya', 'Beto no puede anular la de Ana: ' . $r['raw']);
    $r = $ana->pedir('POST', "solicitudes/$id1/anular", []);
    chequear(($r['json']['estado'] ?? '') === 'anulada', 'Ana anula la suya sin RUT: ' . $r['raw']);
    $r = $coord->pedir('POST', "coord/solicitudes/$id2/anular", []);
    chequear(($r['json']['estado'] ?? '') === 'anulada', 'coordinación anula: ' . $r['raw']);
});

prueba('Coordinación: registra «en nombre de» y resuelve firmando con su cuenta (sin «Resuelto por» a mano)', function () {
    [$app, $coord, , , $idA] = appTresPersonas();
    $r = $coord->pedir('POST', 'coord/solicitudes/en-nombre-de', ['funcionario_id' => $idA, 'tipo' => 'ADM', 'fecha_inicio' => manana(), 'fraccion' => 'completo']);
    chequear(($r['json']['ok'] ?? false) === true, 'registra en nombre de Ana: ' . $r['raw']);
    $id = (int)($r['json']['id'] ?? 0);
    $r = $coord->pedir('POST', "coord/solicitudes/$id/resolver", ['accion' => 'autorizar']);
    chequear(($r['json']['estado'] ?? '') === 'autorizada', 'autoriza sin escribir «Resuelto por»: ' . $r['raw']);
    $db = db_open($app . '/datos.sqlite');
    $s = $db->query("SELECT s.funcionario_id, s.creada_por, s.resuelta_por_id, s.resuelto_por, f.nombre AS coord FROM solicitudes s JOIN funcionarios f ON f.id=s.creada_por WHERE s.id=$id")->fetch();
    chequear((int)$s['funcionario_id'] === $idA && $s['coord'] === 'Coordinadora Prueba', 'es de Ana y la creó la coordinadora');
    chequear((int)$s['resuelta_por_id'] === (int)$s['creada_por'] && $s['resuelto_por'] === 'Coordinadora Prueba', 'resolución firmada por la cuenta de coordinación');
    $acc = $db->query("SELECT funcionario_id FROM auditoria WHERE accion='crear' AND solicitud_id=$id")->fetchColumn();
    chequear((int)$acc === (int)$s['creada_por'], 'la auditoría registra quién la creó');
    $db = null;
});

prueba('Límite de envíos por cuenta, no por IP', function () {
    [, , $ana, $beto] = appTresPersonas(['limite_envios_hora' => 2]);
    $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(14), 'fraccion' => 'am']);
    $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(15), 'fraccion' => 'am']);
    $r = $ana->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(16), 'fraccion' => 'am']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'muchas solicitudes'), 'Ana llega a su límite: ' . $r['raw']);
    $r = $beto->pedir('POST', 'solicitudes', ['tipo' => 'ADM', 'fecha_inicio' => manana(14), 'fraccion' => 'pm']);
    chequear(($r['json']['ok'] ?? false) === true, 'Beto, desde la misma IP, sí puede: ' . $r['raw']);
});

prueba('Ya no existen la solicitud «fuera de nómina» ni la vinculación', function () {
    [, $coord] = appTresPersonas();
    $r = $coord->pedir('POST', 'coord/solicitudes/1/vincular', []);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'no encontrada'), 'coord/.../vincular ya no existe: ' . $r['raw']);
});

prueba('Bug N2 por HTTP: simular con el id de otra persona calcula para quien tiene la sesión y no revela a la otra', function () {
    [$app, , $ana, , , $idB] = appTresPersonas();
    $db = db_open($app . '/datos.sqlite');
    $db->exec("UPDATE funcionarios SET calidad='honorarios' WHERE id=$idB");
    $db = null;
    $r = $ana->pedir('POST', 'solicitudes/simular', ['funcionario_id' => $idB, 'tipo' => 'FL', 'fecha_inicio' => manana(14), 'fecha_fin' => manana(20)]);
    $txt = $r['raw'];
    chequear(!str_contains($txt, 'honorarios') && !str_contains($txt, 'Beto') && !str_contains($txt, '"persona"'), 'no revela datos de Beto: ' . $txt);
    chequear(($r['json']['habiles'] ?? 0) >= 5 && ($r['json']['consumo_total'] ?? -1) === 0, 'calcula con la calidad de Ana (titular: FL largo sin cupo), no la de Beto');
});
