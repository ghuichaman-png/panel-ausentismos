<?php
declare(strict_types=1);

if (!defined('APP_DIR')) define('APP_DIR', dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificar.php';
require_once __DIR__ . '/reglas.php';

function cfg_defaults(): array
{
    return [
        'titulo' => 'Ausentismos · Kinesiología HPM', 'logo' => '', 'db_path' => '', 'clave_hash' => '', 'coord_sin_clave' => false,
        'timezone' => 'America/Santiago', 'forzar_https' => true,
        'limite_envios_hora' => 10, 'login_max_intentos' => 5, 'login_bloqueo_min' => 15, 'sesion_horas' => 8,
        'fl_largo_dias_habiles' => 5, 'anticipacion_min_dias' => null, 'horizonte_max_dias' => null,
    ];
}

function cfg(?string $k = null, mixed $def = null): mixed
{
    static $c = null;
    if ($c === null) {
        $f = APP_DIR . '/config.php';
        $usr = is_file($f) ? require $f : [];
        $c = array_merge(cfg_defaults(), is_array($usr) ? $usr : []);
        date_default_timezone_set((string)$c['timezone']);
    }
    return $k === null ? $c : ($c[$k] ?? $def);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = (string)cfg('db_path');
        if ($path === '' || !is_file($path)) {
            throw new ApiError('El sistema aún no está instalado (falta la base de datos). Ejecuta install.php.', 503);
        }
        $pdo = db_open($path);
    }
    return $pdo;
}

function es_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function ip_cliente(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }

function cabeceras_seguridad(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new ApiError('La solicitud no tiene un formato válido.', 400);
    return $d;
}
