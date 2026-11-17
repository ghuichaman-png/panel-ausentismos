<?php
declare(strict_types=1);

require __DIR__ . '/app-dir.php';
require_once APP_DIR . '/lib/bootstrap.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/handlers_public.php';
require_once APP_DIR . '/lib/handlers_coord.php';

cabeceras_seguridad();

// [método, patrón de la ruta (parámetro ?r=), handler, requiere sesión de coordinación]
$rutas = [
    ['GET',  'config',                         'h_config',                  false],
    ['GET',  'nomina/buscar',                  'h_nomina_buscar',           false],
    ['GET',  'calendario',                     'h_calendario',              false],
    ['POST', 'solicitudes/simular',            'h_simular',                 false],
    ['POST', 'solicitudes',                    'h_crear',                   false],
    ['GET',  'solicitudes',                    'h_solicitudes_listar',      false],
    ['POST', 'solicitudes/(\d+)/anular',       'h_anular',                  false],

    ['GET',  'coord/sesion',                   'h_coord_sesion',            false],
    ['POST', 'coord/login',                    'h_coord_login',             false],
    ['POST', 'coord/logout',                   'h_coord_logout',            false],
    ['GET',  'coord/solicitudes',              'h_coord_solicitudes',       true],
    ['POST', 'coord/solicitudes/(\d+)/resolver', 'h_coord_resolver',        true],
    ['POST', 'coord/solicitudes/(\d+)/revertir', 'h_coord_revertir',        true],
    ['POST', 'coord/solicitudes/(\d+)/anular',   'h_coord_anular',          true],
    ['POST', 'coord/solicitudes/(\d+)/vincular', 'h_coord_vincular',        true],
    ['GET',  'coord/calendario',               'h_coord_calendario',        true],
    ['GET',  'coord/resumen',                  'h_coord_resumen',           true],
    ['GET',  'coord/exportar',                 'h_coord_exportar',          true],
    ['GET',  'coord/respaldo',                 'h_coord_respaldo',          true],
    ['GET',  'coord/nomina',                   'h_coord_nomina_listar',     true],
    ['POST', 'coord/nomina/importar',          'h_coord_nomina_importar',   true],
    ['POST', 'coord/nomina/(\d+)',             'h_coord_nomina_guardar',    true],
    ['POST', 'coord/nomina',                   'h_coord_nomina_guardar',    true],
    ['GET',  'coord/equipos',                  'h_coord_equipos_listar',    true],
    ['POST', 'coord/equipos/(\d+)',            'h_coord_equipos_guardar',   true],
    ['POST', 'coord/equipos',                  'h_coord_equipos_guardar',    true],
    ['GET',  'coord/feriados',                 'h_coord_feriados_listar',   true],
    ['POST', 'coord/feriados/eliminar',        'h_coord_feriados_eliminar', true],
    ['POST', 'coord/feriados',                 'h_coord_feriados_guardar',  true],
];

try {
    if (cfg('forzar_https') && !es_https()) throw new ApiError('Esta aplicación solo funciona por HTTPS.', 400);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ruta = trim((string)($_GET['r'] ?? ''), '/');
    $encontrada = false;
    foreach ($rutas as [$m, $patron, $handler, $auth]) {
        if ($m !== $metodo || !preg_match('#^' . $patron . '$#', $ruta, $mt)) continue;
        $encontrada = true;
        if ($auth) coord_requerir($metodo);
        $in = $metodo === 'POST' ? body_json() : [];
        json_out($handler(db(), $mt, $in));
    }
    throw new ApiError($encontrada ? 'Método no permitido.' : 'Ruta no encontrada.', $encontrada ? 405 : 404);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[ausentismos] ' . $e);
    json_out(['error' => 'Ocurrió un error inesperado. Intenta de nuevo.'], 500);
}
