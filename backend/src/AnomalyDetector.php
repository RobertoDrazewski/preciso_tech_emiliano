<?php

/**
 * Motor de detección de anomalías de combustible.
 *
 * ⚠️ Todo en litros absolutos — Emiliano confirmó (27/07/2026) que no existe
 * capacidad de tanque en ningún lado, así que ningún cálculo acá usa "% del
 * tanque". Los umbrales son litros perdidos, directamente.
 *
 * Explicable, con el filtro de
 * "rebote de pendiente" que pidió Emiliano: distinguir una caída real
 * (robo/fuga) de un falso positivo cuando el combustible se acumula de un
 * lado del tanque en una pendiente y el sensor lee de menos, para después
 * volver a su nivel normal cuando el vehículo se nivela.
 *
 * La forma correcta de distinguir esto (más importante que cualquier umbral)
 * es mirar qué pasa DESPUÉS de la caída, no solo la caída en sí:
 *
 *   - Una caída de pendiente se RECUPERA sola en minutos: el nivel vuelve a
 *     subir cerca de donde estaba antes, sin que el vehículo haya cargado
 *     combustible.
 *   - Una caída real (fuga, robo, consumo anómalo) NO se recupera: el nivel
 *     se queda abajo.
 *
 * Por eso ninguna caída se marca como anomalía en el momento en que ocurre.
 * Primero se espera una "ventana de confirmación" (config: recovery_window_minutes).
 * Si dentro de esa ventana el nivel se recupera al menos
 * recovery_min_fraction de lo que había caído, se descarta como rebote de
 * pendiente y ni siquiera se cuenta para las estadísticas. Si no se
 * recupera, ahí sí se evalúa como candidata a anomalía real con las reglas
 * de siempre (umbral absoluto en litros + z-score contra el historial propio
 * del vehículo — historial que además ya viene limpio de rebotes, porque se
 * arma con el mismo filtro).
 *
 * Si todavía no pasó suficiente tiempo desde la caída como para saber si se
 * va a recuperar o no, la caída queda "pendiente de confirmar" y se
 * reevalúa sola en la próxima corrida del cron — no se alerta ni se
 * descarta a las apuradas.
 */
class AnomalyDetector
{
    private array $cfg;
    private Storage $storage;

    public function __construct(array $anomalyConfig, Storage $storage)
    {
        $this->cfg = $anomalyConfig;
        $this->storage = $storage;
    }

