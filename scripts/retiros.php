<?php
/**
 * retiros.php — Por qué un retiro del CRM "no saca nada".
 *
 * LA CONFUSIÓN QUE LO ORIGINA (Nahuel, 16/09/2026): *"el botón para retirarle
 * fichas a un jugador no funciona"*. Casi siempre no está roto: está esperando.
 *
 * EL BOTÓN NO RETIRA, PIDE. Crea una acción en `acciones_saldo` con
 * `aprobado = 0`, y la cola SOLO entrega retiros con `aprobado = 1`
 * (`acciones_cola.php`). O sea que hasta que alguien lo apruebe en
 * **CRM → Retiros pendientes**, ningún worker lo toca y las fichas siguen ahí.
 *
 * Es deliberado y conviene que siga así: sacarle plata a alguien es una
 * decisión de una persona, nunca de un worker. Pero desde el CRM se ve igual
 * que un bug — apretás, esperás, y no pasa nada.
 *
 * DESPUÉS DE APROBADO SÍ SE EJECUTA SOLO. Lo hace `aprobar_cargas.py`
 * (`una_pasada_retiros`, en cada vuelta del loop, o sea cada minuto) con
 * `POST /api/agent_admin/user/{id}/payment/` y `operation: 1` — capturado de un
 * retiro real de $1 el 13/9/2026, no adivinado: la respuesta trae
 * `from_user_id` = el jugador y `to_user_id` = nosotros.
 *
 * OJO CON EL OTRO WORKER: el de depósitos vive en el repo del bot y, si le cae
 * un retiro, lo manda a 'revisar' con "lo resuelve un agente". Por eso la cola
 * entrega POR TIPO (`?tipo=retirar`) y los dos no se pisan. Leer ese archivo
 * suelto hace concluir que el retiro no está implementado -- me pasó.
 *
 * SOLO LEE. No aprueba nada: para eso está la pantalla.
 *
 *   php /opt/goldpaw/scripts/retiros.php
 *   php /opt/goldpaw/scripts/retiros.php ganamoscrm.online 7
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$dias    = max(1, (int)($argv[2] ?? 3));

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';

function plata($n) { return number_format((float)$n, 2, ',', '.'); }
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 76) . "\n"; }

echo "\nRetiros — " . $dominio . "  (últimos " . $dias . " días)\n";

// ===========================================================================
titulo('1. Esperando TU aprobación (acá suele estar el "no funciona")');
/* aprobado = 0 y todavía vivo. Estas son las que el operador pidió y se
   quedaron esperando: ningún worker las mira. */
$st = $pdo->prepare(
    "SELECT id, usuario, monto, estado, creada_en,
            TIMESTAMPDIFF(MINUTE, creada_en, NOW()) hace
       FROM acciones_saldo
      WHERE tipo = 'retirar' AND aprobado = 0
        AND estado IN ('pendiente', 'procesando')
        AND creada_en >= NOW() - INTERVAL ? DAY
      ORDER BY creada_en DESC"
);
$st->execute([$dias]);
$esperando = $st->fetchAll();
foreach ($esperando as $f) {
    printf("  #%-5d %-22s %10s   pedido hace %d min\n",
           (int)$f['id'], $f['usuario'], plata($f['monto']), (int)$f['hace']);
}
if (!$esperando) {
    echo "  Ninguno. Todo lo pedido ya fue aprobado (o no se pidió nada).\n";
} else {
    printf("\n  \033[1m%d retiro(s) esperando.\033[0m No se están ejecutando y no es una falla:\n",
           count($esperando));
    echo "  el botón PIDE, no retira. Andá a CRM → Retiros pendientes y aprobalos;\n";
    echo "  el worker los ejecuta en la vuelta siguiente (menos de un minuto).\n";
}

