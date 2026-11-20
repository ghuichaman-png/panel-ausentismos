<?php
declare(strict_types=1);

/**
 * Cuentas y sesiones (SPEC-v2 §5, opción A): RUT + PIN de 6 dígitos, código de activación o recuperación
 * entregado por coordinación, bloqueo por cuenta (no por IP) y sesiones por dispositivo.
 * Todas las horas salen de Reloj (sustituible en pruebas).
 */

const CODIGO_ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sin 0/O, 1/I/L
const CODIGO_DIAS = 7;
const SESION_DIAS_PERSONAL = 180;
const SESION_MIN_COMPARTIDO = 30;
const INTENTOS_MAX = 5;
const MSG_CREDENCIALES = 'RUT o PIN incorrectos. Si es tu primera vez, usa «Activar con código».';

function ts(DateTimeImmutable $d): string { return $d->format('Y-m-d H:i:s'); }

function cuenta_auditar(PDO $db, string $accion, ?int $actor, string $detalle = '', string $ip = ''): void
{
    $db->prepare('INSERT INTO auditoria (fecha,accion,solicitud_id,detalle,ip,funcionario_id) VALUES (?,?,?,?,?,?)')
       ->execute([Reloj::stamp(), $accion, null, mb_substr($detalle, 0, 2000), $ip, $actor]);
}

function codigo_normalizar(string $c): string { return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($c)); }

