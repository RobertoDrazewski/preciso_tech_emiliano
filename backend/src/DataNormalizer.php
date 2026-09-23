<?php

/**
 * Traduce el JSON crudo de la API a un arreglo interno estable que el resto
 * del sistema usa siempre igual, sin importar cómo venga la API real.
 *
 * Así, si el día de mañana Emiliano cambia de proveedor de GPS, sólo hay que
 * tocar config/field_map.php — nada más del sistema se entera del cambio.
 *
 * Todo en litros absolutos (no hay capacidad de tanque, confirmado por
 * Emiliano 27/07/2026).
 */
class DataNormalizer
{
    private array $fieldMap;

    // Techo genérico cuando no sabemos la capacidad real del tanque de ese
    // equipo puntual. Bajado de 5000L a 1500L el 14/09/2026: el techo viejo
    // era demasiado permisivo para un camión común (que normalmente no
    // supera los 800-1200L, ni sumando varios tanques) — dejaba pasar
    // ruido de sensor erático que después inflaba el consumo calculado a
    // números absurdos (caso real: equipo con tanque de 873L, sin la
    // capacidad cargada, mostró "102.672 L consumidos" en una semana,
    // contra los 254L reales que mostraba el sistema de origen para el
    // mismo período). Los tanques grandes de verdad (tanques fijos
    // estacionarios) sí suelen traer su capacidad real cargada en la API
    // de equipos, así que caen en la otra rama (usan su capacidad real,
    // no este techo genérico) — bajarlo no les afecta.
    private const TECHO_GENERICO = 1500.0;

    public function __construct(array $fieldMap)
    {
        $this->fieldMap = $fieldMap;
    }

    /**
     * @return array<int, array{equipo:string, fecha:DateTimeImmutable, evento:?string,
     *   lat:?float, lng:?float, velocidad:float, combustible_litros:?float,
     *   tanques:array<int,?float>, variacion:?float, odometro_km:?float, motor_encendido:bool}>
     */
    /**
     * @param float|null $capacidadMaxima Techo de sensatez para el nivel de
     *   combustible, en litros. Si se conoce la capacidad real del tanque
     *   (viene de la API de equipos de Emiliano), usar esa. Si no se pasa
     *   nada, se usa un techo genérico bien generoso (self::TECHO_GENERICO)
     *   para no perder datos reales de tanques grandes que no tenemos
     *   registrados.
     *
     *   Por qué hace falta esto: confirmado por Emiliano (26/08/2026) — el
     *   dispositivo a veces manda valores basura durante una pérdida y
     *   reconexión de alimentación. Sin este techo, un valor así (por
     *   ejemplo, un salto a 90000L) se toma como lectura real: infla el
     *   consumo calculado a números absurdos, rompe la escala de los
     *   gráficos, y genera anomalías falsas de "descarga" tanto al saltar
     *   para arriba como al volver a un valor normal. Rechazarlo en el
     *   normalizador protege TODO lo que viene después (gráficos, consumo,
     *   detección de anomalías) de una sola vez, en vez de tener que
     *   filtrarlo en cada lugar por separado.
     */
    public function normalize(array $rawResponse, string $equipoIdFallback, ?float $capacidadMaxima = null): array
    {
        $techo = $capacidadMaxima !== null && $capacidadMaxima > 0 ? $capacidadMaxima : self::TECHO_GENERICO;
        $rootKey = $this->fieldMap['root_key'] ?? null;
        $records = $rootKey !== null && isset($rawResponse[$rootKey])
            ? $rawResponse[$rootKey]
            : $rawResponse;

        if (!is_array($records)) {
            return [];
        }

        // Si vino un solo objeto en vez de una lista, lo envolvemos.
        if (self::isAssoc($records)) {
            $records = [$records];
        }

        $out = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }

            $equipo = $this->extract($rec, 'equipo') ?? $equipoIdFallback;
            $fechaRaw = $this->extract($rec, 'fecha');
            $fecha = $this->parseFecha($fechaRaw);
            if ($fecha === null) {
                continue; // sin fecha no podemos ordenar/comparar, se descarta
            }

            $evento = $this->extract($rec, 'evento');

            $litros = $this->extractFloat($rec, 'combustible_litros');
            if ($litros !== null && ($litros < 0 || $litros > $techo)) {
                $litros = null; // valor imposible — se descarta como si no hubiera venido, no se inventa un número corregido
            }
            $odometro = $this->extractFloat($rec, 'odometro_km');

            // Máscara por tipo de evento: en esta API, cada tipo de evento
            // trae SOLO una parte de los campos con datos reales; el resto
            // viene en 0 (no ausente, CERO), lo que generaría anomalías
            // falsas si se tomara como una lectura real. Ver comentario en
            // config/field_map.php para el detalle.
            $tanques = [
                1 => $this->extractTanque($rec, 'tanque1_litros'),
                2 => $this->extractTanque($rec, 'tanque2_litros'),
                3 => $this->extractTanque($rec, 'tanque3_litros'),
                4 => $this->extractTanque($rec, 'tanque4_litros'),
            ];

