<?php
declare(strict_types=1);

// Bugs de seguridad corregidos (etapa 0).

prueba('Bug N1: coordinación nunca queda abierta sin clave, aunque config.php traiga coord_sin_clave', function () {
    $app = appTemporal(['coord_sin_clave' => true, 'clave_hash' => password_hash('clave-de-prueba-larga', PASSWORD_DEFAULT)]);
    // Desde la etapa 2 la sesión es por usuario (auth/sesion); el objetivo del test no cambia.
    $s = json_decode(pedirApi($app, 'GET', 'auth/sesion'), true);
    chequear(is_array($s) && ($s['autenticado'] ?? null) === false, 'sin login no hay sesión: ' . json_encode($s));
    chequear(is_array($s) && array_key_exists('csrf', $s) && $s['csrf'] === null, 'sin login no se entrega token CSRF');
    $r = pedirApi($app, 'GET', 'coord/respaldo');
    chequear(!str_starts_with($r, 'SQLite format 3'), 'coord/respaldo sin login no entrega la base de datos');
    $j = json_decode($r, true);
    chequear(is_array($j) && str_contains((string)($j['error'] ?? ''), 'sesión'), 'coord/respaldo sin login responde error de sesión: ' . substr($r, 0, 80));
    $l = json_decode(pedirApi($app, 'GET', 'coord/solicitudes'), true);
    chequear(is_array($l) && isset($l['error']) && !isset($l['solicitudes']), 'coord/solicitudes sin login no lista nada');
});

prueba('Bug N2: la simulación no expone nombre, equipo ni calidad jurídica de nadie', function () {
    $db = nuevaDb();
    $a = mk($db, 'Persona Honorarios Ficticia', 'diurno', 'H7', 'honorarios');
    $rutA = rutDe($db, $a);
    $sinDatos = function (array $r, string $caso): void {
        $txt = json_encode($r, JSON_UNESCAPED_UNICODE);
        chequear(!str_contains($txt, 'honorarios'), "$caso: no revela la calidad jurídica: $txt");
        chequear(!str_contains($txt, 'Persona Honorarios Ficticia'), "$caso: no revela el nombre");
        chequear(!array_key_exists('persona', $r), "$caso: no devuelve el bloque 'persona'");
    };
    // 1) la simulación (función de reglas) no devuelve datos de la persona
    $sinDatos(reg_crear($db, ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02', 'fraccion' => 'completo'], ['cfg' => cfg_defaults()], true), 'por id');
    // 2) la vía «solo el RUT» (fuera de nómina) ya no existe desde la etapa 2: se rechaza sin revelar nada
    $e = lanza(fn() => reg_crear($db, ['rut' => $rutA, 'nombre' => 'xx', 'jornada' => 'diurno', 'calidad' => 'titular',
                                       'equipo_id' => eid($db, 'H7'), 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02'], ['cfg' => cfg_defaults()], true), 422);
    chequear($e !== null && !str_contains($e->getMessage(), 'Honorarios'), 'por RUT: se rechaza sin revelar datos');
    // 3) por HTTP, simular siempre usa a la persona de la sesión: ver tests/casos/10_solicitudes_sesion.php
});

