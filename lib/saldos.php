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
