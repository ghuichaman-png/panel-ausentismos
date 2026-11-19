<?php
declare(strict_types=1);

// Control de acceso por sesión y rol a través de api.php (SPEC-v2 §5 y §7).

/** App temporal con una coordinadora y una funcionaria con códigos listos. Devuelve [app, rutCoord, codCoord, rutFunc, codFunc]. */
function appConPersonas(array $config = []): array
{
    $app = appTemporal($config);
    $db = db_open($app . '/datos.sqlite');
    $eq = (int)$db->query("SELECT id FROM equipos WHERE nombre='H7'")->fetchColumn();
    $ins = $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,rol) VALUES (?,?,?,?,?,?,?)');
    $rc = '15000003-' . rut_dv('15000003'); $rf = '16000006-' . rut_dv('16000006');
    $ins->execute(['Coordinadora Prueba', 'coordinadora prueba', $rc, 'diurno', $eq, 'titular', 'coordinacion']);
    $idc = (int)$db->lastInsertId();
    $ins->execute(['Funcionaria Prueba', 'funcionaria prueba', $rf, 'diurno', $eq, 'titular', 'funcionario']);
    $idf = (int)$db->lastInsertId();
    Reloj::$fijo = null;                                   // api.php usa el reloj real
    $cc = cuenta_generar_codigo($db, $idc, null); $cf = cuenta_generar_codigo($db, $idf, null);
    horaNormal();
    $db = null;
    return [$app, $rc, $cc, $rf, $cf];
}

function entrar(string $app, string $rut, string $cod, string $pin): ClienteApi
{
    $c = new ClienteApi($app);
    $r = $c->pedir('POST', 'auth/activar', ['rut' => $rut, 'codigo' => $cod, 'pin' => $pin]);
    chequear(($r['json']['autenticado'] ?? false) === true, "activa $rut: " . $r['raw']);
    return $c;
}

prueba('HTTP: sin sesión, todas las rutas protegidas responden 401 y no entregan datos', function () {
    [$app] = appConPersonas();
    $c = new ClienteApi($app);
    $s = $c->pedir('GET', 'auth/sesion');
    chequear(($s['json']['autenticado'] ?? null) === false && array_key_exists('csrf', $s['json'] ?? []) && $s['json']['csrf'] === null, 'auth/sesion sin cookie: no autenticado: ' . $s['raw']);
    foreach ([['GET', 'config'], ['GET', 'calendario'], ['GET', 'nomina/buscar'], ['GET', 'solicitudes'], ['POST', 'solicitudes/simular'],
              ['POST', 'solicitudes'], ['GET', 'coord/solicitudes'], ['GET', 'coord/respaldo'], ['GET', 'coord/nomina']] as [$m, $ruta]) {
        $r = $c->pedir($m, $ruta, $m === 'POST' ? [] : null);
        chequear(($r['json']['codigo'] ?? '') === 'sin_sesion' && !str_starts_with($r['raw'], 'SQLite'), "$m $ruta → sin sesión: " . substr($r['raw'], 0, 90));
    }
    $r = $c->pedir('POST', 'coord/login', ['clave' => 'x']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'no encontrada'), 'la clave compartida ya no existe (coord/login → 404)');
});

prueba('HTTP: activar deja la sesión iniciada; la cookie y el CSRF funcionan; salir la cierra', function () {
    [$app, , , $rf, $cf] = appConPersonas();
    $c = entrar($app, $rf, $cf, '482915');
    chequear(isset($c->cookies['aus_s']) && strlen($c->cookies['aus_s']) === 64 && $c->csrf !== null, 'cookie de sesión y CSRF');
    $s = $c->pedir('GET', 'auth/sesion');
    chequear(($s['json']['persona']['nombre'] ?? '') === 'Funcionaria Prueba' && ($s['json']['persona']['rol'] ?? '') === 'funcionario', 'auth/sesion devuelve la persona');
    chequear(isset($c->pedir('GET', 'config')['json']['equipos']), 'con sesión accede a config');
    $sin = clone $c; $sin->csrf = null;
    chequear(($sin->pedir('POST', 'solicitudes/simular', [])['json']['codigo'] ?? '') === 'csrf', 'POST sin CSRF → rechazado');
    $c->pedir('POST', 'auth/salir', []);
    chequear(!isset($c->cookies['aus_s']), 'salir borra la cookie');
    $viejo = clone $sin; // conserva la cookie anterior
    chequear(($viejo->pedir('GET', 'config')['json']['codigo'] ?? '') === 'sin_sesion', 'la sesión cerrada ya no sirve aunque se reuse la cookie');
});

