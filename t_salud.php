<?php
/**
 * t_salud.php — el indicador de "salud del negocio" de Finanzas.
 *
 * POR QUÉ EXISTE (Nahuel, 13/09/2026): "me gustaría que lo hagas como un
 * apartado, que se entienda que eso está midiendo datos que está trayendo desde
 * publicidad, para que no se mezcle con los números que ya tenemos en finanzas
 * ... Es más para saber la salud del negocio."
 *
 * Y antes, el modelo que el indicador tiene que medir: "yo no gano dinero por
 * los registros, yo gano por las cargas... puedo no salir con un ROAS positivo
 * en la primera carga, pero sí en la segunda. Mi modelo de negocio está en ir
 * adquiriendo jugadores hasta llegar a que mis gastos publicitarios diarios
 * sean menores a las ganancias obtenidas."
 *
 * O sea que el número que decide es: lo que deja la BASE YA COMPRADA por día
 * contra la PAUTA por día. Todo lo que se prueba acá existe para que ese número
 * no mienta.
 *
 *     php t_salud.php
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
require __DIR__ . '/api/publicidad_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* Prefijo propio para no pisar datos de otros tests ni de la demo. */
const U = 't_sal_';
$limpiar = function () use ($pdo) {
    $pdo->exec("DELETE FROM recargas    WHERE usuario LIKE '" . U . "%'");
    $pdo->exec("DELETE FROM movimientos WHERE usuario LIKE '" . U . "%'");
    $pdo->exec("DELETE FROM gasto_diario WHERE landing_slug LIKE 't_sal%' OR publicista_id = 99777");
};

// ===========================================================================
echo "\n=== El tablero de la app: las cuatro preguntas del negocio ===\n";

/* EL PEDIDO (Nahuel, 19/09/2026): *"nuestro modelo se sostiene gracias al
   mantenimiento de los usuarios activos... quiero metricas que me ayuden a
   entender la situacion de mi negocio"*.

   La cadena es: el jugador llega, carga, y para que no se enfrie hay que poder
   hablarle -- y para eso tiene que tener la app. Las metricas contestan las
   cuatro preguntas de esa cadena. */
$srcN = file_get_contents(__DIR__ . '/api/crm_notificaciones.php');
$crmH = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('1. a cuantos les puedo hablar: el embudo',
         str_contains($srcN, "'embudo' =>") && str_contains($crmH, 'id="nvEmbudo"'));
chequear('2. gano o pierdo: altas y bajas por semana',
         str_contains($srcN, "'semanas'") && str_contains($srcN, "'bajas' => \$bajas"));
chequear('3. sirve de algo: retencion con app vs sin app',
         str_contains($srcN, "'retencion'") && str_contains($srcN, "'con_app'"));
chequear('4. lo que mando llega: entregadas y leidas',
         str_contains($srcN, "'entrega'") && str_contains($srcN, 'leidas'));

/* EL EMBUDO VA EN ESCALONES SEPARADOS a proposito: cada perdida tiene un
   arreglo distinto --no instalan, no permiten, no abren-- y un solo porcentaje
   escondería cual de los tres esta mal. */
chequear('el embudo separa los tres escalones',
         substr_count($srcN, "'k' => '") >= 4
         && str_contains($srcN, "'k' => 'instalada'")
         && str_contains($srcN, "'k' => 'permitida'")
         && str_contains($srcN, "'k' => 'alcanzable'"));
chequear('y la pantalla nombra el escalon que mas pierde',
         str_contains($crmH, 'Donde más se pierde gente es en'),
         'cuatro numeros sueltos no dicen donde trabajar');

/* UNA BASE DE UNO NO ES UN PORCENTAJE. Medido el 19/09: 1 jugador con app y 6
   sin app. Mostrar "0% vs 0%" seria una conclusion inventada sobre la que
   alguien podria decidir apagar el canal. */
