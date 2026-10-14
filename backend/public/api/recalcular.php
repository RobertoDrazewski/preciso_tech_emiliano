<?php

/**
 * Recalcular las anomalías de un tipo después de cambiar sus umbrales
 * (pedido de Emiliano, 02/10/2026).
 *
 * Cambiar un umbral solo afecta a lo que se detecte de ahí en adelante. Para
 * aplicarlo también a lo que ya pasó, este endpoint recorre los equipos de a
 * tandas y, para cada uno:
 *   1. borra las anomalías de ESE tipo que nadie trabajó (por notificar),
 *   2. las vuelve a calcular con los umbrales actuales,
 *   3. guarda las que siguen cumpliendo.
 * Las que ya no cumplen quedan descartadas. Los tickets notificados,
 * informados o rechazados NO se tocan: son trabajo del operador.
 *
 * El avance se guarda en la base (tabla estado, clave "recalculo_job"), así
 * que el proceso sigue aunque se cierre la ventana de umbrales o se cambie de
 * pantalla: cualquier pantalla abierta lo retoma (assets/js/trabajos.js).
 *
 * GET  /api/recalcular.php                         -> estado del recálculo actual
 * POST /api/recalcular.php {accion:"iniciar", tipo, dias}
 * POST /api/recalcular.php {accion:"paso", tab}    -> procesa una tanda
 * POST /api/recalcular.php {accion:"cancelar"}
 */

require_once __DIR__ . '/_bootstrap_api.php';

const CLAVE_JOB = 'recalculo_job';
// De a 2 (antes 5): cada tanda ocupa el servidor menos tiempo, así los clics
// del operador (Rechazar, Notificar) no quedan esperando detrás del recálculo.
const EQUIPOS_POR_TANDA = 2;
const TIPOS_RECALCULABLES = ['descarga_detenido', 'caida_instantanea', 'consumo_anomalo', 'ralenti', 'variacion_temperatura',
                             'desconexion_sensor', 'corte_alimentacion', 'carga_no_coincide'];

/** Lo que se le muestra al usuario (sin la lista interna de equipos). */
function resumen(?array $job): array
{
    if (!$job) {
        return ['activo' => false];
    }
    $total = count($job['equipos']);
    return [
        'activo'     => !$job['terminado'],
        'terminado'  => $job['terminado'],
        'cancelado'  => $job['cancelado'] ?? false,
        'tipo'       => $job['tipo'],
        'dias'       => $job['dias'],
        'procesados' => $job['indice'],
        'total'      => $total,
        'porcentaje' => $total ? round($job['indice'] / $total * 100) : 100,
        'borradas'   => $job['borradas'],
        'creadas'    => $job['creadas'],
        'iniciado'   => $job['iniciado'],
        'finalizado' => $job['finalizado'] ?? null,
    ];
}

try {
    $storage = new Storage($config['db']);
    $job = json_decode((string) $storage->getEstado(CLAVE_JOB), true) ?: null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOk(resumen($job));
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string) ($body['accion'] ?? '');
    $ahora = new DateTimeImmutable();

    if ($accion === 'iniciar') {
        $tipo = (string) ($body['tipo'] ?? '');
        if (!in_array($tipo, TIPOS_RECALCULABLES, true)) {
            jsonError('Ese tipo de anomalía no se puede recalcular.');
        }
        if ($job && !$job['terminado']) {
            jsonError('Ya hay un recálculo en curso (' . str_replace('_', ' ', $job['tipo']) . '). Esperá a que termine.');
        }
        $dias = max(1, min(90, (int) ($body['dias'] ?? 30)));
        $desde = $ahora->modify("-{$dias} days");
        $equipos = array_map('strval', $storage->equiposConLecturasDesde($desde));
        $job = [
            'tipo' => $tipo, 'dias' => $dias, 'desde' => $desde->format(DATE_ATOM),
            'equipos' => $equipos, 'indice' => 0, 'borradas' => 0, 'creadas' => 0,
            'iniciado' => $ahora->format(DATE_ATOM), 'terminado' => count($equipos) === 0,
            'en_paso' => null, 'tab' => null,
        ];
        $storage->setEstado(CLAVE_JOB, json_encode($job));
        jsonOk(resumen($job));
    }

    if ($accion === 'cancelar') {
        if ($job && !$job['terminado']) {
            $job['terminado'] = true;
            $job['cancelado'] = true;
            $job['finalizado'] = $ahora->format(DATE_ATOM);
            $storage->setEstado(CLAVE_JOB, json_encode($job));
        }
        jsonOk(resumen($job));
    }

    if ($accion === 'paso') {
        if (!$job || $job['terminado']) {
            jsonOk(resumen($job));
        }
        // Si otra pestaña está procesando una tanda (hace menos de 45 s), no
        // me meto: esta pestaña solo muestra el avance.
        $tab = (string) ($body['tab'] ?? '');
        if ($job['en_paso'] && $job['tab'] !== $tab && (time() - strtotime($job['en_paso'])) < 45) {
            jsonOk(resumen($job) + ['ocupado' => true]);
        }
        $job['en_paso'] = $ahora->format(DATE_ATOM);
        $job['tab'] = $tab;
        $storage->setEstado(CLAVE_JOB, json_encode($job));

        $desde = new DateTimeImmutable($job['desde']);
        $detector = new AnomalyDetector($config['anomaly'], $storage);
        // Igual que el reprocesado: el detector mira 7 días para atrás, así que
        // lo corro en cortes cada 6 días para cubrir todo el período.
        $cortes = [];
        for ($t = $desde->modify('+6 days'); $t < $ahora; $t = $t->modify('+6 days')) {
            $cortes[] = $t;
        }
        $cortes[] = $ahora;

        $tanda = array_slice($job['equipos'], $job['indice'], EQUIPOS_POR_TANDA);
        foreach ($tanda as $equipoId) {
            $job['borradas'] += $storage->borrarPendientesDeTipo($equipoId, $job['tipo'], $desde);
            $equipo = $storage->equipoPorId($equipoId);
            $capacidad = ($equipo && $equipo['capacidad_litros'] > 0)
                ? (float) $equipo['capacidad_litros']
                : $storage->capacidadEstimadaPorHistorial($equipoId);
            foreach ($cortes as $corte) {
                foreach ($detector->analizar($equipoId, $corte, $capacidad) as $a) {
                    if ($a['tipo'] !== $job['tipo'] || $a['fecha'] < $desde) {
                        continue;
                    }
                    if ($storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
                        continue;
                    }
                    $storage->guardarAnomalia($a);
                    $job['creadas']++;
                }
            }
            $job['indice']++;
        }

        $job['en_paso'] = null;
        if ($job['indice'] >= count($job['equipos'])) {
            $job['terminado'] = true;
            $job['finalizado'] = (new DateTimeImmutable())->format(DATE_ATOM);
        }
        $storage->setEstado(CLAVE_JOB, json_encode($job));
        jsonOk(resumen($job));
    }

    jsonError('accion tiene que ser iniciar, paso o cancelar.');
} catch (Throwable $e) {
    jsonError('Error en el recálculo: ' . $e->getMessage(), 500);
}
