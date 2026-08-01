<?php

/**
 * Bootstrap común para los endpoints de backend/public/api/*.php.
 * Pone headers JSON, CORS (para que el frontend, que vive aparte, pueda
 * pegarle sin quilombo), y un helper para tirar errores en formato JSON
 * prolijo en vez de un warning de PHP crudo.
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$origenPermitido = Env::get('FRONTEND_ORIGIN', '*');
header("Access-Control-Allow-Origin: {$origenPermitido}");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
