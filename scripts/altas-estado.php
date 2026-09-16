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
function corto($s, $n = 58) { $s = trim((string)$s); return $s === '' ? '' : mb_substr($s, 0, $n); }

echo "\nCola de altas — " . $dominio . "\n";

// ===========================================================================
titulo('1. ¿El bot está vivo?');
/* El latido lo escribe `altas_cola.php` en CADA sondeo del bot (cada ~3 s), no
   cuando crea una cuenta: separa "está corriendo" de "está logrando algo", que
   es justo la distinción que hace falta. */
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
    if ($hace <= 30)      { echo "  \033[1m>> VIVO.\033[0m Está sondeando la cola normalmente.\n"; }
    elseif ($hace <= 300) { echo "  \033[1m>> DUDOSO.\033[0m Sondea cada ~3 s: este silencio ya es raro.\n"; }
    else                  { echo "  \033[1m>> CAÍDO o en crash-loop.\033[0m Nada se va a crear hasta que vuelva.\n"
                               . "     docker ps --filter name=ganamos-bot\n"
                               . "     docker logs --tail 50 ganamos-bot-creador\n"; }
}

// ===========================================================================
titulo('2. Qué hay en la cola AHORA');
$st = $pdo->query(
    "SELECT estado, COUNT(*) n,
            MAX(TIMESTAMPDIFF(MINUTE, pedido_en, NOW())) mas_vieja
       FROM altas
      WHERE estado IN ('pendiente', 'procesando')
      GROUP BY estado"
);
$enCola = 0; $masVieja = 0;
foreach ($st as $f) {
    $enCola += (int)$f['n'];
    $masVieja = max($masVieja, (int)$f['mas_vieja']);
    printf("  %-12s %3d   la más vieja hace %d min\n", $f['estado'], $f['n'], (int)$f['mas_vieja']);
}
if ($enCola === 0) {
    echo "  Vacía: no hay ningún alta esperando.\n";
    echo "\n  OJO CON ESTE CASO. Si el jugador está esperando y la cola está vacía,\n";
    echo "  el alta NO se encoló: el problema está ANTES, en el chatbot, y esto\n";
    echo "  no lo va a mostrar. Buscá su nombre en la sección 3; si tampoco está,\n";
    echo "  la herramienta crear_cuenta nunca llegó a correr.\n";
} elseif ($masVieja > 5) {
    echo "\n  \033[1m>> Hay altas trabadas.\033[0m Con el bot vivo esto tiene que ser ~0.\n";
    echo "  El motivo de cada una está en la columna `mensaje` de la sección 3.\n";
}

// ===========================================================================
titulo('3. Las últimas ' . $cuantas . ' altas');
/* `mensaje` es lo que informó el bot en su último intento, y es LO ÚNICO que
   dice por qué no salió. `intentos` contra el máximo (10) dice si todavía le
   quedan chances o ya se rindió. */
try {
    $st = $pdo->prepare(
        "SELECT usuario, estado, intentos, origen, pedido_en, tomado_en, hecho_en,
                mensaje, creado_en_panel, proximo_intento_en
           FROM altas ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $cuantas, PDO::PARAM_INT);
    $st->execute();
    $filas = $st->fetchAll();
} catch (Throwable $e) {
    // creado_en_panel es de la migración 36; proximo_intento_en, de la 37.
    $st = $pdo->prepare(
        "SELECT usuario, estado, intentos, origen, pedido_en, tomado_en, hecho_en,
                mensaje, NULL creado_en_panel, NULL proximo_intento_en
           FROM altas ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $cuantas, PDO::PARAM_INT);
    $st->execute();
    $filas = $st->fetchAll();
}

foreach ($filas as $f) {
    printf("  %-22s %-11s int:%-2d %-9s %s\n",
           corto($f['usuario'], 22), $f['estado'], (int)$f['intentos'],
           $f['origen'], substr((string)$f['pedido_en'], 5, 14));
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
titulo('5. Salieron o no, por hora (últimas 12 h)');
/* La curva dice si esto empezó ahora o viene de antes, que cambia dónde mirar:
   un corte limpio a una hora tiene causa (un deploy, un reinicio); una caída
   gradual es la sesión del panel muriéndose. */
$st = $pdo->query(
    "SELECT DATE_FORMAT(pedido_en, '%d/%m %Hh') hora,
            SUM(estado = 'ok') ok,
            SUM(estado = 'error') err,
            SUM(estado IN ('pendiente','procesando')) esperando
       FROM altas
      WHERE pedido_en >= NOW() - INTERVAL 12 HOUR
      GROUP BY hora ORDER BY hora"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  %-12s ok:%-3d error:%-3d esperando:%-3d %s\n",
           $f['hora'], (int)$f['ok'], (int)$f['err'], (int)$f['esperando'],
           str_repeat('#', min(30, (int)$f['ok'])));
}
if (!$hay) { echo "  No se pidió ningún alta en las últimas 12 h.\n"; }

echo "\n";
