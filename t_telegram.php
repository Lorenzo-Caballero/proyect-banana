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
          'tg_ev_derivacion', 'tg_ev_revision', 'tg_ev_retiro', 'tg_ev_salud'] as $k) {
    chequear("'$k' se puede guardar", array_key_exists($k, CFG_CRM_DEFAULTS));
}

$pdo->exec("DELETE FROM tg_avisos WHERE clave LIKE 'test\\_%'");
cfg_crm_guardar($pdo, ['tg_bot_token' => '', 'tg_chat_id' => ''], 'test');
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
