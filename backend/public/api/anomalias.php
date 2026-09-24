<?php

/**
 * GET /api/anomalias.php?equipo=&cliente=&tipo=&dias=7&revision=activas
 *
 * Anomalías de la flota con filtros. Todos opcionales:
 *   equipo   = id de un equipo puntual
 *   cliente  = nombre de la flota/cliente, o "__sin_cliente__"
 *   tipo     = uno de los tipos de anomalía
 *   dias     = ventana hacia atrás (1 a 90, default 7)
 *   revision = activas (default: pendientes + validadas) | pendiente | validada | descartada | todas
 *
 * Además de la lista, devuelve los números para los indicadores de aguja
 * (stats). Los números de revisión (pendientes/validadas/descartadas) se
 * calculan sobre TODO lo filtrado, sin importar qué revisión estés mirando,
 * así los indicadores no cambian al tocar ese filtro.
 */

require_once __DIR__ . '/_bootstrap_api.php';

$tiposValidos = ['descarga_detenido', 'caida_instantanea', 'consumo_anomalo', 'agua_combustible', 'ralenti',
                 'variacion_temperatura', 'desconexion_sensor', 'corte_alimentacion', 'carga_no_coincide'];

$equipo   = isset($_GET['equipo']) ? trim($_GET['equipo']) : null;
$cliente  = isset($_GET['cliente']) ? trim($_GET['cliente']) : null;
$tipo     = isset($_GET['tipo']) ? trim($_GET['tipo']) : null;
$revision = isset($_GET['revision']) ? trim($_GET['revision']) : 'activas';
$dias     = isset($_GET['dias']) && is_numeric($_GET['dias']) ? max(1, min(90, (int) $_GET['dias'])) : 7;

if ($tipo !== null && $tipo !== '' && !in_array($tipo, $tiposValidos, true)) {
    jsonError('tipo tiene que ser uno de: ' . implode(', ', $tiposValidos));
}
if (!in_array($revision, ['activas', 'pendiente', 'validada', 'descartada', 'todas'], true)) {
    jsonError('revision tiene que ser activas, pendiente, validada, descartada o todas');
}

try {
    $storage = new Storage($config['db']);
    $hasta = new DateTimeImmutable();
    $desde = $hasta->modify("-{$dias} days");

    // Traigo todo lo filtrado y separo por revisión acá: con una sola
    // consulta tengo la lista Y los contadores de revisión.
    $todas = $storage->anomaliasFlota($desde, $hasta, $equipo, $tipo, $cliente);

    $conteoRevision = ['pendiente' => 0, 'validada' => 0, 'descartada' => 0];
    $litrosValidados = 0.0;
    foreach ($todas as $a) {
        $estado = $a['estado_revision'] ?? 'pendiente';
        $conteoRevision[$estado] = ($conteoRevision[$estado] ?? 0) + 1;
        if ($estado === 'validada') {
            $litrosValidados += (float) $a['litros_perdidos'];
        }
    }

    $mostrar = array_values(array_filter($todas, function ($a) use ($revision) {
        $estado = $a['estado_revision'] ?? 'pendiente';
        if ($revision === 'todas') return true;
        if ($revision === 'activas') return $estado !== 'descartada';
        return $estado === $revision;
    }));

    // Los indicadores (total, litros, vehículos) se calculan sobre lo que
    // se muestra: por defecto, SIN las descartadas (una falsa alarma no es
    // una merma).
    $litros = array_sum(array_map(fn($a) => (float) $a['litros_perdidos'], $mostrar));
    $vehiculos = count(array_unique(array_column($mostrar, 'equipo')));

    jsonOk([
        'total'     => count($mostrar),
        'stats'     => [
            'litros_perdidos_totales' => round($litros, 1),
            'equipos_afectados'       => $vehiculos,
            'flota_activa'            => count($storage->listarEquipos(true)),
            'pendientes'              => $conteoRevision['pendiente'],
            'validadas'               => $conteoRevision['validada'],
            'descartadas'             => $conteoRevision['descartada'],
            'litros_validados'        => round($litrosValidados, 1),
        ],
        'anomalias' => $mostrar,
        'simulado'  => $config['api']['simulate'] ?? false,
    ]);
} catch (Throwable $e) {
    jsonError('No se pudieron traer las anomalías: ' . $e->getMessage(), 500);
}
