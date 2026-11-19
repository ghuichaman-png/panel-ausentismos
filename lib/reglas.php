<?php
declare(strict_types=1);

/**
 * Reglas de negocio de ausentismos (sección 4 de la especificación).
 * Todo lo que toca cupo se ejecuta dentro de tx() = BEGIN IMMEDIATE.
 */

const REG_EPS = 0.0001;
const REG_FRACCIONES_DIURNO = ['completo', 'am', 'pm'];
const REG_FRACCIONES_TURNANTE = ['completo', 'primera_mitad', 'segunda_mitad'];

function reg_feriados(PDO $db): array
{
    $set = [];
    foreach ($db->query('SELECT fecha FROM feriados') as $r) $set[$r['fecha']] = true;
    return $set;
}

/** Tipo de ausentismo activo por sigla (SPEC-v2 §3.5). */
function reg_tipo(PDO $db, string $sigla): array
{
    $st = $db->prepare('SELECT * FROM tipos_ausentismo WHERE sigla=? AND activo=1');
    $st->execute([strtoupper(trim($sigla))]);
    $t = $st->fetch();
    if (!$t) throw new ApiError('Elige el tipo de ausentismo.', 422);
    foreach (['consume_cupo', 'medio_dia', 'exige_reemplazo', 'es_cambio_turno', 'precargado', 'activo'] as $k) $t[$k] = (bool)$t[$k];
    return $t;
}

function reg_sol(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM solicitudes WHERE id=?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) throw new ApiError('La solicitud no existe.', 404);
    return $s;
}

function reg_sol_dias(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM solicitud_dias WHERE solicitud_id=? ORDER BY fecha, turno');
    $st->execute([$id]);
    return $st->fetchAll();
}

/**
 * Persona que pide, desde la nómina. Con login (SPEC-v2 §5) el funcionario_id lo pone el servidor desde la
 * sesión (o coordinación en «en nombre de»); ya no existen las solicitudes «fuera de nómina».
 */
function reg_persona(PDO $db, array $in): array
{
    $sel = 'SELECT f.*, e.nombre AS equipo_nombre FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id';
    if (empty($in['funcionario_id'])) throw new ApiError('Falta indicar la persona.', 422);
    $st = $db->prepare($sel . ' WHERE f.id=? AND f.activo=1');
    $st->execute([(int)$in['funcionario_id']]);
    $f = $st->fetch();
    if (!$f) throw new ApiError('No encontramos a esa persona en la nómina (o está desactivada).', 422);
    if ($f['jornada'] === 'diurno' && !$f['equipo_id']) {
        throw new ApiError('Esta persona no tiene equipo asignado en la nómina. Avisa a coordinación.', 422);
    }
    return ['funcionario_id' => (int)$f['id'], 'nombre' => $f['nombre'], 'rut' => $f['rut'], 'jornada' => $f['jornada'],
            'equipo_id' => $f['equipo_id'] !== null ? (int)$f['equipo_id'] : null, 'calidad' => $f['calidad'],
            'fuera' => 0, 'equipo_nombre' => $f['equipo_nombre']];
}

