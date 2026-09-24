<?php

/**
 * Revisión de anomalías — lo que usa la campanita.
 *
 * GET  /api/revision.php
 *      -> { "pendientes": 12, "anomalias": [ ...las últimas pendientes... ] }
 *
 * POST /api/revision.php   body: { "id": 123, "accion": "validar|descartar|reabrir", "comentario": "opcional" }
 *      -> { "ok": true, "pendientes": 11 }
 *
 * Qué significa cada acción está explicado en Storage::revisarAnomalia().
 */

require_once __DIR__ . '/_bootstrap_api.php';

try {
    $storage = new Storage($config['db']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        $accion = (string) ($body['accion'] ?? '');
        if ($id <= 0 || !in_array($accion, ['validar', 'descartar', 'reabrir'], true)) {
            jsonError('Faltan datos: id y accion (validar, descartar o reabrir).');
        }
        $ok = $storage->revisarAnomalia($id, $accion, $body['comentario'] ?? null);
        if (!$ok) {
            jsonError('No encontré esa anomalía.', 404);
        }
        jsonOk(['ok' => true, 'pendientes' => $storage->contarPendientesRevision()]);
    }

    $limite = isset($_GET['limite']) ? max(1, min(50, (int) $_GET['limite'])) : 15;
    jsonOk([
        'pendientes' => $storage->contarPendientesRevision(),
        'anomalias'  => $storage->ultimasPendientesRevision($limite),
    ]);
} catch (Throwable $e) {
    jsonError('Error en la revisión de anomalías: ' . $e->getMessage(), 500);
}
