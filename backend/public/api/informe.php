<?php

/**
 * GET /api/informe.php?equipo=1001&tipo=diario|semanal|mensual
 *
 * Devuelve todo lo que necesita la pantalla del informe: gráficos, mapa y
 * anomalías del período.
 *
 * Más rápido desde el 02/10/2026: antes, cada vez que se abría un informe se
 * le pedía TODO el período a la API de TTM (un mes son miles de lecturas) y
 * se recalculaban las anomalías en el momento. Pero el cron ya va guardando
 * las lecturas de cada equipo y detectando las anomalías. Ahora:
 *   1. leo las lecturas de nuestra base;
 *   2. solo le pido a la API lo que falta desde la última lectura guardada
 *      (unos minutos), o el período entero si de ese equipo no hay nada;
 *   3. no recalculo anomalías: muestro las que ya detectó el cron.
 */

require_once __DIR__ . '/_bootstrap_api.php';

$equipoId = $_GET['equipo'] ?? null;
$tipo = $_GET['tipo'] ?? 'diario';

if (!$equipoId) {
    jsonError('Falta el parámetro ?equipo=');
}

$storage = new Storage($config['db']);

$equipo = $storage->equipoPorId($equipoId);
if (!$equipo) {
    jsonError("Equipo {$equipoId} no está en la flota. Si es una instalación nueva, correr scripts/agregar_equipo.php", 404);
}

if (!in_array($tipo, ['diario', 'semanal', 'mensual'], true)) {
    jsonError('El parámetro ?tipo= tiene que ser diario, semanal o mensual');
}

$dias = match ($tipo) {
    'semanal' => $config['reports']['weekly_days'],
    'mensual' => $config['reports']['monthly_days'],
    default   => $config['reports']['daily_days'],
};

$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify("-{$dias} days");

/** Pide a la API un tramo, lo normaliza y lo guarda. */
function traerYGuardar(array $config, array $fieldMap, Storage $storage, array $equipo, DateTimeImmutable $desde, DateTimeImmutable $hasta): void
{
    $capacidad = (isset($equipo['capacidad_litros']) && $equipo['capacidad_litros'] > 0)
        ? (float) $equipo['capacidad_litros']
        : $storage->capacidadEstimadaPorHistorial($equipo['id']);
    $raw = crearApiClient($config)->getFullData($equipo['id'], $desde, $hasta);
    $lecturas = (new DataNormalizer($fieldMap))->normalize($raw, $equipo['id'], $capacidad);
    $storage->guardarLecturas($lecturas);
}

try {
    $ultima = $storage->ultimaLecturaDe($equipo['id']);
    $primera = $storage->primeraLecturaDesde($equipo['id'], $desde);

    if ($ultima === null || $primera === null || ($primera->getTimestamp() - $desde->getTimestamp()) > 86400) {
        // De este equipo no tenemos el período en la base (equipo nuevo o
        // anterior a que empezara el cron): lo traigo entero de la API.
        traerYGuardar($config, $fieldMap, $storage, $equipo, $desde, $hasta);
    } elseif (($hasta->getTimestamp() - $ultima->getTimestamp()) > 10 * 60) {
        // Tengo casi todo: solo pido lo que falta desde la última lectura.
        traerYGuardar($config, $fieldMap, $storage, $equipo, max($desde, $ultima->modify('-5 minutes')), $hasta);
    }

    $lecturas = $storage->lecturasTypedEnRango($equipo['id'], $desde, $hasta);
    $anomaliasDelRango = $storage->anomaliasEnRango($equipo['id'], $desde, $hasta);

    $builder = new ReportBuilder();
    $reporte = $builder->construir($lecturas, $anomaliasDelRango, $config['anomaly']);

    jsonOk([
        'equipo' => [
            'id'     => $equipo['id'],
            'nombre' => $equipo['nombre'],
        ],
        'tipo'     => $tipo,
        'desde'    => $desde->format(DateTimeInterface::ATOM),
        'hasta'    => $hasta->format(DateTimeInterface::ATOM),
        'simulado' => $config['api']['simulate'],
        'reporte'  => $reporte,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 502);
}
