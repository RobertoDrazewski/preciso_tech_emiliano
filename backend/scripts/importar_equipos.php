<?php

/**
 * Importa/actualiza la flota completa desde un JSON (id + nombre).
 *
 * Uso:
 *   php scripts/importar_equipos.php ruta/al/equipos.json
 *
 * El JSON tiene que ser un array de objetos: [{"id": "1011", "nombre": "AF668OJ (Scania - R410)"}, ...]
 *
 * Es seguro correrlo de nuevo cuando Emiliano mande una lista actualizada —
 * no borra nada, actualiza el nombre de los que ya existen y agrega los
 * nuevos. Los equipos que ya no vengan en el archivo NO se borran ni se
 * desactivan solos (por las dudas de que sea un export parcial) — si hay
 * que dar de baja alguno, se hace a mano.
 */

require_once __DIR__ . '/../bootstrap.php';

$rutaJson = $argv[1] ?? __DIR__ . '/../data/equipos_flota.json';
if (!file_exists($rutaJson)) {
    echo "Uso: php scripts/importar_equipos.php ruta/al/equipos.json\n";
    exit(1);
}

$equipos = json_decode(file_get_contents($rutaJson), true);
if (!is_array($equipos)) {
    echo "ERROR: el archivo no tiene un JSON válido (array de {id, nombre}).\n";
    exit(1);
}

$storage = new Storage($config['db']);
$cantidad = $storage->upsertEquiposMasivo($equipos);

echo "Listo: {$cantidad} equipos importados/actualizados.\n";
echo "Total de equipos activos en la base: " . count($storage->listarEquipos()) . "\n";
