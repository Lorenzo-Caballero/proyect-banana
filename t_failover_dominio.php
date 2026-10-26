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
    preg_match('/function cert_vigente\(string \$host\): bool \{.*?\n\}/s', $PROV, $f1);
    preg_match('/function panel_dominio_elegido\(\): string \{.*?\n\}/s', $PROV, $f2);
    eval($c[0]); eval($f1[0]); eval($f2[0]);
    // $argv[2] = CSV de los hosts que el mock considera "vigentes"
    $vigentes = array_filter(explode(',', $argv[2] ?? ''));
    $GLOBALS['GP_CERT_CHECK'] = fn($h) => in_array($h, $vigentes, true);
    ob_start(); $r = panel_dominio_elegido(); ob_end_clean();  // el progreso va a stdout; lo descartamos
    echo $r;
    exit;
}
function elegido(string $vigentesCsv): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --caso ' . escapeshellarg($vigentesCsv);
    return trim((string) shell_exec($cmd));
}

echo "=== 1. Elige el primero con cert vigente ===\n";
chequear('todos vigentes -> el preferido (ganamos.io)',
         elegido('agents.ganamos.io,agents.ganamosbet.net,agents.ganamosonline.com')
           === 'agents.ganamos.io');
chequear('solo ganamos.io vigente -> ganamos.io',
         elegido('agents.ganamos.io') === 'agents.ganamos.io');

echo "\n=== 2. Failover: si al preferido se le vence el cert, salta ===\n";
chequear('ganamos.io vencido -> ganamosbet.net',
         elegido('agents.ganamosbet.net,agents.ganamosonline.com') === 'agents.ganamosbet.net',
         'esto es lo que nos habria salvado el 22/10');
chequear('io y bet vencidos -> ganamosonline (ultimo recurso)',
         elegido('agents.ganamosonline.com') === 'agents.ganamosonline.com');

echo "\n=== 3. Ninguno valida (sin red) -> no queda vacio ===\n";
chequear('nada vigente -> cae al preferido, no a ""',
         elegido('') === 'agents.ganamos.io',
         'mejor intentar el preferido que no arrancar');

echo "\n=== 4. El ORDEN: menos WAF primero ===\n";
/* El orden de la lista ES la politica. ganamosonline tiene Bot Fight Mode, asi
   que va ULTIMO; los sin-BFM primero. */
preg_match('/const PANEL_DOMINIOS_PRIORIDAD = \[(.*?)\];/s', $PROV, $m);
$lista = $m[1] ?? '';
$pIo  = strpos($lista, 'ganamos.io');
$pBet = strpos($lista, 'ganamosbet.net');
$pOn  = strpos($lista, 'ganamosonline.com');
chequear('ganamos.io antes que ganamosonline', $pIo !== false && $pOn !== false && $pIo < $pOn);
chequear('ganamosbet.net antes que ganamosonline', $pBet !== false && $pOn !== false && $pBet < $pOn);
chequear('ganamosonline es el ULTIMO (tiene Bot Fight Mode)',
         $pOn > $pIo && $pOn > $pBet);

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
chequear('y en el shell ganamos.io va antes que ganamosonline',
         $sIo !== false && $sOn !== false && $sIo < $sOn);
chequear('el shell elige por cert vigente (cert_vigente/elegir_dominio)',
         str_contains($sh, 'cert_vigente') && str_contains($sh, 'elegir_dominio'));

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
