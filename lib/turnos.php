<?php
declare(strict_types=1);

/**
 * Calendario de turnos (SPEC-v2 §3.7). Cada turno es de una persona turnante (titular) en una unidad, fecha y tipo
 * (largo | noche; la noche lleva la fecha en que empieza). Una ausencia autorizada lo marca «cubierto por» alguien;
 * un cambio de turno cambia su titular (guardando el original para poder restaurarlo).
 */

const TURNO_TIPOS = ['largo', 'noche'];

function turno_fila(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM turnos WHERE id=?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) throw new ApiError('Ese turno no existe.', 404);
    return $t;
}

/** ¿El turno está tomado por una solicitud activa (pendiente o autorizada)? */
function turno_en_uso(PDO $db, int $id): ?string
{
    $st = $db->prepare("SELECT s.codigo FROM solicitud_dias sd JOIN solicitudes s ON s.id=sd.solicitud_id
        WHERE sd.turno_id=? AND s.estado IN ('pendiente','autorizada') LIMIT 1");
    $st->execute([$id]);
    $c = $st->fetchColumn();
    if ($c !== false) return (string)$c;
    $st = $db->prepare("SELECT s.codigo FROM turnos t JOIN solicitudes s ON s.id=t.solicitud_id WHERE t.id=? AND s.estado IN ('pendiente','autorizada')");
    $st->execute([$id]);
    $c = $st->fetchColumn();
    return $c === false ? null : (string)$c;
}

function turno_validar(PDO $db, array $in): array
{
    if (!fecha_ok($in['fecha'] ?? null)) throw new ApiError('Indica la fecha del turno.', 422);
    $tipo = $in['tipo'] ?? '';
    if (!es_en($tipo, TURNO_TIPOS)) throw new ApiError('El turno debe ser largo o noche.', 422);
    $u = (int)($in['unidad_id'] ?? 0);
    $st = $db->prepare('SELECT 1 FROM equipos WHERE id=? AND activo=1 AND tiene_turnantes=1');
    $st->execute([$u]);
    if (!$st->fetchColumn()) throw new ApiError('Elige una unidad con turnantes.', 422);
    $f = (int)($in['titular_id'] ?? 0);
    $st = $db->prepare("SELECT jornada FROM funcionarios WHERE id=? AND activo=1");
    $st->execute([$f]);
    $j = $st->fetchColumn();
    if ($j === false) throw new ApiError('Elige a la persona del turno.', 422);
    if ($j !== 'turnante') throw new ApiError('El titular de un turno debe ser turnante (los diurnos solo aparecen cuando cubren uno).', 422);
    return ['fecha' => $in['fecha'], 'tipo' => $tipo, 'unidad_id' => $u, 'titular_id' => $f];
}

const MSG_TURNO_DUPLICADO = 'Esa persona ya tiene ese turno ese día en esa unidad.';

function h_coord_turno_guardar(PDO $db, array $p, array $in): array
{
    $id = isset($p[1]) && (int)$p[1] > 0 ? (int)$p[1] : null;
    $v = turno_validar($db, $in);
    $extra = array_key_exists('es_extra', $in) ? (int)(bool)$in['es_extra'] : 0;
    $horas = isset($in['horas_override']) && $in['horas_override'] !== '' && $in['horas_override'] !== null ? (float)str_replace(',', '.', (string)$in['horas_override']) : null;
    if ($horas !== null && ($horas < 0 || $horas > 24)) throw new ApiError('Las horas deben estar entre 0 y 24.', 422);
    return tx($db, function () use ($db, $id, $v, $extra, $horas) {
        try {
            if ($id === null) {
                $db->prepare('INSERT INTO turnos (fecha,tipo,unidad_id,titular_id,es_extra,horas_override,creado_en) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$v['fecha'], $v['tipo'], $v['unidad_id'], $v['titular_id'], $extra, $horas, Reloj::stamp()]);
                $id = (int)$db->lastInsertId();
                auditar($db, 'turno_crear', null, "#$id {$v['fecha']} {$v['tipo']}", ip_cliente(), yo_o_null());
            } else {
                $t = turno_fila($db, $id);
                $cambiaBase = $t['fecha'] !== $v['fecha'] || $t['tipo'] !== $v['tipo'] || (int)$t['unidad_id'] !== $v['unidad_id'] || (int)$t['titular_id'] !== $v['titular_id'];
                if ($cambiaBase && ($c = turno_en_uso($db, $id)) !== null) {
                    throw new ApiError("Ese turno está en la solicitud $c: solo se pueden cambiar las horas o si es extra.", 409);
                }
                $db->prepare('UPDATE turnos SET fecha=?, tipo=?, unidad_id=?, titular_id=?, es_extra=?, horas_override=? WHERE id=?')
                   ->execute([$v['fecha'], $v['tipo'], $v['unidad_id'], $v['titular_id'], $extra, $horas, $id]);
                auditar($db, 'turno_editar', null, "#$id", ip_cliente(), yo_o_null());
            }
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) throw new ApiError(MSG_TURNO_DUPLICADO, 409);
            throw $e;
        }
        return ['id' => $id];
    });
}

/** Carga en lote con un patrón que se repite (p. ej. largo, noche, libre, libre). Repetible: no duplica. */
function h_coord_turnos_lote(PDO $db, array $p, array $in): array
{
    $patron = $in['patron'] ?? [];
    if (!is_array($patron) || count($patron) < 1 || count($patron) > 14) throw new ApiError('El patrón debe tener entre 1 y 14 días.', 422);
    foreach ($patron as $x) if (!es_en($x, ['largo', 'noche', ''])) throw new ApiError('Cada día del patrón es «largo», «noche» o libre.', 422);
    if (!array_filter($patron)) throw new ApiError('El patrón no tiene ningún turno.', 422);
    if (!fecha_ok($in['desde'] ?? null) || !fecha_ok($in['hasta'] ?? null) || $in['hasta'] < $in['desde']) throw new ApiError('Indica desde y hasta.', 422);
    $base = turno_validar($db, ['fecha' => $in['desde'], 'tipo' => 'largo'] + $in);
    return tx($db, function () use ($db, $in, $patron, $base) {
        $st = $db->prepare('INSERT OR IGNORE INTO turnos (fecha,tipo,unidad_id,titular_id,creado_en) VALUES (?,?,?,?,?)');
        $creados = 0; $omitidos = 0;
        foreach (rango_fechas($in['desde'], $in['hasta'], 400) as $i => $f) {
            $tipo = $patron[$i % count($patron)];
            if ($tipo === '') continue;
            $st->execute([$f, $tipo, $base['unidad_id'], $base['titular_id'], Reloj::stamp()]);
            $st->rowCount() ? $creados++ : $omitidos++;
        }
        auditar($db, 'turnos_lote', null, "unidad {$base['unidad_id']} titular {$base['titular_id']} {$in['desde']}→{$in['hasta']}: $creados creados", ip_cliente(), yo_o_null());
        return ['creados' => $creados, 'omitidos' => $omitidos];
    });
}

function h_coord_turno_eliminar(PDO $db, array $p, array $in): array
{
    $id = (int)$p[1];
    turno_fila($db, $id);
    if (($c = turno_en_uso($db, $id)) !== null) throw new ApiError("No se puede borrar: el turno está en la solicitud $c.", 409);
    $db->prepare('UPDATE solicitud_dias SET turno_id=NULL WHERE turno_id=?')->execute([$id]);   // solo solicitudes anuladas o rechazadas
    $db->prepare('DELETE FROM turnos WHERE id=?')->execute([$id]);
    auditar($db, 'turno_eliminar', null, "#$id", ip_cliente(), yo_o_null());
    return ['ok' => true];
}

const TURNO_SELECT = "SELECT t.*, u.nombre AS unidad, ft.nombre AS titular, fc.nombre AS cubierto_por, s.codigo AS solicitud_codigo, s.tipo AS solicitud_tipo
    FROM turnos t JOIN equipos u ON u.id=t.unidad_id JOIN funcionarios ft ON ft.id=t.titular_id
    LEFT JOIN funcionarios fc ON fc.id=t.cubierto_por_id LEFT JOIN solicitudes s ON s.id=t.solicitud_id";

function turno_publico(array $t): array
{
    foreach (['id', 'unidad_id', 'titular_id'] as $k) $t[$k] = (int)$t[$k];
    foreach (['cubierto_por_id', 'solicitud_id', 'titular_original_id'] as $k) $t[$k] = $t[$k] !== null ? (int)$t[$k] : null;
    $t['es_extra'] = (bool)$t['es_extra'];
    $t['cubierto_extra'] = (bool)($t['cubierto_extra'] ?? false);
    $t['horas_override'] = $t['horas_override'] !== null ? (float)$t['horas_override'] : null;
    // estado: normal · cubierto (ausencia con reemplazo) · sin_cubrir (licencia sin reemplazo) · cambio (cambio de turno)
    $t['estado'] = $t['cubierto_por_id'] ? 'cubierto' : (in_array($t['origen'], ['licencia', 'ausencia'], true) ? 'sin_cubrir' : ($t['origen'] === 'cambio' ? 'cambio' : 'normal'));
    return $t;
}

function h_coord_turnos(PDO $db, array $p, array $in): array
{
    $mes = (string)($_GET['mes'] ?? substr(Reloj::hoy(), 0, 7));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) throw new ApiError('Mes inválido.', 422);
    $desde = $mes . '-01';
    $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
    $sql = TURNO_SELECT . ' WHERE t.fecha BETWEEN ? AND ?';
    $par = [$desde, $hasta];
    if (!empty($_GET['unidad_id'])) { $sql .= ' AND t.unidad_id=?'; $par[] = (int)$_GET['unidad_id']; }
    $st = $db->prepare($sql . ' ORDER BY t.fecha, t.tipo, u.orden, ft.nombre');
    $st->execute($par);
    return ['mes' => $mes, 'turnos' => array_map('turno_publico', $st->fetchAll())];
}

