<?php
/**
 * altas-estado.php — Por qué un alta pedida por el chat no termina de crearse.
 *
 * LA PREGUNTA (Nahuel, 16/09/2026): el bot contestó *"ya te estoy creando la
 * cuenta con el usuario Javierso"* y la cuenta **nunca apareció**.
 *
 * Que el chat conteste eso NO significa que la cuenta se esté creando: el
 * chatbot solo ENCOLA (`altas`, estado 'pendiente') y contesta. El que la crea
 * de verdad es el bot de Playwright, que sondea esa cola y llena el formulario
 * del panel de agentes. O sea que entre "ya te la estoy creando" y la cuenta
 * hay un segundo sistema, y este script mira ese tramo.
 *
 * LAS TRES FORMAS DE FALLAR, que se distinguen mirando dos números juntos:
 *
 *   latido viejo  + cola creciendo  -> EL BOT ESTÁ CAÍDO. No sondea. Nada se
 *                                      va a crear hasta que vuelva.
 *   latido fresco + cola creciendo  -> el bot vive y el PANEL le rechaza el
 *                                      trabajo: sesión caída, credenciales,
 *                                      challenge del WAF. El `mensaje` de cada
 *                                      fila dice cuál.
 *   latido fresco + cola vacía      -> el alta ni siquiera se encoló. El
 *                                      problema está antes, en el chatbot.
 *
 * Uno solo de los dos números no alcanza, y por eso se imprimen juntos: "hay 8
 * altas en cola" es lo mismo con el bot muerto que con el bot peleándose con el
 * panel, y son dos arreglos distintos.
 *
 * SOLO LEE. No destraba nada: para eso está el botón de reintentar del CRM.
 *
 *   php /opt/goldpaw/scripts/altas-estado.php
 *   php /opt/goldpaw/scripts/altas-estado.php ganamoscrm.online 25
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$cuantas = max(1, (int)($argv[2] ?? 15));

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/config_crm.php';

function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 78) . "\n"; }

/* MINUTOS_ZOMBIE se LEE de altas_cola.php en vez de copiarlo. Es el numero que
   decide cuando un alta colgada vuelve sola a la cola, y es justo el que hace
   que este script diga "esta trabada" o "va a reintentar sola": copiarlo aca
   significaria que el dia que alguien lo cambie, el diagnostico empiece a
   mentir sin que nada falle. */
$ZOMBIE = 15;
$backoffTxt = ['5', '20', '60'];
try {
    $srcCola = @file_get_contents($API . '/altas_cola.php');
    if ($srcCola && preg_match('/MINUTOS_ZOMBIE\s*=\s*(\d+)/', $srcCola, $m)) {
        $ZOMBIE = (int)$m[1];
    }
    if ($srcCola && preg_match('/MINUTOS_BACKOFF\s*=\s*\[([0-9,\s]+)\]/', $srcCola, $m)) {
        $backoffTxt = array_map('trim', explode(',', $m[1]));
    }
} catch (Throwable $e) {}
define('MINUTOS_BACKOFF_TXT', $backoffTxt);
function corto($s, $n = 58) { $s = trim((string)$s); return $s === '' ? '' : mb_substr($s, 0, $n); }

/* Segundos a algo legible. En segundos hasta el minuto porque es la escala en
   la que pasa todo: un alta sana sale en 5-20 s y una que peleo contra el WAF
   tarda minutos. Decir "0 min" y "3 min" esconde justo la diferencia. */
function dur($seg) {
    if ($seg === null) { return '—'; }
    $seg = (int)$seg;
    if ($seg < 90)   { return $seg . 's'; }
    if ($seg < 5400) { return round($seg / 60) . 'min'; }
    return round($seg / 3600, 1) . 'h';
}

echo "\nCola de altas — " . $dominio . "\n";

// ===========================================================================
titulo('1. ¿El bot está vivo?');
/* El latido lo escribe `altas_cola.php` en CADA sondeo del bot (cada ~3 s), no
   cuando crea una cuenta: separa "está corriendo" de "está logrando algo", que
   es justo la distinción que hace falta.

   PERO EL BOT NO SONDEA MIENTRAS TRABAJA, y por eso los umbrales de acá abajo
   son anchos. La primera corrida de este script (16/09/2026) dio "DUDOSO" con
   65 segundos de silencio mientras el bot estaba creando cuentas sin problema:
   estaba esperando un formulario que no cargaba, con Playwright bloqueado. Una
   verificación contra el listado del panel ya tarda 15-20 s, y un timeout de
   Playwright son 30 s más. Un monitor que grita cada vez que el bot está
   ocupado enseña a ignorarlo, que es peor que no tenerlo. */