/** Días y consumo de cupo para un DIURNO según la configuración del tipo. Omite fines de semana y feriados. */
function reg_dias_diurno(array $t, string $fi, string $ff, string $fraccion, string $calidad, array $feriados, int $umbral = 5): array
{
    if ($t['es_cambio_turno']) throw new ApiError('El cambio de turno solo está disponible para turnantes.', 422);
    if ($t['rango'] === 'un_dia') {
        if ($ff !== $fi) throw new ApiError('«' . $t['nombre'] . '» es de un solo día.', 422);
    }
    if ($t['medio_dia']) {
        if (!in_array($fraccion, REG_FRACCIONES_DIURNO, true)) throw new ApiError('Elige día completo, mañana o tarde.', 422);
    } else {
        $fraccion = 'completo';
    }
    if ($ff < $fi) throw new ApiError('La fecha final es anterior a la inicial.', 422);

    $habiles = [];
    $omitidos = [];
    foreach (rango_fechas($fi, $ff) as $f) {
        if (dia_semana($f) >= 6) { $omitidos[] = ['fecha' => $f, 'motivo' => 'fin de semana']; continue; }
        if (isset($feriados[$f])) { $omitidos[] = ['fecha' => $f, 'motivo' => 'feriado']; continue; }
        $habiles[] = $f;
    }
    $n = count($habiles);
    if ($n === 0) throw new ApiError('Las fechas elegidas no incluyen días hábiles (lunes a viernes no feriados).', 422);

    if (!$t['consume_cupo']) $consumo = 0.0;
    elseif ($t['regla_cupo'] === 'fl') $consumo = ($calidad !== 'honorarios' && $n >= $umbral) ? 0.0 : 1.0;
    else $consumo = $fraccion === 'completo' ? 1.0 : 0.5;

    $dias = [];
    foreach ($habiles as $f) {
        // Bloques: día completo ocupa mañana y tarde; medio día, solo su bloque (SPEC-v2 §4.2).
        $usa = $consumo > 0 ? 1.0 : 0.0;
        $dias[] = ['fecha' => $f, 'consumo' => $consumo, 'fraccion' => $fraccion,
                   'am' => in_array($fraccion, ['completo', 'am'], true) ? $usa : 0.0,
                   'pm' => in_array($fraccion, ['completo', 'pm'], true) ? $usa : 0.0,
                   'turno' => null, 'unidad_turno_id' => null, 'reemplazo_nombre' => null];
    }
    return ['dias' => $dias, 'omitidos' => $omitidos, 'habiles' => $n];
}

/** Persona activa de la nómina distinta de quien pide (reemplazo o compañero de cambio). Devuelve su nombre. */
function reg_otra_persona(PDO $db, mixed $id, int $yo, string $que): string
{
    $id = (int)$id;
    if ($id <= 0) throw new ApiError("Indica $que.", 422);
    if ($id === $yo) throw new ApiError('Para ' . $que . ' elige a otra persona.', 422);
    $st = $db->prepare('SELECT nombre FROM funcionarios WHERE id=? AND activo=1');
    $st->execute([$id]);
    $n = $st->fetchColumn();
    if ($n === false) throw new ApiError("No encontramos en la nómina a la persona elegida para $que.", 422);
    return (string)$n;
}

/** Un turno cargado cuyo titular es $fid. */
function reg_turno_de(PDO $db, mixed $turnoId, int $fid, string $msgAjeno = 'Ese turno no es tuyo.'): array
{
    $t = $turnoId ? (function () use ($db, $turnoId) { $st = $db->prepare('SELECT * FROM turnos WHERE id=?'); $st->execute([(int)$turnoId]); return $st->fetch(); })() : false;
    if (!$t) throw new ApiError('Elige uno de tus turnos cargados. Si te falta un turno, avisa a coordinación.', 422);
    if ((int)$t['titular_id'] !== $fid) throw new ApiError($msgAjeno, 422);
    return $t;
}

function reg_dia_de_turno(array $tu, string $fraccion, ?int $reemplazoId, ?string $reemplazoNombre): array
{
    return ['fecha' => $tu['fecha'], 'consumo' => 0.0, 'am' => 0.0, 'pm' => 0.0, 'fraccion' => $fraccion, 'turno' => $tu['tipo'],
            'unidad_turno_id' => (int)$tu['unidad_id'], 'turno_id' => (int)$tu['id'], 'reemplazo_id' => $reemplazoId, 'reemplazo_nombre' => $reemplazoNombre];
}

/**
 * Días de un TURNANTE sobre sus turnos cargados (SPEC-v2 §4.3: se exige turno cargado). Nunca consumen cupo.
 *  - Tipos de un día (ADM): turno_id + reemplazo_id.
 *  - Tipos de rango (FL): fecha_inicio, fecha_fin y reemplazos {turno_id: reemplazo_id}; entran todos sus turnos del rango.
 *  - Cambio de turno: turno_id + cambio_con_id (+ devolucion_turno_id, un turno del compañero).
 * $extra recibe los campos del cambio de turno.
 */
