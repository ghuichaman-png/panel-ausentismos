<?php
declare(strict_types=1);

// Login por usuario, opción A (SPEC-v2 §5): código de activación, PIN, bloqueo por cuenta y sesiones.

function hora(string $s): void { Reloj::$fijo = new DateTimeImmutable($s, new DateTimeZone('America/Santiago')); }
function horaNormal(): void { hora('2026-12-01 09:00:00'); }
function meta(): array { return ['ip' => '10.0.0.1', 'ua' => 'pruebas']; }

/** Crea una persona con código de activación generado por una coordinadora; devuelve [id, rut, código]. */
function conCodigo(PDO $db, string $nombre = 'Ana Uno'): array
{
    $coord = mk($db, 'Coordinadora ' . bin2hex(random_bytes(2)), 'diurno', 'H7');
    $db->exec("UPDATE funcionarios SET rol='coordinacion' WHERE id=$coord");
    $id = mk($db, $nombre, 'diurno', 'H7');
    return [$id, rutDe($db, $id), cuenta_generar_codigo($db, $id, $coord)];
}

prueba('Código de activación: 8 caracteres sin ambiguos, se guarda solo su hash y vence en 7 días', function () {
    $db = nuevaDb();
    [$id, , $cod] = conCodigo($db);
    chequear(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $cod) === 1, "formato XXXX-XXXX sin 0/O/1/I/L: $cod");
    $c = $db->query("SELECT * FROM credenciales WHERE funcionario_id=$id")->fetch();
    chequear($c && !str_contains((string)$c['codigo_hash'], str_replace('-', '', $cod)) && password_verify(str_replace('-', '', $cod), $c['codigo_hash']), 'en la base solo está el hash');
    chequear($c['codigo_vence'] === '2026-12-08 09:00:00', 'vence en 7 días: ' . $c['codigo_vence']);
});

prueba('Activar: con RUT + código válido se crea el PIN y queda iniciada la sesión; el código no se puede reusar', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    $s = cuenta_activar($db, $rut, strtolower($cod), '482915', false, meta());
    chequear(($s['funcionario']['id'] ?? 0) === $id && strlen($s['token']) === 64 && strlen($s['csrf']) >= 32, 'entrega token y CSRF');
    chequear(sesion_validar($db, $s['token'])['funcionario_id'] === $id, 'la sesión es válida');
    lanza(fn() => cuenta_activar($db, $rut, $cod, '739104', false, meta()), 401, 'código');
    chequear(cuenta_ingresar($db, $rut, '482915', false, meta())['funcionario']['id'] === $id, 'después entra con RUT + PIN');
});

prueba('Activar: código vencido o de otra persona no sirve', function () {
    $db = nuevaDb();
    [, $rutA, $codA] = conCodigo($db, 'Ana Uno');
    [, $rutB] = conCodigo($db, 'Beto Dos');
    lanza(fn() => cuenta_activar($db, $rutB, $codA, '482915', false, meta()), 401);
    hora('2026-12-08 09:00:01');
    lanza(fn() => cuenta_activar($db, $rutA, $codA, '482915', false, meta()), 401, 'venció');
    horaNormal();
});

prueba('PIN: 6 dígitos y no trivial (repetidos, secuencias, parte del RUT)', function () {
    foreach (['12345', '1234567', 'abcdef', '000000', '111111', '123456', '654321', '012345', '123123', '121212'] as $malo) {
        lanza(fn() => pin_validar($malo, '15000003-3'), 422);
    }
    lanza(fn() => pin_validar('000003', '15000003-3'), 422, 'RUT');
    lanza(fn() => pin_validar('150000', '15000003-3'), 422, 'RUT');
    pin_validar('482915', '15000003-3');
    chequear(true, 'un PIN razonable se acepta');
});

