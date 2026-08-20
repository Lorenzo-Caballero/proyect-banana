<?php
/**
 * auth_lib.php — JWT propio (HS256, sin dependencias) para el login del sitio.
 *
 *   jwt_crear($payload, $secret[, $ttl])  -> string token
 *   jwt_verificar($token, $secret)        -> array claims | null (si firma/exp mal)
 *
 * El secreto sale de JWT_SECRET (config.local.php). El payload lleva 'username',
 * que es lo que el navegador (index.html) y chatbot.php usan para identificar.
 */

declare(strict_types=1);

if (!function_exists('jwt_crear')) {

    function _b64url(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    function _b64url_dec(string $d): string
    {
        return (string)base64_decode(strtr($d, '-_', '+/'));
    }

    /** Crea un JWT firmado. $ttl en segundos (default 7 dias). */
    function jwt_crear(array $payload, string $secret, int $ttl = 604800): string
    {
        $header = _b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;
        $cuerpo = _b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $firma  = _b64url(hash_hmac('sha256', "$header.$cuerpo", $secret, true));
        return "$header.$cuerpo.$firma";
    }

    /** Verifica firma y expiracion. Devuelve los claims o null. */
    function jwt_verificar(string $token, string $secret): ?array
    {
        $p = explode('.', $token);
        if (count($p) !== 3) { return null; }
        [$h, $c, $firma] = $p;
        $calc = _b64url(hash_hmac('sha256', "$h.$c", $secret, true));
        if (!hash_equals($calc, $firma)) { return null; }
        $claims = json_decode(_b64url_dec($c), true);
        if (!is_array($claims)) { return null; }
        if (isset($claims['exp']) && time() > (int)$claims['exp']) { return null; }
        return $claims;
    }
}
