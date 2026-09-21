<?php
/**
 * t_fid_analitica.php — El seguimiento de la campaña de fidelización.
 *
 * Pedido del dueño (18/09/2026): "un analytic que vaya siguiendo qué usuario
 * respondió a la campaña, la tasa de conversión y un promedio de a qué día
 * vuelven a activarse".
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es donde una métrica miente sin romperse:
 *
 *  1. QUE LA UNIDAD SEA LA RACHA Y NO EL AVISO. Un jugador recibe 2d, 3d, 4d
 *     y 7d en la misma racha: contando por aviso, el que vuelve suma UNA
 *     vuelta contra CUATRO envíos y la conversión sale 4 veces más baja. Y
 *     encima empeoraría sola al agregar escalones — justo lo que el operador
 *     está evaluando cuando los agrega.
 *
 *  2. QUE LA VENTANA DE ATRIBUCIÓN SE RESPETE. Sin ella, el que vuelve tres
 *     meses después cuenta como converso y la campaña se atribuye todo lo que
 *     pasa en el casino.
 *
 *  3. QUE "VOLVIÓ" SEA PLATA REAL, por las DOS vías (transferencia y el botón
 *     del juego), con la definición única del CRM.
 *
 *  4. QUE NO CUENTE UNA CARGA ANTERIOR AL AVISO. Es el error que haría ver
 *     una campaña genial el día que se prende.
 *
 *     T_PORT=3399 php t_fid_analitica.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/publicidad_lib.php';
require_once __DIR__ . '/api/fidelizacion_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$PREF = 'tfa_';
function limpiar(PDO $pdo): void {
    global $PREF;
    foreach (['fidelizacion_avisos' => 'usuario', 'recargas' => 'usuario',
              'movimientos' => 'usuario', 'usuarios' => 'username'] as $t => $col) {
        try { $pdo->exec("DELETE FROM $t WHERE $col LIKE '{$PREF}%'"); } catch (Throwable $e) {}
    }
}
/** Un aviso de la campaña, hace $haceDias días, para la racha $ref. */
function aviso(PDO $pdo, string $u, int $escalon, int $haceDias, string $ref, int $pct = 20): void {
    $pdo->prepare(
        "INSERT INTO fidelizacion_avisos (usuario, dias, pct, ruleta, actividad_ref, enviado_en)
         VALUES (?,?,?,0,?, DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([$u, $escalon, $pct, $ref, $haceDias]);
}
/** Una carga acreditada por transferencia (camino B), hace $haceDias días. */
function cargaTransf(PDO $pdo, string $u, float $monto, int $haceDias): void {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, centavos,
                               estado, creada_en, acreditada_en, vence_en)
         VALUES (?,?,?,?,?,0,'acreditada', DATE_SUB(NOW(), INTERVAL ? DAY),
                 DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([substr(md5(uniqid('', true)), 0, 10), $u, (int)$monto, floor($monto), $monto,
                $haceDias, $haceDias, $haceDias]);
}
/** Una carga por el botón «Depósitos» del juego (camino A). */
function cargaJuego(PDO $pdo, string $u, float $monto, int $haceDias): void {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES (?, 'saldo', ?, 'del test', 'peticion', DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([$u, (int)$monto, $haceDias]);
}

limpiar($pdo);

// ===========================================================================
echo "=== 1. Sin datos no inventa nada ===\n";
$a = fid_analitica($pdo, 30, 14);
chequear('responde ok aunque no haya avisos', !empty($a['ok']));
chequear('0 rachas, tasa 0, y los días quedan en null (no en 0)',
         (int)$a['resumen']['rachas'] === 0 && (float)$a['resumen']['tasa'] === 0.0
         && $a['resumen']['dias_prom'] === null,
         json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 2. LA UNIDAD ES LA RACHA, no el aviso ===\n";
/* Un jugador, UNA racha, CUATRO avisos (cruzó los cuatro escalones) y vuelve.
   Si esto contara por aviso daría 1/4 = 25%. Es 1/1 = 100%. */
$ref = date('Y-m-d H:i:s', strtotime('-12 days'));
foreach ([[2, 10], [3, 9], [4, 8], [7, 5]] as [$esc, $hace]) {
    aviso($pdo, $PREF . 'multi', $esc, $hace, $ref, 20 + $esc);
}
cargaTransf($pdo, $PREF . 'multi', 5000, 3);   // volvió 7 días después del primer aviso
$a = fid_analitica($pdo, 30, 14);
chequear('cuatro avisos de la misma racha son UNA racha',
         (int)$a['resumen']['rachas'] === 1, json_encode($a['resumen']));
chequear('y la conversión es 100%, no 25%',
         (float)$a['resumen']['tasa'] === 100.0, json_encode($a['resumen']));
chequear('el crédito va al escalón MÁS ALTO que recibió (último toque)',
         count($a['por_escalon']) === 1 && (int)$a['por_escalon'][0]['dias'] === 7,
         json_encode($a['por_escalon']));
chequear('la fila de "quiénes" dice cuántos avisos hicieron falta',
         (int)$a['quienes'][0]['avisos'] === 4, json_encode($a['quienes'][0] ?? []));

/* Y una racha NUEVA del mismo jugador (volvió a jugar y se volvió a enfriar)
   sí cuenta aparte: son dos oportunidades distintas. */
$ref2 = date('Y-m-d H:i:s', strtotime('-3 days'));
aviso($pdo, $PREF . 'multi', 2, 2, $ref2, 20);
$a = fid_analitica($pdo, 30, 14);
chequear('otra racha del mismo jugador cuenta como otra oportunidad',
         (int)$a['resumen']['rachas'] === 2, json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 3. La ventana de atribución se respeta ===\n";
limpiar($pdo);
$refL = date('Y-m-d H:i:s', strtotime('-40 days'));
aviso($pdo, $PREF . 'lento', 7, 25, $refL);
cargaTransf($pdo, $PREF . 'lento', 3000, 2);   // volvió 23 días después: fuera de 14
$a = fid_analitica($pdo, 60, 14);
chequear('el que vuelve fuera de la ventana NO cuenta como converso',
         (int)$a['resumen']['volvieron'] === 0, json_encode($a['resumen']));
$a30 = fid_analitica($pdo, 60, 30);
chequear('y con la ventana en 30 días, el mismo caso SÍ cuenta',
         (int)$a30['resumen']['volvieron'] === 1, json_encode($a30['resumen']));

// ===========================================================================
echo "\n=== 4. Una carga ANTERIOR al aviso no cuenta ===\n";
limpiar($pdo);
$refP = date('Y-m-d H:i:s', strtotime('-10 days'));
cargaTransf($pdo, $PREF . 'previo', 9000, 9);   // cargó ANTES
aviso($pdo, $PREF . 'previo', 4, 5, $refP);     // y recién después se le avisó
$a = fid_analitica($pdo, 30, 14);
chequear('la carga previa al aviso no se cuenta (sería una campaña genial de mentira)',
         (int)$a['resumen']['volvieron'] === 0 && (float)$a['resumen']['monto'] === 0.0,
         json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 5. Vuelve por el botón del juego (camino A), no solo por transferencia ===\n";
limpiar($pdo);
$refJ = date('Y-m-d H:i:s', strtotime('-8 days'));
aviso($pdo, $PREF . 'juego', 3, 6, $refJ);
cargaJuego($pdo, $PREF . 'juego', 2500, 4);
$a = fid_analitica($pdo, 30, 14);
chequear('la carga del camino A cuenta igual (la campaña no elige por dónde vuelve)',
         (int)$a['resumen']['volvieron'] === 1, json_encode($a['resumen']));
chequear('y suma su plata', (float)$a['resumen']['monto'] === 2500.0, json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 6. Promedio Y mediana: a qué día vuelven ===\n";
limpiar($pdo);
/* Tres vuelven al día 1 y uno al día 13. El promedio dice 4 días; la mediana
   dice 1. El operador que lee solo el promedio concluye "vuelven a la semana"
   sobre una campaña en la que la mayoría vuelve al otro día. */
foreach ([['a', 10, 9], ['b', 10, 9], ['c', 10, 9], ['d', 20, 7]] as [$n, $avisoHace, $cargaHace]) {
    $r = date('Y-m-d H:i:s', strtotime("-{$avisoHace} days"));
    aviso($pdo, $PREF . $n, 3, $avisoHace, $r);
    cargaTransf($pdo, $PREF . $n, 1000, $cargaHace);
}
$a = fid_analitica($pdo, 60, 30);
chequear('los cuatro volvieron', (int)$a['resumen']['volvieron'] === 4, json_encode($a['resumen']));
chequear('el promedio se estira por el que tardó 13 días',
         (float)$a['resumen']['dias_prom'] === 4.0,
         'prom=' . var_export($a['resumen']['dias_prom'], true));
chequear('la mediana describe al jugador típico (1 día)',
         (float)$a['resumen']['dias_med'] === 1.0,
         'med=' . var_export($a['resumen']['dias_med'], true));
chequear('el ticket promedio sale de los que volvieron, no del total',
         (float)$a['resumen']['ticket'] === 1000.0, json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 7. Tasa por escalón: cuál sirve y cuál se gasta al pedo ===\n";
limpiar($pdo);
// Escalón 2: dos avisados, uno vuelve (50%). Escalón 7: dos avisados, ninguno.
foreach (['e1' => true, 'e2' => false] as $n => $vuelve) {
    $r = date('Y-m-d H:i:s', strtotime('-9 days'));
    aviso($pdo, $PREF . $n, 2, 8, $r);
    if ($vuelve) { cargaTransf($pdo, $PREF . $n, 4000, 6); }
}
foreach (['e3', 'e4'] as $n) {
    $r = date('Y-m-d H:i:s', strtotime('-9 days'));
    aviso($pdo, $PREF . $n, 7, 8, $r);
}
$a = fid_analitica($pdo, 30, 14);
$porEsc = [];
foreach ($a['por_escalon'] as $e) { $porEsc[(int)$e['dias']] = $e; }
chequear('el escalón de 2 días convierte al 50%',
         (float)($porEsc[2]['tasa'] ?? -1) === 50.0, json_encode($porEsc[2] ?? []));
chequear('el de 7 días, 0% (es el que hay que revisar)',
         (float)($porEsc[7]['tasa'] ?? -1) === 0.0, json_encode($porEsc[7] ?? []));

// ===========================================================================
echo "\n=== 8. 'Todavía en curso': lo que evita leer un derrumbe falso ===\n";
limpiar($pdo);
$rHoy = date('Y-m-d H:i:s', strtotime('-2 days'));
aviso($pdo, $PREF . 'reciente', 2, 1, $rHoy);      // avisado ayer, sin volver TODAVÍA
$rViejo = date('Y-m-d H:i:s', strtotime('-30 days'));
aviso($pdo, $PREF . 'viejo', 2, 25, $rViejo);      // avisado hace 25 días, no volvió nunca
$a = fid_analitica($pdo, 60, 14);
chequear('el avisado ayer figura EN CURSO (todavía puede volver)',
         (int)$a['resumen']['en_curso'] === 1, json_encode($a['resumen']));
chequear('el de hace 25 días ya no: su ventana venció',
         (int)$a['resumen']['rachas'] === 2 && (int)$a['resumen']['volvieron'] === 0,
         json_encode($a['resumen']));

// ===========================================================================
echo "\n=== 9. La serie del gráfico ===\n";
chequear('trae un punto por día con avisadas y vueltas',
         count($a['serie']) === 2
         && isset($a['serie'][0]['dia'], $a['serie'][0]['avisadas'], $a['serie'][0]['volvieron']),
         json_encode($a['serie']));
chequear('ordenada de más vieja a más nueva (el eje del tiempo va así)',
         $a['serie'][0]['dia'] < $a['serie'][1]['dia'], json_encode($a['serie']));

// ===========================================================================
echo "\n=== 10. Los clamps de los parámetros ===\n";
$a = fid_analitica($pdo, 99999, 99999);
chequear('días de análisis se acota a 365', (int)$a['dias'] === 365);
chequear('ventana de atribución se acota a 90', (int)$a['ventana'] === 90);
$a = fid_analitica($pdo, 0, 0);
chequear('y un 0 no deja la consulta sin rango', (int)$a['dias'] === 1 && (int)$a['ventana'] === 1);

/* La definición de carga es la ÚNICA del CRM. Si alguien escribe otra acá, la
   pantalla de fidelización y Finanzas muestran plata distinta el mismo día
   (pasó dos veces, ver CLAUDE.md). Posicional: es una regla de arquitectura
   que ningún test de comportamiento ve. */
chequear('usa publicidad_sql_cargas(), no una consulta propia',
         str_contains(file_get_contents(__DIR__ . '/api/fidelizacion_lib.php'),
                      'publicidad_sql_cargas()'));

// ===========================================================================
echo "
=== 11. La pantalla: lo que no se puede aflojar al tocarla ===
";

$crm = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('la vista pide la analitica al endpoint nuevo',
         str_contains($crm, 'accion:"fid_analitica"'));
chequear('y el endpoint existe en crm.php',
         str_contains(file_get_contents(__DIR__ . '/api/crm.php'), "\$accion === 'fid_analitica'"));

/* EL TRACK DE LAS BARRAS VA SIEMPRE AL 100%. Lo tuvo proporcional a los
   avisados durante un rato y al mirarlo RENDERIZADO se vio el problema: la
   barra de "2 dias" (33% de 3) medía lo mismo que la de "4 dias" (50% de 2).
   Dos largos iguales que significan cosas distintas es lo unico que un
   grafico de barras no puede hacer. */
chequear('el ancho del track NO depende del volumen (largos comparables)',
         !str_contains($crm, 'fid-bar-track" style="width:'),
         'si el track vuelve a ser proporcional, dos escalones distintos miden igual');

/* Los colores de DATOS estan validados contra el fondo oscuro del CRM (banda
   de luminosidad, croma, separacion para daltonismo y contraste >= 3:1). Los
   tokens semanticos del tema (--green) NO pasan esa banda: son para texto. */
chequear('el grafico usa la paleta de datos validada, no los tokens de texto',
         str_contains($crm, '--d-ok:#27af66') && str_contains($crm, '--d-info:#599ad5'));

/* El estado nunca es solo color: cada punto va con su palabra al lado. */
foreach (['Volvió', 'Esperando', 'No volvió'] as $etq) {
    chequear('el estado «' . $etq . '» se escribe, no solo se pinta', str_contains($crm, $etq));
}

/* SIN DATOS NO SE MUESTRA UN 0%: un cero se lee como "la campaña fracasó", y
   que todavía no haya nada que medir es otra cosa. */
chequear('sin avisos el hero dice que no hay nada que medir, no 0%',
         str_contains($crm, 'Todavía no se mandó ningún aviso en este período'));

/* Y LA TASA "EN CURSO" SE ACLARA. Sin eso los últimos días parecen un
   derrumbe: a esa gente todavía no le dimos tiempo de volver. */
chequear('se muestra cuántos siguen dentro del plazo',
         str_contains($crm, 'todavía están dentro del plazo'));

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
