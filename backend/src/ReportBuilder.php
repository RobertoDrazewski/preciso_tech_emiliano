<?php

/**
 * Arma los datos agregados que consume la API (public/api/informe.php):
 * resumen numérico, series para los gráficos, puntos para el mapa y listado
 * de anomalías del período.
 *
 * Todo en litros absolutos — no hay concepto de "% de tanque" (confirmado
 * por Emiliano 27/07/2026).
 */
class ReportBuilder
{
    /**
     * Caídas de combustible por debajo de este valor entre dos lecturas
     * consecutivas se consideran ruido de sensor (vibración, chapoteo del
     * líquido) y NO se suman como "consumo real". Sin este piso, con miles
     * de lecturas por semana, el ruido acumulado infla el total a números
     * absurdos (lo vimos: 46.000 L en una semana). Ajustar si hace falta
     * una vez que tengamos más semanas de datos reales para calibrar.
     */
    private const RUIDO_MINIMO_LITROS = 1.0;

    public function construir(array $lecturas, array $anomalias, array $anomalyConfig = []): array
    {
        $n = count($lecturas);

        $kmRecorridos = null;
        if ($n >= 2) {
            $primerOdo = $this->primerOdometroValido($lecturas);
            $ultimoOdo = $this->ultimoOdometroValido($lecturas);
            if ($primerOdo !== null && $ultimoOdo !== null && $ultimoOdo >= $primerOdo) {
                $kmRecorridos = round($ultimoOdo - $primerOdo, 1);
            }
        }

        // Solo los tipos que son pérdida real (ver Storage::TIPOS_MERMA): el
        // ralentí y el consumo anómalo no son combustible perdido.
        $litrosAnomalos = array_sum(array_map(
            fn($a) => in_array($a['tipo'], Storage::TIPOS_MERMA, true) ? (float) $a['litros_perdidos'] : 0.0,
            $anomalias
        ));

        // ⚠️ Para el GRÁFICO (no para el cálculo de consumo, que ya tiene su
        // propio filtro de rebote más preciso — ver calcularConsumoReal):
        // detectamos picos aislados de ruido de sensor y los sacamos de lo
        // que se dibuja. Un pico aislado es una lectura que cae marcadamente
        // por debajo de sus dos vecinos válidos (el anterior y el
        // siguiente) — típico de un salto de sensor que se recupera
        // enseguida. Un tramo de consumo real, en cambio, involucra VARIAS
        // lecturas moviéndose juntas, no un solo punto rodeado de valores
        // mucho más altos de los dos lados.
        $puntosRuido = $this->identificarPicosAislados($lecturas);

        $serieCombustible = [];
        $serieVelocidad = [];
        $seriesTanques = [1 => [], 2 => [], 3 => [], 4 => []];
        $tanquesDetectados = [];
        $puntosMapa = [];

        foreach ($lecturas as $l) {
            $claveTiempo = $l['fecha']->format(DateTimeInterface::ATOM);
            $esRuido = isset($puntosRuido[$claveTiempo]);
            $serieCombustible[] = [
                'x' => $claveTiempo,
                'y' => ($l['combustible_litros'] !== null && !$esRuido) ? round($l['combustible_litros'], 1) : null,
            ];
            $serieVelocidad[] = [
                'x' => $claveTiempo,
                'y' => round($l['velocidad'], 1),
            ];

            foreach ($l['tanques'] ?? [] as $num => $valor) {
                if ($valor !== null) {
                    $seriesTanques[$num][] = ['x' => $claveTiempo, 'y' => round($valor, 1)];
                    $tanquesDetectados[$num] = true;
                }
            }

            if ($l['lat'] !== null && $l['lng'] !== null) {
                $puntosMapa[] = ['lat' => $l['lat'], 'lng' => $l['lng']];
            }
        }

        // ⚠️ Filtro de outliers de GPS: un dispositivo que recién arranca a
        // veces manda una posición inválida (0,0 o cualquier cosa) antes de
        // "engancharse" bien a los satélites. Sin filtrar esto, una sola
        // lectura mala hace que el mapa dibuje una línea recta cruzando el
        // océano hasta el otro lado del mundo. Descartamos los puntos que
        // estén demasiado lejos de la mediana del resto — para una flota
        // que opera en una zona (ej. Mendoza), cualquier punto real debería
        // estar razonablemente cerca de los demás.
        $puntosMapa = $this->filtrarOutliersGps($puntosMapa);

        // Litros consumidos por balance (ver calcularConsumoReal).
        $litrosConsumidosTotal = $this->calcularConsumoReal($lecturas, $anomalyConfig, $litrosAnomalos);

        // Consumo cada 100 km: solo si el vehículo anduvo algo. Con muy
        // pocos km (un camión parado toda la semana, en ralentí) la cuenta
        // da números absurdos — vimos "6.894 L/100km" con 1,9 km. Por
        // debajo de este mínimo, no lo muestro.
        $kmMinimoParaPromedio = 20;
        $consumoPromedioL100km = null;
        if ($kmRecorridos && $kmRecorridos >= $kmMinimoParaPromedio && $litrosConsumidosTotal > 0) {
            $consumoPromedioL100km = round(($litrosConsumidosTotal / $kmRecorridos) * 100, 1);
        }

        // Solo mandamos al frontend los tanques que realmente tuvieron datos
        // válidos en el período (los que la mayoría del tiempo vienen en
        // 65535/0 ni se mencionan).
        $seriesTanquesFiltradas = [];
        foreach ($tanquesDetectados as $num => $_) {
            $seriesTanquesFiltradas[$num] = $seriesTanques[$num];
        }

        return [
            'cantidad_lecturas'        => $n,
            'km_recorridos'            => $kmRecorridos,
            'litros_consumidos_total'  => round($litrosConsumidosTotal, 1),
            'litros_perdidos_anomalos' => round($litrosAnomalos, 1),
            'consumo_l_100km'          => $consumoPromedioL100km,
            'cantidad_anomalias'       => count($anomalias),
            'anomalias'                => $anomalias,
            'serie_combustible'        => $serieCombustible,
            'serie_velocidad'          => $serieVelocidad,
            'series_tanques'           => $seriesTanquesFiltradas,
            'puntos_mapa'              => $puntosMapa,
        ];
    }

