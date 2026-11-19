<?php
declare(strict_types=1);
/**
 * Instalador. Crea la base de datos SQLite, el esquema, los 19 equipos iniciales y la PRIMERA persona de
 * coordinación, y muestra su código de activación (una sola vez). Se auto-deshabilita al terminar
 * (deja install.lock junto a la base). Después de instalar: BORRA este archivo del servidor.
 *
 * Uso: abrir en el navegador. Por SSH: `php install.php` crea la base y los equipos; la primera
 * coordinadora se crea con `php herramientas/codigo-acceso.php <RUT> --coordinacion --nombre=... ...`.
 */
require __DIR__ . '/app-dir.php';
require_once APP_DIR . '/lib/bootstrap.php';
require_once APP_DIR . '/lib/handlers_coord.php';

$cli = PHP_SAPI === 'cli' && !defined('AUS_PRUEBA_WEB');
$msgs = [];
$error = '';
$codigo = '';

$dbPath = (string)cfg('db_path');
$lock = $dbPath !== '' ? dirname($dbPath) . '/install.lock' : '';
$yaInstalado = $lock !== '' && is_file($lock);

function salida(string $titulo, array $msgs, string $error, string $codigo, bool $yaInstalado, bool $cli): void
{
    if ($cli) {
        echo $titulo . PHP_EOL . implode(PHP_EOL, $msgs) . PHP_EOL . ($error ? "ERROR: $error" . PHP_EOL : '');
        return;
    }
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Instalación</title><style>body{font:16px system-ui,sans-serif;background:#f4f7f6;color:#0f1d1a;max-width:640px;margin:40px auto;padding:0 16px}';
    echo 'button{font:inherit;font-weight:600;padding:12px 24px;border:0;border-radius:999px;background:#08766b;color:#fff;cursor:pointer}';
    echo 'label{display:block;font-weight:600;margin:12px 0 4px}input,select{font:inherit;padding:10px;width:100%;box-sizing:border-box;border:1px solid #dbe3e0;border-radius:14px}';
    echo '.cod{font-size:32px;font-weight:700;letter-spacing:.1em;background:#d7f0eb;padding:12px 20px;border-radius:14px;display:inline-block}.err{color:#c0301e;font-weight:600}.ok{color:#1e7448}</style>';
    echo '<h1>' . $e($titulo) . '</h1>';
    if ($error) echo '<p class="err">' . $e($error) . '</p>';
    foreach ($msgs as $m) echo '<p class="ok">' . $e($m) . '</p>';
    if ($codigo) {
        echo '<p>Código de activación de la primera persona de coordinación (se muestra <strong>una sola vez</strong>; anótalo):</p>';
        echo '<p class="cod">' . $e($codigo) . '</p><p>Entra a la app, elige «Activar con código», escribe tu RUT y este código, y crea tu PIN. Vence en 7 días.</p>';
    }
    if (!$yaInstalado) {
        $opts = implode('', array_map(fn($q) => '<option>' . $e($q[0]) . '</option>', array_filter(db_equipos_iniciales(), fn($q) => $q[1])));
        echo '<form method="post" autocomplete="off"><input type="hidden" name="accion" value="instalar">';
        echo '<h2>Primera persona de coordinación</h2><p>Con su cuenta se carga después la nómina y se entregan los códigos al resto.</p>';
        echo '<label for="n">Nombre completo</label><input id="n" name="nombre" required>';
        echo '<label for="r">RUT</label><input id="r" name="rut" placeholder="12.345.678-5" required>';
        echo '<label for="j">Jornada</label><select id="j" name="jornada"><option value="diurno">Diurno</option><option value="turnante">Turnante</option></select>';
        echo '<label for="q">Equipo (obligatorio si es diurno)</label><select id="q" name="equipo"><option value="">(sin equipo)</option>' . $opts . '</select>';
        echo '<label for="c">Calidad</label><select id="c" name="calidad"><option value="titular">Titular</option><option value="contrata">Contrata</option><option value="honorarios">Honorarios</option></select>';
        echo '<p><button>Instalar ahora</button></p></form>';
    } else {
        echo '<p><strong>Instalación terminada.</strong> Borra install.php del servidor.</p>';
    }
}

try {
    if ($yaInstalado) {
        if (!$cli) http_response_code(403);
        salida('Instalación', [], 'El sistema ya está instalado. Borra install.php del servidor.', '', true, $cli);
        exit;
    }
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Falta la extensión pdo_sqlite en este PHP.');
    if (!is_file(APP_DIR . '/config.php')) throw new RuntimeException('Falta config.php: copia config.example.php como config.php y complétalo.');
    if ($dbPath === '' || str_contains($dbPath, 'USUARIO')) throw new RuntimeException('Ajusta db_path en config.php (ruta fuera de la carpeta pública).');

    $accion = $cli ? 'instalar' : ($_POST['accion'] ?? '');
    if ($accion === 'instalar') {
        if (!$cli && trim((string)($_POST['nombre'] ?? '')) === '') throw new RuntimeException('Completa los datos de la primera persona de coordinación (nombre, RUT, jornada, equipo y calidad).');
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException("No pude crear la carpeta $dir");
        $pdo = db_open($dbPath);
        db_schema($pdo);
        $n = db_sembrar_equipos($pdo);
        if (!$cli) {
            $eqId = null;
            if (($_POST['equipo'] ?? '') !== '') {
                $st = $pdo->prepare('SELECT id FROM equipos WHERE nombre=?');
                $st->execute([(string)$_POST['equipo']]);
                $eqId = $st->fetchColumn() ?: null;
            }
            $v = nomina_validar($pdo, ['nombre' => (string)$_POST['nombre'], 'rut' => (string)($_POST['rut'] ?? ''), 'jornada' => (string)($_POST['jornada'] ?? ''),
                                       'calidad' => (string)($_POST['calidad'] ?? ''), 'equipo_id' => $eqId]);
            $pdo->prepare("INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,rol,activo) VALUES (?,?,?,?,?,?,'coordinacion',1)")
                ->execute([$v['nombre'], texto_norm($v['nombre']), $v['rut'], $v['jornada'], $v['equipo_id'], $v['calidad']]);
            $codigo = cuenta_generar_codigo($pdo, (int)$pdo->lastInsertId(), null, 'instalador');
        }
        file_put_contents($lock, 'instalado ' . date('c') . PHP_EOL);
        $msgs[] = "Base de datos creada en $dbPath";
        $msgs[] = $n ? "Se sembraron $n equipos." : 'Los equipos ya existían.';
        if ($cli) $msgs[] = 'Crea la primera persona de coordinación con: php herramientas/codigo-acceso.php <RUT> --coordinacion --nombre="..." --jornada=diurno --equipo=H7 --calidad=titular';
        $msgs[] = 'Siguiente paso: BORRA install.php del servidor.';
        $yaInstalado = true;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
salida('Instalación · Ausentismos', $msgs, $error, $codigo, $yaInstalado, $cli);