/** Turnos de una persona (como titular) entre dos fechas. */
function turnos_de(PDO $db, int $fid, string $desde, string $hasta): array
{
    $st = $db->prepare(TURNO_SELECT . ' WHERE t.titular_id=? AND t.fecha BETWEEN ? AND ? ORDER BY t.fecha, t.tipo');
    $st->execute([$fid, $desde, $hasta]);
    return array_map('turno_publico', $st->fetchAll());
}

/** «Mis turnos»: los propios desde hoy y los que me tocan cubrir. */
function h_turnos_mios(PDO $db, array $p, array $in): array
{
    $hoy = Reloj::hoy();
    $hasta = fecha_add($hoy, 120);
    $st = $db->prepare(TURNO_SELECT . ' WHERE t.cubierto_por_id=? AND t.fecha BETWEEN ? AND ? ORDER BY t.fecha, t.tipo');
    $st->execute([yo(), $hoy, $hasta]);
    // en_solicitud: código de la solicitud activa que ya usa ese turno (el formulario no lo ofrece de nuevo).
    $mios = array_map(fn($t) => $t + ['en_solicitud' => turno_en_uso($db, $t['id'])], turnos_de($db, yo(), $hoy, $hasta));
    return ['turnos' => $mios, 'cubro' => array_map('turno_publico', $st->fetchAll())];
}

