<?php
declare(strict_types=1);

function db_open(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout=8000');
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    return $pdo;
}

/** Ejecuta $fn dentro de BEGIN IMMEDIATE (toma el lock de escritura antes de leer cupos). */
function tx(PDO $db, callable $fn): mixed
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn();
        $db->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $_) { /* no había transacción abierta */ }
        throw $e;
    }
}

function db_schema(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS equipos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre TEXT NOT NULL UNIQUE,
  tiene_diurnos INTEGER NOT NULL DEFAULT 1,
  tiene_turnantes INTEGER NOT NULL DEFAULT 0,
  cupo_diurno REAL NOT NULL DEFAULT 2,
  activo INTEGER NOT NULL DEFAULT 1,
  orden INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS funcionarios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre TEXT NOT NULL,
  nombre_norm TEXT NOT NULL,
  rut TEXT NOT NULL UNIQUE,
  jornada TEXT NOT NULL CHECK (jornada IN ('diurno','turnante')),
  equipo_id INTEGER REFERENCES equipos(id),
  calidad TEXT NOT NULL CHECK (calidad IN ('titular','contrata','honorarios')),
  activo INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS ix_func_norm ON funcionarios(nombre_norm);
CREATE TABLE IF NOT EXISTS feriados (
  fecha TEXT PRIMARY KEY,
  nombre TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS solicitudes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo TEXT NOT NULL UNIQUE,
  funcionario_id INTEGER REFERENCES funcionarios(id),
  nombre TEXT NOT NULL,
  nombre_norm TEXT NOT NULL,
  rut TEXT NOT NULL,
  jornada TEXT NOT NULL CHECK (jornada IN ('diurno','turnante')),
  equipo_id INTEGER REFERENCES equipos(id),
  calidad TEXT NOT NULL,
  tipo TEXT NOT NULL CHECK (tipo IN ('FL','ADM','CT')),
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
  anulado_en TEXT
);
CREATE INDEX IF NOT EXISTS ix_sol_estado ON solicitudes(estado, fecha_inicio);
CREATE INDEX IF NOT EXISTS ix_sol_rut ON solicitudes(rut);
CREATE TABLE IF NOT EXISTS solicitud_dias (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  solicitud_id INTEGER NOT NULL REFERENCES solicitudes(id) ON DELETE CASCADE,
  fecha TEXT NOT NULL,
  equipo_id INTEGER,
  consumo REAL NOT NULL DEFAULT 0,
  fraccion TEXT NOT NULL DEFAULT 'completo',
  turno TEXT,
  unidad_turno_id INTEGER,
  reemplazo_nombre TEXT
);
CREATE INDEX IF NOT EXISTS ix_dias_fecha ON solicitud_dias(fecha, equipo_id);
CREATE INDEX IF NOT EXISTS ix_dias_sol ON solicitud_dias(solicitud_id);
CREATE TABLE IF NOT EXISTS auditoria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fecha TEXT NOT NULL,
  accion TEXT NOT NULL,
  solicitud_id INTEGER,
  detalle TEXT,
  ip TEXT
);
CREATE TABLE IF NOT EXISTS rate (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  clave TEXT NOT NULL,
  ts INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_rate ON rate(clave, ts);
SQL);
}

function db_sembrar_equipos(PDO $db): int
{
    if ((int)$db->query('SELECT COUNT(*) FROM equipos')->fetchColumn() > 0) return 0;
    // nombre, diurnos, turnantes, cupo
    $equipos = [
        ['H7', 1, 1, 2], ['H6', 1, 1, 2], ['UTI H5', 1, 1, 2], ['UTAC H5', 1, 0, 2], ['H5', 1, 1, 2],
        ['H3 Pensionados', 1, 0, 2], ['Q3 Neonatología', 1, 0, 2], ['Q2 UTI Qx', 0, 1, 0], ['H8', 1, 1, 2],
        ['Traumatología', 1, 0, 2], ['Neurología', 1, 0, 2], ['Cardio adultos', 1, 0, 2],
        ['Cardio infantil', 1, 0, 2], ['Piso pélvico', 1, 0, 2], ['Piscina terapéutica', 1, 0, 2],
        ['Oncología', 1, 0, 2], ['Respiratorio', 1, 0, 2],
        ['Oncología infantil', 1, 0, 2], ['Neurología infantil', 1, 0, 2],
    ];
    $st = $db->prepare('INSERT INTO equipos (nombre,tiene_diurnos,tiene_turnantes,cupo_diurno,activo,orden) VALUES (?,?,?,?,1,?)');
    foreach ($equipos as $i => $e) $st->execute([$e[0], $e[1], $e[2], $e[3], $i + 1]);
    return count($equipos);
}

function auditar(PDO $db, string $accion, ?int $solId, string $detalle = '', string $ip = ''): void
{
    $db->prepare('INSERT INTO auditoria (fecha,accion,solicitud_id,detalle,ip) VALUES (?,?,?,?,?)')
       ->execute([Reloj::stamp(), $accion, $solId, mb_substr($detalle, 0, 2000), $ip]);
}

function rate_contar(PDO $db, string $clave, int $ventanaSeg): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM rate WHERE clave=? AND ts>?');
    $st->execute([$clave, time() - $ventanaSeg]);
    return (int)$st->fetchColumn();
}

function rate_registrar(PDO $db, string $clave): void
{
    $db->prepare('INSERT INTO rate (clave,ts) VALUES (?,?)')->execute([$clave, time()]);
    if (random_int(1, 50) === 1) $db->prepare('DELETE FROM rate WHERE ts<?')->execute([time() - 86400]);
}