function reg_dias_turnos(PDO $db, array $in, array $t, int $fid, array &$extra): array
{
    if (!empty($in['dias'])) throw new ApiError('Las solicitudes de turnantes se hacen sobre los turnos cargados en el calendario de turnos.', 422);
    $fraccion = 'completo';
    if ($t['medio_dia']) {
        $fraccion = (string)($in['fraccion'] ?? 'completo');
        if (!in_array($fraccion, REG_FRACCIONES_TURNANTE, true)) throw new ApiError('Elige turno completo o una mitad del turno.', 422);
    }
    if ($t['es_cambio_turno']) {
        $tu = reg_turno_de($db, $in['turno_id'] ?? null, $fid);
        $conId = (int)($in['cambio_con_id'] ?? 0);
        $extra['cambio_con'] = reg_otra_persona($db, $conId, $fid, 'con quién cambias el turno');
        $extra['cambio_con_id'] = $conId;
        if (!empty($in['devolucion_turno_id'])) {
            $dev = reg_turno_de($db, $in['devolucion_turno_id'], $conId, 'La devolución debe ser un turno de la persona con quien cambias.');
            $extra['devolucion_turno_id'] = (int)$dev['id'];
            $extra['devolucion_fecha'] = $dev['fecha'];
            $extra['devolucion_turno'] = $dev['tipo'];
        }
        return [reg_dia_de_turno($tu, 'completo', null, null)];
    }
    $reemplazo = function (mixed $rid, array $tu) use ($db, $t, $fid): array {
        if (!$rid && !$t['exige_reemplazo']) return [null, null];
        if (!$rid) throw new ApiError('Indica quién cubre tu turno del ' . fecha_cl($tu['fecha']) . '.', 422);
        return [(int)$rid, reg_otra_persona($db, $rid, $fid, 'quién cubre tu turno del ' . fecha_cl($tu['fecha']))];
    };
    if ($t['rango'] === 'un_dia') {
        $tu = reg_turno_de($db, $in['turno_id'] ?? null, $fid);
        [$rid, $rn] = $reemplazo($in['reemplazo_id'] ?? null, $tu);
        return [reg_dia_de_turno($tu, $fraccion, $rid, $rn)];
    }
    if (!fecha_ok($in['fecha_inicio'] ?? null)) throw new ApiError('Indica desde qué fecha.', 422);
    $ff = fecha_ok($in['fecha_fin'] ?? null) ? $in['fecha_fin'] : $in['fecha_inicio'];
    if ($ff < $in['fecha_inicio']) throw new ApiError('La fecha final es anterior a la inicial.', 422);
    $turnos = turnos_de($db, $fid, $in['fecha_inicio'], $ff);
    if (!$turnos) throw new ApiError('No tienes turnos cargados entre el ' . fecha_cl($in['fecha_inicio']) . ' y el ' . fecha_cl($ff) . '. Si falta alguno, avisa a coordinación.', 422);
    if (count($turnos) > 62) throw new ApiError('Demasiados turnos en una sola solicitud (máximo 62).', 422);
    $mapa = is_array($in['reemplazos'] ?? null) ? $in['reemplazos'] : [];
    $dias = [];
    foreach ($turnos as $tu) {
        [$rid, $rn] = $reemplazo($mapa[$tu['id']] ?? $mapa[(string)$tu['id']] ?? null, $tu);
        $dias[] = reg_dia_de_turno($tu, 'completo', $rid, $rn);
    }
    return $dias;
}

function reg_fraccion_choca(string $a, string $b): bool
{
    return $a === $b || $a === 'completo' || $b === 'completo';
}

