<?php

/**
 * GET /api/informe.php?equipo=1001&tipo=diario|semanal|mensual
 *
 * Trae los datos del período pedido desde la API de TTM, los guarda,
 * corre la detección de anomalías, y devuelve todo listo para que el
 * frontend dibuje los gráficos, el mapa y la tabla de anomalías.
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

try {
    $apiClient = crearApiClient($config);
    $raw = $apiClient->getFullData($equipo['id'], $desde, $hasta);

    // Prioridad: 1) capacidad real de TTM si la tenemos, 2) estimada del
    // propio historial del equipo (no vamos a depender de que TTM la
    // cargue — la mayoría no la tiene), 3) el techo genérico dentro de
    // DataNormalizer, como último recurso.
    $capacidadLitros = (isset($equipo['capacidad_litros']) && $equipo['capacidad_litros'] > 0)
        ? (float) $equipo['capacidad_litros']
        : $storage->capacidadEstimadaPorHistorial($equipo['id']);

    $normalizer = new DataNormalizer($fieldMap);
    $lecturas = $normalizer->normalize($raw, $equipo['id'], $capacidadLitros);

    $storage->guardarLecturas($lecturas);

    $detector = new AnomalyDetector($config['anomaly'], $storage);
    // Corremos la detección (guarda anomalías confirmadas nuevas, si las hay).
    $anomaliasDetectadas = $detector->analizar($equipo['id'], $hasta, $capacidadLitros);
    foreach ($anomaliasDetectadas as $a) {
        if (!$storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
            $storage->guardarAnomalia($a);
        }
    }

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
