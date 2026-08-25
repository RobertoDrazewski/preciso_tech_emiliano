<?php

/**
 * Simulador de la API de TTM/Preciso, para poder mostrar el sistema
 * funcionando de punta a punta mientras la API real (ttm.com.ar/testing/...)
 * está caída del lado de ellos.
 *
 * Genera datos con la MISMA estructura exacta que confirmamos con la API
 * real (mismos nombres de campo, mismo comportamiento de eventos DATA/TIEMPO,
 * mismo ruido en nivel2-4 con 0 y 65535), así que todo lo que viene después
 * (DataNormalizer, AnomalyDetector, ReportBuilder) se ejerce exactamente
 * igual que el día que se conecte la API de verdad. Cambiar de uno a otro es
 * una sola variable de entorno (API_SIMULATE), sin tocar ni una línea de
 * código más.
 *
 * La historia que cuenta la simulación, para que la demo se vea completa:
 *   - Manejo normal: viajes durante el día, parado de noche.
 *   - Un "rebote de pendiente" por día (cae y se recupera solo en minutos)
 *     — para mostrar que el filtro de falsos positivos funciona.
 *   - Un evento de descarga REAL en algún punto del período — para mostrar
 *     que el sistema sí detecta lo que tiene que detectar.
 *   - Recargas periódicas para que no se quede sin combustible en ventanas
 *     largas (semanal/mensual).
 *   - Un segundo tanque con datos válidos la mayoría del tiempo, pero con
 *     apariciones de 0 y 65535 para probar el descarte.
 */
class SimulatedApiClient
{
    private const PASO_MINUTOS = 5;

