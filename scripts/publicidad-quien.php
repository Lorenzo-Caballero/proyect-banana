<?php
/**
 * publicidad-quien.php — QUIÉNES son los registros y las cargas de una campaña.
 *
 * PARA QUÉ. La tabla de Publicidad dice "3 registros, 2 primeras cargas" y no
 * hay forma de saber de quién habla. Cuando el número no coincide con lo que
 * uno recuerda, la pregunta siguiente es siempre la misma — "¿y quiénes son?"
 * — y hasta ahora no había manera de contestarla sin escribir SQL a mano.
 *
 * Además separa explícitamente las RECARGAS (la segunda carga en adelante),
 * que es la sospecha natural cuando el número parece alto: acá se ve que van
 * aparte y NO entran en la columna "1ª cargas".
 *
 * SOLO LEE. No escribe una sola fila.
 *
 *   php /opt/goldpaw/scripts/publicidad-quien.php lp:bono-50
 *   php /opt/goldpaw/scripts/publicidad-quien.php lp:bono-50 7
 *   php /opt/goldpaw/scripts/publicidad-quien.php lp:bono-50 1 ganamoscrm.online
 *
 * El primer argumento es `altas.origen` tal cual está en la base. Para una
 * landing es "lp:<slug>" (la de bono-50 es `lp:bono-50`); sin argumento lista
 * los orígenes que existen y sale.
 *
 * USA LAS MISMAS DEFINICIONES QUE LA PANTALLA: las cargas salen de
 * publicidad_sql_cargas() --requerida de la librería, no copiada-- y la
 * "primera" es el MIN(cuando) de toda la historia del jugador, igual que en
 * publicidad_por_dia(). Si este script y la tabla no coinciden, uno de los dos
 * tiene un bug y hay que poder verlo.
 */

$origen  = $argv[1] ?? '';
$dias    = max(1, (int)($argv[2] ?? 1));
$dominio = $argv[3] ?? 'ganamoscrm.online';

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/publicidad_lib.php';

$hoy   = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
$ini   = $desde . ' 00:00:00';
$fin   = $hoy . ' 23:59:59';

function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 78) . "\n"; }
function plata($n) { return '$' . number_format((float)$n, 2, ',', '.'); }

/* Sin origen: decir cuáles hay. Adivinar el slug es la primera forma de
   quedarse mirando una tabla vacía y creer que no hubo nada. */
if ($origen === '') {
    titulo('ORÍGENES QUE EXISTEN EN `altas` (usá uno como primer argumento)');
    $st = $pdo->query(
        "SELECT COALESCE(origen,'(sin origen)') o, COUNT(*) n, MAX(pedido_en) ultimo
           FROM altas GROUP BY origen ORDER BY n DESC LIMIT 25"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  %-34s %6d altas   última: %s\n", $r['o'], $r['n'], $r['ultimo'] ?? '—');
    }
    echo "\n";
    exit;
}

echo "\n=== PUBLICIDAD: QUIÉNES SON ===\n";
printf("  %-22s %s\n", 'Campaña (altas.origen)', $origen);
printf("  %-22s %s\n", 'Base', $GLOBALS['TENANT_DB'] ?? '(?)');
printf("  %-22s %s\n", 'Ventana', $dias === 1 ? "hoy ($hoy)" : "$desde .. $hoy ($dias días)");

/* ------------------------------------------------------------------ */
titulo('1. REGISTROS — cuentas creadas en la ventana desde esta campaña');
/* Es COUNT(*) sobre `altas.pedido_en`: la fecha en que se creó la cuenta.
   No tiene nada que ver con si cargó o no. */
$st = $pdo->prepare(
    "SELECT usuario, pedido_en, estado
       FROM altas
      WHERE origen = ? AND pedido_en BETWEEN ? AND ?
      ORDER BY pedido_en"
);
$st->execute([$origen, $ini, $fin]);
$regs = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($regs as $r) {
    printf("  %-32s %s   %s\n", $r['usuario'], $r['pedido_en'], $r['estado']);
}
echo '  => ' . count($regs) . " registros\n";

/* ------------------------------------------------------------------ */
titulo('2. PRIMERAS CARGAS — es lo que cuenta la columna «1ª cargas»');
/* OJO CON ESTO, que es lo que suele no cuadrar: el jugador se cuenta acá por
   la fecha de su PRIMERA CARGA, no por la de su registro. Alguien que se
   registró la semana pasada y cargó por primera vez hoy suma HOY -- aunque
   "hoy no entró nadie nuevo por la landing". Son dos preguntas distintas. */
$union = publicidad_sql_cargas();
$st = $pdo->prepare(
    "SELECT c.usuario, c.cuando, c.monto, c.via, a.pedido_en, a.origen
       FROM ($union) c
       JOIN altas a ON a.usuario COLLATE utf8mb4_unicode_ci = c.usuario
       JOIN (SELECT usuario, MIN(cuando) AS primera FROM ($union) t GROUP BY usuario) pr
         ON pr.usuario = c.usuario
      WHERE a.origen = ? AND c.cuando BETWEEN ? AND ?
        AND c.cuando = pr.primera
      ORDER BY c.cuando"
);
$st->execute([$origen, $ini, $fin]);
$primeras = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($primeras as $r) {
    $mismoDia = substr((string)$r['pedido_en'], 0, 10) === substr((string)$r['cuando'], 0, 10);
    printf("  %-30s cargó %s  %-12s (%s)\n", $r['usuario'], $r['cuando'],
           plata($r['monto']), $r['via']);
    printf("  %-30s se registró %s%s\n", '', $r['pedido_en'],
           $mismoDia ? '' : '   << se registró OTRO día');
}
echo '  => ' . count($primeras) . " primeras cargas\n";

/* ------------------------------------------------------------------ */
titulo('3. RECARGAS — la segunda en adelante. NO entran en «1ª cargas»');
/* La sospecha natural cuando el número parece alto. Se listan aparte para que
   se vea que van por separado: si estuvieran contadas, aparecerían arriba. */
$st = $pdo->prepare(
    "SELECT c.usuario, c.cuando, c.monto, c.via
       FROM ($union) c
       JOIN altas a ON a.usuario COLLATE utf8mb4_unicode_ci = c.usuario
       JOIN (SELECT usuario, MIN(cuando) AS primera FROM ($union) t GROUP BY usuario) pr
         ON pr.usuario = c.usuario
      WHERE a.origen = ? AND c.cuando BETWEEN ? AND ?
        AND c.cuando <> pr.primera
      ORDER BY c.cuando"
);
$st->execute([$origen, $ini, $fin]);
$re = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($re as $r) {
    printf("  %-30s %s  %-12s (%s)\n", $r['usuario'], $r['cuando'], plata($r['monto']), $r['via']);
}
echo '  => ' . count($re) . " recargas (van al «Depositado real», no a «1ª cargas»)\n";

/* ------------------------------------------------------------------ */
titulo('4. LO QUE DEBERÍA DECIR LA TABLA');
printf("  %-24s %d\n", 'Registros', count($regs));
printf("  %-24s %d\n", '1ª cargas', count($primeras));
$dep = 0.0;
foreach (array_merge($primeras, $re) as $r) { $dep += (float)$r['monto']; }
printf("  %-24s %s   (primeras + recargas)\n", 'Depositado real', plata($dep));
echo "\n";
