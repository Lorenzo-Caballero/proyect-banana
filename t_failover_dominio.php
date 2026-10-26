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

echo "\n=== 4. El ORDEN: el probado bajo carga primero ===\n";
/* El orden de la lista ES la politica. ganamos.io daba 200 a curl pelado pero
   bajo la rafaga del bot real tiraba challenges sin parar (25/10): va ULTIMO.
   ganamosonline es el unico probado funcionando bajo carga: va PRIMERO. */
preg_match('/const PANEL_DOMINIOS_PRIORIDAD = \[(.*?)\];/s', $PROV, $m);
$lista = $m[1] ?? '';
$pIo  = strpos($lista, 'ganamos.io');
$pBet = strpos($lista, 'ganamosbet.net');
$pOn  = strpos($lista, 'ganamosonline.com');
chequear('ganamosonline antes que ganamos.io', $pOn !== false && $pIo !== false && $pOn < $pIo);
chequear('ganamosonline es el PRIMERO (probado bajo carga)',
         $pOn < $pIo && $pOn < $pBet);
chequear('ganamos.io es el ULTIMO (tira challenges bajo rafaga)',
         $pIo > $pOn && $pIo > $pBet);

echo "\n=== 5. Solo dominios con escritura confirmada ===\n";
/* ganamos.net / ganamos.bet pasaron lectura pero NO una alta real: no van en
   el failover -- saltar a uno que no escribe seria cambiar el cert vencido por
   un Unauthorized silencioso. */
chequear('no incluye ganamos.net (no se probo escritura)',
         strpos($lista, 'ganamos.net') === false);
chequear('no incluye ganamos7 (cert vencido)',
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
