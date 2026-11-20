<?php
declare(strict_types=1);

// Herramienta de carga inicial (plan, etapa 6): nómina + saldos + códigos de activación.

function correrCarga(string $app, array $args): array
{
    $herr = dirname(TESTS_DIR) . '/herramientas/carga-inicial.php';
    $p = proc_open(array_merge([PHP_BINARY, $herr], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['AUS_APP_DIR' => $app] + getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
}

function estadoDb(string $app): string
{
    $db = db_open($app . '/datos.sqlite');
    $f = $db->query('SELECT rut, nombre, jornada, equipo_id, calidad, telefono, activo FROM funcionarios ORDER BY rut')->fetchAll();
    $s = $db->query('SELECT funcionario_id, anio, saldo, clase, dias FROM saldo_movimientos ORDER BY funcionario_id, saldo, clase')->fetchAll();
    $c = $db->query('SELECT funcionario_id, codigo_hash IS NOT NULL AS cod FROM credenciales ORDER BY funcionario_id')->fetchAll();
    return json_encode([$f, $s, $c]);
}

function csvPrueba(string $app, string $contenido, string $sufijo = ''): string
{
    $f = $app . '/../nomina-' . basename($app) . $sufijo . '.csv';
    file_put_contents($f, $contenido);
    return $f;
}

const CSV_CARGA = "\xEF\xBB\xBFnombre,rut,jornada,equipo,calidad,telefono\n"
    . "Ana Uno,12345678-5,diurno,H7,titular,9 1234 5678\n"
    . "Carla Tres,13456789-9,turnante,,contrata,\n"
    . "Sin Calidad,14567890-0,diurno,H7,,\n";

prueba('Carga inicial --dry-run: informa lo que haría y no escribe nada', function () {
    $app = appTemporal([]);
    $csv = csvPrueba($app, CSV_CARGA);
    $hash = hash_file('sha256', $csv);
    $antes = estadoDb($app);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--dry-run']);
    chequear($cod === 0, 'termina bien: ' . $out);
    chequear(str_contains($out, 'PRUEBA') && str_contains($out, 'Se crearían: 2'), 'dice que es prueba y cuánto crearía: ' . $out);
    chequear(str_contains($out, 'línea 4') && str_contains(mb_strtolower($out), 'calidad'), 'informa la fila con calidad faltante');
    chequear(estadoDb($app) === $antes, 'la base no cambió');
    chequear(hash_file('sha256', $csv) === $hash, 'el CSV no cambió');
    chequear(glob($app . '/datos.sqlite.antes-carga-*') === [], 'en prueba no deja respaldo');
});

prueba('Carga inicial: crea nómina y saldos; repetirla no duplica ni cambia nada; nunca borra', function () {
    $app = appTemporal([]);
    $csv = csvPrueba($app, CSV_CARGA);
    $hash = hash_file('sha256', $csv);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--anio=2026']);
    chequear($cod === 0 && str_contains($out, 'Creados: 2'), 'primera pasada crea 2: ' . $out);
    $db = db_open($app . '/datos.sqlite');
    chequear((int)$db->query('SELECT COUNT(*) FROM funcionarios')->fetchColumn() === 2, 'hay 2 personas');
    chequear((int)$db->query("SELECT COUNT(*) FROM saldo_movimientos WHERE anio=2026 AND clase='asignacion'")->fetchColumn() === 4, 'FL y ADM 2026 asignados a ambas');
    $db = null;
    $tras1 = estadoDb($app);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--anio=2026']);
    chequear($cod === 0 && str_contains($out, 'Creados: 0') && str_contains($out, 'Sin cambios: 2'), 'segunda pasada no crea nada: ' . $out);
    chequear(estadoDb($app) === $tras1, 'la base queda igual tras repetir');
    chequear(count(glob($app . '/datos.sqlite.antes-carga-*')) >= 1, 'antes de escribir deja un respaldo de la base');
    // un CSV con menos personas no borra ni desactiva a nadie
    $csv2 = csvPrueba($app, "nombre,rut,jornada,equipo,calidad\nAna Uno,12345678-5,diurno,H6,titular\n", '-b');
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv2, '--anio=2026']);
    $db = db_open($app . '/datos.sqlite');
    chequear((int)$db->query('SELECT COUNT(*) FROM funcionarios WHERE activo=1')->fetchColumn() === 2, 'quien no viene en el CSV sigue activo');
    chequear(str_contains($out, 'no vienen en el CSV: 1'), 'avisa cuántas personas no vienen en el CSV (sin tocarlas): ' . $out);
    chequear($db->query("SELECT telefono FROM funcionarios WHERE rut='12345678-5'")->fetchColumn() === '+56912345678', 'sin columna teléfono, no borra el teléfono');
    chequear(hash_file('sha256', $csv) === $hash, 'el CSV original no cambió');
});

prueba('Carga inicial --codigos: archivo local fuera de la app; repetir solo da códigos a quien no se ha activado', function () {
    $app = appTemporal([]);
    $csv = csvPrueba($app, CSV_CARGA);
    $dirCod = $app . '/../codigos-' . basename($app);
    @mkdir($dirCod);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--codigos', '--salida=' . $dirCod]);
    $arch = glob($dirCod . '/codigos-*.csv');
    chequear($cod === 0 && count($arch) === 1, 'escribe un archivo de códigos: ' . $out);
    $lineas = array_values(array_filter(explode("\n", (string)file_get_contents($arch[0] ?? ''))));
    chequear(count($lineas) === 3 && preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', $lineas[1]) === 1, 'cabecera + 2 códigos');
    chequear(!preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', $out), 'los códigos no se muestran en pantalla');
    // Ana activa su cuenta; Carla no
    $codAna = str_getcsv($lineas[1], ';')[3] ?? '';
    $db = db_open($app . '/datos.sqlite');
    Reloj::$fijo = null;
    $nombreAna = str_getcsv($lineas[1], ';')[0];
    $rutAna = str_getcsv($lineas[1], ';')[1];
    cuenta_activar($db, $rutAna, $codAna, '482915', false, meta());
    horaNormal();
    $db = null;
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--codigos', '--salida=' . $dirCod]);
    chequear($cod === 0 && str_contains($out, 'código vigente') && count(glob($dirCod . '/codigos-*.csv')) === 1, 'con un código vigente sin usar, avisa y no lo reemplaza: ' . $out);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--codigos', '--reemplazar-vigentes', '--salida=' . $dirCod]);
    $arch = glob($dirCod . '/codigos-*.csv');
    usort($arch, fn($a, $b) => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
    $nuevo = (string)file_get_contents(end($arch));
    chequear(count($arch) === 2 && substr_count($nuevo, "\n") === 2 && !str_contains($nuevo, $nombreAna), 'con --reemplazar-vigentes: solo Carla (Ana ya activó): ' . $out);
    [$cod, $out] = correrCarga($app, ['--csv=' . $csv, '--codigos', '--salida=' . $app]);
    chequear($cod !== 0 && str_contains($out, 'fuera de la carpeta de la app'), 'se niega a escribir códigos dentro de la app: ' . $out);
});
