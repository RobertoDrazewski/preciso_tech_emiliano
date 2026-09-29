<?php

/**
 * Umbrales de detección editables desde la pantalla.
 *
 * GET  /api/parametros.php
 *      -> { "tipos": { "ralenti": [ {clave, nombre, ayuda, unidad, min, max, valor, por_defecto}, ... ], ... } }
 *
 * POST /api/parametros.php   body: { "clave": "ralenti_minutos_minimos", "valor": 30 }
 *      body: { "clave": "...", "restaurar": true }  -> vuelve al valor de config.php
 *
 * Solo se aceptan claves que estén en config/parametros_catalogo.php, y
 * dentro de su rango. Así nadie rompe la detección con un número raro.
 */

require_once __DIR__ . '/_bootstrap_api.php';

$catalogo = require BASE_PATH . '/config/parametros_catalogo.php';

try {
    $storage = new Storage($config['db']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $clave = (string) ($body['clave'] ?? '');
        $def = null;
        foreach ($catalogo as $params) {
            foreach ($params as $p) {
                if ($p['clave'] === $clave) {
                    $def = $p;
                }
            }
        }
        if ($def === null) {
            jsonError('Ese parámetro no se puede cambiar desde acá.');
        }
        if (!empty($body['restaurar'])) {
            $storage->borrarParametro($clave);
            jsonOk(['ok' => true, 'valor' => $config['anomaly'][$clave] ?? null]);
        }
        if (!isset($body['valor']) || !is_numeric($body['valor'])) {
            jsonError('El valor tiene que ser un número.');
        }
        $valor = (float) $body['valor'];
        if ($valor < $def['min'] || $valor > $def['max']) {
            jsonError("El valor tiene que estar entre {$def['min']} y {$def['max']} {$def['unidad']}.");
        }
        $storage->guardarParametro($clave, $valor);
        jsonOk(['ok' => true, 'valor' => $valor]);
    }

    $guardados = $storage->parametrosGuardados();
    $salida = [];
    foreach ($catalogo as $tipo => $params) {
        $salida[$tipo] = array_map(function ($p) use ($guardados, $config) {
            $porDefecto = $config['anomaly'][$p['clave']] ?? null;
            if (is_bool($porDefecto)) {
                $porDefecto = $porDefecto ? 1 : 0;
            }
            $p['por_defecto'] = $porDefecto;
            $p['valor'] = $guardados[$p['clave']] ?? $porDefecto;
            $p['modificado'] = array_key_exists($p['clave'], $guardados);
            return $p;
        }, $params);
    }
    jsonOk(['tipos' => $salida]);
} catch (Throwable $e) {
    jsonError('Error con los parámetros: ' . $e->getMessage(), 500);
}
