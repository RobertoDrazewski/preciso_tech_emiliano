<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $config = require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../src/Storage.php';

    $equipo = isset($_GET['equipo']) ? trim($_GET['equipo']) : null;
    $tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : null;

    // Validación básica de entrada — sin esto, un tipo mal escrito devuelve
    // "0 anomalías" en silencio en vez de avisar que el filtro está mal.
    if ($tipo !== null && $tipo !== '' && !in_array($tipo, ['descarga_detenido', 'caida_instantanea', 'consumo_anomalo', 'agua_combustible', 'ralenti'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'tipo tiene que ser descarga_detenido, caida_instantanea, consumo_anomalo, agua_combustible o ralenti'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dias = isset($_GET['dias']) && is_numeric($_GET['dias']) ? max(1, min(90, (int) $_GET['dias'])) : 7;
    $desde = new DateTimeImmutable(date('Y-m-d H:i:s', strtotime("-{$dias} days")));
    $hasta = new DateTimeImmutable();

    $storage = new Storage($config['db']);
    $anomalias = $storage->anomaliasFlota($desde, $hasta, $equipo, $tipo);

    $totalLitros = array_sum(array_column($anomalias, 'litros_perdidos'));
    $equiposAfectados = count(array_unique(array_column($anomalias, 'equipo')));

    echo json_encode([
        'total' => count($anomalias),
        'stats' => [
            'litros_perdidos_totales' => round($totalLitros, 1),
            'equipos_afectados'       => $equiposAfectados,
        ],
        'anomalias' => $anomalias,
        'simulado'  => $config['api']['simulate'] ?? false,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'No se pudo traer las anomalías: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}