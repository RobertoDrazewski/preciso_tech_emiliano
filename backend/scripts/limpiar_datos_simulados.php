<?php

/**
 * Vacía las tablas `lecturas` y `anomalias` — pensado para limpiar toda la
 * basura generada por pruebas con el simulador antes de arrancar en serio
 * con datos reales. NO toca la tabla `equipos` (la flota se queda cargada).
 *
 * Pide confirmación explícita antes de borrar — no hay "deshacer" después.
 *
 * Uso:
 *   php scripts/limpiar_datos_simulados.php
 *   php scripts/limpiar_datos_simulados.php --confirmar   (salta la pregunta, para correrlo sin interacción)
 */

require_once __DIR__ . '/../bootstrap.php';

$storage = new Storage($config['db']);

// Contamos antes de borrar, para que quede claro qué se está por perder.
$pdo = new ReflectionProperty(Storage::class, 'pdo');
$pdo->setAccessible(true);
$conexion = $pdo->getValue($storage);

$totalLecturas = (int) $conexion->query('SELECT COUNT(*) as c FROM lecturas')->fetch()['c'];
$totalAnomalias = (int) $conexion->query('SELECT COUNT(*) as c FROM anomalias')->fetch()['c'];

echo "Vas a borrar:\n";
echo "  - {$totalLecturas} lecturas\n";
echo "  - {$totalAnomalias} anomalías\n";
echo "La tabla de equipos (tu flota) NO se toca.\n\n";

$confirmarPorFlag = in_array('--confirmar', $argv, true);

if (!$confirmarPorFlag) {
    echo "Escribí BORRAR (en mayúsculas) para confirmar, cualquier otra cosa cancela: ";
    $respuesta = trim(fgets(STDIN));
    if ($respuesta !== 'BORRAR') {
        echo "Cancelado, no se borró nada.\n";
        exit(0);
    }
}

$conexion->exec('DELETE FROM lecturas');
$conexion->exec('DELETE FROM anomalias');

// Reiniciamos también el cursor de rotación del cron, para que la próxima
// corrida arranque desde el principio de la flota otra vez.
$storage->setEstado('cron_cursor_equipo', '');

echo "Listo. Lecturas y anomalías borradas. Cursor del cron reiniciado.\n";
