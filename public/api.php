<?php
declare(strict_types=1);

require __DIR__ . '/app-dir.php';
require_once APP_DIR . '/lib/bootstrap.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/handlers_public.php';
require_once APP_DIR . '/lib/handlers_coord.php';

cabeceras_seguridad();

// [método, patrón de la ruta (parámetro ?r=), handler, acceso]
// acceso: 'abierta' (sin sesión) · 'sesion' (cualquier persona con sesión) · 'coord' (rol coordinación).
// Toda escritura con sesión exige además el token CSRF de esa sesión (lib/auth.php).
$rutas = [
    ['GET',  'auth/sesion',                    'h_auth_sesion',             'abierta'],
    ['POST', 'auth/activar',                   'h_auth_activar',            'abierta'],
    ['POST', 'auth/ingresar',                  'h_auth_ingresar',           'abierta'],
    ['POST', 'auth/salir',                     'h_auth_salir',              'sesion'],
    ['POST', 'auth/pin',                       'h_auth_pin',                'sesion'],
    ['GET',  'auth/sesiones',                  'h_auth_sesiones',           'sesion'],
    ['POST', 'auth/sesiones/(\d+)/cerrar',     'h_auth_sesion_cerrar',      'sesion'],
    ['POST', 'auth/sesiones/cerrar-otras',     'h_auth_sesiones_cerrar_otras', 'sesion'],

    ['GET',  'config',                         'h_config',                  'sesion'],
    ['GET',  'nomina/buscar',                  'h_nomina_buscar',           'sesion'],
    ['GET',  'calendario',                     'h_calendario',              'sesion'],
    ['POST', 'solicitudes/simular',            'h_simular',                 'sesion'],
    ['POST', 'solicitudes',                    'h_crear',                   'sesion'],
    ['GET',  'solicitudes',                    'h_solicitudes_listar',      'sesion'],
    ['POST', 'solicitudes/(\d+)/anular',       'h_anular',                  'sesion'],

    ['GET',  'coord/solicitudes',              'h_coord_solicitudes',       'coord'],
    ['POST', 'coord/solicitudes/(\d+)/resolver', 'h_coord_resolver',        'coord'],
    ['POST', 'coord/solicitudes/(\d+)/revertir', 'h_coord_revertir',        'coord'],
    ['POST', 'coord/solicitudes/(\d+)/anular',   'h_coord_anular',          'coord'],
    ['POST', 'coord/solicitudes/(\d+)/vincular', 'h_coord_vincular',        'coord'],
    ['GET',  'coord/calendario',               'h_coord_calendario',        'coord'],
    ['GET',  'coord/resumen',                  'h_coord_resumen',           'coord'],
    ['GET',  'coord/exportar',                 'h_coord_exportar',          'coord'],
    ['GET',  'coord/respaldo',                 'h_coord_respaldo',          'coord'],
    ['GET',  'coord/nomina',                   'h_coord_nomina_listar',     'coord'],
    ['POST', 'coord/nomina/importar',          'h_coord_nomina_importar',   'coord'],
    ['POST', 'coord/nomina/(\d+)/codigo',      'h_coord_cuenta_codigo',     'coord'],
    ['POST', 'coord/nomina/(\d+)/desbloquear', 'h_coord_cuenta_desbloquear', 'coord'],
    ['POST', 'coord/nomina/(\d+)/cerrar-sesiones', 'h_coord_cuenta_cerrar_sesiones', 'coord'],
    ['POST', 'coord/nomina/(\d+)/rol',         'h_coord_cuenta_rol',        'coord'],
    ['POST', 'coord/nomina/(\d+)',             'h_coord_nomina_guardar',    'coord'],
    ['POST', 'coord/nomina',                   'h_coord_nomina_guardar',    'coord'],
    ['GET',  'coord/equipos',                  'h_coord_equipos_listar',    'coord'],
    ['POST', 'coord/equipos/(\d+)',            'h_coord_equipos_guardar',   'coord'],
    ['POST', 'coord/equipos',                  'h_coord_equipos_guardar',   'coord'],
    ['GET',  'coord/feriados',                 'h_coord_feriados_listar',   'coord'],
    ['POST', 'coord/feriados/eliminar',        'h_coord_feriados_eliminar', 'coord'],
    ['POST', 'coord/feriados',                 'h_coord_feriados_guardar',  'coord'],
];

try {
    if (cfg('forzar_https') && !es_https()) throw new ApiError('Esta aplicación solo funciona por HTTPS.', 400);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ruta = trim((string)($_GET['r'] ?? ''), '/');
    $existe = false;
    foreach ($rutas as [$m, $patron, $handler, $acceso]) {
        if (!preg_match('#^' . $patron . '$#', $ruta, $mt)) continue;
        $existe = true;
        if ($m !== $metodo) continue;
        acceso_requerir($acceso, $metodo);
        $in = $metodo === 'POST' ? body_json() : [];
        json_out($handler(db(), $mt, $in));
    }
    throw new ApiError($existe ? 'Método no permitido.' : 'Ruta no encontrada.', $existe ? 405 : 404);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[ausentismos] ' . $e);
    json_out(['error' => 'Ocurrió un error inesperado. Intenta de nuevo.'], 500);
}
