<?php
declare(strict_types=1);

// ───────────────────────── Solicitudes ─────────────────────────

function sol_filtrar(array $q): array
{
    $w = ['1=1'];
    $par = [];
    if (!empty($q['estado']) && es_en($q['estado'], ['pendiente', 'autorizada', 'rechazada', 'anulada'])) { $w[] = 's.estado=?'; $par[] = $q['estado']; }
    if (!empty($q['equipo_id'])) { $w[] = '(s.equipo_id=? OR s.unidad_turno_id=?)'; $par[] = (int)$q['equipo_id']; $par[] = (int)$q['equipo_id']; }
    if (!empty($q['jornada']) && es_en($q['jornada'], ['diurno', 'turnante'])) { $w[] = 's.jornada=?'; $par[] = $q['jornada']; }
    if (!empty($q['tipo']) && es_en($q['tipo'], ['FL', 'ADM', 'CT'])) { $w[] = 's.tipo=?'; $par[] = $q['tipo']; }
    if (!empty($q['sobre_cupo'])) $w[] = 's.sobre_cupo=1';
    if (!empty($q['fuera_nomina'])) $w[] = 's.fuera_de_nomina=1';
    if (!empty($q['vencidas'])) { $w[] = "s.estado='pendiente' AND s.fecha_inicio < ?"; $par[] = Reloj::hoy(); }
    if (!empty($q['funcionario_id'])) { $w[] = 's.funcionario_id=?'; $par[] = (int)$q['funcionario_id']; }
    if (fecha_ok($q['desde'] ?? null)) { $w[] = 's.fecha_fin >= ?'; $par[] = $q['desde']; }
    if (fecha_ok($q['hasta'] ?? null)) { $w[] = 's.fecha_inicio <= ?'; $par[] = $q['hasta']; }
    $texto = limpiar($q['q'] ?? '', 80);
    if ($texto !== '') {
        $w[] = "(s.nombre_norm LIKE ? ESCAPE '\\' OR s.rut LIKE ? ESCAPE '\\' OR s.codigo LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes(texto_norm($texto), '%_\\') . '%';
        $par[] = $like; $par[] = $like; $par[] = '%' . addcslashes(strtoupper($texto), '%_\\') . '%';
    }
    return [implode(' AND ', $w), $par];
}

const SOL_SELECT = 'SELECT s.*, e.nombre AS equipo_nombre, u.nombre AS unidad_nombre
    FROM solicitudes s LEFT JOIN equipos e ON e.id=s.equipo_id LEFT JOIN equipos u ON u.id=s.unidad_turno_id';

function h_coord_solicitudes(PDO $db, array $p, array $in): array
{
    [$w, $par] = sol_filtrar($_GET);
    $orden = ($_GET['estado'] ?? '') === 'pendiente' ? 's.fecha_inicio ASC, s.id ASC' : 's.fecha_inicio DESC, s.id DESC';
    $st = $db->prepare(SOL_SELECT . " WHERE $w ORDER BY $orden LIMIT 300");
    $st->execute($par);
    $rows = $st->fetchAll();
    $hoy = Reloj::hoy();
    $cupos = [];
    $diasSt = $db->prepare('SELECT fecha, consumo, am, pm, fraccion, turno, unidad_turno_id, reemplazo_nombre FROM solicitud_dias WHERE solicitud_id=? ORDER BY fecha, turno');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        foreach (['sobre_cupo', 'fuera_de_nomina'] as $b) $r[$b] = (bool)$r[$b];
        $r['vencida'] = $r['estado'] === 'pendiente' && $r['fecha_inicio'] < $hoy;
        $diasSt->execute([$r['id']]);
        $r['dias'] = $diasSt->fetchAll();
        $r['ocupacion'] = [];
        if ($r['jornada'] === 'diurno' && $r['equipo_id']) {
            $eid = (int)$r['equipo_id'];
            if (!isset($cupos[$eid])) $cupos[$eid] = (float)reg_equipo($db, $eid)['cupo_diurno'];
            $oc = reg_ocupacion_bloques($db, $eid, $r['fecha_inicio'], $r['fecha_fin']);
            foreach ($r['dias'] as $d) {
                $b = $oc[$d['fecha']] ?? ['am' => 0.0, 'pm' => 0.0];
                $r['ocupacion'][] = ['fecha' => $d['fecha'], 'am' => $b['am'], 'pm' => $b['pm'], 'ocupado' => max($b['am'], $b['pm']), 'cupo' => $cupos[$eid]];
            }
        }
    }
    return ['solicitudes' => $rows, 'hoy' => $hoy, 'limite' => count($rows) >= 300];
}

