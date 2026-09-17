<?php
/**
 * simulacro.php — Recorrer el circuito de la plata EN PRODUCCIÓN, con un
 * jugador inventado, y verificar cada paso.
 *
 * PARA QUÉ (Nahuel, 16/09/2026): *"me gustaría testear quizás con algo
 * simulado, para no tener que dejar corriendo la publicidad 24 hs. ¿Podés
 * mandar acciones simuladas que prueben que el sistema funciona?"*.
 *
 * ============================================================================
 * POR QUÉ NO ALCANZA CON LOS TESTS QUE YA HAY
 * ============================================================================
 * Las 54 suites (`t_*.php`, `t_*.js`) corren contra una base de prueba con
 * datos inventados. Prueban la LÓGICA: que el matcher desempate bien, que un
 * bono no se pague dos veces, que un bloqueado no pueda retirar.
 *
 * Lo que NO prueban es que **producción esté bien cableada**: que la migración
 * corrió en la base del cliente, que la cuenta de cobro esté cargada, que el
 * bot esté vivo, que el colector haya pasado, que la config del tenant sea la
 * que uno cree. Eso no falla en un test — falla cuando entra plata de verdad,
 * y ahí ya es tarde.
 *
 * Esto recorre el circuito real, con el código real y la config real, usando
 * un jugador que no existe.
 *
 * ============================================================================
 * QUÉ TOCA Y QUÉ NO
 * ============================================================================
 * **NO toca el panel de ganamos.** No crea cuentas, no deposita, no retira.
 * Todo lo que hace vive en nuestra base y se borra al final.
 *
 * Lo que SÍ hace: crear un jugador de prueba, pedirle una recarga, simular el
 * mail del banco, y verificar que el matcher la acredite. Es el tramo donde
 * entra la plata, que es el que más duele si está roto.
 *
 * Con `--dejar` no limpia, por si querés mirar las filas.
 *
 *   php scripts/simulacro.php
 *   php scripts/simulacro.php --tenant ganamos
 *   php scripts/simulacro.php --dejar
 */

$args = $argv; array_shift($args);
$dejar = in_array('--dejar', $args, true);
$slug  = '';
$i = array_search('--tenant', $args, true);
if ($i !== false && isset($args[$i + 1])) { $slug = trim($args[$i + 1]); }

$_SERVER['HTTP_HOST'] = 'ganamoscrm.online';
if ($slug !== '') { $_SERVER['HTTP_X_TENANT_SLUG'] = $slug; }

/* NADA SALE HACIA AFUERA. Va ANTES de cargar cualquier lib, porque lo miran
   meta_evento() y tg_evento() en cuanto los llame el código real.

   POR QUÉ HACE FALTA (17/09/2026): este script acredita una recarga por el
   camino de verdad, y acreditar dispara `rl_notificar_acreditada()`, que manda
   un `Purchase` a la API de conversiones de Meta. Siete corridas dejaron
   SIETE conversiones falsas de $1.000 en la cuenta de publicidad, de jugadores
   `zzsim…` que no existen y que este mismo script borra al terminar.

   No es un número feo en un informe: Meta optimiza la pauta con esos eventos,
   así que el simulacro le estaba enseñando al algoritmo a buscar fantasmas. Y
   no se puede deshacer — un evento mandado a la CAPI no se retracta.

   La regla: una prueba puede tocar NUESTRA base, nunca a un tercero. */
define('GP_MODO_PRUEBA', true);

$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/config_crm.php';
require_once $API . '/recargas_lib.php';
require_once $API . '/fichas_lib.php';

$ok = 0; $mal = 0; $avisos = 0;
function bien(string $q, bool $c, string $d = ''): void {
    global $ok, $mal;
    if ($c) { $ok++;  printf("  \033[32mOK\033[0m    %s\n", $q); }
    else     { $mal++; printf("  \033[31mMAL\033[0m   %s%s\n", $q, $d !== '' ? "   $d" : ''); }
}
function ojo(string $q, string $d = ''): void {
    global $avisos; $avisos++;
    printf("  \033[33mOJO\033[0m   %s%s\n", $q, $d !== '' ? "   $d" : '');
}
function titulo(string $t): void { echo "\n\033[1m$t\033[0m\n" . str_repeat('-', 72) . "\n"; }

