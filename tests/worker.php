<?php
declare(strict_types=1);
// Proceso de la prueba de concurrencia: php worker.php <db> <funcionario_id> <fecha> <instante_inicio>
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/lib/bootstrap.php';
date_default_timezone_set('America/Santiago');
Reloj::$fijo = new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('America/Santiago'));
[, $path, $fid, $fecha, $inicio] = $argv;
$db = db_open($path);
while (microtime(true) < (float)$inicio) usleep(200); // barrera: todos arrancan a la vez
try {
    $r = reg_crear($db, ['funcionario_id' => (int)$fid, 'tipo' => 'ADM', 'fecha_inicio' => $fecha, 'fraccion' => 'completo'],
                   ['ip' => '9.9.9.9', 'ua' => 'worker', 'cfg' => cfg_defaults()]);
    echo 'OK ' . $r['codigo'];
} catch (ApiError $e) {
    echo (($e->extra['codigo'] ?? '') === 'sin_cupo' ? 'SIN_CUPO' : 'ERROR ' . $e->getMessage());
}
