<?php

/**
 * Actualiza SOLO la capacidad del tanque de un equipo que ya existe en la
 * base — sin tocar nombre, cliente, ni ningún otro dato. Pensado para
 * cuando TTM confirma la capacidad real de un vehículo puntual (por chat,
 * no por la API de equipos, que muchas veces la trae en 0/vacía).
 *
 * Uso:
 *   php scripts/actualizar_capacidad.php 1061 873
 */

require_once __DIR__ . '/../bootstrap.php';

$id = $argv[1] ?? null;
$capacidad = $argv[2] ?? null;

if (!$id || !$capacidad || !is_numeric($capacidad)) {
    echo "Uso: php scripts/actualizar_capacidad.php <id> <capacidad_en_litros>\n";
    exit(1);
}

$storage = new Storage($config['db']);
$equipo = $storage->equipoPorId($id);

if (!$equipo) {
    echo "ERROR: no existe ningún equipo con id {$id} en la base.\n";
    exit(1);
}

// Conservamos todo lo que ya tenía el equipo, solo cambiamos la capacidad.
$storage->upsertEquipo($equipo['id'], $equipo['nombre'], [
    'cliente'          => $equipo['cliente'],
    'estado_raw'       => $equipo['estado_raw'],
    'tipo'             => $equipo['tipo'],
    'marca'            => $equipo['marca'],
    'modelo'           => $equipo['modelo'],
    'capacidad_litros' => (float) $capacidad,
    'diagnostico'      => $equipo['diagnostico'],
    'servicio'         => $equipo['servicio'],
]);

echo "Listo: {$equipo['nombre']} (equipo {$id}) — capacidad actualizada a {$capacidad} L.\n";
