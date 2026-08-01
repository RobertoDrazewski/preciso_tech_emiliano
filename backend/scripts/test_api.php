<?php

/**
 * Paso 0 (leer README.md). Corré esto primero:
 *
 *   php scripts/test_api.php
 *   php scripts/test_api.php 1001 "2026-07-20 00:00" "2026-07-23 23:59"
 *
 * Pega contra la API real de TTM, imprime el JSON tal cual viene y una lista
 * de las claves que encontró en el primer registro, para que sea fácil
 * ajustar config/field_map.php.
 */

require_once __DIR__ . '/../bootstrap.php';

$equipoId = $argv[1] ?? ($equipos[0]['id'] ?? '1001');
$desdeStr = $argv[2] ?? (new DateTimeImmutable('-3 days'))->format('Y-m-d H:i');
$hastaStr = $argv[3] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i');

$desde = new DateTimeImmutable($desdeStr);
$hasta = new DateTimeImmutable($hastaStr);

echo "Consultando equipo {$equipoId} desde {$desde->format('Y-m-d H:i')} hasta {$hasta->format('Y-m-d H:i')}...\n\n";

try {
    $client = crearApiClient($config);
    $raw = $client->getFullData($equipoId, $desde, $hasta);

    echo "== JSON crudo (primeros 2000 caracteres) ==\n";
    $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo substr($json, 0, 2000) . (strlen($json) > 2000 ? "\n... (cortado)\n" : "\n") . "\n";

    // Intenta encontrar el primer registro para listar sus claves.
    $records = $raw;
    if (isset($raw['data']) && is_array($raw['data'])) {
        $records = $raw['data'];
    }
    $first = is_array($records) ? reset($records) : null;

    if (is_array($first)) {
        echo "== Claves detectadas en el primer registro ==\n";
        foreach ($first as $k => $v) {
            $tipo = is_array($v) ? 'array' : gettype($v);
            $preview = is_scalar($v) ? (string) $v : json_encode($v);
            echo sprintf("  %-25s (%s) => %s\n", $k, $tipo, substr((string) $preview, 0, 60));
        }
        echo "\nUsá estos nombres de clave para completar 'raw' => [...] en config/field_map.php\n";
    } else {
        echo "No se pudo detectar un registro individual — revisá el JSON crudo de arriba\n";
        echo "para entender la estructura (¿viene envuelto en otra clave que no sea 'data'?).\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nSi la API pide headers especiales, autenticación, o un formato de fecha\n";
    echo "distinto, avisale a Emiliano y ajustamos ApiClient.php.\n";
}
