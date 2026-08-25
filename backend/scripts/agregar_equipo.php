<?php

/**
 * Suma un equipo nuevo a la flota (instalación nueva que avise Emiliano).
 *
 * Uso:
 *   php scripts/agregar_equipo.php 1250 "AB123CD (Volvo - 380)"
 *
 * No hace falta redeployar ni tocar código — el equipo queda disponible
 * en el panel apenas se corre esto.
 */

require_once __DIR__ . '/../bootstrap.php';

$id = $argv[1] ?? null;
$nombre = $argv[2] ?? null;

if (!$id || !$nombre) {
    echo "Uso: php scripts/agregar_equipo.php <id> \"<nombre>\"\n";
    exit(1);
}

$storage = new Storage($config['db']);
$storage->upsertEquipo($id, $nombre);

echo "Equipo {$id} ({$nombre}) agregado/actualizado.\n";