    public function getFullData(string $equipoId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $data = [];
        $id = 500000000 + random_int(0, 999999); // arranque de ID, no importa el valor exacto

        $inicioVentana = DateTimeImmutable::createFromInterface($from);
        $finVentana = DateTimeImmutable::createFromInterface($to);

        // ⚠️ Punto fijo determinístico por equipo+día (NO relativo a la
        // ventana pedida). Antes esto se calculaba como "1/3 de la ventana
        // desde/hasta", pero con el cron corriendo cada 5 min esa ventana se
        // corre todo el tiempo — cada corrida generaba un timestamp NUEVO
        // para "la misma" anomalía, y el sistema de deduplicado (que
        // compara equipo+fecha+tipo) nunca la reconocía como repetida.
        // Resultado: una anomalía "nueva" (y un mail, si estaba activado)
        // por cada equipo en CADA corrida del cron, para siempre. Ahora el
        // momento del evento se ancla a la medianoche del día + un offset
        // fijo por equipo (siempre el mismo para ese equipo, ese día),
        // así que si la ventana lo vuelve a incluir en una corrida
        // posterior, el dedup lo reconoce bien y no se repite.
        $diaBase = new DateTimeImmutable($inicioVentana->format('Y-m-d'));
        $offsetSegundos = crc32($equipoId . $diaBase->format('Y-m-d')) % 43200; // hasta 12hs después de medianoche
        $momentoRobo = $diaBase->modify("+{$offsetSegundos} seconds");

        $combustible = 128.0; // arranca con el tanque bastante lleno
        $tanque2Base = 42.0;
        $odometro = 418500.0 + random_int(0, 400); // base creíble para un vehículo de reparto
        $lat = -33.156575;
        $lng = -68.442403;

        $cursor = $inicioVentana;
        $paso = 0;
        $yaOcurrioRobo = false;
        $recuperandoseDePendiente = null; // timestamp hasta el que dura el "hueco" de pendiente

        while ($cursor <= $finVentana) {
            $hora = (int) $cursor->format('H');
            $minutoDelDia = $hora * 60 + (int) $cursor->format('i');

            // ⚠️ $pasoAbsoluto en vez de $paso: tiene que depender del
            // instante de reloj, no de en qué paso del loop está ESTA
            // llamada puntual — si no, el mismo instante puede salir "en
            // movimiento" en una corrida del cron y "detenido" en la
            // siguiente (porque cada corrida arranca su ventana en un
            // minuto distinto), y eso corre el momento exacto en el que se
            // dispara la anomalía simulada cada vez, rompiendo el
            // deduplicado.
            $pasoAbsoluto = intdiv($minutoDelDia, self::PASO_MINUTOS);

            $enHorarioLaboral = $hora >= 7 && $hora < 19;
            $enAlmuerzo = $hora === 13;
            $enMovimiento = $enHorarioLaboral && !$enAlmuerzo && ($pasoAbsoluto % 3 !== 0);

            // --- Consumo normal ---
            if ($enMovimiento) {
                $combustible -= 0.09;
                $odometro += 0.35;
                $lat += 0.0006;
                $lng += 0.0005;
            } elseif ($enHorarioLaboral) {
                $combustible -= 0.01; // ralentí ocasional
            }

            // --- Rebote de pendiente, una vez por día, ~9:15-9:35 ---
            $esVentanaDePendiente = $hora === 9 && $minutoDelDia % 1440 >= 555 && $minutoDelDia % 1440 <= 575;
            if ($esVentanaDePendiente && $recuperandoseDePendiente === null && $enMovimiento) {
                $combustible -= 16.0; // el sensor "lee de menos" en la subida
                $recuperandoseDePendiente = $cursor->modify('+15 minutes');
            } elseif ($recuperandoseDePendiente !== null && $cursor >= $recuperandoseDePendiente) {
                $combustible += 16.0; // se nivela y el sensor vuelve a leer normal
                $recuperandoseDePendiente = null;
            }

            // --- Descarga real, una sola vez en la ventana, vehículo detenido ---
            $variacion = 0;
            if (!$yaOcurrioRobo && $cursor >= $momentoRobo && !$enMovimiento) {
                $combustible -= 27.0;
                $variacion = -27;
                $yaOcurrioRobo = true;
            }

            $combustible = max(8.0, $combustible);

            // --- Recarga si se está por quedar sin combustible (ventanas largas) ---
            if ($combustible < 15.0) {
                $combustible = 128.0;
            }

            // --- Tanque 2: casi siempre válido, a veces "desconectado" (0) o "no presente" (65535) ---
            $tanque2Base += $enMovimiento ? -0.03 : 0;
            $tanque2Base = max(5.0, $tanque2Base);
            $ruidoTanque2 = $paso % 47 === 0 ? 0 : ($paso % 91 === 0 ? 65535 : round($tanque2Base, 1));

            // --- Alternamos evento DATA / TIEMPO como hace la API real ---
            $esTiempo = ($pasoAbsoluto % 3) === 2;

            $data[] = [
                'ID'                 => $id++,
                'variacion'          => $variacion,
                'fecha_reporte'      => $cursor->format('Y-m-d H:i:s'),
                'odometro'           => $esTiempo ? round($odometro, 1) : 0,
                'evento'             => $esTiempo ? 'TIEMPO' : 'DATA',
                'combustible_total'  => $esTiempo ? 0 : round($combustible, 1),
                'nivel1'             => $esTiempo ? 0 : round($combustible, 1),
                'nivel2'             => $esTiempo ? 0 : $ruidoTanque2,
                'nivel3'             => 65535,
                'nivel4'             => 65535,
                'longitud'           => round($lng, 6),
                'altitud'            => 660.3,
                'latitud'            => round($lat, 6),
                'velocidad'          => $enMovimiento ? (35 + ($paso % 5) * 6) : 0,
                'temp_tanque'        => 18 + ($paso % 6),
                'movimiento'         => $enMovimiento ? 1 : 0,
                'equipo'             => $equipoId,
            ];

            $cursor = $cursor->modify('+' . self::PASO_MINUTOS . ' minutes');
            $paso++;
        }

        return [
            'success' => true,
            'total'   => count($data),
            'data'    => $data,
        ];
    }
}
