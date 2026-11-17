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

/** Resuelve a la persona que pide: nómina (funcionario_id o RUT conocido) o "fuera de nómina". */
function reg_persona(PDO $db, array $in): array
{
    $sel = 'SELECT f.*, e.nombre AS equipo_nombre FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id';
    $f = null;
    if (!empty($in['funcionario_id'])) {
        $st = $db->prepare($sel . ' WHERE f.id=? AND f.activo=1');
        $st->execute([(int)$in['funcionario_id']]);
        $f = $st->fetch();
        if (!$f) throw new ApiError('No encontramos a esa persona en la nómina.', 422);
    } else {
        $rut = rut_normalizar((string)($in['rut'] ?? ''));
        if ($rut === null) throw new ApiError('El RUT no es válido. Revisa el dígito verificador.', 422);
        $st = $db->prepare($sel . ' WHERE f.rut=? AND f.activo=1');
        $st->execute([$rut]);
        $f = $st->fetch();
        if (!$f) {
            $nombre = limpiar($in['nombre'] ?? '', 120);
            if (mb_strlen($nombre) < 3) throw new ApiError('Escribe tu nombre completo.', 422);
            $jornada = $in['jornada'] ?? '';
            if (!es_en($jornada, ['diurno', 'turnante'])) throw new ApiError('Indica tu jornada.', 422);
            $calidad = $in['calidad'] ?? '';
            if (!es_en($calidad, ['titular', 'contrata', 'honorarios'])) throw new ApiError('Indica tu calidad jurídica.', 422);
            $equipo = !empty($in['equipo_id']) ? (int)$in['equipo_id'] : null;
            if ($jornada === 'diurno' && $equipo === null) throw new ApiError('Indica tu equipo.', 422);
            if ($equipo !== null) {
                $st = $db->prepare('SELECT 1 FROM equipos WHERE id=? AND activo=1');
                $st->execute([$equipo]);
                if (!$st->fetchColumn()) throw new ApiError('El equipo indicado no existe.', 422);
            }
            return ['funcionario_id' => null, 'nombre' => $nombre, 'rut' => $rut, 'jornada' => $jornada,
                    'equipo_id' => $equipo, 'calidad' => $calidad, 'fuera' => 1, 'equipo_nombre' => null];
        }
    }
    if ($f['jornada'] === 'diurno' && !$f['equipo_id']) {
        throw new ApiError('Esta persona no tiene equipo asignado en la nómina. Avisa a coordinación.', 422);
    }
    return ['funcionario_id' => (int)$f['id'], 'nombre' => $f['nombre'], 'rut' => $f['rut'], 'jornada' => $f['jornada'],
            'equipo_id' => $f['equipo_id'] !== null ? (int)$f['equipo_id'] : null, 'calidad' => $f['calidad'],
            'fuera' => 0, 'equipo_nombre' => $f['equipo_nombre']];
}

