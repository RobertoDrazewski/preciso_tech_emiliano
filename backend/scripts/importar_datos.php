<?php

/**
 * Importa un archivo generado con scripts/exportar_datos.php en la base
 * configurada en backend/.env (la de la instalación nueva).
 *
 * Se puede correr más de una vez sin duplicar nada:
 *   - equipos, parámetros y estado se actualizan;
 *   - anomalías, historial de tickets y lecturas se agregan, y las que ya
 *     están (mismo equipo, fecha y tipo) se saltean.
 * Si la base está vacía, primero se crean las tablas solas.
 *
 * Números de ticket: si la base nueva todavía no tiene anomalías, se
 * conservan los mismos números que en la instalación anterior. Si ya tiene
 * (porque el cron empezó a detectar antes de importar), las importadas
 * reciben números nuevos y su historial se reengancha solo. Por eso conviene
 * importar ANTES de arrancar el cron (ver el manual de integración).
 *
 * Uso:
 *   php scripts/importar_datos.php storage/exportacion/preciso_2026-10-21_1000.jsonl.gz
 *
 * Con docker compose:
 *   docker compose cp preciso_2026-10-21_1000.jsonl.gz backend:/app/storage/
 *   docker compose exec backend php scripts/importar_datos.php storage/preciso_2026-10-21_1000.jsonl.gz
 */

require_once __DIR__ . '/../bootstrap.php';

$archivo = $argv[1] ?? '';
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php scripts/importar_datos.php <archivo .jsonl.gz>\n");
    exit(1);
}

$storage = new Storage($config['db']); // crea tablas y migraciones si hace falta
$pdo = $storage->pdo();
$pg = $storage->driver() === 'pgsql';

