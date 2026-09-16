<?php
/**
 * t_ip_cliente.php — Que la IP sea la del jugador, y que el header no se crea
 * por si mismo.
 *
 * POR QUE EXISTE: `alta_ip()` devolvia REMOTE_ADDR con el sitio detras de
 * Cloudflare, o sea el edge de la CDN. Nadie lo noto por meses porque el
 * sintoma --vinculos falsos en el CRM, jugadores con "Demasiados intentos" al
 * entrar-- no se parece en nada a la causa. Ver api/ip_cliente.php.
 *
 * Los dos lados del chequeo pesan igual:
 *   - que detras de Cloudflare se lea CF-Connecting-IP (si no, volvemos al bug);
 *   - que FUERA de Cloudflare NO se lea (si no, cualquiera se saltea los
 *     limites mandando un header, que es exactamente lo que advertia el
 *     comentario viejo de altas_lib.php).
 */

declare(strict_types=1);
require_once __DIR__ . '/api/ip_cliente.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $cond, string $det = ''): void
{
    global $ok, $fail;
    if ($cond) { $ok++;  echo "  OK    $q\n"; }
    else       { $fail++; echo "  FALLA $q   $det\n"; }
}

/** Pone el escenario de una request y devuelve lo que ve ip_cliente(). */
function comoSi(string $remota, ?string $cf = null): string
{
    $_SERVER['REMOTE_ADDR'] = $remota;
    unset($_SERVER['HTTP_CF_CONNECTING_IP']);
    if ($cf !== null) { $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf; }
    return ip_cliente();
}

echo "\n=== 1. Detras de Cloudflare gana el header ===\n";

/* Las tres IP son de las que aparecieron de verdad en `altas.ip`. */
chequear('un edge 162.158.x deja pasar la IP del jugador',
         comoSi('162.158.195.184', '181.45.20.7') === '181.45.20.7');
chequear('un edge 172.69.x tambien',
         comoSi('172.69.255.142', '190.2.3.4') === '190.2.3.4');
chequear('y 198.41.230.150, que tenia 16 cuentas',
         comoSi('198.41.230.150', '200.9.9.9') === '200.9.9.9');
chequear('IPv6 de Cloudflare igual',
         comoSi('2606:4700:1::1', '181.45.20.7') === '181.45.20.7');

echo "\n=== 2. Fuera de Cloudflare el header NO se cree ===\n";

/* ESTE ES EL CHEQUEO QUE PROTEGE EL LIMITE. Si algun dia alguien "simplifica"
   esto a leer la cabecera siempre, los limites por IP quedan sin efecto: se
   esquivan mandando un valor distinto en cada request. */
chequear('una IP cualquiera con header mentido se ignora',
         comoSi('45.10.20.30', '1.2.3.4') === '45.10.20.30',
         'el header lo puede escribir cualquiera');
chequear('localhost con header mentido tambien',
         comoSi('127.0.0.1', '8.8.8.8') === '127.0.0.1');
chequear('una IP PEGADA a un rango de CF, pero afuera, no cuenta',
         comoSi('162.157.255.255', '1.2.3.4') === '162.157.255.255',
         '162.158.0.0/15 arranca en 162.158');

echo "\n=== 3. Ante la duda, REMOTE_ADDR ===\n";

/* Degradar hacia el comportamiento viejo es siempre seguro: como mucho se
   vuelve al bug conocido. Devolver basura no. */
chequear('sin header, el edge se devuelve tal cual',
         comoSi('162.158.195.184') === '162.158.195.184');
chequear('un header vacio no borra la IP',
         comoSi('162.158.195.184', '') === '162.158.195.184');
chequear('un header que no es una IP se descarta',
         comoSi('162.158.195.184', 'pepe') === '162.158.195.184');
chequear('y uno con basura pegada tambien',
         comoSi('162.158.195.184', '1.2.3.4, 5.6.7.8') === '162.158.195.184',
         'CF-Connecting-IP es un valor unico, no una lista');

echo "\n=== 4. Los limites cuentan personas, no el edge ===\n";

/* EL SINTOMA QUE NADIE ATABA A ESTO: auth.php limita 15 intentos cada 5 min
   por IP. Con REMOTE_ADDR, los jugadores comparten unos pocos edges, asi que
   el limite pasaba a ser global y el numero 16 se comia "Demasiados intentos"
   sin haber fallado una sola vez. */
$a = comoSi('162.158.195.184', '181.45.20.7');
$b = comoSi('162.158.195.184', '190.55.1.2');
chequear('dos jugadores por el MISMO edge son dos IP distintas', $a !== $b,
         "$a vs $b");

$src = file_get_contents(__DIR__ . '/api/auth.php');
chequear('auth.php limita por ip_cliente(), no por REMOTE_ADDR',
         str_contains($src, 'ip_cliente()')
         && !preg_match('/auth_rl_.*REMOTE_ADDR/', $src));

$src = file_get_contents(__DIR__ . '/api/altas_lib.php');
chequear('alta_ip() tambien',
         (bool)preg_match('/function alta_ip\(\).*?return ip_cliente\(\);/s', $src));

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