/** Una persona no puede tener dos solicitudes activas que se superpongan. */
function reg_superposicion(PDO $db, string $rut, array $dias, ?int $excluir = null): void
{
    if (!$dias) return;
    $fechas = array_column($dias, 'fecha');
    $st = $db->prepare("SELECT sd.fecha, sd.fraccion, sd.turno, s.codigo
        FROM solicitud_dias sd JOIN solicitudes s ON s.id = sd.solicitud_id
        WHERE s.rut = ? AND s.estado IN ('pendiente','autorizada') AND s.id <> ? AND sd.fecha BETWEEN ? AND ?");
    $st->execute([$rut, $excluir ?? 0, min($fechas), max($fechas)]);
    foreach ($st->fetchAll() as $ex) {
        foreach ($dias as $d) {
            if ($d['fecha'] !== $ex['fecha']) continue;
            if ($d['turno'] !== null && $ex['turno'] !== null && $d['turno'] !== $ex['turno']) continue;
            if (reg_fraccion_choca($d['fraccion'], $ex['fraccion'])) {
                throw new ApiError('Ya existe una solicitud activa (' . $ex['codigo'] . ') que coincide con el ' . fecha_cl($d['fecha']) . '.', 409, ['codigo' => 'superpuesta']);
            }
        }
    }
}

/** Ocupación por bloque (pendientes + autorizadas de diurnos): fecha => ['am' => x, 'pm' => y]. */
function reg_ocupacion_bloques(PDO $db, int $equipoId, string $desde, string $hasta, ?int $excluir = null): array
{
    $st = $db->prepare("SELECT sd.fecha, SUM(sd.am) AS am, SUM(sd.pm) AS pm
        FROM solicitud_dias sd JOIN solicitudes s ON s.id = sd.solicitud_id
        WHERE s.estado IN ('pendiente','autorizada') AND s.jornada = 'diurno'
          AND sd.equipo_id = ? AND s.id <> ? AND sd.fecha BETWEEN ? AND ?
        GROUP BY sd.fecha");
    $st->execute([$equipoId, $excluir ?? 0, $desde, $hasta]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['fecha']] = ['am' => (float)$r['am'], 'pm' => (float)$r['pm']];
    return $out;
}

/** Ocupación de un día = la del bloque más ocupado (para resúmenes de un solo número). */
function reg_ocupacion(PDO $db, int $equipoId, string $desde, string $hasta, ?int $excluir = null): array
{
    return array_map(fn($b) => max($b['am'], $b['pm']), reg_ocupacion_bloques($db, $equipoId, $desde, $hasta, $excluir));
}

/** Días en que algún bloque pedido quedaría sobre el cupo. Cada entrada indica qué bloques ('am', 'pm') se llenan. */
function reg_dias_llenos(PDO $db, int $equipoId, array $dias, float $cupo, ?int $excluir = null): array
{
    if (!$dias) return [];
    $fechas = array_column($dias, 'fecha');
    $oc = reg_ocupacion_bloques($db, $equipoId, min($fechas), max($fechas), $excluir);
    $llenos = [];
    foreach ($dias as $d) {
        $o = $oc[$d['fecha']] ?? ['am' => 0.0, 'pm' => 0.0];
        $bloques = [];
        foreach (['am', 'pm'] as $b) {
            $pide = (float)($d[$b] ?? 0);
            if ($pide > 0 && $o[$b] + $pide > $cupo + REG_EPS) $bloques[] = $b;
        }
        if ($bloques) {
            $llenos[] = ['fecha' => $d['fecha'], 'bloques' => $bloques, 'ocupado' => max(array_map(fn($b) => $o[$b], $bloques)),
                         'ocupado_am' => $o['am'], 'ocupado_pm' => $o['pm'], 'cupo' => $cupo, 'consumo' => (float)$d['consumo']];
        }
    }
    return $llenos;
}

function reg_equipo(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM equipos WHERE id=?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) throw new ApiError('El equipo no existe.', 422);
    return $e;
}

function reg_unidades_turno(PDO $db): array
{
    $u = [];
    foreach ($db->query('SELECT id FROM equipos WHERE tiene_turnantes=1 AND activo=1') as $r) $u[(int)$r['id']] = true;
    return $u;
}

/**
 * Crea (o simula con $dry=true) una solicitud. $meta: ip, ua, cfg[].
 * Con $dry devuelve el resumen sin escribir nada.
 */
function reg_crear(PDO $db, array $in, array $meta = [], bool $dry = false): array
{
    $fn = function () use ($db, $in, $meta, $dry): array {
        $cfg = $meta['cfg'] ?? [];
        $p = reg_persona($db, $in);
        $t = reg_tipo($db, (string)($in['tipo'] ?? ''));
        $tipo = $t['sigla'];
        if ($t['lo_pide'] === 'coordinacion' && empty($meta['por_coordinacion'])) {
            throw new ApiError('«' . $t['nombre'] . '» lo registra coordinación.', 403, ['codigo' => 'solo_coordinacion']);
        }
        $hoy = Reloj::hoy();
        $extra = ['cambio_con' => null, 'cambio_con_id' => null, 'devolucion_turno_id' => null, 'devolucion_fecha' => null, 'devolucion_turno' => null];
        $rangoSaldo = null;
        $omitidos = [];
        $equipo = null;

        if ($p['jornada'] === 'diurno') {
            $equipo = reg_equipo($db, (int)$p['equipo_id']);
            if (!$equipo['tiene_diurnos']) throw new ApiError('Ese equipo no tiene personal diurno.', 422);
            if (!fecha_ok($in['fecha_inicio'] ?? null)) throw new ApiError('Indica la fecha.', 422);
            $fi = $in['fecha_inicio'];
            $ff = fecha_ok($in['fecha_fin'] ?? null) ? $in['fecha_fin'] : $fi;
            $calc = reg_dias_diurno($t, $fi, $ff, (string)($in['fraccion'] ?? 'completo'), $p['calidad'],
                                    reg_feriados($db), (int)($cfg['fl_largo_dias_habiles'] ?? 5));
            $dias = $calc['dias'];
            $omitidos = $calc['omitidos'];
            foreach ($dias as &$d) $d['equipo_id'] = (int)$p['equipo_id'];
            unset($d);
        } else {
            if ($t['lo_pide'] === 'coordinacion' && !$t['exige_reemplazo'] && !$t['es_cambio_turno'] && $t['rango'] === 'rango' && fecha_ok($in['fecha_inicio'] ?? null)) {
                // Turnante con un tipo de rango sin reemplazo (p. ej. licencia): un día por fecha, sin turno (SPEC-v2 §4.6).
                $ff = fecha_ok($in['fecha_fin'] ?? null) ? $in['fecha_fin'] : $in['fecha_inicio'];
                if ($ff < $in['fecha_inicio']) throw new ApiError('La fecha final es anterior a la inicial.', 422);
                $dias = array_map(fn($f) => ['fecha' => $f, 'consumo' => 0.0, 'am' => 0.0, 'pm' => 0.0, 'fraccion' => 'completo', 'turno' => null,
                                             'unidad_turno_id' => null, 'turno_id' => null, 'reemplazo_id' => null, 'reemplazo_nombre' => null], rango_fechas($in['fecha_inicio'], $ff, 62));
            } else {
                $dias = reg_dias_turnos($db, $in, $t, $p['funcionario_id'], $extra);
                // FL de turnante: el saldo cuenta los días hábiles del rango pedido, no solo los días con turno (§12.1).
                if ($t['rango'] === 'rango' && !$t['es_cambio_turno']) $rangoSaldo = [$in['fecha_inicio'], fecha_ok($in['fecha_fin'] ?? null) ? $in['fecha_fin'] : $in['fecha_inicio']];
            }
            foreach ($dias as &$d) $d['equipo_id'] = null;
            unset($d);
        }

        $fechas = array_column($dias, 'fecha');
        $primera = min($fechas);
        $ultima = max($fechas);
        // Coordinación puede registrar licencias o solicitudes pasadas «en nombre de» (SPEC-v2 §4.8).
        if ($primera < $hoy && empty($meta['por_coordinacion'])) throw new ApiError('No se pueden pedir fechas pasadas.', 422);
        if (!empty($cfg['anticipacion_min_dias'])) {
            $dif = (int)(new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($primera))->days;
            if ($dif < (int)$cfg['anticipacion_min_dias']) throw new ApiError('Debes pedir con al menos ' . (int)$cfg['anticipacion_min_dias'] . ' días de anticipación.', 422);
        }
        if (!empty($cfg['horizonte_max_dias'])) {
            $dif = (int)(new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($ultima))->days;
            if ($dif > (int)$cfg['horizonte_max_dias']) throw new ApiError('Solo se puede pedir con hasta ' . (int)$cfg['horizonte_max_dias'] . ' días de anticipación.', 422);
        }

        reg_superposicion($db, $p['rut'], $dias);

        $llenos = [];
        if ($equipo) $llenos = reg_dias_llenos($db, (int)$equipo['id'], $dias, (float)$equipo['cupo_diurno']);
        $consumoTotal = array_sum(array_column($dias, 'consumo'));
        // Saldo (SPEC-v2 §4.5): se calcula dentro de la misma transacción que el cupo.
        $usoSaldo = saldo_requerido($t, $dias, reg_feriados($db), $rangoSaldo);
        $revSaldo = saldo_revisar($db, $p['funcionario_id'], $t['saldo'], $usoSaldo);

        if ($dry) {
            // Sin datos de la persona: sin login, cualquiera podría simular a nombre de otro (bug N2).
            return ['dias' => array_map(fn($d) => ['fecha' => $d['fecha'], 'consumo' => $d['consumo'], 'am' => $d['am'], 'pm' => $d['pm'], 'fraccion' => $d['fraccion']], $dias),
                    'omitidos' => $omitidos, 'habiles' => count($dias), 'consumo_total' => $consumoTotal,
                    'dias_llenos' => $llenos, 'cupo' => $equipo ? (float)$equipo['cupo_diurno'] : null, 'saldo' => $revSaldo];
        }

        saldo_exigir($revSaldo);   // se bloquea si no alcanza (antes que el sobre cupo: eso no lo arregla)
        $justif = limpiar($in['justificacion'] ?? '', 1000);
        if ($llenos) {
            if (empty($in['acepta_sobre_cupo'])) {
                throw new ApiError('Hay días sin cupo disponible.', 409, ['codigo' => 'sin_cupo', 'dias_llenos' => $llenos]);
            }
            if ($justif === '') throw new ApiError('Para enviarla sobre cupo debes escribir una justificación.', 422, ['codigo' => 'falta_justificacion']);
        }

        $anio = substr($hoy, 0, 4);
        $st = $db->prepare('SELECT COALESCE(MAX(CAST(substr(codigo,10) AS INTEGER)),0) FROM solicitudes WHERE codigo LIKE ?');
        $st->execute(["AUS-$anio-%"]);
        $codigo = sprintf('AUS-%s-%04d', $anio, (int)$st->fetchColumn() + 1);
        $d0 = $dias[0];
        $nomb = $p['nombre'];

        $db->prepare('INSERT INTO solicitudes
            (codigo,funcionario_id,nombre,nombre_norm,rut,jornada,equipo_id,calidad,tipo,fecha_inicio,fecha_fin,fraccion,turno,
             unidad_turno_id,reemplazo_nombre,cambio_con,devolucion_fecha,devolucion_turno,comentario,sobre_cupo,
             justificacion_sobre_cupo,estado,fuera_de_nomina,creado_en,ip,user_agent,creada_por,cambio_con_id,devolucion_turno_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$codigo, $p['funcionario_id'], $nomb, texto_norm($nomb), $p['rut'], $p['jornada'], $p['equipo_id'],
                      $p['calidad'], $tipo, $primera, $ultima, $d0['fraccion'], $d0['turno'], $d0['unidad_turno_id'],
                      $d0['reemplazo_nombre'], $extra['cambio_con'], $extra['devolucion_fecha'], $extra['devolucion_turno'],
                      limpiar($in['comentario'] ?? '', 500) ?: null, $llenos ? 1 : 0, $llenos ? $justif : null,
                      'pendiente', 0, Reloj::stamp(), $meta['ip'] ?? '', mb_substr((string)($meta['ua'] ?? ''), 0, 300),
                      $meta['creada_por'] ?? $p['funcionario_id'], $extra['cambio_con_id'], $extra['devolucion_turno_id']]);
        $id = (int)$db->lastInsertId();

        $ins = $db->prepare('INSERT INTO solicitud_dias (solicitud_id,fecha,equipo_id,consumo,am,pm,fraccion,turno,unidad_turno_id,turno_id,reemplazo_id,reemplazo_nombre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($dias as $d) {
            $ins->execute([$id, $d['fecha'], $d['equipo_id'], $d['consumo'], $d['am'], $d['pm'], $d['fraccion'], $d['turno'], $d['unidad_turno_id'], $d['turno_id'] ?? null, $d['reemplazo_id'] ?? null, $d['reemplazo_nombre']]);
        }
        saldo_registrar_uso($db, $id, $t['saldo'], $usoSaldo);
        // Los tipos que registra coordinación (licencia) nacen autorizados, firmados por quien los registró (§12.5).
        $estado = 'pendiente';
        if ($t['lo_pide'] === 'coordinacion' && !empty($meta['por_coordinacion'])) {
            $estado = 'autorizada';
            $porId = isset($meta['creada_por']) ? (int)$meta['creada_por'] : null;
            $db->prepare("UPDATE solicitudes SET estado='autorizada', resuelta_por_id=?, resuelto_por=(SELECT nombre FROM funcionarios WHERE id=?), resuelto_en=? WHERE id=?")
               ->execute([$porId, $porId, Reloj::stamp(), $id]);
        }
        $porOtro = isset($meta['creada_por']) && (int)$meta['creada_por'] !== $p['funcionario_id'];
        auditar($db, 'crear', $id, "$codigo $tipo {$primera}→{$ultima}" . ($llenos ? ' SOBRE CUPO' : '') . ($porOtro ? ' (registrada por coordinación)' : ''),
                $meta['ip'] ?? '', $meta['creada_por'] ?? $p['funcionario_id']);
        notificar('solicitud_creada', ['id' => $id, 'codigo' => $codigo]);

        return ['id' => $id, 'codigo' => $codigo, 'estado' => $estado, 'sobre_cupo' => (bool)$llenos,
                'habiles' => count($dias), 'omitidos' => $omitidos, 'consumo_total' => $consumoTotal];
    };
    return $dry ? $fn() : tx($db, $fn);
}

/** $por: nombre de quien resuelve (se muestra); $porId: su funcionario_id (cuenta con sesión). */
function reg_resolver(PDO $db, int $id, string $accion, string $motivo, string $por, string $ip = '', ?int $porId = null): array
{
    return tx($db, function () use ($db, $id, $accion, $motivo, $por, $ip, $porId) {
        $s = reg_sol($db, $id);
        $por = limpiar($por, 60);
        $motivo = limpiar($motivo, 500);
        if ($por === '') throw new ApiError('Indica quién resuelve (iniciales o nombre).', 422);
        if ($s['estado'] !== 'pendiente') throw new ApiError('La solicitud ya no está pendiente (estado: ' . $s['estado'] . ').', 409);
        if ($accion === 'autorizar') $nuevo = 'autorizada';
        elseif ($accion === 'rechazar') {
            if ($motivo === '') throw new ApiError('Para rechazar debes escribir el motivo.', 422);
            $nuevo = 'rechazada';
        } else throw new ApiError('Acción inválida.', 422);
        $db->prepare('UPDATE solicitudes SET estado=?, motivo_resolucion=?, resuelto_por=?, resuelta_por_id=?, resuelto_en=? WHERE id=?')
           ->execute([$nuevo, $motivo ?: null, $por, $porId, Reloj::stamp(), $id]);
        auditar($db, $nuevo === 'autorizada' ? 'autorizar' : 'rechazar', $id, "{$s['codigo']} por $por. $motivo", $ip, $porId);
        notificar('solicitud_resuelta', ['id' => $id, 'codigo' => $s['codigo'], 'estado' => $nuevo]);
        return ['estado' => $nuevo];
    });
}

/** autorizada → rechazada (motivo obligatorio) | rechazada → autorizada (re-verifica cupo). */
function reg_revertir(PDO $db, int $id, string $motivo, string $por, bool $aceptaSobre = false, string $justif = '', string $ip = '', ?int $porId = null): array
{
    return tx($db, function () use ($db, $id, $motivo, $por, $aceptaSobre, $justif, $ip, $porId) {
        $s = reg_sol($db, $id);
        $por = limpiar($por, 60);
        $motivo = limpiar($motivo, 500);
        $justif = limpiar($justif, 1000);
        if ($por === '') throw new ApiError('Indica quién resuelve (iniciales o nombre).', 422);

        if ($s['estado'] === 'autorizada') {
            if ($motivo === '') throw new ApiError('Para rechazar debes escribir el motivo.', 422);
            $db->prepare("UPDATE solicitudes SET estado='rechazada', motivo_resolucion=?, resuelto_por=?, resuelta_por_id=?, resuelto_en=? WHERE id=?")
               ->execute([$motivo, $por, $porId, Reloj::stamp(), $id]);
            auditar($db, 'revertir_a_rechazada', $id, "{$s['codigo']} por $por. $motivo", $ip, $porId);
            notificar('solicitud_resuelta', ['id' => $id, 'codigo' => $s['codigo'], 'estado' => 'rechazada']);
            return ['estado' => 'rechazada'];
        }
        if ($s['estado'] !== 'rechazada') throw new ApiError('Solo se pueden revertir solicitudes autorizadas o rechazadas.', 409);

        $dias = reg_sol_dias($db, $id);
        reg_superposicion($db, $s['rut'], $dias, $id);
        $llenos = [];
        if ($s['jornada'] === 'diurno' && $s['equipo_id']) {
            $eq = reg_equipo($db, (int)$s['equipo_id']);
            $llenos = reg_dias_llenos($db, (int)$eq['id'], $dias, (float)$eq['cupo_diurno'], $id);
        }
        $uso = saldo_uso_guardado($db, $id);   // al volver a autorizar se re-verifica el saldo (sin contarse a sí misma)
        saldo_exigir(saldo_revisar($db, (int)$s['funcionario_id'], $uso['saldo'], $uso['req'], $id));
        if ($llenos) {
            if (!$aceptaSobre) throw new ApiError('Ya no hay cupo en algunos días. Para autorizarla debe marcarse sobre cupo con justificación.', 409, ['codigo' => 'sin_cupo', 'dias_llenos' => $llenos]);
            if ($justif === '') throw new ApiError('Escribe la justificación del sobre cupo.', 422, ['codigo' => 'falta_justificacion']);
        }
        $db->prepare("UPDATE solicitudes SET estado='autorizada', sobre_cupo=?, justificacion_sobre_cupo=COALESCE(?, justificacion_sobre_cupo), motivo_resolucion=?, resuelto_por=?, resuelta_por_id=?, resuelto_en=? WHERE id=?")
           ->execute([$llenos ? 1 : 0, $llenos ? $justif : null, $motivo ?: null, $por, $porId, Reloj::stamp(), $id]);
        auditar($db, 'revertir_a_autorizada', $id, "{$s['codigo']} por $por" . ($llenos ? ' SOBRE CUPO' : ''), $ip, $porId);
        notificar('solicitud_resuelta', ['id' => $id, 'codigo' => $s['codigo'], 'estado' => 'autorizada']);
        return ['estado' => 'autorizada', 'sobre_cupo' => (bool)$llenos];
    });
}

/**
 * Anula. La dueña de la solicitud (su sesión: $actorId) puede hacerlo hasta las 23:59 del día anterior a la
 * primera fecha; coordinación ($esCoord), siempre. Nadie anula la solicitud de otra persona.
 */
function reg_anular(PDO $db, int $id, ?int $actorId, bool $esCoord, string $por = '', string $ip = ''): array
{
    return tx($db, function () use ($db, $id, $actorId, $esCoord, $por, $ip) {
        $s = reg_sol($db, $id);
        if (!in_array($s['estado'], ['pendiente', 'autorizada'], true)) {
            throw new ApiError('Esta solicitud ya está ' . $s['estado'] . ' y no se puede anular.', 409);
        }
        if (!$esCoord) {
            if ($actorId === null || (int)$s['funcionario_id'] !== $actorId) {
                throw new ApiError('Solo puedes anular tus propias solicitudes.', 403, ['codigo' => 'no_es_tuya']);
            }
            if (Reloj::hoy() >= $s['fecha_inicio']) {
                throw new ApiError('Ya no se puede anular: el plazo terminó a las 23:59 del día anterior al primer día. Habla con coordinación.', 409);
            }
        }
        $db->prepare("UPDATE solicitudes SET estado='anulada', anulado_en=?, resuelto_por=COALESCE(?, resuelto_por) WHERE id=?")
           ->execute([Reloj::stamp(), $esCoord ? (limpiar($por, 60) ?: null) : null, $id]);
        auditar($db, $esCoord ? 'anular_coord' : 'anular', $id, $s['codigo'] . ($esCoord ? " por $por" : ''), $ip, $actorId);
        return ['estado' => 'anulada'];
    });
}