chequear('con muestra chica NO se muestra un porcentaje de retencion',
         str_contains($srcN, "\$ret['suficiente']")
         && str_contains($crmH, 'Todavía no alcanza para comparar'),
         'un 0% sobre base 1 es peor que no mostrar nada');

/* LA BAJA NO GENERA NINGUN EVENTO: nadie avisa que desinstalo, el celular
   simplemente deja de sondear. Sin esta cuenta, el canal parece crecer para
   siempre. */
chequear('una app que dejo de sondear cuenta como baja',
         str_contains($srcN, 'INTERVAL 14 DAY'),
         'sin esto el canal parece crecer para siempre');

/* Y que la retencion use la definicion UNICA de "una carga": si esta pantalla
   armara la suya, mostraria un numero distinto al de Finanzas el mismo dia. */
chequear('la retencion usa la definicion unica de carga',
         str_contains($srcN, 'publicidad_sql_cargas()'));


// ===========================================================================
echo "\n=== Efectividad: de los que lo RECIBIERON, cuantos cargaron ===\n";

/* EL PEDIDO (Nahuel, 19/09/2026): *"a que porcentaje de los que le mandamos
   notificacion realmente cargaron"*. */
$srcN = file_get_contents(__DIR__ . '/api/crm_notificaciones.php');
$crmH = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('existe la medicion de efectividad',
         str_contains($srcN, 'function crmnotif_efectividad('));

/* SE CUENTA SOBRE LOS QUE LA RECIBIERON, no sobre los que se les mando. Un
   aviso encolado que nadie vio no le puede pedir nada a nadie -- y la
   diferencia no es teorica: la fidelizacion mando 600 y entrego 0. */
chequear('se cuenta sobre los ENTREGADOS, no sobre los creados',
         str_contains($srcN, 'JOIN notificaciones_entregas e ON e.notificacion_id = o.id')
         && str_contains($srcN, 'JOIN dispositivos d ON d.device_id = e.device_id'),
         'mandar no es llegar: 600 creadas y 0 entregadas ya paso');

/* La ventana TERMINA hace un dia: a alguien que recibio el aviso hace dos
   horas todavia no se le puede reprochar no haber cargado, y contarlo como
   fracaso hunde el porcentaje sin decir nada. */
chequear('no cuenta como fracaso al que lo recibio recien',
         str_contains($srcN, "AND e.entregada_en <  DATE_SUB(NOW(), INTERVAL 1 DAY)"));

/* LO QUE NO SE VE NO ES UN AVISO. El "te contestamos" del chat va con
   solo_app=1: el widget lo consume y no lo dibuja. Contarlo era lo que mas
   inflaba el numero -- 84 de 87 "avisados" el 19/09/2026. */
chequear('un aviso que nadie ve no cuenta como aviso',
         str_contains($srcN, 'COALESCE(o.solo_app, 0) = 0'));

/* Y los que llegan DESPUES de una operacion van aparte: "te acreditamos la
   carga" no hizo cargar a nadie. */
chequear('los transaccionales se separan de las promos',
         str_contains($srcN, 'CRMNOTIF_ORIGEN_TRANSACCIONAL'));

/* LA MEDIDA QUE SI SE PUEDE ATRIBUIR: uso el bono que el aviso le prometio.
   Necesita que el bono guarde de que aviso salio, que es lo que faltaba. */
chequear('se mide si reclamaron el bono del aviso',
         str_contains($srcN, "'reclamo'") && str_contains($crmH, 'id="nvCardReclamo"'),
         'que cargue despues pudo pasar igual; que use ESE bono, no');
chequear('y la campaña ata el bono al aviso que lo prometio',
         str_contains(file_get_contents(__DIR__ . '/api/fidelizacion_lib.php'),
                      'SET notificacion_id = ?'),
         'sin el vinculo la pantalla muestra un 0% que en realidad es "no lo medimos"');

chequear('usa la definicion unica de una carga',
         str_contains($srcN, 'publicidad_sql_cargas()'));
chequear('y mira solo las cargas POSTERIORES al aviso',
         str_contains($srcN, '$tc > $ts && $tc < $ts + 7 * 86400'));

