<?php
/**
 * Ejecuta UNA petición contra public/api.php en un proceso aparte (para pruebas).
 * Uso: php api_sub.php <APP_DIR de prueba> <método> <ruta> [@<archivo con JSON {cuerpo, cabeceras, cookies, query, ip}>]
 * Escribe la respuesta en la salida estándar y, en la salida de error, una línea
 * «COOKIES:{json}» con las cookies de sesión vigentes al terminar (para encadenar peticiones).
 */
declare(strict_types=1);

[, $appDir, $metodo, $ruta] = $argv;
$arg = $argv[4] ?? '';
if (str_starts_with($arg, '@')) $arg = (string)file_get_contents(substr($arg, 1)); // JSON en archivo (evita problemas de comillas en Windows)
$extra = $arg !== '' ? (json_decode($arg, true) ?: []) : [];

define('APP_DIR', $appDir);
$_SERVER['REQUEST_METHOD'] = $metodo;
$_SERVER['REMOTE_ADDR'] = (string)($extra['ip'] ?? '10.0.0.1');
$_SERVER['HTTP_USER_AGENT'] = 'pruebas';
$_GET['r'] = $ruta;
foreach (($extra['query'] ?? []) as $k => $v) $_GET[$k] = $v;
foreach (($extra['cabeceras'] ?? []) as $k => $v) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
$_COOKIE = $extra['cookies'] ?? [];
if (array_key_exists('cuerpo', $extra)) define('AUS_PRUEBA_CUERPO', json_encode($extra['cuerpo']));
ini_set('session.save_path', $appDir);

register_shutdown_function(function () {
    // En CLI no hay cabeceras: lib/auth.php anota en $GLOBALS['__aus_cookies'] las cookies que habría enviado.
    $c = $_COOKIE;
    foreach (($GLOBALS['__aus_cookies'] ?? []) as $k => $v) { if ($v === '') unset($c[$k]); else $c[$k] = $v; }
    fwrite(STDERR, "\nCOOKIES:" . json_encode($c) . "\n");
});

require dirname(__DIR__) . '/public/api.php';
