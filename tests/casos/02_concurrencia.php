<?php
declare(strict_types=1);

prueba('Concurrencia: dos procesos piden el último cupo → solo uno sin sobre cupo', function () {
    $db = nuevaDb();
    $db->exec("UPDATE equipos SET cupo_diurno=1 WHERE nombre='H7'");
    $ids = [mk($db, 'Ana Uno', 'diurno', 'H7'), mk($db, 'Beto Dos', 'diurno', 'H7'), mk($db, 'Carla Tres', 'diurno', 'H7'), mk($db, 'Dani Cuatro', 'diurno', 'H7')];
    $db = null; // cerrar conexión del padre
    global $dbpath;
    $inicio = microtime(true) + 1.5;
    $procs = [];
    foreach ($ids as $id) {
        $cmd = [PHP_BINARY, TESTS_DIR . '/worker.php', $dbpath, (string)$id, '2026-12-10', (string)$inicio];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $res = [];
    foreach ($procs as [$p, $pipes]) {
        $res[] = trim((string)stream_get_contents($pipes[1])) . trim((string)stream_get_contents($pipes[2]));
        proc_close($p);
    }
    $oks = count(array_filter($res, fn($r) => str_starts_with($r, 'OK')));
    $sin = count(array_filter($res, fn($r) => str_starts_with($r, 'SIN_CUPO')));
    chequear($oks === 1 && $sin === 3, "1 OK y 3 SIN_CUPO, obtenido: " . implode(' | ', $res));
    $db = db_open($dbpath);
    chequear(ocup($db, 'H7', '2026-12-10') === 1.0, 'la ocupación final es exactamente 1');
});

