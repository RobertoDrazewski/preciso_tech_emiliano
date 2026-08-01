<?php

/**
 * Correr cada 5-10 minutos por cron. Para cada equipo configurado:
 *  1. Pide a la API los últimos datos (ventana corta, p. ej. última hora).
 *  2. Los guarda en SQLite.
 *  3. Corre el detector de anomalías (que internamente espera la "ventana de
 *     confirmación" antes de dar una caída por real — ver AnomalyDetector.php).
 *  4. Manda un email por cada anomalía nueva que todavía no se haya alertado.
 *
 * Nota: una caída recién detectada NO genera alerta en la misma corrida —
 * el sistema espera unos minutos (config: recovery_window_minutes) para
 * confirmar que no era un simple rebote de pendiente antes de avisar. Es
 * normal que la alerta llegue 20-30 min después del evento real, no al
 * instante.
 *
 * Ejemplo de crontab (cada 10 minutos):
 *   [asterisco]/10 [asterisco] [asterisco] [asterisco] [asterisco] php /ruta/a/preciso-informes/cron/check_alertas.php >> /ruta/a/logs/alertas.log 2>&1
 *   (ver crontab de ejemplo completo en README.md)
 */

require_once __DIR__ . '/../bootstrap.php';

$storage = new Storage($config['db']['path']);
$apiClient = crearApiClient($config);
$normalizer = new DataNormalizer($fieldMap);
$detector = new AnomalyDetector($config['anomaly'], $storage);
$mailer = new SmtpMailer($config['smtp']);

// Ventana corta: alcanza con solapar un poco el intervalo del cron para no
// perder lecturas que llegaron justo en el borde.
$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify('-90 minutes');

$totalNuevas = 0;

foreach ($equipos as $equipo) {
    try {
        $raw = $apiClient->getFullData($equipo['id'], $desde, $hasta);
        $lecturas = $normalizer->normalize($raw, $equipo['id']);

        if (empty($lecturas)) {
            echo "[" . date('Y-m-d H:i:s') . "] Equipo {$equipo['id']}: sin lecturas nuevas.\n";
            continue;
        }

        $storage->guardarLecturas($lecturas);

        $anomalias = $detector->analizar($equipo['id'], $hasta);

        foreach ($anomalias as $a) {
            if ($storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
                continue;
            }
            $id = $storage->guardarAnomalia($a);
            $totalNuevas++;

            $asunto = "⚠️ Anomalía de combustible — {$equipo['nombre']}";
            $html = construirEmailAlerta($equipo, $a);

            $enviado = $mailer->send($asunto, $html);
            if ($enviado) {
                $storage->marcarAlertada($id);
                echo "[" . date('Y-m-d H:i:s') . "] Alerta enviada: {$equipo['nombre']} — {$a['tipo']}\n";
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR enviando alerta para {$equipo['nombre']} (se reintentará en la próxima corrida).\n";
            }
        }
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR con equipo {$equipo['id']}: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Listo. Anomalías nuevas: {$totalNuevas}.\n";

function construirEmailAlerta(array $equipo, array $anomalia): string
{
    $fecha = $anomalia['fecha']->format('d/m/Y H:i');
    return <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 560px;">
      <div style="background:#0d1b2e; color:#fff; padding:16px 20px; border-radius:8px 8px 0 0;">
        <h2 style="margin:0; font-size:18px;">⚠️ Anomalía de combustible detectada</h2>
      </div>
      <div style="border:1px solid #e5e9f0; border-top:none; padding:20px; border-radius:0 0 8px 8px;">
        <p><strong>Vehículo:</strong> {$equipo['nombre']} (equipo {$equipo['id']})</p>
        <p><strong>Fecha:</strong> {$fecha}</p>
        <p><strong>Tipo:</strong> {$anomalia['tipo']}</p>
        <p><strong>Detalle:</strong> {$anomalia['detalle']}</p>
        <p style="color:#6b7280; font-size:12.5px; margin-top:20px;">
          Este es un aviso automático del módulo de informes de Preciso.
        </p>
      </div>
    </div>
    HTML;
}