/* El jugador inventado. El prefijo `zzsim` no puede chocar con uno real: la
   landing y el chat generan nombres que empiezan con `hola`. */
$U = 'zzsim' . date('His');
$REF = null;

printf("\nSimulacro — tenant \033[1m%s\033[0m  (base %s)\n",
       $slug !== '' ? $slug : 'principal', $GLOBALS['TENANT_DB'] ?? '?');
printf("Jugador de prueba: %s\n", $U);

/* Pase lo que pase, la basura se limpia. Si el simulacro se cae en la mitad,
   igual no deja un jugador fantasma con saldo. */
$limpiar = function () use ($pdo, $U, $dejar) {
    if ($dejar) { echo "\n  (--dejar: no se borró nada)\n"; return; }
    foreach ([
        "DELETE FROM movimientos     WHERE usuario = ?",
        "DELETE FROM acciones_saldo  WHERE usuario = ?",
        "DELETE FROM recargas        WHERE usuario = ?",
        "DELETE FROM huellas_pagador WHERE usuario = ?",
        "DELETE FROM usuarios        WHERE username = ?",
    ] as $sql) {
        try { $pdo->prepare($sql)->execute([$U]); } catch (Throwable $e) {}
    }
    try { $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(['zzsim%']); }
    catch (Throwable $e) {}
};
register_shutdown_function($limpiar);

// ===========================================================================
titulo('1. La base está al día');
foreach ([
    'usuarios'       => 'saldo_visto_en',   // migración 68
    'usuarios2'      => 'bloqueado',        // 69
    'acciones_saldo' => 'payment_id',       // 70
] as $t => $col) {
    $tabla = $t === 'usuarios2' ? 'usuarios' : $t;
    try { $pdo->query("SELECT $col FROM $tabla LIMIT 0"); bien("columna $tabla.$col", true); }
    catch (Throwable $e) { bien("columna $tabla.$col", false, 'falta correr la migración'); }
}
foreach (['huellas_pagador', 'operaciones_panel', 'dispositivos_usuarios', 'bonos_pendientes'] as $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 0"); bien("tabla $t", true); }
    catch (Throwable $e) { bien("tabla $t", false, 'falta'); }
}

// ===========================================================================
titulo('2. La config con la que opera el cliente');
$cta = rl_cuenta_cobro();
$alias = trim((string)($cta['alias'] ?? ''));
$cbu   = trim((string)($cta['cbu'] ?? ''));
bien('hay una cuenta de cobro cargada', $alias !== '' || $cbu !== '',
     'sin esto el jugador no sabe a dónde transferir');
printf("        alias=%s  cbu=%s  titular=%s\n", $alias ?: '-', $cbu ?: '-',
       trim((string)($cta['titular'] ?? '')) ?: '-');
$cpp = rl_coins_por_peso();
bien('coins por peso configurado', $cpp > 0, "valor: $cpp");
bien('el alta de cuentas está prendida', cfg_crm_activo($pdo, 'registro_activo'));
if (!cfg_crm_activo($pdo, 'ruleta_activa')) {
    ojo('la ruleta está APAGADA', 'el bot no la ofrece; los bonos de giro no se pagan');
}

