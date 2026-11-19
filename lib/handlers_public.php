<?php
declare(strict_types=1);

function h_config(PDO $db, array $p, array $in): array
{
    $eq = $db->query('SELECT id,nombre,tiene_diurnos,tiene_turnantes,cupo_diurno,orden FROM equipos WHERE activo=1 ORDER BY orden,nombre')->fetchAll();
    foreach ($eq as &$e) {
        $e['id'] = (int)$e['id'];
        $e['tiene_diurnos'] = (bool)$e['tiene_diurnos'];
        $e['tiene_turnantes'] = (bool)$e['tiene_turnantes'];
        $e['cupo_diurno'] = (float)$e['cupo_diurno'];
    }
    return ['titulo' => cfg('titulo'), 'logo' => cfg('logo'), 'hoy' => Reloj::hoy(), 'equipos' => $eq, 'tipos' => tipos_listar($db, true)];
}

/** Tipos de ausentismo con su configuración (para el formulario y la vista de coordinación). */
function tipos_listar(PDO $db, bool $soloActivos): array
{
    $r = $db->query('SELECT * FROM tipos_ausentismo' . ($soloActivos ? ' WHERE activo=1' : '') . ' ORDER BY orden, nombre')->fetchAll();
    foreach ($r as &$t) {
        $t['id'] = (int)$t['id']; $t['orden'] = (int)$t['orden'];
        foreach (['consume_cupo', 'medio_dia', 'exige_reemplazo', 'es_cambio_turno', 'precargado', 'activo'] as $k) $t[$k] = (bool)$t[$k];
    }
    return $r;
}

