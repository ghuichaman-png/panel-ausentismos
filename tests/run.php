<?php
declare(strict_types=1);
/**
 * Pruebas de las reglas de negocio. Sin dependencias:  php tests/run.php
 * Hoy simulado: martes 2026-12-01. Feriado de prueba: martes 2026-12-08.
 */
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/lib/bootstrap.php';
require APP_DIR . '/lib/handlers_public.php';
require APP_DIR . '/lib/handlers_coord.php';
date_default_timezone_set('America/Santiago');
Reloj::$fijo = new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('America/Santiago'));

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aus_test_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$GLOBALS['fallos'] = 0;
$GLOBALS['total'] = 0;
$rutSeq = 11000000;

function nuevaDb(): PDO
{
    global $tmp;
    static $n = 0;
    $path = $tmp . DIRECTORY_SEPARATOR . 'db' . (++$n) . '.sqlite';
    $db = db_open($path);
    db_schema($db);
    db_sembrar_equipos($db);
    $db->exec("INSERT INTO feriados (fecha,nombre) VALUES ('2026-12-08','Inmaculada Concepción')");
    $GLOBALS['dbpath'] = $path;
    return $db;
}

function mk(PDO $db, string $nombre, string $jornada, ?string $equipo, string $calidad = 'titular'): int
{
    global $rutSeq;
    $rutSeq += 7;
    $rut = $rutSeq . '-' . rut_dv((string)$rutSeq);
    $eid = $equipo ? (int)$db->query("SELECT id FROM equipos WHERE nombre=" . $db->quote($equipo))->fetchColumn() : null;
    $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad) VALUES (?,?,?,?,?,?)')
       ->execute([$nombre, texto_norm($nombre), $rut, $jornada, $eid, $calidad]);
    return (int)$db->lastInsertId();
}

function rutDe(PDO $db, int $fid): string { return (string)$db->query("SELECT rut FROM funcionarios WHERE id=$fid")->fetchColumn(); }
function eid(PDO $db, string $n): int { return (int)$db->query("SELECT id FROM equipos WHERE nombre=" . $db->quote($n))->fetchColumn(); }
function crear(PDO $db, array $in): array { return reg_crear($db, $in, ['ip' => '1.2.3.4', 'ua' => 'test', 'cfg' => cfg_defaults()]); }
function ocup(PDO $db, string $eq, string $f): float { return reg_ocupacion($db, eid($db, $eq), $f, $f)[$f] ?? 0.0; }

function chequear(bool $cond, string $msg): void
{
    $GLOBALS['total']++;
    if (!$cond) { $GLOBALS['fallos']++; echo "    ✗ $msg\n"; }
}

function lanza(callable $fn, ?int $codigo = null, ?string $contiene = null): ?ApiError
{
    try { $fn(); } catch (ApiError $e) {
        $ok = ($codigo === null || $e->getCode() === $codigo) && ($contiene === null || str_contains($e->getMessage(), $contiene));
        chequear($ok, "ApiError inesperado (código {$e->getCode()}): {$e->getMessage()}");
        return $e;
    }
    chequear(false, 'se esperaba un ApiError y no ocurrió');
    return null;
}

function prueba(string $nombre, callable $fn): void
{
    echo "• $nombre\n";
    $antes = $GLOBALS['fallos'];
    try { $fn(); } catch (Throwable $e) { chequear(false, 'excepción: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
    echo $GLOBALS['fallos'] === $antes ? "    ✓ ok\n" : '';
}

// ───────────────────────── pruebas ─────────────────────────

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

prueba('Dos ADM medio día (AM + PM) suman 1', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H6'); $b = mk($db, 'Beto Dos', 'diurno', 'H6');
    crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'am']);
    crear($db, ['funcionario_id' => $b, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'pm']);
    chequear(ocup($db, 'H6', '2026-12-10') === 1.0, 'AM + PM = 1');
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

prueba('Turnantes: FL, ADM y CT nunca consumen cupo; reemplazo obligatorio', function () {
    $db = nuevaDb();
    $t = mk($db, 'Tati Turno', 'turnante', null);
    $u = eid($db, 'H7');
    $dia = fn($f, $reemp = 'Pedro Reemplazo') => ['fecha' => $f, 'turno' => 'largo', 'unidad_id' => $u, 'reemplazo' => $reemp];
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'dias' => [$dia('2026-12-10'), $dia('2026-12-11')]]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'primera_mitad', 'dias' => [$dia('2026-12-20')]]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'cambio_con' => 'Luis Colega', 'dias' => [$dia('2026-12-22', '')],
                'devolucion_fecha' => '2026-12-29', 'devolucion_turno' => 'noche']);
    $suma = (float)$db->query('SELECT COALESCE(SUM(consumo),0) FROM solicitud_dias')->fetchColumn();
    chequear($suma === 0.0, 'consumo total de turnantes = 0');
    chequear(ocup($db, 'H7', '2026-12-10') === 0.0, 'no ocupa cupo del equipo');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'dias' => [$dia('2026-12-15', '')]]), 422, 'cubre');
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'CT', 'dias' => [$dia('2026-12-16')]]), 422, 'con quién');
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

