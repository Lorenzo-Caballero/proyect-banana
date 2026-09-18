<?php
/**
 * t_limites.php — Los limites de carga y retiro, por cliente.
 *
 * Dos cosas que importan y por eso se prueban las dos:
 *
 *  1. Que los limites configurados se APLIQUEN. Un limite que solo esta
 *     escrito en el prompt es una sugerencia: el modelo lo puede ignorar.
 *  2. Que sin configurar nada, el comportamiento sea EL DE ANTES. Estos
 *     limites eran constantes en el codigo; si el default cambiara la
 *     conducta, cada cliente que ya esta andando se veria afectado por un
 *     despliegue que no pidio.
 *
 *     php t_limites.php
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
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/fichas_lib.php';
require_once __DIR__ . '/api/chatbot_contexto.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function ponerLimite(PDO $pdo, string $clave, string $valor): void {
    cfg_crm_guardar($pdo, [$clave => $valor], 'test');
}
function limpiarLimites(PDO $pdo): void {
    cfg_crm_guardar($pdo, [
        'lim_carga_min' => '100', 'lim_carga_max' => '500000',
        'lim_retiro_min' => '100', 'lim_retiro_max_dia' => '0',
        'lim_retiro_max' => '0', 'lim_retiro_cant_dia' => '0',
    ], 'test');
}

$ID = 987654323;
function prepararJugador(PDO $pdo, int $id, float $balance): void {
    $pdo->exec("DELETE FROM usuarios WHERE id=$id");
    $pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
    $pdo->prepare("INSERT INTO usuarios (id,username,coins,balance) VALUES (?,?,?,?)")
        ->execute([$id, 'test_lim', 1000000, $balance]);
}

// ===========================================================================
echo "\n=== 1. Sin configurar: los valores de siempre (no cambia nadie) ===\n";
limpiarLimites($pdo);
chequear('carga minima 100',    fichas_limite($pdo, 'lim_carga_min', FICHAS_MIN_CARGA) === 100);
chequear('carga maxima 500000', fichas_limite($pdo, 'lim_carga_max', FICHAS_MAX_CARGA) === 500000);
chequear('sin tope diario',     fichas_limite($pdo, 'lim_retiro_max_dia', 0) === 0);

echo "\n=== 2. Un valor invalido NO se convierte en 'sin limite' ===\n";
// El lado seguro: si alguien deja basura, se usa el default, no se abre todo.
ponerLimite($pdo, 'lim_carga_min', 'muchas');
chequear('texto -> cae al default', fichas_limite($pdo, 'lim_carga_min', 100) === 100);
ponerLimite($pdo, 'lim_carga_min', '');
chequear('vacio -> cae al default', fichas_limite($pdo, 'lim_carga_min', 100) === 100);

// ===========================================================================
echo "\n=== 3. Carga minima de 500: 400 se rechaza, 500 pasa ===\n";
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_carga_min', '500');
prepararJugador($pdo, $ID, 0);
$r = fichas_pedir_carga($pdo, 'test_lim', 400, 'test');
chequear('400 se rechaza',       empty($r['ok']) && ($r['codigo'] ?? '') === 'monto_bajo', json_encode($r));
chequear('avisa el minimo real', strpos((string)($r['error'] ?? ''), '500') !== false, (string)($r['error'] ?? ''));
$r = fichas_pedir_carga($pdo, 'test_lim', 500, 'test');
chequear('500 pasa',             !empty($r['ok']), json_encode($r));

// ===========================================================================
echo "\n=== 4. Retiro minimo propio, distinto del de carga ===\n";
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_carga_min',  '100');
ponerLimite($pdo, 'lim_retiro_min', '2000');
prepararJugador($pdo, $ID, 50000);
$r = fichas_pedir_retiro($pdo, 'test_lim', 1000, 'test');
chequear('retiro de 1000 se rechaza', empty($r['ok']) && ($r['codigo'] ?? '') === 'monto_bajo', json_encode($r));
chequear('avisa 2.000, no 100',       strpos((string)($r['error'] ?? ''), '2.000') !== false, (string)($r['error'] ?? ''));

// ===========================================================================
echo "\n=== 5. Tope diario: cuenta lo que ya cobro hoy ===\n";
// Escenario real: retiro 8.000 mas temprano (ya pagado) y ahora pide 5.000.
// Se usa 'hecha' y no 'pendiente' porque hay una guarda anterior que impide
// tener dos retiros pendientes a la vez -- el tope se suma igual sobre los
// pendientes, pero por ese camino no se llega.
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_retiro_min', '100');
ponerLimite($pdo, 'lim_retiro_max_dia', '10000');
prepararJugador($pdo, $ID, 500000);
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',8000,'hecha',NOW())")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('8000 cobrados + 5000 supera el tope',
         empty($r['ok']) && ($r['codigo'] ?? '') === 'tope_diario', json_encode($r));
chequear('dice cuanto le queda hoy (2.000)',
         strpos((string)($r['error'] ?? ''), '2.000') !== false, (string)($r['error'] ?? ''));
$r = fichas_pedir_retiro($pdo, 'test_lim', 2000, 'test');
chequear('justo el remanente (2000) SI pasa', !empty($r['ok']), json_encode($r));

echo "\n=== 5b. Lo de AYER no cuenta para el tope de hoy ===\n";
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',9000,'hecha',DATE_SUB(NOW(), INTERVAL 1 DAY))")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('el tope arranca de cero cada dia', !empty($r['ok']), json_encode($r));

echo "\n=== 6. Un retiro rechazado NO consume el tope del dia ===\n";
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',9000,'error',NOW())")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('el rechazado no cuenta', !empty($r['ok']), json_encode($r));

// ===========================================================================
echo "\n=== 6b. Dos retiros de 50.000 por dia (el caso que pidio Nahuel) ===\n";
/* Los tres numeros juntos: tope por pedido 50.000, tope diario 100.000 y dos
   pedidos por dia. Con el tope diario SOLO, el jugador se lleva los 100.000 de
   una; con la cantidad SOLA, hace dos de 100.000. Hacen falta los dos. */
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_retiro_max',      '50000');
ponerLimite($pdo, 'lim_retiro_max_dia',  '100000');
ponerLimite($pdo, 'lim_retiro_cant_dia', '2');
prepararJugador($pdo, $ID, 500000);

