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

    // Cualquier equipo que quedó marcado activo en nuestra base pero NO
    // vino en esta sincronización (típicamente, del excel viejo cargado
    // antes de tener esta API, con otro esquema de IDs) se desactiva —
    // la API de equipos pasa a ser la fuente de verdad de qué está
    // realmente en la flota. No se borra nada, solo deja de contarse como
    // "monitoreado".
    $idsPresentes = array_column($equipos, 'id');
    $desactivados = $storage->desactivarEquiposNoPresentes($idsPresentes);
    if ($desactivados > 0) {
        echo "Desactivados {$desactivados} equipos que ya no vienen en la API (probablemente del excel viejo).\n";
    }

    // ⚠️ Señal de agua en combustible que YA trae la API de equipos
    // (diagnostico="AGUA") — más confiable que el campo WIF de la
    // telemetría por lectura, que todavía no confirmamos el nombre exacto
    // del campo real. No duplicamos si ya hay una sin resolver reciente
    // para ese equipo (esto es un estado ACTUAL, no una lectura puntual con
    // fecha propia, así que no tiene sentido crear una nueva cada sync).
    $nuevasAgua = 0;
    foreach ($equipos as $eq) {
        if (($eq['diagnostico'] ?? null) !== 'AGUA') {
            continue;
        }
        if ($storage->existeAnomaliaPendienteReciente($eq['id'], 'agua_combustible', 7)) {
            continue;
        }
        $storage->guardarAnomalia([
            'equipo'          => $eq['id'],
            'fecha'           => new DateTimeImmutable(),
            'tipo'            => 'agua_combustible',
            'detalle'         => 'La API de equipos de TTM marca este vehículo con diagnóstico "AGUA" (agua en el combustible). Confirmar con Emiliano/TTM.',
            'litros_perdidos' => 0.0,
            'z_score'         => null,
            'lat'             => $eq['lat'] ?? null,
            'lng'             => $eq['lng'] ?? null,
        ]);
        $nuevasAgua++;
    }
    if ($nuevasAgua > 0) {
        echo "Detectadas {$nuevasAgua} anomalías de agua en combustible nuevas (según diagnóstico de TTM).\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