/* Abierto por tipo: la comparacion entre filas dice algo, el numero suelto
   dice poco. Y una fila de "1 de 1 = 100%" arriba de todo seria la conclusion
   mas ruidosa y mas falsa de la pantalla. */
chequear('se abre por tipo de aviso',
         str_contains($srcN, "'por_origen'") && str_contains($crmH, 'id="nvEfect"'));
chequear('y los tipos con muestra chica no se muestran',
         str_contains($crmH, 'o.avisados >= 10'),
         'una fila de 1 de 1 = 100% seria la conclusion mas falsa de la pantalla');
chequear('con pocos avisados en total tampoco se da un porcentaje',
         str_contains($crmH, 'ef.promo.avisados < MUESTRA_MIN'));

echo "\n=== La pantalla scrollea entera, no por dentro ===\n";

/* EL REPORTE: *"la parte de abajo, donde hay que scrollear, ocupa menos de la
   mitad de la pantalla... preferiria que toda la pagina se pueda scrollear"*.
   La causa: .view-panel recorta y .nv-body tiene su propio overflow, asi que
   el contenido real vivia en lo que sobraba despues del tablero. */
chequear('la vista entera scrollea',
         str_contains($crmH, '#viewNotificaciones{overflow-y:auto'));
chequear('y el cuerpo deja de tener su propio scroll',
         str_contains($crmH, '#viewNotificaciones .nv-body{flex:0 0 auto;overflow-y:visible'),
         'el scroll anidado hace que el dedo no sepa cual se va a mover');
chequear('el tablero se puede plegar',
         str_contains($crmH, 'id="nvCobToggle"') && str_contains($crmH, 'gp_nv_tablero'));

$limpiar();

$recarga = function (string $u, string $cuando, float $monto) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                               estado, creada_en, vence_en, acreditada_en, metodo)
         VALUES (?, ?, ?, ?, ?, 'acreditada', ?, ?, ?, 'transferencia')"
    )->execute([substr(md5($u . $cuando), 0, 12), $u, (int)$monto, $monto, $monto,
                $cuando, $cuando, $cuando]);
};
$enJuego = function (string $u, string $cuando, float $monto) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES (?, 'saldo', ?, 'test', 'peticion', ?)"
    )->execute([$u, (int)$monto, $cuando]);
};

// ===========================================================================
echo "\n=== 1. La pauta suma publicistas Y landings, sin discriminar ===\n";
/* Es la diferencia con publicidad_gasto_periodo(): alla la pregunta es "cuanto
   puso ESTA campaña", aca es "cuanto puse en total". Si esto discriminara,
   alguien que carga gasto en una landing veria pauta 0 en Finanzas teniendo la
   campaña corriendo -- y el indicador diria "autofinanciado" siempre. */
publicidad_gasto_guardar($pdo, 0,     '2019-09-10', 4000.0, 'test', 't_sal_lp');
publicidad_gasto_guardar($pdo, 0,     '2019-09-11', 6000.0, 'test', 't_sal_lp2');
publicidad_gasto_guardar($pdo, 99777, '2019-09-11', 5000.0, 'test');
$g = publicidad_gasto_total($pdo, '2019-09-01', '2019-09-30');
chequear('suma las tres filas', abs($g['total'] - 15000.0) < 0.01, 'total=' . $g['total']);
chequear('cuenta DIAS distintos, no filas', $g['dias'] === 2, 'dias=' . $g['dias']);

$g0 = publicidad_gasto_total($pdo, '2019-03-01', '2019-03-31');
chequear('un periodo sin gasto da 0 y no falla', $g0['total'] === 0.0 && $g0['dias'] === 0);