$r = fichas_pedir_retiro($pdo, 'test_lim', 100000, 'test');
chequear('pedir los 100.000 de una: lo frena el tope POR RETIRO',
         empty($r['ok']) && ($r['codigo'] ?? '') === 'monto_alto', json_encode($r));
chequear('y le dice el numero (50.000)',
         strpos((string)($r['error'] ?? ''), '50.000') !== false, (string)($r['error'] ?? ''));

$r = fichas_pedir_retiro($pdo, 'test_lim', 50000, 'test');
chequear('el primero de 50.000 pasa', !empty($r['ok']), json_encode($r));
$pdo->exec("UPDATE acciones_saldo SET estado='hecha' WHERE usuario='test_lim'");
$r = fichas_pedir_retiro($pdo, 'test_lim', 50000, 'test');
chequear('el segundo de 50.000 tambien', !empty($r['ok']), json_encode($r));
$pdo->exec("UPDATE acciones_saldo SET estado='hecha' WHERE usuario='test_lim'");

/* El tercero choca con los DOS topes a la vez (dos de 50.000 agotan tambien
   los 100.000 del dia). Gana el de monto porque se evalua antes, y esta bien:
   los dos mensajes dicen lo mismo -- hoy no hay mas, manana si. Lo que importa
   es que NO pase, y que se lo diga de una forma accionable. El caso 6c aisla
   el tope de cantidad, que es el que no existia. */
$r = fichas_pedir_retiro($pdo, 'test_lim', 10000, 'test');
chequear('el tercero NO, aunque sea chico', empty($r['ok']), json_encode($r));
chequear('frenado por un tope del DIA (monto o cantidad)',
         in_array($r['codigo'] ?? '', ['tope_diario', 'tope_cantidad'], true),
         (string)($r['codigo'] ?? ''));
chequear('y le dice que manana puede', stripos((string)($r['error'] ?? ''), 'ana pod') !== false,
         (string)($r['error'] ?? ''));

