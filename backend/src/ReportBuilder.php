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

    public function construir(array $lecturas, array $anomalias): array
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

        $litrosAnomalos = array_sum(array_column($anomalias, 'litros_perdidos'));

        $serieCombustible = [];
        $serieVelocidad = [];
        $seriesTanques = [1 => [], 2 => [], 3 => [], 4 => []];
        $tanquesDetectados = [];
        $puntosMapa = [];

        foreach ($lecturas as $l) {
            $serieCombustible[] = [
                'x' => $l['fecha']->format(DateTimeInterface::ATOM),
                'y' => $l['combustible_litros'] !== null ? round($l['combustible_litros'], 1) : null,
            ];
            $serieVelocidad[] = [
                'x' => $l['fecha']->format(DateTimeInterface::ATOM),
                'y' => round($l['velocidad'], 1),
            ];

            foreach ($l['tanques'] ?? [] as $num => $valor) {
                if ($valor !== null) {
                    $seriesTanques[$num][] = ['x' => $l['fecha']->format(DateTimeInterface::ATOM), 'y' => round($valor, 1)];
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

        // ⚠️ Litros consumidos: comparamos lecturas de combustible VÁLIDAS
        // consecutivas (saltando los TIEMPO de en medio, mismo criterio que
        // AnomalyDetector — si no, un TIEMPO metido en el medio hace perder
        // el rastro de un tramo entero). Además, cualquier caída menor a
        // RUIDO_MINIMO_LITROS se ignora: es vibración/chapoteo del sensor,
        // no consumo real. Sin este piso, miles de lecturas por semana con
        // ruido de +/-uno o dos litros inflan el total a números sin sentido.
        $litrosConsumidosTotal = 0.0;
        $lecturasConCombustible = array_values(array_filter($lecturas, fn($l) => $l['combustible_litros'] !== null));
        for ($i = 1; $i < count($lecturasConCombustible); $i++) {
            $delta = $lecturasConCombustible[$i - 1]['combustible_litros'] - $lecturasConCombustible[$i]['combustible_litros'];
            if ($delta >= self::RUIDO_MINIMO_LITROS) {
                $litrosConsumidosTotal += $delta;
            }
        }

        $consumoPromedioL100km = null;
        if ($kmRecorridos && $kmRecorridos > 0 && $litrosConsumidosTotal > 0) {
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
