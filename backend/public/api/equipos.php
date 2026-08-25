<?php

/**
 * GET /api/equipos.php
 *
 * Lista la flota (guardada en la base — ver Storage::listarEquipos), con la
 * cantidad de anomalías detectadas en los últimos 30 días para cada uno.
 *
 * Con una flota de 1000+ equipos, el conteo de anomalías se hace con UNA
 * sola consulta agregada (Storage::contarAnomaliasPorEquipo), no un loop
 * equipo por equipo — eso sí sería carísimo a esta escala.
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
    $storage = new Storage($config['db']);

    $hasta = new DateTimeImmutable('now');
    $desde = $hasta->modify('-30 days');

    $equipos = $storage->listarEquipos();
    $conteos = $storage->contarAnomaliasPorEquipo($desde, $hasta);

    $out = [];
    foreach ($equipos as $eq) {
        $out[] = [
            'id'            => $eq['id'],
            'nombre'        => $eq['nombre'],
            'anomalias_30d' => $conteos[$eq['id']] ?? 0,
        ];
    }

    jsonOk(['simulado' => $config['api']['simulate'], 'equipos' => $out]);
} catch (Throwable $e) {
    jsonError('No se pudo armar la lista de equipos: ' . $e->getMessage(), 500);
}