echo "\n=== 6c. Cada limite anda solo, sin el otro ===\n";
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_retiro_cant_dia', '1');
prepararJugador($pdo, $ID, 500000);
$r = fichas_pedir_retiro($pdo, 'test_lim', 90000, 'test');
chequear('sin tope de monto, un pedido grande pasa', !empty($r['ok']), json_encode($r));
$pdo->exec("UPDATE acciones_saldo SET estado='hecha' WHERE usuario='test_lim'");
$r = fichas_pedir_retiro($pdo, 'test_lim', 100, 'test');
chequear('pero el segundo del dia no', ($r['codigo'] ?? '') === 'tope_cantidad', json_encode($r));
chequear('y lo dice en singular cuando el limite es 1',
         strpos((string)($r['error'] ?? ''), 'un retiro por') !== false, (string)($r['error'] ?? ''));

// Un retiro RECHAZADO no gasta cupo de cantidad (mismo criterio que el monto).
// 'cancelada' y no 'rechazada': el ENUM de acciones_saldo no tiene 'rechazada'
// (pendiente/procesando/hecha/error/revisar/cancelada). Con sql_mode laxo el
// valor invalido truncaba a '' y el test pasaba de casualidad; en una MariaDB
// estricta explotaba con "Data truncated". Prueba lo mismo: un estado fuera
// del IN(...) del cupo no cuenta.
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_retiro_cant_dia', '1');
prepararJugador($pdo, $ID, 500000);
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',5000,'cancelada',NOW())")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('un retiro rechazado NO consume el cupo de cantidad',
         !empty($r['ok']), json_encode($r));


echo "\n=== 7. El prompt: las REGLAS FIJAS van al final ===\n";
// Si las indicaciones del operador quedaran despues, le ganarian al
// procedimiento -- que es exactamente como se rompio el cobro en produccion.
$p = chatbot_armar_prompt(
    ['reglas_extra' => 'MARCA_DEL_OPERADOR'],
    ['carga_min' => 500, 'carga_max' => 0, 'retiro_min' => 2000, 'retiro_max_dia' => 100000]
);
$posOperador = strpos($p, 'MARCA_DEL_OPERADOR');
$posFijas    = strpos($p, 'ESTO MANDA SOBRE TODO LO ANTERIOR');
chequear('las reglas fijas van DESPUES de lo del operador',
         $posOperador !== false && $posFijas !== false && $posFijas > $posOperador,
         "operador=$posOperador fijas=$posFijas");
chequear('el prompt dice la carga minima',   strpos($p, '500') !== false);
chequear('el prompt dice el tope diario',    strpos($p, '100.000') !== false);
chequear('no inventa un maximo si es 0',     strpos($p, 'Carga MAXIMA') === false);

// ===========================================================================
echo "\n=== Ventana horaria de retiros ===\n";

/* Es logica con dos trampas: cruza la medianoche, y se evalua en hora
   ARGENTINA aunque el server corra en UTC. Las dos se prueban usando la hora
   actual como referencia, asi el test vale corra a la hora que corra. */
$ahoraAr = fichas_ahora_ar();
$hh      = (int)$ahoraAr->format('G');
$hm      = (string)$ahoraAr->format('i');
$hora    = static fn(int $h) => sprintf('%02d:%s', ($h + 24) % 24, $hm);

// Sin configurar: se retira siempre, como venia funcionando.
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => '', 'lim_retiro_hora_hasta' => ''], 'test');
$v = fichas_ventana_retiro($pdo);
chequear('sin horario configurado, siempre abierto', $v['abierta'] === true);

// Una sola de las dos no alcanza para definir una franja.
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => '03:00', 'lim_retiro_hora_hasta' => ''], 'test');
chequear('con una sola punta cargada, no aplica',
         fichas_ventana_retiro($pdo)['abierta'] === true);

// Franja que CONTIENE la hora actual -> cerrado.
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => $hora($hh - 1),
                       'lim_retiro_hora_hasta' => $hora($hh + 2)], 'test');
chequear('dentro de la franja, cerrado',
         fichas_ventana_retiro($pdo)['abierta'] === false,
         'ahora AR=' . $ahoraAr->format('H:i'));

