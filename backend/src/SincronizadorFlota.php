<?php

/**
 * Sincroniza la flota completa desde la API de equipos de TTM
 * (get_devices.php): nombres, clientes, estado, tipo, diagnóstico, correos.
 *
 * Antes esto vivía solo en scripts/sincronizar_equipos.php y corría
 * únicamente cuando arrancaba el backend: si nadie redeployaba, los equipos
 * dados de baja, los clientes nuevos y el diagnóstico "AGUA" quedaban
 * desactualizados días enteros. Ahora lo usan el script y también el cron
 * (una vez por día, ver cron/check_alertas.php).
 *
 * Devuelve las líneas de texto para mostrar en la consola / log.
 */
class SincronizadorFlota
{
    public static function sincronizar(array $config, Storage $storage): array
    {
        $log = [];
        $client = new DevicesApiClient($config['api']);
        $crudos = $client->getDevices();

        $equipos = (new DevicesNormalizer())->normalize($crudos);
        $log[] = 'Recibidos ' . count($equipos) . ' equipos de la API.';

        $n = $storage->upsertEquiposMasivo($equipos);

        // Correos de los clientes (para "Notificar al cliente"), si la API los trae.
        $emails = [];
        foreach ($equipos as $eq) {
            if (!empty($eq['email_cliente'])) {
                $emails[$eq['id']] = $eq['email_cliente'];
            }
        }
        $storage->actualizarEmailsClientes($emails);
        $log[] = count($emails) > 0
            ? 'Correos de cliente encontrados en la API: ' . count($emails) . ' equipos.'
            : 'La API de equipos no trajo correos de cliente (revisar con TTM el nombre del campo).';

        $activos = count(array_filter($equipos, fn($e) => ($e['estado_raw'] ?? '') === 'ACTIVO'));
        $log[] = "Listo: {$n} equipos sincronizados ({$activos} activos, " . ($n - $activos) . ' inactivos/suspendidos).';

        // Lo que está activo acá pero ya no viene en la API se desactiva (no
        // se borra): la API de equipos es la fuente de verdad.
        $desactivados = $storage->desactivarEquiposNoPresentes(array_column($equipos, 'id'));
        if ($desactivados > 0) {
            $log[] = "Desactivados {$desactivados} equipos que ya no vienen en la API.";
        }

        // Diagnóstico "AGUA" que trae la API de equipos: es un estado actual,
        // no una lectura con fecha, así que no lo repito si ya hay uno reciente.
        $nuevasAgua = 0;
        foreach ($equipos as $eq) {
            if (($eq['diagnostico'] ?? null) !== 'AGUA') {
                continue;
            }
            if ($storage->existeAnomaliaDeTipoDesde($eq['id'], 'agua_combustible', (new DateTimeImmutable())->modify('-7 days'))) {
                continue;
            }
            $storage->guardarAnomalia([
                'equipo'          => $eq['id'],
                'fecha'           => new DateTimeImmutable(),
                'tipo'            => 'agua_combustible',
                'detalle'         => 'La API de equipos de TTM marca este vehículo con diagnóstico "AGUA" (agua en el combustible).',
                'litros_perdidos' => 0.0,
                'z_score'         => null,
                'lat'             => $eq['lat'] ?? null,
                'lng'             => $eq['lng'] ?? null,
            ]);
            $nuevasAgua++;
        }
        if ($nuevasAgua > 0) {
            $log[] = "Detectadas {$nuevasAgua} anomalías de agua en combustible nuevas (según diagnóstico de TTM).";
        }

        $storage->setEstado('ultima_sync_equipos', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
        return $log;
    }
}
