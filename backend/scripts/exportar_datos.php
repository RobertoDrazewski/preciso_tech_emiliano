<?php

/**
 * Exporta los datos de la base a un archivo, para llevarlos a otra
 * instalación (por ejemplo, del entorno de prueba en Railway al servidor de
 * TTM). Se importan con scripts/importar_datos.php.
 *
 * Qué exporta:
 *   - equipos          (la flota, con clientes y correos)
 *   - anomalias        (todas, con su estado de ticket y la observación)
 *   - ticket_eventos   (el historial de cada ticket)
 *   - parametros       (los umbrales que se cambiaron desde la pantalla)
 *   - estado           (marcas internas: última sincronización, etc.)
 *   - lecturas         SOLO si se pide con --lecturas=N (los últimos N días).
 *                      No hacen falta: el sistema las vuelve a pedir a la API
 *                      de TTM cuando abre un informe, y el cron arma solo el
 *                      historial nuevo. Pero con --lecturas=8 la detección
 *                      arranca con una semana de historial para comparar.
 *
 * Lo importante de llevar son las anomalías y los tickets: es el trabajo de
 * los operadores y no se puede volver a generar.
 *
 * Usa la base configurada en backend/.env (DATABASE_URL o SQLite). Se puede
 * correr desde cualquier máquina con PHP y acceso a esa base.
 *
 * Uso:
 *   php scripts/exportar_datos.php                 -> sin lecturas
 *   php scripts/exportar_datos.php --lecturas=8    -> con los últimos 8 días de lecturas
 *
 * Deja el archivo en backend/storage/exportacion/preciso_AAAA-MM-DD_HHMM.jsonl.gz
 * ⚠️ Tiene datos de clientes: no subirlo al repositorio (la carpeta ya está
 * en .gitignore) y pasarlo por un canal privado.
 */

require_once __DIR__ . '/../bootstrap.php';

$diasLecturas = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--lecturas=(\d+)$/', $arg, $m)) {
        $diasLecturas = (int) $m[1];
    }
}

$storage = new Storage($config['db']);
$pdo = $storage->pdo();

$dir = BASE_PATH . '/storage/exportacion';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$archivo = $dir . '/preciso_' . date('Y-m-d_Hi') . '.jsonl.gz';
$gz = gzopen($archivo, 'wb6');
if (!$gz) {
    fwrite(STDERR, "No pude crear {$archivo}\n");
    exit(1);
}

$escribir = fn(array $linea) => gzwrite($gz, json_encode($linea, JSON_UNESCAPED_UNICODE) . "\n");
$escribir(['tipo' => 'cabecera', 'formato' => 'preciso-export-1', 'creado' => date(DATE_ATOM), 'motor' => $storage->driver()]);

/** Exporta una tabla de a tandas (ordenada por su clave), sin cargar todo en memoria. */
function exportarTabla(PDO $pdo, callable $escribir, string $tabla, string $clave, string $where = '', array $params = []): int
{
    $n = 0;
    $ultimo = null;
    while (true) {
        $sql = "SELECT * FROM {$tabla} WHERE " . ($where !== '' ? "({$where}) AND " : '') . ($ultimo === null ? '1=1' : "{$clave} > :ultimo") . " ORDER BY {$clave} LIMIT 2000";
        $stmt = $pdo->prepare($sql);
        $p = $params;
        if ($ultimo !== null) {
            $p[':ultimo'] = $ultimo;
        }
        $stmt->execute($p);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) {
            break;
        }
        foreach ($filas as $f) {
            $escribir(['tipo' => 'fila', 'tabla' => $tabla, 'datos' => $f]);
        }
        $n += count($filas);
        $ultimo = end($filas)[$clave];
        if ($n % 20000 < 2000) {
            echo "  {$tabla}: {$n}...\n";
        }
    }
    return $n;
}

$totales = [];
foreach ([['equipos', 'id'], ['anomalias', 'id'], ['ticket_eventos', 'id'], ['parametros', 'clave'], ['estado', 'clave']] as [$tabla, $clave]) {
    echo "Exportando {$tabla}...\n";
    // El recálculo en curso y el cursor del cron son de ESTA instalación: no los llevo.
    $where = $tabla === 'estado' ? "clave NOT IN ('recalculo_job', 'cron_cursor_equipo', 'esquema_version')" : '';
    $totales[$tabla] = exportarTabla($pdo, $escribir, $tabla, $clave, $where);
}
if ($diasLecturas > 0) {
    echo "Exportando lecturas de los últimos {$diasLecturas} días (puede tardar)...\n";
    $desde = (new DateTimeImmutable())->modify("-{$diasLecturas} days")->format(DateTimeInterface::ATOM);
    $totales['lecturas'] = exportarTabla($pdo, $escribir, 'lecturas', 'id', 'fecha >= :desde', [':desde' => $desde]);
}
$escribir(['tipo' => 'fin', 'totales' => $totales]);
gzclose($gz);

echo "\nListo: {$archivo}\n";
foreach ($totales as $t => $n) {
    echo str_pad($t, 16) . number_format($n, 0, ',', '.') . "\n";
}
echo "Tamaño: " . round(filesize($archivo) / 1024 / 1024, 1) . " MB\n";
