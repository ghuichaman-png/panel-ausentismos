<?php
declare(strict_types=1);

/**
 * Sesión por usuario en la API (SPEC-v2 §5). Reemplaza a la clave compartida de coordinación.
 * La cookie «aus_s» lleva un token opaco; en la base solo está su hash (lib/cuentas.php).
 */

const COOKIE_SESION = 'aus_s';

/** Sesión de la petición en curso (o null). Se calcula una sola vez. */
function sesion_actual(): ?array
{
    static $hecho = false, $s = null;
    if (!$hecho) {
        $hecho = true;
        $s = sesion_validar(db(), $_COOKIE[COOKIE_SESION] ?? null);
    }
    return $s;
}

/** Id de la persona con sesión (las rutas protegidas ya la exigieron). */
function yo(): int
{
    $s = sesion_actual();
    if ($s === null) throw new ApiError('Ingresa con tu RUT y PIN.', 401, ['codigo' => 'sin_sesion']);
    return $s['funcionario_id'];
}

function es_coordinacion(): bool { return (sesion_actual()['rol'] ?? '') === 'coordinacion'; }

/** Exige sesión; en rutas de coordinación, el rol; en escrituras, el token CSRF de esa sesión. */
function acceso_requerir(string $nivel, string $metodo): void
{
    if ($nivel === 'abierta') return;
    $s = sesion_actual();
    if ($s === null) throw new ApiError('Tu sesión terminó o no has ingresado. Ingresa con tu RUT y PIN.', 401, ['codigo' => 'sin_sesion']);
    if ($nivel === 'coord' && $s['rol'] !== 'coordinacion') throw new ApiError('Esta sección es solo para coordinación.', 403, ['codigo' => 'sin_permiso']);
    if ($metodo !== 'GET') {
        $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($tok === '' || !hash_equals((string)$s['csrf'], $tok)) throw new ApiError('Token de seguridad inválido. Recarga la página.', 403, ['codigo' => 'csrf']);
    }
}

/** Fija o borra la cookie de sesión (en pruebas por consola, la anota en $GLOBALS['__aus_cookies']). */
function cookie_sesion(?string $token, bool $compartido = false, ?string $vence = null): void
{
    $GLOBALS['__aus_cookies'][COOKIE_SESION] = $token ?? '';
    if (PHP_SAPI === 'cli') return;
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    $expira = $token === null ? 1 : ($compartido ? 0 : (int)strtotime((string)$vence));
    setcookie(COOKIE_SESION, $token ?? '', ['expires' => $expira, 'path' => $path, 'secure' => es_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Límite alto por IP en ingreso/activación: solo frena ataques masivos (todo el hospital comparte una IP). */
function limite_ip_auth(PDO $db): void
{
    $max = (int)cfg('limite_ip_auth_hora');
    if ($max > 0 && rate_contar($db, 'auth:' . ip_cliente(), 3600) >= $max) {
        throw new ApiError('Demasiados intentos desde esta red. Espera un rato e intenta de nuevo.', 429);
    }
    rate_registrar($db, 'auth:' . ip_cliente());
}

function respuesta_sesion(array $s): array
{
    cookie_sesion($s['token'], $s['compartido'], $s['vence']);
    return ['autenticado' => true, 'csrf' => $s['csrf'], 'compartido' => $s['compartido'], 'persona' => $s['funcionario']];
}

function meta_peticion(): array { return ['ip' => ip_cliente(), 'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')]; }

// ───────────────────────── Rutas auth/* ─────────────────────────

function h_auth_sesion(PDO $db, array $p, array $in): array
{
    $s = sesion_actual();
    if ($s === null) return ['autenticado' => false, 'csrf' => null];
    return ['autenticado' => true, 'csrf' => $s['csrf'], 'compartido' => (bool)$s['compartido'], 'persona' => persona_publica_propia($db, $s['funcionario_id'])];
}

function h_auth_activar(PDO $db, array $p, array $in): array
{
    limite_ip_auth($db);
    return respuesta_sesion(cuenta_activar($db, (string)($in['rut'] ?? ''), (string)($in['codigo'] ?? ''), (string)($in['pin'] ?? ''),
                                           !empty($in['compartido']), meta_peticion()));
}

function h_auth_ingresar(PDO $db, array $p, array $in): array
{
    limite_ip_auth($db);
    return respuesta_sesion(cuenta_ingresar($db, (string)($in['rut'] ?? ''), (string)($in['pin'] ?? ''), !empty($in['compartido']), meta_peticion()));
}

function h_auth_salir(PDO $db, array $p, array $in): array
{
    $s = sesion_actual();
    if ($s) sesion_revocar($db, $s['id'], $s['funcionario_id']);
    cookie_sesion(null);
    return ['autenticado' => false];
}

function h_auth_pin(PDO $db, array $p, array $in): array
{
    cuenta_cambiar_pin($db, yo(), (string)($in['actual'] ?? ''), (string)($in['nuevo'] ?? ''), ip_cliente());
    return ['ok' => true];
}

function h_auth_sesiones(PDO $db, array $p, array $in): array
{
    $actual = sesion_actual()['id'];
    return ['sesiones' => array_map(fn($x) => $x + ['actual' => $x['id'] === $actual], sesiones_listar($db, yo()))];
}

function h_auth_sesion_cerrar(PDO $db, array $p, array $in): array
{
    sesion_revocar($db, (int)$p[1], yo());
    return ['ok' => true];
}

function h_auth_sesiones_cerrar_otras(PDO $db, array $p, array $in): array
{
    return ['cerradas' => sesiones_revocar_todas($db, yo(), sesion_actual()['id'])];
}

// ───────────────────────── Cuentas desde coordinación ─────────────────────────

function h_coord_cuenta_codigo(PDO $db, array $p, array $in): array
{
    return ['codigo' => cuenta_generar_codigo($db, (int)$p[1], yo(), ip_cliente()), 'vence_dias' => CODIGO_DIAS];
}

function h_coord_cuenta_desbloquear(PDO $db, array $p, array $in): array
{
    cuenta_desbloquear($db, (int)$p[1], yo(), ip_cliente());
    return ['ok' => true];
}

function h_coord_cuenta_cerrar_sesiones(PDO $db, array $p, array $in): array
{
    $n = sesiones_revocar_todas($db, (int)$p[1]);
    cuenta_auditar($db, 'cerrar_sesiones', yo(), "funcionario #{$p[1]}: $n", ip_cliente());
    return ['cerradas' => $n];
}

function h_coord_cuenta_rol(PDO $db, array $p, array $in): array
{
    $rol = $in['rol'] ?? '';
    if (!es_en($rol, ['funcionario', 'coordinacion'])) throw new ApiError('Rol inválido.', 422);
    $id = (int)$p[1];
    return tx($db, function () use ($db, $id, $rol) {
        if ($rol === 'funcionario') {
            $n = (int)$db->query("SELECT COUNT(*) FROM funcionarios WHERE rol='coordinacion' AND activo=1 AND id<>$id")->fetchColumn();
            if ($n === 0) throw new ApiError('Debe quedar al menos una persona con rol de coordinación.', 409);
        }
        $st = $db->prepare('UPDATE funcionarios SET rol=? WHERE id=?');
        $st->execute([$rol, $id]);
        if ($st->rowCount() === 0) throw new ApiError('Esa persona no existe.', 404);
        cuenta_auditar($db, 'rol_cambiar', yo(), "funcionario #$id → $rol", ip_cliente());
        return ['rol' => $rol];
    });
}
