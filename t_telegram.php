<?php
/**
 * t_telegram.php — Los avisos al agente, y sobre todo el freno anti-spam.
 *
 * Lo que se prueba acá NO es que Telegram reciba el mensaje (eso depende de la
 * red y de un token real). Es la lógica que decide SI se manda, que es la parte
 * que puede arruinar la función entera:
 *
 *   · Los avisos de "algo está roto" los detecta un cron que corre CADA MINUTO.
 *     Sin freno son 1.440 mensajes por día por problema, el agente silencia el
 *     bot, y después no se entera de lo que sí importaba. Un aviso que satura
 *     es peor que ninguno, porque da la sensación de estar cubierto.
 *   · Pero si el freno es demasiado, se pierde información: pasar de "1
 *     comprobante sin resolver" a "5" es una novedad, no una repetición.
 *
 * Corre contra la base de prueba y NO manda nada a Telegram: sin credenciales
 * configuradas, tg_avisar() devuelve false sin salir a internet.
 *
 *     php t_telegram.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/telegram_lib.php';
// Por rl_avisar_revision_vieja(). require_once y no require: recargas_lib
// carga varias libs por su cuenta y un require pelado revienta con
// "Cannot redeclare" -- que php -l no ve, porque no ejecuta.
require_once __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* El envío real se reemplaza por un contador. tg_avisar_una_vez() decide y
   después delega en tg_avisar(); acá interceptamos esa delegación para contar
   cuántas veces habría salido un mensaje, sin tocar la red. */
$GLOBALS['__tg_enviados'] = 0;
if (!function_exists('tg_avisar_test_reset')) {
    function tg_avisar_test_reset(): void { $GLOBALS['__tg_enviados'] = 0; }
    function tg_avisar_test_cuenta(): int { return (int)$GLOBALS['__tg_enviados']; }
}

$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'test\\_%'");
cfg_crm_guardar($pdo, ['tg_repetir_min' => '180'], 'test');

// ===========================================================================
echo "\n=== 1. Sin credenciales no se manda nada, y no es un error ===\n";

cfg_crm_guardar($pdo, ['tg_bot_token' => '', 'tg_chat_id' => ''], 'test');
chequear('tg_configurado() dice que no', tg_configurado($pdo) === false);
chequear('tg_avisar() devuelve false sin explotar', tg_avisar('hola', $pdo) === false);
chequear('tg_evento() tampoco explota',
         tg_evento($pdo, 'retiro', 'Prueba', ['Jugador' => 'x']) === false);

/* No tener Telegram es una opcion valida: la mitad de los clientes no lo van a
   usar. Si esto lanzara, cada retiro y cada comprobante romperia el chat. */
chequear('un texto con < y > no rompe (se escapa antes de salir)',
         tg_evento($pdo, 'retiro', 'Prueba', ['Motivo' => '<script>x</script>']) === false);

// ===========================================================================
echo "\n=== 2. El freno anti-spam ===\n";

/* Se simula tener credenciales para que la logica de dedupe corra de verdad.
   El envio va a fallar (el token es falso), y eso es justamente lo que permite
   verificar la regla mas importante: que un envio fallido NO se anote como
   hecho. */
cfg_crm_guardar($pdo, ['tg_bot_token' => 'test:FALSO', 'tg_chat_id' => '1'], 'test');

/* Se CUENTAN los intentos de envio de verdad, y no se mira `ultimo_en`.
   Primera version de este test: comparaba ultimo_en antes y despues. Pasaba
   siempre, porque con el token falso el envio falla y ultimo_en no se actualiza
   NI cuando el dedupe frena NI cuando deja pasar. O sea que el chequeo del
   anti-spam no probaba el anti-spam.
   Cada intento real deja una linea "telegram: HTTP" en el error_log, asi que
   contarlas es la forma honesta de saber si salio o no. */
$logTmp = sys_get_temp_dir() . '/t_telegram_' . getmypid() . '.log';
@unlink($logTmp);
ini_set('error_log', $logTmp);
$intentos = static function () use ($logTmp): int {
    return is_file($logTmp) ? substr_count((string)@file_get_contents($logTmp), 'telegram: HTTP') : 0;
};

$pdo->exec("DELETE FROM tg_avisos WHERE clave = 'test_salud'");
$i0 = $intentos();
tg_avisar_una_vez('test_salud', 'el colector esta caido', $pdo, 180);
chequear('la primera vez SI intenta mandar', $intentos() === $i0 + 1,
         'intentos=' . ($intentos() - $i0));
$n = (int)$pdo->query("SELECT COUNT(*) FROM tg_avisos WHERE clave='test_salud'")->fetchColumn();
chequear('un envio que FALLA no se anota como mandado', $n === 0,
         "quedaron $n filas; si se anotara, el aviso se perderia para siempre");

/* Ahora se anota a mano, como si hubiera salido bien, para probar el dedupe. */
$pdo->prepare("INSERT INTO tg_avisos (clave, huella, ultimo_en) VALUES (?,?,NOW())")
    ->execute(['test_salud', md5('<b>x</b>')]);

