<?php
/**
 * Copiar a config.php y ajustar. config.php NUNCA debe quedar accesible por web
 * (subirlo junto a lib/ en la carpeta privada, fuera del dominio público).
 *
 * El acceso es por usuario (RUT + PIN). La primera persona de coordinación se crea en install.php
 * (o con: php herramientas/codigo-acceso.php <RUT> --coordinacion ...).
 */
return [
    'titulo'   => 'Ausentismos · Kinesiología HPM',
    'logo'     => '',   // ruta relativa a la web, p. ej. 'assets/logo.png' (vacío = sin logo)

    // Archivo SQLite: debe estar FUERA de la carpeta pública.
    'db_path'  => '/home/USUARIO/ausentismos-data/ausentismos.sqlite',

    'timezone' => 'America/Santiago',
    'forzar_https' => true,          // poner false solo en desarrollo local

    // Anti-abuso
    'limite_envios_hora'   => 10,    // solicitudes por persona por hora
    'limite_ip_auth_hora'  => 300,   // intentos de ingreso por IP por hora (alto: todo el hospital comparte IP)

    // Reglas
    'fl_largo_dias_habiles' => 5,    // FL con >= N días hábiles (titular/contrata) no consume cupo
    'anticipacion_min_dias' => null, // null = desactivado
    'horizonte_max_dias'    => null, // null = desactivado
];
