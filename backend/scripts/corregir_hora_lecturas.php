<?php

/**
 * Corrige las lecturas que quedaron guardadas 3 horas adelantadas (18/11/2026).
 *
 * Qué pasó: la API de telemetría de TTM manda la hora en UTC, pero en el
 * servidor de prueba API_TIMEZONE quedó vacío y la hora se tomó como si ya
 * fuera de Mendoza. Resultado: cada lectura quedó guardada 3 horas más
 * tarde de lo que pasó, y las anomalías no coincidían con la plataforma de
 * TTM (la caída que TTM muestra a las 11:34 aparecía acá a las 14:34).
 *
 * Desde v3.9 la API se toma en UTC por defecto y cada lectura nueva se
 * guarda con hora_corregida = 1. Este script se ocupa de las viejas.
 *
 * Uso:
 *   php scripts/corregir_hora_lecturas.php 617
 *       Solo revisa (no toca nada): para el equipo 617 le pide a la API un
 *       rato de cada día y se fija si lo guardado está en la hora correcta
 *       o corrido 3 horas. Usar un equipo que reporte seguido.
 *
 *   php scripts/corregir_hora_lecturas.php --arreglar
 *       Atrasa 3 horas todas las lecturas y anomalías que todavía no están
 *       marcadas como corregidas, y las marca. Se puede correr más de una
 *       vez: lo ya corregido no se vuelve a mover.
 *
 *   php scripts/corregir_hora_lecturas.php --arreglar --desde=2026-10-22
 *       Lo mismo, pero solo desde esa fecha (por si la revisión muestra que
 *       los días anteriores ya estaban bien, por ejemplo datos importados).
 *
 * Después de arreglar: php scripts/reprocesar_anomalias.php 30
 */

require_once __DIR__ . '/../bootstrap.php';

$arreglar = in_array('--arreglar', $argv, true);
$desde = null;
$equipo = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--desde=')) {
        $desde = substr($arg, 8);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            echo "La fecha de --desde tiene que ser AAAA-MM-DD.\n";
            exit(1);
        }
    } elseif ($arg !== '--arreglar') {
        $equipo = $arg;
    }
}

$storage = new Storage($config['db']);
$pdo = $storage->pdo();
$pgsql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
$zonaApp = new DateTimeZone(date_default_timezone_get());

$pendientes = (int) $pdo->query('SELECT COUNT(*) FROM lecturas WHERE hora_corregida IS NULL')->fetchColumn();
echo "Lecturas sin marcar como corregidas: {$pendientes}\n\n";

if (!$arreglar) {
    if ($equipo === null) {
        echo "Para revisar: php scripts/corregir_hora_lecturas.php <equipo>\n";
        echo "Para corregir: php scripts/corregir_hora_lecturas.php --arreglar [--desde=AAAA-MM-DD]\n";
        exit(0);
    }

    // Días que tiene guardados ese equipo.
    $stmt = $pdo->prepare('SELECT DISTINCT SUBSTR(fecha, 1, 10) AS dia FROM lecturas WHERE equipo = :e ORDER BY dia');
    $stmt->execute([':e' => $equipo]);
    $dias = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'dia');
    if (!$dias) {
        echo "El equipo {$equipo} no tiene lecturas guardadas.\n";
        exit(1);
    }

    $cliente = new ApiClient(['timezone' => 'UTC'] + $config['api']);
    $existe = $pdo->prepare('SELECT 1 FROM lecturas WHERE equipo = :e AND fecha = :f LIMIT 1');
    echo "Día         bien   corridas 3 h   (sobre lecturas de la API entre las 12 y las 16 de ese día)\n";
    foreach ($dias as $dia) {
        $ini = new DateTimeImmutable("{$dia} 12:00:00", $zonaApp);
        try {
            $raw = $cliente->getFullData($equipo, $ini, $ini->modify('+4 hours'));
        } catch (Throwable $e) {
            echo "{$dia}  (la API no respondió: {$e->getMessage()})\n";
            continue;
        }
        $bien = 0;
        $corridas = 0;
        foreach (array_slice($raw['data'] ?? [], 0, 30) as $r) {
            if (empty($r['fecha_reporte'])) {
                continue;
            }
            $texto = (string) $r['fecha_reporte'];
            // Cómo se guarda bien: la hora UTC pasada a Mendoza.
            $correcta = (new DateTimeImmutable($texto, new DateTimeZone('UTC')))->setTimezone($zonaApp)->format(DateTimeInterface::ATOM);
            // Cómo quedó guardada mal: la misma hora tomada como de Mendoza.
            $corrida = (new DateTimeImmutable($texto, $zonaApp))->format(DateTimeInterface::ATOM);
            $existe->execute([':e' => $equipo, ':f' => $correcta]);
            $bien += $existe->fetchColumn() ? 1 : 0;
            $existe->execute([':e' => $equipo, ':f' => $corrida]);
            $corridas += $existe->fetchColumn() ? 1 : 0;
        }
        printf("%s  %5d  %13d%s\n", $dia, $bien, $corridas, ($bien + $corridas) === 0 ? '   (sin datos para comparar)' : '');
    }
    echo "\nLos días con 'corridas' son los que hay que arreglar. Si todos lo están:\n";
    echo "  php scripts/corregir_hora_lecturas.php --arreglar\n";
    echo "Si los primeros días están bien, arreglá desde el primer día corrido:\n";
    echo "  php scripts/corregir_hora_lecturas.php --arreglar --desde=AAAA-MM-DD\n";
    exit(0);
}

