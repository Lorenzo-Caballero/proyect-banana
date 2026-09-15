<?php
/**
 * finanzas-hoy.php — La verdad cruda detrás de la pantalla de Finanzas.
 *
 * PARA QUÉ. Finanzas suma nueve consultas sobre cuatro tablas, y cuando un
 * número "se ve raro" no hay forma de saber si está mal el cálculo o si el dato
 * de abajo es ese. Esto imprime lo de abajo, para comparar con lo de arriba: si
 * coinciden, la pantalla anda; si no, ya se sabe en cuál de los dos mirar.
 *
 * SOLO LEE. No escribe una sola fila, no toca config, no manda nada.
 *
 *   php /opt/goldpaw/scripts/finanzas-hoy.php
 *   php /opt/goldpaw/scripts/finanzas-hoy.php ganamoscrm.online
 *   php /opt/goldpaw/scripts/finanzas-hoy.php ganamoscrm.online 7    (últimos 7 días)
 *
 * El dominio resuelve la base del cliente por el MISMO camino que la API
 * (api/db.php lo saca de HTTP_HOST contra `goldpaw_control.clientes`), así que
 * no hay forma de que este script mire una base distinta de la que ve el CRM.
 *
 * SOBRE LAS DEFINICIONES. Las cargas salen de `publicidad_sql_cargas()`, que se
 * requiere de la librería de verdad -- no una copia. Es LA definición de "una
 * carga" en todo el CRM (ver CLAUDE.md) y duplicarla acá sería exactamente el
 * error que ese bloque viene a evitar. Los retiros se leen de
 * `operaciones_panel` con el mismo filtro que usa `fn_retiros()`; si algún día
 * divergen, la comparación con la pantalla lo va a mostrar, que es para lo que
 * existe este script.
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$dias    = max(1, (int)($argv[2] ?? 1));

/* db.php resuelve el cliente por el dominio pedido. En CLI no hay request, así
   que se lo damos a mano: es el mismo camino, no un atajo. */
$_SERVER['HTTP_HOST'] = $dominio;

$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/publicidad_lib.php';
require_once $API . '/config_crm.php';

$hoy   = date('Y-m-d');
$desde = date('Y-m-d', strtotime("-" . ($dias - 1) . " days"));

function plata($n) { return number_format((float)$n, 2, ',', '.'); }
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 68) . "\n"; }
function fila($k, $v) { printf("  %-38s %s\n", $k, $v); }

echo "\n=== FINANZAS, LA VERDAD CRUDA ===\n";
fila('Cliente (dominio)', $dominio);
/* db.php deja el nombre en TENANT_DB. Se imprime para que no quede duda de
   contra qué base se leyeron estos números. */
fila('Base', $GLOBALS['TENANT_DB'] ?? '(?)');
fila('Ventana', $dias === 1 ? "hoy ($hoy)" : "$desde .. $hoy ($dias días)");

/* ------------------------------------------------------------------ */
titulo('1. EL ANCLA — desde cuándo mide la pantalla');
/* Todos los acumulados de Finanzas cuelgan de acá. Si el ancla se movió, TODOS
   los números se mueven con ella, y eso se lee como "Finanzas se rompió". */
$manual = '';
try { $manual = trim((string)(cfg_crm($pdo, 'fin_medir_desde') ?? '')); } catch (Throwable $e) {}
$primera = null;
try {
    $v = $pdo->query("SELECT MIN(cuando) FROM (" . publicidad_sql_cargas() . ") c")->fetchColumn();
    if ($v) { $primera = substr((string)$v, 0, 10); }
} catch (Throwable $e) { fila('!! no pude leer las cargas', $e->getMessage()); }

if ($manual !== '') {
    fila('Configurado a mano (fin_medir_desde)', $manual);
    fila('   -> la pantalla mide desde', $manual);
} else {
    fila('Configurado a mano', '(vacío: automático)');
    fila('   -> la pantalla mide desde', $primera ?? '(sin una sola carga todavía)');
}
fila('Primera carga de la historia', $primera ?? '(ninguna)');

/* ------------------------------------------------------------------ */
titulo('2. INGRESOS — las cargas, por las DOS vías');
/* El error que costó dos pantallas: medir solo `recargas` deja afuera el botón
   «Depósitos» de adentro del juego. Acá van separadas justamente para que se
   vea si una de las dos se cayó. */
$sql = publicidad_sql_cargas();
$st = $pdo->prepare(
    "SELECT via, COUNT(*) n, COALESCE(SUM(monto),0) total
       FROM ($sql) c
      WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
      GROUP BY via"
);
$st->execute([$desde, $hoy]);
$totIng = 0.0; $totN = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    fila($r['via'] === 'juego' ? 'Botón «Depósitos» del juego' : 'Transferencia (chatbot)',
         $r['n'] . ' cargas · $' . plata($r['total']));
    $totIng += (float)$r['total']; $totN += (int)$r['n'];
}
if ($totN === 0) { fila('(sin cargas en la ventana)', '—'); }
fila('TOTAL INGRESOS', $totN . ' cargas · $' . plata($totIng));

