<?php

/**
 * Acceso al panel (14/10/2026).
 *
 * Hasta la v3.5 la API no pedía nada: cualquiera que tuviera la URL del
 * backend podía rechazar tickets, cambiar umbrales o usar "Notificar al
 * cliente" para mandar un mail desde la cuenta de Preciso. Ahora todos los
 * endpoints (menos login.php) piden un pase firmado que se obtiene con
 * usuario y clave.
 *
 * Se configura con variables de entorno en el servicio del BACKEND:
 *   PANEL_USUARIO  usuario del panel (ej: emiliano)
 *   PANEL_CLAVE    clave del panel
 *   PANEL_SECRETO  (opcional) texto largo al azar para firmar los pases. Si
 *                  no está, se deriva de la clave: cambiar la clave cierra
 *                  todas las sesiones abiertas.
 *
 * Si PANEL_CLAVE no está configurada, el acceso queda ABIERTO como antes (así
 * un deploy sin las variables no deja a nadie afuera), y se avisa en el log.
 *
 * Sin base de datos ni sesiones de PHP: el pase lleva adentro el usuario y
 * el vencimiento, firmados con HMAC. Viaja en el header Authorization (el
 * frontend y el backend están en dominios distintos, y las cookies entre
 * dominios dan más problemas que soluciones).
 */
class Auth
{
    private const DURACION_SEGUNDOS = 7 * 24 * 3600; // una semana

    public static function activo(): bool
    {
        return trim((string) Env::get('PANEL_CLAVE', '')) !== '';
    }

    private static function secreto(): string
    {
        $s = trim((string) Env::get('PANEL_SECRETO', ''));
        return $s !== '' ? $s : hash('sha256', 'preciso-panel|' . Env::get('PANEL_CLAVE', ''));
    }

    private static function b64(string $t): string
    {
        return rtrim(strtr(base64_encode($t), '+/', '-_'), '=');
    }

    private static function deB64(string $t): string|false
    {
        return base64_decode(strtr($t, '-_', '+/'), true);
    }

    /** Devuelve un pase si usuario y clave son correctos, o null. */
    public static function login(string $usuario, string $clave): ?array
    {
        $usuarioOk = (string) Env::get('PANEL_USUARIO', 'preciso');
        $claveOk = (string) Env::get('PANEL_CLAVE', '');
        if ($claveOk === '') {
            return null;
        }
        $ok = hash_equals(strtolower(trim($usuarioOk)), strtolower(trim($usuario)))
            && hash_equals($claveOk, $clave);
        if (!$ok) {
            return null;
        }
        $vence = time() + self::DURACION_SEGUNDOS;
        $payload = self::b64(json_encode(['u' => trim($usuario), 'exp' => $vence]));
        $firma = self::b64(hash_hmac('sha256', $payload, self::secreto(), true));
        return ['token' => $payload . '.' . $firma, 'vence' => $vence, 'usuario' => trim($usuario)];
    }

    /** Usuario del pase si es válido y no venció; null si no. */
    public static function validar(?string $token): ?string
    {
        if (!$token || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $firma] = explode('.', $token);
        $esperada = self::b64(hash_hmac('sha256', $payload, self::secreto(), true));
        if (!hash_equals($esperada, $firma)) {
            return null;
        }
        $datos = json_decode((string) self::deB64($payload), true);
        if (!is_array($datos) || ($datos['exp'] ?? 0) < time()) {
            return null;
        }
        return (string) ($datos['u'] ?? '');
    }

    /** Lee el pase del header "Authorization: Bearer ...". */
    public static function tokenDelPedido(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $h = $v;
                }
            }
        }
        return preg_match('/^Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : null;
    }
}
