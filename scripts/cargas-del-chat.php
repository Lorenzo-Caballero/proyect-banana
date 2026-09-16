<?php
/**
 * cargas-del-chat.php — Por qué algunas cargas pedidas por el chat no se
 * acreditaron solas.
 *
 * PARA QUÉ. La queja es "algunas cargas no se efectuaron solas". Eso puede ser
 * tres cosas MUY distintas, y desde afuera se ven igual:
 *
 *   1. el jugador nunca transfirió          -> la recarga vence y está bien
 *   2. transfirió TARDE                     -> el matcher ya no la ve
 *   3. transfirió a tiempo pero no se pudo  -> el pago queda en `revision`
 *      identificar de quién era                y alguien lo asigna a mano
 *
 * Los tres terminan en "el jugador escribió al CRM y se lo cargaron", así que
 * la única forma de distinguirlos es mirar los tiempos. Eso hace esto.
 *
 * LA MECÁNICA QUE HAY QUE TENER EN LA CABEZA para leer la salida: una recarga
 * vive RL_VENCIMIENTO_MIN (45) minutos, y el matcher SOLO mira recargas en
 * `pendiente` (ver rl_matchear_y_acreditar: `WHERE estado='pendiente' AND ...`).
 * Pasados los 45 minutos, rl_vencer() la marca 'vencida' y deja de existir para
 * el matcher PARA SIEMPRE. Si la transferencia entra al minuto 50, el mail
 * llega igual, el pago se captura igual -- y no tiene con qué casar. Cae en
 * `revision` y lo resuelve una persona.
 *
 * O sea que "no se efectuó sola" casi nunca es una falla del matcher: es una
 * transferencia que llegó fuera de la ventana. La sección 3 lo mide.
 *
 * SOLO LEE. No escribe una sola fila.
 *
 *   php /opt/goldpaw/scripts/cargas-del-chat.php
 *   php /opt/goldpaw/scripts/cargas-del-chat.php ganamoscrm.online 7
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$dias    = max(1, (int)($argv[2] ?? 3));

/* Mismo camino que la API para resolver la base del cliente: en CLI no hay
   request, así que el host se pone a mano. */
$_SERVER['HTTP_HOST'] = $dominio;

$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/recargas_lib.php';

$desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

function plata($n) { return number_format((float)$n, 2, ',', '.'); }
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 72) . "\n"; }

echo "\nCargas pedidas por el chat — " . $dominio
   . "  (desde " . substr($desde, 0, 10) . ", " . $dias . " día" . ($dias == 1 ? '' : 's') . ")\n";
echo "Ventana de una recarga: " . RL_VENCIMIENTO_MIN . " min.\n";

// ===========================================================================
titulo('1. En qué terminó cada recarga');
/* `origen` no existe en `recargas`: todas las filas de esta tabla vienen del
   camino B (chatbot / landing). Las del botón «Depósitos» del juego no dejan
   fila acá -- ver CLAUDE.md, «hay UNA definición de una carga». */
$st = $pdo->prepare(
    "SELECT estado, COUNT(*) n, SUM(monto_pedido) total
       FROM recargas
      WHERE creada_en >= ?
      GROUP BY estado
      ORDER BY n DESC"
);
$st->execute([$desde]);
$tot = 0;
foreach ($st as $f) {
    $tot += (int)$f['n'];
    printf("  %-12s %4d   $%s\n", $f['estado'], $f['n'], plata($f['total']));
}
if ($tot === 0) { echo "  (ninguna)\n"; }
echo "\n  'vencida' = se pidió y no entró plata en los " . RL_VENCIMIENTO_MIN . " min.\n";
echo "  No es un error por sí solo: el que se arrepintió también cae acá.\n";
echo "  Lo que hay que mirar es si DESPUÉS entró la transferencia (sección 3).\n";

// ===========================================================================
titulo('2. Las que se acreditaron: ¿solas o a mano?');
/* `pagos.asignado_por` (migración 34) se escribe SOLO cuando un operador la
   asigna desde Comprobantes. Si está NULL, la cerró el matcher. Esta es la
   respuesta directa a la pregunta: cuántas hubo que hacer a mano. */
