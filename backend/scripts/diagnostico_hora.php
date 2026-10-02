<?php

/**
 * ¿En qué zona horaria manda TTM las fechas? (02/10/2026)
 *
 * Emiliano notó que las horas de las anomalías no coinciden con las de su
 * plataforma. Este script lo resuelve con un dato duro: le pide a la API las
 * últimas lecturas de un equipo que esté reportando AHORA y compara la hora
 * de la última lectura (tal cual viene, sin convertir) contra la hora actual
 * en Mendoza y en UTC. La que esté cerca es la zona de la API.
 *
 * Uso (con un equipo que esté prendido y reportando en este momento):
 *   php scripts/diagnostico_hora.php 1004
 */

require_once __DIR__ . '/../bootstrap.php';

$equipo = $argv[1] ?? null;
if (!$equipo) {
    echo "Uso: php scripts/diagnostico_hora.php <id de un equipo que esté reportando ahora>\n";
    exit(1);
}

$ahoraLocal = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
$ahoraUtc = $ahoraLocal->setTimezone(new DateTimeZone('UTC'));

// Pido una ventana ancha (desde ayer hasta mañana) para que entre la última
// lectura sea cual sea la zona horaria de la API.
$config['api']['timezone'] = date_default_timezone_get();
$cliente = new ApiClient($config['api']);
$raw = $cliente->getFullData($equipo, $ahoraLocal->modify('-1 day')->setTime(0, 0), $ahoraLocal->modify('+1 day')->setTime(23, 59));
$registros = $raw['data'] ?? [];
if (!$registros) {
    echo "La API no devolvió lecturas para el equipo {$equipo}. Probá con otro que esté reportando.\n";
    exit(1);
}

$ultima = null;
foreach ($registros as $r) {
    if (!empty($r['fecha_reporte']) && ($ultima === null || $r['fecha_reporte'] > $ultima)) {
        $ultima = $r['fecha_reporte'];
    }
}

// La comparo "como texto de reloj" contra las dos horas actuales.
$comoReloj = new DateTimeImmutable($ultima, new DateTimeZone('UTC'));
$relojLocal = new DateTimeImmutable($ahoraLocal->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
$relojUtc = new DateTimeImmutable($ahoraUtc->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
$difLocal = ($relojLocal->getTimestamp() - $comoReloj->getTimestamp()) / 60;
$difUtc = ($relojUtc->getTimestamp() - $comoReloj->getTimestamp()) / 60;

echo "Última lectura de la API (tal cual viene): {$ultima}\n";
echo "Hora actual en " . date_default_timezone_get() . ":  " . $ahoraLocal->format('Y-m-d H:i:s') . "  (diferencia: " . round($difLocal) . " min)\n";
echo "Hora actual en UTC:                       " . $ahoraUtc->format('Y-m-d H:i:s') . "  (diferencia: " . round($difUtc) . " min)\n\n";

// ¿Las lecturas que ya guardamos coinciden con la hora de la API? Si el cron
// corrió alguna vez sin APP_TIMEZONE (PHP queda en UTC), se guardaron
// corridas 3 horas, y por eso las anomalías no coinciden con TTM.
$storage = new Storage($config['db']);
$guardada = $storage->ultimaLecturaDe($equipo);
echo "Zona horaria con la que corre este servidor: " . date_default_timezone_get() . "\n";
if ($guardada) {
    $guardadaReloj = new DateTimeImmutable($guardada->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    $difGuardada = ($comoReloj->getTimestamp() - $guardadaReloj->getTimestamp()) / 60;
    echo "Última lectura guardada en Preciso (UTC-3): " . $guardadaReloj->format('Y-m-d H:i:s') . "  (atrás de la API: " . round($difGuardada) . " min)\n";
    if (abs(abs($difGuardada) - 180) < 30) {
        echo "   ⚠️ Las lecturas guardadas están CORRIDAS 3 HORAS: revisar que APP_TIMEZONE=\"America/Argentina/Mendoza\" esté en el servicio del CRON en Railway.\n";
    } elseif ($difGuardada >= -5 && $difGuardada < 120) {
        echo "   ✓ Las lecturas guardadas coinciden con la hora de la API (la diferencia es solo la demora del cron).\n";
    }
}
echo "\n";

if (abs($difUtc) < abs($difLocal) && abs($difUtc) < 90) {
    echo "=> La API manda la hora en UTC.\n";
    echo "   Poner la variable API_TIMEZONE=\"UTC\" en Railway (backend Y cron).\n";
} elseif (abs($difLocal) < 90) {
    echo "=> La API manda la hora local (" . date_default_timezone_get() . "). No hay que cambiar nada de zona horaria.\n";
} else {
    echo "=> No es concluyente: la última lectura es vieja. Probá con un equipo que esté reportando ahora mismo.\n";
}