// ───────────────────────── Efecto de las solicitudes en los turnos (§4.3, §4.4, §4.6) ─────────────────────────

const MSG_TURNO_NO_DISPONIBLE = 'El turno del %s ya no está disponible (cambió de titular o lo tomó otra solicitud). Revísalo en el calendario de turnos.';

/**
 * Aplica una solicitud autorizada al calendario de turnos. Se llama DENTRO de la transacción que la autoriza:
 * si algo falla, se revierte todo (bug B6 de la app anterior).
 *  - Ausencia con reemplazo: el turno queda «cubierto por» el reemplazo (extra según $extra).
 *  - Cambio de turno: el turno cedido pasa al compañero y la devolución a quien pidió (guardando el titular original).
 *  - Tipo sin reemplazo registrado por coordinación (licencia): los turnos del rango quedan «sin cubrir».
 */
function turnos_aplicar(PDO $db, array $s, bool $extra): void
{
    if ($s['jornada'] !== 'turnante') return;
    $st = $db->prepare('SELECT * FROM tipos_ausentismo WHERE sigla=?');
    $st->execute([$s['tipo']]);
    $t = $st->fetch();
    $fid = (int)$s['funcionario_id'];
    $sid = (int)$s['id'];
    $exigir = function (?int $turnoId, int $titular) use ($db): array {
        $st = $db->prepare('SELECT * FROM turnos WHERE id=?');
        $st->execute([(int)$turnoId]);
        $tu = $st->fetch();
        if (!$tu || (int)$tu['titular_id'] !== $titular || $tu['solicitud_id'] !== null) {
            throw new ApiError(sprintf(MSG_TURNO_NO_DISPONIBLE, $tu ? fecha_cl($tu['fecha']) : '(borrado)'), 409, ['codigo' => 'turno_no_disponible']);
        }
        return $tu;
    };
    try {
        if ($t && (int)$t['es_cambio_turno']) {
            $d = reg_sol_dias($db, $sid)[0] ?? null;
            $tu = $exigir($d ? (int)$d['turno_id'] : null, $fid);
            $con = (int)$s['cambio_con_id'];
            $db->prepare("UPDATE turnos SET titular_original_id=titular_id, titular_id=?, origen='cambio', solicitud_id=? WHERE id=?")->execute([$con, $sid, $tu['id']]);
            if ($s['devolucion_turno_id']) {
                $dev = $exigir((int)$s['devolucion_turno_id'], $con);
                $db->prepare("UPDATE turnos SET titular_original_id=titular_id, titular_id=?, origen='cambio', solicitud_id=? WHERE id=?")->execute([$fid, $sid, $dev['id']]);
            }
        } elseif ($t && $t['lo_pide'] === 'coordinacion' && !(int)$t['exige_reemplazo']) {
            $db->prepare("UPDATE turnos SET origen='licencia', solicitud_id=?, cubierto_por_id=NULL, cubierto_extra=0
                          WHERE titular_id=? AND fecha BETWEEN ? AND ? AND solicitud_id IS NULL")->execute([$sid, $fid, $s['fecha_inicio'], $s['fecha_fin']]);
        } else {
            foreach (reg_sol_dias($db, $sid) as $d) {
                if (!$d['turno_id']) continue;
                $tu = $exigir((int)$d['turno_id'], $fid);
                $db->prepare("UPDATE turnos SET cubierto_por_id=?, cubierto_extra=?, origen='ausencia', solicitud_id=? WHERE id=?")
                   ->execute([$d['reemplazo_id'], $d['reemplazo_id'] ? (int)$extra : 0, $sid, $tu['id']]);
            }
        }
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new ApiError('El cambio dejaría a una persona con dos turnos iguales el mismo día. Revisa el calendario de turnos.', 409);
        throw $e;
    }
}

/** Deshace en los turnos SOLO lo que hizo esta solicitud (bug B15 de la app anterior). */
function turnos_restaurar(PDO $db, int $solicitudId): void
{
    $db->prepare("UPDATE turnos SET titular_id=COALESCE(titular_original_id, titular_id), titular_original_id=NULL, cubierto_por_id=NULL,
                  cubierto_extra=0, origen='carga', solicitud_id=NULL WHERE solicitud_id=?")->execute([$solicitudId]);
}

/** Turnos de personas ausentes que nadie cubre (licencias o ausencias sin reemplazo). */
function h_coord_turnos_sin_cubrir(PDO $db, array $p, array $in): array
{
    $desde = fecha_ok($_GET['desde'] ?? null) ? $_GET['desde'] : Reloj::hoy();
    $hasta = fecha_ok($_GET['hasta'] ?? null) ? $_GET['hasta'] : fecha_add($desde, 30);
    $st = $db->prepare(TURNO_SELECT . " WHERE t.solicitud_id IS NOT NULL AND t.cubierto_por_id IS NULL AND t.origen IN ('licencia','ausencia')
        AND t.fecha BETWEEN ? AND ? ORDER BY t.fecha, t.tipo");
    $st->execute([$desde, $hasta]);
    return ['turnos' => array_map('turno_publico', $st->fetchAll())];
}

/** Coordinación asigna quién cubre un turno de una persona ausente. */
function h_coord_turno_cubrir(PDO $db, array $p, array $in): array
{
    $tu = turno_fila($db, (int)$p[1]);
    if ($tu['solicitud_id'] === null || $tu['origen'] === 'cambio') throw new ApiError('Ese turno no es de una persona ausente.', 422);
    $nombre = reg_otra_persona($db, $in['funcionario_id'] ?? 0, (int)$tu['titular_id'], 'quién cubre el turno');
    $db->prepare('UPDATE turnos SET cubierto_por_id=?, cubierto_extra=? WHERE id=?')->execute([(int)$in['funcionario_id'], (int)(bool)($in['es_extra'] ?? true), $tu['id']]);
    auditar($db, 'turno_cubrir', (int)$tu['solicitud_id'], "turno #{$tu['id']} {$tu['fecha']} → $nombre", ip_cliente(), yo_o_null());
    return ['ok' => true];
}
/** Turnos futuros de un compañero turnante (para elegir la devolución de un cambio). Solo fecha, turno y unidad. */
function h_turnos_de(PDO $db, array $p, array $in): array
{
    $st = $db->prepare("SELECT 1 FROM funcionarios WHERE id=? AND activo=1 AND jornada='turnante'");
    $st->execute([(int)$p[1]]);
    if (!$st->fetchColumn()) throw new ApiError('Esa persona no es turnante.', 404);
    $hoy = Reloj::hoy();
    return ['turnos' => array_map(fn($t) => ['id' => $t['id'], 'fecha' => $t['fecha'], 'tipo' => $t['tipo'], 'unidad' => $t['unidad'], 'estado' => $t['estado']],
                                  turnos_de($db, (int)$p[1], $hoy, fecha_add($hoy, 120)))];
}
