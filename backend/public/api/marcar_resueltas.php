<?php

/**
 * POST /api/marcar_resueltas.php
 * Body (JSON): { "equipo": "1011", "desde": "2026-08-14T00:00:00-03:00", "hasta": "2026-08-15T00:00:00-03:00" }
 *
 * Marca como "resueltas" todas las anomalías de ese equipo dentro del rango
 * dado. Se llama desde el frontend apenas se termina de generar/descargar
 * el PDF de un informe — la idea de Emiliano: la alarma/contador de arriba
 * del panel baja recién cuando alguien "atendió" la anomalía bajando su
 * informe, no antes.
 *
 * Respuesta: { "marcadas": N }
 */

require_once __DIR__ . '/_bootstrap_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Este endpoint es POST.', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['equipo']) || empty($body['desde']) || empty($body['hasta'])) {
    jsonError('Faltan datos: equipo, desde y hasta son obligatorios.');
}

try {
    $storage = new Storage($config['db']);

    $desde = new DateTimeImmutable($body['desde']);
    $hasta = new DateTimeImmutable($body['hasta']);

    $marcadas = $storage->marcarResueltasPorEquipoYRango((string) $body['equipo'], $desde, $hasta);

    jsonOk(['marcadas' => $marcadas]);
} catch (Throwable $e) {
    jsonError('No se pudieron marcar las anomalías como resueltas: ' . $e->getMessage(), 500);
}