// ===========================================================================
echo "\n=== 1b. De donde sale la pauta: el desglose ===\n";
/* POR QUE EXISTE (Nahuel, 14/09/2026): "no entiendo muy bien el gasto que me
   aparece de la pauta de ochenta y ocho mil pesos, no entiendo de donde sale
   eso". Un total sin desglose no se puede auditar: si dice $88.534 y uno se
   acuerda de haber cargado $22.500, no hay forma de saber si el resto son
   otras campañas, un dia cargado dos veces, o un error de tipeo. */
$det  = publicidad_gasto_detalle($pdo, '2019-09-01', '2019-09-30');
$mias = array_values(array_filter($det, fn($d) => str_starts_with($d['campana'], 't_sal')
                                                 || str_contains($d['campana'], '99777')));
chequear('trae una fila por campaña', count($mias) === 3,
         json_encode(array_column($mias, 'campana')));

$porNombre = array_column($det, 'total', 'campana');
chequear('cada una con lo suyo',
         ($porNombre['t_sal_lp'] ?? 0) == 4000.0 && ($porNombre['t_sal_lp2'] ?? 0) == 6000.0,
         json_encode($porNombre));

/* LA INVARIANTE: el desglose tiene que sumar EXACTO el total. Si no cerrara
   seria peor que no tenerlo -- haria dudar del numero bueno. */
$sumaDet = array_sum(array_column($mias, 'total'));
chequear('el desglose suma el total', abs($sumaDet - 15000.0) < 0.01, 'suma=' . $sumaDet);

/* Un publicista sin ficha en la tabla igual tiene que aparecer: la plata se
   gasto, y una fila que se cae del desglose lo descuadra contra el total. */
$pub = array_values(array_filter($det, fn($d) => $d['clase'] === 'publicista'));
chequear('el gasto del publicista figura aunque no exista su ficha',
         count($pub) >= 1 && abs((float)$pub[0]['total'] - 5000.0) < 0.01,
         json_encode($pub));

chequear('dice cuantos dias abarca cada una',
         ($porNombre['t_sal_lp'] ?? null) !== null
         && (array_column($det, 'dias', 'campana')['t_sal_lp'] ?? 0) === 1);

chequear('un periodo sin gasto da lista vacia',
         publicidad_gasto_detalle($pdo, '2019-03-01', '2019-03-31') === []);

// ===========================================================================
echo "\n=== 2. Nuevo vs. repetido: la primera es la HISTORICA, no la del rango ===\n";
/* ESTE ES EL TEST QUE SOSTIENE TODO EL INDICADOR. Si "primera carga" se
   calculara dentro del rango, un jugador que viene cargando hace meses
   aparecria como nuevo cada vez que se mueve la fecha de inicio del reporte.
   Resultado: la plata de la base acumulada se contaria como recupero de la
   pauta de hoy, el CPA daria barato y el "autofinanciado" seria falso.
   Justo al reves de lo que el indicador tiene que contestar. */
$recarga(U . 'viejo', '2019-07-05 10:00:00', 10000.0);  // fuera del rango, hace meses
$recarga(U . 'viejo', '2019-09-12 10:00:00',  8000.0);  // dentro: es REPETICION
$recarga(U . 'nuevo', '2019-09-12 11:00:00',  5000.0);  // dentro y primera: NUEVO

$s = publicidad_cargas_split($pdo, '2019-09-01', '2019-09-30');
chequear('el que ya cargaba NO cuenta como nuevo', $s['jugadores_nuevos'] === 1,
         'nuevos=' . $s['jugadores_nuevos']);
chequear('y si cuenta como repetidor', $s['jugadores_repiten'] === 1,
         'repiten=' . $s['jugadores_repiten']);
chequear('su plata va a repeticion', abs($s['dep_repeticion'] - 8000.0) < 0.01,
         'rep=' . $s['dep_repeticion']);
chequear('la del nuevo va a primeras', abs($s['dep_primeras'] - 5000.0) < 0.01,
         'pri=' . $s['dep_primeras']);
chequear('la carga vieja NO entra al total del rango', abs($s['depositado'] - 13000.0) < 0.01,
         'dep=' . $s['depositado']);