// ===========================================================================
titulo('3. El bot y el colector están vivos');
$visto = trim((string)cfg_crm($pdo, 'bot_altas_visto_en'));
if ($visto === '') { bien('el bot sondeó la cola alguna vez', false, 'nunca se lo vio'); }
else {
    $hace = time() - (int)strtotime($visto);
    bien('el bot sondeó hace poco', $hace <= 600, "hace {$hace}s");
}
try {
    /* CUIDADO CON LO QUE MIDE ESTA COLUMNA. La primera version dio MAL con el
       colector andando perfecto: `visto_en` solo se escribia al INSERT, asi que
       MAX(visto_en) era 'cuando aparecio la ultima operacion NUEVA' -- que de
       madrugada, sin movimiento en el panel, son horas. Desde el 16/09/2026 el
       upsert la refresca en cada pasada, que es lo que el nombre de la columna
       dice.

       Se toleran 30 min porque el libro se sincroniza cada 15 (LIBRO_CADA_MIN):
       un margen de una pasada perdida evita que esto grite por nada. */
    $ultLibro = $pdo->query("SELECT MAX(visto_en) FROM operaciones_panel")->fetchColumn();
    $h = $ultLibro ? round((time() - strtotime((string)$ultLibro)) / 60) : null;
    bien('el colector trajo el libro del panel hace poco', $h !== null && $h <= 30,
         $h === null ? 'nunca' : "hace {$h} min");
    $nOps = (int)$pdo->query("SELECT COUNT(*) FROM operaciones_panel")->fetchColumn();
    bien('y el libro tiene operaciones', $nOps > 0, "$nOps filas");
} catch (Throwable $e) { bien('el libro del panel existe', false); }

// ===========================================================================
titulo('4. El circuito de la plata, con un jugador inventado');

/* Un jugador como los de verdad: sale del espejo con id de ganamos. */
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus) VALUES (?,?,0,0,0)")
    ->execute([crc32($U), $U]);
bien('se creó el jugador de prueba', true);

/* 4.1 Pide una recarga, por el mismo camino que el chatbot. */
$monto = 1000;
$r = rl_crear_recarga($pdo, $U, $monto, 'JUGADOR DE PRUEBA', true);
bien('el chat le puede crear una recarga', !empty($r['ok']), json_encode($r));
$REF = (string)($r['referencia'] ?? '');
if (!empty($r['ok'])) {
    printf("        referencia=%s  monto=%s  alias=%s\n", $REF,
           $r['monto'] ?? '?', $r['alias'] ?? '-');
    bien('le dice a dónde transferir', trim((string)($r['alias'] ?? $r['cbu'] ?? '')) !== '');
}

/* 4.2 UN PAGO QUE NO ES SUYO no puede acreditarle nada. Es la mitad que
   importa: un matcher que acredita de más es peor que uno que no acredita. */
$idAjeno = 'zzsim_ajeno_' . time();
$pdo->prepare(
    "INSERT INTO pagos (id_unico, monto, remitente, cuit, cbu_origen, nro_transaccion, estado)
     VALUES (?,?,?,?,?,?,'pendiente')"
)->execute([$idAjeno, $monto + 777, 'OTRA PERSONA DISTINTA', '20999999999',
            '0000000000000000000000', $idAjeno]);
$res = rl_matchear_y_acreditar($pdo, $idAjeno, (float)($monto + 777));
$q = $pdo->prepare("SELECT estado FROM recargas WHERE referencia = ?");
$q->execute([$REF]);
bien('un pago de OTRO monto no le acredita nada',
     $q->fetchColumn() === 'pendiente', json_encode($res));

/* 4.3 El mail del banco de verdad: mismo monto, titular declarado. */
$idBueno = 'zzsim_ok_' . time();
$pdo->prepare(
    "INSERT INTO pagos (id_unico, monto, remitente, cuit, cbu_origen, nro_transaccion, estado)
     VALUES (?,?,?,?,?,?,'pendiente')"
)->execute([$idBueno, $monto, 'JUGADOR DE PRUEBA', '20111111111',
            '1111111111111111111111', $idBueno]);
$res = rl_matchear_y_acreditar($pdo, $idBueno, (float)$monto);
bien('la transferencia se acredita sola',
     ($res['resultado'] ?? '') === 'acreditada', json_encode($res));

$q->execute([$REF]);
bien('la recarga queda acreditada', $q->fetchColumn() === 'acreditada');

/* 4.4 La huella se aprende: es lo que detecta multicuenta después. */
try {
    $h = $pdo->prepare("SELECT COUNT(*) FROM huellas_pagador WHERE usuario = ?");
    $h->execute([$U]);
    bien('se aprendió la huella del pagador (CUIT/CBU)', (int)$h->fetchColumn() > 0,
         'sin esto no se detecta multicuenta');
} catch (Throwable $e) { bien('huellas_pagador', false); }

