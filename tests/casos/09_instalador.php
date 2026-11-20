<?php
declare(strict_types=1);

// install.php crea la base, los 19 equipos y la primera persona de coordinación con su código (sin clave compartida).

function instalarWeb(string $app, array $post): string
{
    $arch = tempnam(sys_get_temp_dir(), 'inst');
    file_put_contents($arch, json_encode($post));
    $p = proc_open([PHP_BINARY, TESTS_DIR . '/install_sub.php', $app, '@' . $arch], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    @unlink($arch);
    return $out;
}

prueba('Instalador: crea la base, los equipos y la primera coordinadora con su código; luego se bloquea', function () {
    $app = appTemporal([]);
    $db = $app . '/datos.sqlite';
    @unlink($db);
    $rut = '15000003-' . rut_dv('15000003');
    $html = instalarWeb($app, ['accion' => 'instalar']);
    chequear(str_contains($html, 'nombre') && !is_file($db), 'sin los datos de la primera coordinadora no instala');
    $html = instalarWeb($app, ['accion' => 'instalar', 'nombre' => 'Coordinadora Inicial', 'rut' => $rut, 'jornada' => 'diurno', 'equipo' => 'H7', 'calidad' => 'titular']);
    chequear(preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', $html, $m) === 1, 'muestra el código de activación');
    chequear(!str_contains($html, 'clave_hash') && !str_contains($html, 'Generar hash'), 'ya no habla de la clave compartida');
    $pdo = db_open($db);
    chequear((int)$pdo->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 19, '19 equipos');
    chequear($pdo->query("SELECT rol FROM funcionarios WHERE rut='$rut'")->fetchColumn() === 'coordinacion', 'la persona queda con rol coordinación');
    Reloj::$fijo = null;
    $s = cuenta_activar($pdo, $rut, $m[0] ?? '', '482915', false, meta());
    horaNormal();
    chequear(($s['funcionario']['rol'] ?? '') === 'coordinacion', 'con ese código activa su cuenta');
    $pdo = null;
    $html = instalarWeb($app, ['accion' => 'instalar', 'nombre' => 'Otra', 'rut' => $rut, 'jornada' => 'diurno', 'equipo' => 'H7', 'calidad' => 'titular']);
    chequear(str_contains($html, 'ya está instalado'), 'una segunda vez se niega');
});
