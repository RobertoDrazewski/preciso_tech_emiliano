<?php

/**
 * Correr cada 5 minutos por cron. Procesa un LOTE de equipos por corrida
 * (no toda la flota: con 1000+ equipos, pegarle a la API de TTM esa cantidad
 * de veces en una sola corrida la tumbaría). Va rotando sola por toda la
 * flota entre corridas — ver Storage::proximoLoteEquipos() y cron.batch_size.
 *
 * Por corrida:
 *  1. Una vez por día, sincroniza la flota con la API de equipos de TTM
 *     (antes solo pasaba al redeployar el backend).
 *  2. Trae el próximo lote de equipos.
 *  3. Para cada uno pide a la API lo que falta DESDE SU ÚLTIMA LECTURA
 *     guardada (antes eran siempre los últimos 90 minutos: si el cron se
 *     atrasaba o crecía la flota, se perdían lecturas sin que nadie lo viera).
 *  4. Guarda, corre el detector y registra las anomalías nuevas.
 *  5. Cada 'alertas.cada_horas' manda UN mail de resumen con las anomalías
 *     importantes (antes era un mail por anomalía: cientos por día, y Gmail
 *     corta alrededor de 500 y puede bloquear la cuenta).
 *
 * Ejemplo de crontab (cada 5 minutos):
 *   [asterisco]/5 [asterisco] [asterisco] [asterisco] [asterisco] php /ruta/a/backend/cron/check_alertas.php >> /ruta/a/logs/alertas.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

$storage = new Storage($config['db']);
$apiClient = crearApiClient($config);
$normalizer = new DataNormalizer($fieldMap);
$detector = new AnomalyDetector($config['anomaly'], $storage);
$mailer = new SmtpMailer($config['smtp']);

$log = fn(string $t) => print('[' . date('Y-m-d H:i:s') . "] {$t}\n");
$hasta = new DateTimeImmutable('now');

// 1. Sincronización diaria de la flota.
$ultimaSync = $storage->getEstado('ultima_sync_equipos');
if (!$config['api']['simulate'] && (!$ultimaSync || strtotime($ultimaSync) < time() - 24 * 3600)) {
    try {
        foreach (SincronizadorFlota::sincronizar($config, $storage) as $linea) {
            $log('[flota] ' . $linea);
        }
    } catch (Throwable $e) {
        $log('[flota] ERROR sincronizando: ' . $e->getMessage() . ' (se reintenta en la próxima corrida)');
    }
}

// 2-4. Lote de equipos.
$lote = $storage->proximoLoteEquipos($config['cron']['batch_size']);
$log('Procesando lote de ' . count($lote) . ' equipos.');

$totalNuevas = 0;
foreach ($lote as $equipo) {
    try {
        $capacidadLitros = (isset($equipo['capacidad_litros']) && $equipo['capacidad_litros'] > 0)
            ? (float) $equipo['capacidad_litros']
            : $storage->capacidadEstimadaPorHistorial($equipo['id']);

        // Desde la última lectura guardada (con 10 min de solapamiento), como
        // mínimo los últimos 90 minutos y como máximo 24 horas para atrás.
        $ultima = $storage->ultimaLecturaDe($equipo['id']);
        $desde = $hasta->modify('-90 minutes');
        if ($ultima !== null && $ultima->modify('-10 minutes') < $desde) {
            $desde = max($ultima->modify('-10 minutes'), $hasta->modify('-24 hours'));
        }

        $raw = $apiClient->getFullData($equipo['id'], $desde, $hasta);
        $lecturas = $normalizer->normalize($raw, $equipo['id'], $capacidadLitros);
        if (empty($lecturas)) {
            continue;
        }
        $storage->guardarLecturas($lecturas);

        foreach ($detector->analizar($equipo['id'], $hasta, $capacidadLitros) as $a) {
            if ($storage->anomaliaYaRegistrada($a['equipo'], $a['fecha'], $a['tipo'])) {
                continue;
            }
            $storage->guardarAnomalia($a);
            $totalNuevas++;
        }
    } catch (Throwable $e) {
        $log("ERROR con equipo {$equipo['id']}: " . $e->getMessage());
    }
}
$log("Anomalías nuevas: {$totalNuevas}.");

// 5. Resumen de alertas por mail.
$cadaHoras = (float) ($config['alertas']['cada_horas'] ?? 4);
$ultimoResumen = $storage->getEstado('ultimo_resumen_alertas');
if ($ultimoResumen && strtotime($ultimoResumen) > time() - $cadaHoras * 3600) {
    exit(0); // todavía no toca
}

$sinAlertar = $storage->anomaliasSinAlertar();
$litrosMin = (float) ($config['alertas']['litros_minimos'] ?? 30);
$hace24h = $hasta->modify('-24 hours');
// Importantes: pérdidas reales de combustible, de cierto tamaño, detectadas
// en las últimas 24 horas (lo viejo, por ejemplo de un reprocesado, no se
// manda por mail: está en el panel).
$importantes = array_values(array_filter($sinAlertar, fn($a) =>
    in_array($a['tipo'], Storage::TIPOS_MERMA, true)
    && (float) $a['litros_perdidos'] >= $litrosMin
    && new DateTimeImmutable($a['creado_en']) >= $hace24h
));

if ($config['api']['simulate']) {
    // Candado: con datos simulados nunca se manda mail real.
    $storage->marcarTodasAlertadas();
    $storage->setEstado('ultimo_resumen_alertas', $hasta->format(DATE_ATOM));
    exit(0);
}

if (empty($importantes)) {
    $storage->marcarTodasAlertadas();
    $storage->setEstado('ultimo_resumen_alertas', $hasta->format(DATE_ATOM));
    $log('Resumen de alertas: nada importante para avisar.');
    exit(0);
}

$html = construirResumenAlertas($importantes, count($sinAlertar), $litrosMin);
$asunto = '⚠️ Preciso — ' . count($importantes) . ' anomalía' . (count($importantes) === 1 ? '' : 's') . ' de combustible para revisar';
if ($mailer->send($asunto, $html)) {
    $storage->marcarTodasAlertadas();
    $storage->setEstado('ultimo_resumen_alertas', $hasta->format(DATE_ATOM));
    $log('Resumen de alertas enviado (' . count($importantes) . ' anomalías).');
} else {
    // No marco nada: en la próxima corrida se vuelve a intentar con las mismas.
    $log('ERROR enviando el resumen de alertas: ' . $mailer->ultimoError . ' (se reintenta en la próxima corrida).');
}

function construirResumenAlertas(array $anomalias, int $totalNuevas, float $litrosMin): string
{
    $e = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $filas = '';
    foreach (array_slice($anomalias, 0, 100) as $a) {
        $fecha = (new DateTimeImmutable($a['fecha']))->format('d/m H:i');
        $filas .= '<tr>'
            . "<td style='padding:6px;border-bottom:1px solid #e5e9f0;white-space:nowrap;'>{$fecha}</td>"
            . "<td style='padding:6px;border-bottom:1px solid #e5e9f0;'><b>" . $e($a['equipo_nombre'] ?: $a['equipo']) . '</b><br><span style="color:#6b7280;">' . $e($a['equipo_cliente'] ?? '') . '</span></td>'
            . "<td style='padding:6px;border-bottom:1px solid #e5e9f0;'>" . $e(str_replace('_', ' ', $a['tipo'])) . '</td>'
            . "<td style='padding:6px;border-bottom:1px solid #e5e9f0;color:#c0392b;font-weight:700;white-space:nowrap;'>" . round((float) $a['litros_perdidos'], 1) . ' L</td>'
            . '</tr>';
    }
    $mas = count($anomalias) > 100 ? '<p>… y ' . (count($anomalias) - 100) . ' más en el panel.</p>' : '';
    $resto = $totalNuevas - count($anomalias);
    return <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 680px;">
      <div style="background:#0d1b2e; color:#fff; padding:16px 20px; border-radius:8px 8px 0 0;">
        <h2 style="margin:0; font-size:18px;">⚠️ Anomalías de combustible para revisar</h2>
      </div>
      <div style="border:1px solid #e5e9f0; border-top:none; padding:20px; border-radius:0 0 8px 8px;">
        <p style="color:#6b7280; font-size:13px;">Pérdidas de {$litrosMin} L o más detectadas desde el último aviso. Hay {$resto} anomalías menores más en el panel (Tickets).</p>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <tr style="text-align:left; color:#6b7280; font-size:11px; text-transform:uppercase;">
            <th style="padding:6px;">Fecha</th><th style="padding:6px;">Equipo</th><th style="padding:6px;">Tipo</th><th style="padding:6px;">Litros</th>
          </tr>
          {$filas}
        </table>
        {$mas}
        <p style="color:#6b7280; font-size:12.5px; margin-top:20px;">Aviso automático del módulo de anomalías de Preciso.</p>
      </div>
    </div>
    HTML;
}
