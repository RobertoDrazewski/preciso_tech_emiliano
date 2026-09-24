<?php

/**
 * Inventario COMPLETO de lo que mandan las dos APIs de TTM.
 *
 * Por qué lo armé: el test_api.php de siempre solo muestra las claves del
 * PRIMER registro. Así se me pasó que TTM manda eventos POWON / POWOFF
 * (corte y vuelta de la alimentación del equipo): vienen en el campo
 * "evento", en registros sueltos, y en los primeros registros solo había
 * DATA y TIEMPO. Este script recorre TODOS los registros y lista:
 *
 *   Telemetría (get_full_data.php):
 *     - todos los campos que aparecen en algún registro, con ejemplo
 *     - todos los valores distintos de "evento" y cuántas veces vino cada uno
 *     - un ejemplo completo de cada evento que no sea DATA ni TIEMPO
 *
 *   Equipos (get_devices.php):
 *     - todos los campos, en cuántos equipos vienen con dato, y un ejemplo
 *     - los valores distintos de los campos tipo "categoría"
 *
 * Siempre consulta la API REAL, aunque el .env tenga API_SIMULATE=true.
 *
 * Uso:
 *   php scripts/diagnostico_apis.php 1076 "2026-09-07 00:00" "2026-09-14 23:59"
 *   (equipo y fechas opcionales; por defecto 1061, últimos 7 días)
 */

require_once __DIR__ . '/../bootstrap.php';

$equipo = $argv[1] ?? '1061';
$hasta = isset($argv[3]) ? new DateTimeImmutable($argv[3]) : new DateTimeImmutable();
$desde = isset($argv[2]) ? new DateTimeImmutable($argv[2]) : $hasta->modify('-7 days');

function ejemplo($v): string
{
    $txt = is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v);
    return strlen($txt) > 60 ? substr($txt, 0, 57) . '...' : $txt;
}

// ---------------------------------------------------------------------
// 1. Telemetría
// ---------------------------------------------------------------------
echo "=================================================================\n";
echo " API DE TELEMETRÍA (get_full_data.php)\n";
echo " equipo {$equipo}, " . $desde->format('Y-m-d H:i') . " a " . $hasta->format('Y-m-d H:i') . "\n";
echo "=================================================================\n";

try {
    $cliente = new ApiClient($config['api']); // siempre la real
    $raw = $cliente->getFullData($equipo, $desde, $hasta);
    $registros = $raw['data'] ?? [];
    echo "Registros recibidos: " . count($registros) . "\n\n";

    $campos = [];
    $eventos = [];
    $ejemplosEvento = [];
    foreach ($registros as $r) {
        foreach ($r as $k => $v) {
            if (!isset($campos[$k])) {
                $campos[$k] = ['veces' => 0, 'con_dato' => 0, 'tipos' => [], 'ejemplo' => null];
            }
            $campos[$k]['veces']++;
            $campos[$k]['tipos'][gettype($v)] = true;
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') {
                $campos[$k]['con_dato']++;
                if ($campos[$k]['ejemplo'] === null) {
                    $campos[$k]['ejemplo'] = $v;
                }
            }
        }
        $ev = (string) ($r['evento'] ?? '(sin evento)');
        $eventos[$ev] = ($eventos[$ev] ?? 0) + 1;
        if (!in_array($ev, ['DATA', 'TIEMPO'], true) && count($ejemplosEvento[$ev] ?? []) < 2) {
            $ejemplosEvento[$ev][] = $r;
        }
    }

    echo "-- Campos (en todos los registros) --\n";
    printf("  %-20s %-10s %-16s %s\n", 'campo', 'aparece', 'con dato (≠0)', 'ejemplo');
    foreach ($campos as $k => $c) {
        printf("  %-20s %-10s %-16s %s  [%s]\n", $k, $c['veces'], $c['con_dato'], ejemplo($c['ejemplo']), implode('/', array_keys($c['tipos'])));
    }

    echo "\n-- Valores de \"evento\" --\n";
    arsort($eventos);
    foreach ($eventos as $ev => $n) {
        printf("  %-20s %d\n", $ev, $n);
    }

    if ($ejemplosEvento) {
        echo "\n-- Ejemplos completos de cada evento que no es DATA/TIEMPO --\n";
        foreach ($ejemplosEvento as $ev => $lista) {
            foreach ($lista as $r) {
                echo "  [{$ev}] " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    } else {
        echo "\n(En este período no vino ningún evento distinto de DATA/TIEMPO. Probá con un\n equipo y fechas donde el gráfico de TTM muestre marcas de Power OFF.)\n";
    }
} catch (Throwable $e) {
    echo "ERROR consultando telemetría: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------
// 2. Equipos
// ---------------------------------------------------------------------
echo "\n=================================================================\n";
echo " API DE EQUIPOS (get_devices.php)\n";
echo "=================================================================\n";

try {
    $equipos = (new DevicesApiClient($config['api']))->getDevices();
    $total = count($equipos);
    echo "Equipos recibidos: {$total}\n\n";

    $campos = [];
    foreach ($equipos as $e) {
        foreach ($e as $k => $v) {
            if (!isset($campos[$k])) {
                $campos[$k] = ['con_dato' => 0, 'ejemplo' => null, 'valores' => []];
            }
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0' && $v !== 0.0) {
                $campos[$k]['con_dato']++;
                if ($campos[$k]['ejemplo'] === null) {
                    $campos[$k]['ejemplo'] = $v;
                }
            }
            if (is_scalar($v)) {
                $clave = trim((string) $v);
                $campos[$k]['valores'][$clave] = ($campos[$k]['valores'][$clave] ?? 0) + 1;
            }
        }
    }

    echo "-- Campos --\n";
    printf("  %-20s %-18s %s\n", 'campo', 'con dato', 'ejemplo');
    foreach ($campos as $k => $c) {
        printf("  %-20s %-18s %s\n", $k, $c['con_dato'] . " de {$total}", ejemplo($c['ejemplo']));
    }

    // Los campos con pocos valores distintos son "categorías": muestro todos
    // sus valores. Los que tienen muchos (patentes, IMEI, fechas) no.
    echo "\n-- Valores posibles de los campos tipo categoría --\n";
    foreach ($campos as $k => $c) {
        $distintos = count($c['valores']);
        if ($distintos > 0 && $distintos <= 15) {
            arsort($c['valores']);
            $lista = [];
            foreach ($c['valores'] as $val => $n) {
                $lista[] = ($val === '' ? '(vacío)' : $val) . " ({$n})";
            }
            echo "  {$k}: " . implode(', ', $lista) . "\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR consultando equipos: " . $e->getMessage() . "\n";
}
