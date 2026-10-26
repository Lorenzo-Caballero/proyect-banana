<?php
/**
 * t_failover_dominio.php — El failover de dominio por cert vigente.
 *
 * QUE CUIDA. El 22/10/2026 a agents.ganamos7.com se le vencio el cert TLS y
 * tumbo las altas. El failover elige, de una LISTA en orden de preferencia, el
 * primero con cert vigente -- asi el dia que a uno se le venza, salta solo al
 * siguiente en vez de caerse.
 *
 * Reglas que se chequean:
 *  1. Elige el PRIMERO con cert vigente (no simplemente el primero).
 *  2. Si al preferido se le vence el cert, pasa al siguiente.
 *  3. Si NINGUNO valida (sin red), no queda vacio: cae al preferido.
 *  4. El ORDEN es "el que menos molesta el WAF primero": ganamos.io y
 *     ganamosbet (sin Bot Fight Mode) antes que ganamosonline (con BFM).
 *  5. Solo entran dominios con ESCRITURA confirmada (no uno solo-lectura).
 *
 * La funcion PHP real se extrae de provisionar.php y se corre con un cert-check
 * mockeado (GP_CERT_CHECK). El orden del shell se verifica sobre el texto.
 *
 *     php t_failover_dominio.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* Carga la funcion real sin incluir todo provisionar.php (que requiere
   panel_config.php). Se corre en un SUBPROCESO por caso: panel_dominio_elegido
   cachea en static, asi que cada escenario necesita un proceso limpio. */
$PROV = file_get_contents(__DIR__ . '/panel/provisionar.php');

if (($argv[1] ?? '') === '--caso') {
    preg_match('/const PANEL_DOMINIOS_PRIORIDAD = \[.*?\];/s', $PROV, $c);
    preg_match('/function cert_estado\(string \$host\): string \{.*?\n\}/s', $PROV, $f1);
    preg_match('/function panel_dominio_elegido\(\): string \{.*?\n\}/s', $PROV, $f2);
    eval($c[0]); eval($f1[0]); eval($f2[0]);
    // $argv[2] = CSV de tokens "estado:host" (gts:host / otro:host). Lo que no
    // figure es 'no' (vencido/inalcanzable).
    $mapa = [];
    foreach (array_filter(explode(',', $argv[2] ?? '')) as $tok) {
        [$est, $h] = array_pad(explode(':', $tok, 2), 2, '');
        $mapa[$h] = $est;
    }
    $GLOBALS['GP_CERT_CHECK'] = fn($h) => $mapa[$h] ?? 'no';
    ob_start(); $r = panel_dominio_elegido(); ob_end_clean();  // el progreso va a stdout; lo descartamos
    echo $r;
    exit;
}
function elegido(string $tokensCsv): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --caso ' . escapeshellarg($tokensCsv);
    return trim((string) shell_exec($cmd));
}

echo "=== 1. Elige el primero con cert auto-renovable (gts) ===\n";
chequear('todos gts -> el preferido (ganamosonline, probado bajo carga)',
         elegido('gts:agents.ganamosonline.com,gts:agents.ganamosbet.net,gts:agents.ganamos.io')
           === 'agents.ganamosonline.com');
chequear('solo ganamosonline gts -> ganamosonline',
         elegido('gts:agents.ganamosonline.com') === 'agents.ganamosonline.com');

echo "\n=== 2. Failover: si al preferido se le vence el cert, salta ===\n";
chequear('ganamosonline vencido -> ganamosbet.net',
         elegido('gts:agents.ganamosbet.net,gts:agents.ganamos.io') === 'agents.ganamosbet.net',
         'esto es lo que nos habria salvado el 22/10');
chequear('online y bet vencidos -> ganamos.io (ultimo recurso)',
         elegido('gts:agents.ganamos.io') === 'agents.ganamos.io');

echo "\n=== 2b. Preferencia por EMISOR: auto-renovable gana al a-mano ===\n";
/* ganamosonline vigente pero de OTRO emisor (a mano, como el LE de ganamos7);
   ganamosbet gts. Aunque online este primero en la lista, se elige bet: un
   cert auto-renovado no es la falla, uno a mano puede lapsar. */
