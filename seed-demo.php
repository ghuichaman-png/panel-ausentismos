<?php
declare(strict_types=1);
/**
 * Datos de DEMOSTRACIÓN (opcional, separado de la instalación). Solo por línea de comandos:
 *   php seed-demo.php            (falla si la nómina ya tiene gente)
 *   php seed-demo.php --force    (agrega igual)
 * Crea ~70 funcionarios ficticios (RUT válidos inventados) y solicitudes variadas desde hoy.
 * No usar en producción real.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
define('APP_DIR', __DIR__);
require __DIR__ . '/lib/bootstrap.php';

$db = db();
if ((int)$db->query('SELECT COUNT(*) FROM funcionarios')->fetchColumn() > 0 && !in_array('--force', $argv, true)) {
    exit("La nómina ya tiene datos. Usa --force para agregar igualmente.\n");
}
mt_srand(2026);

$nombres = ['Camila','Javiera','Constanza','Fernanda','Valentina','Catalina','Francisca','Daniela','Paula','Macarena','Antonia','Sofía',
            'Matías','Sebastián','Felipe','Nicolás','Cristóbal','Diego','Ignacio','Tomás','Benjamín','Rodrigo','Andrés','Pablo'];
$apellidos = ['Alarcón','Barría','Cárdenas','Delgado','Espinoza','Figueroa','Gallardo','Huenchumilla','Illanes','Jaramillo','Kramer','Levicán',
              'Mansilla','Navarro','Oyarzún','Paredes','Quintana','Ríos','Soto','Treuquil','Uribe','Vidal','Wilckens','Yáñez','Zúñiga','Ojeda','Vargas','Millán'];

$equipos = $db->query('SELECT * FROM equipos WHERE activo=1')->fetchAll();
$diurnosEq = array_values(array_filter($equipos, fn($e) => $e['tiene_diurnos']));
$turEq = array_values(array_filter($equipos, fn($e) => $e['tiene_turnantes']));
$calidades = ['titular', 'titular', 'titular', 'contrata', 'contrata', 'honorarios'];

$ins = $db->prepare('INSERT INTO funcionarios (nombre,nombre_norm,rut,jornada,equipo_id,calidad,activo) VALUES (?,?,?,?,?,?,1)');
$usados = [];
$creados = 0;
for ($i = 0; $i < 70; $i++) {
    do { $n = mt_rand(10000000, 24999999); } while (isset($usados[$n]));
    $usados[$n] = true;
    $rut = $n . '-' . rut_dv((string)$n);
    $nombre = $nombres[array_rand($nombres)] . ' ' . $apellidos[array_rand($apellidos)] . ' ' . $apellidos[array_rand($apellidos)];
    $turn = $i < 25;
    $eq = $turn ? null : $diurnosEq[$i % count($diurnosEq)]['id'];
    $ins->execute([$nombre, texto_norm($nombre), $rut, $turn ? 'turnante' : 'diurno', $eq, $calidades[array_rand($calidades)]]);
    $creados++;
}
echo "Funcionarios creados: $creados\n";

$funcs = $db->query('SELECT * FROM funcionarios')->fetchAll();
$hoy = Reloj::hoy();
$fin = (new DateTimeImmutable($hoy))->modify('last day of next month')->format('Y-m-d');
$span = (int)(new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($fin))->days;
$ok = 0; $fallos = 0;
foreach ($funcs as $f) {
    $veces = mt_rand(0, 2);
    for ($k = 0; $k < $veces; $k++) {
        $ini = fecha_add($hoy, mt_rand(1, $span));
        $tipoSel = mt_rand(1, 10);
        try {
            if ($f['jornada'] === 'diurno') {
                $in = ['funcionario_id' => $f['id'], 'acepta_sobre_cupo' => mt_rand(1, 6) === 1, 'justificacion' => 'Trámite personal (demo)'];
                if ($tipoSel <= 5) $in += ['tipo' => 'ADM', 'fecha_inicio' => $ini, 'fraccion' => ['completo', 'completo', 'am', 'pm'][mt_rand(0, 3)]];
                else $in += ['tipo' => 'FL', 'fecha_inicio' => $ini, 'fecha_fin' => fecha_add($ini, mt_rand(2, 12))];
            } else {
                $u = $turEq[array_rand($turEq)]['id'];
                $dia = fn($fch) => ['fecha' => $fch, 'turno' => mt_rand(0, 1) ? 'largo' : 'noche', 'unidad_id' => $u, 'reemplazo' => 'Reemplazo Demo'];
                if ($tipoSel <= 4) $in = ['funcionario_id' => $f['id'], 'tipo' => 'FL', 'dias' => [$dia($ini), $dia(fecha_add($ini, 4))]];
                elseif ($tipoSel <= 7) $in = ['funcionario_id' => $f['id'], 'tipo' => 'ADM', 'fraccion' => 'completo', 'dias' => [$dia($ini)]];
                else $in = ['funcionario_id' => $f['id'], 'tipo' => 'CT', 'cambio_con' => 'Colega Demo', 'dias' => [$dia($ini)]];
            }
            $r = reg_crear($db, $in, ['ip' => '127.0.0.1', 'ua' => 'seed-demo', 'cfg' => cfg()]);
            $ok++;
            $x = mt_rand(1, 10);
            if ($x <= 5) reg_resolver($db, $r['id'], 'autorizar', '', 'DEMO');
            elseif ($x <= 6) reg_resolver($db, $r['id'], 'rechazar', 'Falta de cobertura en esa fecha (demo)', 'DEMO');
        } catch (ApiError $e) {
            $fallos++; // sin cupo, superposición, etc.: es esperable
        }
    }
}
echo "Solicitudes creadas: $ok (omitidas por reglas: $fallos)\n";
