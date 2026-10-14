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
            $lat = isset($d['latitud']) && $d['latitud'] !== '' ? (float) $d['latitud'] : null;
            $lng = isset($d['longitud']) && $d['longitud'] !== '' ? (float) $d['longitud'] : null;

            $out[] = [
                'id'                => (string) $d['ID'],
                'nombre'            => (string) ($d['nombre'] ?? $d['ID']),
                'cliente'           => $this->limpiar($d['cliente'] ?? null),
                'estado_raw'        => $this->limpiar($d['estado'] ?? null),
                'tipo'              => $this->limpiar($d['tipo'] ?? null),
                'marca'             => $this->limpiar($d['marca'] ?? null),
                'modelo'            => $this->limpiar($d['modelo'] ?? null),
                'capacidad_litros'  => $capacidad > 0 ? $capacidad : null,
                'diagnostico'       => $this->limpiar($d['diagnostico'] ?? null),
                'servicio'          => $this->limpiar($d['servicio'] ?? null),
                // SI/NO: si el cliente quiere recibir avisos ("Notificar al cliente" lo respeta).
                'notificaciones'    => $this->limpiar(isset($d['notificaciones']) ? strtoupper((string) $d['notificaciones']) : null),
                // Correo del cliente para "Notificar al cliente". Emiliano dice
                // que viene en la API; como todavía no sabemos el nombre exacto
                // del campo, pruebo los más comunes. Puede traer varios
                // separados por coma o punto y coma.
                'email_cliente'     => $this->primerCampo($d, ['email_cliente', 'mail_cliente', 'email', 'mail', 'emails', 'mails', 'correo', 'correos', 'email_notificaciones']),
                // lat/lng no se guardan en la tabla equipos (esa tabla es
                // datos administrativos, no telemetría) — se devuelven acá
                // igual para que sincronizar_equipos.php los pueda usar al
                // armar la anomalía de agua con ubicación.
                'lat'               => ($lat !== null && $lat !== 0.0) ? $lat : null,
                'lng'               => ($lng !== null && $lng !== 0.0) ? $lng : null,
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

    /** Devuelve el primer campo con dato de una lista de nombres posibles. */
    private function primerCampo(array $d, array $nombres): ?string
    {
        foreach ($nombres as $n) {
            if (isset($d[$n]) && is_scalar($d[$n]) && trim((string) $d[$n]) !== '') {
                return trim((string) $d[$n]);
            }
        }
        return null;
    }
}