/** Nombre de la persona de coordinación con sesión (queda como «Resuelta por»). */
function mi_nombre(PDO $db): string
{
    $st = $db->prepare('SELECT nombre FROM funcionarios WHERE id=?');
    $st->execute([yo()]);
    return (string)$st->fetchColumn();
}

function h_coord_resolver(PDO $db, array $p, array $in): array
{
    return reg_resolver($db, (int)$p[1], (string)($in['accion'] ?? ''), (string)($in['motivo'] ?? ''), mi_nombre($db), ip_cliente(), yo(),
                        array_key_exists('es_extra', $in) ? (bool)$in['es_extra'] : true);
}

function h_coord_revertir(PDO $db, array $p, array $in): array
{
    return reg_revertir($db, (int)$p[1], (string)($in['motivo'] ?? ''), mi_nombre($db),
                        !empty($in['acepta_sobre_cupo']), (string)($in['justificacion'] ?? ''), ip_cliente(), yo());
}

function h_coord_anular(PDO $db, array $p, array $in): array
{
    return reg_anular($db, (int)$p[1], yo(), true, mi_nombre($db), ip_cliente());
}

/** Coordinación registra una solicitud en nombre de otra persona (queda auditado quién la creó). */
function h_coord_en_nombre_de(PDO $db, array $p, array $in): array
{
    if (empty($in['funcionario_id'])) throw new ApiError('Elige a la persona.', 422);
    $r = reg_crear($db, $in, ['ip' => ip_cliente(), 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'cfg' => cfg(), 'creada_por' => yo(), 'por_coordinacion' => true]);
    return ['ok' => true] + $r;
}

function h_coord_calendario(PDO $db, array $p, array $in): array
{
    return cal_datos($db, (string)($_GET['mes'] ?? substr(Reloj::hoy(), 0, 7)), true);
}

// ───────────────────────── Resumen y exportación ─────────────────────────

function resumen_datos(PDO $db, int $anio): array
{
    $st = $db->prepare("SELECT s.rut, s.nombre, s.jornada, s.calidad, e.nombre AS equipo, s.tipo, s.estado, sd.fraccion
        FROM solicitud_dias sd JOIN solicitudes s ON s.id=sd.solicitud_id LEFT JOIN equipos e ON e.id=s.equipo_id
        WHERE substr(sd.fecha,1,4)=? ORDER BY s.nombre");
    $st->execute([(string)$anio]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $k = $r['rut'];
        $out[$k] ??= ['rut' => $r['rut'], 'nombre' => $r['nombre'], 'jornada' => $r['jornada'], 'calidad' => $r['calidad'],
                      'equipo' => $r['equipo'], 'd' => []];
        $peso = ($r['tipo'] === 'ADM' && $r['fraccion'] !== 'completo') ? 0.5 : 1.0;
        $out[$k]['d'][$r['tipo']][$r['estado']] = ($out[$k]['d'][$r['tipo']][$r['estado']] ?? 0) + $peso;
    }
    return array_values($out);
}

function h_coord_resumen(PDO $db, array $p, array $in): array
{
    $anio = (int)($_GET['anio'] ?? substr(Reloj::hoy(), 0, 4));
    return ['anio' => $anio, 'personas' => resumen_datos($db, $anio)];
}

function csv_num(float $n): string { return str_replace('.', ',', (string)$n); }

function csv_celda(mixed $v): string
{
    $s = (string)$v;
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
}

function csv_out(string $nombre, array $cab, array $filas): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: no-store');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $cab, ';', '"', '\\');
    foreach ($filas as $f) fputcsv($o, array_map('csv_celda', $f), ';', '"', '\\');
    fclose($o);
    exit;
}

