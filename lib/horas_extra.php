<?php
declare(strict_types=1);

/**
 * Horas extra (SPEC-v2 §4.7). Portado de utils/extraHours.ts de la app anterior, con los feriados de la tabla
 * en vez de una lista fija. Festivo = sábado, domingo o feriado. La noche lleva la fecha en que empieza.
 * La app calcula y exporta; RR.HH. valida el pago.
 */

function es_festivo(string $f, array $feriados): bool
{
    return dia_semana($f) >= 6 || isset($feriados[$f]);
}

/**
 * Horas de un turno. $diurno: lo hace una persona diurna. $override: horas corregidas a mano (se reparten con las
 * mismas reglas). $mitad: 'am' | 'pm' si se cubre medio turno. Devuelve ['diurnas' => x, 'festivas' => y].
 */
function horas_turno(string $fecha, string $tipo, bool $diurno, ?float $override, ?string $mitad, array $feriados): array
{
    $hoyF = es_festivo($fecha, $feriados);
    $r = fn(float $d, float $f) => ['diurnas' => $d, 'festivas' => $f];
    if ($tipo === 'largo') {
        if ($override !== null) return $hoyF ? $r(0.0, $override) : $r($override, 0.0);
        if ($hoyF) return $r(0.0, $mitad ? 5.5 : 11.0);
        if ($diurno) {
            if ($mitad === 'am') return $r(0.0, 0.0);              // la mañana cae en su jornada habitual
            return $r(dia_semana($fecha) === 5 ? 4.0 : 3.0, 0.0);   // sale a las 16:00 el viernes y a las 17:00 de lunes a jueves
        }
        return $r($mitad ? 6.0 : 12.0, 0.0);
    }
    $sigF = es_festivo(fecha_add($fecha, 1), $feriados);
    [$d, $f] = match (true) {
        !$hoyF && !$sigF => [2.0, 10.0],   // 20:00-21:00 y 07:00-08:00 diurnas
        !$hoyF && $sigF => [1.0, 12.0],    // hacia festivo: 13 h
        $hoyF && !$sigF => [1.0, 11.0],    // desde festivo: 12 h
        default => [0.0, 13.0],            // entre festivos: todo festivo
    };
    if ($override !== null) {
        $tope = $hoyF && $sigF ? 0.0 : (($hoyF xor $sigF) ? 1.0 : 2.0);
        $d = min($tope, $override);
        $f = max(0.0, $override - $d);
    }
    if ($mitad) { $d /= 2; $f /= 2; }
    return $r($d, $f);
}

/**
 * Turnos extra de un mes: los cubiertos por un reemplazo marcado como extra y los cargados como extra (sin reemplazo).
 * Devuelve una fila por persona que hace las horas, con su detalle.
 */
function horas_extra_mes(PDO $db, string $mes): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) throw new ApiError('Mes inválido.', 422);
    $desde = $mes . '-01';
    $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
    $fer = reg_feriados($db);
    $st = $db->prepare("SELECT t.*, u.nombre AS unidad, ft.nombre AS titular,
            COALESCE(t.cubierto_por_id, t.titular_id) AS quien_id,
            (SELECT sd.fraccion FROM solicitud_dias sd WHERE sd.turno_id = t.id AND sd.solicitud_id = t.solicitud_id LIMIT 1) AS fraccion
        FROM turnos t JOIN equipos u ON u.id = t.unidad_id JOIN funcionarios ft ON ft.id = t.titular_id
        WHERE t.fecha BETWEEN ? AND ? AND ((t.cubierto_por_id IS NOT NULL AND t.cubierto_extra = 1) OR (t.cubierto_por_id IS NULL AND t.es_extra = 1 AND t.solicitud_id IS NULL))
        ORDER BY t.fecha, t.tipo");
    $st->execute([$desde, $hasta]);
    $personas = [];
    $q = $db->prepare('SELECT id, nombre, rut, jornada FROM funcionarios WHERE id=?');
    foreach ($st->fetchAll() as $t) {
        $id = (int)$t['quien_id'];
        if (!isset($personas[$id])) {
            $q->execute([$id]);
            $f = $q->fetch();
            $personas[$id] = ['funcionario_id' => $id, 'nombre' => $f['nombre'], 'rut' => $f['rut'], 'jornada' => $f['jornada'], 'diurnas' => 0.0, 'festivas' => 0.0, 'turnos' => []];
        }
        $mitad = match ($t['fraccion']) { 'primera_mitad' => 'am', 'segunda_mitad' => 'pm', default => null };
        $h = horas_turno($t['fecha'], $t['tipo'], $personas[$id]['jornada'] === 'diurno', $t['horas_override'] !== null ? (float)$t['horas_override'] : null, $mitad, $fer);
        $personas[$id]['diurnas'] += $h['diurnas'];
        $personas[$id]['festivas'] += $h['festivas'];
        $personas[$id]['turnos'][] = ['turno_id' => (int)$t['id'], 'fecha' => $t['fecha'], 'tipo' => $t['tipo'], 'unidad' => $t['unidad'],
            'motivo' => $t['cubierto_por_id'] ? 'Cubre a ' . $t['titular'] . ($mitad ? ' (medio turno)' : '') : 'Turno extra', 'manual' => $t['horas_override'] !== null] + $h;
    }
    $out = array_values($personas);
    usort($out, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));
    foreach ($out as &$p) $p['total'] = $p['diurnas'] + $p['festivas'];
    return ['mes' => $mes, 'personas' => $out];
}

function h_coord_horas_extra(PDO $db, array $p, array $in): array
{
    return horas_extra_mes($db, (string)($_GET['mes'] ?? substr(Reloj::hoy(), 0, 7)));
}

/** CSV para Excel en español (separador ;, UTF-8 con BOM): una fila por turno, con persona y horas. */
function h_coord_horas_extra_exportar(PDO $db, array $p, array $in): array
{
    $r = horas_extra_mes($db, (string)($_GET['mes'] ?? substr(Reloj::hoy(), 0, 7)));
    $filas = [];
    foreach ($r['personas'] as $pe) foreach ($pe['turnos'] as $t) {
        $filas[] = [$pe['nombre'], $pe['rut'], ucfirst($pe['jornada']), fecha_cl($t['fecha']), $t['tipo'], $t['unidad'], $t['motivo'] . ($t['manual'] ? ' · horas corregidas' : ''),
                    csv_num($t['diurnas']), csv_num($t['festivas']), csv_num($t['diurnas'] + $t['festivas'])];
    }
    csv_out("horas-extra-{$r['mes']}.csv", ['Persona', 'RUT', 'Jornada', 'Fecha', 'Turno', 'Unidad', 'Motivo', 'Horas diurnas', 'Horas festivas', 'Total'], $filas);
}
