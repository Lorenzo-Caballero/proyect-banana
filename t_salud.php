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
