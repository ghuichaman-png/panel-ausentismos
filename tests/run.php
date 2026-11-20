<?php
declare(strict_types=1);
/**
 * Pruebas sin dependencias:  php tests/run.php
 * Los casos están en tests/casos/*.php (se cargan en orden alfabético); los ayudantes en tests/ayudantes.php.
 */
require __DIR__ . '/ayudantes.php';

foreach (glob(__DIR__ . '/casos/*.php') ?: [] as $caso) {
    echo "\n── " . basename($caso, '.php') . "\n";
    require $caso;
}

echo "\n" . ($GLOBALS['fallos'] === 0 ? "TODO OK" : "FALLARON {$GLOBALS['fallos']}") . " ({$GLOBALS['total']} verificaciones)\n";
unset($GLOBALS['__db']);   // conexiones abiertas impiden borrar en Windows
gc_collect_cycles();
// Borra la carpeta temporal de la corrida (incluye las apps temporales con subcarpetas).
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($tmp);
exit($GLOBALS['fallos'] === 0 ? 0 : 1);
