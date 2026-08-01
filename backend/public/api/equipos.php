<?php

/**
 * GET /api/equipos.php
 *
 * Lista la flota configurada en config/equipos.php, con la cantidad de
 * anomalías detectadas en los últimos 30 días para cada uno.
 *
 * Respuesta:
 * {
 *   "equipos": [
 *     { "id": "1001", "nombre": "...", "anomalias_30d": 2 }
 *   ]
 * }
 */

require_once __DIR__ . '/_bootstrap_api.php';

try {
    $storage = new Storage($config['db']['path']);

    $hasta = new DateTimeImmutable('now');
    $desde = $hasta->modify('-30 days');

    $out = [];
    foreach ($equipos as $eq) {
        $anomalias = $storage->anomaliasEnRango((string) $eq['id'], $desde, $hasta);
        $out[] = [
            'id'             => $eq['id'],
            'nombre'         => $eq['nombre'],
            'anomalias_30d'  => count($anomalias),
        ];
    }

    jsonOk(['simulado' => $config['api']['simulate'], 'equipos' => $out]);
} catch (Throwable $e) {
    jsonError('No se pudo armar la lista de equipos: ' . $e->getMessage(), 500);
}
