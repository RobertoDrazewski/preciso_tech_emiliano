<?php

/**
 * Correr 1 vez al día (recomendado: 6am). Actualiza los datos de cada equipo
 * en SQLite para que los informes diario/semanal/mensual estén frescos
 * cuando alguien los abra en public/informe.php — y, si hay destinatarios
 * configurados, manda por mail un resumen del día anterior.
 *
 * Ejemplo de crontab:
 *   0 6 * * * php /ruta/a/preciso-informes/cron/generar_informes.php >> /ruta/a/logs/informes.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

$storage = new Storage($config['db']['path']);
$apiClient = crearApiClient($config);
$normalizer = new DataNormalizer($fieldMap);
$detector = new AnomalyDetector($config['anomaly'], $storage);
$builder = new ReportBuilder();
$mailer = new SmtpMailer($config['smtp']);

$hasta = new DateTimeImmutable('today'); // medianoche de hoy
$desde = $hasta->modify('-1 day');       // medianoche de ayer -> informe del día completo de ayer

$resumenGeneral = [];

foreach ($equipos as $equipo) {
    try {
        $raw = $apiClient->getFullData($equipo['id'], $desde, $hasta);
        $lecturas = $normalizer->normalize($raw, $equipo['id']);
        $storage->guardarLecturas($lecturas);

        $anomalias = $detector->analizar($equipo['id'], $hasta);
        foreach ($anomalias as $a) {
            if (!$storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
                $storage->guardarAnomalia($a);
            }
        }

        $anomaliasDelDia = $storage->anomaliasEnRango($equipo['id'], $desde, $hasta);
        $reporte = $builder->construir($lecturas, $anomaliasDelDia);

        $resumenGeneral[] = [
            'equipo'  => $equipo,
            'reporte' => $reporte,
        ];

        echo "[" . date('Y-m-d H:i:s') . "] Informe diario actualizado: {$equipo['nombre']}.\n";
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR con equipo {$equipo['id']}: " . $e->getMessage() . "\n";
    }
}

// Resumen general por email (opcional — solo si hay destinatarios configurados).
if (!empty($config['smtp']['to']) && !empty($resumenGeneral)) {
    $html = construirResumenDiario($desde, $resumenGeneral);
    $ok = $mailer->send('Resumen diario de flota — ' . $desde->format('d/m/Y'), $html);
    echo "[" . date('Y-m-d H:i:s') . "] Resumen diario " . ($ok ? 'enviado.' : 'FALLÓ al enviarse.') . "\n";
}

function construirResumenDiario(DateTimeImmutable $fecha, array $resumenGeneral): string
{
    $filas = '';
    foreach ($resumenGeneral as $item) {
        $eq = $item['equipo'];
        $r = $item['reporte'];
        $colorAnom = $r['cantidad_anomalias'] > 0 ? '#c0392b' : '#1f9d55';
        $filas .= "<tr>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0;'>{$eq['nombre']}</td>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0;'>" . ($r['km_recorridos'] ?? '—') . " km</td>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0;'>{$r['litros_consumidos_total']} L</td>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0; color:{$colorAnom}; font-weight:700;'>{$r['cantidad_anomalias']}</td>
        </tr>";
    }

    return <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 620px;">
      <div style="background:#0d1b2e; color:#fff; padding:16px 20px; border-radius:8px 8px 0 0;">
        <h2 style="margin:0; font-size:18px;">Resumen diario de flota — {$fecha->format('d/m/Y')}</h2>
      </div>
      <div style="border:1px solid #e5e9f0; border-top:none; padding:20px; border-radius:0 0 8px 8px;">
        <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
          <tr style="text-align:left; color:#6b7280; font-size:11px; text-transform:uppercase;">
            <th style="padding:8px;">Equipo</th><th style="padding:8px;">Km</th><th style="padding:8px;">Combustible</th><th style="padding:8px;">Anomalías</th>
          </tr>
          {$filas}
        </table>
        <p style="color:#6b7280; font-size:12.5px; margin-top:20px;">
          Informes detallados con gráficos y mapa disponibles en el panel de Preciso.
        </p>
      </div>
    </div>
    HTML;
}
