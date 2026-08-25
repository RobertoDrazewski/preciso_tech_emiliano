<?php

/**
 * Correr 1 vez al día (recomendado: 6am). A diferencia de check_alertas.php,
 * este NO le pega a la API de TTM — lee de la base local, que ya viene
 * alimentada durante el día por check_alertas.php (que rota por toda la
 * flota cada 5-10 min). Con 1000+ equipos, volver a consultar la API acá
 * sería redundante y carísimo — la base ya tiene los datos del día.
 *
 * Manda un resumen por mail SOLO con los equipos que tuvieron alguna
 * anomalía (con 1000+ equipos, una tabla con todos sería un mail
 * inmanejable) — más un total general para contexto.
 *
 * Ejemplo de crontab:
 *   0 6 * * * php /ruta/a/preciso-informes/cron/generar_informes.php >> /ruta/a/logs/informes.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

$storage = new Storage($config['db']);
$mailer = new SmtpMailer($config['smtp']);

$hasta = new DateTimeImmutable('today'); // medianoche de hoy
$desde = $hasta->modify('-1 day');       // medianoche de ayer -> informe del día completo de ayer

$equipos = $storage->listarEquipos();
$anomaliasDelDia = $storage->anomaliasFlota($desde, $hasta, null, null, 2000);

// Agrupamos las anomalías por equipo para el resumen.
$anomaliasPorEquipo = [];
foreach ($anomaliasDelDia as $a) {
    $anomaliasPorEquipo[$a['equipo']][] = $a;
}

echo "[" . date('Y-m-d H:i:s') . "] Flota: " . count($equipos) . " equipos. Con anomalías ayer: " . count($anomaliasPorEquipo) . ".\n";

// Resumen por email (opcional — solo si hay destinatarios configurados),
// y solo si hubo alguna anomalía — evitamos mandar un mail vacío todos los días.
if (!empty($config['smtp']['to']) && !empty($anomaliasPorEquipo)) {
    $nombresPorEquipo = [];
    foreach ($equipos as $eq) {
        $nombresPorEquipo[$eq['id']] = $eq['nombre'];
    }

    $html = construirResumenDiario($desde, $anomaliasPorEquipo, $nombresPorEquipo, count($equipos));
    $ok = $mailer->send('Resumen diario de anomalías — ' . $desde->format('d/m/Y'), $html);
    echo "[" . date('Y-m-d H:i:s') . "] Resumen diario " . ($ok ? 'enviado.' : 'FALLÓ al enviarse.') . "\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Sin anomalías ayer (o sin destinatarios configurados) — no se manda mail.\n";
}

function construirResumenDiario(DateTimeImmutable $fecha, array $anomaliasPorEquipo, array $nombresPorEquipo, int $totalEquipos): string
{
    $filas = '';
    foreach ($anomaliasPorEquipo as $equipoId => $anomalias) {
        $nombre = $nombresPorEquipo[$equipoId] ?? $equipoId;
        $litrosTotal = array_sum(array_column($anomalias, 'litros_perdidos'));
        $filas .= "<tr>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0;'>{$nombre}</td>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0; color:#c0392b; font-weight:700;'>" . count($anomalias) . "</td>
            <td style='padding:8px; border-bottom:1px solid #e5e9f0;'>" . round($litrosTotal, 1) . " L</td>
        </tr>";
    }

    $totalConAnomalias = count($anomaliasPorEquipo);

    return <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 620px;">
      <div style="background:#0d1b2e; color:#fff; padding:16px 20px; border-radius:8px 8px 0 0;">
        <h2 style="margin:0; font-size:18px;">Resumen diario de anomalías — {$fecha->format('d/m/Y')}</h2>
      </div>
      <div style="border:1px solid #e5e9f0; border-top:none; padding:20px; border-radius:0 0 8px 8px;">
        <p style="color:#6b7280; font-size:13px;">
          {$totalEquipos} equipos en la flota · {$totalConAnomalias} con alguna anomalía ayer.
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
          <tr style="text-align:left; color:#6b7280; font-size:11px; text-transform:uppercase;">
            <th style="padding:8px;">Equipo</th><th style="padding:8px;">Anomalías</th><th style="padding:8px;">Litros</th>
          </tr>
          {$filas}
        </table>
        <p style="color:#6b7280; font-size:12.5px; margin-top:20px;">
          Detalle completo (gráficos, mapa, tipo de cada anomalía) disponible en el panel de Preciso.
        </p>
      </div>
    </div>
    HTML;
}
