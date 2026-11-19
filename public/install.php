<?php
declare(strict_types=1);
/**
 * Instalador. Crea la base de datos SQLite, el esquema y los equipos iniciales.
 * Se auto-deshabilita al terminar (deja un archivo install.lock junto a la base).
 * Después de instalar: BORRA este archivo del servidor.
 *
 * Uso: abrir en el navegador, o por SSH:  php install.php
 */
require __DIR__ . '/app-dir.php';
require_once APP_DIR . '/lib/bootstrap.php';

$cli = PHP_SAPI === 'cli';
$msgs = [];
$error = '';
$hashGenerado = '';

$dbPath = (string)cfg('db_path');
$lock = $dbPath !== '' ? dirname($dbPath) . '/install.lock' : '';
$yaInstalado = $lock !== '' && is_file($lock);

function salida(string $titulo, array $msgs, string $error, string $hash, bool $yaInstalado, bool $cli): void
{
    if ($cli) {
        echo $titulo . PHP_EOL . implode(PHP_EOL, $msgs) . PHP_EOL . ($error ? "ERROR: $error" . PHP_EOL : '');
        return;
    }
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Instalación</title><style>body{font:16px system-ui,sans-serif;background:#fdf6e3;color:#2b3a39;max-width:640px;margin:40px auto;padding:0 16px}';
    echo 'button{font:inherit;padding:12px 20px;border:0;border-radius:10px;background:#416b66;color:#fdf6e3;cursor:pointer}input{font:inherit;padding:10px;width:100%;box-sizing:border-box;margin:8px 0}';
    echo 'code,pre{background:#f6ebcf;padding:2px 6px;border-radius:4px;word-break:break-all;white-space:pre-wrap}.err{color:#9b1c31;font-weight:600}.ok{color:#1e6b52}</style>';
    echo '<h1>' . $e($titulo) . '</h1>';
    if ($error) echo '<p class="err">' . $e($error) . '</p>';
    foreach ($msgs as $m) echo '<p class="ok">' . $e($m) . '</p>';
    if ($hash) echo '<p>Pega esto en <code>clave_hash</code> de config.php:</p><pre>' . $e($hash) . '</pre>';
    if (!$yaInstalado) {
        echo '<form method="post"><input type="hidden" name="accion" value="instalar"><button>Instalar ahora</button></form>';
        echo '<h2>Generar hash de la clave de coordinación</h2><form method="post" autocomplete="off"><input type="hidden" name="accion" value="hash">';
        echo '<label>Clave nueva<input type="password" name="clave" minlength="8" required></label><button>Generar hash</button></form>';
    } else {
        echo '<p><strong>Ya instalado.</strong> Borra install.php del servidor.</p>';
    }
}

try {
    if ($yaInstalado) {
        http_response_code(403);
        salida('Instalación', [], 'El sistema ya está instalado. Borra install.php del servidor.', '', true, $cli);
        exit;
    }
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Falta la extensión pdo_sqlite en este PHP.');
    if (!is_file(APP_DIR . '/config.php')) throw new RuntimeException('Falta config.php: copia config.example.php como config.php y complétalo.');
    if ($dbPath === '' || str_contains($dbPath, 'USUARIO')) throw new RuntimeException('Ajusta db_path en config.php (ruta fuera de la carpeta pública).');

    $accion = $cli ? 'instalar' : ($_POST['accion'] ?? '');
    if ($accion === 'hash') {
        $c = (string)($_POST['clave'] ?? '');
        if (strlen($c) < 8) throw new RuntimeException('Usa una clave de al menos 8 caracteres.');
        $hashGenerado = password_hash($c, PASSWORD_DEFAULT);
    } elseif ($accion === 'instalar') {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException("No pude crear la carpeta $dir");
        $pdo = db_open($dbPath);
        db_schema($pdo);
        $n = db_sembrar_equipos($pdo);
        file_put_contents($lock, 'instalado ' . date('c') . PHP_EOL);
        $msgs[] = "Base de datos creada en $dbPath";
        $msgs[] = $n ? "Se sembraron $n equipos." : 'Los equipos ya existían.';
        if ((string)cfg('clave_hash') === '') $msgs[] = 'Falta configurar clave_hash en config.php (genera el hash en esta misma página antes de instalar, o con php -r).';
        $msgs[] = 'Siguiente paso: BORRA install.php del servidor y carga la nómina desde /coordinacion.';
        $yaInstalado = true;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
salida('Instalación · Ausentismos', $msgs, $error, $hashGenerado, $yaInstalado, $cli);
