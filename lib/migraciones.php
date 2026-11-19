<?php
declare(strict_types=1);

/**
 * Migraciones del esquema, numeradas. La versión aplicada se guarda en PRAGMA user_version.
 *
 * Reglas:
 *  - Nunca se edita una migración ya publicada: los cambios van en una migración nueva.
 *  - Cada migración corre en su propia transacción BEGIN IMMEDIATE: o se aplica entera o no se aplica.
 *  - Antes de migrar una base que ya tiene datos se deja un respaldo junto a ella (VACUUM INTO).
 */

/** @return array<int, callable(PDO):void> */
function migraciones(): array
{
    return [
        1 => 'db_esquema_v1',
    ];
}

function db_version(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

function db_version_ultima(): int
{
    return max(array_keys(migraciones()));
}

/**
 * Aplica las migraciones pendientes. Devuelve los números aplicados.
 * $ruta: archivo de la base; si se indica y la base ya tiene tablas, se respalda antes de migrar.
 * $lista: solo para pruebas (reemplaza la lista de migraciones).
 */
function db_migrar(PDO $db, ?string $ruta = null, ?array $lista = null): array
{
    $lista ??= migraciones();
    ksort($lista);
    $pendientes = array_filter(array_keys($lista), fn($n) => $n > db_version($db));
    if (!$pendientes) return [];

    $tieneTablas = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn() > 0;
    if ($ruta !== null && $tieneTablas) db_respaldar_antes($db, $ruta);

    $aplicadas = [];
    foreach ($pendientes as $n) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (db_version($db) >= $n) { $db->exec('COMMIT'); continue; } // otro proceso ya la aplicó
            ($lista[$n])($db);
            $db->exec('PRAGMA user_version = ' . (int)$n);
            $db->exec('COMMIT');
            $aplicadas[] = $n;
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $_) { /* sin transacción abierta */ }
            throw $e;
        }
    }
    return $aplicadas;
}

/** Copia consistente de la base antes de migrar: <ruta>.antes-v<versión>-<fecha>.sqlite (no sobrescribe). */
function db_respaldar_antes(PDO $db, string $ruta): string
{
    $destino = sprintf('%s.antes-v%d-%s.sqlite', $ruta, db_version($db), date('Ymd-His'));
    if (is_file($destino)) return $destino;
    $db->exec("VACUUM INTO '" . str_replace("'", "''", $destino) . "'");
    return $destino;
}