function h_coord_exportar(PDO $db, array $p, array $in): array
{
    $que = $_GET['que'] ?? 'solicitudes';
    if ($que === 'resumen') {
        $anio = (int)($_GET['anio'] ?? substr(Reloj::hoy(), 0, 4));
        $filas = [];
        foreach (resumen_datos($db, $anio) as $r) {
            $f = [$r['nombre'], $r['rut'], $r['jornada'], $r['equipo'] ?? '', $r['calidad']];
            foreach (['FL', 'ADM', 'CT'] as $t) foreach (['autorizada', 'pendiente', 'rechazada', 'anulada'] as $e) $f[] = csv_num((float)($r['d'][$t][$e] ?? 0));
            $filas[] = $f;
        }
        $cab = ['Nombre', 'RUT', 'Jornada', 'Equipo', 'Calidad'];
        foreach (['FL', 'ADM', 'CT'] as $t) foreach (['autorizada', 'pendiente', 'rechazada', 'anulada'] as $e) $cab[] = "$t $e";
        csv_out("resumen-$anio.csv", $cab, $filas);
    }
    [$w, $par] = sol_filtrar($_GET);
    $st = $db->prepare(SOL_SELECT . " WHERE $w ORDER BY s.fecha_inicio, s.id LIMIT 20000");
    $st->execute($par);
    $dst = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(consumo),0) AS c FROM solicitud_dias WHERE solicitud_id=?');
    $filas = [];
    foreach ($st->fetchAll() as $s) {
        $dst->execute([$s['id']]);
        $d = $dst->fetch();
        $filas[] = [$s['codigo'], $s['creado_en'], $s['nombre'], $s['rut'], $s['jornada'], $s['equipo_nombre'] ?? '', $s['calidad'],
            $s['tipo'], fecha_cl($s['fecha_inicio']), fecha_cl($s['fecha_fin']), $s['fraccion'], $s['turno'] ?? '', $s['unidad_nombre'] ?? '',
            $s['reemplazo_nombre'] ?? $s['cambio_con'] ?? '', $d['n'], csv_num((float)$d['c']), $s['estado'],
            $s['sobre_cupo'] ? 'sí' : 'no', $s['justificacion_sobre_cupo'] ?? '', $s['motivo_resolucion'] ?? '',
            $s['resuelto_por'] ?? '', $s['resuelto_en'] ?? '', $s['fuera_de_nomina'] ? 'sí' : 'no', $s['comentario'] ?? ''];
    }
    csv_out('solicitudes-' . Reloj::hoy() . '.csv',
        ['Código', 'Creada', 'Nombre', 'RUT', 'Jornada', 'Equipo', 'Calidad', 'Tipo', 'Desde', 'Hasta', 'Fracción', 'Turno', 'Unidad',
         'Reemplazo / cambio con', 'Días', 'Consumo de cupo', 'Estado', 'Sobre cupo', 'Justificación', 'Motivo', 'Resuelto por',
         'Resuelto en', 'Fuera de nómina', 'Comentario'], $filas);
}

function h_coord_respaldo(PDO $db, array $p, array $in): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aus_' . bin2hex(random_bytes(8)) . '.sqlite';
    $db->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="ausentismos-' . Reloj::hoy() . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    auditar($db, 'respaldo', null, '', ip_cliente());
    exit;
}

// ───────────────────────── Nómina ─────────────────────────

