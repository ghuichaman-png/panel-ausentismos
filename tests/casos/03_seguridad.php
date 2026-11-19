<?php
declare(strict_types=1);

// Bugs de seguridad corregidos (etapa 0).

prueba('Bug N1: coordinación nunca queda abierta sin clave, aunque config.php traiga coord_sin_clave', function () {
    $app = appTemporal(['coord_sin_clave' => true, 'clave_hash' => password_hash('clave-de-prueba-larga', PASSWORD_DEFAULT)]);
    $s = json_decode(pedirApi($app, 'GET', 'coord/sesion'), true);
    chequear(is_array($s) && ($s['autenticado'] ?? null) === false, 'coord/sesion sin login no está autenticada: ' . json_encode($s));
    chequear(is_array($s) && array_key_exists('csrf', $s) && $s['csrf'] === null, 'coord/sesion sin login no entrega token CSRF');
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
    // 1) eligiendo a otra persona por id (sin login, cualquiera puede hacerlo)
    $sinDatos(h_simular($db, [], ['funcionario_id' => $a, 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02', 'fraccion' => 'completo']), 'por id');
    // 2) escribiendo solo el RUT de otra persona en «No estoy en la lista»
    $sinDatos(h_simular($db, [], ['rut' => $rutA, 'nombre' => 'xx', 'jornada' => 'diurno', 'calidad' => 'titular',
                                 'equipo_id' => eid($db, 'H7'), 'tipo' => 'ADM', 'fecha_inicio' => '2026-12-02']), 'por RUT');
});

