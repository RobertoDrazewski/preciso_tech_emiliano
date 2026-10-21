<?php

/**
 * Programador de tareas para correr el cron SIN crontab (por ejemplo, en el
 * contenedor "cron" de docker-compose.yml, o en un servidor donde no se
 * pueda tocar el crontab). Queda corriendo para siempre y:
 *
 *   - cada CRON_MINUTOS minutos (5 por defecto) corre cron/check_alertas.php
 *   - todos los días a la hora CRON_HORA_RESUMEN (6 por defecto, hora de la
 *     aplicación, UTC-3) corre cron/generar_informes.php
 *
 * Cada tarea corre en un proceso aparte: si una falla, el programador sigue.
 * Si una corrida de check_alertas tarda más que el intervalo, la próxima
 * arranca apenas termina (nunca dos a la vez).
 *
 * Si el servidor tiene crontab, NO hace falta esto: alcanza con las dos
 * líneas de crontab que están en el README / manual de integración.
 *
 * Uso:
 *   php cron/programador.php
 */

require_once __DIR__ . '/../bootstrap.php';

$minutos = max(1, (int) (getenv('CRON_MINUTOS') ?: 5));
$horaResumen = (int) (getenv('CRON_HORA_RESUMEN') !== false && getenv('CRON_HORA_RESUMEN') !== '' ? getenv('CRON_HORA_RESUMEN') : 6);
$php = PHP_BINARY ?: 'php';

$log = fn(string $t) => print('[' . date('Y-m-d H:i:s') . "] [programador] {$t}\n");
$log("Arranco: check_alertas cada {$minutos} min, resumen diario a las {$horaResumen}:00.");

$ultimoResumen = null; // fecha (Y-m-d) del último resumen diario enviado

while (true) {
    $inicio = time();

    passthru(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/check_alertas.php'), $codigo);
    if ($codigo !== 0) {
        $log("check_alertas terminó con código {$codigo}.");
    }

    // generar_informes.php se fija solo si el resumen de ayer ya salió, así
    // que un reinicio del programador no lo manda dos veces.
    $hoy = date('Y-m-d');
    if ((int) date('G') >= $horaResumen && $ultimoResumen !== $hoy) {
        passthru(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/generar_informes.php'), $codigo);
        if ($codigo !== 0) {
            $log("generar_informes terminó con código {$codigo}.");
        } else {
            $ultimoResumen = $hoy;
        }
    }

    $espera = $minutos * 60 - (time() - $inicio);
    if ($espera > 0) {
        sleep($espera);
    }
}
