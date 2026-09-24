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
 * Aguante a cortes de conexión: la primera vez que lo corrí desde mi Mac
 * contra Railway, el proxy público cortó la conexión en el equipo 150 de
 * 770 y el script se murió. Por eso ahora:
 *   - Si se corta la conexión en un equipo, me reconecto y reintento ese
 *     mismo equipo (hasta 3 veces) en vez de morirme.
 *   - Cada 50 equipos cierro y abro la conexión de nuevo por las mías,
 *     para no tener una sola conexión abierta durante media hora.
 *   - Después de cada equipo guardo por dónde voy. Si igual se cae todo,
 *     lo corro con --continuar y sigue desde ahí, sin volver a borrar ni
 *     rehacer lo que ya estaba.
 *
 * Uso:
 *   php scripts/reprocesar_anomalias.php               (últimos 30 días, pide confirmación)
 *   php scripts/reprocesar_anomalias.php 14            (últimos 14 días)
 *   php scripts/reprocesar_anomalias.php --continuar   (sigue una corrida que se cortó)
 */

require_once __DIR__ . '/../bootstrap.php';

const CLAVE_CURSOR = 'reproceso_ultimo_equipo';
const CLAVE_DESDE  = 'reproceso_desde';
const REINTENTOS   = 3;
const RECONECTAR_CADA = 50;

$continuar = in_array('--continuar', $argv, true);
$sinPreguntar = in_array('--confirmar', $argv, true);
$dias = 30;
foreach (array_slice($argv, 1) as $arg) {
    if (is_numeric($arg)) {
        $dias = max(1, (int) $arg);
    }
}

/** Abre una conexión nueva. La uso al arrancar y cada vez que hay que reconectar. */
function conectar(array $config): array
{
    $storage = new Storage($config['db']);
    $detector = new AnomalyDetector($config['anomaly'], $storage);
    return [$storage, $detector];
}

[$storage, $detector] = conectar($config);
$ahora = new DateTimeImmutable();

if ($continuar) {
    // Sigo una corrida anterior: uso la MISMA fecha desde (si no, los
    // bordes no coinciden) y arranco después del último equipo terminado.
    $desdeGuardado = $storage->getEstado(CLAVE_DESDE);
    $cursor = $storage->getEstado(CLAVE_CURSOR);
    if (!$desdeGuardado) {
        echo "No hay ninguna corrida a medias para continuar. Corrélo sin --continuar.\n";
        exit(1);
    }
    $desde = new DateTimeImmutable($desdeGuardado);
    echo "Continúo la corrida anterior (desde " . $desde->format('d/m/Y') . "), después del equipo " . ($cursor ?: '(ninguno todavía)') . ".\n";
} else {
    $desde = $ahora->modify("-{$dias} days");
    $cursor = null;
}

$equipos = $storage->equiposConLecturasDesde($desde);
// Ordeno acá con el mismo criterio que uso para comparar con el cursor
// (Postgres y SQLite pueden ordenar texto distinto).
$equipos = array_map('strval', $equipos);
sort($equipos, SORT_STRING);
if ($cursor !== null && $cursor !== '') {
    // Los equipos vienen ordenados; me salteo los que ya terminé.
    $equipos = array_values(array_filter($equipos, fn($e) => strcmp((string) $e, $cursor) > 0));
}

if (!$continuar) {
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
    $storage->setEstado(CLAVE_DESDE, $desde->format(DateTimeInterface::ATOM));
    $storage->setEstado(CLAVE_CURSOR, '');
} else {
    echo "Me quedan " . count($equipos) . " equipos por recalcular.\n";
}

// El detector mira 7 días para atrás cada vez que lo llamo. Para cubrir
// todo el período lo corro en "cortes" cada 6 días (con un día de
// solapamiento, así no se me escapa nada en los bordes). Las repetidas
// entre cortes no se duplican porque chequeo antes de guardar.
$cortes = [];
for ($t = $desde->modify('+6 days'); $t < $ahora; $t = $t->modify('+6 days')) {
    $cortes[] = $t;
}
$cortes[] = $ahora;

$nuevas = 0;
$porTipo = [];
$fallidos = [];
$total = count($equipos);

foreach ($equipos as $i => $equipoId) {
    // Reconexión preventiva, para no tener la misma conexión abierta todo el rato.
    if ($i > 0 && $i % RECONECTAR_CADA === 0) {
        [$storage, $detector] = conectar($config);
    }

    $ok = false;
    for ($intento = 1; $intento <= REINTENTOS && !$ok; $intento++) {
        try {
            $equipo = $storage->equipoPorId((string) $equipoId);
            $capacidad = ($equipo && $equipo['capacidad_litros'] > 0)
                ? (float) $equipo['capacidad_litros']
                : $storage->capacidadEstimadaPorHistorial((string) $equipoId);

            foreach ($cortes as $corte) {
                foreach ($detector->analizar((string) $equipoId, $corte, $capacidad) as $a) {
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

            // Anoto que este equipo quedó terminado.
            $storage->setEstado(CLAVE_CURSOR, (string) $equipoId);
            $ok = true;
        } catch (PDOException $e) {
            echo "  ! Se cortó la conexión en el equipo {$equipoId} (intento {$intento}/" . REINTENTOS . "). Reconecto...\n";
            sleep(3 * $intento);
            try {
                [$storage, $detector] = conectar($config);
            } catch (PDOException $e2) {
                // Si ni siquiera puedo reconectar, lo intento en la próxima vuelta del for.
            }
        }
    }

    if (!$ok) {
        $fallidos[] = $equipoId;
        echo "  ! No pude con el equipo {$equipoId} después de " . REINTENTOS . " intentos. Lo salteo.\n";
    }

    if (($i + 1) % 50 === 0) {
        echo "  ... " . ($i + 1) . " de {$total} equipos\n";
    }
}

echo "\nListo. Anomalías recalculadas en esta corrida: {$nuevas}\n";
ksort($porTipo);
foreach ($porTipo as $tipo => $cant) {
    echo "  {$tipo}: {$cant}\n";
}

if (!empty($fallidos)) {
    echo "\nEquipos que no se pudieron procesar (" . count($fallidos) . "): " . implode(', ', $fallidos) . "\n";
    echo "Podés volver a correr todo más tarde para cubrirlos.\n";
} else {
    // Terminó entero: limpio el estado para que un --continuar futuro no se confunda.
    $storage->setEstado(CLAVE_CURSOR, '');
    $storage->setEstado(CLAVE_DESDE, '');
}
