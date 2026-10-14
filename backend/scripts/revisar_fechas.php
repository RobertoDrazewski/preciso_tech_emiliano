<?php

/**
 * Revisa (y si se le pide, corrige) fechas guardadas con una zona horaria
 * distinta a la de la aplicación (UTC-3).
 *
 * Por qué: las fechas se guardan como texto ("2026-10-01T12:00:00-03:00").
 * Si alguna vez el cron corrió con APP_TIMEZONE inválido, guardó en UTC
 * ("2026-10-01T15:00:00+00:00", el mismo instante). Para la base son dos
 * textos distintos: la misma lectura queda dos veces y los filtros por fecha
 * comparan mal. Este script las pasa todas a UTC-3 y borra las repetidas.
 *
 * Uso:
 *   php scripts/revisar_fechas.php            -> solo cuenta, no toca nada
 *   php scripts/revisar_fechas.php --arreglar -> corrige
 */

require_once __DIR__ . '/../bootstrap.php';

$arreglar = in_array('--arreglar', $argv, true);
$storage = new Storage($config['db']);

foreach (['lecturas', 'anomalias'] as $tabla) {
    [$a, $b] = $storage->normalizarFechas($tabla, !$arreglar);
    if ($arreglar) {
        echo "{$tabla}: {$a} fechas corregidas a UTC-3, {$b} filas repetidas borradas.\n";
    } else {
        echo "{$tabla}: {$a} filas con otra zona horaria." . ($a > 0 ? ' Corré con --arreglar para corregirlas.' : ' Todo bien.') . "\n";
    }
}
