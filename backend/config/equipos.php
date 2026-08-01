<?php

/**
 * Tu flota. Completar con los IDs reales de "equipo" tal como los espera la
 * API (?equipo=1001, etc) y un nombre para mostrar en los informes.
 *
 * ⚠️ Ya NO se pide capacidad de tanque acá — Emiliano confirmó (27/07/2026)
 * que esa información no existe en ningún lado, todo se maneja en litros
 * absolutos directamente. Los umbrales de anomalía (config/config.php) están
 * en litros, no en % de un tanque que no existe.
 */

return [
    [
        'id'     => '1001',
        'nombre' => 'Equipo 1001 (prueba)',
    ],

    // [
    //     'id'     => '1002',
    //     'nombre' => 'Camión 2 - Interno XX',
    // ],
];
