<?php

/**
 * Traduce el JSON crudo de get_devices.php a nuestro formato interno de
 * equipos (el que espera Storage::upsertEquiposMasivo).
 *
 * Campos crudos confirmados de la API real (26/08/2026):
 *   ID, nombre, cliente, modelo, tipo, estado (ACTIVO/INACTIVO/SUSPENDIDO),
 *   marca, capacidad, ultimo_nivel, odometro, latitud, longitud, chip,
 *   imei, diagnostico, proceso, etc.
 *
 * Ojo con dos cosas de calidad de dato que vimos en la muestra real:
 *   - El campo "chip" a veces trae un tab (\t) pegado al final — no nos
 *     afecta porque no lo usamos, pero queda anotado por si hace falta a futuro.
 *   - "capacidad" viene en 0 para la gran mayoría de los vehículos (parece
 *     estar cargado solo para algunos tanques fijos) — lo guardamos igual,
 *     tratamos 0 como "sin dato" en vez de "tanque de 0 litros".
 */
class DevicesNormalizer
{
    public function normalize(array $rawDevices): array
    {
        $out = [];

        foreach ($rawDevices as $d) {
            if (!isset($d['ID'])) {
                continue; // registro sin id, no lo podemos usar
            }

            $capacidad = isset($d['capacidad']) ? (float) $d['capacidad'] : 0.0;

            $out[] = [
                'id'                => (string) $d['ID'],
                'nombre'            => (string) ($d['nombre'] ?? $d['ID']),
                'cliente'           => $this->limpiar($d['cliente'] ?? null),
                'estado_raw'        => $this->limpiar($d['estado'] ?? null),
                'tipo'              => $this->limpiar($d['tipo'] ?? null),
                'marca'             => $this->limpiar($d['marca'] ?? null),
                'modelo'            => $this->limpiar($d['modelo'] ?? null),
                'capacidad_litros'  => $capacidad > 0 ? $capacidad : null,
            ];
        }

        return $out;
    }

    private function limpiar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $valor = trim($valor);
        return $valor === '' ? null : $valor;
    }
}