prueba('HTTP: ingresar con RUT + PIN; computador compartido; listar y cerrar sesiones; cambiar PIN', function () {
    [$app, , , $rf, $cf] = appConPersonas();
    $a = entrar($app, $rf, $cf, '482915');
    $b = new ClienteApi($app);
    $r = $b->pedir('POST', 'auth/ingresar', ['rut' => $rf, 'pin' => '482915', 'compartido' => true]);
    chequear(($r['json']['compartido'] ?? null) === true, 'ingreso en modo compartido: ' . $r['raw']);
    $l = $a->pedir('GET', 'auth/sesiones')['json']['sesiones'] ?? [];
    chequear(count($l) === 2 && count(array_filter($l, fn($x) => $x['actual'])) === 1, 'lista 2 sesiones y marca la actual');
    $otra = array_values(array_filter($l, fn($x) => !$x['actual']))[0]['id'];
    $a->pedir('POST', "auth/sesiones/$otra/cerrar", []);
    chequear(($b->pedir('GET', 'config')['json']['codigo'] ?? '') === 'sin_sesion', 'cerrar una sesión desde otro dispositivo la invalida');
    $r = $a->pedir('POST', 'auth/pin', ['actual' => '482915', 'nuevo' => '739104']);
    chequear(($r['json']['ok'] ?? false) === true, 'cambia el PIN: ' . $r['raw']);
    $r = (new ClienteApi($app))->pedir('POST', 'auth/ingresar', ['rut' => $rf, 'pin' => '739104']);
    chequear(($r['json']['autenticado'] ?? false) === true, 'entra con el PIN nuevo');
});

prueba('HTTP: un funcionario no accede a coordinación (403); coordinación sí', function () {
    [$app, $rc, $cc, $rf, $cf] = appConPersonas();
    $f = entrar($app, $rf, $cf, '482915');
    foreach (['coord/solicitudes', 'coord/nomina', 'coord/respaldo', 'coord/equipos'] as $ruta) {
        $r = $f->pedir('GET', $ruta);
        chequear(($r['json']['codigo'] ?? '') === 'sin_permiso' && !str_starts_with($r['raw'], 'SQLite'), "funcionario en $ruta → 403");
    }
    $k = entrar($app, $rc, $cc, '739104');
    chequear(count($k->pedir('GET', 'coord/equipos')['json']['equipos'] ?? []) === 19, 'coordinación lista equipos');
    chequear(str_starts_with($k->pedir('GET', 'coord/respaldo')['raw'], 'SQLite format 3'), 'coordinación descarga el respaldo');
});

prueba('HTTP: coordinación genera códigos, desbloquea, cierra sesiones y asigna rol (sin quedar sin coordinadores)', function () {
    [$app, $rc, $cc, $rf, $cf] = appConPersonas();
    $k = entrar($app, $rc, $cc, '739104');
    $f = entrar($app, $rf, $cf, '482915');
    $lista = $k->pedir('GET', 'coord/nomina')['json']['funcionarios'] ?? [];
    $fila = array_values(array_filter($lista, fn($x) => $x['rut'] === $rf))[0] ?? [];
    chequear(($fila['cuenta'] ?? '') === 'activa', 'la nómina muestra el estado de la cuenta: ' . json_encode($fila['cuenta'] ?? null));
    $id = (int)$fila['id'];
    $r = $k->pedir('POST', "coord/nomina/$id/codigo", []);
    chequear(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', (string)($r['json']['codigo'] ?? '')) === 1, 'entrega un código nuevo: ' . $r['raw']);
    chequear(($f->pedir('GET', 'config')['json']['codigo'] ?? '') === 'sin_sesion', 'el código nuevo cerró las sesiones de la persona');
    $r = $k->pedir('POST', "coord/nomina/$id/rol", ['rol' => 'coordinacion']);
    chequear(($r['json']['rol'] ?? '') === 'coordinacion', 'asigna rol coordinación');
    $yo = (int)(array_values(array_filter($lista, fn($x) => $x['rut'] === $rc))[0]['id'] ?? 0);
    $k->pedir('POST', "coord/nomina/$id/rol", ['rol' => 'funcionario']);
    $r = $k->pedir('POST', "coord/nomina/$yo/rol", ['rol' => 'funcionario']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'al menos una persona'), 'no deja el sistema sin coordinación: ' . $r['raw']);
    $r = $k->pedir('POST', "coord/nomina/$id/desbloquear", []);
    chequear(($r['json']['ok'] ?? false) === true, 'desbloquear responde ok');
});

prueba('HTTP: límite de intentos por IP alto (solo contra ataques masivos)', function () {
    [$app, , , $rf] = appConPersonas(['limite_ip_auth_hora' => 3]);
    $c = new ClienteApi($app, '200.1.1.1');
    for ($i = 0; $i < 3; $i++) $c->pedir('POST', 'auth/ingresar', ['rut' => $rf, 'pin' => '000001']);
    $r = $c->pedir('POST', 'auth/ingresar', ['rut' => $rf, 'pin' => '000001']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'Demasiados intentos desde esta red'), 'al superar el límite de la IP responde 429: ' . $r['raw']);
});
