<?php
/**
 * jugador-plata.php — Toda la plata de UN jugador, en una línea de tiempo.
 *
 * PARA QUÉ. La pregunta que lo origina (16/09/2026, Nahuel): *"a ese usuario le
 * cargué manual porque no vi que se le acreditaran. Revisá si le cargamos
 * doble"*. Esa pregunta no se puede contestar mirando una tabla: la plata de un
 * jugador entra por cuatro puertas distintas y cada una deja rastro en un lugar
 * distinto. Puesto todo junto y ordenado por hora, un doble crédito se ve.
 *
 * LA FUENTE QUE DECIDE ES EL LIBRO DEL PANEL (`operaciones_panel`, tipo 0).
 * Es el registro de la PLATAFORMA, no el nuestro, y ahí figura todo depósito
 * que efectivamente se ejecutó sobre la cuenta -- lo haya hecho el worker, el
 * jugador desde el juego o el operador a mano en el panel. La regla está en
 * CLAUDE.md: estar en el libro es la prueba de que la operación se ejecutó, y
 * no estar es la prueba de que no. Nuestras tablas dicen lo que quisimos hacer;
 * el libro dice lo que pasó.
 *
 * `comentario` es lo que distingue quién la originó:
 *     ''                   nació de un pedido del jugador (botón Depósitos)
 *     'direct ...'         la hizo un agente directo: nuestro worker, o una
 *                          persona tipeando en el panel
 *
 * CÓMO SE VE UN DOBLE: dos depósitos del MISMO monto sobre la misma cuenta,
 * cerca en el tiempo, uno con comentario vacío y otro 'direct'. O sea: el
 * sistema lo acreditó y además alguien lo acreditó a mano.
 *
 * OJO CON EL LÍMITE DEL LIBRO. Se mantiene con una ventana móvil de 30 días
 * (más lo que haya traído el backfill, `aprobar_cargas.py --libro N`). Para una
 * fecha anterior, el libro diría "cero" y cero no es un dato: es una ausencia.
 * El script avisa hasta dónde llega.
 *
 * SOLO LEE. No escribe una sola fila, no corrige nada.
 *
 *   php /opt/goldpaw/scripts/jugador-plata.php rodrigoalejandro1234
 *   php /opt/goldpaw/scripts/jugador-plata.php rodrigoalejandro1234 ganamoscrm.online 30
 */

$usuario = trim((string)($argv[1] ?? ''));
$dominio = $argv[2] ?? 'ganamoscrm.online';
$dias    = max(1, (int)($argv[3] ?? 30));

if ($usuario === '') {
    fwrite(STDERR, "Falta el usuario.\n  php jugador-plata.php <usuario> [dominio] [dias]\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';

$desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

function plata($n) { return number_format((float)$n, 2, ',', '.'); }
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 76) . "\n"; }
function cuando($s) { return $s === null ? '     —      ' : substr((string)$s, 5, 14); }

echo "\nLa plata de \033[1m" . $usuario . "\033[0m — " . $dominio
   . "  (últimos " . $dias . " días)\n";

// ===========================================================================
titulo('0. La ficha');
$st = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? LIMIT 1");
$st->execute([$usuario]);
$ficha = $st->fetch();
if (!$ficha) {
    echo "  NO EXISTE en `usuarios`. Puede ser un alta que no bajó por espejo\n";
    echo "  todavía, o el nombre está escrito distinto. Sin esto, el resto\n";
    echo "  va a salir vacío y eso NO significa que no haya plata.\n";
} else {
    printf("  saldo real en ganamos (espejo): $%s   leído: %s\n",
           plata($ficha['balance'] ?? 0), $ficha['saldo_visto_en'] ?? '(sin migración 68)');
    printf("  fichas (contador propio):       $%s\n", plata($ficha['coins'] ?? 0));
    printf("  bonos:                          $%s\n", plata($ficha['bonus'] ?? 0));
}

// ===========================================================================
titulo('1. EL LIBRO DEL PANEL — lo que de verdad se acreditó (acá se ve el doble)');
/* El alcance del libro se dice ANTES de los datos: si la fecha que se busca
   queda afuera, una lista vacía engaña. */
$lim = $pdo->query("SELECT MIN(cuando) a, MAX(cuando) b FROM operaciones_panel")->fetch();
printf("  El libro va de %s a %s.\n\n",
       $lim['a'] ?? '(vacío)', $lim['b'] ?? '(vacío)');