/** Genera un código nuevo para la persona: invalida su PIN, cierra sus sesiones y la desbloquea. Devuelve «XXXX-XXXX». */
function cuenta_generar_codigo(PDO $db, int $funcionarioId, ?int $porId, string $ip = ''): string
{
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= CODIGO_ALFABETO[random_int(0, strlen(CODIGO_ALFABETO) - 1)];
    $vence = ts(Reloj::ahora()->modify('+' . CODIGO_DIAS . ' days'));
    tx($db, function () use ($db, $funcionarioId, $porId, $c, $vence, $ip) {
        $st = $db->prepare('SELECT nombre FROM funcionarios WHERE id=? AND activo=1');
        $st->execute([$funcionarioId]);
        $nombre = $st->fetchColumn();
        if ($nombre === false) throw new ApiError('Esa persona no existe o está desactivada.', 404);
        $db->prepare('INSERT INTO credenciales (funcionario_id, pin_hash, codigo_hash, codigo_vence, intentos_fallidos, bloqueos, bloqueado_hasta, actualizado_en)
                      VALUES (?, NULL, ?, ?, 0, 0, NULL, ?)
                      ON CONFLICT(funcionario_id) DO UPDATE SET pin_hash=NULL, codigo_hash=excluded.codigo_hash, codigo_vence=excluded.codigo_vence,
                        intentos_fallidos=0, bloqueos=0, bloqueado_hasta=NULL, actualizado_en=excluded.actualizado_en')
           ->execute([$funcionarioId, password_hash($c, PASSWORD_DEFAULT), $vence, Reloj::stamp()]);
        $db->prepare('UPDATE sesiones SET revocada=1 WHERE funcionario_id=? AND revocada=0')->execute([$funcionarioId]);
        cuenta_auditar($db, 'codigo_generar', $porId, "para $nombre (#$funcionarioId), vence $vence", $ip);
    });
    return substr($c, 0, 4) . '-' . substr($c, 4);
}

/** PIN de 6 dígitos que no sea trivial ni salga del RUT. */
function pin_validar(string $pin, string $rut): void
{
    if (!preg_match('/^\d{6}$/', $pin)) throw new ApiError('El PIN debe tener exactamente 6 números.', 422);
    $d = array_map('intval', str_split($pin));
    $difs = array_unique(array_map(fn($i) => $d[$i + 1] - $d[$i], range(0, 4)));
    if (count(array_unique($d)) === 1 || $difs === [1] || $difs === [-1]
        || $pin === str_repeat(substr($pin, 0, 2), 3) || $pin === str_repeat(substr($pin, 0, 3), 2)) {
        throw new ApiError('Ese PIN es muy fácil de adivinar. Evita números repetidos o seguidos.', 422);
    }
    $cuerpo = (string)preg_replace('/\D/', '', explode('-', $rut)[0] ?? '');
    if ($cuerpo !== '' && str_contains($cuerpo, $pin)) throw new ApiError('No uses números de tu RUT como PIN.', 422);
}

/** Busca la cuenta por RUT (persona activa). null si no existe. */
function cuenta_por_rut(PDO $db, string $rut): ?array
{
    $r = rut_normalizar($rut);
    if ($r === null) return null;
    $st = $db->prepare('SELECT f.id, f.nombre, f.rut, f.rol, c.pin_hash, c.codigo_hash, c.codigo_vence, c.intentos_fallidos, c.bloqueos, c.bloqueado_hasta
        FROM funcionarios f LEFT JOIN credenciales c ON c.funcionario_id = f.id WHERE f.rut = ? AND f.activo = 1');
    $st->execute([$r]);
    return $st->fetch() ?: null;
}

/** Lanza 423 si la cuenta está bloqueada. */
function cuenta_revisar_bloqueo(array $c): void
{
    $b = $c['bloqueado_hasta'] ?? null;
    if ($b === null) return;
    if ($b === 'manual') throw new ApiError('Tu cuenta quedó bloqueada por muchos intentos fallidos. Pide a coordinación que la desbloquee.', 423);
    if (Reloj::stamp() < $b) {
        $min = (int)ceil(((new DateTimeImmutable($b))->getTimestamp() - Reloj::ahora()->getTimestamp()) / 60);
        $txt = (int)$c['bloqueos'] >= 2 ? '1 hora' : '15 minutos';
        throw new ApiError("Demasiados intentos fallidos. Tu cuenta está bloqueada por $txt (quedan $min min) o hasta que coordinación la desbloquee.", 423);
    }
}

/** Registra un intento fallido y bloquea al llegar al máximo: 15 min, luego 1 h, luego manual. */
function cuenta_registrar_fallo(PDO $db, int $id, string $ip): void
{
    tx($db, function () use ($db, $id, $ip) {
        $db->prepare('INSERT OR IGNORE INTO credenciales (funcionario_id) VALUES (?)')->execute([$id]);
        $c = $db->query('SELECT intentos_fallidos, bloqueos FROM credenciales WHERE funcionario_id=' . $id)->fetch();
        $n = (int)$c['intentos_fallidos'] + 1;
        if ($n < INTENTOS_MAX) {
            $db->prepare('UPDATE credenciales SET intentos_fallidos=? WHERE funcionario_id=?')->execute([$n, $id]);
        } else {
            $bloqueos = (int)$c['bloqueos'] + 1;
            $hasta = match ($bloqueos) {
                1 => ts(Reloj::ahora()->modify('+15 minutes')),
                2 => ts(Reloj::ahora()->modify('+60 minutes')),
                default => 'manual',
            };
            $db->prepare('UPDATE credenciales SET intentos_fallidos=0, bloqueos=?, bloqueado_hasta=? WHERE funcionario_id=?')->execute([$bloqueos, $hasta, $id]);
            cuenta_auditar($db, 'cuenta_bloqueada', $id, "bloqueo $bloqueos hasta $hasta", $ip);
        }
        cuenta_auditar($db, 'ingreso_fallido', $id, '', $ip);
    });
}

function cuenta_exito(PDO $db, int $id): void
{
    $db->prepare('UPDATE credenciales SET intentos_fallidos=0, bloqueos=0, bloqueado_hasta=NULL WHERE funcionario_id=?')->execute([$id]);
}

/** Primera vez o recuperación: RUT + código → crea el PIN e inicia sesión. */
function cuenta_activar(PDO $db, string $rut, string $codigo, string $pin, bool $compartido, array $meta): array
{
    $ip = (string)($meta['ip'] ?? '');
    $c = cuenta_por_rut($db, $rut);
    if ($c === null || $c['codigo_hash'] === null) throw new ApiError('RUT o código incorrectos. Pide un código nuevo a coordinación si no lo tienes.', 401);
    cuenta_revisar_bloqueo($c);
    $cod = codigo_normalizar($codigo);
    if (!password_verify($cod, (string)$c['codigo_hash'])) {
        cuenta_registrar_fallo($db, (int)$c['id'], $ip);
        throw new ApiError('RUT o código incorrectos. Revisa el código que te dio coordinación.', 401);
    }
    if (Reloj::stamp() > (string)$c['codigo_vence']) throw new ApiError('Ese código ya venció (duran 7 días). Pide uno nuevo a coordinación.', 401);
    pin_validar($pin, (string)$c['rut']);
    $id = (int)$c['id'];
    tx($db, function () use ($db, $id, $pin, $ip) {
        $db->prepare('UPDATE credenciales SET pin_hash=?, codigo_hash=NULL, codigo_vence=NULL, intentos_fallidos=0, bloqueos=0, bloqueado_hasta=NULL, actualizado_en=? WHERE funcionario_id=?')
           ->execute([password_hash($pin, PASSWORD_DEFAULT), Reloj::stamp(), $id]);
        cuenta_auditar($db, 'activar', $id, '', $ip);
    });
    return sesion_crear($db, $id, $compartido, $meta);
}

/** Ingreso normal: RUT + PIN. */
function cuenta_ingresar(PDO $db, string $rut, string $pin, bool $compartido, array $meta): array
{
    $ip = (string)($meta['ip'] ?? '');
    $c = cuenta_por_rut($db, $rut);
    if ($c === null) throw new ApiError(MSG_CREDENCIALES, 401);
    cuenta_revisar_bloqueo($c);
    if ($c['pin_hash'] === null || !password_verify($pin, (string)$c['pin_hash'])) {
        if ($c['pin_hash'] !== null) cuenta_registrar_fallo($db, (int)$c['id'], $ip);
        throw new ApiError(MSG_CREDENCIALES, 401);
    }
    cuenta_exito($db, (int)$c['id']);
    cuenta_auditar($db, 'ingreso', (int)$c['id'], $compartido ? 'computador compartido' : '', $ip);
    return sesion_crear($db, (int)$c['id'], $compartido, $meta);
}

function cuenta_cambiar_pin(PDO $db, int $id, string $actual, string $nuevo, string $ip = ''): void
{
    $st = $db->prepare('SELECT c.pin_hash, f.rut FROM credenciales c JOIN funcionarios f ON f.id=c.funcionario_id WHERE c.funcionario_id=?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c || $c['pin_hash'] === null || !password_verify($actual, (string)$c['pin_hash'])) throw new ApiError('El PIN actual no es correcto.', 403);
    pin_validar($nuevo, (string)$c['rut']);
    $db->prepare('UPDATE credenciales SET pin_hash=?, actualizado_en=? WHERE funcionario_id=?')->execute([password_hash($nuevo, PASSWORD_DEFAULT), Reloj::stamp(), $id]);
    cuenta_auditar($db, 'cambiar_pin', $id, '', $ip);
}

function cuenta_desbloquear(PDO $db, int $id, int $porId, string $ip = ''): void
{
    $db->prepare('UPDATE credenciales SET intentos_fallidos=0, bloqueos=0, bloqueado_hasta=NULL WHERE funcionario_id=?')->execute([$id]);
    cuenta_auditar($db, 'desbloquear', $porId, "funcionario #$id", $ip);
}

// ───────────────────────── Sesiones ─────────────────────────

function sesion_vence(bool $compartido): string
{
    return ts(Reloj::ahora()->modify($compartido ? '+' . SESION_MIN_COMPARTIDO . ' minutes' : '+' . SESION_DIAS_PERSONAL . ' days'));
}

/** Crea la sesión. El token solo se devuelve aquí (va a la cookie); en la base queda su hash. */
function sesion_crear(PDO $db, int $id, bool $compartido, array $meta): array
{
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(24));
    $vence = sesion_vence($compartido);
    $db->prepare('INSERT INTO sesiones (token_hash, csrf, funcionario_id, creada, ultimo_uso, vence, compartido, user_agent, ip) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([hash('sha256', $token), $csrf, $id, Reloj::stamp(), Reloj::stamp(), $vence, $compartido ? 1 : 0,
                  mb_substr((string)($meta['ua'] ?? ''), 0, 200), (string)($meta['ip'] ?? '')]);
    return ['token' => $token, 'csrf' => $csrf, 'vence' => $vence, 'compartido' => $compartido, 'funcionario' => persona_publica_propia($db, $id)];
}

/** Datos de la persona que inició sesión (para su propia pantalla). */
function persona_publica_propia(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT f.id, f.nombre, f.jornada, f.equipo_id, e.nombre AS equipo, f.calidad, f.rol
        FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id WHERE f.id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    $p['id'] = (int)$p['id'];
    $p['equipo_id'] = $p['equipo_id'] !== null ? (int)$p['equipo_id'] : null;
    return $p;
}

/** Valida el token de la cookie y renueva el vencimiento. Devuelve la sesión con la persona, o null. */
function sesion_validar(PDO $db, ?string $token): ?array
{
    if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $st = $db->prepare('SELECT s.*, f.rol, f.activo FROM sesiones s JOIN funcionarios f ON f.id = s.funcionario_id
        WHERE s.token_hash = ? AND s.revocada = 0');
    $st->execute([hash('sha256', $token)]);
    $s = $st->fetch();
    if (!$s || !(int)$s['activo'] || Reloj::stamp() > $s['vence']) return null;
    $compartido = (bool)$s['compartido'];
    // Renovar: la compartida en cada uso; la personal como máximo cada 10 minutos (menos escrituras).
    if ($compartido || Reloj::ahora()->modify('-10 minutes')->format('Y-m-d H:i:s') > $s['ultimo_uso']) {
        $s['vence'] = sesion_vence($compartido);
        $db->prepare('UPDATE sesiones SET ultimo_uso=?, vence=? WHERE id=?')->execute([Reloj::stamp(), $s['vence'], $s['id']]);
    }
    $s['funcionario_id'] = (int)$s['funcionario_id'];
    $s['id'] = (int)$s['id'];
    unset($s['token_hash']);
    return $s;
}

function sesiones_listar(PDO $db, int $funcionarioId): array
{
    $st = $db->prepare('SELECT id, creada, ultimo_uso, vence, compartido, user_agent FROM sesiones
        WHERE funcionario_id=? AND revocada=0 AND vence >= ? ORDER BY ultimo_uso DESC');
    $st->execute([$funcionarioId, Reloj::stamp()]);
    $r = $st->fetchAll();
    foreach ($r as &$x) { $x['id'] = (int)$x['id']; $x['compartido'] = (bool)$x['compartido']; }
    return $r;
}

function sesion_revocar(PDO $db, int $sesionId, int $funcionarioId): void
{
    $db->prepare('UPDATE sesiones SET revocada=1 WHERE id=? AND funcionario_id=?')->execute([$sesionId, $funcionarioId]);
}

function sesiones_revocar_todas(PDO $db, int $funcionarioId, ?int $exceptoId = null): int
{
    $st = $db->prepare('UPDATE sesiones SET revocada=1 WHERE funcionario_id=? AND revocada=0 AND id<>?');
    $st->execute([$funcionarioId, $exceptoId ?? 0]);
    return $st->rowCount();
}
