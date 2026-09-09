<?php

/**
 * Sincroniza la flota completa desde la API de equipos de Emiliano
 * (get_devices.php) — reemplaza el import manual desde excel
 * (importar_equipos.php) como forma principal de mantener la flota
 * actualizada, ahora que existe esta API.
 *
 * Es seguro correr esto las veces que haga falta (upsert, no duplica).
 * Un equipo que Emiliano da de baja (estado=INACTIVO o SUSPENDIDO) queda
 * marcado como inactivo acá también — no se borra, solo deja de contarse
 * en el monitoreo activo.
 *
 * Uso:
 *   php scripts/sincronizar_equipos.php
 *
 * Pensado para correr a mano cuando haga falta, o programarlo 1 vez al día
 * con un cron aparte (no hace falta más seguido — la flota no cambia tan
 * rápido como para justificarlo, y es un endpoint que devuelve 1000+
 * registros de una sola consulta).
 */

require_once __DIR__ . '/../bootstrap.php';

try {
    $client = new DevicesApiClient($config['api']);
    $crudos = $client->getDevices();

    $normalizer = new DevicesNormalizer();
    $equipos = $normalizer->normalize($crudos);

    echo "Recibidos " . count($equipos) . " equipos de la API.\n";

    $storage = new Storage($config['db']);
    $n = $storage->upsertEquiposMasivo($equipos);

    $activos = count(array_filter($equipos, fn($e) => ($e['estado_raw'] ?? '') === 'ACTIVO'));
    $inactivos = $n - $activos;

    echo "Listo: {$n} equipos sincronizados ({$activos} activos, {$inactivos} inactivos/suspendidos).\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