// Y el retiro se rechaza con el codigo que el bot sabe explicar. Se limpia
// primero: las secciones de arriba dejaron pedidos suyos y lo que se mide aca
// es que ESTE no encole nada.
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$r = fichas_pedir_retiro($pdo, 'test_lim', 500, 'test');
chequear('el retiro devuelve fuera_de_horario',
         ($r['codigo'] ?? '') === 'fuera_de_horario', json_encode($r));
$n = (int)$pdo->query("SELECT COUNT(*) FROM acciones_saldo
                        WHERE usuario='test_lim' AND tipo='retirar'")->fetchColumn();
chequear('y NO deja el pedido encolado', $n === 0, "encolados=$n");

// Franja que NO contiene la hora actual -> abierto.
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => $hora($hh + 2),
                       'lim_retiro_hora_hasta' => $hora($hh + 4)], 'test');
chequear('fuera de la franja, abierto',
         fichas_ventana_retiro($pdo)['abierta'] === true);

/* La franja que cruza la medianoche es el caso REAL (nadie aprueba de
   madrugada) y el que un `if` ingenuo rompe: con desde > hasta, comparar
   "entre los dos" da siempre falso. */
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => $hora($hh - 1),
                       'lim_retiro_hora_hasta' => $hora($hh + 1)], 'test');
$cruza = fichas_ventana_retiro($pdo);
chequear('franja que cruza la medianoche: cerrado si estoy adentro',
         $cruza['abierta'] === false, 'desde=' . $cruza['desde'] . ' hasta=' . $cruza['hasta']);

// Un horario mal cargado NO bloquea: un typo del operador no puede dejar a
// todos sin poder cobrar.
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => '25:00',
                       'lim_retiro_hora_hasta' => 'ocho'], 'test');
chequear('un horario invalido se ignora en vez de bloquear',
         fichas_ventana_retiro($pdo)['abierta'] === true);

cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => '', 'lim_retiro_hora_hasta' => ''], 'test');

// ===========================================================================
echo "\n=== El tope diario usa el dia ARGENTINO ===\n";

/* Antes se comparaba con CURDATE(), y con la base en UTC el tope se reseteaba
   a las 21:00 hora argentina. Un retiro hecho a las 22:00 AR de hoy quedaba en
   "manana" para el conteo y liberaba el cupo entero. */
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
ponerLimite($pdo, 'lim_retiro_max_dia', '1000');

// Se inserta un retiro fechado HOY en hora argentina pero que, pasado a UTC,
// cae en la fecha de MAÑANA: exactamente el caso que el bug dejaba escapar.
/* Se inserta un retiro en la ULTIMA hora del dia argentino -- justo la franja
   que el bug dejaba escapar, porque en UTC ya es el dia siguiente. La fila se
   fecha con el mismo criterio que usa el codigo (fichas_rango_dia_ar), asi el
   test vale tanto con la base en UTC (produccion) como en hora argentina
   (el MySQL local). Esa diferencia entre entornos fue justamente la que hizo
   fallar el primer intento de este arreglo.

   Va como 'hecha' y no 'pendiente': un retiro pendiente dispara antes la
   guarda de "ya tenes uno en curso" y el test no llegaria a medir el tope. */
$dia = fichas_rango_dia_ar($pdo);
$ultimaHora = (new DateTime($dia['hasta']))->modify('-30 minutes')->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, estado, creada_en)
               VALUES ('test_lim','retirar',900,'hecha',?)")->execute([$ultimaHora]);

$r = fichas_pedir_retiro($pdo, 'test_lim', 500, 'test');
chequear('un retiro del final del dia AR cuenta para el tope de HOY',
         ($r['codigo'] ?? '') === 'tope_diario',
         'fila=' . $ultimaHora . ' rango=' . $dia['desde'] . '..' . $dia['hasta']
         . ' -> ' . json_encode($r));

// Y el borde de al lado: media hora DESPUES ya es manana y no debe contar.
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$manana = (new DateTime($dia['hasta']))->modify('+30 minutes')->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, estado, creada_en)
               VALUES ('test_lim','retirar',900,'hecha',?)")->execute([$manana]);
$r = fichas_pedir_retiro($pdo, 'test_lim', 500, 'test');
chequear('y uno ya pasado el corte NO cuenta', !empty($r['ok']), json_encode($r));

$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
ponerLimite($pdo, 'lim_retiro_max_dia', '0');

