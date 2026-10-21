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

        $equipos = (new DevicesNormalizer($config['api']['devices_campo_email'] ?? ''))->normalize($crudos);
        $log[] = 'Recibidos ' . count($equipos) . ' equipos de la API.';

        $n = $storage->upsertEquiposMasivo($equipos);

        // Correos de los clientes (para "Notificar al cliente"), si la API los trae.
        $emails = [];
        foreach ($equipos as $eq) {
            if (!empty($eq['email_cliente'])) {
                $emails[$eq['id']] = $eq['email_cliente'];
            }
        }
        // Alternativa mientras la API no traiga los correos: el archivo
        // data/emails_clientes.json, con el correo de cada CLIENTE (por
        // nombre, igual que en TTM). Se usa solo para los equipos a los que
        // la API no les mandó correo: si la API lo trae, manda la API.
        $porCliente = self::emailsDesdeArchivo(BASE_PATH . '/data/emails_clientes.json');
        $desdeArchivo = 0;
        if ($porCliente) {
            foreach ($equipos as $eq) {
                $cliente = mb_strtolower(trim((string) ($eq['cliente'] ?? '')), 'UTF-8');
                if (empty($emails[$eq['id']]) && $cliente !== '' && isset($porCliente[$cliente])) {
                    $emails[$eq['id']] = $porCliente[$cliente];
                    $desdeArchivo++;
                }
            }
        }

        $storage->actualizarEmailsClientes($emails);
        $log[] = count($emails) > 0
            ? 'Correos de cliente: ' . count($emails) . ' equipos (' . (count($emails) - $desdeArchivo) . ' desde la API, ' . $desdeArchivo . ' desde data/emails_clientes.json).'
            : 'Sin correos de cliente: la API de equipos no los trae y no hay data/emails_clientes.json (ver DEVICES_CAMPO_EMAIL).';

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

    /**
     * Lee data/emails_clientes.json: { "Nombre del cliente": "a@x.com, b@x.com", ... }
     * Devuelve [nombre en minúsculas => correos]. Si el archivo no está o
     * está mal armado, devuelve [] (y lo aviso en el log, sin cortar nada).
     */
    public static function emailsDesdeArchivo(string $ruta): array
    {
        if (!is_file($ruta)) {
            return [];
        }
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($datos)) {
            error_log("[SincronizadorFlota] {$ruta} no es un JSON válido, lo ignoro.");
            return [];
        }
        $out = [];
        foreach ($datos as $cliente => $correos) {
            if (str_starts_with((string) $cliente, '_')) {
                continue; // claves que empiezan con "_" son comentarios
            }
            $lista = is_array($correos) ? $correos : preg_split('/[\s,;]+/', (string) $correos);
            $validos = array_values(array_filter(array_map('trim', $lista), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            if ($validos) {
                $out[mb_strtolower(trim((string) $cliente), 'UTF-8')] = implode(', ', $validos);
            }
        }
        return $out;
    }
}
