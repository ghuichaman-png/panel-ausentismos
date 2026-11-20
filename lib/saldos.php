<?php
declare(strict_types=1);

/**
 * Saldos internos de FL y ADM (SPEC-v2 §3.8 y §4.5). El saldo NO se guarda: se calcula.
 *   disponible = asignación anual + ajustes − días de solicitudes autorizadas − días de solicitudes pendientes
 * Asignación: regla fija (config: saldo_fl_anual 15, saldo_adm_anual 6) por año calendario, sin arrastre.
 * Lo que usa cada solicitud queda en solicitud_saldo (por año), así que rechazar, anular o revertir no exige
 * «devolver» nada a mano y es imposible descontar dos veces (bug B5 de la app anterior).
 */

const SALDOS = ['FL', 'ADM'];

function saldo_anual(string $saldo): float
{
    return (float)($saldo === 'FL' ? cfg('saldo_fl_anual', 15) : cfg('saldo_adm_anual', 6));
}

/** Crea la asignación anual que falte (todas las personas activas, o solo una). Repetible: no duplica. */
function saldos_asegurar_asignacion(PDO $db, int $anio, ?int $funcionarioId = null): void
{
    $sql = 'INSERT OR IGNORE INTO saldo_movimientos (funcionario_id, anio, saldo, clase, dias, motivo, creado_en)
            SELECT id, ?, ?, \'asignacion\', ?, \'Asignación anual\', ? FROM funcionarios WHERE activo=1' . ($funcionarioId ? ' AND id=?' : '');
    $st = $db->prepare($sql);
    foreach (SALDOS as $s) {
        $par = [$anio, $s, saldo_anual($s), Reloj::stamp()];
        if ($funcionarioId) $par[] = $funcionarioId;
        $st->execute($par);
    }
}

/**
 * Días de saldo que usa una solicitud, por año: ['2026' => 4.0, '2027' => 3.0].
 * Tipos de rango (FL): días hábiles entre la primera y la última fecha (también para turnantes, §12.1).
 * Tipos de un día (ADM): 1 por día o turno completo, 0,5 por medio día o media jornada (§12.2).
 */
function saldo_requerido(array $tipo, array $dias, array $feriados, ?array $rango = null): array
{
    if (empty($tipo['saldo']) || !$dias) return [];
    $out = [];
    if ($tipo['rango'] === 'rango') {
        $fechas = array_column($dias, 'fecha');
        [$desde, $hasta] = $rango ?? [min($fechas), max($fechas)];   // $rango: el pedido (turnantes), si no, el de los días
        foreach (rango_fechas($desde, $hasta) as $f) {
            if (dia_semana($f) >= 6 || isset($feriados[$f])) continue;
            $out[substr($f, 0, 4)] = ($out[substr($f, 0, 4)] ?? 0.0) + 1.0;
        }
    } else {
        foreach ($dias as $d) $out[substr($d['fecha'], 0, 4)] = ($out[substr($d['fecha'], 0, 4)] ?? 0.0) + ($d['fraccion'] === 'completo' ? 1.0 : 0.5);
    }
    return $out;
}

/*
 * FL: bloque de vacaciones o días fraccionados (pedido del 20-11-2026; reemplaza SPEC-v2 §12.1).
 *  - Bloque: el rango pedido tiene `fl_bloque_min_dias_habiles` (10) o más días hábiles. Descuenta los días hábiles
 *    (lunes a viernes sin festivos), también al turnante, aunque sus turnos caigan sábado o domingo.
 *  - Fraccionado: rango más corto. El diurno descuenta sus días hábiles; el turnante, 1 por cada turno.
 *    Máximo `fl_fraccionado_max_dias` (5) por año, contando pendientes y autorizadas. Vale para todos.
 */

/** ¿Aplica la regla de bloque/fraccionado? Tipos de rango que descuentan del saldo FL. */
function fl_aplica(array $t): bool
{
    return ($t['saldo'] ?? null) === 'FL' && ($t['rango'] ?? '') === 'rango' && empty($t['es_cambio_turno']);
}

function fl_habiles(string $desde, string $hasta, array $feriados): int
{
    $n = 0;
    foreach (rango_fechas($desde, $hasta) as $f) if (dia_semana($f) < 6 && !isset($feriados[$f])) $n++;
    return $n;
}

function fl_modalidad(string $desde, string $hasta, array $feriados, array $cfg): string
{
    return fl_habiles($desde, $hasta, $feriados) >= (int)($cfg['fl_bloque_min_dias_habiles'] ?? 10) ? 'bloque' : 'fraccionado';
}

