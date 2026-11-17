<?php
declare(strict_types=1);

function sesion_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)((int)cfg('sesion_horas') * 3600));
    session_name('aus_coord');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => es_https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function coord_autenticado(): bool
{
    sesion_iniciar();
    if (cfg('coord_sin_clave')) { // modo abierto: la vista de coordinación no pide clave
        if (empty($_SESSION['coord'])) $_SESSION = ['coord' => true, 'csrf' => bin2hex(random_bytes(24))];
        $_SESSION['last'] = time();
        return true;
    }
    if (empty($_SESSION['coord'])) return false;
    if (time() - (int)($_SESSION['last'] ?? 0) > (int)cfg('sesion_horas') * 3600) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

/** Exige sesión y, en métodos que modifican, el token CSRF. */
function coord_requerir(string $metodo): void
{
    if (!coord_autenticado()) throw new ApiError('Tu sesión de coordinación expiró. Ingresa de nuevo.', 401);
    if ($metodo !== 'GET') {
        $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($tok === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $tok)) {
            throw new ApiError('Token de seguridad inválido. Recarga la página.', 403);
        }
    }
}

function h_coord_sesion(PDO $db, array $p, array $in): array
{
    $ok = coord_autenticado();
    return ['autenticado' => $ok, 'csrf' => $ok ? $_SESSION['csrf'] : null];
}

function h_coord_login(PDO $db, array $p, array $in): array
{
    sesion_iniciar();
    $clave = 'login:' . ip_cliente();
    $max = (int)cfg('login_max_intentos');
    $min = (int)cfg('login_bloqueo_min');
    if (rate_contar($db, $clave, $min * 60) >= $max) {
        throw new ApiError("Demasiados intentos fallidos. Espera $min minutos.", 429);
    }
    $hash = (string)cfg('clave_hash');
    if ($hash === '') throw new ApiError('La clave de coordinación no está configurada.', 503);
    if (!password_verify((string)($in['clave'] ?? ''), $hash)) {
        rate_registrar($db, $clave);
        auditar($db, 'login_fallido', null, '', ip_cliente());
        throw new ApiError('Clave incorrecta.', 401);
    }
    $db->prepare('DELETE FROM rate WHERE clave=?')->execute([$clave]);
    session_regenerate_id(true);
    $_SESSION = ['coord' => true, 'last' => time(), 'csrf' => bin2hex(random_bytes(24))];
    auditar($db, 'login', null, '', ip_cliente());
    return ['autenticado' => true, 'csrf' => $_SESSION['csrf']];
}

function h_coord_logout(PDO $db, array $p, array $in): array
{
    sesion_iniciar();
    $_SESSION = [];
    session_destroy();
    return ['autenticado' => false];
}
