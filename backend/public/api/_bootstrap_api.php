<?php

/**
 * Bootstrap común para los endpoints de backend/public/api/*.php.
 * Pone headers JSON, CORS (para que el frontend, que vive aparte, pueda
 * pegarle sin quilombo), controla el acceso (ver src/Auth.php) y un helper
 * para tirar errores en formato JSON prolijo en vez de un warning de PHP crudo.
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$origenPermitido = Env::get('FRONTEND_ORIGIN', '*');
header("Access-Control-Allow-Origin: {$origenPermitido}");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonError(string $mensaje, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonOk(array $data): void
{
    // Sin JSON_PRETTY_PRINT: con miles de anomalías los espacios hacían la
    // respuesta bastante más pesada, y nadie la lee a mano.
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Control de acceso: todo menos el propio login pide un pase válido.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'login.php') {
    if (Auth::activo()) {
        if (Auth::validar(Auth::tokenDelPedido()) === null) {
            jsonError('Sesión vencida o inexistente. Ingresá de nuevo.', 401);
        }
    } else {
        error_log('[Auth] ⚠️ PANEL_CLAVE no está configurada: la API está abierta sin login.');
    }
}