function nomina_validar(PDO $db, array $r): array
{
    $nombre = limpiar($r['nombre'] ?? '', 120);
    if (mb_strlen($nombre) < 3) throw new ApiError('Falta el nombre.', 422);
    $rut = rut_normalizar((string)($r['rut'] ?? ''));
    if ($rut === null) throw new ApiError('RUT inválido.', 422);
    $jornada = $r['jornada'] ?? '';
    if (!es_en($jornada, ['diurno', 'turnante'])) throw new ApiError('Jornada debe ser diurno o turnante.', 422);
    $calidad = $r['calidad'] ?? '';
    if (!es_en($calidad, ['titular', 'contrata', 'honorarios'])) throw new ApiError('Calidad debe ser titular, contrata u honorarios.', 422);
    $equipo = !empty($r['equipo_id']) ? (int)$r['equipo_id'] : null;
    if ($jornada === 'diurno' && $equipo === null) throw new ApiError('Los diurnos necesitan equipo.', 422);
    if ($equipo !== null) {
        $st = $db->prepare('SELECT tiene_diurnos FROM equipos WHERE id=?');
        $st->execute([$equipo]);
        $e = $st->fetch();
        if (!$e) throw new ApiError('El equipo no existe.', 422);
        if ($jornada === 'diurno' && !$e['tiene_diurnos']) throw new ApiError('Ese equipo no tiene personal diurno.', 422);
    }
    $v = ['nombre' => $nombre, 'rut' => $rut, 'jornada' => $jornada, 'calidad' => $calidad, 'equipo_id' => $equipo];
    // Teléfono opcional; si la clave no viene (p. ej. CSV antiguo sin esa columna), no se toca el guardado.
    if (array_key_exists('telefono', $r)) $v['telefono'] = telefono_normalizar((string)($r['telefono'] ?? ''));
    return $v;
}

function h_coord_nomina_listar(PDO $db, array $p, array $in): array
{
    $w = ['1=1'];
    $par = [];
    $q = texto_norm((string)($_GET['q'] ?? ''));
    if ($q !== '') { $w[] = "(f.nombre_norm LIKE ? ESCAPE '\\' OR f.rut LIKE ? ESCAPE '\\')"; $l = '%' . addcslashes($q, '%_\\') . '%'; $par[] = $l; $par[] = $l; }
    if (($_GET['activo'] ?? '') !== '') { $w[] = 'f.activo=?'; $par[] = (int)$_GET['activo']; }
    $st = $db->prepare('SELECT f.id,f.nombre,f.rut,f.jornada,f.equipo_id,e.nombre AS equipo,f.calidad,f.telefono,f.rol,f.activo,
            c.pin_hash IS NOT NULL AS tiene_pin, c.codigo_vence, c.bloqueado_hasta
        FROM funcionarios f LEFT JOIN equipos e ON e.id=f.equipo_id LEFT JOIN credenciales c ON c.funcionario_id=f.id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY f.nombre LIMIT 1000');
    $st->execute($par);
    $rows = $st->fetchAll();
    $ahora = Reloj::stamp();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['activo'] = (bool)$r['activo'];
        // Estado de la cuenta, sin exponer hashes: sin_cuenta · codigo_pendiente · codigo_vencido · activa · bloqueada
        $b = $r['bloqueado_hasta'];
        $r['cuenta'] = ($b === 'manual' || ($b !== null && $ahora < $b)) ? 'bloqueada'
            : ((int)$r['tiene_pin'] ? 'activa'
            : ($r['codigo_vence'] !== null ? ($ahora <= $r['codigo_vence'] ? 'codigo_pendiente' : 'codigo_vencido') : 'sin_cuenta'));
        unset($r['tiene_pin'], $r['bloqueado_hasta']);
    }
    return ['funcionarios' => $rows];
}

function h_coord_nomina_guardar(PDO $db, array $p, array $in): array
{
    $id = isset($p[1]) ? (int)$p[1] : null;
    $v = nomina_validar($db, $in);
    $activo = array_key_exists('activo', $in) ? (int)(bool)$in['activo'] : 1;
    return tx($db, function () use ($db, $id, $v, $activo) {
        $st = $db->prepare('SELECT id FROM funcionarios WHERE rut=?');
        $st->execute([$v['rut']]);
        $otro = $st->fetchColumn();
        if ($otro && (int)$otro !== $id) throw new ApiError('Ya existe alguien con ese RUT en la nómina.', 409);
        if ($id === null) {
            $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,telefono,activo) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$v['nombre'], texto_norm($v['nombre']), $v['rut'], $v['jornada'], $v['equipo_id'], $v['calidad'], $v['telefono'] ?? null, $activo]);
            $id = (int)$db->lastInsertId();
            auditar($db, 'nomina_crear', null, "{$v['nombre']}", ip_cliente());
        } else {
            $db->prepare('UPDATE funcionarios SET nombre=?,nombre_norm=?,rut=?,jornada=?,equipo_id=?,calidad=?,activo=? WHERE id=?')
               ->execute([$v['nombre'], texto_norm($v['nombre']), $v['rut'], $v['jornada'], $v['equipo_id'], $v['calidad'], $activo, $id]);
            if (array_key_exists('telefono', $v)) $db->prepare('UPDATE funcionarios SET telefono=? WHERE id=?')->execute([$v['telefono'], $id]);
            auditar($db, 'nomina_editar', null, "{$v['nombre']} activo=$activo", ip_cliente());
        }
        return ['id' => $id];
    });
}

