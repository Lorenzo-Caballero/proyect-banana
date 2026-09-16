<?php
/**
 * ip_cliente.php — La IP del jugador, no la del proxy que tenemos adelante.
 *
 * ============================================================================
 * POR QUÉ EXISTE (16/09/2026)
 * ============================================================================
 * `altas_lib.php` decía, con toda razón:
 *
 *     REMOTE_ADDR y nada mas. Las cabeceras tipo X-Forwarded-For las manda el
 *     cliente: confiar en ellas es dejar el limite sin efecto con un header.
 *     Si algun dia el sitio queda detras de Cloudflare, ACA hay que cambiarlo.
 *
 * Ese día llegó y nadie lo notó, porque el síntoma no se parece en nada a la
 * causa. `ganamoscrm.online` pasó a estar detrás de Cloudflare y `REMOTE_ADDR`
 * dejó de ser el jugador: pasó a ser el edge de Cloudflare. Medido ese día
 * sobre la tabla `altas`:
 *
 *     162.158.195.184   124 cuentas
 *     172.69.255.142     45 cuentas
 *     198.41.230.150     16 cuentas
 *     ... 21 IPs "compartidas" que tocan 237 cuentas
 *
 * Todas esas son rangos de Cloudflare. **No había ni una IP de jugador en la
 * base.** Lo que rompió:
 *
 *   1. **Los vínculos por IP eran todos falsos.** El CRM le avisaba al
 *      operador que cuentas legítimas "parecen ser la misma persona" porque
 *      compartían un edge de la CDN. Eso es lo que hizo desconfiar del aviso
 *      entero, que es el daño peor: un aviso que miente se deja de leer.
 *   2. **El límite de login de `auth.php`** (15 intentos cada 5 min por IP)
 *      pasó a ser un límite GLOBAL: el jugador número 16 que intentaba entrar
 *      en esos 5 minutos recibía "Demasiados intentos", sin haber fallado una
 *      sola vez.
 *   3. Los límites `ALTAS_POR_IP_HORA` / `ALTAS_POR_IP_DIA` habrían frenado
 *      TODAS las altas a la vez. Se salvaron de casualidad: están en 0.
 *
 * ============================================================================
 * CÓMO SE HACE SIN REABRIR EL AGUJERO QUE EL COMENTARIO VIEJO AVISABA
 * ============================================================================
 * El riesgo es real: `X-Forwarded-For` y compañía las escribe cualquiera, y si
 * se les cree a ciegas, un atacante se saltea todos los límites mandando un
 * header distinto en cada request.
 *
 * La regla que lo cierra: **la cabecera se lee SOLO si la conexión viene de
 * Cloudflare.** Si `REMOTE_ADDR` está en los rangos publicados de Cloudflare,
 * entonces `CF-Connecting-IP` la puso Cloudflare —que la sobrescribe siempre,
 * pase lo que pase con lo que mande el cliente— y es confiable. Si la conexión
 * NO viene de Cloudflare (alguien pegándole directo al origen), la cabecera es
 * del cliente y se ignora: se usa `REMOTE_ADDR`, como antes.
 *
 * O sea que el header nunca se cree por sí mismo: se cree por QUIÉN lo trajo.
 *
 * `X-Forwarded-For` NO se usa ni siquiera viniendo de Cloudflare: es una lista
 * a la que el cliente puede anteponer entradas y elegir bien cuál tomar es
 * fácil de hacer mal. `CF-Connecting-IP` es un valor único y lo pisa el edge.
 */

declare(strict_types=1);

/**
 * Rangos publicados de Cloudflare (https://www.cloudflare.com/ips/).
 *
 * Están hardcodeados a propósito: bajarlos en vivo metería una llamada de red
 * en el camino de cada request y un modo de fallo nuevo (¿qué IP es la del
 * jugador si la lista no se pudo bajar?). Cambian muy de vez en cuando; si
 * alguna vez cambian, el síntoma es benigno —se vuelve a usar REMOTE_ADDR, o
 * sea el comportamiento viejo— y no que alguien se saltee un límite.
 */
const IPC_RANGOS_CLOUDFLARE = [
    '173.245.48.0/20',  '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18',  '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15',  '104.16.0.0/13',
    '104.24.0.0/14',    '172.64.0.0/13',   '131.0.72.0/22',
    '2400:cb00::/32',   '2606:4700::/32',  '2803:f800::/32',  '2405:b500::/32',
    '2405:8100::/32',   '2a06:98c0::/29',  '2c0f:f248::/32',
];

/**
 * ¿$ip cae dentro de $cidr? Sirve para IPv4 y IPv6: se comparan los primeros
 * $bits bits de la forma binaria, que es lo que `inet_pton` devuelve.
 *
 * Devuelve false ante cualquier cosa rara (IP inválida, familias distintas).
 * Acá "ante la duda, no" significa tratar la conexión como NO-Cloudflare, o
 * sea quedarse con REMOTE_ADDR: el comportamiento conservador.
 */
function ipc_en_rango(string $ip, string $cidr): bool
{
    $partes = explode('/', $cidr, 2);
    if (count($partes) !== 2) { return false; }
    $bits = (int)$partes[1];

    $bin  = @inet_pton($ip);
    $base = @inet_pton($partes[0]);
    if ($bin === false || $base === false) { return false; }
    if (strlen($bin) !== strlen($base)) { return false; }   // IPv4 vs IPv6

    $bytesEnteros = intdiv($bits, 8);
    $bitsSueltos  = $bits % 8;

    if ($bytesEnteros > 0
        && strncmp($bin, $base, $bytesEnteros) !== 0) { return false; }

    if ($bitsSueltos === 0) { return true; }

    $mascara = ~((1 << (8 - $bitsSueltos)) - 1) & 0xFF;
    return (ord($bin[$bytesEnteros]) & $mascara)
        === (ord($base[$bytesEnteros]) & $mascara);
}

/** ¿La conexión la trajo un edge de Cloudflare? */
function ipc_viene_de_cloudflare(string $remota): bool
{
    if ($remota === '') { return false; }
    foreach (IPC_RANGOS_CLOUDFLARE as $cidr) {
        if (ipc_en_rango($remota, $cidr)) { return true; }
    }
    return false;
}

/**
 * La IP del jugador. Usala en vez de `$_SERVER['REMOTE_ADDR']` en cualquier
 * lugar donde la IP se use para IDENTIFICAR o para LIMITAR: si no, estás
 * contando a todos los jugadores como si fueran uno solo.
 */
function ip_cliente(): string
{
    $remota = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    if (ipc_viene_de_cloudflare($remota)) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        // Se valida igual: una IP mal formada no sirve ni para limitar ni para
        // vincular, y guardar basura en la base es peor que guardar el edge.
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }
    }

    return $remota;
}