$st = $pdo->prepare(
    "SELECT payment_id, tipo, monto, comentario, cuando, titular
       FROM operaciones_panel
      WHERE username = ? AND cuando >= ?
      ORDER BY cuando ASC"
);
$st->execute([$usuario, $desde]);
$libro = $st->fetchAll();

$entro = 0.0; $salio = 0.0; $depos = [];
foreach ($libro as $o) {
    $esDep = (int)$o['tipo'] === 0;
    if ($esDep) { $entro += (float)$o['monto']; $depos[] = $o; }
    else        { $salio += (float)$o['monto']; }
    $com = trim((string)$o['comentario']);
    printf("  %s  %-8s $%-12s %-20s [%d]\n",
           cuando($o['cuando']),
           $esDep ? 'DEPÓSITO' : 'retiro',
           plata($o['monto']),
           $com === '' ? 'lo pidió el jugador' : $com,
           (int)$o['payment_id']);
}
if (!$libro) { echo "  (ninguna operación en el libro para este jugador)\n"; }
printf("\n  Entró: $%s en %d depósito(s).   Salió: $%s\n",
       plata($entro), count($depos), plata($salio));

/* LA DETECCIÓN. Dos depósitos del mismo monto cerca en el tiempo son el patrón
   del doble crédito. No se afirma que lo sea -- alguien puede cargar dos veces
   lo mismo a propósito -- pero es exactamente lo que hay que mirar. */
$sospechas = [];
for ($i = 0; $i < count($depos); $i++) {
    for ($j = $i + 1; $j < count($depos); $j++) {
        if (round((float)$depos[$i]['monto'], 2) !== round((float)$depos[$j]['monto'], 2)) { continue; }
        $dt = abs(strtotime((string)$depos[$j]['cuando']) - strtotime((string)$depos[$i]['cuando'])) / 60;
        if ($dt <= 720) { $sospechas[] = [$depos[$i], $depos[$j], $dt]; }
    }
}
if ($sospechas) {
    echo "\n  \033[1m⚠ DOS DEPÓSITOS IGUALES, CERCA EN EL TIEMPO:\033[0m\n";
    foreach ($sospechas as [$a, $b, $dt]) {
        printf("    $%s  —  %s [%d, %s]  y  %s [%d, %s]  (%d min de diferencia)\n",
               plata($a['monto']),
               cuando($a['cuando']), (int)$a['payment_id'],
               trim((string)$a['comentario']) === '' ? 'del jugador' : 'directo',
               cuando($b['cuando']), (int)$b['payment_id'],
               trim((string)$b['comentario']) === '' ? 'del jugador' : 'directo',
               (int)$dt);
    }
    echo "\n  Uno 'del jugador' + uno 'directo' del mismo monto = se acreditó\n";
    echo "  solo Y ADEMÁS a mano. Los dos 'directo' pueden ser dos cargas\n";
    echo "  manuales queridas. Contrastar con la sección 2.\n";
} else {
    echo "\n  Sin dos depósitos iguales cerca en el tiempo.\n";
}

// ===========================================================================
titulo('2. Lo que el jugador TRANSFIRIÓ (el mail del banco, que no miente)');
/* El único que confirma plata es el mail del banco. Se buscan los pagos atados
   a una recarga suya -- por el matcher o asignados a mano. */
$st = $pdo->prepare(
    "SELECT p.id_unico, p.monto, p.remitente, p.estado, p.capturado_en,
            p.asignado_por, r.referencia, r.usuario
       FROM pagos p
       JOIN recargas r ON r.id = p.recarga_id
      WHERE r.usuario = ? AND p.capturado_en >= ?
      ORDER BY p.capturado_en ASC"
);
$st->execute([$usuario, $desde]);
$pagos = $st->fetchAll();
$transferido = 0.0;
foreach ($pagos as $p) {
    $transferido += (float)$p['monto'];
    printf("  %s  $%-12s de %-26s %s%s\n",
           cuando($p['capturado_en']), plata($p['monto']),
           mb_substr(trim((string)$p['remitente']) ?: '(sin titular)', 0, 26),
           $p['referencia'],
           trim((string)$p['asignado_por']) !== '' ? '  (a mano: ' . $p['asignado_por'] . ')' : '');
}
if (!$pagos) { echo "  (ninguna transferencia atada a una recarga suya)\n"; }
printf("\n  Total transferido por este camino: $%s\n", plata($transferido));