// --- Corrección ---------------------------------------------------------
// Las fechas son texto "AAAA-MM-DDTHH:MM:SS-03:00". Les resto 3 horas en SQL
// (8 millones de filas no se pueden mover de a una desde PHP).
$menos3 = $pgsql
    ? "TO_CHAR(CAST(SUBSTR(%s, 1, 19) AS timestamp) - INTERVAL '3 hours', 'YYYY-MM-DD\"T\"HH24:MI:SS') || '-03:00'"
    : "STRFTIME('%%Y-%%m-%%dT%%H:%%M:%%S', SUBSTR(%s, 1, 19), '-3 hours') || '-03:00'";
$filtro = "hora_corregida IS NULL AND fecha LIKE '%-03:00'" . ($desde ? ' AND fecha >= :desde' : '');
$params = $desde ? [':desde' => $desde] : [];

echo 'Atraso 3 horas las lecturas' . ($desde ? " desde el {$desde}" : '') . ". Con muchos datos tarda unos minutos...\n";
$pdo->beginTransaction();
try {
    // 1) Muevo con una "x" adelante para no chocar con la restricción
    //    UNIQUE(equipo, fecha) mientras unas filas ya se movieron y otras no.
    $s = $pdo->prepare('UPDATE lecturas SET fecha = \'x\' || ' . sprintf($menos3, 'fecha') . ", hora_corregida = 1 WHERE {$filtro}");
    $s->execute($params);
    $movidas = $s->rowCount();

    // 2) Si la lectura con la hora buena ya existe (la bajó el cron después
    //    del arreglo), la vieja sobra.
    $repetidas = $pdo->exec("
        DELETE FROM lecturas
        WHERE fecha LIKE 'x%'
          AND EXISTS (SELECT 1 FROM lecturas b WHERE b.equipo = lecturas.equipo AND b.fecha = SUBSTR(lecturas.fecha, 2))
    ");

    // 3) Saco la marca.
    $pdo->exec("UPDATE lecturas SET fecha = SUBSTR(fecha, 2) WHERE fecha LIKE 'x%'");

    // 4) Lo anterior a --desde ya estaba bien: lo marco para no tocarlo nunca.
    $pdo->exec('UPDATE lecturas SET hora_corregida = 1 WHERE hora_corregida IS NULL');

    // Anomalías: incluye las ya revisadas (notificadas, informadas,
    // rechazadas), que se calcularon con la hora corrida.
    $s = $pdo->prepare('UPDATE anomalias SET fecha = ' . sprintf($menos3, 'fecha')
        . ', fecha_desde = CASE WHEN fecha_desde LIKE \'%-03:00\' THEN ' . sprintf($menos3, 'fecha_desde') . ' ELSE fecha_desde END'
        . ", hora_corregida = 1 WHERE {$filtro}");
    $s->execute($params);
    $anomalias = $s->rowCount();
    $pdo->exec('UPDATE anomalias SET hora_corregida = 1 WHERE hora_corregida IS NULL');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n✗ No se cambió nada: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Lecturas corregidas: " . ($movidas - (int) $repetidas) . " (y {$repetidas} repetidas borradas).\n";
echo "Anomalías corregidas: {$anomalias}.\n\n";
echo "Ahora recalculá las anomalías con la hora buena:\n";
echo "  php scripts/reprocesar_anomalias.php 30\n";
