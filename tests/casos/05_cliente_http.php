<?php
declare(strict_types=1);

// Comprueba el cliente HTTP de pruebas (sesión + CSRF entre peticiones), base de los tests de login de la etapa 2.

prueba('Cliente HTTP: login de coordinación, la sesión se mantiene y el CSRF es obligatorio', function () {
    $app = appTemporal(['clave_hash' => password_hash('clave-de-prueba-larga', PASSWORD_DEFAULT)]);
    $c = new ClienteApi($app);

    $r = $c->pedir('POST', 'coord/login', ['clave' => 'incorrecta']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'Clave incorrecta'), 'clave incorrecta rechazada: ' . $r['raw']);

    $r = $c->pedir('POST', 'coord/login', ['clave' => 'clave-de-prueba-larga']);
    chequear(($r['json']['autenticado'] ?? false) === true && $c->csrf !== null, 'login correcto entrega CSRF: ' . $r['raw']);

    $r = $c->pedir('GET', 'coord/sesion');
    chequear(($r['json']['autenticado'] ?? false) === true, 'la sesión se mantiene en la petición siguiente');

    $r = $c->pedir('GET', 'coord/equipos');
    chequear(count($r['json']['equipos'] ?? []) === 19, 'con sesión lista los 19 equipos');

    $r = $c->pedir('POST', 'coord/feriados', ['fecha' => '2026-12-25', 'nombre' => 'Navidad']);
    chequear(($r['json']['guardados'] ?? 0) === 1, 'escritura con CSRF funciona: ' . $r['raw']);

    $sinCsrf = clone $c;
    $sinCsrf->csrf = null;
    $r = $sinCsrf->pedir('POST', 'coord/feriados', ['fecha' => '2026-12-31', 'nombre' => 'Fin de año']);
    chequear(str_contains((string)($r['json']['error'] ?? ''), 'Token de seguridad'), 'sin CSRF se rechaza: ' . $r['raw']);

    $otro = new ClienteApi($app);
    $r = $otro->pedir('GET', 'coord/equipos');
    chequear(isset($r['json']['error']) && !isset($r['json']['equipos']), 'otro cliente sin login no accede');
    chequear($r['error'] === null && $c->pedir('GET', 'coord/sesion')['error'] === null, 'sin advertencias de PHP en las peticiones');
});