$visto = '';
try { $visto = trim((string)cfg_crm($pdo, 'bot_altas_visto_en')); } catch (Throwable $e) {}

if ($visto === '') {
    echo "  \033[1mNUNCA se lo vio.\033[0m O el bot no arrancó nunca, o está corriendo una\n";
    echo "  imagen vieja, anterior al latido. Mirá que el contenedor exista:\n";
    echo "      docker ps --filter name=ganamos-bot\n";
} else {
    $hace = time() - (int)strtotime($visto);
    printf("  Último sondeo: %s  (hace %s)\n", $visto,
           $hace < 120 ? $hace . ' seg' : round($hace / 60) . ' min');
    if ($hace <= 120) {
        echo "  \033[1m>> VIVO.\033[0m Sondeando, o trabajando en un alta (ahí no sondea).\n";
    } elseif ($hace <= 600) {
        echo "  \033[1m>> DUDOSO.\033[0m Más de lo que tarda un alta, incluso fallando.\n"
           . "     Mirá la sección 3: si hay una en 'procesando', está peleándola.\n";
    } else {
        echo "  \033[1m>> CAÍDO o en crash-loop.\033[0m Nada se va a crear hasta que vuelva,\n"
           . "     y tampoco se destraba solo lo que quedó colgado: el rescate de\n"
           . "     zombies corre DENTRO del sondeo del bot.\n"
           . "     docker ps --filter name=ganamos-bot\n"
           . "     docker logs --tail 50 ganamos-bot-creador\n";
    }
}

// ===========================================================================
titulo('2. Qué hay en la cola AHORA');
/* ESPERAR EL BACKOFF NO ES ESTAR TRABADA, y confundirlos manda a buscar el
   problema donde no está. Esta sección lo diagnosticó mal dos veces (16/09/2026)
   antes de quedar así: la segunda vez dijo "el bot no está sondeando" con el bot
   contestando hacía 16 segundos.

   Una pendiente vieja puede ser DOS cosas opuestas:

     con `proximo_intento_en` en el futuro  -> falló y está cumpliendo su espera
                                               antes del próximo intento. Es el
                                               diseño funcionando.
     sin fecha de reintento, y vieja        -> nadie la tomó. ESO sí es que el
                                               bot no está mirando la cola.

   Y una 'procesando' vieja es otra cosa más: un zombie, que el rescate devuelve
   a la cola a los MINUTOS_ZOMBIE. Ese rescate corre DENTRO del sondeo del bot,
   así que si pasó el plazo y sigue ahí, el bot no está sondeando.

   Tres estados, tres causas, tres arreglos. Por eso se cuentan por separado. */
$st = $pdo->query(
    "SELECT
       SUM(estado = 'procesando')                                        AS proc,
       MAX(CASE WHEN estado = 'procesando'
                THEN TIMESTAMPDIFF(MINUTE, COALESCE(tomado_en, pedido_en), NOW()) END) AS proc_min,
       SUM(estado = 'pendiente' AND proximo_intento_en > NOW())          AS esperando,
       MIN(CASE WHEN estado = 'pendiente' AND proximo_intento_en > NOW()
                THEN proximo_intento_en END)                             AS proximo,
       MAX(CASE WHEN estado = 'pendiente' AND proximo_intento_en > NOW()
                THEN TIMESTAMPDIFF(MINUTE, NOW(), proximo_intento_en) END) AS espera_max,
       SUM(estado = 'pendiente' AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW())) AS listas,
       MAX(CASE WHEN estado = 'pendiente' AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW())
                THEN TIMESTAMPDIFF(MINUTE, pedido_en, NOW()) END)        AS listas_min
     FROM altas
     WHERE estado IN ('pendiente', 'procesando') AND password IS NOT NULL"
);
$c = $st->fetch() ?: [];
$proc      = (int)($c['proc'] ?? 0);
$esperando = (int)($c['esperando'] ?? 0);
$listas    = (int)($c['listas'] ?? 0);
$enCola    = $proc + $esperando + $listas;

printf("  en el panel ahora mismo      %3d%s\n", $proc,
       $proc ? '   (hace ' . (int)$c['proc_min'] . ' min)' : '');
