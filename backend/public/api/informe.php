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

$equipo = equipoPorId($equipos, $equipoId);
if (!$equipo) {
    jsonError("Equipo {$equipoId} no está configurado en config/equipos.php", 404);
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

    $normalizer = new DataNormalizer($fieldMap);
    $lecturas = $normalizer->normalize($raw, $equipo['id']);

    $storage = new Storage($config['db']['path']);
    $storage->guardarLecturas($lecturas);

    $detector = new AnomalyDetector($config['anomaly'], $storage);
    // Corremos la detección (guarda anomalías confirmadas nuevas, si las hay).
    $anomaliasDetectadas = $detector->analizar($equipo['id'], $hasta);
    foreach ($anomaliasDetectadas as $a) {
        if (!$storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
            $storage->guardarAnomalia($a);
        }
    }

    $anomaliasDelRango = $storage->anomaliasEnRango($equipo['id'], $desde, $hasta);

    $builder = new ReportBuilder();
    $reporte = $builder->construir($lecturas, $anomaliasDelRango);

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
