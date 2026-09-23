<?php

/**
 * Recalcula las anomalías con las reglas nuevas, usando las lecturas que
 * ya tenemos guardadas en la base. NO le vuelve a pedir nada a la API de
 * Emiliano.
 *
 * Para qué sirve: cuando cambio cómo se detecta algo (como el 23/09/2026,
 * que agregué el hueco máximo entre lecturas, el techo de velocidad
 * física y el control de "detenido" por GPS), las anomalías viejas que ya
 * quedaron guardadas siguen ahí, mal calculadas. El código nuevo solo
 * afecta a lo que se detecte de ahora en adelante. Este script borra las
 * calculadas de los últimos N días y las vuelve a sacar con las reglas
 * nuevas.
 *
 * Qué NO toca: el agua en combustible que viene del diagnóstico de TTM
 * (esa no sale de las lecturas), ni las lecturas, ni la flota.
 * Ojo: las anomalías que se vuelven a crear aparecen como "no resueltas",
 * aunque antes las hayas marcado bajando el PDF.
 *
 * Uso:
 *   php scripts/reprocesar_anomalias.php            (últimos 30 días, pide confirmación)
 *   php scripts/reprocesar_anomalias.php 14         (últimos 14 días)
 *   php scripts/reprocesar_anomalias.php 30 --confirmar   (sin preguntar)
 */

require_once __DIR__ . '/../bootstrap.php';

$dias = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (int) $argv[1]) : 30;
$sinPreguntar = in_array('--confirmar', $argv, true);

$storage = new Storage($config['db']);
$ahora = new DateTimeImmutable();
$desde = $ahora->modify("-{$dias} days");

$equipos = $storage->equiposConLecturasDesde($desde);
echo "Voy a borrar las anomalías calculadas de los últimos {$dias} días y recalcularlas\n";
echo "para " . count($equipos) . " equipos con lecturas guardadas.\n\n";

if (!$sinPreguntar) {
    echo "Escribí REPROCESAR para seguir, cualquier otra cosa cancela: ";
    if (trim(fgets(STDIN)) !== 'REPROCESAR') {
        echo "Cancelado, no toqué nada.\n";
        exit(0);
    }
}

$borradas = $storage->borrarAnomaliasCalculadasDesde($desde);
echo "Borradas {$borradas} anomalías viejas.\n";

// El detector mira 7 días para atrás cada vez que lo llamo. Para cubrir
// todo el período lo corro en "cortes" cada 6 días (con un día de
// solapamiento, así no se me escapa nada en los bordes). Las repetidas
// entre cortes no se duplican porque chequeo antes de guardar.
$detector = new AnomalyDetector($config['anomaly'], $storage);
$cortes = [];
for ($t = $desde->modify('+6 days'); $t < $ahora; $t = $t->modify('+6 days')) {
    $cortes[] = $t;
}
$cortes[] = $ahora;

$nuevas = 0;
$porTipo = [];
foreach ($equipos as $i => $equipoId) {
    $equipo = $storage->equipoPorId($equipoId);
    $capacidad = ($equipo && $equipo['capacidad_litros'] > 0)
        ? (float) $equipo['capacidad_litros']
        : $storage->capacidadEstimadaPorHistorial($equipoId);

    foreach ($cortes as $corte) {
        foreach ($detector->analizar($equipoId, $corte, $capacidad) as $a) {
            if ($a['fecha'] < $desde) {
                continue; // fuera del período que estoy reprocesando
            }
            if ($storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
                continue;
            }
            $storage->guardarAnomalia($a);
            $nuevas++;
            $porTipo[$a['tipo']] = ($porTipo[$a['tipo']] ?? 0) + 1;
        }
    }

    if (($i + 1) % 50 === 0) {
        echo "  ... " . ($i + 1) . " de " . count($equipos) . " equipos\n";
    }
}

echo "\nListo. Anomalías recalculadas: {$nuevas}\n";
ksort($porTipo);
foreach ($porTipo as $tipo => $cant) {
    echo "  {$tipo}: {$cant}\n";
}
