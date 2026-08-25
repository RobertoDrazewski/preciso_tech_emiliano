<?php

/**
 * Cliente de la API de TTM / Preciso (get_full_data.php).
 *
 * Formato de consulta confirmado por Emiliano (23/07/2026):
 *   get_full_data.php?date_range=2026-07-02+0%3A00+%2F+2026-07-23+23%3A59&equipo=1001
 *
 * date_range: "YYYY-MM-DD H:i / YYYY-MM-DD H:i" (con la barra como separador,
 * urlencodeada). Lo armamos acá para no tener que recordar el formato exacto
 * en cada lugar que se use.
 */
class ApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $retries;
    private bool $insecureSslTesting;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '?&');
        $this->token   = $config['token'] ?? '';
        $this->timeout = $config['timeout'] ?? 20;
        $this->retries = $config['retries'] ?? 2;
        $this->insecureSslTesting = $config['insecure_ssl_testing'] ?? false;

        if ($this->insecureSslTesting) {
            error_log('[ApiClient] ⚠️ Verificación SSL DESACTIVADA (API_INSECURE_SSL_TESTING=true). Solo para diagnóstico local, no usar en producción.');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function getFullData(string $equipoId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $dateRange = sprintf(
            '%s / %s',
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i')
        );

        $params = [
            'date_range' => $dateRange,
            'equipo'     => $equipoId,
        ];

        if ($this->token !== '') {
            $params['token'] = $this->token;
        }

        $url = $this->baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $lastError = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            try {
                return $this->request($url);
            } catch (RuntimeException $e) {
                $lastError = $e;
                if ($attempt < $this->retries) {
                    usleep(400_000); // 0.4s antes de reintentar
                }
            }
        }

        throw new RuntimeException(
            "No se pudo obtener datos de la API para equipo {$equipoId}: " . $lastError->getMessage()
        );
    }

    private function request(string $url): array
    {
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
            throw new RuntimeException("Error de cURL ({$errno}): {$error}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("HTTP {$httpCode} al consultar la API. Respuesta: " . substr($body, 0, 300));
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Respuesta no es JSON válido: ' . json_last_error_msg() . ' — Body: ' . substr($body, 0, 300));
        }

        return $decoded ?? [];
    }
}