/* 4.5 El bono de bienvenida. SE IMPRIME, NO SE AFIRMA.

   Aca da CERO y esta bien: el bono se decide por `altas.origen`
   (rl_bono_bienvenida_aplicar) y este jugador no tiene fila en `altas` -- no
   vino de la landing ni del chat, lo invento el simulacro. Uno real si lo
   cobra: el 16/09/2026 holaDiego858 transfirio 500 y se le acreditaron 250.

   La primera version cerraba esto con bien('...se resolvio', true). Afirmar
   con un true fijo sobre algo que siempre da cero no es un chequeo, es un
   adorno que suma un OK al total sin haber mirado nada. */
try {
    $b = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM movimientos
                         WHERE usuario = ? AND tipo = 'bono' AND monto > 0");
    $b->execute([$U]);
    $bono = (float)$b->fetchColumn();
    printf("        bono de bienvenida: %s  (0 es correcto: el de prueba no tiene alta)\n",
           number_format($bono, 0, ',', '.'));
} catch (Throwable $e) { ojo('no pude leer los movimientos del bono'); }

/* 4.6 Un pago repetido NO puede acreditarse dos veces. */
$res2 = rl_matchear_y_acreditar($pdo, $idBueno, (float)$monto);
bien('el MISMO pago no se acredita dos veces',
     ($res2['resultado'] ?? '') !== 'acreditada', json_encode($res2));

// ===========================================================================
titulo('5. Los frenos que protegen la plata');

/* Bloquear al jugador tiene que cortarle todo. */
if (function_exists('vin_bloquear')) {
    vin_bloquear($pdo, $U, true, 'simulacro', 'prueba automatica');
    $rc = rl_crear_recarga($pdo, $U, 1000, 'JUGADOR DE PRUEBA', true);
    bien('un bloqueado no puede pedir una recarga',
         empty($rc['ok']) && ($rc['codigo'] ?? '') === 'bloqueado', json_encode($rc));
    $rr = fichas_pedir_retiro($pdo, $U, 100, 'simulacro');
    bien('un bloqueado no puede retirar',
         empty($rr['ok']) && ($rr['codigo'] ?? '') === 'bloqueado', json_encode($rr));
    vin_bloquear($pdo, $U, false, 'simulacro');
    bien('y se puede desbloquear', !vin_bloqueado($pdo, $U));
} else { ojo('vinculos_lib no está disponible', 'falta desplegar o migración 69'); }

/* Los límites del negocio: que existan y sean coherentes. */
$min = fichas_limite($pdo, 'lim_carga_min', 0);
$max = fichas_limite($pdo, 'lim_carga_max', 0);
bien('los límites de carga son coherentes', $max <= 0 || $min < $max, "min=$min max=$max");
$rr = rl_crear_recarga($pdo, $U, max(1, $min - 1), 'X', true);
bien('una carga por debajo del mínimo se rechaza', empty($rr['ok']), json_encode($rr));

// ===========================================================================
titulo('Resultado');
printf("  %d bien, %d mal, %d para mirar\n", $ok, $mal, $avisos);
if ($mal === 0) {
    echo "\n  El circuito de la plata está sano: se pide, se detecta la\n";
    echo "  transferencia, se acredita una sola vez, se aprende la huella y los\n";
    echo "  frenos cortan. Sin tocar el panel ni gastar un peso.\n";
} else {
    echo "\n  \033[1mHay algo roto arriba.\033[0m Cada línea en MAL dice qué falló.\n";
}
echo "\n  Lo que esto NO prueba: que el bot cree cuentas en el panel ni que los\n";
echo "  depósitos entren en ganamos -- eso cruza el WAF y solo se mide con\n";
echo "  altas reales (scripts/prueba-volumen.php) o mirando scripts/waf.php.\n\n";
exit($mal > 0 ? 1 : 0);