/** Columnas que existen en la tabla destino (por si una base vieja tiene alguna menos). */
function columnas(PDO $pdo, bool $pg, string $tabla): array
{
    if ($pg) {
        $s = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t");
        $s->execute([':t' => $tabla]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_column($pdo->query("PRAGMA table_info({$tabla})")->fetchAll(PDO::FETCH_ASSOC), 'name');
}

// Qué hacer si la fila ya existe: actualizar (datos de referencia) o saltear
// (datos históricos, que nunca se pisan).
$reglas = [
    'equipos'        => ['clave' => 'id',     'actualizar' => true],
    'parametros'     => ['clave' => 'clave',  'actualizar' => true],
    'estado'         => ['clave' => 'clave',  'actualizar' => true],
    'anomalias'      => ['clave' => 'id',     'actualizar' => false],
    'ticket_eventos' => ['clave' => 'id',     'actualizar' => false],
    'lecturas'       => ['clave' => 'equipo, fecha', 'actualizar' => false],
];

$gz = gzopen($archivo, 'rb');
$cabecera = json_decode((string) gzgets($gz, 10_000_000), true);
if (($cabecera['formato'] ?? '') !== 'preciso-export-1') {
    fwrite(STDERR, "El archivo no es una exportación de Preciso.\n");
    exit(1);
}
echo "Importando exportación del {$cabecera['creado']} ({$cabecera['motor']}).\n";

// ¿Conservo los números de ticket o los renumero? (ver arriba)
$conservarIds = (int) $pdo->query('SELECT COUNT(*) FROM anomalias')->fetchColumn() === 0;
echo $conservarIds
    ? "La base no tiene anomalías: se conservan los números de ticket.\n"
    : "La base ya tiene anomalías: las importadas reciben números nuevos (el historial se reengancha solo).\n";
$mapaIds = [];   // id viejo de anomalía -> id en esta base
$buscarAnomalia = $pdo->prepare('SELECT id FROM anomalias WHERE equipo = :e AND fecha = :f AND tipo = :t LIMIT 1');

$cacheCols = [];
$cacheStmt = [];
$totales = [];
$pdo->beginTransaction();
$enTanda = 0;

while (($linea = gzgets($gz, 10_000_000)) !== false) {
    $l = json_decode($linea, true);
    if (!is_array($l) || ($l['tipo'] ?? '') !== 'fila') {
        continue;
    }
    $tabla = $l['tabla'];
    if (!isset($reglas[$tabla])) {
        continue;
    }
    $cacheCols[$tabla] ??= columnas($pdo, $pg, $tabla);
    $datos = array_intersect_key($l['datos'], array_flip($cacheCols[$tabla]));

    // Las lecturas se identifican por equipo + fecha: su id interno no se trae.
    if ($tabla === 'lecturas') {
        unset($datos['id']);
    }
    if ($tabla === 'anomalias' && !$conservarIds) {
        $idViejo = $datos['id'];
        $buscarAnomalia->execute([':e' => $datos['equipo'], ':f' => $datos['fecha'], ':t' => $datos['tipo']]);
        $existente = $buscarAnomalia->fetchColumn();
        if ($existente !== false) {
            $mapaIds[$idViejo] = (int) $existente; // ya estaba: no la duplico
            $totales[$tabla] = ($totales[$tabla] ?? 0) + 1;
            continue;
        }
        unset($datos['id']);
        $cols = array_keys($datos);
        $ins = $pdo->prepare('INSERT INTO anomalias (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')');
        $p = [];
        foreach ($datos as $c => $v) {
            $p[':' . $c] = $v;
        }
        $ins->execute($p);
        $mapaIds[$idViejo] = (int) $pdo->lastInsertId();
        $totales[$tabla] = ($totales[$tabla] ?? 0) + 1;
        continue;
    }
    if ($tabla === 'ticket_eventos' && !$conservarIds) {
        if (!isset($mapaIds[$datos['anomalia_id']])) {
            continue; // evento de una anomalía que no vino en el archivo
        }
        $datos['anomalia_id'] = $mapaIds[$datos['anomalia_id']];
        unset($datos['id']);
        // Si ya se importó antes (mismo ticket, fecha y acción), no lo repito.
        $ya = $pdo->prepare('SELECT 1 FROM ticket_eventos WHERE anomalia_id = :a AND fecha = :f AND COALESCE(estado, \'\') = :e AND COALESCE(observacion, \'\') = :o LIMIT 1');
        $ya->execute([':a' => $datos['anomalia_id'], ':f' => $datos['fecha'], ':e' => $datos['estado'] ?? '', ':o' => $datos['observacion'] ?? '']);
        if ($ya->fetchColumn() !== false) {
            $totales[$tabla] = ($totales[$tabla] ?? 0) + 1;
            continue;
        }
        $cols = array_keys($datos);
        $ins = $pdo->prepare('INSERT INTO ticket_eventos (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')');
        $p = [];
        foreach ($datos as $c => $v) {
            $p[':' . $c] = $v;
        }
        $ins->execute($p);
        $totales[$tabla] = ($totales[$tabla] ?? 0) + 1;
        continue;
    }
    $cols = array_keys($datos);
    $firma = $tabla . '|' . implode(',', $cols);

    if (!isset($cacheStmt[$firma])) {
        $r = $reglas[$tabla];
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $conflicto = $r['actualizar']
            ? 'DO UPDATE SET ' . implode(', ', array_map(fn($c) => "{$c} = excluded.{$c}", array_diff($cols, explode(', ', $r['clave']))))
            : 'DO NOTHING';
        $cacheStmt[$firma] = $pdo->prepare("INSERT INTO {$tabla} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ") ON CONFLICT ({$r['clave']}) {$conflicto}");
    }
    $params = [];
    foreach ($datos as $c => $v) {
        $params[':' . $c] = $v;
    }
    $cacheStmt[$firma]->execute($params);
    $totales[$tabla] = ($totales[$tabla] ?? 0) + 1;

    if (++$enTanda >= 5000) {
        $pdo->commit();
        $pdo->beginTransaction();
        $enTanda = 0;
        echo "  " . json_encode($totales) . "\n";
    }
}
$pdo->commit();
gzclose($gz);

// En Postgres, los contadores de id tienen que seguir después del último
// importado: si no, el próximo ticket nuevo chocaría con uno viejo.
if ($pg) {
    foreach (['anomalias', 'ticket_eventos'] as $t) {
        $pdo->query("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM {$t}), 0), 1))");
    }
}

echo "\nListo. Filas procesadas (las que ya estaban se saltearon o actualizaron):\n";
foreach ($totales as $t => $n) {
    echo str_pad($t, 16) . number_format($n, 0, ',', '.') . "\n";
}