function h_coord_nomina_importar(PDO $db, array $p, array $in): array
{
    $csv = (string)($in['csv'] ?? '');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    if (trim($csv) === '') throw new ApiError('El archivo está vacío.', 422);
    $primera = strtok($csv, "\n") ?: '';
    $delim = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $cab = fgetcsv($fh, 0, $delim, '"', '\\') ?: [];
    $cab = array_map(fn($c) => texto_norm((string)$c), $cab);
    foreach (['nombre', 'rut', 'jornada', 'equipo', 'calidad'] as $req) {
        if (!in_array($req, $cab, true)) throw new ApiError("Falta la columna «{$req}». Columnas esperadas: nombre,rut,jornada,equipo,calidad.", 422);
    }
    $eqMap = [];
    foreach ($db->query('SELECT id,nombre FROM equipos') as $e) $eqMap[texto_norm($e['nombre'])] = (int)$e['id'];

    $res = ['creados' => 0, 'actualizados' => 0, 'errores' => []];
    $linea = 1;
    tx($db, function () use ($db, $fh, $cab, $delim, $eqMap, &$res, &$linea) {
        while (($f = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            $linea++;
            if (count($f) === 1 && trim((string)$f[0]) === '') continue;
            if ($linea > 3001) { $res['errores'][] = ['linea' => $linea, 'motivo' => 'Máximo 3000 filas por importación.']; break; }
            $row = [];
            foreach ($cab as $i => $c) $row[$c] = trim((string)($f[$i] ?? ''));
            try {
                $eqNombre = texto_norm($row['equipo']);
                $equipoId = null;
                if ($eqNombre !== '') {
                    if (!isset($eqMap[$eqNombre])) throw new ApiError('Equipo «' . $row['equipo'] . '» no existe.', 422);
                    $equipoId = $eqMap[$eqNombre];
                }
                $cal = texto_norm($row['calidad']);
                if ($cal === 'honorario') $cal = 'honorarios';
                $datos = ['nombre' => $row['nombre'], 'rut' => $row['rut'], 'jornada' => texto_norm($row['jornada']),
                          'calidad' => $cal, 'equipo_id' => $equipoId];
                if (array_key_exists('telefono', $row)) $datos['telefono'] = $row['telefono']; // columna opcional
                $v = nomina_validar($db, $datos);
                $st = $db->prepare('SELECT id FROM funcionarios WHERE rut=?');
                $st->execute([$v['rut']]);
                $existe = $st->fetchColumn();
                if ($existe) {
                    $db->prepare('UPDATE funcionarios SET nombre=?,nombre_norm=?,jornada=?,equipo_id=?,calidad=?,activo=1 WHERE id=?')
                       ->execute([$v['nombre'], texto_norm($v['nombre']), $v['jornada'], $v['equipo_id'], $v['calidad'], $existe]);
                    if (array_key_exists('telefono', $v)) $db->prepare('UPDATE funcionarios SET telefono=? WHERE id=?')->execute([$v['telefono'], $existe]);
                    $res['actualizados']++;
                } else {
                    $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,telefono,activo) VALUES (?,?,?,?,?,?,?,1)')
                       ->execute([$v['nombre'], texto_norm($v['nombre']), $v['rut'], $v['jornada'], $v['equipo_id'], $v['calidad'], $v['telefono'] ?? null]);
                    $res['creados']++;
                }
            } catch (ApiError $e) {
                $res['errores'][] = ['linea' => $linea, 'motivo' => $e->getMessage()];
            }
        }
        auditar($db, 'nomina_importar', null, "creados={$res['creados']} actualizados={$res['actualizados']} errores=" . count($res['errores']), ip_cliente());
    });
    return $res;
}

