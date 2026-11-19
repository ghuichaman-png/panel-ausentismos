<?php
/**
 * Ejecuta una petición contra public/api.php en un proceso aparte (para pruebas).
 * Uso: php api_sub.php <APP_DIR de prueba> <método> <ruta> [<cuerpo JSON>]
 * Imprime la respuesta tal cual la entrega api.php.
 */
declare(strict_types=1);

[, $appDir, $metodo, $ruta] = $argv;
define('APP_DIR', $appDir);
$_SERVER['REQUEST_METHOD'] = $metodo;
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_GET['r'] = $ruta;
ini_set('session.save_path', $appDir);
require dirname(__DIR__) . '/public/api.php';