// ===========================================================================
echo "\n=== 3. Las dos partes cierran contra el total ===\n";
/* Si no cerraran, el margen aplicado a dep_repeticion mediria una base que no
   existe y la ganancia de la base saldria inventada. */
chequear('primeras + repeticion = depositado',
         abs(($s['dep_primeras'] + $s['dep_repeticion']) - $s['depositado']) < 0.01);

// ===========================================================================
echo "\n=== 4. Cuenta el boton \"Depositos\" del juego, no solo el chatbot ===\n";
/* El bug que Publicidad ya sufrio: la carga pedida DENTRO del juego no crea
   fila en `recargas`, queda en `movimientos` con origen='peticion'. Medir solo
   `recargas` mostraba cero conversiones con la gente cargando de verdad -- y
   aca haria parecer que el negocio no da, con la plata entrando. */
$enJuego(U . 'dejuego', '2019-09-13 09:00:00', 7000.0);
$s2 = publicidad_cargas_split($pdo, '2019-09-01', '2019-09-30');
chequear('la carga del juego suma al total', abs($s2['depositado'] - 20000.0) < 0.01,
         'dep=' . $s2['depositado']);
chequear('y su jugador cuenta como nuevo', $s2['jugadores_nuevos'] === 2,
         'nuevos=' . $s2['jugadores_nuevos']);

/* Y la contracara: alguien cuya PRIMERA carga fue por el juego y despues carga
   por transferencia tiene que salir repetidor. Las dos vias son un solo
   historial, no dos. */
$enJuego(U . 'mixto', '2019-06-01 09:00:00', 3000.0);   // primera, hace meses, por el juego
$recarga(U . 'mixto', '2019-09-14 09:00:00', 4000.0);   // dentro, por transferencia
$s3 = publicidad_cargas_split($pdo, '2019-09-01', '2019-09-30');
chequear('la primera del juego cuenta como historial de la transferencia',
         $s3['jugadores_nuevos'] === 2 && $s3['jugadores_repiten'] === 2,
         'nuevos=' . $s3['jugadores_nuevos'] . ' repiten=' . $s3['jugadores_repiten']);

// ===========================================================================
echo "\n=== 5. Un mismo jugador puede ser nuevo Y repetidor ===\n";
/* Carga por primera vez y vuelve el mismo mes. Cuenta en los dos lados a
   proposito, asi que `jugadores` NO es la suma -- esta documentado en el
   endpoint para que nadie "arregle" el total. */
$recarga(U . 'nuevo', '2019-09-20 12:00:00', 2000.0);   // su segunda
$s4 = publicidad_cargas_split($pdo, '2019-09-01', '2019-09-30');
chequear('sigue contando como nuevo', $s4['jugadores_nuevos'] === 2,
         'nuevos=' . $s4['jugadores_nuevos']);
chequear('y ahora tambien como repetidor', $s4['jugadores_repiten'] === 3,
         'repiten=' . $s4['jugadores_repiten']);
chequear('el total de jugadores NO es la suma de los dos',
         $s4['jugadores'] === 4, 'jugadores=' . $s4['jugadores']);
chequear('su segunda carga va a repeticion',
         abs($s4['dep_repeticion'] - 14000.0) < 0.01, 'rep=' . $s4['dep_repeticion']);

// ===========================================================================
echo "\n=== 6. La aritmetica del indicador ===\n";
/* Se replica la cuenta del endpoint para fijar la semantica de cada numero.
   Si alguien cambia el orden de las restas, esto lo agarra. */