printf("  esperando su reintento       %3d%s\n", $esperando,
       $esperando ? '   (el próximo, ' . substr((string)$c['proximo'], 11, 5)
                  . '; el que más espera, ' . (int)$c['espera_max'] . ' min)' : '');
printf("  listas para que las tome     %3d%s\n", $listas,
       $listas ? '   (la más vieja hace ' . (int)$c['listas_min'] . ' min)' : '');

if ($enCola === 0) {
    echo "\n  Vacía: no hay ningún alta esperando.\n";
    echo "\n  OJO CON ESTE CASO. Si el jugador está esperando y la cola está vacía,\n";
    echo "  el alta NO se encoló: el problema está ANTES, en el chatbot, y esto\n";
    echo "  no lo va a mostrar. Buscá su nombre en la sección 3; si tampoco está,\n";
    echo "  la herramienta crear_cuenta nunca llegó a correr.\n";
}
/* El orden de los diagnósticos va de lo más grave a lo más benigno: si el bot
   no toma nada, lo demás es consecuencia y no hace falta leerlo. */
if ($listas > 0 && (int)$c['listas_min'] > 2) {
    printf("\n  \033[1m>> HAY ALTAS LISTAS QUE NADIE TOMA\033[0m (la más vieja, %d min).\n",
           (int)$c['listas_min']);
    echo "  El bot sondea cada pocos segundos: esto solo pasa si no está mirando\n";
    echo "  la cola. Volvé a la sección 1 y mirá los logs del contenedor.\n";
}
if ($proc > 0 && (int)$c['proc_min'] > $ZOMBIE) {
    printf("\n  \033[1m>> UNA QUEDÓ COLGADA\033[0m en el panel hace %d min, más que los %d del\n",
           (int)$c['proc_min'], $ZOMBIE);
    echo "  rescate automático. Ese rescate corre DENTRO del sondeo del bot, así\n";
    echo "  que si no se disparó es porque el bot no está sondeando.\n";
}
if ($esperando > 0) {
    /* ESTO NO ES UNA FALLA: es el backoff haciendo lo suyo. Pero sí es la
       respuesta a "el jugador dice que no le llega": le va a llegar, y cuándo. */
    printf("\n  %d esperando reintento NO es una falla: fallaron una vez y el sistema\n", $esperando);
    printf("  espacia los intentos a propósito (%s min) para no pegarle al WAF\n",
           implode(', ', MINUTOS_BACKOFF_TXT));
    echo "  todavía caliente. El motivo de cada una está en la sección 3.\n";
    if ((int)$c['espera_max'] > 20) {
        printf("  \033[1mPero una espera %d min:\033[0m para un jugador parado en el chat eso es\n",
               (int)$c['espera_max']);
        echo "  no llegar nunca. Si está esperando, conviene crearla a mano en el panel.\n";
    }
}

// ===========================================================================
titulo('3. Las últimas ' . $cuantas . ' altas');
/* `mensaje` es lo que informó el bot en su último intento, y es LO ÚNICO que
   dice por qué no salió. `intentos` contra el máximo (10) dice si todavía le
   quedan chances o ya se rindió. */
try {
    $st = $pdo->prepare(
        "SELECT usuario, estado, intentos, origen, pedido_en, tomado_en, hecho_en,
                mensaje, creado_en_panel, proximo_intento_en,
                TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) AS tardo
           FROM altas ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $cuantas, PDO::PARAM_INT);
    $st->execute();
    $filas = $st->fetchAll();
} catch (Throwable $e) {
    // creado_en_panel es de la migración 36; proximo_intento_en, de la 37.
    $st = $pdo->prepare(
        "SELECT usuario, estado, intentos, origen, pedido_en, tomado_en, hecho_en,
                mensaje, NULL creado_en_panel, NULL proximo_intento_en,
                TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) AS tardo
           FROM altas ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $cuantas, PDO::PARAM_INT);
    $st->execute();
    $filas = $st->fetchAll();
}