    /**
     * @param string $equipo id del equipo a analizar
     * @param DateTimeInterface $ahora instante actual — nada más allá de esto existe todavía
     * @return array<int, array> anomalías CONFIRMADAS (ya descartados los rebotes de pendiente y las caídas aún sin confirmar)
     */
    public function analizar(string $equipo, DateTimeInterface $ahora, ?float $capacidadLitros = null): array
    {
        $ventanaHistorial = $this->cfg['history_window_hours'] ?? 168; // 7 días por defecto
        $desde = $ahora->modify("-{$ventanaHistorial} hours");

        $lecturas = $this->storage->lecturasTypedEnRango($equipo, $desde, $ahora);
        if (count($lecturas) < 2) {
            return [];
        }

        $confirmadas = $this->caidasConfirmadas($lecturas, $ahora);

        // Historial de tasas (L/min) SOLO de caídas ya confirmadas como
        // reales (sin rebotes de pendiente), para que el z-score no se
        // "acostumbre" a ruido de sensor y pierda sensibilidad.
        $tasas = array_map(fn($c) => $c['litros_perdidos'] / $c['minutos'], $confirmadas);
        [$media, $desvio] = $this->mediaYDesvio($tasas);
        $usarZScore = count($tasas) >= $this->cfg['min_history_points'] && $desvio > 0;

        $anomalias = [];
        foreach ($confirmadas as $c) {
            $tasaPorMinuto = $c['litros_perdidos'] / $c['minutos'];
            $notaVariacion = $c['variacion_detectada']
                ? ' El dispositivo también marcó una variación en ese momento (campo "variacion"), lo que corrobora el evento.'
                : '';

            if ($c['detenido'] && $c['litros_perdidos'] >= $this->cfg['stopped_drop_liters_threshold']) {
                $anomalias[] = $this->construir(
                    $equipo, $c['fecha'], 'descarga_detenido',
                    sprintf(
                        'Vehículo detenido perdió %.1f L entre %s y %s (%.1f L/min), sin recuperarse.%s',
                        $c['litros_perdidos'], $c['fecha_inicio']->format('H:i'), $c['fecha']->format('H:i'), $tasaPorMinuto, $notaVariacion
                    ),
                    $c['litros_perdidos'], null, $c['lat'], $c['lng']
                );
                continue;
            }

            if ($c['litros_perdidos'] >= $this->cfg['min_drop_liters_instant']
                && $c['minutos'] <= $this->cfg['min_drop_window_minutes']
            ) {
                $anomalias[] = $this->construir(
                    $equipo, $c['fecha'], 'caida_instantanea',
                    sprintf(
                        'Caída de %.1f L en %.0f minutos (%.1f L/min), sin recuperarse después.%s',
                        $c['litros_perdidos'], $c['minutos'], $tasaPorMinuto, $notaVariacion
                    ),
                    $c['litros_perdidos'], null, $c['lat'], $c['lng']
                );
                continue;
            }

            if ($usarZScore) {
                $z = ($tasaPorMinuto - $media) / $desvio;
                if ($z >= $this->cfg['z_threshold']) {
                    $anomalias[] = $this->construir(
                        $equipo, $c['fecha'], 'consumo_anomalo',
                        sprintf(
                            'Caída de %.1f L en %.0f min (%.3f L/min), sin recuperarse — %.1f desvíos estándar por encima de lo normal para este vehículo.%s',
                            $c['litros_perdidos'], $c['minutos'], $tasaPorMinuto, $z, $notaVariacion
                        ),
                        $c['litros_perdidos'], $z, $c['lat'], $c['lng']
                    );
                }
            }
        }

        // Agua en combustible: a diferencia de las otras 3, esta no mide
        // litros ni necesita ventana de confirmación — es una lectura
        // directa del sensor WIF del dispositivo (booleano). Solo se marca
        // en el momento en que el sensor PASA a detectar agua (no en cada
        // lectura mientras sigue en 1), para no generar una anomalía por
        // cada lectura de un mismo evento sostenido.
        foreach ($this->deteccionAgua($lecturas) as $a) {
            $anomalias[] = $a;
        }

        // Ralentí: motor encendido + detenido + consumiendo, sostenido en
        // el tiempo. A propósito NO usa GPS/posición (Emiliano: muchos
        // equipos quedan bajo techo y ahí el GPS no sirve).
        foreach ($this->deteccionRalenti($lecturas) as $a) {
            $anomalias[] = $a;
        }

        // ⚠️ Chequeo de sentido físico: si sabemos la capacidad del tanque
        // (real de TTM si la tienen cargada, o si no, la que calculamos
        // solos mirando el historial propio del equipo — no vamos a
        // depender de que TTM la cargue, ver Storage::capacidadEstimadaPorHistorial),
        // y una caída reportada la supera, es prácticamente imposible que
        // sea combustible real perdido — es mucho más probable que sea un
        // error de sensor o una pérdida/reconexión de alimentación del
        // dispositivo (justo el caso real que nos marcó Emiliano el
        // 26/08/2026, con AG914LT). Lo marcamos en el detalle en vez de
        // descartarlo solo, para que quede a la vista y alguien lo confirme.
        if ($capacidadLitros !== null && $capacidadLitros > 0) {
            foreach ($anomalias as &$a) {
                if ($a['litros_perdidos'] > $capacidadLitros) {
                    $a['detalle'] .= sprintf(
                        ' ⚠️ Esto supera la capacidad conocida del tanque (%.0f L) — es más probable que sea un error de sensor o una pérdida/reconexión de alimentación del dispositivo que una pérdida real de combustible. Revisar antes de dar por confirmado.',
                        $capacidadLitros
                    );
                    $a['sospecha_error_sensor'] = true;
                }
            }
            unset($a);
        }

        return $anomalias;
    }

    /**
     * Detecta el momento en que el sensor WIF (Water In Fuel) pasa de
     * "sin agua" a "agua detectada". Confirmado por Emiliano (14/08/2026):
     * es un campo booleano (0 o 1) que manda el propio dispositivo.
     */
    private function deteccionAgua(array $lecturas): array
    {
        $anomalias = [];
        $wifAnterior = null;

        foreach ($lecturas as $l) {
            if ($l['wif'] === null) {
                continue; // sin dato de este sensor en esta lectura
            }
            if ($l['wif'] === true && $wifAnterior !== true) {
                $anomalias[] = $this->construir(
                    $l['equipo'], $l['fecha'], 'agua_combustible',
                    'El sensor del dispositivo detectó agua en el combustible.',
                    0.0, null, $l['lat'], $l['lng']
                );
            }
            $wifAnterior = $l['wif'];
        }

        return $anomalias;
    }

