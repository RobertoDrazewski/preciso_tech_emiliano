<?php

/**
 * Motor de detección de anomalías de combustible.
 *
 * ⚠️ Todo en litros absolutos — Emiliano confirmó (27/07/2026) que no existe
 * capacidad de tanque en ningún lado, así que ningún cálculo acá usa "% del
 * tanque". Los umbrales son litros perdidos, directamente.
 *
 * Explicable (estadística simple, no una caja negra), con el filtro de
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
        // Los umbrales que el usuario cambió desde la pantalla (tabla
        // "parametros") pisan los de config.php. Si nunca tocó nada, quedan
        // los de config.
        $this->cfg = array_merge($anomalyConfig, $storage->parametrosGuardados());
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

        // El contacto del motor: TTM lo manda como eventos IGON / IGOFF.
        // El campo "movimiento" que usábamos casi nunca viene con dato (4 de
        // 3.612 registros en una semana real), así que el "motor encendido"
        // estaba casi siempre en falso y el ralentí no andaba.
        $lecturas = $this->aplicarEstadoIgnicion($lecturas);

        // ¿Es un equipo que no se mueve nunca (tanque fijo, pozo, máquina)?
        // A esos no les aplican las reglas pensadas para vehículos — ver
        // 'palabras_equipo_estacionario' en config.
        $esEstacionario = $this->esEquipoEstacionario($equipo);

        $confirmadas = $this->caidasConfirmadas($lecturas, $ahora);

        // Descargas LENTAS con el vehículo parado (14/10/2026): sumo las caídas
        // seguidas en un solo evento. Comparando solo lectura contra lectura,
        // un robo de 120 L en una hora (10 L cada 5 minutos) no aparecía,
        // porque cada paso quedaba debajo del mínimo.
        $tramos = $esEstacionario ? [] : $this->tramosDescargaDetenido($lecturas, $ahora);

        // Historial de tasas (L/min) SOLO de caídas ya confirmadas como
        // reales (sin rebotes de pendiente), para que el z-score no se
        // "acostumbre" a ruido de sensor y pierda sensibilidad.
        $tasaMaxima = $this->cfg['tasa_maxima_fisica_lpm'] ?? 150.0;
        // Los saltos imposibles (desconexiones) los dejo afuera de la
        // estadística: si no, un salto de 800 L en un minuto infla el
        // promedio del vehículo y después ninguna caída real parece rara.
        $tasas = [];
        foreach ($confirmadas as $c) {
            $t = $c['litros_perdidos'] / $c['minutos'];
            if ($t <= $tasaMaxima) {
                $tasas[] = $t;
            }
        }
        [$media, $desvio] = $this->mediaYDesvio($tasas);
        $usarZScore = count($tasas) >= $this->cfg['min_history_points'] && $desvio > 0;

        $anomalias = [];
        foreach ($tramos as $t) {
            $anomalias[] = $this->construir(
                $equipo, $t['fin'], 'descarga_detenido',
                sprintf(
                    'Vehículo detenido perdió %.1f L de forma sostenida entre %s y %s (%s, %.1f L/min promedio, en %d bajadas seguidas), sin recuperarse.',
                    $t['litros'], $this->horaCorta($t['inicio'], $t['fin']), $this->horaCorta($t['fin'], $t['inicio']),
                    $this->textoDuracion($t['minutos']), $t['litros'] / max(1, $t['minutos']), $t['pasos']
                ),
                $t['litros'], null, $t['lat'], $t['lng']
            );
        }
        foreach ($confirmadas as $c) {
            // Si esta caída es parte de una descarga lenta ya reportada, no la
            // repito suelta.
            foreach ($tramos as $t) {
                if ($c['fecha'] > $t['inicio'] && $c['fecha'] <= $t['fin']) {
                    continue 2;
                }
            }
            $tasaPorMinuto = $c['litros_perdidos'] / $c['minutos'];
            $notaVariacion = $c['variacion_detectada']
                ? ' El dispositivo también marcó una variación en ese momento (campo "variacion"), lo que corrobora el evento.'
                : '';

            // Salto más rápido que cualquier bomba → no es combustible que
            // sale del tanque, es el sensor que se desconectó o se
            // reinició (punto 5 de Emiliano: "desconexión de algún
            // tanque"). Va con 0 L de merma porque no se perdió nada real.
            // En tanques fijos / de stock y otros estacionarios, una caída muy
            // rápida es un despacho con bomba de alto caudal, no una
            // desconexión: el top de "desconexiones" eran tanques de stock.
            if ($tasaPorMinuto > $tasaMaxima && $esEstacionario) {
                continue;
            }
            if ($tasaPorMinuto > $tasaMaxima) {
                $anomalias[] = $this->construir(
                    $equipo, $c['fecha'], 'desconexion_sensor',
                    sprintf(
                        'El nivel saltó de golpe %.1f L en %s (%.0f L/min) y no volvió. Ninguna bomba saca combustible tan rápido: es una desconexión o reinicio del sensor/tanque, no una pérdida real. Revisar la instalación.',
                        $c['litros_perdidos'], $this->textoDuracion($c['minutos']), $tasaPorMinuto
                    ),
                    0.0, null, $c['lat'], $c['lng']
                );
                continue;
            }

            // Descarga detenido y caída instantánea: solo para vehículos. A un
            // tanque fijo le sacan combustible todo el día (es su trabajo) y
            // un pozo consume sin moverse — para ellos esto es lo normal.
            if (!$esEstacionario && $c['detenido'] && $c['litros_perdidos'] >= $this->cfg['stopped_drop_liters_threshold']) {
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

            if (!$esEstacionario && $c['litros_perdidos'] >= $this->cfg['min_drop_liters_instant']
                && $c['minutos'] <= $this->cfg['min_drop_window_minutes']
            ) {
                $anomalias[] = $this->construir(
                    $equipo, $c['fecha'], 'caida_instantanea',
                    sprintf(
                        'Caída de %.1f L en %s (%.1f L/min), sin recuperarse después.%s',
                        $c['litros_perdidos'], $this->textoDuracion($c['minutos']), $tasaPorMinuto, $notaVariacion
                    ),
                    $c['litros_perdidos'], null, $c['lat'], $c['lng']
                );
                continue;
            }

            if ($usarZScore) {
                $z = ($tasaPorMinuto - $media) / $desvio;
                if ($z >= $this->cfg['z_threshold'] && $c['litros_perdidos'] >= ($this->cfg['consumo_anomalo_litros_minimos'] ?? 10.0)) {
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
        // El ralentí tampoco tiene sentido en algo que no es un vehículo.
        if (!$esEstacionario) {
            foreach ($this->deteccionRalenti($lecturas) as $a) {
                $anomalias[] = $a;
            }
        }

        // Carga informada vs. medida (evento CARGA).
        foreach ($this->deteccionCargas($lecturas, $ahora) as $a) {
            $anomalias[] = $a;
        }

        // Cortes de alimentación (POWOFF/POWON): cada corte se reporta, y si
        // al volver falta combustible, se marca como posible robo.
        foreach ($this->deteccionCortesAlimentacion($lecturas) as $a) {
            $anomalias[] = $a;
        }

        // Temperatura / nivel: el sensor de nivel "sigue" a la temperatura
        // mucho más de lo que explica la dilatación del gasoil. No es una
        // pérdida de combustible, es un aviso de que ese sensor no es
        // confiable. Ver deteccionTemperaturaNivel() más abajo.
        foreach ($this->deteccionTemperaturaNivel($lecturas) as $a) {
            // Es un aviso sobre el sensor, no un evento: si ya se avisó en la
            // última semana (en cualquier estado), no lo repito cada día.
            if ($this->storage->existeAnomaliaDeTipoDesde($equipo, 'variacion_temperatura', $a['fecha']->modify('-7 days'), $a['fecha'])) {
                continue;
            }
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
     * Pone el "motor encendido" de cada lectura según los eventos de
     * contacto IGON (se prendió) / IGOFF (se apagó) que manda TTM.
     *
     * Recorro en orden y voy arrastrando el estado. Para las lecturas de
     * antes del primer evento, lo deduzco del propio evento: si el
     * primero es IGOFF, antes estaba prendido; si es IGON, estaba apagado.
     * Si en todo el período no hay ningún IGON/IGOFF, no toco nada y queda
     * lo que ya había (mejor eso que inventar).
     */
    private function aplicarEstadoIgnicion(array $lecturas): array
    {
        $primero = null;
        foreach ($lecturas as $l) {
            if (in_array($l['evento'] ?? null, ['IGON', 'IGOFF'], true)) {
                $primero = $l['evento'];
                break;
            }
        }
        if ($primero === null) {
            return $lecturas;
        }

        $encendido = ($primero === 'IGOFF');
        $maxHueco = $this->cfg['max_gap_minutos'] ?? 30;
        $anterior = null;
        foreach ($lecturas as $i => $l) {
            // Después de un hueco sin datos no asumo que el motor siguió
            // prendido: si llegó un IGON y el equipo dejó de reportar (por
            // ejemplo, por un corte de alimentación), el IGOFF nunca llega y
            // el motor quedaba "prendido" días enteros. Hasta el próximo
            // evento de contacto, lo tomo como apagado.
            if ($anterior !== null && ($l['fecha']->getTimestamp() - $anterior['fecha']->getTimestamp()) / 60 > $maxHueco) {
                $encendido = false;
            }
            $anterior = $l;
            if (($l['evento'] ?? null) === 'IGON') {
                $encendido = true;
            } elseif (($l['evento'] ?? null) === 'IGOFF') {
                $encendido = false;
            }
            $lecturas[$i]['motor_encendido'] = $encendido;
        }
        return $lecturas;
    }

    /**
     * Carga informada vs. medida — punto 3 de Emiliano.
     *
     * TTM manda un evento CARGA con los litros informados en "variacion".
     * Para cada uno comparo:
     *   - lo informado, contra
     *   - lo que subió de verdad el nivel: el último nivel válido antes de
     *     la carga contra el más alto que alcanzó en los 30 minutos
     *     siguientes (le doy tiempo a que el combustible se asiente).
     *
     * Si la diferencia pasa los dos mínimos de config (litros y
     * porcentaje), aviso. Si entró MENOS de lo informado, esa diferencia
     * cuenta como merma (se pagó combustible que no está en el tanque);
     * si entró MÁS, igual aviso, pero sin merma.
     *
     * Si todavía no pasaron los 30 minutos desde la carga, espero a la
     * próxima corrida. La fecha de la anomalía es la del evento CARGA, así
     * el cron no la duplica.
     */
    private function deteccionCargas(array $lecturas, DateTimeInterface $ahora): array
    {
        $margen = $this->cfg['carga_margen_lectura_minutos'] ?? 60;
        $asentamiento = $this->cfg['carga_ventana_asentamiento_min'] ?? 30;
        $minLitros = $this->cfg['carga_diferencia_min_litros'] ?? 10.0;
        $minPct = $this->cfg['carga_diferencia_min_porcentaje'] ?? 0.15;

        $anomalias = [];
        $n = count($lecturas);

        $agrupar = $this->cfg['carga_agrupar_minutos'] ?? 30;

        for ($i = 0; $i < $n; $i++) {
            $carga = $lecturas[$i];
            if (($carga['evento'] ?? null) !== 'CARGA' || ($carga['variacion'] ?? null) === null || (float) $carga['variacion'] == 0.0) {
                continue;
            }

            // Junto los eventos CARGA que vienen seguidos: son una sola carga
            // partida en pedazos. Sumo lo informado de todos.
            $grupo = [$carga];
            $ultima = $carga;
            for ($j = $i + 1; $j < $n; $j++) {
                $l = $lecturas[$j];
                if (($l['fecha']->getTimestamp() - $ultima['fecha']->getTimestamp()) / 60 > $agrupar) {
                    break;
                }
                if (($l['evento'] ?? null) === 'CARGA' && ($l['variacion'] ?? null) !== null && (float) $l['variacion'] != 0.0) {
                    $grupo[] = $l;
                    $ultima = $l;
                    $i = $j; // no vuelvo a procesar estos eventos
                }
            }

            $finAsentamiento = $ultima['fecha']->modify("+{$asentamiento} minutes");
            if ($finAsentamiento > $ahora) {
                continue; // todavía se está asentando, lo veo en la próxima corrida
            }

            // Lo informado es la SUMA de todos los eventos CARGA seguidos.
            // Confirmado por Emiliano (30/09/2026): el chofer carga el tanque
            // 1, corta (una carga), pasa al tanque 2 (otra carga). Cada evento
            // es un tramo, y como el nivel que medimos es el total de todos
            // los tanques, lo informado también tiene que ser el total.
            $informada = array_sum(array_map(fn($g) => abs((float) $g['variacion']), $grupo));

            // Lo medido, como lo hace el propio sensor (Emiliano, 29/09/2026):
            // ESTABILIDAD INICIAL contra ESTABILIDAD FINAL. No dos lecturas
            // sueltas (así me daba 46 L en una carga de 71: tomaba un punto
            // en el medio de la subida).
            //   - Meseta de antes: las lecturas de hasta 'carga_margen_lectura_minutos'
            //     antes de la carga que están cerca del nivel más bajo.
            //   - Meseta de después: las lecturas hasta que se asentó que
            //     están cerca del nivel más alto.
            // De cada meseta tomo la mediana (un pico de ruido no la mueve).
            $tolerancia = $this->cfg['carga_tolerancia_meseta_litros'] ?? 3.0;
            $inicioVentana = $carga['fecha']->modify("-{$margen} minutes");
            $previas = [];
            $posteriores = [];
            foreach ($lecturas as $l) {
                if ($l['combustible_litros'] === null) {
                    continue;
                }
                if ($l['fecha'] >= $inicioVentana && $l['fecha'] <= $ultima['fecha']) {
                    $previas[] = $l['combustible_litros'];
                }
                if ($l['fecha'] >= $carga['fecha'] && $l['fecha'] <= $finAsentamiento) {
                    $posteriores[] = $l['combustible_litros'];
                }
            }
            if (count($previas) === 0 || count($posteriores) === 0) {
                continue; // no tengo cómo medir, no invento
            }
            // La referencia de cada meseta NO es el mínimo ni el máximo sueltos:
            // un pico de ruido hacia abajo antes de la carga, o la espuma que
            // marca de más justo después, se volvían la referencia y las dos
            // cosas agrandaban lo medido (reprocesando 30 días, el 87% de las
            // "cargas no coincide" daban "entró MÁS": un sesgo, no la realidad).
            // Ahora uso el percentil 20 de antes y el 80 de después, y la meseta
            // son las lecturas cercanas a esa referencia. Con menos de 2
            // lecturas en alguna meseta, no hay estabilidad: no mido.
            $refAntes = $this->percentil($previas, 0.20);
            $refDespues = $this->percentil($posteriores, 0.80);
            $mesetaAntes = array_filter($previas, fn($v) => abs($v - $refAntes) <= $tolerancia);
            $mesetaDespues = array_filter($posteriores, fn($v) => abs($v - $refDespues) <= $tolerancia);
            if (count($mesetaAntes) < 2 || count($mesetaDespues) < 2) {
                continue;
            }
            $antes = $this->mediana($mesetaAntes);
            $despues = $this->mediana($mesetaDespues);

            $medida = $despues - $antes;
            $diferencia = $medida - $informada;
            if (abs($diferencia) < $minLitros || abs($diferencia) < $informada * $minPct) {
                continue; // coincide razonablemente
            }
            // "Entró más de lo informado": por defecto no se avisa (ver
            // 'carga_avisar_si_entro_mas' en config).
            if ($diferencia > 0 && empty($this->cfg['carga_avisar_si_entro_mas'])) {
                continue;
            }

            $merma = $diferencia < 0 ? abs($diferencia) : 0.0;
            $partes = count($grupo) > 1 ? sprintf(' (suma de %d cargas seguidas)', count($grupo)) : '';
            // La hora de la carga va en el texto para poder buscarla directo en
            // el gráfico de combustible de TTM y comparar.
            $horaCarga = $carga['fecha']->format('H:i') . ($ultima['fecha'] != $carga['fecha'] ? ' a ' . $ultima['fecha']->format('H:i') : '');
            $detalle = sprintf(
                'Carga de las %s. Informada por el sensor: %.1f L%s. Medida por nivel (estabilidad inicial %.1f L → estabilidad final %.1f L): %.1f L. ',
                $horaCarga, $informada, $partes, $antes, $despues, $medida
            );
            $detalle .= $diferencia < 0
                ? sprintf('Entraron %.1f L menos de lo informado.', abs($diferencia))
                : sprintf('Entraron %.1f L más de lo informado.', $diferencia);

            $anomalias[] = $this->construir(
                $carga['equipo'], $carga['fecha'], 'carga_no_coincide', $detalle,
                $merma, null, $carga['lat'], $carga['lng']
            );
        }

        return $anomalias;
    }

    /**
     * Cortes de alimentación — eventos POWOFF / POWON que manda TTM.
     *
     * Para cada POWOFF busco el POWON que viene después. Si todavía no
     * volvió (el equipo sigue sin alimentación al final de los datos), no
     * reporto nada: lo agarra la próxima corrida del cron, cuando ya se
     * sepa cuánto duró y cómo quedó el tanque. Así no reporto el mismo
     * corte dos veces con datos distintos.
     *
     * Con el corte cerrado, comparo:
     *   - el último nivel válido ANTES del POWOFF, contra
     *   - el primer nivel válido DESPUÉS del POWON
     * (buscando hasta 'corte_margen_lectura_minutos' para cada lado).
     *
     * Siempre reporto el corte (Emiliano quiere ver las desconexiones),
     * pero si al volver faltan 'corte_litros_sospechosos' o más, lo
     * marco como posible robo y cuento esos litros como merma.
     *
     * La fecha de la anomalía es la del POWOFF: siempre la misma para el
     * mismo corte, así el cron no lo duplica.
     */
    private function deteccionCortesAlimentacion(array $lecturas): array
    {
        $margen = $this->cfg['corte_margen_lectura_minutos'] ?? 60;
        $litrosSospechosos = $this->cfg['corte_litros_sospechosos'] ?? 15.0;

        $anomalias = [];
        $n = count($lecturas);

        for ($i = 0; $i < $n; $i++) {
            if (($lecturas[$i]['evento'] ?? null) !== 'POWOFF') {
                continue;
            }
            $off = $lecturas[$i];

            // El POWON que cierra este corte. Si antes aparece otro POWOFF,
            // salteo este y me quedo con el último (en los datos reales hay
            // más POWON que POWOFF: el equipo a veces se reinicia sin un
            // corte previo, y no quiero emparejar mal).
            $on = null;
            $hayOtroOff = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $ev = $lecturas[$j]['evento'] ?? null;
                if ($ev === 'POWOFF') {
                    $hayOtroOff = true;
                    break;
                }
                if ($ev === 'POWON') {
                    $on = $lecturas[$j];
                    break;
                }
            }
            if ($hayOtroOff) {
                continue;
            }
            if ($on === null) {
                continue; // sigue cortado, lo veo en la próxima corrida
            }

            // Último nivel válido antes del corte.
            $antes = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                $l = $lecturas[$k];
                if (($off['fecha']->getTimestamp() - $l['fecha']->getTimestamp()) / 60 > $margen) {
                    break;
                }
                if ($l['combustible_litros'] !== null) {
                    $antes = $l;
                    break;
                }
            }

            // Primer nivel válido después de volver.
            $despues = null;
            foreach ($lecturas as $l) {
                if ($l['fecha'] <= $on['fecha']) {
                    continue;
                }
                if (($l['fecha']->getTimestamp() - $on['fecha']->getTimestamp()) / 60 > $margen) {
                    break;
                }
                if ($l['combustible_litros'] !== null) {
                    $despues = $l;
                    break;
                }
            }

            $minutos = ($on['fecha']->getTimestamp() - $off['fecha']->getTimestamp()) / 60;
            $texto = sprintf(
                'Se cortó la alimentación del equipo de %s a %s (%s).',
                $off['fecha']->format('d/m H:i'), $on['fecha']->format('d/m H:i'), $this->textoDuracion($minutos)
            );
            $litros = 0.0;
            $reportarSinPerdida = $this->cfg['corte_reportar_sin_perdida'] ?? false;

            // Sin pérdida (o sin lecturas para comparar), por defecto no lo
            // reporto: es el corta-corriente de todos los días.
            $hayPerdida = $antes !== null && $despues !== null
                && ($antes['combustible_litros'] - $despues['combustible_litros']) >= $litrosSospechosos;
            if (!$hayPerdida && !$reportarSinPerdida) {
                continue;
            }

            if ($antes !== null && $despues !== null) {
                $diferencia = $antes['combustible_litros'] - $despues['combustible_litros'];
                if ($diferencia >= $litrosSospechosos) {
                    $litros = $diferencia;
                    $texto .= sprintf(
                        ' Antes del corte tenía %.1f L y al volver %.1f L: faltan %.1f L. Posible robo — es el patrón típico de cortar la alimentación para que no se registre la descarga.',
                        $antes['combustible_litros'], $despues['combustible_litros'], $diferencia
                    );
                } else {
                    $texto .= sprintf(
                        ' El nivel antes (%.1f L) y después (%.1f L) no muestra una pérdida significativa.',
                        $antes['combustible_litros'], $despues['combustible_litros']
                    );
                }
            } else {
                $texto .= ' No hay lecturas de combustible cerca del corte para comparar el nivel.';
            }

            $anomalias[] = $this->construir(
                $off['equipo'], $off['fecha'], 'corte_alimentacion', $texto,
                $litros, null, $off['lat'], $off['lng']
            );
        }

        return $anomalias;
    }

    /**
     * Temperatura / nivel — el punto 7 que nos pidió Emiliano.
     *
     * La idea: el gasoil se dilata con el calor, así que es normal que el
     * nivel suba un poco al mediodía y baje de noche con el vehículo
     * quieto. Lo que busco es el caso en que el nivel acompaña a la
     * temperatura MUCHO más de lo que explica esa dilatación. Cuando pasa
     * eso, el sensor de nivel está afectado por la temperatura (mal
     * calibrado, mal instalado, sonda dañada) y cualquier cálculo de
     * consumo o pérdida que salga de ese equipo hay que mirarlo con
     * cuidado.
     *
     * Pasos:
     *  1. Me quedo solo con lecturas del vehículo QUIETO (velocidad 0), con
     *     combustible y temperatura válidos. Andando, el nivel baja por
     *     consumo y eso ensucia la comparación.
     *  2. Si no tengo suficientes lecturas, o la temperatura casi no se
     *     movió, no hay nada que cruzar y salgo.
     *  3. Calculo la correlación entre temperatura y nivel (qué tan juntas
     *     se mueven las dos curvas, de -1 a 1) y la pendiente (cuántos
     *     litros cambia el nivel por cada grado).
     *  4. Comparo lo que varió el nivel "por culpa de la temperatura"
     *     (pendiente × rango de temperatura) contra lo que debería variar
     *     por dilatación (nivel promedio × coeficiente × rango).
     *  5. Si la correlación es alta Y la variación supera varias veces la
     *     esperada, lo marco.
     *
     * Para no repetir la misma anomalía en cada corrida del cron, la fecha
     * de la anomalía es la medianoche del día de la última lectura: como
     * mucho una por equipo por día.
     */
    private function deteccionTemperaturaNivel(array $lecturas): array
    {
        $minLecturas   = $this->cfg['temp_min_lecturas'] ?? 30;
        $rangoMinimo   = $this->cfg['temp_rango_minimo_grados'] ?? 5.0;
        $corrMinima    = $this->cfg['temp_correlacion_minima'] ?? 0.8;
        $factorExceso  = $this->cfg['temp_factor_exceso'] ?? 3.0;
        $variacionMin  = $this->cfg['temp_variacion_minima_litros'] ?? 5.0;
        $coefDilatacion = $this->cfg['temp_coef_dilatacion'] ?? 0.00083;

        // 1. Solo quieto, con los dos datos válidos.
        $velMax = $this->cfg['detenido_velocidad_max_kmh'] ?? 1.0;
        $puntos = [];
        foreach ($lecturas as $l) {
            if ((float) $l['velocidad'] > $velMax) {
                continue;
            }
            if (($l['temp_tanque'] ?? null) === null || $l['combustible_litros'] === null) {
                continue;
            }
            $puntos[] = $l;
        }

        // 2. ¿Hay con qué trabajar?
        $n = count($puntos);
        if ($n < $minLecturas) {
            return [];
        }
        $temps = array_map(fn($p) => (float) $p['temp_tanque'], $puntos);
        $niveles = array_map(fn($p) => (float) $p['combustible_litros'], $puntos);
        $rangoTemp = max($temps) - min($temps);
        if ($rangoTemp < $rangoMinimo) {
            return [];
        }

        // 3. Correlación y pendiente (regresión lineal simple, nada raro).
        $mediaT = array_sum($temps) / $n;
        $mediaN = array_sum($niveles) / $n;
        $cov = 0.0;
        $varT = 0.0;
        $varN = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dt = $temps[$i] - $mediaT;
            $dn = $niveles[$i] - $mediaN;
            $cov += $dt * $dn;
            $varT += $dt * $dt;
            $varN += $dn * $dn;
        }
        if ($varT == 0.0 || $varN == 0.0) {
            return []; // una de las dos curvas es plana, no hay correlación posible
        }
        $correlacion = $cov / sqrt($varT * $varN);
        $pendiente = $cov / $varT; // litros por grado

        // 4. Lo que varió por temperatura vs. lo que explica la dilatación.
        $variacionObservada = abs($pendiente) * $rangoTemp;
        $variacionEsperada = $mediaN * $coefDilatacion * $rangoTemp;

        // 5. ¿Es para avisar?
        if (abs($correlacion) < $corrMinima) {
            return [];
        }
        if ($variacionObservada < $variacionMin) {
            return [];
        }
        if ($variacionObservada < $factorExceso * $variacionEsperada) {
            return []; // se explica por dilatación normal del gasoil, no hay problema
        }

        $ultima = end($puntos);
        $fecha = new DateTimeImmutable($ultima['fecha']->format('Y-m-d') . ' 00:00:00', $ultima['fecha']->getTimezone());

        return [$this->construir(
            $ultima['equipo'], $fecha, 'variacion_temperatura',
            sprintf(
                'El nivel medido acompaña a la temperatura del tanque (correlación %.2f): varía unos %.1f L en un rango de %.1f °C, cuando por dilatación del gasoil se esperarían como mucho %.1f L. Probable sensor de nivel afectado por la temperatura — revisar calibración/instalación antes de confiar en sus lecturas.',
                $correlacion, $variacionObservada, $rangoTemp, $variacionEsperada
            ),
            0.0, $correlacion, null, null
        )];
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
        $maxHueco = $this->cfg['max_gap_minutos'] ?? 30;
        $velMaxRalenti = $this->cfg['detenido_velocidad_max_kmh'] ?? 1.0;
        $anterior = null;

        foreach ($lecturas as $l) {
            // Si entre esta lectura y la anterior hay un hueco sin datos, el
            // bloque de ralentí en curso termina ACÁ: no sé qué pasó en el
            // medio. Sin esto aparecían ralentís de "3.868 minutos" que en
            // realidad eran dos días sin datos del equipo (30/09/2026).
            if ($anterior !== null && $inicioBloque !== null
                && ($l['fecha']->getTimestamp() - $anterior['fecha']->getTimestamp()) / 60 > $maxHueco) {
                $anomalia = $this->evaluarBloqueRalenti($anterior['equipo'] ?? null, $inicioBloque, $finBloque, $combustibleInicio, $combustibleUltimo, $minMinutos, $minLitros);
                if ($anomalia !== null) {
                    $anomalias[] = $anomalia;
                }
                $inicioBloque = null;
                $finBloque = null;
                $combustibleInicio = null;
                $combustibleUltimo = null;
            }
            $anterior = $l;

            // Detenido = velocidad hasta el umbral de "detenido" (1 km/h), no
            // exactamente 0: el GPS de un camión parado marca 0,1-0,3 km/h todo
            // el tiempo, y con eso cada ralentí se cortaba en pedacitos.
            $enRalenti = $l['motor_encendido'] === true && (float) $l['velocidad'] <= $velMaxRalenti;

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
                $minutos,
                // Si cruza la medianoche muestro el día, si no queda confuso ("de 23:40 a 00:20").
                $inicio->format('Y-m-d') === $fin->format('Y-m-d') ? $inicio->format('H:i') : $inicio->format('d/m H:i'),
                $inicio->format('Y-m-d') === $fin->format('Y-m-d') ? $fin->format('H:i') : $fin->format('d/m H:i'),
                $litrosConsumidos
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

        // Momentos de corte/vuelta de alimentación. Si entre dos lecturas
        // hubo uno, esa caída la analiza deteccionCortesAlimentacion() —
        // acá la salteo para no reportar lo mismo dos veces.
        $eventosAlimentacion = [];
        foreach ($lecturas as $l) {
            if (in_array($l['evento'] ?? null, ['POWOFF', 'POWON'], true)) {
                $eventosAlimentacion[] = $l['fecha'];
            }
        }

        for ($i = 1; $i < count($lecturasConCombustible); $i++) {
            $prev = $lecturasConCombustible[$i - 1];
            $cur = $lecturasConCombustible[$i];

            $cruzaCorte = false;
            foreach ($eventosAlimentacion as $f) {
                if ($f > $prev['fecha'] && $f <= $cur['fecha']) {
                    $cruzaCorte = true;
                    break;
                }
            }
            if ($cruzaCorte) {
                continue;
            }

            $litrosPerdidos = $prev['combustible_litros'] - $cur['combustible_litros'];
            if ($litrosPerdidos <= 0) {
                continue; // no hubo caída
            }

            $minutos = ($cur['fecha']->getTimestamp() - $prev['fecha']->getTimestamp()) / 60;
            if ($minutos <= 0) {
                continue;
            }

            // Si entre las dos lecturas hay un hueco grande, no comparo:
            // no sé qué pasó en el medio (ver 'max_gap_minutos' en config).
            if ($minutos > ($this->cfg['max_gap_minutos'] ?? 30)) {
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
                'detenido'             => $this->estabaDetenido($prev, $cur),
                'variacion_detectada'  => $variacionDetectada,
                'lat'                  => $cur['lat'],
                'lng'                  => $cur['lng'],
            ];
        }

        return $confirmadas;
    }

    /**
     * Descargas lentas con el vehículo detenido (14/10/2026).
     *
     * Recorro las lecturas con combustible y armo "tramos de bajada": una
     * serie de lecturas seguidas, con el vehículo quieto, en la que el nivel
     * va bajando. El tramo se corta si:
     *   - el vehículo se movió, o hubo un hueco sin datos, o un corte de
     *     alimentación (eso lo analiza deteccionCortesAlimentacion);
     *   - el nivel volvió a subir más de 'carga_tolerancia_meseta_litros'
     *     (ruido normal del sensor, 3 L);
     *   - pasaron más de 15 minutos sin que siga bajando (una pérdida de 1 L
     *     cada tanto por el frío de la noche no arma un tramo).
     * Lo reporto si en total perdió al menos 'stopped_drop_liters_threshold'
     * en DOS o más bajadas (una sola bajada grande ya la agarra la regla
     * lectura contra lectura), a un ritmo físicamente posible, y si después
     * del tramo el nivel no se recuperó (misma ventana y fracción que las
     * demás caídas). Solo tramos ya cerrados y con la ventana vencida, así el
     * cron no reporta el mismo tramo dos veces con distinto final.
     */
    private function tramosDescargaDetenido(array $lecturas, DateTimeInterface $ahora): array
    {
        $umbral = (float) ($this->cfg['stopped_drop_liters_threshold'] ?? 15.0);
        $tolerancia = (float) ($this->cfg['carga_tolerancia_meseta_litros'] ?? 3.0);
        $maxHueco = (float) ($this->cfg['max_gap_minutos'] ?? 30);
        $tasaMaxima = (float) ($this->cfg['tasa_maxima_fisica_lpm'] ?? 150.0);
        $ventanaMin = (int) ($this->cfg['recovery_window_minutes'] ?? 20);
        $fraccion = (float) ($this->cfg['recovery_min_fraction'] ?? 0.7);
        $maxSinBajar = 15 * 60;

        $con = array_values(array_filter($lecturas, fn($l) => $l['combustible_litros'] !== null));
        $cortes = [];
        foreach ($lecturas as $l) {
            if (in_array($l['evento'] ?? null, ['POWOFF', 'POWON'], true)) {
                $cortes[] = $l['fecha'];
            }
        }

        $tramos = [];
        $t = null; // tramo en curso
        $cerrar = function () use (&$t, &$tramos, $umbral) {
            if ($t !== null && $t['pasos'] >= 2 && ($t['inicioNivel'] - $t['minNivel']) >= $umbral) {
                $tramos[] = $t;
            }
            $t = null;
        };

        $n = count($con);
        for ($i = 1; $i < $n; $i++) {
            $prev = $con[$i - 1];
            $cur = $con[$i];
            $seg = $cur['fecha']->getTimestamp() - $prev['fecha']->getTimestamp();
            $cruzaCorte = false;
            foreach ($cortes as $f) {
                if ($f > $prev['fecha'] && $f <= $cur['fecha']) {
                    $cruzaCorte = true;
                    break;
                }
            }
            // Con el motor prendido el nivel baja por consumo (eso es ralentí, otra
            // regla): acá solo cuento con el motor apagado.
            $valido = $seg > 0 && $seg / 60 <= $maxHueco && !$cruzaCorte && $this->estabaDetenido($prev, $cur)
                && empty($prev['motor_encendido']) && empty($cur['motor_encendido']);
            if (!$valido) {
                $cerrar();
                continue;
            }
            $delta = $prev['combustible_litros'] - $cur['combustible_litros']; // > 0 = bajó
            if ($delta > 0 && $delta / ($seg / 60) > $tasaMaxima) {
                // Salto imposible para una bomba: es el sensor (desconexión),
                // lo informa la regla de siempre. No lo mezclo en un tramo.
                $cerrar();
                continue;
            }
            if ($t === null) {
                if ($delta >= 1.0) {
                    $t = [
                        'inicio' => $prev['fecha'], 'inicioNivel' => $prev['combustible_litros'],
                        'fin' => $cur['fecha'], 'minNivel' => $cur['combustible_litros'],
                        'ultimaBajada' => $cur['fecha'], 'pasos' => 1,
                        'lat' => $cur['lat'], 'lng' => $cur['lng'],
                    ];
                }
                continue;
            }
            if ($cur['combustible_litros'] > $t['minNivel'] + $tolerancia) {
                $cerrar(); // volvió a subir: se terminó (o era ruido)
                continue;
            }
            if ($cur['combustible_litros'] < $t['minNivel'] - 0.5) {
                $t['minNivel'] = $cur['combustible_litros'];
                $t['fin'] = $cur['fecha'];
                $t['ultimaBajada'] = $cur['fecha'];
                $t['pasos']++;
                $t['lat'] = $cur['lat'] ?? $t['lat'];
                $t['lng'] = $cur['lng'] ?? $t['lng'];
            } elseif ($cur['fecha']->getTimestamp() - $t['ultimaBajada']->getTimestamp() > $maxSinBajar) {
                $cerrar(); // dejó de bajar: el tramo terminó en la última bajada
            }
        }
        // Un tramo que sigue abierto al final de los datos todavía no terminó:
        // lo veo en la próxima corrida.

        $out = [];
        foreach ($tramos as $tr) {
            $litros = $tr['inicioNivel'] - $tr['minNivel'];
            $minutos = ($tr['fin']->getTimestamp() - $tr['inicio']->getTimestamp()) / 60;
            if ($minutos <= 0 || $litros / $minutos > $tasaMaxima) {
                continue;
            }
            $finVentana = $tr['fin']->modify("+{$ventanaMin} minutes");
            if ($finVentana > $ahora) {
                continue; // todavía no sé si se recupera
            }
            $mejor = $tr['minNivel'];
            foreach ($con as $l) {
                if ($l['fecha'] > $tr['fin'] && $l['fecha'] <= $finVentana) {
                    $mejor = max($mejor, $l['combustible_litros']);
                }
            }
            if (($mejor - $tr['minNivel']) / $litros >= $fraccion) {
                continue; // se recuperó: pendiente o ruido
            }
            $out[] = ['inicio' => $tr['inicio'], 'fin' => $tr['fin'], 'litros' => $litros, 'minutos' => $minutos,
                      'pasos' => $tr['pasos'], 'lat' => $tr['lat'], 'lng' => $tr['lng']];
        }
        return $out;
    }

    /** "14:05", o "13/10 23:50" si el otro extremo es de otro día. */
    private function horaCorta(DateTimeInterface $f, DateTimeInterface $otra): string
    {
        return $f->format('Y-m-d') === $otra->format('Y-m-d') ? $f->format('H:i') : $f->format('d/m H:i');
    }

    /**
     * ¿El vehículo estaba realmente quieto entre estas dos lecturas?
     *
     * Antes usaba solo el campo "movimiento" de la API, pero no es
     * confiable: en el JSON real hay lecturas con movimiento=0 y el camión
     * a 9 km/h. Resultado: el consumo normal andando (sobre todo subiendo
     * la cordillera) salía como "descarga con vehículo detenido".
     *
     * Ahora pido las dos cosas: velocidad casi cero en ambas lecturas, y
     * que entre una y otra no se haya movido más de unos metros (si hay
     * GPS en las dos). Si no hay GPS, me quedo con la velocidad.
     */
    private function estabaDetenido(array $prev, array $cur): bool
    {
        $velMax = $this->cfg['detenido_velocidad_max_kmh'] ?? 1.0;
        if ((float) $prev['velocidad'] > $velMax || (float) $cur['velocidad'] > $velMax) {
            return false;
        }

        $distMax = $this->cfg['detenido_distancia_max_metros'] ?? 200;
        if ($prev['lat'] !== null && $prev['lng'] !== null && $cur['lat'] !== null && $cur['lng'] !== null) {
            if ($this->distanciaMetros($prev['lat'], $prev['lng'], $cur['lat'], $cur['lng']) > $distMax) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Este equipo es de los que no se mueven (tanque fijo, pozo, máquina,
     * generador)? Miro primero el "tipo" que viene de la API de equipos
     * de TTM y, si no dice nada, el nombre. Alcanza con que contenga
     * alguna de las palabras de config ('palabras_equipo_estacionario').
     * Si el equipo no está en la base, lo trato como vehículo (lo normal).
     */
    private function esEquipoEstacionario(string $equipo): bool
    {
        $info = $this->storage->equipoPorId($equipo);
        if (!$info) {
            return false;
        }
        $texto = mb_strtolower(($info['tipo'] ?? '') . ' ' . ($info['nombre'] ?? ''), 'UTF-8');
        foreach ($this->cfg['palabras_equipo_estacionario'] ?? [] as $palabra) {
            if ($palabra !== '' && strpos($texto, mb_strtolower($palabra, 'UTF-8')) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Percentil de una lista (0.20 = el valor que deja al 20% por debajo). Robusto a picos sueltos. */
    private function percentil(array $valores, float $p): float
    {
        $v = array_values($valores);
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return 0.0;
        }
        $pos = ($n - 1) * $p;
        $bajo = (int) floor($pos);
        $alto = (int) ceil($pos);
        return $v[$bajo] + ($v[$alto] - $v[$bajo]) * ($pos - $bajo);
    }

    /** Mediana de una lista de números (la uso para las mesetas de las cargas). */
    private function mediana(array $valores): float
    {
        $v = array_values($valores);
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return 0.0;
        }
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /** "menos de 1 minuto", "3 minutos", etc. — para que el detalle se lea bien. */
    private function textoDuracion(float $minutos): string
    {
        if ($minutos < 1) {
            return 'menos de 1 minuto';
        }
        return sprintf('%.0f minuto%s', $minutos, round($minutos) == 1 ? '' : 's');
    }

    /** Distancia entre dos puntos GPS en metros (fórmula de haversine, la de siempre). */
    private function distanciaMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