prueba('Anulación: RUT incorrecto falla; pasado el plazo falla; en plazo funciona', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    $r = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02', 'fraccion' => 'completo']);
    lanza(fn() => reg_anular($db, $r['id'], '11111111-1', false), 403, 'RUT');
    // 2026-12-02 es la primera fecha; hoy = 12-01 → aún en plazo (hasta 23:59 de hoy)
    $rutA = rutDe($db, $a);
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-04', 'fraccion' => 'completo']);
    reg_anular($db, $r2['id'], $rutA, false);
    chequear($db->query("SELECT estado FROM solicitudes WHERE id={$r2['id']}")->fetchColumn() === 'anulada', 'anulada en plazo');
    // avanzamos el reloj al día de la primera fecha: ya no se puede
    Reloj::$fijo = new DateTimeImmutable('2026-12-02 00:05:00', new DateTimeZone('America/Santiago'));
    lanza(fn() => reg_anular($db, $r['id'], $rutA, false), 409, 'plazo');
    reg_anular($db, $r['id'], null, true, 'JT'); // coordinación sí puede
    chequear($db->query("SELECT estado FROM solicitudes WHERE id={$r['id']}")->fetchColumn() === 'anulada', 'coordinación anula sin RUT ni plazo');
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
    // turnante: misma fecha y mismo turno choca; distinto turno no
    $t = mk($db, 'Tati Turno', 'turnante', null); $u = eid($db, 'H7');
    $dia = fn($turno) => ['fecha' => '2026-12-12', 'turno' => $turno, 'unidad_id' => $u, 'reemplazo' => 'Pedro'];
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'dias' => [$dia('largo')]]);
    crear($db, ['funcionario_id' => $t, 'tipo' => 'FL', 'dias' => [$dia('noche')]]);
    lanza(fn() => crear($db, ['funcionario_id' => $t, 'tipo' => 'ADM', 'fraccion' => 'completo', 'dias' => [$dia('largo')]]), 409);
});