// ===========================================================================
titulo('3. Lo que pidió por el chat');
$st = $pdo->prepare(
    "SELECT referencia, monto_pedido, estado, creada_en, acreditada_en, titular_declarado
       FROM recargas WHERE usuario = ? AND creada_en >= ? ORDER BY creada_en ASC"
);
$st->execute([$usuario, $desde]);
$hay = 0;
foreach ($st as $r) {
    $hay++;
    printf("  %s  $%-12s %-11s %s%s\n",
           cuando($r['creada_en']), plata($r['monto_pedido']), $r['estado'],
           $r['referencia'],
           $r['acreditada_en'] ? '  acreditada ' . cuando($r['acreditada_en']) : '');
}
if (!$hay) { echo "  (ninguna)\n"; }

// ===========================================================================
titulo('4. Lo que pidió DENTRO del juego (camino A)');
$st = $pdo->prepare(
    "SELECT request_id, monto, estado, primera_vez, motivo
       FROM peticiones_carga WHERE username = ? AND primera_vez >= ?
      ORDER BY primera_vez ASC"
);
try {
    $st->execute([$usuario, $desde]);
    $hay = 0;
    foreach ($st as $q) {
        $hay++;
        printf("  %s  $%-12s %-10s [%d] %s\n",
               cuando($q['primera_vez']), plata($q['monto']), $q['estado'],
               (int)$q['request_id'], mb_substr((string)($q['motivo'] ?? ''), 0, 40));
    }
    if (!$hay) { echo "  (ninguna)\n"; }
} catch (Throwable $e) {
    echo "  (no pude leer peticiones_carga: " . $e->getMessage() . ")\n";
}

// ===========================================================================
titulo('5. Nuestra cola de saldo (lo que el CRM mandó a ejecutar)');
$st = $pdo->prepare(
    "SELECT id, tipo, monto, estado, creada_en, ejecutada_en, motivo, mensaje
       FROM acciones_saldo WHERE usuario = ? AND creada_en >= ? ORDER BY creada_en ASC"
);
$st->execute([$usuario, $desde]);
$hay = 0;
foreach ($st as $a) {
    $hay++;
    printf("  %s  %-8s $%-12s %-10s %s\n",
           cuando($a['creada_en']), $a['tipo'], plata($a['monto']), $a['estado'],
           mb_substr(trim((string)($a['motivo'] ?? $a['mensaje'] ?? '')), 0, 34));
}
if (!$hay) { echo "  (ninguna)\n"; }

// ===========================================================================
titulo('6. Nuestro registro de movimientos');
/* `operador` es de la migración 34. Si no está, se pide sin ella: perder quién
   cargó es molesto, no poder leer los movimientos es peor. */
try {
    $st = $pdo->prepare(
        "SELECT tipo, monto, origen, operador, motivo, creado_en
           FROM movimientos WHERE usuario = ? AND creado_en >= ? ORDER BY creado_en ASC"
    );
    $st->execute([$usuario, $desde]);
} catch (Throwable $e) {
    $st = $pdo->prepare(
        "SELECT tipo, monto, origen, NULL operador, motivo, creado_en
           FROM movimientos WHERE usuario = ? AND creado_en >= ? ORDER BY creado_en ASC"
    );
    $st->execute([$usuario, $desde]);
}
$porTipo = [];
$hay = 0;
foreach ($st as $m) {
    $hay++;
    $porTipo[$m['tipo']] = ($porTipo[$m['tipo']] ?? 0) + (float)$m['monto'];
    printf("  %s  %-6s $%-12s %-10s %-10s %s\n",
           cuando($m['creado_en']), $m['tipo'], plata($m['monto']),
           $m['origen'] ?? '', $m['operador'] ?? '',
           mb_substr((string)($m['motivo'] ?? ''), 0, 30));
}
if (!$hay) { echo "  (ninguno)\n"; }
foreach ($porTipo as $t => $s) { printf("    total %-6s $%s\n", $t, plata($s)); }

// ===========================================================================
titulo('7. El control');
printf("  Transfirió (mail del banco):        $%s\n", plata($transferido));
printf("  Se le acreditó en ganamos (libro):  $%s\n", plata($entro));
$dif = $entro - $transferido;
printf("  Diferencia:                         $%s\n", plata($dif));
echo "\n  Una diferencia POSITIVA no es por sí sola un error: el bono de\n";
echo "  bienvenida, una ficha regalada y una carga de cortesía son plata que\n";
echo "  entró sin transferencia. Lo que hay que explicar es el MONTO de la\n";
echo "  diferencia -- si coincide con un depósito entero de la sección 1, ese\n";
echo "  es el que sobra.\n";
echo "\n  Y una NEGATIVA tampoco: el jugador puede haber transferido para fichas\n";
echo "  que todavía no mandó al juego (mirá `coins` arriba).\n";
echo "\n";