            if ($evento === 'TIEMPO') {
                $litros = null;
                $tanques = [1 => null, 2 => null, 3 => null, 4 => null];
            } elseif ($evento === 'DATA') {
                $odometro = null;
            }

            // ⚠️ El cero del sensor desconectado. Mirando el JSON real
            // (equipo 1061, 11/09) aparecen lecturas DATA con
            // combustible_total = 0 y TODOS los tanques en 0 (y la
            // temperatura también en 0). Eso no es un tanque vacío, es el
            // sensor que en ese momento no tiene dato (se desconectó o se
            // está reconectando). Si lo tomo como "0 litros" pasa lo que
            // vimos: el gráfico cae a cero cada dos por tres y aparecen
            // caídas falsas de 700 L en un minuto. Así que lo trato como
            // "sin dato" (null), igual que un TIEMPO.
            $sinDatoDeSensor = false;
            if ($litros !== null && $litros == 0.0) {
                $algunTanqueConDato = false;
                foreach ($tanques as $t) {
                    if ($t !== null && $t > 0) {
                        $algunTanqueConDato = true;
                        break;
                    }
                }
                if (!$algunTanqueConDato) {
                    $litros = null;
                    $sinDatoDeSensor = true;
                }
            }

            // Temperatura del tanque: si el sensor no tenía dato (el caso de
            // arriba), el 0 que manda tampoco es real. Fuera de ese caso, un
            // 0 °C puede ser real (invierno), así que lo dejo.
            $temp = $this->extractFloat($rec, 'temp_tanque');
            if ($sinDatoDeSensor || $litros === null) {
                $temp = null;
            }

            // El odómetro en 0 tampoco es real (en DATA ya lo anulamos
            // arriba, pero por las dudas en cualquier evento).
            if ($odometro !== null && $odometro == 0.0) {
                $odometro = null;
            }

            // Coordenadas 0,0: el GPS no tenía fix. Ese punto está en el
            // medio del océano frente a África, no es una ubicación real —
            // sin esto aparecían anomalías "ubicadas" en 0.00000, 0.00000.
            $lat = $this->extractFloat($rec, 'lat');
            $lng = $this->extractFloat($rec, 'lng');
            if ($lat !== null && $lng !== null && $lat == 0.0 && $lng == 0.0) {
                $lat = null;
                $lng = null;
            }

            $velocidad = $this->extractFloat($rec, 'velocidad') ?? 0.0;

            $motorRaw = $this->extract($rec, 'motor_encendido');
            $motorEncendido = $motorRaw === null
                ? $velocidad > 0
                : (bool) (is_numeric($motorRaw) ? (int) $motorRaw : filter_var($motorRaw, FILTER_VALIDATE_BOOLEAN));

            $wifRaw = $this->extract($rec, 'wif');
            $wif = $wifRaw === null ? null : (bool) ((int) $wifRaw);

            $out[] = [
                'equipo'             => (string) $equipo,
                'fecha'              => $fecha,
                'evento'             => $evento,
                'lat'                => $lat,
                'lng'                => $lng,
                'velocidad'          => $velocidad,
                'combustible_litros' => $litros,
                'tanques'            => $tanques,
                'variacion'          => $this->extractFloat($rec, 'variacion'),
                'odometro_km'        => $odometro,
                'motor_encendido'    => $motorEncendido,
                'wif'                => $wif,
                'altitud'            => $this->extractFloat($rec, 'altitud'),
                'temp_tanque'        => $temp,
            ];
        }

        usort($out, fn($a, $b) => $a['fecha'] <=> $b['fecha']);

        return $out;
    }

    /**
     * Lectura de un tanque individual (tanque1_litros..tanque4_litros), con
     * la regla que confirmó Emiliano: 0 = recién desconectado (ignorar),
     * 65535 (0xFFFF) = tanque no presente en este vehículo (ignorar).
     */
    private function extractTanque(array $rec, string $fieldKey): ?float
    {
        $val = $this->extractFloat($rec, $fieldKey);
        if ($val === null || $val <= 0.0 || $val >= 65535.0) {
            return null;
        }
        return $val;
    }

    private function extract(array $rec, string $fieldKey)
    {
        $aliases = $this->fieldMap['fields'][$fieldKey]['raw'] ?? [];
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $rec) && $rec[$alias] !== null && $rec[$alias] !== '') {
                return $rec[$alias];
            }
        }
        return null;
    }

    private function extractFloat(array $rec, string $fieldKey): ?float
    {
        $val = $this->extract($rec, $fieldKey);
        if ($val === null || !is_numeric($val)) {
            return $val !== null && is_numeric(str_replace(',', '.', (string) $val))
                ? (float) str_replace(',', '.', (string) $val)
                : null;
        }
        return (float) $val;
    }

    private function parseFecha($raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            // timestamp unix (segundos o milisegundos)
            $ts = (int) $raw;
            if ($ts > 20000000000) { // parece milisegundos
                $ts = intdiv($ts, 1000);
            }
            return (new DateTimeImmutable())->setTimestamp($ts);
        }
        try {
            return new DateTimeImmutable((string) $raw);
        } catch (Exception $e) {
            return null;
        }
    }

    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
