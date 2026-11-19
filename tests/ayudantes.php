<?php
declare(strict_types=1);
/**
 * Pruebas de las reglas de negocio. Sin dependencias:  php tests/run.php
 * Hoy simulado: martes 2026-12-01. Feriado de prueba: martes 2026-12-08.
 */
define('APP_DIR', dirname(__DIR__));
define('TESTS_DIR', __DIR__);
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

/** Carga un turno en el calendario de turnos (para tests de turnantes). */
function turno(PDO $db, string $fecha, string $tipo, string $unidad, int $titular): int
{
    return h_coord_turno_guardar($db, [], ['fecha' => $fecha, 'tipo' => $tipo, 'unidad_id' => eid($db, $unidad), 'titular_id' => $titular])['id'];
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

/** Una petición suelta, sin sesión. Devuelve el cuerpo de la respuesta tal cual. */
function pedirApi(string $appDir, string $metodo, string $ruta): string
{
    return (new ClienteApi($appDir))->pedir($metodo, $ruta)['raw'];
}

/**
 * Cliente HTTP para pruebas: cada petición corre api.php en un proceso aparte, como en el servidor,
 * y el cliente conserva las cookies de sesión y el token CSRF entre peticiones (como un navegador).
 */
final class ClienteApi
{
    public array $cookies = [];
    public ?string $csrf = null;

    public function __construct(public string $appDir, public string $ip = '10.0.0.1') {}

    /** @return array{raw:string, json:mixed, error:?string} */
    public function pedir(string $metodo, string $ruta, ?array $cuerpo = null, array $query = []): array
    {
        $extra = ['cookies' => $this->cookies, 'ip' => $this->ip, 'query' => $query, 'cabeceras' => []];
        if ($cuerpo !== null) $extra['cuerpo'] = $cuerpo;
        if ($this->csrf !== null) $extra['cabeceras']['X-CSRF-Token'] = $this->csrf;
        $arch = tempnam(sys_get_temp_dir(), 'apiq');
        file_put_contents($arch, json_encode($extra));
        $p = proc_open([PHP_BINARY, TESTS_DIR . '/api_sub.php', $this->appDir, $metodo, $ruta, '@' . $arch],
                       [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $raw = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        proc_close($p);
        @unlink($arch);
        if (preg_match('/^COOKIES:(.*)$/m', $err, $m)) $this->cookies = json_decode($m[1], true) ?: [];
        $json = json_decode($raw, true);
        if (is_array($json) && array_key_exists('csrf', $json) && $json['csrf'] !== null) $this->csrf = $json['csrf'];
        $resto = trim((string)preg_replace('/^COOKIES:.*$/m', '', $err));
        return ['raw' => $raw, 'json' => $json, 'error' => $resto === '' ? null : $resto];
    }
}