prueba('Ingresar: RUT o PIN incorrectos dan el mismo mensaje; persona desactivada no entra', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    cuenta_activar($db, $rut, $cod, '482915', false, meta());
    $e1 = lanza(fn() => cuenta_ingresar($db, $rut, '999999', false, meta()), 401);
    $e2 = lanza(fn() => cuenta_ingresar($db, '11111111-1', '482915', false, meta()), 401);
    chequear($e1 && $e2 && $e1->getMessage() === $e2->getMessage(), 'mismo mensaje para RUT inexistente y PIN malo');
    $db->exec("UPDATE funcionarios SET activo=0 WHERE id=$id");
    lanza(fn() => cuenta_ingresar($db, $rut, '482915', false, meta()), 401);
});

prueba('Bloqueo por cuenta: 5 errores bloquean 15 min, luego 1 h, luego hasta que coordinación desbloquee; no por IP', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    cuenta_activar($db, $rut, $cod, '482915', false, meta());
    [$idB, $rutB, $codB] = conCodigo($db, 'Beto Dos');
    cuenta_activar($db, $rutB, $codB, '739104', false, meta());
    for ($i = 0; $i < 5; $i++) lanza(fn() => cuenta_ingresar($db, $rut, '000001', false, meta()), 401);
    lanza(fn() => cuenta_ingresar($db, $rut, '482915', false, meta()), 423, '15 minutos');
    chequear(cuenta_ingresar($db, $rutB, '739104', false, meta())['funcionario']['id'] === $idB, 'otra cuenta desde la misma IP sigue entrando');
    hora('2026-12-01 09:15:01');
    for ($i = 0; $i < 5; $i++) lanza(fn() => cuenta_ingresar($db, $rut, '000001', false, meta()), 401);
    lanza(fn() => cuenta_ingresar($db, $rut, '482915', false, meta()), 423, '1 hora');
    hora('2026-12-01 10:15:02');
    for ($i = 0; $i < 5; $i++) lanza(fn() => cuenta_ingresar($db, $rut, '000001', false, meta()), 401);
    hora('2026-12-03 10:00:00');
    lanza(fn() => cuenta_ingresar($db, $rut, '482915', false, meta()), 423, 'coordinación');
    cuenta_desbloquear($db, $id, $idB);
    chequear(cuenta_ingresar($db, $rut, '482915', false, meta())['funcionario']['id'] === $id, 'desbloqueada por coordinación, entra');
    horaNormal();
});

prueba('Sesión personal: dura 180 días desde el último uso y se renueva con el uso', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    $s = cuenta_activar($db, $rut, $cod, '482915', false, meta());
    hora('2027-05-29 08:00:00');                      // 179 días después
    chequear(sesion_validar($db, $s['token']) !== null, 'sigue válida a los 179 días');
    hora('2027-11-24 08:00:00');                      // 179 días después del último uso
    chequear(sesion_validar($db, $s['token']) !== null, 'se renovó con el uso anterior');
    hora('2028-05-23 08:00:00');                      // 181 días sin uso
    chequear(sesion_validar($db, $s['token']) === null, 'vence tras 180 días sin uso');
    horaNormal();
});

prueba('Sesión en computador compartido: vence a los 30 minutos sin uso', function () {
    $db = nuevaDb();
    [, $rut, $cod] = conCodigo($db);
    $s = cuenta_activar($db, $rut, $cod, '482915', true, meta());
    chequear($s['compartido'] === true, 'marcada como compartida');
    hora('2026-12-01 09:29:00');
    chequear(sesion_validar($db, $s['token']) !== null, 'válida a los 29 minutos');
    hora('2026-12-01 09:58:30');                      // 29 min 30 s después del último uso (09:29)
    chequear(sesion_validar($db, $s['token']) !== null, 'el uso la renovó');
    hora('2026-12-01 10:30:00');
    chequear(sesion_validar($db, $s['token']) === null, 'vence tras 30 minutos sin uso');
    horaNormal();
});

