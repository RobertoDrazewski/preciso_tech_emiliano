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

    public function __construct(array $fieldMap)
    {
        $this->fieldMap = $fieldMap;
    }

    /**
     * @return array<int, array{equipo:string, fecha:DateTimeImmutable, evento:?string,
     *   lat:?float, lng:?float, velocidad:float, combustible_litros:?float,
     *   tanques:array<int,?float>, variacion:?float, odometro_km:?float, motor_encendido:bool}>
     */
    public function normalize(array $rawResponse, string $equipoIdFallback): array
    {
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

            $velocidad = $this->extractFloat($rec, 'velocidad') ?? 0.0;

            $motorRaw = $this->extract($rec, 'motor_encendido');
            $motorEncendido = $motorRaw === null
                ? $velocidad > 0
                : (bool) (is_numeric($motorRaw) ? (int) $motorRaw : filter_var($motorRaw, FILTER_VALIDATE_BOOLEAN));

            $out[] = [
                'equipo'             => (string) $equipo,
                'fecha'              => $fecha,
                'evento'             => $evento,
                'lat'                => $this->extractFloat($rec, 'lat'),
                'lng'                => $this->extractFloat($rec, 'lng'),
                'velocidad'          => $velocidad,
                'combustible_litros' => $litros,
                'tanques'            => $tanques,
                'variacion'          => $this->extractFloat($rec, 'variacion'),
                'odometro_km'        => $odometro,
                'motor_encendido'    => $motorEncendido,
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