/** Días y consumo de cupo para un DIURNO. Omite fines de semana y feriados. */
function reg_dias_diurno(string $tipo, string $fi, string $ff, string $fraccion, string $calidad, array $feriados, int $umbral = 5): array
{
    if ($tipo === 'ADM') {
        $ff = $fi; // el administrativo es de un solo día
        if (!in_array($fraccion, REG_FRACCIONES_DIURNO, true)) throw new ApiError('Elige día completo, mañana o tarde.', 422);
    } elseif ($tipo === 'FL') {
        $fraccion = 'completo';
    } else {
        throw new ApiError('El cambio de turno solo está disponible para turnantes.', 422);
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

    if ($tipo === 'ADM') $consumo = $fraccion === 'completo' ? 1.0 : 0.5;
    else $consumo = ($calidad !== 'honorarios' && $n >= $umbral) ? 0.0 : 1.0;

    $dias = [];
    foreach ($habiles as $f) {
        $dias[] = ['fecha' => $f, 'consumo' => $consumo, 'fraccion' => $fraccion,
                   'turno' => null, 'unidad_turno_id' => null, 'reemplazo_nombre' => null];
    }
    return ['dias' => $dias, 'omitidos' => $omitidos, 'habiles' => $n];
}

/** Días de un TURNANTE (nunca consumen cupo). $extra recibe los campos de cambio de turno. */
function reg_dias_turnante(array $in, string $tipo, array $unidades, array &$extra): array
{
    $raw = $in['dias'] ?? [];
    if (!is_array($raw) || count($raw) === 0) throw new ApiError('Indica al menos un día y turno.', 422);
    if (count($raw) > 62) throw new ApiError('Demasiados días en una sola solicitud (máximo 62).', 422);
    if ($tipo !== 'FL' && count($raw) !== 1) throw new ApiError('Este tipo de solicitud es de un solo turno.', 422);

    $fraccion = 'completo';
    if ($tipo === 'ADM') {
        $fraccion = (string)($in['fraccion'] ?? 'completo');
        if (!in_array($fraccion, REG_FRACCIONES_TURNANTE, true)) throw new ApiError('Elige turno completo o una mitad del turno.', 422);
    }

    $dias = [];
    $vistos = [];
    foreach ($raw as $d) {
        if (!is_array($d) || !fecha_ok($d['fecha'] ?? null)) throw new ApiError('Hay una fecha inválida.', 422);
        $turno = $d['turno'] ?? '';
        if (!es_en($turno, ['largo', 'noche'])) throw new ApiError('Indica el turno (largo o noche) de cada fecha.', 422);
        $unidad = (int)($d['unidad_id'] ?? 0);
        if (!isset($unidades[$unidad])) throw new ApiError('Indica la unidad del turno de cada fecha.', 422);
        $clave = $d['fecha'] . '|' . $turno;
        if (isset($vistos[$clave])) throw new ApiError('Hay un turno repetido el ' . fecha_cl($d['fecha']) . '.', 422);
        $vistos[$clave] = true;
        $reemplazo = null;
        if ($tipo !== 'CT') {
            $reemplazo = limpiar($d['reemplazo'] ?? '', 120);
            if ($reemplazo === '') throw new ApiError('Indica quién cubre tu turno del ' . fecha_cl($d['fecha']) . '.', 422);
        }
        $dias[] = ['fecha' => $d['fecha'], 'consumo' => 0.0, 'fraccion' => $fraccion, 'turno' => $turno,
                   'unidad_turno_id' => $unidad, 'reemplazo_nombre' => $reemplazo];
    }
    usort($dias, fn($a, $b) => [$a['fecha'], $a['turno']] <=> [$b['fecha'], $b['turno']]);

    if ($tipo === 'CT') {
        $extra['cambio_con'] = limpiar($in['cambio_con'] ?? '', 120);
        if ($extra['cambio_con'] === '') throw new ApiError('Indica con quién cambias el turno.', 422);
        $df = $in['devolucion_fecha'] ?? null;
        $dt = $in['devolucion_turno'] ?? null;
        if (($df !== null && $df !== '') || ($dt !== null && $dt !== '')) {
            if (!fecha_ok($df) || !es_en($dt, ['largo', 'noche'])) {
                throw new ApiError('Para la devolución indica fecha y turno, o déjala en blanco.', 422);
            }
            $extra['devolucion_fecha'] = $df;
            $extra['devolucion_turno'] = $dt;
        }
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

/** Ocupación de cupo (suma de consumo de pendientes + autorizadas de diurnos) por fecha. */
function reg_ocupacion(PDO $db, int $equipoId, string $desde, string $hasta, ?int $excluir = null): array
{
    $st = $db->prepare("SELECT sd.fecha, SUM(sd.consumo) AS oc
        FROM solicitud_dias sd JOIN solicitudes s ON s.id = sd.solicitud_id
        WHERE s.estado IN ('pendiente','autorizada') AND s.jornada = 'diurno'
          AND sd.equipo_id = ? AND s.id <> ? AND sd.fecha BETWEEN ? AND ?
        GROUP BY sd.fecha");
    $st->execute([$equipoId, $excluir ?? 0, $desde, $hasta]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['fecha']] = (float)$r['oc'];
    return $out;
}

function reg_dias_llenos(PDO $db, int $equipoId, array $dias, float $cupo, ?int $excluir = null): array
{
    if (!$dias) return [];
    $fechas = array_column($dias, 'fecha');
    $oc = reg_ocupacion($db, $equipoId, min($fechas), max($fechas), $excluir);
    $llenos = [];
    foreach ($dias as $d) {
        if ($d['consumo'] <= 0) continue;
        $o = $oc[$d['fecha']] ?? 0.0;
        if ($o + $d['consumo'] > $cupo + REG_EPS) {
            $llenos[] = ['fecha' => $d['fecha'], 'ocupado' => $o, 'cupo' => $cupo, 'consumo' => (float)$d['consumo']];
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
        $tipo = $in['tipo'] ?? '';
        if (!es_en($tipo, ['FL', 'ADM', 'CT'])) throw new ApiError('Elige el tipo de ausentismo.', 422);
        $hoy = Reloj::hoy();
        $extra = ['cambio_con' => null, 'devolucion_fecha' => null, 'devolucion_turno' => null];
        $omitidos = [];
        $equipo = null;

        if ($p['jornada'] === 'diurno') {
            $equipo = reg_equipo($db, (int)$p['equipo_id']);
            if (!$equipo['tiene_diurnos']) throw new ApiError('Ese equipo no tiene personal diurno.', 422);
            if (!fecha_ok($in['fecha_inicio'] ?? null)) throw new ApiError('Indica la fecha.', 422);
            $fi = $in['fecha_inicio'];
            $ff = fecha_ok($in['fecha_fin'] ?? null) ? $in['fecha_fin'] : $fi;
            $calc = reg_dias_diurno($tipo, $fi, $ff, (string)($in['fraccion'] ?? 'completo'), $p['calidad'],
                                    reg_feriados($db), (int)($cfg['fl_largo_dias_habiles'] ?? 5));
            $dias = $calc['dias'];
            $omitidos = $calc['omitidos'];
            foreach ($dias as &$d) $d['equipo_id'] = (int)$p['equipo_id'];
            unset($d);
        } else {
            $dias = reg_dias_turnante($in, $tipo, reg_unidades_turno($db), $extra);
            foreach ($dias as &$d) $d['equipo_id'] = null;
            unset($d);
        }

        $fechas = array_column($dias, 'fecha');
        $primera = min($fechas);
        $ultima = max($fechas);
        if ($primera < $hoy) throw new ApiError('No se pueden pedir fechas pasadas.', 422);
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

        if ($dry) {
            return ['persona' => ['nombre' => $p['nombre'], 'jornada' => $p['jornada'], 'equipo_id' => $p['equipo_id'],
                                  'equipo' => $p['equipo_nombre'] ?? ($equipo['nombre'] ?? null), 'calidad' => $p['calidad'],
                                  'fuera_de_nomina' => (bool)$p['fuera']],
                    'dias' => array_map(fn($d) => ['fecha' => $d['fecha'], 'consumo' => $d['consumo'], 'fraccion' => $d['fraccion']], $dias),
                    'omitidos' => $omitidos, 'habiles' => count($dias), 'consumo_total' => $consumoTotal,
                    'dias_llenos' => $llenos, 'cupo' => $equipo ? (float)$equipo['cupo_diurno'] : null];
        }

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
             justificacion_sobre_cupo,estado,fuera_de_nomina,creado_en,ip,user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$codigo, $p['funcionario_id'], $nomb, texto_norm($nomb), $p['rut'], $p['jornada'], $p['equipo_id'],
                      $p['calidad'], $tipo, $primera, $ultima, $d0['fraccion'], $d0['turno'], $d0['unidad_turno_id'],
                      $d0['reemplazo_nombre'], $extra['cambio_con'], $extra['devolucion_fecha'], $extra['devolucion_turno'],
                      limpiar($in['comentario'] ?? '', 500) ?: null, $llenos ? 1 : 0, $llenos ? $justif : null,
                      'pendiente', $p['fuera'], Reloj::stamp(), $meta['ip'] ?? '', mb_substr((string)($meta['ua'] ?? ''), 0, 300)]);
        $id = (int)$db->lastInsertId();

        $ins = $db->prepare('INSERT INTO solicitud_dias (solicitud_id,fecha,equipo_id,consumo,fraccion,turno,unidad_turno_id,reemplazo_nombre) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($dias as $d) {
            $ins->execute([$id, $d['fecha'], $d['equipo_id'], $d['consumo'], $d['fraccion'], $d['turno'], $d['unidad_turno_id'], $d['reemplazo_nombre']]);
        }
        auditar($db, 'crear', $id, "$codigo $tipo {$primera}→{$ultima}" . ($llenos ? ' SOBRE CUPO' : '') . ($p['fuera'] ? ' FUERA DE NÓMINA' : ''), $meta['ip'] ?? '');
        notificar('solicitud_creada', ['id' => $id, 'codigo' => $codigo]);

        return ['id' => $id, 'codigo' => $codigo, 'estado' => 'pendiente', 'sobre_cupo' => (bool)$llenos,
                'habiles' => count($dias), 'omitidos' => $omitidos, 'consumo_total' => $consumoTotal];
    };
    return $dry ? $fn() : tx($db, $fn);
}

function reg_resolver(PDO $db, int $id, string $accion, string $motivo, string $por, string $ip = ''): array
{
    return tx($db, function () use ($db, $id, $accion, $motivo, $por, $ip) {
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
        $db->prepare('UPDATE solicitudes SET estado=?, motivo_resolucion=?, resuelto_por=?, resuelto_en=? WHERE id=?')
           ->execute([$nuevo, $motivo ?: null, $por, Reloj::stamp(), $id]);
        auditar($db, $nuevo === 'autorizada' ? 'autorizar' : 'rechazar', $id, "{$s['codigo']} por $por. $motivo", $ip);
        notificar('solicitud_resuelta', ['id' => $id, 'codigo' => $s['codigo'], 'estado' => $nuevo]);
        return ['estado' => $nuevo];
    });
}

/** autorizada → rechazada (motivo obligatorio) | rechazada → autorizada (re-verifica cupo). */
function reg_revertir(PDO $db, int $id, string $motivo, string $por, bool $aceptaSobre = false, string $justif = '', string $ip = ''): array
{
    return tx($db, function () use ($db, $id, $motivo, $por, $aceptaSobre, $justif, $ip) {
        $s = reg_sol($db, $id);
        $por = limpiar($por, 60);
        $motivo = limpiar($motivo, 500);
        $justif = limpiar($justif, 1000);
        if ($por === '') throw new ApiError('Indica quién resuelve (iniciales o nombre).', 422);

        if ($s['estado'] === 'autorizada') {
            if ($motivo === '') throw new ApiError('Para rechazar debes escribir el motivo.', 422);
            $db->prepare("UPDATE solicitudes SET estado='rechazada', motivo_resolucion=?, resuelto_por=?, resuelto_en=? WHERE id=?")
               ->execute([$motivo, $por, Reloj::stamp(), $id]);
            auditar($db, 'revertir_a_rechazada', $id, "{$s['codigo']} por $por. $motivo", $ip);
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
        if ($llenos) {
            if (!$aceptaSobre) throw new ApiError('Ya no hay cupo en algunos días. Para autorizarla debe marcarse sobre cupo con justificación.', 409, ['codigo' => 'sin_cupo', 'dias_llenos' => $llenos]);
            if ($justif === '') throw new ApiError('Escribe la justificación del sobre cupo.', 422, ['codigo' => 'falta_justificacion']);
        }
        $db->prepare("UPDATE solicitudes SET estado='autorizada', sobre_cupo=?, justificacion_sobre_cupo=COALESCE(?, justificacion_sobre_cupo), motivo_resolucion=?, resuelto_por=?, resuelto_en=? WHERE id=?")
           ->execute([$llenos ? 1 : 0, $llenos ? $justif : null, $motivo ?: null, $por, Reloj::stamp(), $id]);
        auditar($db, 'revertir_a_autorizada', $id, "{$s['codigo']} por $por" . ($llenos ? ' SOBRE CUPO' : ''), $ip);
        notificar('solicitud_resuelta', ['id' => $id, 'codigo' => $s['codigo'], 'estado' => 'autorizada']);
        return ['estado' => 'autorizada', 'sobre_cupo' => (bool)$llenos];
    });
}

/** Anula. El funcionario debe dar su RUT y hacerlo hasta las 23:59 del día anterior a la primera fecha. */
function reg_anular(PDO $db, int $id, ?string $rut, bool $esCoord, string $por = '', string $ip = ''): array
{
    return tx($db, function () use ($db, $id, $rut, $esCoord, $por, $ip) {
        $s = reg_sol($db, $id);
        if (!in_array($s['estado'], ['pendiente', 'autorizada'], true)) {
            throw new ApiError('Esta solicitud ya está ' . $s['estado'] . ' y no se puede anular.', 409);
        }
        if (!$esCoord) {
            $r = rut_normalizar((string)$rut);
            if ($r === null || $r !== $s['rut']) throw new ApiError('El RUT no coincide con el de la solicitud.', 403);
            if (Reloj::hoy() >= $s['fecha_inicio']) {
                throw new ApiError('Ya no se puede anular: el plazo terminó a las 23:59 del día anterior al primer día. Habla con coordinación.', 409);
            }
        }
        $db->prepare("UPDATE solicitudes SET estado='anulada', anulado_en=?, resuelto_por=COALESCE(?, resuelto_por) WHERE id=?")
           ->execute([Reloj::stamp(), $esCoord ? (limpiar($por, 60) ?: null) : null, $id]);
        auditar($db, $esCoord ? 'anular_coord' : 'anular', $id, $s['codigo'] . ($esCoord ? " por $por" : ''), $ip);
        return ['estado' => 'anulada'];
    });
}