$st = $pdo->prepare(
    "SELECT r.referencia, r.usuario, r.monto_pedido, r.creada_en, r.acreditada_en,
            p.asignado_por, p.capturado_en,
            TIMESTAMPDIFF(MINUTE, r.creada_en, p.capturado_en) AS tardo_min
       FROM recargas r
       LEFT JOIN pagos p ON p.id_unico = r.pago_id
      WHERE r.estado = 'acreditada' AND r.creada_en >= ?
      ORDER BY r.creada_en DESC"
);
$st->execute([$desde]);
$filas = $st->fetchAll();
$solas = 0; $mano = 0;
foreach ($filas as $f) { if (trim((string)$f['asignado_por']) !== '') { $mano++; } else { $solas++; } }
printf("  Acreditadas solas (el matcher): %d\n", $solas);
printf("  Acreditadas a mano:             %d\n", $mano);
if ($mano > 0) {
    echo "\n  Las de a mano, una por una:\n";
    foreach ($filas as $f) {
        if (trim((string)$f['asignado_por']) === '') { continue; }
        printf("    %-10s %-16s $%-10s pedida %s, la asignó %s\n",
               $f['referencia'], $f['usuario'], plata($f['monto_pedido']),
               substr((string)$f['creada_en'], 5, 11), $f['asignado_por']);
        if ($f['tardo_min'] !== null) {
            printf("               la transferencia entró %d min después de pedirla%s\n",
                   (int)$f['tardo_min'],
                   (int)$f['tardo_min'] > RL_VENCIMIENTO_MIN ? "  << FUERA DE LA VENTANA" : "");
        }
    }
}

// ===========================================================================
titulo('3. Transferencias que llegaron TARDE (la causa más probable)');
/* El cruce que explica el síntoma: una recarga vencida y, después, un pago del
   mismo importe. El matcher no pudo verla porque ya no estaba en 'pendiente'.
   Se compara por el importe redondeado a centavos, igual que rl_matchear_y_acreditar. */
$st = $pdo->prepare(
    "SELECT r.referencia, r.usuario, r.monto_pedido, r.creada_en, r.vence_en,
            p.id_unico, p.remitente, p.estado AS pago_estado, p.capturado_en,
            p.asignado_por,
            TIMESTAMPDIFF(MINUTE, r.creada_en, p.capturado_en) AS tardo_min
       FROM recargas r
       JOIN pagos p
         ON ROUND(p.monto * 100) = ROUND(r.monto_pedido * 100)
        AND p.capturado_en > r.vence_en
        AND p.capturado_en < r.vence_en + INTERVAL 12 HOUR
      WHERE r.estado = 'vencida' AND r.creada_en >= ?
      ORDER BY r.creada_en DESC"
);
$st->execute([$desde]);
$tarde = $st->fetchAll();
if (!$tarde) {
    echo "  Ninguna. Las vencidas de arriba son gente que no transfirió.\n";
} else {
    printf("  %d recarga(s) vencida(s) con una transferencia del mismo importe DESPUÉS.\n\n",
           count($tarde));
    foreach ($tarde as $f) {
        printf("    %-10s %-16s $%-10s vencía %s, pagó %d min tarde\n",
               $f['referencia'], $f['usuario'], plata($f['monto_pedido']),
               substr((string)$f['vence_en'], 5, 11), (int)$f['tardo_min']);
        printf("               pago %s de \"%s\" -> quedó en '%s'%s\n",
               $f['id_unico'], trim((string)$f['remitente']) ?: '(sin titular)',
               $f['pago_estado'],
               trim((string)$f['asignado_por']) !== ''
                   ? ', lo asignó ' . $f['asignado_por'] : '');
    }
    echo "\n  OJO: esto es una coincidencia por importe, no una prueba. Dos personas\n";
    echo "  transfiriendo el mismo número el mismo día se ven igual. Sirve para\n";
    echo "  saber DÓNDE mirar, no para dar nada por acreditado.\n";
}

// ===========================================================================
titulo('4. Plata sin resolver AHORA MISMO');
/* Lo único de todo el script que es urgente: un pago en 'revision' es plata que
   entró y todavía no es de nadie. */
$st = $pdo->query(
    "SELECT id_unico, monto, remitente, estado, capturado_en,
            TIMESTAMPDIFF(MINUTE, capturado_en, NOW()) AS hace_min
       FROM pagos
      WHERE estado IN ('revision', 'pendiente')
      ORDER BY capturado_en DESC
      LIMIT 30"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  %-8s $%-10s %-26s hace %d min  [%s]\n",
           $f['estado'], plata($f['monto']),
           mb_substr(trim((string)$f['remitente']) ?: '(sin titular)', 0, 26),
           (int)$f['hace_min'], $f['id_unico']);
}
if (!$hay) { echo "  Nada pendiente. Toda la plata que entró está asignada.\n"; }

