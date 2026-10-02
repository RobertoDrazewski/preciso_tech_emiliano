<?php

/**
 * Cliente SMTP mínimo (sin Composer / PHPMailer) — suficiente para mandar
 * alertas HTML por Gmail u otro proveedor con STARTTLS en el puerto 587.
 *
 * Si más adelante prefieren usar PHPMailer (más robusto para adjuntos, etc),
 * esta clase se puede reemplazar sin tocar el resto del código: lo único que
 * usan los demás archivos es el método send().
 */
class SmtpMailer
{
    private array $cfg;

    /** Por qué falló el último envío (para mostrárselo al operador). */
    public string $ultimoError = '';

    public function __construct(array $smtpConfig)
    {
        $this->cfg = $smtpConfig;
    }

    /**
     * $adjuntos (opcional): [['nombre' => 'x.pdf', 'contenido' => <bytes>, 'tipo' => 'application/pdf'], ...]
     * Lo agregué para mandarle al cliente el PDF de la anomalía (02/10/2026).
     */
    public function send(string $subject, string $htmlBody, ?array $toOverride = null, array $adjuntos = []): bool
    {
        $this->ultimoError = '';
        $to = $toOverride ?? $this->cfg['to'];
        if (empty($to) || empty($this->cfg['user']) || empty($this->cfg['pass'])) {
            $this->ultimoError = 'El correo no está configurado (faltan SMTP_USER / SMTP_PASS o el destinatario).';
            error_log('[SmtpMailer] Configuración SMTP incompleta, no se envió el mail: ' . $subject);
            return false;
        }

        $socket = @stream_socket_client(
            "tcp://{$this->cfg['host']}:{$this->cfg['port']}",
            $errno,
            $errstr,
            15
        );
        if (!$socket) {
            $this->ultimoError = "No se pudo conectar al servidor de correo ({$this->cfg['host']}:{$this->cfg['port']}).";
            error_log("[SmtpMailer] No se pudo conectar a {$this->cfg['host']}:{$this->cfg['port']} — {$errstr}");
            return false;
        }

        try {
            $this->expect($socket, '220');
            $this->cmd($socket, "EHLO localhost", '250');
            $this->cmd($socket, "STARTTLS", '220');

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo negociar TLS con el servidor SMTP.');
            }

            $this->cmd($socket, "EHLO localhost", '250');
            $this->cmd($socket, "AUTH LOGIN", '334');
            $this->cmd($socket, base64_encode($this->cfg['user']), '334');
            $this->cmd($socket, base64_encode($this->cfg['pass']), '235');

            $this->cmd($socket, "MAIL FROM:<{$this->cfg['from']}>", '250');
            foreach ($to as $addr) {
                $this->cmd($socket, "RCPT TO:<{$addr}>", ['250', '251']);
            }

            $this->cmd($socket, "DATA", '354');

            $headers = [];
            $headers[] = "From: {$this->cfg['from_name']} <{$this->cfg['from']}>";
            $headers[] = 'To: ' . implode(', ', $to);
            $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Date: ' . date('r');

            if (empty($adjuntos)) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
                $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n";
            } else {
                // Con adjuntos: mensaje "multipart" — una parte con el HTML y
                // una parte por archivo, todo en base64.
                $limite = 'preciso_' . bin2hex(random_bytes(8));
                $headers[] = "Content-Type: multipart/mixed; boundary=\"{$limite}\"";
                $cuerpo = "--{$limite}\r\n"
                    . "Content-Type: text/html; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($htmlBody));
                foreach ($adjuntos as $a) {
                    $nombre = str_replace('"', '', $a['nombre']);
                    $cuerpo .= "--{$limite}\r\n"
                        . "Content-Type: " . ($a['tipo'] ?? 'application/octet-stream') . "; name=\"{$nombre}\"\r\n"
                        . "Content-Transfer-Encoding: base64\r\n"
                        . "Content-Disposition: attachment; filename=\"{$nombre}\"\r\n\r\n"
                        . chunk_split(base64_encode($a['contenido']));
                }
                $cuerpo .= "--{$limite}--\r\n";
                $message = implode("\r\n", $headers) . "\r\n\r\n" . $cuerpo;
            }
            // Escapar líneas que empiecen con "." según el protocolo SMTP.
            $message = preg_replace('/^\./m', '..', $message);

            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->cmd($socket, "QUIT", '221');

            return true;
        } catch (Exception $e) {
            $this->ultimoError = 'El servidor de correo rechazó el envío: ' . $e->getMessage();
            error_log('[SmtpMailer] ' . $e->getMessage());
            return false;
        } finally {
            fclose($socket);
        }
    }

    private function cmd($socket, string $command, $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, $expectedCode): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // La última línea de una respuesta multilínea tiene un espacio (no guion) después del código.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $codes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $actual = substr($response, 0, 3);
        if (!in_array($actual, $codes, true)) {
            throw new RuntimeException("Respuesta SMTP inesperada: {$response}");
        }

        return $response;
    }
}
