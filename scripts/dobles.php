<?php
/**
 * dobles.php — ¿A quién más se le acreditó la misma plata dos veces?
 *
 * DE DÓNDE SALE (16/09/2026). A rodrigoalejandro1234 se le acreditaron $35.000
 * dos veces con un minuto de diferencia: transfirió una vez, el operador se lo
 * cargó a mano porque no lo veía acreditado, y dos minutos después entró la
 * transferencia y el sistema se lo acreditó de nuevo. `jugador-plata.php` lo
 * encontró mirando UN jugador; esto hace la misma pregunta sobre todos.
 *
 * EL CRITERIO, y por qué no alcanza con "dos depósitos iguales": un jugador
 * puede transferir $2.000 dos veces el mismo día, y eso son dos depósitos
 * legítimos. Lo que delata el doble es el DESBALANCE:
 *
 *     depósitos de $X   >   transferencias de $X
 *
 * O sea que entró más plata a la cuenta que la que el banco confirmó por ese
 * monto. Se cuentan las dos cosas en la misma ventana y se comparan.
 *
 * LA FUENTE DE LOS DEPÓSITOS ES EL LIBRO DEL PANEL (`operaciones_panel`), que
 * es el registro de la PLATAFORMA: ahí figura todo lo que se ejecutó sobre la
 * cuenta, lo haya hecho el worker, el jugador desde el juego o una persona
 * tipeando. Nuestras tablas dicen lo que quisimos hacer; el libro, lo que pasó.
 *
 * LO QUE NO PUEDE VER, y conviene saberlo antes de confiar en un cero:
 *   - El libro se mantiene con una ventana móvil de 30 días más lo que haya
 *     traído el backfill. Para fechas anteriores diría "cero", y cero no es un
 *     dato: es una ausencia. El script avisa hasta dónde llega.
 *   - Un bono o una carga de cortesía son plata que entra sin transferencia y
 *     aparecen como desbalance. Por eso cada caso dice si hubo una carga a
 *     mano al lado, con el nombre del operador: eso es lo que lo distingue.
 *
 * SOLO LEE. No corrige nada: sacarle plata a un jugador es decisión de una
 * persona, y se hace en el panel.
 *
 *   php /opt/goldpaw/scripts/dobles.php
 *   php /opt/goldpaw/scripts/dobles.php ganamoscrm.online 30 60
 *                                        dominio          días ventana(min)
 */

$dominio  = $argv[1] ?? 'ganamoscrm.online';
$dias     = max(1, (int)($argv[2] ?? 30));
$ventana  = max(1, (int)($argv[3] ?? 60));   // minutos entre los dos depósitos

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';

$desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

function plata($n) { return number_format((float)$n, 2, ',', '.'); }
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 76) . "\n"; }

echo "\nDepósitos repetidos — " . $dominio . "\n";
echo "Últimos " . $dias . " días, dos depósitos iguales dentro de " . $ventana . " min.\n";

$lim = $pdo->query("SELECT MIN(cuando) a, MAX(cuando) b FROM operaciones_panel WHERE tipo = 0")->fetch();
printf("El libro de depósitos va de %s a %s.\n", $lim['a'] ?? '(vacío)', $lim['b'] ?? '(vacío)');

// ===========================================================================
titulo('Casos');
/* `b.payment_id > a.payment_id` y no `b.cuando > a.cuando`: el panel da la hora
   al minuto, así que dos operaciones del mismo minuto empatan y el par saldría
   dos veces (A-B y B-A). El id es único y creciente, y desempata siempre. */
$st = $pdo->prepare(
    "SELECT a.username, a.monto,
            a.payment_id ida, a.cuando cuandoa, a.comentario coma,
            b.payment_id idb, b.cuando cuandob, b.comentario comb,
            TIMESTAMPDIFF(MINUTE, a.cuando, b.cuando) dt
       FROM operaciones_panel a
       JOIN operaciones_panel b
         ON b.username = a.username
        AND b.tipo = 0
        AND ROUND(b.monto * 100) = ROUND(a.monto * 100)
        AND b.payment_id > a.payment_id
        AND b.cuando BETWEEN a.cuando AND (a.cuando + INTERVAL ? MINUTE)
      WHERE a.tipo = 0 AND a.cuando >= ? AND a.username <> ''
      ORDER BY a.cuando DESC"
);
$st->execute([$ventana, $desde]);
$pares = $st->fetchAll();

/* Cuántas transferencias de ESE monto le entraron al jugador alrededor. La
   ventana es amplia (±12 h) a propósito: lo que se busca es si el banco
   confirmó una o dos, no hacerlas coincidir al minuto. */
