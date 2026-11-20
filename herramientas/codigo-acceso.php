<?php
declare(strict_types=1);
/**
 * Genera un código de activación o recuperación desde la consola (SSH de DreamHost o local).
 * Sirve para crear el PRIMER coordinador y para emergencias (si coordinación perdió el acceso).
 *
 *   php herramientas/codigo-acceso.php <RUT> [--coordinacion]
 *   php herramientas/codigo-acceso.php <RUT> --coordinacion --nombre="Nombre Apellido" --jornada=diurno --equipo=H7 --calidad=titular [--telefono=...]
 *
 * Si la persona no está en la nómina, se crea con los datos indicados. --coordinacion le asigna ese rol.
 * El código se muestra una sola vez: entrégalo en persona o por WhatsApp. Invalida el PIN anterior y cierra sus sesiones.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('APP_DIR', getenv('AUS_APP_DIR') ?: dirname(__DIR__));
require APP_DIR . '/lib/bootstrap.php';
require APP_DIR . '/lib/handlers_coord.php';

$args = array_slice($argv, 1);
$rutArg = null;
$op = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $op[$m[1]] = $m[2] ?? true;
    elseif ($rutArg === null) $rutArg = $a;
}
if ($rutArg === null) { fwrite(STDERR, "Uso: php herramientas/codigo-acceso.php <RUT> [--coordinacion] [--nombre=... --jornada=... --equipo=... --calidad=...]\n"); exit(2); }

try {
    $db = db();
    $rut = rut_normalizar($rutArg) ?? throw new RuntimeException('El RUT no es válido (revisa el dígito verificador).');
    $st = $db->prepare('SELECT id, nombre FROM funcionarios WHERE rut=?');
    $st->execute([$rut]);
    $f = $st->fetch();
    if (!$f) {
        if (empty($op['nombre'])) throw new RuntimeException("$rut no está en la nómina. Agrega --nombre, --jornada, --equipo y --calidad para crearlo.");
        $eq = null;
        if (!empty($op['equipo'])) {
            $s2 = $db->prepare('SELECT id FROM equipos WHERE nombre=?');
            $s2->execute([(string)$op['equipo']]);
            $eq = $s2->fetchColumn() ?: throw new RuntimeException('No existe el equipo «' . $op['equipo'] . '».');
        }
        $datos = ['nombre' => (string)$op['nombre'], 'rut' => $rut, 'jornada' => (string)($op['jornada'] ?? ''), 'calidad' => (string)($op['calidad'] ?? ''), 'equipo_id' => $eq];
        if (isset($op['telefono'])) $datos['telefono'] = (string)$op['telefono'];
        $v = nomina_validar($db, $datos);
        $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,telefono,activo) VALUES (?,?,?,?,?,?,?,1)')
           ->execute([$v['nombre'], texto_norm($v['nombre']), $v['rut'], $v['jornada'], $v['equipo_id'], $v['calidad'], $v['telefono'] ?? null]);
        $f = ['id' => (int)$db->lastInsertId(), 'nombre' => $v['nombre']];
        cuenta_auditar($db, 'nomina_crear', null, "{$v['nombre']} (consola)");
    }
    if (!empty($op['coordinacion'])) {
        $db->prepare("UPDATE funcionarios SET rol='coordinacion' WHERE id=?")->execute([$f['id']]);
        cuenta_auditar($db, 'rol_cambiar', null, "{$f['nombre']} → coordinacion (consola)");
    }
    $codigo = cuenta_generar_codigo($db, (int)$f['id'], null, 'consola');
    echo "Código para {$f['nombre']} ($rut): $codigo\n";
    echo "Vence en 7 días. Entrégalo en persona o por WhatsApp; se usa en «Activar con código».\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