/* ------------------------------------------------------------------ */
titulo('3. RETIROS — del libro del panel');
/* `acciones_saldo` es NUESTRA cola y solo tiene los del chat. El libro
   (operaciones_panel) tiene lo que la plataforma EJECUTÓ de verdad, que es lo
   único que cuenta. Se imprimen los dos para ver la diferencia. */
$libroDesde = null;
try {
    $libroDesde = $pdo->query("SELECT MIN(cuando) FROM operaciones_panel")->fetchColumn() ?: null;
} catch (Throwable $e) { fila('!! no existe operaciones_panel', 'falta la migración 67'); }

if ($libroDesde) {
    fila('El libro llega hasta atrás de', substr((string)$libroDesde, 0, 16));
    $alcanza = $libroDesde <= $desde . ' 00:00:00';
    fila('¿Cubre la ventana pedida?', $alcanza ? 'sí' : 'NO — la pantalla cae a la cola vieja');

    $st = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM operaciones_panel
          WHERE tipo = 1 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hoy]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    fila('Retiros EJECUTADOS (libro)', $r['n'] . ' · $' . plata($r['total']));
    $totRet = (float)$r['total'];
} else {
    $totRet = 0.0;
}

$st = $pdo->prepare(
    "SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM acciones_saldo
      WHERE tipo='retirar' AND estado='hecha'
        AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY"
);
$st->execute([$desde, $hoy]);
$r = $st->fetch(PDO::FETCH_ASSOC);
fila('   (de referencia: nuestra cola)', $r['n'] . ' · $' . plata($r['total']));
fila('   OJO', 'el libro REEMPLAZA a la cola, no se suma');

/* ------------------------------------------------------------------ */
titulo('4. EL ESPEJO DE SALDOS — ¿el número de "fichas en poder de jugadores" es de hoy?');
/* Es el único dato de Finanzas que sale de `usuarios.balance`, y ese espejo
   estuvo muerto (el contenedor que lo escribía está apagado por profile). Desde
   el 15/09 lo hace aprobar_cargas.py cada 5 minutos. Si esta sección dice que
   nadie leyó nada hace horas, el número de la pantalla es viejo. */
$suma = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM usuarios")->fetchColumn();
fila('Fichas en poder de jugadores', '$' . plata($suma));
try {
    $r = $pdo->query(
        "SELECT COUNT(*) total,
                SUM(saldo_visto_en IS NULL) sin_leer,
                MAX(saldo_visto_en) ultimo,
                TIMESTAMPDIFF(MINUTE, MAX(saldo_visto_en), NOW()) hace_min
           FROM usuarios"
    )->fetch(PDO::FETCH_ASSOC);
    fila('Jugadores espejados', (string)$r['total']);
    fila('Sin leer NUNCA', (string)$r['sin_leer']);
    fila('Última lectura del panel', ($r['ultimo'] ?? '(nunca)')
        . ($r['hace_min'] !== null ? '  (hace ' . $r['hace_min'] . ' min)' : ''));
    if ($r['hace_min'] === null) {
        fila('>> VEREDICTO', 'EL ESPEJO NUNCA CORRIÓ — ese número no significa nada');
    } elseif ((int)$r['hace_min'] > 15) {
        fila('>> VEREDICTO', 'el espejo está ATRASADO (debería ser <= 5 min)');
    } else {
        fila('>> VEREDICTO', 'el espejo está al día');
    }
} catch (Throwable $e) {
    fila('!! falta la migración 68', 'no se puede saber si el número es de hoy');
}

/* ------------------------------------------------------------------ */
titulo('5. CONTROL — fichas entregadas vs fichas pedidas');
/* Si el libro registra más depósitos que los que pedimos, alguien cargó a mano
   desde el panel (normal). Si registra MENOS, hay depósitos que dimos por
   hechos y la plataforma nunca ejecutó -- que es el bug del documento de
   Fauno, y se ve acá antes de que lo reclame el jugador. */
try {
    $st = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM operaciones_panel
          WHERE tipo = 0 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hoy]);
    $lib = $st->fetch(PDO::FETCH_ASSOC);

    $st = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM acciones_saldo
          WHERE tipo='cargar' AND estado='hecha'
            AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hoy]);
    $col = $st->fetch(PDO::FETCH_ASSOC);

    fila('Depósitos EJECUTADOS (libro)', $lib['n'] . ' · $' . plata($lib['total']));
    fila('Depósitos que dimos por hechos', $col['n'] . ' · $' . plata($col['total']));
    $delta = (float)$lib['total'] - (float)$col['total'];
    fila('Diferencia', ($delta >= 0 ? '+' : '') . plata($delta)
        . ($delta < 0 ? '   << NEGATIVO: fichas que el jugador no recibió' : ''));
} catch (Throwable $e) {
    fila('!! sin operaciones_panel', 'no se puede cruzar');
}

/* ------------------------------------------------------------------ */
titulo('6. LO QUE DEBERÍA DECIR LA PANTALLA');
$res = $totIng - $totRet;
fila('Ingresos - retiros (sin costos)', '$' . plata($res));
echo "\n  El RESULTADO de la pantalla resta además el costo de las fichas y las\n"
   . "  comisiones de la pasarela, así que va a dar MENOS que este número.\n"
   . "  Si da MÁS, o si los ingresos no coinciden, ahí está el problema.\n\n";