    /**
     * Detecta bloques de ralentí: motor encendido + velocidad 0 + consumo
     * real durante ese tramo, sostenido al menos `ralenti_minutos_minimos`.
     * A propósito NO usa GPS/posición — Emiliano lo pidió así porque muchos
     * equipos quedan bajo techo (mala señal de GPS ahí) y porque
     * motor+velocidad+consumo ya alcanza para detectarlo bien.
     *
     * Solo se reporta un bloque que ya TERMINÓ dentro de los datos
     * analizados (el vehículo arrancó a moverse o se apagó el motor) — un
     * bloque que sigue en curso al final de la ventana consultada se deja
     * sin reportar todavía, para no generar entradas repetidas con límites
     * distintos cada vez que el cron vuelve a mirar el mismo ralentí largo
     * en corridas sucesivas (mismo problema que ya resolvimos con el
     * simulador, aplicado acá de forma preventiva).
     */
    private function deteccionRalenti(array $lecturas): array
    {
        $anomalias = [];
        $minMinutos = $this->cfg['ralenti_minutos_minimos'] ?? 15;
        $minLitros = $this->cfg['ralenti_litros_minimos'] ?? 1.0;

        $inicioBloque = null;
        $finBloque = null;
        $combustibleInicio = null;
        $combustibleUltimo = null;

        foreach ($lecturas as $l) {
            $enRalenti = $l['motor_encendido'] === true && (float) $l['velocidad'] === 0.0;

            if ($enRalenti) {
                if ($inicioBloque === null) {
                    $inicioBloque = $l['fecha'];
                    $combustibleInicio = null;
                }
                $finBloque = $l['fecha'];
                if ($l['combustible_litros'] !== null) {
                    if ($combustibleInicio === null) {
                        $combustibleInicio = $l['combustible_litros'];
                    }
                    $combustibleUltimo = $l['combustible_litros'];
                }
                continue;
            }

            // Se cortó el ralentí (se movió o se apagó el motor) — evaluamos
            // el bloque que acaba de terminar.
            $anomalia = $this->evaluarBloqueRalenti($l['equipo'] ?? null, $inicioBloque, $finBloque, $combustibleInicio, $combustibleUltimo, $minMinutos, $minLitros);
            if ($anomalia !== null) {
                $anomalias[] = $anomalia;
            }
            $inicioBloque = null;
            $finBloque = null;
            $combustibleInicio = null;
            $combustibleUltimo = null;
        }

        // Nota: si el array termina en medio de un bloque de ralentí (el
        // vehículo seguía detenido con el motor prendido en la última
        // lectura), a propósito NO lo reportamos todavía — ver el comentario
        // de arriba.

        return $anomalias;
    }

    private function evaluarBloqueRalenti(?string $equipo, ?DateTimeInterface $inicio, ?DateTimeInterface $fin, ?float $combustibleInicio, ?float $combustibleUltimo, int $minMinutos, float $minLitros): ?array
    {
        if ($inicio === null || $fin === null || $equipo === null) {
            return null;
        }

        $minutos = ($fin->getTimestamp() - $inicio->getTimestamp()) / 60;
        if ($minutos < $minMinutos) {
            return null;
        }

        if ($combustibleInicio === null || $combustibleUltimo === null) {
            return null; // no hubo lecturas de combustible válidas en el bloque, no se puede medir el consumo
        }

        $litrosConsumidos = $combustibleInicio - $combustibleUltimo;
        if ($litrosConsumidos < $minLitros) {
            return null; // se mantuvo con el motor prendido, pero no gastó lo suficiente como para marcarlo
        }

        return $this->construir(
            $equipo, $fin, 'ralenti',
            sprintf(
                'Motor encendido y vehículo detenido durante %.0f minutos (de %s a %s), consumiendo %.1f L en ralentí.',
                $minutos, $inicio->format('H:i'), $fin->format('H:i'), $litrosConsumidos
            ),
            $litrosConsumidos, null, null, null // sin lat/lng a propósito, ver comentario de la función que llama a esto
        );
    }