// ───────────────────────── Tipos de ausentismo ─────────────────────────

function h_coord_tipos_listar(PDO $db, array $p, array $in): array
{
    return ['tipos' => tipos_listar($db, false)];
}

/**
 * Crea o edita un tipo (SPEC-v2 §3.5). La sigla es única e inmutable. Los precargados (FL, ADM, CT, LIC) solo
 * cambian nombre, etiqueta, orden y activo. Nada se borra: se desactiva. Cambiar un tipo no recalcula solicitudes.
 */
function h_coord_tipo_guardar(PDO $db, array $p, array $in): array
{
    $id = isset($p[1]) && (int)$p[1] > 0 ? (int)$p[1] : null;
    $nombre = limpiar($in['nombre'] ?? '', 60);
    $etiqueta = limpiar($in['etiqueta_publica'] ?? '', 30);
    if (mb_strlen($nombre) < 2) throw new ApiError('Falta el nombre del tipo.', 422);
    if ($etiqueta === '') throw new ApiError('Falta la etiqueta que verán los demás en el calendario.', 422);
    $b = fn($k, $def) => array_key_exists($k, $in) ? (int)(bool)$in[$k] : $def;
    $activo = $b('activo', 1);
    $orden = (int)($in['orden'] ?? 50);
    return tx($db, function () use ($db, $id, $in, $nombre, $etiqueta, $b, $activo, $orden) {
        if ($id !== null) {
            $st = $db->prepare('SELECT * FROM tipos_ausentismo WHERE id=?');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) throw new ApiError('Ese tipo no existe.', 404);
            if ((int)$t['precargado']) {
                $db->prepare('UPDATE tipos_ausentismo SET nombre=?, etiqueta_publica=?, activo=?, orden=? WHERE id=?')->execute([$nombre, $etiqueta, $activo, $orden, $id]);
                auditar($db, 'tipo_guardar', null, "{$t['sigla']} (precargado): nombre, etiqueta, activo=$activo", ip_cliente(), yo_o_null());
                return ['id' => $id];
            }
        }
        $regla = $in['regla_cupo'] ?? 'normal';
        $saldo = $in['saldo'] ?? null;
        $loPide = $in['lo_pide'] ?? 'funcionario';
        $rango = $in['rango'] ?? 'rango';
        if (!es_en($regla, ['normal', 'fl'])) throw new ApiError('Regla de cupo inválida.', 422);
        if ($saldo !== null && $saldo !== '' && !es_en($saldo, ['FL', 'ADM'])) throw new ApiError('El saldo debe ser FL, ADM o ninguno.', 422);
        if (!es_en($loPide, ['funcionario', 'coordinacion'])) throw new ApiError('Indica quién lo pide.', 422);
        if (!es_en($rango, ['un_dia', 'rango'])) throw new ApiError('Indica si es de un día o de un rango de fechas.', 422);
        $vals = [$nombre, $b('consume_cupo', 1), $regla, $saldo ?: null, $b('medio_dia', 0), $loPide, $b('exige_reemplazo', 1), $rango, $etiqueta, $activo, $orden];
        if ($id === null) {
            $sigla = strtoupper(trim((string)($in['sigla'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $sigla)) throw new ApiError('La sigla debe tener de 2 a 6 letras o números, sin espacios.', 422);
            $st = $db->prepare('SELECT 1 FROM tipos_ausentismo WHERE sigla=?');
            $st->execute([$sigla]);
            if ($st->fetchColumn()) throw new ApiError("Ya existe un tipo con la sigla $sigla.", 409);
            $db->prepare('INSERT INTO tipos_ausentismo (nombre,consume_cupo,regla_cupo,saldo,medio_dia,lo_pide,exige_reemplazo,rango,etiqueta_publica,activo,orden,sigla)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, $sigla]);
            $id = (int)$db->lastInsertId();
            auditar($db, 'tipo_crear', null, "$sigla: $nombre", ip_cliente(), yo_o_null());
        } else {
            $db->prepare('UPDATE tipos_ausentismo SET nombre=?,consume_cupo=?,regla_cupo=?,saldo=?,medio_dia=?,lo_pide=?,exige_reemplazo=?,rango=?,etiqueta_publica=?,activo=?,orden=? WHERE id=?')
               ->execute([...$vals, $id]);
            auditar($db, 'tipo_guardar', null, "#$id: $nombre", ip_cliente(), yo_o_null());
        }
        return ['id' => $id];
    });
}

/** Persona con sesión, o null si se llama fuera de una petición (pruebas, consola). */
function yo_o_null(): ?int
{
    return function_exists('sesion_actual') && isset($_COOKIE['aus_s']) ? (sesion_actual()['funcionario_id'] ?? null) : null;
}

// ───────────────────────── Equipos y feriados ─────────────────────────

function h_coord_equipos_listar(PDO $db, array $p, array $in): array
{
    $rows = $db->query('SELECT * FROM equipos ORDER BY orden,nombre')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        foreach (['tiene_diurnos', 'tiene_turnantes', 'activo'] as $b) $r[$b] = (bool)$r[$b];
        $r['cupo_diurno'] = (float)$r['cupo_diurno'];
        $r['orden'] = (int)$r['orden'];
    }
    return ['equipos' => $rows];
}

function h_coord_equipos_guardar(PDO $db, array $p, array $in): array
{
    $id = isset($p[1]) ? (int)$p[1] : null;
    $nombre = limpiar($in['nombre'] ?? '', 60);
    if (mb_strlen($nombre) < 2) throw new ApiError('Falta el nombre del equipo.', 422);
    $cupo = (float)str_replace(',', '.', (string)($in['cupo_diurno'] ?? 2));
    if ($cupo < 0 || $cupo > 50) throw new ApiError('El cupo debe estar entre 0 y 50.', 422);
    $d = (int)(bool)($in['tiene_diurnos'] ?? 1);
    $t = (int)(bool)($in['tiene_turnantes'] ?? 0);
    $act = (int)(bool)($in['activo'] ?? 1);
    $ord = (int)($in['orden'] ?? 0);
    try {
        if ($id === null) {
            $db->prepare('INSERT INTO equipos (nombre,tiene_diurnos,tiene_turnantes,cupo_diurno,activo,orden) VALUES (?,?,?,?,?,?)')
               ->execute([$nombre, $d, $t, $cupo, $act, $ord]);
            $id = (int)$db->lastInsertId();
        } else {
            $db->prepare('UPDATE equipos SET nombre=?,tiene_diurnos=?,tiene_turnantes=?,cupo_diurno=?,activo=?,orden=? WHERE id=?')
               ->execute([$nombre, $d, $t, $cupo, $act, $ord, $id]);
        }
    } catch (PDOException $e) {
        throw new ApiError('Ya existe un equipo con ese nombre.', 409);
    }
    auditar($db, 'equipo_guardar', null, "$nombre cupo=$cupo", ip_cliente());
    return ['id' => $id];
}

function h_coord_feriados_listar(PDO $db, array $p, array $in): array
{
    return ['feriados' => $db->query('SELECT fecha,nombre FROM feriados ORDER BY fecha')->fetchAll()];
}

function h_coord_feriados_guardar(PDO $db, array $p, array $in): array
{
    $items = [];
    if (!empty($in['texto'])) {
        foreach (preg_split('/\R/', (string)$in['texto']) as $l) {
            $l = trim($l);
            if ($l === '') continue;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s*[;,\-\s]?\s*(.*)$/u', $l, $m)) $f = "$m[1]-$m[2]-$m[3]";
            elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})\s*[;,\-\s]?\s*(.*)$/u', $l, $m)) $f = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            else throw new ApiError("No entendí la línea: «{$l}». Usa «aaaa-mm-dd Nombre» o «dd-mm-aaaa Nombre».", 422);
            if (!fecha_ok($f)) throw new ApiError("Fecha inválida: $l", 422);
            $items[] = [$f, limpiar($m[4], 80) ?: 'Feriado'];
        }
    } else {
        if (!fecha_ok($in['fecha'] ?? null)) throw new ApiError('Fecha inválida.', 422);
        $items[] = [$in['fecha'], limpiar($in['nombre'] ?? '', 80) ?: 'Feriado'];
    }
    tx($db, function () use ($db, $items) {
        $st = $db->prepare('INSERT OR REPLACE INTO feriados (fecha,nombre) VALUES (?,?)');
        foreach ($items as $i) $st->execute($i);
        auditar($db, 'feriados_guardar', null, count($items) . ' fecha(s)', ip_cliente());
    });
    return ['guardados' => count($items)];
}

