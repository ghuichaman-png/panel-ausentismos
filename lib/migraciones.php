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
        2 => 'mig_002_telefono_rol',
        3 => 'mig_003_cuentas_sesiones',
    ];
}

/** Login por usuario (SPEC-v2 §3.3 y §5): credenciales con PIN/código y sesiones por dispositivo. */
function mig_003_cuentas_sesiones(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE credenciales (
  funcionario_id INTEGER PRIMARY KEY REFERENCES funcionarios(id),
  pin_hash TEXT,
  codigo_hash TEXT,
  codigo_vence TEXT,
  intentos_fallidos INTEGER NOT NULL DEFAULT 0,
  bloqueos INTEGER NOT NULL DEFAULT 0,
  bloqueado_hasta TEXT,
  actualizado_en TEXT
);
CREATE TABLE sesiones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  csrf TEXT NOT NULL,
  funcionario_id INTEGER NOT NULL REFERENCES funcionarios(id),
  creada TEXT NOT NULL,
  ultimo_uso TEXT NOT NULL,
  vence TEXT NOT NULL,
  compartido INTEGER NOT NULL DEFAULT 0,
  user_agent TEXT,
  ip TEXT,
  revocada INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX ix_sesiones_func ON sesiones(funcionario_id, revocada);
ALTER TABLE solicitudes ADD COLUMN creada_por INTEGER REFERENCES funcionarios(id);
ALTER TABLE solicitudes ADD COLUMN resuelta_por_id INTEGER REFERENCES funcionarios(id);
ALTER TABLE auditoria ADD COLUMN funcionario_id INTEGER;
SQL);
}

/** Nómina: teléfono (solo lo ve coordinación) y rol por persona (SPEC-v2 §3.2). */
function mig_002_telefono_rol(PDO $db): void
{
    $db->exec('ALTER TABLE funcionarios ADD COLUMN telefono TEXT');
    $db->exec("ALTER TABLE funcionarios ADD COLUMN rol TEXT NOT NULL DEFAULT 'funcionario' CHECK (rol IN ('funcionario','coordinacion'))");
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
