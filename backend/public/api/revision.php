<?php

/**
 * Tickets de anomalías — lo que usan la campanita y la pantalla de Tickets.
 *
 * GET  /api/revision.php                 -> { pendientes: N, anomalias: [...últimas por notificar...] }
 * GET  /api/revision.php?historial=123   -> { eventos: [...historial del ticket 123...] }
 *
 * POST /api/revision.php  body: { id, accion, observacion }
 *   accion: notificar | informar | rechazar | reabrir | observacion
 *   -> { ok: true, pendientes: N }
 *
 * Qué significa cada estado está explicado en Storage::revisarAnomalia().
 */

require_once __DIR__ . '/_bootstrap_api.php';

try {
    $storage = new Storage($config['db']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        $accion = (string) ($body['accion'] ?? '');
        if ($id <= 0 || !in_array($accion, ['notificar', 'informar', 'rechazar', 'reabrir', 'observacion'], true)) {
            jsonError('Faltan datos: id y accion (notificar, informar, rechazar, reabrir u observacion).');
        }
        if (!$storage->revisarAnomalia($id, $accion, $body['observacion'] ?? null)) {
            jsonError('No encontré ese ticket.', 404);
        }
        jsonOk(['ok' => true, 'pendientes' => $storage->contarPendientesRevision()]);
    }

    if (isset($_GET['historial'])) {
        jsonOk(['eventos' => $storage->eventosTicket((int) $_GET['historial'])]);
    }

    $limite = isset($_GET['limite']) ? max(1, min(50, (int) $_GET['limite'])) : 15;
    jsonOk([
        'pendientes' => $storage->contarPendientesRevision(),
        'anomalias'  => $storage->ultimasPendientesRevision($limite),
    ]);
} catch (Throwable $e) {
    jsonError('Error en los tickets: ' . $e->getMessage(), 500);
}