prueba('Sesiones: se listan, se cierran una a una o todas; un código nuevo invalida el PIN y cierra las sesiones', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    $s1 = cuenta_activar($db, $rut, $cod, '482915', false, meta());
    $s2 = cuenta_ingresar($db, $rut, '482915', true, meta());
    $l = sesiones_listar($db, $id);
    chequear(count($l) === 2 && !array_key_exists('token_hash', $l[0]), 'lista 2 sesiones sin exponer el token');
    sesion_revocar($db, (int)$l[0]['id'], $id);
    chequear(count(sesiones_listar($db, $id)) === 1, 'cierra una');
    $coord = (int)$db->query("SELECT id FROM funcionarios WHERE rol='coordinacion'")->fetchColumn();
    $cod2 = cuenta_generar_codigo($db, $id, $coord);
    chequear(sesion_validar($db, $s1['token']) === null && sesion_validar($db, $s2['token']) === null, 'el código nuevo cierra todas las sesiones');
    lanza(fn() => cuenta_ingresar($db, $rut, '482915', false, meta()), 401);
    chequear(cuenta_activar($db, $rut, $cod2, '739104', false, meta())['funcionario']['id'] === $id, 'con el código nuevo crea otro PIN');
});

prueba('Cambiar PIN: exige el PIN actual; el nuevo debe ser válido', function () {
    $db = nuevaDb();
    [$id, $rut, $cod] = conCodigo($db);
    cuenta_activar($db, $rut, $cod, '482915', false, meta());
    lanza(fn() => cuenta_cambiar_pin($db, $id, '000001', '739104'), 403);
    lanza(fn() => cuenta_cambiar_pin($db, $id, '482915', '111111'), 422);
    cuenta_cambiar_pin($db, $id, '482915', '739104');
    chequear(cuenta_ingresar($db, $rut, '739104', false, meta())['funcionario']['id'] === $id, 'entra con el PIN nuevo');
});

prueba('Auditoría: activaciones, ingresos, fallos, bloqueos y códigos quedan registrados', function () {
    $db = nuevaDb();
    [, $rut, $cod] = conCodigo($db);
    cuenta_activar($db, $rut, $cod, '482915', false, meta());
    try { cuenta_ingresar($db, $rut, '000001', false, meta()); } catch (ApiError $e) { }
    $acc = array_column($db->query('SELECT accion FROM auditoria')->fetchAll(), 'accion');
    foreach (['codigo_generar', 'activar', 'ingreso_fallido'] as $a) chequear(in_array($a, $acc, true), "registra $a");
});

prueba('Herramienta de consola: crea el primer coordinador con su código de activación', function () {
    $app = appTemporal([]);
    $herr = dirname(TESTS_DIR) . '/herramientas/codigo-acceso.php';
    $rut = '15000003-' . rut_dv('15000003');
    $correr = function (array $args) use ($app, $herr): array {
        $p = proc_open(array_merge([PHP_BINARY, $herr], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['AUS_APP_DIR' => $app] + getenv());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    [$cod1, $out] = $correr([$rut]);
    chequear($cod1 !== 0 && str_contains($out, 'no está en la nómina'), 'sin la persona en la nómina, avisa: ' . trim($out));
    [$cod2, $out] = $correr([$rut, '--coordinacion', '--nombre=Coordinadora Prueba', '--jornada=diurno', '--equipo=H7', '--calidad=titular']);
    chequear($cod2 === 0 && preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', $out, $m) === 1, 'imprime el código: ' . trim($out));
    $db = db_open($app . '/datos.sqlite');
    chequear($db->query("SELECT rol FROM funcionarios WHERE rut='$rut'")->fetchColumn() === 'coordinacion', 'queda con rol coordinación');
    Reloj::$fijo = null;                              // la herramienta usó el reloj real
    $s = cuenta_activar($db, $rut, $m[0] ?? '', '482915', false, meta());
    horaNormal();
    chequear(($s['funcionario']['rol'] ?? '') === 'coordinacion', 'con ese código se activa la cuenta');
    $db = null;
});