// ===========================================================================
titulo('2. Aprobados: qué hizo el worker con ellos');
$st = $pdo->prepare(
    "SELECT id, usuario, monto, estado, intentos, creada_en, ejecutada_en, mensaje
       FROM acciones_saldo
      WHERE tipo = 'retirar' AND aprobado = 1
        AND creada_en >= NOW() - INTERVAL ? DAY
      ORDER BY creada_en DESC LIMIT 30"
);
$st->execute([$dias]);
$aprob = $st->fetchAll();
$trabados = 0;
foreach ($aprob as $f) {
    $mal = in_array((string)$f['estado'], ['error', 'revisar'], true);
    if ($mal) { $trabados++; }
    printf("  #%-5d %-22s %10s   %-10s %s\n",
           (int)$f['id'], $f['usuario'], plata($f['monto']), $f['estado'],
           $f['ejecutada_en'] ? substr((string)$f['ejecutada_en'], 5, 14) : '');
    $m = trim((string)($f['mensaje'] ?? ''));
    if ($m !== '') { printf("        └─ %s\n", mb_substr($m, 0, 74)); }
}
if (!$aprob) { echo "  Ninguno aprobado en el período.\n"; }
elseif ($trabados) {
    printf("\n  \033[1m%d aprobado(s) que NO salieron.\033[0m El motivo está en cada línea.\n", $trabados);
    echo "  Si dice que lo cortó el WAF, vuelve solo a la cola y se reintenta.\n";
}

// ===========================================================================
titulo('3. El libro del panel: lo que de verdad salió');
/* La prueba final, igual que con los depósitos: `operaciones_panel` es el
   registro de la PLATAFORMA. Nuestras tablas dicen lo que quisimos hacer. */
$lim = $pdo->query("SELECT MIN(cuando) a, MAX(cuando) b FROM operaciones_panel WHERE tipo = 1")->fetch();
printf("  El libro de retiros va de %s a %s.\n\n", $lim['a'] ?? '(vacío)', $lim['b'] ?? '(vacío)');

$st = $pdo->prepare(
    "SELECT payment_id, username, monto, comentario, cuando
       FROM operaciones_panel
      WHERE tipo = 1 AND cuando >= NOW() - INTERVAL ? DAY
      ORDER BY cuando DESC LIMIT 30"
);
$st->execute([$dias]);
$hay = 0; $total = 0.0;
foreach ($st as $f) {
    $hay++; $total += (float)$f['monto'];
    $com = trim((string)$f['comentario']);
    printf("  %s  %-22s %10s   %s\n",
           substr((string)$f['cuando'], 5, 14), $f['username'], plata($f['monto']),
           $com === '' ? 'lo pidió el jugador' : $com);
}
if (!$hay) { echo "  Ningún retiro ejecutado en el período.\n"; }
else { printf("\n  %d retiro(s), $%s en total.\n", $hay, plata($total)); }

// ===========================================================================
titulo('4. Pedidos desde ADENTRO del juego (los que no pasan por el CRM)');
/* Otra cola, otra pantalla, y la que más volumen tiene. El botón de retirar de
   la plataforma no toca `acciones_saldo`: la solicitud vive del lado de ganamos
   y de este lado solo queda el espejo. Se resuelven EN EL PANEL. */
try {
    $st = $pdo->prepare(
        "SELECT request_id, username, monto, estado, primera_vez
           FROM retiros_panel
          WHERE estado <> 'cerrado' AND primera_vez >= NOW() - INTERVAL ? DAY
          ORDER BY primera_vez DESC LIMIT 20"
    );
    $st->execute([$dias]);
    $hay = 0;
    foreach ($st as $f) {
        $hay++;
        printf("  %s  %-22s %10s   %s  [%d]\n",
               substr((string)$f['primera_vez'], 5, 14), $f['username'],
               plata($f['monto']), $f['estado'], (int)$f['request_id']);
    }
    if (!$hay) { echo "  Ninguno abierto.\n"; }
    else { echo "\n  Estos se resuelven EN EL PANEL de ganamos, no acá.\n"; }
} catch (Throwable $e) {
    echo "  (sin espejo de retiros del juego: falta la migración 64)\n";
}

echo "\n";
echo "EL CIRCUITO, EN ORDEN\n";
echo str_repeat('-', 76) . "\n";
echo "  1. Botón «Retirar» en la ficha  ->  queda PEDIDO, no retira nada\n";
echo "  2. CRM → Retiros pendientes     ->  lo aprobás vos (decisión humana)\n";
echo "  3. El worker lo ejecuta          ->  le saca las fichas en ganamos\n";
echo "  4. La transferencia al banco     ->  la hacés vos, siempre\n\n";
echo "  El paso 2 es el que falta cuando «no funciona». El 4 no lo hace nadie\n";
echo "  automáticamente y nunca lo hizo.\n\n";
