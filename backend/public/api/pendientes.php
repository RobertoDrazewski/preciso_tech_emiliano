<?php

/**
 * GET /api/pendientes.php
 *
 * Cantidad total de anomalías PENDIENTES (no resueltas) en toda la flota —
 * lo que muestra la alarma/badge fija en la barra de arriba, en todas las
 * pantallas. Endpoint separado y liviano porque se consulta en cada carga
 * de página, no solo en la pantalla de Anomalías.
 *
 * Respuesta: { "pendientes": N }
 */

require_once __DIR__ . '/_bootstrap_api.php';

try {
    $storage = new Storage($config['db']);
    jsonOk(['pendientes' => $storage->contarAnomaliasPendientesGlobal()]);
} catch (Throwable $e) {
    jsonError('No se pudo contar las anomalías pendientes: ' . $e->getMessage(), 500);
}