    /**
     * Para el gráfico: identifica lecturas que son un pico aislado de
     * ruido de sensor — caen marcadamente por debajo de sus dos vecinos
     * válidos más cercanos (anterior y siguiente), lo que indica un salto
     * puntual que no es parte de una tendencia real. No confundir con el
     * filtro de consumo (calcularConsumoReal): este es más simple a
     * propósito, solo para limpiar lo que se DIBUJA — el número de
     * consumo ya está protegido por su propia lógica, más estricta.
     *
     * @return array<string,true> mapa de timestamp (ISO) -> true para las lecturas a ocultar
     */
    private function identificarPicosAislados(array $lecturas): array
    {
        $conCombustible = array_values(array_filter($lecturas, fn($l) => $l['combustible_litros'] !== null));
        $n = count($conCombustible);
        $ruido = [];

        for ($i = 1; $i < $n - 1; $i++) {
            $anterior = $conCombustible[$i - 1]['combustible_litros'];
            $actual = $conCombustible[$i]['combustible_litros'];
            $siguiente = $conCombustible[$i + 1]['combustible_litros'];

            $vecinoMasBajo = min($anterior, $siguiente);
            if ($actual < $vecinoMasBajo - self::RUIDO_MINIMO_LITROS) {
                $clave = $conCombustible[$i]['fecha']->format(DateTimeInterface::ATOM);
                $ruido[$clave] = true;
            }
        }

        return $ruido;
    }

    /**
     * Combustible consumido en el período, por BALANCE (18/11/2026):
     *
     *   consumido = nivel al inicio − nivel al final + cargas − pérdidas
     *
     * Antes sumaba cada bajada entre lecturas y descontaba las que se
     * recuperaban en 20 min. Con el chapoteo de un camión andando las
     * bajadas que se recuperaban más tarde se sumaban igual, y las
     * pérdidas también entraban como consumo: AE553QC daba 443 L y
     * 90,9 L/100 km en una semana en la que el balance da ~176 L
     * (~36 L/100 km, en línea con lo que muestra TTM).
     *
     * - Inicio y final: mediana de las primeras/últimas 5 lecturas, para
     *   que un salto del sensor justo en el borde no mueva la cuenta.
     * - Cargas: subidas sostenidas de al menos CARGA_MINIMA_LITROS (se
     *   toleran bajaditas de ruido de hasta 3 L en el medio).
     * - Pérdidas: las anomalías de merma del período (robos, caídas), que
     *   no son consumo del motor.
     */
    private const CARGA_MINIMA_LITROS = 20.0;

