<?php
/**
 * Ubica la carpeta privada de la aplicación (la que contiene lib/ y config.php).
 *
 * En DreamHost, sube lib/ y config.php a /home/USUARIO/ausentismos-app/ (fuera del dominio)
 * y escribe esa ruta abajo. En desarrollo local no hace falta tocar nada.
 */
$APP_DIR_PRIVADA = ''; // ej.: '/home/USUARIO/ausentismos-app'

if (!defined('APP_DIR')) {
    $cand = array_filter([$APP_DIR_PRIVADA, dirname(__DIR__), __DIR__]);
    foreach ($cand as $c) {
        if (is_dir($c . '/lib')) { define('APP_DIR', $c); break; }
    }
    if (!defined('APP_DIR')) {
        http_response_code(500);
        exit('No se encontró la carpeta lib/. Revisa app-dir.php.');
    }
}