function h_nomina_buscar(PDO $db, array $p, array $in): array
{
    $q = texto_norm((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) return ['resultados' => []];
    $where = [];
    $par = [];
    foreach (array_slice(explode(' ', $q), 0, 5) as $t) {
        $where[] = "f.nombre_norm LIKE ? ESCAPE '\\'";
        $par[] = '%' . addcslashes($t, '%_\\') . '%';
    }
    $st = $db->prepare('SELECT f.id, f.nombre, f.jornada, f.equipo_id, e.nombre AS equipo
        FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id
        WHERE f.activo=1 AND ' . implode(' AND ', $where) . ' ORDER BY f.nombre LIMIT 12');
    $st->execute($par);
    $res = $st->fetchAll();
    foreach ($res as &$r) { $r['id'] = (int)$r['id']; $r['equipo_id'] = $r['equipo_id'] !== null ? (int)$r['equipo_id'] : null; }
    return ['resultados' => $res];
}

/** Ausencias de un mes. Público: sin RUT/calidad/IP/justificaciones. Coordinación: todo. */
function cal_datos(PDO $db, string $mes, bool $coord): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) throw new ApiError('Mes inválido.', 422);
    $desde = $mes . '-01';
    $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');

    $cols = 'sd.fecha, sd.consumo, sd.fraccion, sd.turno, sd.unidad_turno_id AS unidad_id, sd.reemplazo_nombre AS reemplazo,
             s.id AS solicitud_id, s.nombre, s.jornada, s.equipo_id, s.tipo, t.etiqueta_publica AS etiqueta, s.estado, s.cambio_con';
    if ($coord) $cols .= ', s.codigo, s.rut, s.calidad, s.sobre_cupo, s.justificacion_sobre_cupo AS justificacion, s.comentario, s.fuera_de_nomina';
    $st = $db->prepare("SELECT $cols FROM solicitud_dias sd JOIN solicitudes s ON s.id=sd.solicitud_id LEFT JOIN tipos_ausentismo t ON t.sigla=s.tipo
        WHERE s.estado IN ('pendiente','autorizada') AND sd.fecha BETWEEN ? AND ? ORDER BY sd.fecha, s.nombre");
    $st->execute([$desde, $hasta]);
    $aus = $st->fetchAll();
    foreach ($aus as &$a) {
        $a['consumo'] = (float)$a['consumo'];
        $a['solicitud_id'] = (int)$a['solicitud_id'];
        $a['equipo_id'] = $a['equipo_id'] !== null ? (int)$a['equipo_id'] : null;
        $a['unidad_id'] = $a['unidad_id'] !== null ? (int)$a['unidad_id'] : null;
        if ($coord) { $a['sobre_cupo'] = (bool)$a['sobre_cupo']; $a['fuera_de_nomina'] = (bool)$a['fuera_de_nomina']; }
    }
    unset($a);

    $fer = [];
    $f = $db->prepare('SELECT fecha,nombre FROM feriados WHERE fecha BETWEEN ? AND ?');
    $f->execute([$desde, $hasta]);
    foreach ($f->fetchAll() as $r) $fer[$r['fecha']] = $r['nombre'];

    $eq = $db->query('SELECT id,nombre,tiene_diurnos,tiene_turnantes,cupo_diurno,orden FROM equipos WHERE activo=1 ORDER BY orden,nombre')->fetchAll();
    foreach ($eq as &$e) {
        $e['id'] = (int)$e['id'];
        $e['tiene_diurnos'] = (bool)$e['tiene_diurnos'];
        $e['tiene_turnantes'] = (bool)$e['tiene_turnantes'];
        $e['cupo_diurno'] = (float)$e['cupo_diurno'];
    }
    return ['mes' => $mes, 'desde' => $desde, 'hasta' => $hasta, 'hoy' => Reloj::hoy(),
            'equipos' => $eq, 'feriados' => (object)$fer, 'ausencias' => $aus];
}

function h_calendario(PDO $db, array $p, array $in): array
{
    return cal_datos($db, (string)($_GET['mes'] ?? substr(Reloj::hoy(), 0, 7)), false);
}

function h_simular(PDO $db, array $p, array $in): array
{
    $in['funcionario_id'] = yo();   // siempre la persona de la sesión
    return reg_crear($db, $in, ['cfg' => cfg()], true);
}

/** Límite de envíos por cuenta (no por IP: todo el hospital comparte una). */
function limite_envios(PDO $db, int $actor): string
{
    $clave = 'crear:f' . $actor;
    $max = (int)cfg('limite_envios_hora');
    if ($max > 0 && rate_contar($db, $clave, 3600) >= $max) {
        throw new ApiError('Has enviado muchas solicitudes en poco tiempo. Intenta más tarde o habla con coordinación.', 429);
    }
    return $clave;
}

function h_crear(PDO $db, array $p, array $in): array
{
    $yo = yo();
    $clave = limite_envios($db, $yo);
    $in['funcionario_id'] = $yo;    // se ignora cualquier persona indicada en el cuerpo
    $r = reg_crear($db, $in, ['ip' => ip_cliente(), 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'cfg' => cfg(), 'creada_por' => $yo]);
    rate_registrar($db, $clave);
    return ['ok' => true] + $r;
}

function sol_publica(array $s): array
{
    return [
        'id' => (int)$s['id'], 'codigo' => $s['codigo'], 'nombre' => $s['nombre'], 'tipo' => $s['tipo'], 'etiqueta' => $s['etiqueta'] ?? null,
        'fecha_inicio' => $s['fecha_inicio'], 'fecha_fin' => $s['fecha_fin'], 'fraccion' => $s['fraccion'],
        'turno' => $s['turno'], 'estado' => $s['estado'],
        'motivo' => $s['estado'] === 'rechazada' ? $s['motivo_resolucion'] : null,
        'anulable' => in_array($s['estado'], ['pendiente', 'autorizada'], true) && Reloj::hoy() < $s['fecha_inicio'],
        'sobre_cupo' => (bool)$s['sobre_cupo'],
    ];
}

/** «Mis solicitudes»: solo las de la persona con sesión (opcionalmente filtradas por código). */
function h_solicitudes_listar(PDO $db, array $p, array $in): array
{
    $yo = yo();
    $codigo = strtoupper(limpiar($_GET['codigo'] ?? '', 30));
    $sql = 'SELECT s.*, t.etiqueta_publica AS etiqueta FROM solicitudes s LEFT JOIN tipos_ausentismo t ON t.sigla=s.tipo
            WHERE s.funcionario_id=?' . ($codigo !== '' ? ' AND s.codigo=?' : '') . ' ORDER BY s.fecha_inicio DESC, s.id DESC LIMIT 200';
    $st = $db->prepare($sql);
    $st->execute($codigo !== '' ? [$yo, $codigo] : [$yo]);
    return ['solicitudes' => array_map('sol_publica', $st->fetchAll())];
}

function h_anular(PDO $db, array $p, array $in): array
{
    return reg_anular($db, (int)$p[1], yo(), false, '', ip_cliente());
}
