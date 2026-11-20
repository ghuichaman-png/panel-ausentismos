<?php
/**
 * Ejecuta public/install.php como si fuera una petición web POST (para pruebas).
 * Uso: php install_sub.php <APP_DIR de prueba> @<archivo con JSON de los campos del formulario>
 */
declare(strict_types=1);

[, $appDir, $arg] = $argv;
define('APP_DIR', $appDir);
define('AUS_PRUEBA_WEB', true);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = json_decode((string)file_get_contents(substr($arg, 1)), true) ?: [];
require dirname(__DIR__) . '/public/install.php';