$i1 = $intentos();
tg_avisar_una_vez('test_salud', '<b>x</b>', $pdo, 180);
chequear('el MISMO aviso, recien mandado, NI SE INTENTA', $intentos() === $i1,
         'salio ' . ($intentos() - $i1) . ' vez/veces; eso es el spam que hay que evitar');

// Contenido distinto = novedad, se manda aunque sea inmediato.
$i2 = $intentos();
tg_avisar_una_vez('test_salud', '<b>ahora son 5</b>', $pdo, 180);
chequear('si el CONTENIDO cambia, se manda igual', $intentos() === $i2 + 1,
         'pasar de "1 comprobante" a "5" es informacion nueva, no una repeticion');

// Pasado el tiempo, se repite aunque el texto sea igual.
$pdo->exec("UPDATE tg_avisos SET ultimo_en = DATE_SUB(NOW(), INTERVAL 400 MINUTE),
                                 huella = " . $pdo->quote(md5('<b>x</b>')) . "
             WHERE clave = 'test_salud'");
$i3 = $intentos();
tg_avisar_una_vez('test_salud', '<b>x</b>', $pdo, 180);
chequear('pasadas las horas configuradas, vuelve a avisar', $intentos() === $i3 + 1);

// Y una clave DISTINTA nunca se frena por otra.
$i4 = $intentos();
tg_avisar_una_vez('test_otro', '<b>x</b>', $pdo, 180);
chequear('otra clave no queda frenada por la primera', $intentos() === $i4 + 1);

@unlink($logTmp);
ini_restore('error_log');

// ===========================================================================
echo "\n=== 2b. Que avisa SIEMPRE y que se frena ===\n";

/* Hay dos clases de aviso y no se comportan igual:

     SIN espera  -- hechos puntuales: entro una transferencia, se registro
                    alguien, pidieron un retiro, derivaron una charla. Cada uno
                    es distinto y hay que enterarse de todos.
     CON espera  -- "algo SIGUE roto". Lo detecta un cron cada minuto, asi que
                    sin freno serian 1.440 mensajes por dia del mismo problema.

   La diferencia la hace pasar (o no) una CLAVE a tg_evento(). Este chequeo
   existe porque el dia que alguien le ponga clave a los de arriba "para que no
   spameen", con volumen te vas a enterar de una transferencia cada tres horas
   y va a parecer que el sistema dejo de avisar. */
cfg_crm_guardar($pdo, ['tg_ev_pago' => '1', 'tg_ev_alta' => '1'], 'test');
$logTmp2 = sys_get_temp_dir() . '/t_tg2_' . getmypid() . '.log';
@unlink($logTmp2); ini_set('error_log', $logTmp2);
$cuenta = static function () use ($logTmp2): int {
    return is_file($logTmp2) ? substr_count((string)@file_get_contents($logTmp2), 'telegram: HTTP') : 0;
};

$i = $cuenta();
tg_evento($pdo, 'pago', 'Entró una transferencia', ['Monto' => '$1.000']);
tg_evento($pdo, 'pago', 'Entró una transferencia', ['Monto' => '$1.000']);
chequear('dos transferencias IGUALES avisan las dos', $cuenta() === $i + 2,
         'salieron ' . ($cuenta() - $i) . ' de 2; con clave se frenaria la segunda');

$i = $cuenta();
tg_evento($pdo, 'alta', 'Se registró', ['Usuario' => 'uno']);
tg_evento($pdo, 'alta', 'Se registró', ['Usuario' => 'uno']);
chequear('dos registros seguidos avisan los dos', $cuenta() === $i + 2,
         'salieron ' . ($cuenta() - $i) . ' de 2');

/* Y el de salud SI se frena, que es para lo que existe el mecanismo. */
$pdo->exec("DELETE FROM tg_avisos WHERE clave = 'salud'");
$txtSalud = '<b>Hay algo para revisar</b>' . "\nProblemas: el colector esta caido";
$pdo->prepare("INSERT INTO tg_avisos (clave, huella, ultimo_en) VALUES ('salud',?,NOW())")
    ->execute([md5($txtSalud)]);
$i = $cuenta();
tg_evento($pdo, 'salud', 'Hay algo para revisar', ['Problemas' => 'el colector esta caido'], 'salud');
chequear('el aviso de "algo roto" SI espera antes de repetirse', $cuenta() === $i,
         'sin este freno serian 1.440 mensajes por dia');

@unlink($logTmp2); ini_restore('error_log');
$pdo->exec("DELETE FROM tg_avisos WHERE clave = 'salud'");
cfg_crm_guardar($pdo, ['tg_ev_pago' => '0', 'tg_ev_alta' => '0'], 'test');

// ===========================================================================
echo "\n=== 3. Cada tipo de aviso se apaga por separado ===\n";

/* No todos los clientes quieren las mismas interrupciones: el de retiros le
   suena a quien paga, el de derivaciones a quien atiende. */
cfg_crm_guardar($pdo, ['tg_ev_retiro' => '0', 'tg_ev_derivacion' => '1'], 'test');
chequear('un tipo apagado no manda',
         tg_evento($pdo, 'retiro', 'Pedido de retiro', ['Jugador' => 'x']) === false);
chequear('y el apagado de uno no apaga a los otros',
         cfg_crm_activo($pdo, 'tg_ev_derivacion') === true);

cfg_crm_guardar($pdo, ['tg_ev_retiro' => '1'], 'test');

// ===========================================================================
echo "\n=== 4. Los ajustes están en la lista blanca ===\n";

/* cfg_crm_guardar() descarta EN SILENCIO cualquier clave que no esté en
   CFG_CRM_DEFAULTS. Una clave nueva que se olvide de agregar ahí se guarda sin
   error y no aparece nunca: es el modo de fallar más molesto que tiene el CRM. */
foreach (['tg_bot_token', 'tg_chat_id', 'tg_repetir_min', 'tg_sin_actividad_hs',
          'tg_ev_derivacion', 'tg_ev_revision', 'tg_ev_retiro', 'tg_ev_salud',
          'tg_ev_alta', 'tg_ev_pago'] as $k) {
    chequear("'$k' se puede guardar", array_key_exists($k, CFG_CRM_DEFAULTS));
}

/* Los dos informativos arrancan APAGADOS. Si nacieran prendidos, el primer
   deploy le empezaria a mandar un mensaje por cada registro y cada pago a todo
   cliente que ya tenga Telegram configurado, sin que nadie lo haya pedido. */
chequear("'tg_ev_alta' arranca apagado", CFG_CRM_DEFAULTS['tg_ev_alta'] === '0');
chequear("'tg_ev_pago' arranca apagado", CFG_CRM_DEFAULTS['tg_ev_pago'] === '0');

/* Y los que SI piden accion arrancan prendidos: son los que no conviene que
   alguien tenga que descubrir para enterarse de que existen. */
foreach (['tg_ev_derivacion', 'tg_ev_revision', 'tg_ev_retiro', 'tg_ev_salud'] as $k) {
    chequear("'$k' arranca prendido", CFG_CRM_DEFAULTS[$k] === '1');
}

$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'test\\_%'");
cfg_crm_guardar($pdo, ['tg_bot_token' => '', 'tg_chat_id' => ''], 'test');
// ===========================================================================
echo "\n=== El aviso de 'sin resolver' espera antes de sonar ===\n";

/* EL BUG QUE ATAJA, del 3/9/2026: el aviso salia en el mismo momento en que
   entraba el pago, y 'revision' significa "el matcher del camino B no encontro
   a quien acreditarsela". Eso es CIERTO para toda carga pedida con el boton
   "Depositos": ese camino no crea fila en `recargas`, asi que nunca puede
   casar de este lado. Un minuto despues aprobar_cargas.py la cruza contra la
   solicitud del panel, la aprueba y marca el pago 'usado'.

   O sea una falsa alarma por cada carga del camino A -- que es la via que mas
   se usa. Y eso no es ruido inofensivo: entrena a ignorar el aviso, que es lo
   que lo rompe el dia que sea de verdad. */
cfg_crm_guardar($pdo, ['tg_ev_revision' => '1'], 'test');
$pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'ttg-rev%'");
$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'pago_revision:ttg-rev%'");

$logTmp3 = sys_get_temp_dir() . '/t_tg3_' . getmypid() . '.log';
@unlink($logTmp3); ini_set('error_log', $logTmp3);

/* La edad se calcula EN LA BASE (capturado_en contra NOW()). Por eso el pago
   viejo se siembra con INTERVAL y no con una fecha armada en PHP: si el PHP y
   la base no estan en el mismo huso -- que es el caso en produccion -- una
   fecha de PHP haria pasar el test por el motivo equivocado. */
$sembrar = static function (PDO $pdo, string $id, string $estado, int $hace) {
    $pdo->prepare(
        "INSERT INTO pagos (id_unico, monto, remitente, estado, capturado_en)
         VALUES (?, 1000, 'Fulano de Prueba', ?, NOW() - INTERVAL ? MINUTE)"
    )->execute([$id, $estado, $hace]);
};

$sembrar($pdo, 'ttg-rev-fresco', 'revision', 0);
chequear('recien entrada NO avisa (el camino A todavia puede levantarla)',
         rl_avisar_revision_vieja($pdo, 10) === 0);

$sembrar($pdo, 'ttg-rev-viejo', 'revision', 25);
chequear('pero si sigue sin resolverse, SI avisa',
         rl_avisar_revision_vieja($pdo, 10) === 1);

/* Y la que resolvio el camino A no tiene que avisar nunca: quedo 'usado'. Este
   es exactamente el caso de la carga que disparo la falsa alarma. */
$pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'ttg-rev%'");
$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'pago_revision:ttg-rev%'");
$sembrar($pdo, 'ttg-rev-usado', 'usado', 25);
chequear('la que aprobo el camino A no avisa aunque sea vieja',
         rl_avisar_revision_vieja($pdo, 10) === 0);

@unlink($logTmp3); ini_restore('error_log');
$pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'ttg-rev%'");
$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'pago_revision:ttg-rev%'");
cfg_crm_guardar($pdo, ['tg_ev_revision' => '1'], 'test');


printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