$qTransf = $pdo->prepare(
    "SELECT COUNT(*) FROM pagos p
       JOIN recargas r ON r.id = p.recarga_id
      WHERE r.usuario = ?
        AND ROUND(p.monto * 100) = ?
        AND p.capturado_en BETWEEN (? - INTERVAL 12 HOUR) AND (? + INTERVAL 12 HOUR)"
);
/* LAS CARGAS A MANO CUENTAN COMO PLATA LEGITIMA, y esta es la correccion
   mas importante del script.

   La primera version comparaba depositos contra TRANSFERENCIAS y nada mas. Con
   eso, cada carga que el operador hace a mano --una cortesia, un ajuste, una
   devolucion-- aparecia como deposito sin respaldo, o sea "de mas". Medido el
   16/09/2026: el resumen decia 69.600 acreditados de mas, y la mayor parte eran
   cargas que Nahuel habia hecho el mismo a proposito.

   El daño de ese falso positivo no es un numero feo: el script termina diciendo
   "esto se saca del saldo del jugador". Cobrarle a alguien por una cortesia que
   vos le diste es peor que no detectar un doble.

   Asi que el respaldo de un deposito es: una transferencia del banco O una
   carga a mano del CRM. Recien si los depositos superan la SUMA de las dos hay
   algo que explicar. */
$qMano = $pdo->prepare(
    "SELECT operador, monto, creado_en FROM movimientos
      WHERE usuario = ? AND tipo = 'saldo' AND origen = 'crm'
        AND creado_en BETWEEN (? - INTERVAL 2 HOUR) AND (? + INTERVAL 2 HOUR)
      ORDER BY creado_en ASC LIMIT 5"
);

/* Cuantas cargas a mano de ESE monto hubo alrededor. Se cuenta aparte del
   listado de arriba (que es informativo y esta capado) porque este numero
   entra en la cuenta. */
$qManoN = $pdo->prepare(
    "SELECT COUNT(*) FROM movimientos
      WHERE usuario = ? AND tipo = 'saldo' AND origen = 'crm'
        AND ROUND(monto * 100) = ?
        AND creado_en BETWEEN (? - INTERVAL 12 HOUR) AND (? + INTERVAL 12 HOUR)"
);

$sobra = 0.0; $casos = 0;
foreach ($pares as $p) {
    $cent = (int)round((float)$p['monto'] * 100);
    $qTransf->execute([$p['username'], $cent, $p['cuandoa'], $p['cuandoa']]);
    $nTransf = (int)$qTransf->fetchColumn();

    /* Cuántos depósitos de ese monto hay en la misma ventana. Con un par son 2,
       pero si el mismo monto se acreditó tres veces conviene decirlo. */
    $qDep = $pdo->prepare(
        "SELECT COUNT(*) FROM operaciones_panel
          WHERE tipo = 0 AND username = ? AND ROUND(monto * 100) = ?
            AND cuando BETWEEN ? AND (? + INTERVAL ? MINUTE)"
    );
    $qDep->execute([$p['username'], $cent, $p['cuandoa'], $p['cuandoa'], $ventana]);
    $nDep = (int)$qDep->fetchColumn();

    $qManoN->execute([$p['username'], $cent, $p['cuandoa'], $p['cuandoa']]);
    $nMano = (int)$qManoN->fetchColumn();

    /* Respaldo = lo que entro por el banco MAS lo que se cargo a mano. */
    $demas = $nDep - $nTransf - $nMano;
    $casos++;

    printf("\n  \033[1m%s\033[0m   $%s\n", $p['username'], plata($p['monto']));
    printf("    %s [%d, %s]\n", substr((string)$p['cuandoa'], 5, 14), (int)$p['ida'],
           trim((string)$p['coma']) === '' ? 'lo pidió el jugador' : trim((string)$p['coma']));
    printf("    %s [%d, %s]   (%d min después)\n", substr((string)$p['cuandob'], 5, 14),
           (int)$p['idb'], trim((string)$p['comb']) === '' ? 'lo pidió el jugador' : trim((string)$p['comb']),
           (int)$p['dt']);
    printf("    depósitos de ese monto: %d   transferencias: %d   cargas a mano: %d\n",
           $nDep, $nTransf, $nMano);

    $qMano->execute([$p['username'], $p['cuandoa'], $p['cuandoa']]);
    foreach ($qMano as $m) {
        printf("    carga a mano cerca: $%s por %s (%s)\n",
               plata($m['monto']), $m['operador'] ?: '(sin operador)',
               substr((string)$m['creado_en'], 5, 14));
    }

    if ($demas > 0) {
        $deMas = $demas * (float)$p['monto'];
        $sobra += $deMas;
        printf("    \033[1m>> SOBRAN $%s\033[0m (entraron %d de más)\n", plata($deMas), $demas);
    } else {
        echo "    OK: cada depósito tiene su transferencia o su carga a mano.\n";
    }
}

if (!$pares) { echo "  Ninguno. Nadie recibió dos depósitos iguales seguidos.\n"; }

// ===========================================================================
titulo('Resumen');
printf("  Pares encontrados: %d\n", $casos);
printf("  Plata acreditada de más: \033[1m$%s\033[0m\n", plata($sobra));
if ($sobra > 0) {
    echo "\n  NO LO SAQUES SIN MIRAR PRIMERO. Corré `jugador-plata.php <usuario>`:\n";
    echo "  cruza lo que transfirió contra el LIBRO del panel, que es el único\n";
    echo "  registro de lo que de verdad se acreditó.\n";
    echo "\n  Sigue habiendo plata legítima que este script no puede ver: el bono\n";
    echo "  de bienvenida, los bonos al juego y cualquier regalo entran como\n";
    echo "  depósito sin transferencia. Por eso esto marca DÓNDE mirar, no qué\n";
    echo "  cobrar. Si la diferencia coincide con un bono, no sobra nada.\n";
}
echo "\n";
