<?php

/**
 * Bootstrap simple, sin Composer. Cada entry point (public/*.php, cron/*.php,
 * scripts/*.php) empieza con require_once __DIR__ . '/../bootstrap.php'
 * (ajustando la cantidad de '../' según la profundidad).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/src/Env.php';
require_once BASE_PATH . '/src/ApiClient.php';
require_once BASE_PATH . '/src/SimulatedApiClient.php';
require_once BASE_PATH . '/src/DataNormalizer.php';
require_once BASE_PATH . '/src/Storage.php';
require_once BASE_PATH . '/src/AnomalyDetector.php';
require_once BASE_PATH . '/src/ReportBuilder.php';
require_once BASE_PATH . '/src/SmtpMailer.php';

$config = require BASE_PATH . '/config/config.php';
$fieldMap = require BASE_PATH . '/config/field_map.php';
$equipos = require BASE_PATH . '/config/equipos.php';

function equipoPorId(array $equipos, string $id): ?array
{
    foreach ($equipos as $eq) {
        if ((string) $eq['id'] === (string) $id) {
            return $eq;
        }
    }
    return null;
}

/**
 * Devuelve el cliente de la API real o el simulador, según
 * config/config.php → api.simulate (variable de entorno API_SIMULATE).
 * Los dos tienen el mismo método getFullData(equipo, from, to), así que el
 * resto del sistema no se entera de cuál está usando.
 */
function crearApiClient(array $config): object
{
    if ($config['api']['simulate']) {
        return new SimulatedApiClient();
    }
    return new ApiClient($config['api']);
}
