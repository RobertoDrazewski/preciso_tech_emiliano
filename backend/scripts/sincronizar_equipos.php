<?php

/**
 * Sincroniza la flota completa desde la API de equipos de TTM
 * (get_devices.php). Es seguro correrlo las veces que haga falta (upsert,
 * no duplica). Corre solo al arrancar el backend y, además, una vez por día
 * desde el cron (cron/check_alertas.php). La lógica está en
 * src/SincronizadorFlota.php.
 *
 * Uso:
 *   php scripts/sincronizar_equipos.php
 */

require_once __DIR__ . '/../bootstrap.php';

try {
    $storage = new Storage($config['db']);
    foreach (SincronizadorFlota::sincronizar($config, $storage) as $linea) {
        echo $linea . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
