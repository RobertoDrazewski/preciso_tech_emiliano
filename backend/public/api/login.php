<?php

/**
 * GET  /api/login.php                      -> { requiere_login: bool }
 * POST /api/login.php {usuario, clave}     -> { token, vence, usuario }
 *
 * Ver src/Auth.php.
 */

require_once __DIR__ . '/_bootstrap_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOk(['requiere_login' => Auth::activo()]);
}

if (!Auth::activo()) {
    jsonOk(['token' => '', 'vence' => 0, 'usuario' => '', 'requiere_login' => false]);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$pase = Auth::login((string) ($body['usuario'] ?? ''), (string) ($body['clave'] ?? ''));
if ($pase === null) {
    sleep(1); // frena a quien quiera probar claves a lo loco
    jsonError('Usuario o clave incorrectos.', 401);
}
jsonOk($pase);