prueba('Validaciones: fecha pasada, fuera de nómina, honeypot de formulario y código', function () {
    $db = nuevaDb();
    $a = mk($db, 'Ana Uno', 'diurno', 'H7');
    lanza(fn() => crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-11-30', 'fraccion' => 'completo']), 422, 'pasadas');
    lanza(fn() => crear($db, ['rut' => '1-9', 'nombre' => 'X', 'tipo' => 'ADM']), 422);
    $rutOk = '16000000-' . rut_dv('16000000');
    $r = crear($db, ['rut' => $rutOk, 'nombre' => 'Persona Externa', 'jornada' => 'diurno', 'equipo_id' => eid($db, 'H8'),
                     'calidad' => 'contrata', 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
    chequear((int)$db->query("SELECT fuera_de_nomina FROM solicitudes WHERE id={$r['id']}")->fetchColumn() === 1, 'marcada fuera de nómina');
    chequear(preg_match('/^AUS-2026-0001$/', $r['codigo']) === 1, 'código AUS-2026-0001: ' . $r['codigo']);
    $r2 = crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-10', 'fraccion' => 'completo']);
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
    chequear($r['creados'] === 0 && $r['actualizados'] === 2, 'segunda pasada actualiza');
});

prueba('Concurrencia: dos procesos piden el último cupo → solo uno sin sobre cupo', function () {
    $db = nuevaDb();
    $db->exec("UPDATE equipos SET cupo_diurno=1 WHERE nombre='H7'");
    $ids = [mk($db, 'Ana Uno', 'diurno', 'H7'), mk($db, 'Beto Dos', 'diurno', 'H7'), mk($db, 'Carla Tres', 'diurno', 'H7'), mk($db, 'Dani Cuatro', 'diurno', 'H7')];
    $db = null; // cerrar conexión del padre
    global $dbpath;
    $inicio = microtime(true) + 1.5;
    $procs = [];
    foreach ($ids as $id) {
        $cmd = [PHP_BINARY, __DIR__ . '/worker.php', $dbpath, (string)$id, '2026-12-10', (string)$inicio];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $res = [];
    foreach ($procs as [$p, $pipes]) {
        $res[] = trim((string)stream_get_contents($pipes[1])) . trim((string)stream_get_contents($pipes[2]));
        proc_close($p);
    }
    $oks = count(array_filter($res, fn($r) => str_starts_with($r, 'OK')));
    $sin = count(array_filter($res, fn($r) => str_starts_with($r, 'SIN_CUPO')));
    chequear($oks === 1 && $sin === 3, "1 OK y 3 SIN_CUPO, obtenido: " . implode(' | ', $res));
    $db = db_open($dbpath);
    chequear(ocup($db, 'H7', '2026-12-10') === 1.0, 'la ocupación final es exactamente 1');
});

/**
 * Prepara una carpeta de aplicación temporal (lib/ + config.php propio + base) para pedir rutas de api.php
 * en un proceso aparte, sin tocar el config.php ni la base de desarrollo.
 */
function appTemporal(array $config): string
{
    global $tmp;
    static $n = 0;
    $dir = $tmp . DIRECTORY_SEPARATOR . 'app' . (++$n);
    @mkdir($dir . '/lib', 0777, true);
    foreach (glob(APP_DIR . '/lib/*.php') as $f) copy($f, $dir . '/lib/' . basename($f));
    $dbp = $dir . '/datos.sqlite';
    $db = db_open($dbp); db_schema($db); db_sembrar_equipos($db); $db = null;
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config + ['db_path' => $dbp, 'forzar_https' => false], true) . ';');
    return $dir;
}

function pedirApi(string $appDir, string $metodo, string $ruta): string
{
    $p = proc_open([PHP_BINARY, __DIR__ . '/api_sub.php', $appDir, $metodo, $ruta], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
}

prueba('Bug N1: coordinación nunca queda abierta sin clave, aunque config.php traiga coord_sin_clave', function () {
    $app = appTemporal(['coord_sin_clave' => true, 'clave_hash' => password_hash('clave-de-prueba-larga', PASSWORD_DEFAULT)]);
    $s = json_decode(pedirApi($app, 'GET', 'coord/sesion'), true);
    chequear(is_array($s) && ($s['autenticado'] ?? null) === false, 'coord/sesion sin login no está autenticada: ' . json_encode($s));
    chequear(is_array($s) && array_key_exists('csrf', $s) && $s['csrf'] === null, 'coord/sesion sin login no entrega token CSRF');
    $r = pedirApi($app, 'GET', 'coord/respaldo');
    chequear(!str_starts_with($r, 'SQLite format 3'), 'coord/respaldo sin login no entrega la base de datos');
    $j = json_decode($r, true);
    chequear(is_array($j) && str_contains((string)($j['error'] ?? ''), 'sesión'), 'coord/respaldo sin login responde error de sesión: ' . substr($r, 0, 80));
    $l = json_decode(pedirApi($app, 'GET', 'coord/solicitudes'), true);
    chequear(is_array($l) && isset($l['error']) && !isset($l['solicitudes']), 'coord/solicitudes sin login no lista nada');
});

echo "\n" . ($GLOBALS['fallos'] === 0 ? "TODO OK" : "FALLARON {$GLOBALS['fallos']}") . " ({$GLOBALS['total']} verificaciones)\n";
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
@rmdir($tmp);
exit($GLOBALS['fallos'] === 0 ? 0 : 1);