// ===========================================================================
echo "\n=== El bot no desmiente al jugador con un saldo viejo ===\n";

/* EL CASO (Nahuel, 18/09/2026): *"muchas veces las personas dicen quiero
   retirar 5000 y el bot le dice no tenes 5000, tenes 1000"*.

   Cuando eso pasa con una lectura fresca, el bot tiene razon. Cuando pasa con
   una de hace cinco minutos, el bot esta discutiendo con un numero que ya no
   existe -- y el jugador, que acaba de ver su saldo en la pantalla del juego,
   sabe que le estan mintiendo.

   `usuarios.balance` es un ESPEJO: lo refresca el colector. `saldo_visto_en`
   (migracion 68) dice CUANDO se leyo, y hasta hoy nadie lo miraba para esto. */
limpiarLimites($pdo);

// --- lectura FRESCA: el bot puede (y debe) decir que no alcanza -------------
prepararJugador($pdo, $ID, 1000);
$pdo->exec("UPDATE usuarios SET saldo_visto_en = NOW() WHERE id=$ID");
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('con el saldo recien leido, sigue diciendo que no alcanza',
         ($r['codigo'] ?? '') === 'sin_saldo',
         'codigo=' . ($r['codigo'] ?? '?') . ' -- desmentir con un dato fresco esta bien');

// --- lectura VIEJA: mismo saldo, misma plata, otra respuesta ----------------
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->exec("UPDATE usuarios SET saldo_visto_en = NOW() - INTERVAL 10 MINUTE WHERE id=$ID");
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('con el saldo viejo, NO lo desmiente',
         ($r['codigo'] ?? '') === 'saldo_incierto',
         'codigo=' . ($r['codigo'] ?? '?'));
chequear('y lo dice como lo que es: lo que le FIGURA',
         str_contains(mb_strtolower($r['error'] ?? ''), 'me figura'),
         'error=' . ($r['error'] ?? ''));
chequear('sin afirmar que no le alcanza',
         !str_contains(mb_strtolower($r['error'] ?? ''), 'y querés retirar'));

/* Y NO CREA EL PEDIDO. Lo que se decidio es avisar, no encolar a ciegas: un
   retiro creado sobre un saldo que no sabemos es un pedido que alguien tiene
   que cancelar despues. */
$hay = (int)$pdo->query("SELECT COUNT(*) FROM acciones_saldo
                          WHERE usuario='test_lim' AND tipo='retirar'")->fetchColumn();
chequear('y no deja un pedido colgado', $hay === 0, "pedidos=$hay");

/* El mismo criterio en el chequeo del minimo, que afirmaba igual. */
$pdo->exec("UPDATE usuarios SET balance = 10 WHERE id=$ID");
$pdo->exec("UPDATE usuarios SET saldo_visto_en = NOW() - INTERVAL 10 MINUTE WHERE id=$ID");
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('el minimo tampoco se afirma con un dato viejo',
         ($r['codigo'] ?? '') === 'saldo_incierto',
         'codigo=' . ($r['codigo'] ?? '?'));

/* NUNCA LEIDO es el peor caso, no el mejor: sin fecha no sabemos nada de ese
   numero, asi que se trata como viejo. */
$pdo->exec("UPDATE usuarios SET balance = 1000, saldo_visto_en = NULL WHERE id=$ID");
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('un saldo que nunca leimos tampoco se afirma',
         ($r['codigo'] ?? '') === 'saldo_incierto',
         'codigo=' . ($r['codigo'] ?? '?'));

/* Derivar no puede depender de que el modelo obedezca una regla del prompt: el
   que queda esperando es alguien que dice tener plata y no puede sacarla. */
$srcChat = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('el chat lo pasa a un agente sin preguntarle al modelo',
         str_contains($srcChat, "=== 'saldo_incierto'")
         && str_contains($srcChat, "'pasar_a_agente'"));
$srcCtx = file_get_contents(__DIR__ . '/api/chatbot_contexto.php');
chequear('y el prompt le dice que no discuta el numero',
         str_contains($srcCtx, 'saldo_incierto'));

// ===========================================================================
limpiarLimites($pdo);
$pdo->exec("DELETE FROM usuarios WHERE id=$ID");
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->exec("DELETE FROM movimientos WHERE usuario='test_lim'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