function h_coord_feriados_eliminar(PDO $db, array $p, array $in): array
{
    if (!fecha_ok($in['fecha'] ?? null)) throw new ApiError('Fecha inválida.', 422);
    $db->prepare('DELETE FROM feriados WHERE fecha=?')->execute([$in['fecha']]);
    auditar($db, 'feriado_eliminar', null, $in['fecha'], ip_cliente());
    return ['ok' => true];
}

// ───────────────────────── Vistas «Hoy» y «Registro de cambios» (SPEC-v2 §6.2.10–11) ─────────────────────────

/** Lo que coordinación necesita al abrir el panel: quién falta hoy, turnos sin cubrir y pendientes que vencen pronto. */
function h_coord_hoy(PDO $db, array $p, array $in): array
{
    $hoy = Reloj::hoy();
    $st = $db->prepare("SELECT f.nombre, e.nombre AS equipo, COALESCE(t.etiqueta_publica, s.tipo) AS etiqueta, MAX(d.am) AS am, MAX(d.pm) AS pm, s.id
        FROM solicitud_dias d JOIN solicitudes s ON s.id=d.solicitud_id JOIN funcionarios f ON f.id=s.funcionario_id
        LEFT JOIN equipos e ON e.id=f.equipo_id LEFT JOIN tipos_ausentismo t ON t.sigla=s.tipo
        WHERE d.fecha=? AND s.estado='autorizada' AND COALESCE(t.es_cambio_turno,0)=0
        GROUP BY s.id ORDER BY f.nombre");
    $st->execute([$hoy]);
    $aus = [];
    foreach ($st->fetchAll() as $r) {
        $media = ((int)$r['am'] && !(int)$r['pm']) ? ' ½ AM' : ((!(int)$r['am'] && (int)$r['pm']) ? ' ½ PM' : '');
        $aus[] = ['solicitud_id' => (int)$r['id'], 'nombre' => $r['nombre'], 'equipo' => $r['equipo'], 'etiqueta' => $r['etiqueta'] . $media];
    }
    $get = $_GET;
    $_GET = ['desde' => $hoy, 'hasta' => fecha_add($hoy, 7)];
    $sin = h_coord_turnos_sin_cubrir($db, [], [])['turnos'];
    $_GET = $get;
    $st = $db->prepare("SELECT s.*, t.etiqueta_publica AS etiqueta, f.nombre FROM solicitudes s JOIN funcionarios f ON f.id=s.funcionario_id
        LEFT JOIN tipos_ausentismo t ON t.sigla=s.tipo WHERE s.estado='pendiente' AND s.fecha_inicio<=? ORDER BY s.fecha_inicio, s.id");
    $st->execute([fecha_add($hoy, 3)]);
    $pend = array_map(fn($s) => sol_publica($s) + ['vencida' => $s['fecha_inicio'] < $hoy], $st->fetchAll());
    return ['hoy' => $hoy, 'ausentes' => $aus, 'sin_cubrir' => $sin, 'pendientes_pronto' => $pend];
}

/** Registro de cambios: últimas acciones con su autor; filtro de texto sin importar tildes ni mayúsculas. */
function h_coord_auditoria(PDO $db, array $p, array $in): array
{
    $q = texto_norm(limpiar($_GET['q'] ?? '', 80));
    $rows = $db->query("SELECT a.id, a.fecha, a.accion, a.detalle, a.solicitud_id, f.nombre AS quien
        FROM auditoria a LEFT JOIN funcionarios f ON f.id=a.funcionario_id ORDER BY a.id DESC LIMIT 3000")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($q !== '' && !str_contains(texto_norm($r['accion'] . ' ' . $r['detalle'] . ' ' . ($r['quien'] ?? '')), $q)) continue;
        $r['quien'] = $r['quien'] ?? 'Sistema';
        $out[] = $r;
        if (count($out) >= 300) break;
    }
    return ['registros' => $out];
}
