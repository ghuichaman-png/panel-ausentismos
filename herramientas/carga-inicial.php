<?php
declare(strict_types=1);
/**
 * Carga inicial (plan, etapa 6): nómina desde un CSV, asignación anual de saldos y, si se pide, códigos de activación.
 *
 *   php herramientas/carga-inicial.php --csv=nomina-hpm.csv --dry-run     ← primero así: muestra lo que haría, no escribe nada
 *   php herramientas/carga-inicial.php --csv=nomina-hpm.csv               ← carga de verdad (deja antes un respaldo de la base)
 *   php herramientas/carga-inicial.php --csv=nomina-hpm.csv --codigos [--salida=CARPETA] [--reemplazar-vigentes]
 *
 * Opciones:
 *   --anio=2026              año de la asignación de saldos (por defecto, el actual)
 *   --codigos                genera códigos para quienes aún no tienen PIN y los guarda en un archivo
 *   --salida=CARPETA         dónde dejar ese archivo; debe estar FUERA de la carpeta de la app (por defecto, la carpeta que la contiene)
 *   --reemplazar-vigentes    también reemplaza códigos entregados que siguen vigentes (sin esto, se avisa y se saltan)
 *
 * Garantías: se puede repetir sin duplicar (upsert por RUT); nunca borra ni desactiva personas; nunca modifica el CSV;
 * los códigos no se muestran en pantalla. Ver docs/plan.md, etapa 6.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('APP_DIR', getenv('AUS_APP_DIR') ?: dirname(__DIR__));
require APP_DIR . '/lib/bootstrap.php';
require APP_DIR . '/lib/handlers_coord.php';

$op = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $op[$m[1]] = $m[2] ?? true;
    else { fwrite(STDERR, "Opción no reconocida: $a\n"); exit(2); }
}
$prueba = !empty($op['dry-run']);

try {
    $csvPath = (string)($op['csv'] ?? '');
    if ($csvPath === '' || $csvPath === '1') throw new RuntimeException('Indica el archivo con --csv=ruta/al/archivo.csv');
    if (!is_file($csvPath) || !is_readable($csvPath)) throw new RuntimeException("No encuentro o no puedo leer «{$csvPath}».");
    $csv = (string)file_get_contents($csvPath);                 // solo lectura: el CSV nunca se modifica
    $anio = isset($op['anio']) ? (int)$op['anio'] : (int)substr(Reloj::hoy(), 0, 4);
    if ($anio < 2000 || $anio > 2100) throw new RuntimeException('Año inválido en --anio.');

    $salida = null;
    if (!empty($op['codigos']) && !$prueba) {
        $salida = (string)($op['salida'] ?? dirname(realpath(APP_DIR)));
        $real = realpath($salida);
        if ($real === false || !is_dir($real) || !is_writable($real)) throw new RuntimeException("La carpeta de salida «{$salida}» no existe o no se puede escribir.");
        $app = rtrim(str_replace('\\', '/', (string)realpath(APP_DIR)), '/') . '/';
        if (str_starts_with(rtrim(str_replace('\\', '/', $real), '/') . '/', $app)) {
            throw new RuntimeException('Los códigos deben guardarse fuera de la carpeta de la app (para que no terminen en el repositorio ni en el servidor). Usa --salida=OTRA_CARPETA.');
        }
        $salida = $real;
    }

    $db = db();
    echo $prueba ? "MODO PRUEBA (--dry-run): no se escribe nada en la base ni se crean archivos.\n\n" : '';
    if (!$prueba) echo 'Respaldo previo: ' . db_respaldar_carga($db, (string)cfg('db_path')) . "\n\n";

    $db->exec('BEGIN IMMEDIATE');
    try {
        $r = nomina_importar($db, $csv);
        $antes = (int)$db->query("SELECT COUNT(*) FROM saldo_movimientos WHERE anio=$anio AND clase='asignacion'")->fetchColumn();
        saldos_asegurar_asignacion($db, $anio);
        $asign = (int)$db->query("SELECT COUNT(*) FROM saldo_movimientos WHERE anio=$anio AND clase='asignacion'")->fetchColumn() - $antes;
        $ruts = array_flip($r['ruts']);
        $fuera = 0;
        foreach ($db->query('SELECT rut FROM funcionarios WHERE activo=1') as $f) if (!isset($ruts[$f['rut']])) $fuera++;
        $cod = !empty($op['codigos']) ? codigos_pendientes($db, !empty($op['reemplazar-vigentes'])) : null;
        $db->exec($prueba ? 'ROLLBACK' : 'COMMIT');
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $_) {}
        throw $e;
    }

    $v = $prueba ? 'Se crearían' : 'Creados';
    echo "Nómina ({$csvPath}):\n";
    echo "  $v: {$r['creados']}\n";
    echo '  ' . ($prueba ? 'Se actualizarían' : 'Actualizados') . ": {$r['actualizados']}\n";
    echo "  Sin cambios: {$r['sin_cambios']}\n";
    echo '  Filas con error (no se cargan): ' . count($r['errores']) . "\n";
    foreach ($r['errores'] as $e) echo "    · línea {$e['linea']}: {$e['motivo']}\n";
    echo "  Personas activas que no vienen en el CSV: $fuera (no se tocan; si ya no trabajan, desactívalas en Nómina)\n\n";
    echo "Saldos $anio: " . ($prueba ? 'se asignarían' : 'asignados') . " $asign movimiento(s) nuevos (FL " . saldo_anual('FL') . ' y ADM ' . saldo_anual('ADM') . " por persona; quien ya los tenía no cambia).\n";

    if ($cod !== null) {
        echo "\nCódigos de activación:\n";
        foreach ($cod['vigentes'] as $n) echo "  · $n tiene un código vigente sin usar: no se reemplaza (usa --reemplazar-vigentes si se perdió).\n";
        if ($prueba) {
            echo '  Se generarían ' . count($cod['generar']) . " código(s).\n";
        } elseif (!$cod['generar']) {
            echo "  No hay a quién generar códigos (todos activaron su cuenta o tienen uno vigente).\n";
        } else {
            // Nunca sobrescribe un archivo anterior: si ya hay uno de este segundo, agrega -2, -3…
            $base = $salida . DIRECTORY_SEPARATOR . 'codigos-' . date('Ymd-His');
            for ($n = 1, $fh = false; $fh === false && $n <= 50; $n++) {
                $archivo = $base . ($n > 1 ? "-$n" : '') . '.csv';
                $fh = @fopen($archivo, 'x');
            }
            if ($fh === false) throw new RuntimeException("No pude crear el archivo de códigos en «{$salida}».");
            fwrite($fh, "nombre;rut;telefono;codigo;vence\n");
            $vence = Reloj::ahora()->modify('+' . CODIGO_DIAS . ' days')->format('d-m-Y');
            foreach ($cod['generar'] as $f) {
                $c = cuenta_generar_codigo($db, (int)$f['id'], null, 'carga-inicial');
                fputcsv($fh, [$f['nombre'], $f['rut'], $f['telefono'] ?? '', $c, $vence], ';', '"', '');
            }
            fclose($fh);
            echo '  Generados: ' . count($cod['generar']) . " (no se muestran aquí).\n";
            echo "  Archivo: $archivo\n";
            echo "  Entrégalos uno a uno (en persona o por WhatsApp) y luego borra el archivo. Vencen en " . CODIGO_DIAS . " días.\n";
        }
    }
    echo $prueba ? "\nNada se escribió. Si todo se ve bien, repite sin --dry-run.\n" : "\nListo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

/** Respaldo consistente antes de escribir: <ruta>.antes-carga-<fecha>.sqlite (no sobrescribe). */
function db_respaldar_carga(PDO $db, string $ruta): string
{
    $destino = sprintf('%s.antes-carga-%s.sqlite', $ruta, date('Ymd-His'));
    if (!is_file($destino)) $db->exec("VACUUM INTO '" . str_replace("'", "''", $destino) . "'");
    return $destino;
}

/** Quiénes reciben código: activos sin PIN. Los que tienen un código vigente se saltan, salvo que se pida reemplazarlos. */
function codigos_pendientes(PDO $db, bool $reemplazar): array
{
    $ahora = Reloj::stamp();
    $out = ['generar' => [], 'vigentes' => []];
    $st = $db->query('SELECT f.id, f.nombre, f.rut, f.telefono, c.pin_hash, c.codigo_hash, c.codigo_vence FROM funcionarios f
        LEFT JOIN credenciales c ON c.funcionario_id=f.id WHERE f.activo=1 ORDER BY f.nombre');
    foreach ($st as $f) {
        if ($f['pin_hash'] !== null) continue;                                          // ya activó su cuenta
        if ($f['codigo_hash'] !== null && $f['codigo_vence'] > $ahora && !$reemplazar) { $out['vigentes'][] = $f['nombre']; continue; }
        $out['generar'][] = $f;
    }
    return $out;
}
