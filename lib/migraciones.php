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
        4 => 'mig_004_tipos',
        5 => 'mig_005_bloques',
    ];
}

/** Cupo por bloque (SPEC-v2 §4.2): cada día guarda cuánto ocupa de la mañana (am) y de la tarde (pm). */
function mig_005_bloques(PDO $db): void
{
    $db->exec('ALTER TABLE solicitud_dias ADD COLUMN am REAL NOT NULL DEFAULT 0');
    $db->exec('ALTER TABLE solicitud_dias ADD COLUMN pm REAL NOT NULL DEFAULT 0');
    $db->exec("UPDATE solicitud_dias SET
        am = CASE WHEN consumo > 0 AND fraccion IN ('completo','am') THEN 1 ELSE 0 END,
        pm = CASE WHEN consumo > 0 AND fraccion IN ('completo','pm') THEN 1 ELSE 0 END");
}

/**
 * Tipos de ausentismo configurables (SPEC-v2 §3.5). La columna solicitudes.tipo tenía CHECK (FL|ADM|CT) y SQLite
 * no permite cambiar un CHECK: se reconstruyen solicitudes y solicitud_dias copiando todo. Orden importante:
 * se crean las tablas nuevas, se copian, se borra primero la hija (solicitud_dias) y luego la madre, para que
 * ningún borrado en cascada alcance datos; después se renombran (SQLite actualiza las referencias).
 */
function mig_004_tipos(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE tipos_ausentismo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sigla TEXT NOT NULL UNIQUE,
  nombre TEXT NOT NULL,
  consume_cupo INTEGER NOT NULL DEFAULT 1,
  regla_cupo TEXT NOT NULL DEFAULT 'normal' CHECK (regla_cupo IN ('normal','fl')),
  saldo TEXT CHECK (saldo IN ('FL','ADM')),
  medio_dia INTEGER NOT NULL DEFAULT 0,
  lo_pide TEXT NOT NULL DEFAULT 'funcionario' CHECK (lo_pide IN ('funcionario','coordinacion')),
  exige_reemplazo INTEGER NOT NULL DEFAULT 1,
  rango TEXT NOT NULL DEFAULT 'rango' CHECK (rango IN ('un_dia','rango')),
  etiqueta_publica TEXT NOT NULL,
  es_cambio_turno INTEGER NOT NULL DEFAULT 0,
  precargado INTEGER NOT NULL DEFAULT 0,
  activo INTEGER NOT NULL DEFAULT 1,
  orden INTEGER NOT NULL DEFAULT 0
);
INSERT INTO tipos_ausentismo (sigla,nombre,consume_cupo,regla_cupo,saldo,medio_dia,lo_pide,exige_reemplazo,rango,etiqueta_publica,es_cambio_turno,precargado,orden) VALUES
 ('FL','Feriado legal',1,'fl','FL',0,'funcionario',1,'rango','FL',0,1,1),
 ('ADM','Permiso administrativo',1,'normal','ADM',1,'funcionario',1,'un_dia','ADM',0,1,2),
 ('CT','Cambio de turno',0,'normal',NULL,0,'funcionario',0,'un_dia','CT',1,1,3),
 ('LIC','Licencia médica',0,'normal',NULL,0,'coordinacion',0,'rango','Licencia',0,1,4);

CREATE TABLE solicitudes_n (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo TEXT NOT NULL UNIQUE,
  funcionario_id INTEGER REFERENCES funcionarios(id),
  nombre TEXT NOT NULL,
  nombre_norm TEXT NOT NULL,
  rut TEXT NOT NULL,
  jornada TEXT NOT NULL CHECK (jornada IN ('diurno','turnante')),
  equipo_id INTEGER REFERENCES equipos(id),
  calidad TEXT NOT NULL,
  tipo TEXT NOT NULL REFERENCES tipos_ausentismo(sigla),
  fecha_inicio TEXT NOT NULL,
  fecha_fin TEXT NOT NULL,
  fraccion TEXT NOT NULL DEFAULT 'completo',
  turno TEXT,
  unidad_turno_id INTEGER REFERENCES equipos(id),
  reemplazo_nombre TEXT,
  cambio_con TEXT,
  devolucion_fecha TEXT,
  devolucion_turno TEXT,
  comentario TEXT,
  sobre_cupo INTEGER NOT NULL DEFAULT 0,
  justificacion_sobre_cupo TEXT,
  estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','autorizada','rechazada','anulada')),
  motivo_resolucion TEXT,
  resuelto_por TEXT,
  resuelto_en TEXT,
  fuera_de_nomina INTEGER NOT NULL DEFAULT 0,
  creado_en TEXT NOT NULL,
  ip TEXT,
  user_agent TEXT,
  anulado_en TEXT,
  creada_por INTEGER REFERENCES funcionarios(id),
  resuelta_por_id INTEGER REFERENCES funcionarios(id)
);
INSERT INTO solicitudes_n SELECT id,codigo,funcionario_id,nombre,nombre_norm,rut,jornada,equipo_id,calidad,tipo,fecha_inicio,fecha_fin,
  fraccion,turno,unidad_turno_id,reemplazo_nombre,cambio_con,devolucion_fecha,devolucion_turno,comentario,sobre_cupo,justificacion_sobre_cupo,
  estado,motivo_resolucion,resuelto_por,resuelto_en,fuera_de_nomina,creado_en,ip,user_agent,anulado_en,creada_por,resuelta_por_id FROM solicitudes;

CREATE TABLE solicitud_dias_n (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  solicitud_id INTEGER NOT NULL REFERENCES solicitudes_n(id) ON DELETE CASCADE,
  fecha TEXT NOT NULL,
  equipo_id INTEGER,
  consumo REAL NOT NULL DEFAULT 0,
  fraccion TEXT NOT NULL DEFAULT 'completo',
  turno TEXT,
  unidad_turno_id INTEGER,
  reemplazo_nombre TEXT
);
INSERT INTO solicitud_dias_n SELECT id,solicitud_id,fecha,equipo_id,consumo,fraccion,turno,unidad_turno_id,reemplazo_nombre FROM solicitud_dias;

DROP TABLE solicitud_dias;
DROP TABLE solicitudes;
ALTER TABLE solicitudes_n RENAME TO solicitudes;
ALTER TABLE solicitud_dias_n RENAME TO solicitud_dias;
CREATE INDEX ix_sol_estado ON solicitudes(estado, fecha_inicio);
CREATE INDEX ix_sol_rut ON solicitudes(rut);
CREATE INDEX ix_sol_func ON solicitudes(funcionario_id);
CREATE INDEX ix_dias_fecha ON solicitud_dias(fecha, equipo_id);
CREATE INDEX ix_dias_sol ON solicitud_dias(solicitud_id);
SQL);
    // Comprobación dentro de la misma transacción: si algo no cuadra, la migración entera se revierte.
    if ((int)$db->query('SELECT COUNT(*) FROM solicitud_dias WHERE solicitud_id NOT IN (SELECT id FROM solicitudes)')->fetchColumn() > 0) {
        throw new RuntimeException('Migración 4: quedaron días sin solicitud.');
    }
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