    private function calcularConsumoReal(array $lecturas, array $anomalyConfig, float $litrosPerdidos = 0.0): float
    {
        $niveles = [];
        foreach ($lecturas as $l) {
            if ($l['combustible_litros'] !== null) {
                $niveles[] = (float) $l['combustible_litros'];
            }
        }
        $n = count($niveles);
        if ($n < 2) {
            return 0.0;
        }

        $mediana = function (array $v): float {
            sort($v);
            $c = count($v);
            return $c % 2 ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
        };
        $inicio = $mediana(array_slice($niveles, 0, min(5, $n)));
        $fin = $mediana(array_slice($niveles, -min(5, $n)));

        // Cargas: recorro las subidas. Un tramo de subida arranca en un piso
        // y termina cuando el nivel baja más de 3 L desde el techo alcanzado.
        $cargas = 0.0;
        $piso = $niveles[0];
        $techo = $niveles[0];
        for ($i = 1; $i < $n; $i++) {
            $v = $niveles[$i];
            if ($v >= $techo) {
                $techo = $v;
            } elseif ($techo - $v > 3.0) {
                if ($techo - $piso >= self::CARGA_MINIMA_LITROS) {
                    $cargas += $techo - $piso;
                }
                $piso = $v;
                $techo = $v;
            }
            if ($v < $piso) {
                $piso = $v;
                $techo = $v;
            }
        }
        if ($techo - $piso >= self::CARGA_MINIMA_LITROS) {
            $cargas += $techo - $piso;
        }

        return max(0.0, $inicio - $fin + $cargas - $litrosPerdidos);
    }

    /**
     * Descarta puntos GPS demasiado lejos del resto. En vez de un número
     * fijo adivinado, calcula qué tan dispersa está la flota (distancia
     * típica de cada punto a la mediana) y usa un múltiplo generoso de eso
     * como umbral — así se adapta solo tanto a una flota que opera bien
     * local (radio chico) como a una de larga distancia (radio grande),
     * sin dejar pasar un salto de cientos de km por un dato de arranque de
     * GPS inválido.
     */
    private function filtrarOutliersGps(array $puntos): array
    {
        $n = count($puntos);
        if ($n < 3) {
            return $puntos; // muy pocos puntos como para calcular mediana con sentido
        }

        $lats = array_column($puntos, 'lat');
        $lngs = array_column($puntos, 'lng');
        sort($lats);
        sort($lngs);
        $medianaLat = $lats[intdiv($n, 2)];
        $medianaLng = $lngs[intdiv($n, 2)];

        $distancias = array_map(
            fn($p) => sqrt(($p['lat'] - $medianaLat) ** 2 + ($p['lng'] - $medianaLng) ** 2),
            $puntos
        );
        sort($distancias);
        $distanciaTipica = $distancias[intdiv($n, 2)]; // mediana de las distancias

        // Umbral adaptativo: 6 veces la dispersión típica, con un piso de
        // ~15km (para no ser demasiado estricto si casi todos los puntos
        // están prácticamente en el mismo lugar) y un techo de ~150km (para
        // no dejar pasar nunca un salto grande, aunque la flota sea
        // dispersa — ese caso se soluciona subiendo el techo a mano acá si
        // hace falta, no adivinando de nuevo).
        $umbral = min(1.5, max(0.15, $distanciaTipica * 6));

        return array_values(array_filter($puntos, function ($p) use ($medianaLat, $medianaLng, $umbral) {
            $distancia = sqrt(($p['lat'] - $medianaLat) ** 2 + ($p['lng'] - $medianaLng) ** 2);
            return $distancia <= $umbral;
        }));
    }

    private function primerOdometroValido(array $lecturas): ?float
    {
        foreach ($lecturas as $l) {
            if ($l['odometro_km'] !== null) {
                return $l['odometro_km'];
            }
        }
        return null;
    }

    private function ultimoOdometroValido(array $lecturas): ?float
    {
        for ($i = count($lecturas) - 1; $i >= 0; $i--) {
            if ($lecturas[$i]['odometro_km'] !== null) {
                return $lecturas[$i]['odometro_km'];
            }
        }
        return null;
    }
}
