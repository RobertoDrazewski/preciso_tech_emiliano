<?php

/**
 * POST /api/notificar_cliente.php   body: { id, pdf_base64 }
 *
 * Le manda al cliente un mail con el PDF de la anomalía (el PDF lo arma el
 * navegador con la misma información que ve el operador).
 *
 * ⚠️ MODO PRUEBA (por defecto, ver config 'notificacion_cliente'): el mail
 * NO le llega al cliente; le llega a NOTIFICAR_CLIENTE_EMAIL_PRUEBA, y adentro
 * dice a quién le habría llegado. Así se puede probar todo el circuito sin
 * molestar a nadie. Para activarlo de verdad: NOTIFICAR_CLIENTE_MODO_PRUEBA="false".
 *
 * Queda registrado en el historial del ticket.
 */

require_once __DIR__ . '/_bootstrap_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Usar POST.', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$id = isset($body['id']) ? (int) $body['id'] : 0;
$pdf = isset($body['pdf_base64']) ? base64_decode((string) $body['pdf_base64'], true) : false;
if ($id <= 0 || $pdf === false || strlen($pdf) < 100) {
    jsonError('Faltan datos: id de la anomalía y el PDF.');
}
if (strlen($pdf) > 5 * 1024 * 1024) {
    jsonError('El PDF es demasiado grande.');
}

try {
    $storage = new Storage($config['db']);
    $a = $storage->anomaliaPorId($id);
    if (!$a) {
        jsonError('No encontré esa anomalía.', 404);
    }

    // Destinatarios reales: los correos del cliente que trae la API de equipos.
    $reales = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($a['email_cliente'] ?? '')), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $cfgNotif = $config['notificacion_cliente'];
    $modoPrueba = $cfgNotif['modo_prueba'];

    if ($modoPrueba) {
        if (!filter_var($cfgNotif['email_prueba'], FILTER_VALIDATE_EMAIL)) {
            jsonError('Estamos en modo prueba pero falta configurar NOTIFICAR_CLIENTE_EMAIL_PRUEBA (el correo donde llegan las pruebas).');
        }
        $destinatarios = [$cfgNotif['email_prueba']];
    } else {
        if (!$reales) {
            jsonError('Este cliente no tiene correo cargado en la API de equipos de TTM.');
        }
        $destinatarios = $reales;
    }

    $tipo = ucfirst(str_replace('_', ' ', $a['tipo']));
    $equipoNombre = $a['equipo_nombre'] ?: $a['equipo'];
    $fecha = (new DateTimeImmutable($a['fecha']))->format('d/m/Y H:i');
    $esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');

    $avisoPrueba = $modoPrueba
        ? '<p style="background:#fff3cd;padding:10px;border-radius:6px;"><b>MODO PRUEBA.</b> Este correo habría llegado a: <b>'
          . ($reales ? $esc(implode(', ', $reales)) : 'el cliente no tiene correo cargado en la API') . '</b>.</p>'
        : '';
    $html = $avisoPrueba . '
        <p>Estimado cliente:</p>
        <p>Le informamos que el sistema de control de combustible de Preciso detectó una situación en su vehículo
        <b>' . $esc($equipoNombre) . '</b> que requiere su atención.</p>
        <table cellpadding="6" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">
          <tr><td><b>Tipo</b></td><td>' . $esc($tipo) . '</td></tr>
          <tr><td><b>Fecha y hora</b></td><td>' . $esc($fecha) . '</td></tr>
          <tr><td><b>Detalle</b></td><td>' . $esc($a['detalle']) . '</td></tr>
          <tr><td><b>Ticket</b></td><td>#' . $id . '</td></tr>
        </table>
        <p>Adjuntamos el informe en PDF. Ante cualquier consulta, puede responder este correo.</p>
        <p>Saludos cordiales,<br>Preciso · Gestión de flotas</p>';

    $mailer = new SmtpMailer($config['smtp']);
    $ok = $mailer->send(
        "Preciso · Aviso de anomalía — {$equipoNombre} — {$tipo}",
        $html,
        $destinatarios,
        [['nombre' => "preciso_anomalia_{$id}.pdf", 'contenido' => $pdf, 'tipo' => 'application/pdf']]
    );
    if (!$ok) {
        jsonError('No se pudo enviar el correo: ' . ($mailer->ultimoError ?: 'error desconocido.'), 502);
    }

    $detalle = $modoPrueba
        ? 'PDF enviado (MODO PRUEBA) a ' . implode(', ', $destinatarios) . ($reales ? ' en lugar de ' . implode(', ', $reales) : ' — el cliente no tiene correo cargado')
        : 'PDF enviado al cliente: ' . implode(', ', $destinatarios);
    $storage->marcarClienteNotificado($id, $detalle);

    jsonOk(['ok' => true, 'modo_prueba' => $modoPrueba, 'enviado_a' => $destinatarios, 'detalle' => $detalle]);
} catch (Throwable $e) {
    jsonError('Error al notificar al cliente: ' . $e->getMessage(), 500);
}
