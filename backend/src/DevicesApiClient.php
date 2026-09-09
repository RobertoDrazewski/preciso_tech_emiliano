<?php

/**
 * Cliente de la API de EQUIPOS de TTM / Preciso (get_devices.php).
 *
 * Endpoint confirmado por Emiliano (26/08/2026):
 *   https://ttm.com.ar/testing/api/get_devices.php
 *
 * Devuelve el listado completo de la flota (en la prueba: 1270 equipos) con
 * datos administrativos — cliente, estado (ACTIVO/INACTIVO/SUSPENDIDO),
 * tipo, marca, modelo, capacidad del tanque, etc. Es un endpoint DISTINTO
 * al de telemetría (get_full_data.php, que sigue siendo por equipo puntual
 * y con rango de fechas) — este trae TODA la flota de una sola consulta, sin
 * parámetros.
 *
 * A diferencia de get_full_data.php, este endpoint se consulta muy poco
 * (una sincronización manual o, como mucho, una vez al día) — no tiene
 * sentido pedir el listado completo de la flota cada 5 minutos como
 * hacemos con la telemetría.
 */
class DevicesApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private bool $insecureSslTesting;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['devices_url'] ?? '', '?&');
        $this->token   = $config['token'] ?? '';
        $this->timeout = $config['timeout'] ?? 30; // más margen que la de telemetría, trae 1000+ registros de una
        $this->insecureSslTesting = $config['insecure_ssl_testing'] ?? false;
    }

    /**
     * Devuelve el listado crudo tal como lo manda la API (array de arrays
     * asociativos, un elemento por equipo). El mapeo a nuestro formato
     * interno lo hace DevicesNormalizer, no esta clase — misma separación
     * de responsabilidades que ApiClient/DataNormalizer.
     *
     * @throws RuntimeException
     */
    public function getDevices(): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('DEVICES_API_URL no está configurada (ver .env / config/config.php).');
        }

        $params = [];
        if ($this->token !== '') {
            $params['token'] = $this->token;
        }

        $url = $this->baseUrl . ($params ? '?' . http_build_query($params) : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => !$this->insecureSslTesting,
            CURLOPT_SSL_VERIFYHOST => $this->insecureSslTesting ? 0 : 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Error de cURL ({$errno}) consultando get_devices.php: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("HTTP {$httpCode} al consultar get_devices.php. Respuesta: " . substr($body, 0, 300));
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('get_devices.php no devolvió JSON válido: ' . json_last_error_msg());
        }

        if (!isset($decoded['success']) || $decoded['success'] !== true) {
            throw new RuntimeException('get_devices.php devolvió success=false o formato inesperado.');
        }

        return $decoded['data'] ?? [];
    }
}