foreach ($filas as $f) {
    /* El tiempo que tardo va en la MISMA linea que el nombre: "salio" y "salio
       en 4 segundos" son dos respuestas distintas, y la segunda es la que se
       pregunta cuando un jugador se queja de que espero. Solo tiene sentido en
       las que terminaron: en una que sigue esperando seria la edad, no la
       duracion, y confundir las dos hace que una cola trabada parezca lenta. */
    printf("  %-22s %-11s int:%-2d %-9s %-14s %s\n",
           corto($f['usuario'], 22), $f['estado'], (int)$f['intentos'],
           $f['origen'], substr((string)$f['pedido_en'], 5, 14),
           $f['hecho_en'] ? 'tardó ' . dur($f['tardo']) : '');
    if (trim((string)$f['mensaje']) !== '') {
        printf("      └─ %s\n", corto($f['mensaje'], 70));
    }
    if ($f['estado'] === 'ok' && !$f['creado_en_panel']) {
        echo "      └─ \033[1mOJO: marcada 'ok' pero NO confirmada en el panel.\033[0m\n";
    }
    if ($f['proximo_intento_en'] && $f['estado'] === 'pendiente') {
        printf("      └─ no se reintenta hasta %s\n", substr((string)$f['proximo_intento_en'], 5, 14));
    }
}
if (!$filas) { echo "  (ninguna)\n"; }

// ===========================================================================
titulo('4. Las que se rindieron');
/* estado='error' con los 10 intentos gastados: el bot no las va a volver a
   tomar solo. Son las que hay que destrabar a mano desde el CRM. */
$st = $pdo->query(
    "SELECT usuario, intentos, mensaje, pedido_en
       FROM altas
      WHERE estado = 'error' AND pedido_en >= NOW() - INTERVAL 3 DAY
      ORDER BY id DESC LIMIT 20"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  %-22s %d intentos   %s\n", corto($f['usuario'], 22), (int)$f['intentos'],
           substr((string)$f['pedido_en'], 5, 14));
    if (trim((string)$f['mensaje']) !== '') { printf("      └─ %s\n", corto($f['mensaje'], 70)); }
}
if (!$hay) { echo "  Ninguna en los últimos 3 días.\n"; }

// ===========================================================================
titulo('5. Cuántas salieron y CUÁNTO TARDARON, por hora (últimas 12 h)');
/* La curva dice si esto empezó ahora o viene de antes, que cambia dónde mirar:
   un corte limpio a una hora tiene causa (un deploy, un reinicio); una caída
   gradual es la sesión del panel muriéndose.

   Y el tiempo va al lado de la cantidad porque "salieron todas" puede ser una
   respuesta tranquilizadora y falsa: si el promedio pasó de 8 segundos a 4
   minutos, las altas SALEN y el negocio igual se está perdiendo gente que no
   espera tanto. El máximo se muestra aparte del promedio a propósito -- con
   una que tardó 15 minutos entre veinte de 5 segundos, el promedio no se
   mueve y es justo la que hace que alguien se vaya. */
$st = $pdo->query(
    "SELECT DATE_FORMAT(pedido_en, '%d/%m %Hh') hora,
            SUM(estado = 'ok') ok,
            SUM(estado = 'error') err,
            SUM(estado IN ('pendiente','procesando')) esperando,
            AVG(CASE WHEN estado = 'ok' AND hecho_en IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) END) prom,
            MAX(CASE WHEN estado = 'ok' AND hecho_en IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) END) peor
       FROM altas
      WHERE pedido_en >= NOW() - INTERVAL 12 HOUR
      GROUP BY hora ORDER BY hora"
);
$hay = 0; $promGlobal = []; $peorGlobal = 0;
foreach ($st as $f) {
    $hay++;
    if ($f['prom'] !== null) { $promGlobal[] = (float)$f['prom']; }
    $peorGlobal = max($peorGlobal, (int)$f['peor']);
    printf("  %-12s ok:%-3d error:%-3d esperando:%-3d  prom %-6s peor %-6s %s\n",
           $f['hora'], (int)$f['ok'], (int)$f['err'], (int)$f['esperando'],
           $f['prom'] !== null ? dur((int)round((float)$f['prom'])) : '—',
           $f['peor'] !== null ? dur((int)$f['peor']) : '—',
           str_repeat('#', min(24, (int)$f['ok'])));
}
if (!$hay) {
    echo "  No se pidió ningún alta en las últimas 12 h.\n";
} else {
    $p = $promGlobal ? array_sum($promGlobal) / count($promGlobal) : null;
    printf("\n  En promedio: %s.   La que más tardó: %s.\n",
           $p !== null ? dur((int)round($p)) : '—', $peorGlobal ? dur($peorGlobal) : '—');
    echo "  Referencia: con todo sano un alta sale en 5-20 segundos -- lo que\n";
    echo "  tarda es el viaje al panel, no la cola. De un minuto para arriba ya\n";
    echo "  es el bot peleando (challenge del WAF, sesión, reintentos).\n";
}

echo "\n";