// ===========================================================================
titulo('5. Recargas pedidas y todavía vivas');
$st = $pdo->query(
    "SELECT referencia, usuario, monto_pedido, titular_declarado,
            TIMESTAMPDIFF(MINUTE, NOW(), vence_en) AS le_queda
       FROM recargas
      WHERE estado = 'pendiente' AND vence_en > NOW()
      ORDER BY creada_en DESC"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  %-10s %-16s $%-10s le quedan %d min%s\n",
           $f['referencia'], $f['usuario'], plata($f['monto_pedido']),
           (int)$f['le_queda'],
           trim((string)$f['titular_declarado']) !== ''
               ? '  (declaró: ' . $f['titular_declarado'] . ')' : '');
}
if (!$hay) { echo "  Ninguna esperando pago.\n"; }

// ===========================================================================
titulo('6. Las vencidas, POR JUGADOR (el número que importa)');
/* "38 vencidas" no son 38 personas que se fueron: el que pide, se distrae y
   vuelve a pedir cuenta tres veces. Medido el 16/09/2026, holaceleste923 tenía
   tres pedidos de $2.000 en una hora y UNA sola transferencia. Contar filas
   infla el problema y esconde el único dato que decide si hay algo que
   arreglar: de los que pidieron y se les venció, ¿cuántos terminaron cargando
   igual?

   "Cargó" se pregunta con publicidad_sql_cargas(), que es LA definición única
   de una carga en todo el CRM (ver CLAUDE.md). Es a propósito: el que abandona
   el chat y paga con el botón «Depósitos» de adentro del juego NO deja fila en
   `recargas`, y mirando solo esa tabla figuraría como perdido cuando en
   realidad cargó por la otra puerta. */
require_once $API . '/publicidad_lib.php';

$st = $pdo->prepare(
    "SELECT usuario, COUNT(*) pedidos, SUM(monto_pedido) total,
            MIN(creada_en) primera, MAX(monto_pedido) mayor
       FROM recargas
      WHERE estado = 'vencida' AND creada_en >= ?
      GROUP BY usuario
      ORDER BY total DESC"
);
$st->execute([$desde]);
$venc = $st->fetchAll();

/* Se pregunta jugador por jugador y no de una: son unas pocas decenas de filas
   en un script de diagnóstico, y una consulta por jugador se lee de un vistazo. */
$qCargo = $pdo->prepare(
    "SELECT COUNT(*) n, COALESCE(SUM(c.monto), 0) m
       FROM (" . publicidad_sql_cargas() . ") c
      WHERE c.usuario = ? AND c.cuando >= ?"
);

$recuperados = []; $perdidos = [];
foreach ($venc as $v) {
    /* Desde SU primer pedido vencido, no desde el inicio del período: una carga
       ANTERIOR no dice nada sobre el pedido que se le venció después. */
    $qCargo->execute([$v['usuario'], $v['primera']]);
    $c = $qCargo->fetch();
    $v['cargo_n'] = (int)$c['n'];
    $v['cargo_m'] = (float)$c['m'];
    if ($v['cargo_n'] > 0) { $recuperados[] = $v; } else { $perdidos[] = $v; }
}

printf("  %d pedidos vencidos = %d jugador(es) distinto(s).\n\n",
       (int)array_sum(array_column($venc, 'pedidos')), count($venc));

printf("  \033[1mSe les venció pero cargaron igual: %d\033[0m\n", count($recuperados));
foreach ($recuperados as $v) {
    printf("    %-22s %d vencido(s), después cargó %d vez/veces  $%s\n",
           $v['usuario'], (int)$v['pedidos'], $v['cargo_n'], plata($v['cargo_m']));
}
if (!$recuperados) { echo "    (ninguno)\n"; }

printf("\n  \033[1mPidieron y NUNCA cargaron: %d\033[0m\n", count($perdidos));
$plataPerdida = 0;
foreach ($perdidos as $v) {
    $plataPerdida += (float)$v['mayor'];
    printf("    %-22s %d pedido(s), el mayor de $%s  (primero: %s)\n",
           $v['usuario'], (int)$v['pedidos'], plata($v['mayor']),
           substr((string)$v['primera'], 5, 11));
}
if ($perdidos) {
    printf("\n  Intención declarada y no concretada: $%s.\n", plata($plataPerdida));
    echo "  Se suma el pedido MAYOR de cada uno, no todos: pedir tres veces\n";
    echo "  \$2.000 es una intención de \$2.000, no de \$6.000.\n";
    echo "\n  Esto NO es plata perdida por una falla -- el que se arrepintió\n";
    echo "  también está acá. Es el techo de lo que se podría recuperar.\n";
} else {
    echo "    (ninguno: a todos los que se les venció, cargaron igual)\n";
}

echo "\n";