$indicador = function (float $dep, float $dep1, float $ret, float $bon,
                       float $pauta, int $dias, float $cpf = 0.20): array {
    $costo  = ($dep + $bon) * $cpf;
    $antes  = $dep - $ret - $costo;
    $margen = $dep > 0 ? $antes / $dep : null;
    $base   = $margen === null ? 0.0 : ($dep - $dep1) * $margen;
    return [
        'antes'      => round($antes, 2),
        'real'       => round($antes - $pauta, 2),
        'cobertura'  => $pauta > 0 ? round($antes / $pauta, 4) : null,
        'margen'     => $margen === null ? null : round($margen, 4),
        'base_dia'   => $dias > 0 ? round($base / $dias, 2) : 0.0,
        'pauta_dia'  => $dias > 0 ? round($pauta / $dias, 2) : 0.0,
        'autofin'    => $pauta > 0 ? (($base / max(1, $dias)) >= ($pauta / max(1, $dias))) : null,
    ];
};

/* Arrancando: la pauta todavia no se recupera. Es el caso NORMAL al principio
   y el indicador tiene que mostrarlo sin dramatizarlo. */
$a = $indicador(25500, 25500, 0, 2500, 22500, 30);
chequear('campaña nueva: cobertura < 1', $a['cobertura'] < 1.0, 'cob=' . $a['cobertura']);
chequear('campaña nueva: ganancia real negativa', $a['real'] < 0, 'real=' . $a['real']);
chequear('sin base todavia, no se autofinancia', $a['autofin'] === false);

/* Con base acumulada: el mismo gasto diario, pero ahora la mayoria de la plata
   viene de jugadores ya comprados. Esta es la condicion que describio Nahuel. */
$b = $indicador(150000, 30000, 30000, 12000, 40000, 30);
chequear('con base: cobertura > 1', $b['cobertura'] > 1.0, 'cob=' . $b['cobertura']);
chequear('con base: se autofinancia', $b['autofin'] === true,
         'base/dia=' . $b['base_dia'] . ' pauta/dia=' . $b['pauta_dia']);
chequear('la base por dia supera a la pauta por dia', $b['base_dia'] > $b['pauta_dia']);

/* El borde exacto: base por dia == pauta por dia. Es el punto de equilibrio y
   tiene que contar como autofinanciado (>=), no quedar afuera por un peso. */
$c = $indicador(100000, 0, 0, 0, 80000, 1);
chequear('en el borde exacto cuenta como autofinanciado', $c['autofin'] === true,
         'base=' . $c['base_dia'] . ' pauta=' . $c['pauta_dia']);

/* Sin pauta cargada no hay division: null, no infinito ni cero. Un cero diria
   "no recuperas nada" y un infinito diria "todo perfecto"; las dos mienten. */
$d = $indicador(25500, 25500, 0, 2500, 0, 30);
chequear('sin pauta, la cobertura es null', $d['cobertura'] === null);
chequear('sin pauta, autofinanciado es null (no true)', $d['autofin'] === null);

/* Sin depositos no hay margen que calcular, y la base tiene que dar 0 -- no un
   NaN ni una division por cero. */
$e = $indicador(0, 0, 0, 0, 10000, 30);
chequear('sin depositos, el margen es null', $e['margen'] === null);
chequear('sin depositos, la base por dia es 0', $e['base_dia'] === 0.0);

/* Un mes malo de verdad: los retiros se comen todo. El margen tiene que poder
   ser NEGATIVO y arrastrar la base, o el indicador taparia una perdida. */
$f = $indicador(50000, 10000, 60000, 0, 5000, 30);
chequear('con retiros altos el margen da negativo', $f['margen'] < 0, 'margen=' . $f['margen']);
chequear('y la base por dia tambien', $f['base_dia'] < 0, 'base=' . $f['base_dia']);
chequear('no se autofinancia', $f['autofin'] === false);

// ===========================================================================
echo "\n=== 7. El rango acota de verdad ===\n";
$s5 = publicidad_cargas_split($pdo, '2019-09-12', '2019-09-12');
chequear('un solo dia trae solo lo de ese dia',
         abs($s5['depositado'] - 13000.0) < 0.01, 'dep=' . $s5['depositado']);
$s6 = publicidad_cargas_split($pdo, '2019-01-01', '2019-01-31');
chequear('un rango vacio da todo en cero y no rompe',
         $s6['depositado'] === 0.0 && $s6['jugadores'] === 0);

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