chequear('online=otro (a mano), bet=gts -> ganamosbet.net',
         elegido('otro:agents.ganamosonline.com,gts:agents.ganamosbet.net') === 'agents.ganamosbet.net',
         'un cert a-mano arriba no debe ganarle a uno auto-renovable');
chequear('si TODOS son a-mano, igual elige (vigente) el preferido',
         elegido('otro:agents.ganamosonline.com,otro:agents.ganamosbet.net') === 'agents.ganamosonline.com',
         'sin gts disponible, un vigente a-mano es mejor que nada');

echo "\n=== 3. Ninguno valida (sin red) -> no queda vacio ===\n";
chequear('nada vigente -> cae al preferido, no a ""',
         elegido('') === 'agents.ganamosonline.com',
         'mejor intentar el preferido que no arrancar');

echo "\n=== 4. El ORDEN: lo mas probado primero, lo build-nuevo ultimo ===\n";
/* ganamosonline es el unico probado bajo CARGA real: va PRIMERO. Los build-nuevo
   (role:0) crean en el test pero no se midio como aguantan la rafaga: van
   DESPUES de los build-viejo, como fallback mas profundo. */
preg_match('/const PANEL_DOMINIOS_PRIORIDAD = \[(.*?)\];/s', $PROV, $m);
$lista = $m[1] ?? '';
$pOn  = strpos($lista, 'ganamosonline.com');
$pBet = strpos($lista, 'ganamosbet.net');
$pIo  = strpos($lista, 'ganamos.io');
$pCo  = strpos($lista, 'ganamos.co');
chequear('ganamosonline es el PRIMERO (probado bajo carga)',
         $pOn === 0 || ($pOn < $pBet && $pOn < $pIo && $pOn < $pCo));
chequear('los build-viejo (online, bet, io) van antes que los build-nuevo (co...)',
         max($pOn, $pBet, $pIo) < $pCo,
         'un build-nuevo sin medir bajo carga no debe ir antes que uno probado');

echo "\n=== 5. Solo dominios con escritura confirmada ===\n";
/* Los 7 estan write-confirmados (probar_alta.py, 26/10): los 4 build-nuevo
   crean gracias a la plantilla superset (role:0). ganamos7 sigue AFUERA: cert
   vencido y, sobre todo, nunca se le confirmo un alta por esta cuenta. La regla
   no cambio -- "solo escritura confirmada va al failover"-- cambio que ahora
   hay 7 confirmados en vez de 3. */
chequear('incluye los 4 build-nuevo (ya con alta probada via superset)',
         strpos($lista, 'ganamos.co') !== false && strpos($lista, 'ganamos1.com') !== false
         && strpos($lista, 'ganamos.net') !== false && strpos($lista, 'ganamos.bet') !== false);
chequear('NO incluye ganamos7 (cert vencido, alta nunca confirmada)',
         strpos($lista, 'ganamos7') === false);

echo "\n=== 6. El shell usa el MISMO orden ===\n";
$sh = file_get_contents(__DIR__ . '/scripts/arreglar-bot-altas.sh');
preg_match('/DOMINIOS_PRIORIDAD=\((.*?)
\)/s', $sh, $ms);
$lsh = $ms[1] ?? '';
$sIo = strpos($lsh, 'ganamos.io'); $sOn = strpos($lsh, 'ganamosonline.com');
chequear('el shell tiene la lista de prioridad', $lsh !== '');
chequear('y en el shell ganamosonline va antes que ganamos.io (igual que el PHP)',
         $sOn !== false && $sIo !== false && $sOn < $sIo);
chequear('el shell elige por estado de cert (cert_estado/elegir_dominio)',
         str_contains($sh, 'cert_estado') && str_contains($sh, 'elegir_dominio'));
chequear('el shell prefiere el emisor auto-renovable (Google Trust Services)',
         str_contains($sh, 'Google Trust Services'));

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