    /**
     * Recorre las lecturas y arma la lista de caídas de combustible que ya
     * se pueden dar por confirmadas (ventana de recuperación vencida y el
     * nivel no volvió). Las caídas que se recuperaron se descartan del
     * todo. Las que todavía están dentro de la ventana de confirmación se
     * ignoran por ahora (se van a reevaluar en la próxima corrida, cuando
     * ya haya pasado suficiente tiempo real).
     */
    private function caidasConfirmadas(array $lecturas, DateTimeInterface $ahora): array
    {
        $ventanaMin = $this->cfg['recovery_window_minutes'];
        $fraccionMinRecuperacion = $this->cfg['recovery_min_fraction'];

        $confirmadas = [];

        // ⚠️ Importante: comparamos lecturas CONSECUTIVAS CON COMBUSTIBLE
        // VÁLIDO, no vecinos crudos del array. Si comparáramos solo
        // $lecturas[$i-1] con $lecturas[$i], un registro TIEMPO (combustible
        // en null) metido en el medio haría que una caída real quedara
        // invisible: la lectura DATA de antes del TIEMPO no se compara con
        // la de después, y el hueco desaparece sin que nadie lo vea.
        $lecturasConCombustible = array_values(array_filter($lecturas, fn($l) => $l['combustible_litros'] !== null));

        for ($i = 1; $i < count($lecturasConCombustible); $i++) {
            $prev = $lecturasConCombustible[$i - 1];
            $cur = $lecturasConCombustible[$i];

            $litrosPerdidos = $prev['combustible_litros'] - $cur['combustible_litros'];
            if ($litrosPerdidos <= 0) {
                continue; // no hubo caída
            }

            $minutos = ($cur['fecha']->getTimestamp() - $prev['fecha']->getTimestamp()) / 60;
            if ($minutos <= 0) {
                continue;
            }

            $finVentana = $cur['fecha']->modify("+{$ventanaMin} minutes");
            if ($finVentana > $ahora) {
                // Todavía no pasó suficiente tiempo real para saber si esto
                // se recupera solo o no. La dejamos pendiente para la
                // próxima corrida — ni se descarta ni se marca como
                // anomalía todavía.
                continue;
            }

            // Mejor nivel de combustible alcanzado dentro de la ventana de
            // recuperación (mirando hacia adelante desde la caída), y si en
            // el medio el dispositivo marcó alguna "variacion" != 0 (señal
            // propia del equipo, ver field_map.php). Acá sí recorremos TODAS
            // las lecturas (no solo las que tienen combustible) para no
            // perdernos ninguna "variacion" que haya venido en un registro
            // TIEMPO en el medio.
            $mejorNivelPosterior = $cur['combustible_litros'];
            $variacionDetectada = ($cur['variacion'] !== null && $cur['variacion'] != 0.0);
            foreach ($lecturas as $l) {
                if ($l['fecha'] > $cur['fecha'] && $l['fecha'] <= $finVentana) {
                    if ($l['combustible_litros'] !== null) {
                        $mejorNivelPosterior = max($mejorNivelPosterior, $l['combustible_litros']);
                    }
                    if ($l['variacion'] !== null && $l['variacion'] != 0.0) {
                        $variacionDetectada = true;
                    }
                }
            }

            $recuperado = $mejorNivelPosterior - $cur['combustible_litros'];
            $fraccionRecuperada = $recuperado / $litrosPerdidos;

            if ($fraccionRecuperada >= $fraccionMinRecuperacion) {
                // Rebote de pendiente / ruido de sensor — se recuperó solo,
                // no se cuenta como caída real ni se usa para estadística.
                continue;
            }

            $confirmadas[] = [
                'fecha_inicio'         => $prev['fecha'],
                'fecha'                => $cur['fecha'],
                'litros_perdidos'      => $litrosPerdidos,
                'minutos'              => $minutos,
                'detenido'             => !$cur['motor_encendido'] && !$prev['motor_encendido'],
                'variacion_detectada'  => $variacionDetectada,
                'lat'                  => $cur['lat'],
                'lng'                  => $cur['lng'],
            ];
        }

        return $confirmadas;
    }

    private function construir(string $equipo, DateTimeInterface $fecha, string $tipo, string $detalle, float $litros, ?float $z, ?float $lat = null, ?float $lng = null): array
    {
        return [
            'equipo'          => $equipo,
            'fecha'           => $fecha,
            'tipo'            => $tipo,
            'detalle'         => $detalle,
            'litros_perdidos' => round($litros, 2),
            'z_score'         => $z !== null ? round($z, 2) : null,
            'lat'             => $lat,
            'lng'             => $lng,
        ];
    }

    private function mediaYDesvio(array $valores): array
    {
        $n = count($valores);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        $media = array_sum($valores) / $n;
        if ($n < 2) {
            return [$media, 0.0];
        }
        $sumaCuadrados = 0.0;
        foreach ($valores as $v) {
            $sumaCuadrados += ($v - $media) ** 2;
        }
        $desvio = sqrt($sumaCuadrados / ($n - 1));
        return [$media, $desvio];
    }
}