/** Días de FL fraccionado ya usados (pendientes + autorizadas) en un año. $excluir: sin contar esa solicitud. */
function fl_fraccionado_usado(PDO $db, int $fid, int $anio, ?int $excluir = null): float
{
    $st = $db->prepare("SELECT COALESCE(SUM(ss.dias),0) FROM solicitud_saldo ss JOIN solicitudes s ON s.id = ss.solicitud_id
        WHERE s.funcionario_id=? AND ss.anio=? AND ss.saldo='FL' AND s.fl_modalidad='fraccionado'
          AND s.estado IN ('pendiente','autorizada') AND s.id <> ?");
    $st->execute([$fid, $anio, $excluir ?? 0]);
    return (float)$st->fetchColumn();
}

/** Una fila por año: lo que lleva, lo que usa esta solicitud y el máximo. */
function fl_revisar_fraccionado(PDO $db, int $fid, array $req, array $cfg, ?int $excluir = null): array
{
    $max = (float)($cfg['fl_fraccionado_max_dias'] ?? 5);
    $out = [];
    foreach ($req as $anio => $dias) {
        $usado = fl_fraccionado_usado($db, $fid, (int)$anio, $excluir);
        $out[] = ['anio' => (int)$anio, 'usado' => $usado, 'usa' => (float)$dias, 'maximo' => $max, 'excede' => $usado + $dias > $max + REG_EPS];
    }
    return $out;
}

/** Lanza 422 «fl_fraccionado» si algún año supera el máximo de días fraccionados. */
function fl_exigir_fraccionado(array $revision, array $cfg): void
{
    foreach ($revision as $r) {
        if (!$r['excede']) continue;
        $bloque = (int)($cfg['fl_bloque_min_dias_habiles'] ?? 10);
        throw new ApiError(sprintf('El feriado legal fraccionado es de máximo %s días al año. En %d llevas %s y esta solicitud usa %s. '
            . 'El resto se toma en un bloque de %d días hábiles o más.', num_cl($r['maximo']), $r['anio'], num_cl($r['usado']), num_cl($r['usa']), $bloque),
            422, ['codigo' => 'fl_fraccionado', 'fraccionado' => $revision]);
    }
}

/** FL fraccionado de turnante: 1 por cada turno, en el año de su fecha. */
function fl_uso_por_turno(array $dias): array
{
    $out = [];
    foreach ($dias as $d) $out[substr($d['fecha'], 0, 4)] = ($out[substr($d['fecha'], 0, 4)] ?? 0.0) + 1.0;
    return $out;
}

/** Estado de los saldos de una persona en un año. $excluir: no contar esa solicitud (al revertirla). */
function saldo_estado(PDO $db, int $fid, int $anio, ?int $excluir = null): array
{
    saldos_asegurar_asignacion($db, $anio, $fid);
    $out = [];
    foreach (SALDOS as $s) $out[$s] = ['asignado' => 0.0, 'ajustes' => 0.0, 'autorizado' => 0.0, 'pendiente' => 0.0, 'disponible' => 0.0];
    $st = $db->prepare('SELECT saldo, clase, SUM(dias) AS d FROM saldo_movimientos WHERE funcionario_id=? AND anio=? GROUP BY saldo, clase');
    $st->execute([$fid, $anio]);
    foreach ($st->fetchAll() as $r) $out[$r['saldo']][$r['clase'] === 'asignacion' ? 'asignado' : 'ajustes'] += (float)$r['d'];
    $st = $db->prepare("SELECT ss.saldo, s.estado, SUM(ss.dias) AS d FROM solicitud_saldo ss JOIN solicitudes s ON s.id = ss.solicitud_id
        WHERE s.funcionario_id=? AND ss.anio=? AND s.estado IN ('pendiente','autorizada') AND s.id <> ? GROUP BY ss.saldo, s.estado");
    $st->execute([$fid, $anio, $excluir ?? 0]);
    foreach ($st->fetchAll() as $r) $out[$r['saldo']][$r['estado'] === 'autorizada' ? 'autorizado' : 'pendiente'] += (float)$r['d'];
    foreach ($out as &$x) $x['disponible'] = round($x['asignado'] + $x['ajustes'] - $x['autorizado'] - $x['pendiente'], 2);
    return $out;
}

/** Compara lo que pide una solicitud con lo disponible. Devuelve una fila por año (para el resumen del formulario). */
function saldo_revisar(PDO $db, int $fid, ?string $saldo, array $req, ?int $excluir = null): array
{
    if (!$saldo) return [];
    $out = [];
    foreach ($req as $anio => $dias) {
        $disp = saldo_estado($db, $fid, (int)$anio, $excluir)[$saldo]['disponible'];
        $out[] = ['saldo' => $saldo, 'anio' => (int)$anio, 'usa' => $dias, 'disponible' => $disp, 'insuficiente' => $dias > $disp + REG_EPS];
    }
    return $out;
}

/** Lanza 409 «sin_saldo» si algún año no alcanza (SPEC-v2 §4.5: se bloquea). */
function saldo_exigir(array $revision): void
{
    foreach ($revision as $r) {
        if (!$r['insuficiente']) continue;
        $nombre = $r['saldo'] === 'FL' ? 'feriado legal' : 'administrativos';
        throw new ApiError(sprintf('No te alcanza el saldo de %s %d: quedan %s días y esta solicitud usa %s. Habla con coordinación.',
            $nombre, $r['anio'], num_cl($r['disponible']), num_cl($r['usa'])), 409, ['codigo' => 'sin_saldo', 'saldo' => $revision]);
    }
}

function num_cl(float $n): string { return str_replace('.', ',', (string)round($n, 2)); }

/** Guarda lo que usa una solicitud recién creada. */
function saldo_registrar_uso(PDO $db, int $solicitudId, ?string $saldo, array $req): void
{
    if (!$saldo) return;
    $st = $db->prepare('INSERT INTO solicitud_saldo (solicitud_id, anio, saldo, dias) VALUES (?,?,?,?)');
    foreach ($req as $anio => $dias) $st->execute([$solicitudId, (int)$anio, $saldo, $dias]);
}

/** Lo que usa una solicitud ya guardada: ['saldo' => 'FL', 'req' => [anio => días]]. */
function saldo_uso_guardado(PDO $db, int $solicitudId): array
{
    $st = $db->prepare('SELECT anio, saldo, dias FROM solicitud_saldo WHERE solicitud_id=?');
    $st->execute([$solicitudId]);
    $req = []; $saldo = null;
    foreach ($st->fetchAll() as $r) { $saldo = $r['saldo']; $req[(string)$r['anio']] = (float)$r['dias']; }
    return ['saldo' => $saldo, 'req' => $req];
}

/** Ajuste manual de coordinación (p. ej. «+5 FL por antigüedad»). Motivo obligatorio; queda auditado. */
function saldo_ajustar(PDO $db, int $fid, int $anio, string $saldo, float $dias, string $motivo, ?int $porId, string $ip = ''): void
{
    $motivo = limpiar($motivo, 200);
    if (!in_array($saldo, SALDOS, true)) throw new ApiError('El saldo debe ser FL o ADM.', 422);
    if (abs($dias) < REG_EPS || abs($dias) > 60) throw new ApiError('Indica cuántos días sumar (o restar, con signo menos), entre 0,5 y 60.', 422);
    if ($motivo === '') throw new ApiError('Escribe el motivo del ajuste (queda registrado).', 422);
    if ($anio < 2000 || $anio > 2100) throw new ApiError('Año inválido.', 422);
    $st = $db->prepare('SELECT nombre FROM funcionarios WHERE id=?');
    $st->execute([$fid]);
    $nombre = $st->fetchColumn();
    if ($nombre === false) throw new ApiError('Esa persona no existe.', 404);
    tx($db, function () use ($db, $fid, $anio, $saldo, $dias, $motivo, $porId, $ip, $nombre) {
        saldos_asegurar_asignacion($db, $anio, $fid);
        $db->prepare("INSERT INTO saldo_movimientos (funcionario_id, anio, saldo, clase, dias, motivo, creado_por, creado_en) VALUES (?,?,?,'ajuste',?,?,?,?)")
           ->execute([$fid, $anio, $saldo, $dias, $motivo, $porId, Reloj::stamp()]);
        auditar($db, 'saldo_ajuste', null, "$nombre: $saldo $anio " . ($dias > 0 ? '+' : '') . num_cl($dias) . " ($motivo)", $ip, $porId);
    });
}

// ───────────────────────── Rutas ─────────────────────────

function anio_pedido(): int
{
    $a = (int)($_GET['anio'] ?? 0);
    return $a >= 2000 && $a <= 2100 ? $a : (int)substr(Reloj::hoy(), 0, 4);
}

function h_saldos_mios(PDO $db, array $p, array $in): array
{
    $anio = anio_pedido();
    $st = $db->prepare("SELECT saldo, dias, motivo, creado_en FROM saldo_movimientos WHERE funcionario_id=? AND anio=? AND clase='ajuste' ORDER BY id");
    $st->execute([yo(), $anio]);
    $aj = $st->fetchAll();
    foreach ($aj as &$x) $x['dias'] = (float)$x['dias'];
    return ['anio' => $anio, 'saldos' => saldo_estado($db, yo(), $anio), 'ajustes' => $aj];
}

function h_coord_saldos(PDO $db, array $p, array $in): array
{
    $anio = anio_pedido();
    saldos_asegurar_asignacion($db, $anio);
    $out = [];
    foreach ($db->query('SELECT f.id, f.nombre, f.jornada, e.nombre AS equipo FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id WHERE f.activo=1 ORDER BY f.nombre') as $f) {
        $out[] = ['funcionario_id' => (int)$f['id'], 'nombre' => $f['nombre'], 'jornada' => $f['jornada'], 'equipo' => $f['equipo']] + saldo_estado($db, (int)$f['id'], $anio);
    }
    return ['anio' => $anio, 'personas' => $out];
}

function h_coord_saldos_ajustar(PDO $db, array $p, array $in): array
{
    saldo_ajustar($db, (int)($in['funcionario_id'] ?? 0), (int)($in['anio'] ?? 0), (string)($in['saldo'] ?? ''),
                  (float)str_replace(',', '.', (string)($in['dias'] ?? 0)), (string)($in['motivo'] ?? ''), yo(), ip_cliente());
    return ['ok' => true];
}
