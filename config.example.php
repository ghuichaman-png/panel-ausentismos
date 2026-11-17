<?php
/**
 * Copiar a config.php y ajustar. config.php NUNCA debe quedar accesible por web
 * (subirlo junto a lib/ en la carpeta privada, fuera del dominio público).
 *
 * Generar el hash de la clave de coordinación:
 *   - abrir install.php en el navegador (sección "Generar hash"), o
 *   - php -r "echo password_hash('MI-CLAVE', PASSWORD_DEFAULT), PHP_EOL;"
 */
return [
    'titulo'   => 'Ausentismos · Kinesiología HPM',
    'logo'     => '',   // ruta relativa a la web, p. ej. 'assets/logo.png' (vacío = sin logo)

    // Archivo SQLite: debe estar FUERA de la carpeta pública.
    'db_path'  => '/home/USUARIO/ausentismos-data/ausentismos.sqlite',

    // Coordinación SIN clave (la vista /coordinacion/ queda abierta a quien conozca el enlace).
    // Pon false y completa clave_hash si algún día quieres exigir clave.
    'coord_sin_clave' => true,
    // password_hash() de la clave compartida (solo si coord_sin_clave es false). Ver README.
    'clave_hash' => '',

    'timezone' => 'America/Santiago',
    'forzar_https' => true,          // poner false solo en desarrollo local

    // Anti-abuso
    'limite_envios_hora'   => 10,    // solicitudes por IP por hora
    'login_max_intentos'   => 5,     // intentos fallidos por IP
    'login_bloqueo_min'    => 15,    // minutos de bloqueo
    'sesion_horas'         => 8,     // inactividad máxima de la sesión de coordinación

    // Reglas
    'fl_largo_dias_habiles' => 5,    // FL con >= N días hábiles (titular/contrata) no consume cupo
    'anticipacion_min_dias' => null, // null = desactivado
    'horizonte_max_dias'    => null, // null = desactivado
];
