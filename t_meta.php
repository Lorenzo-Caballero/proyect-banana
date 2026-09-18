<?php
/**
 * t_meta.php — El embudo de Meta: que cada compra se reporte UNA vez y con los
 *              datos del jugador.
 *
 * Los errores que cubre no se ven mirando el CRM ni el chat: se ven semanas
 * despues, en el Administrador de Anuncios, cuando la campaña ya gasto mal.
 *
 *   · Cada transferencia reportaba DOS Purchase (uno por la recarga y otro por
 *     la carga interna al juego). Los ingresos salian al doble y la
 *     optimizacion por valor aprendia sobre numeros inventados.
 *   · Todas las conversiones viajaban con la IP del VPS y un User-Agent de
 *     Python, porque las dispara el bot y no el jugador. Meta usa esos campos
 *     para reconocer a la persona.
 *
 * NO manda nada a Meta: la base de prueba tiene meta_activo=0 y el test lo
 * fuerza igual antes de empezar.
 *
 *     php t_meta.php
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

// Igual que en t_matcher: nada puede salir a internet desde un test.
$pdo->prepare("INSERT INTO config_crm (clave, valor) VALUES ('meta_activo','0')
               ON DUPLICATE KEY UPDATE valor='0'")->execute();

require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/publicidad_lib.php';
require_once __DIR__ . '/api/meta_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function limpiar(PDO $pdo): void {
    $pdo->exec("DELETE FROM altas WHERE usuario LIKE 'test\\_%'");
}
limpiar($pdo);

// ===========================================================================
echo "\n=== 1. Los datos que se le mandan a Meta son DEL JUGADOR ===\n";

/* El alta guarda la IP, el navegador y la URL del jugador. Los eventos que mas
   valen los dispara despues el bot del VPS, donde $_SERVER es del servidor. */
$pdo->prepare(
    "INSERT INTO altas (usuario, password, origen, ip, ua, url_landing, fbp, fbc)
     VALUES ('test_meta','x','landing','200.1.2.3','Mozilla/5.0 (Linux; Android 13)',
             'https://casino.test/registro.html','fb.1.100.PPP','fb.1.100.CCC')"
)->execute();

$a = publicidad_atribucion_por_usuario($pdo, 'test_meta');
chequear('devuelve la IP del jugador',        ($a['ip']  ?? '') === '200.1.2.3', json_encode($a));
chequear('devuelve su navegador',             str_contains($a['ua'] ?? '', 'Android'));
chequear('y la URL donde se registro',        str_contains($a['url'] ?? '', 'registro.html'));
chequear('sigue devolviendo las cookies',     ($a['fbp'] ?? '') === 'fb.1.100.PPP');

// ===========================================================================
echo "\n=== 2. El fbc se reconstruye desde el fbclid ===\n";

/* Si el navegador nunca escribio la cookie -- bloqueador, red lenta, iOS
   borrandola -- la conversion viajaba sin NADA que Meta pudiera atar al click
   del anuncio. El fbclid si quedaba guardado, y alcanza para reconstruirlo. */
$pdo->exec("DELETE FROM altas WHERE usuario = 'test_meta2'");
$pdo->prepare(
    "INSERT INTO altas (usuario, password, origen, fbclid, pedido_en)
     VALUES ('test_meta2','x','landing','IwAR0abcdef', '2026-09-01 12:00:00')"
)->execute();

$a2 = publicidad_atribucion_por_usuario($pdo, 'test_meta2');
chequear('sin cookie pero con fbclid, arma el fbc',
         str_starts_with($a2['fbc'] ?? '', 'fb.1.'), json_encode($a2['fbc'] ?? null));
chequear('y termina con el fbclid original',
         str_ends_with($a2['fbc'] ?? '', '.IwAR0abcdef'), (string)($a2['fbc'] ?? ''));

// La cookie real, si existe, MANDA sobre la reconstruida.
$pdo->exec("UPDATE altas SET fbc = 'fb.1.999.REAL' WHERE usuario = 'test_meta2'");
$a3 = publicidad_atribucion_por_usuario($pdo, 'test_meta2');
chequear('si hay cookie real, se usa esa y no la inventada',
         ($a3['fbc'] ?? '') === 'fb.1.999.REAL', (string)($a3['fbc'] ?? ''));

// ===========================================================================
echo "\n=== 3. Un usuario sin alta no rompe nada ===\n";

$v = publicidad_atribucion_por_usuario($pdo, 'test_no_existe');
chequear('devuelve la estructura completa, vacia',
         array_key_exists('ip', $v) && array_key_exists('ua', $v)
         && array_key_exists('url', $v) && ($v['fbc'] === ''), json_encode($v));
chequear('usuario vacio tampoco rompe',
         publicidad_atribucion_por_usuario($pdo, '')['fbp'] === '');

// ===========================================================================
echo "\n=== 4. La doble compra: el codigo que la evitaba ===\n";

/* Cada transferencia acreditada generaba DOS Purchase:
     recargas_lib  -> Purchase ref 'recarga:M'
     acciones_cola -> Purchase ref 'carga:N'   (la carga interna al juego)
   Meta contaba los dos porque las refs son distintas.
   El filtro es por `origen`: la accion que nace de una recarga NO reporta. */
$src = (string)@file_get_contents(__DIR__ . '/api/acciones_cola.php');
chequear('acciones_cola lee `origen` de la accion',
         (str_contains($src, "['origen']") || str_contains($src, "\$a['origen']"))
             && str_contains($src, "\$deRecarga"),
         'sin ese campo no puede distinguir de donde viene la carga');
chequear('y saltea el Purchase si viene de una recarga',
         str_contains($src, "\$deRecarga") && str_contains($src, "&& !\$deRecarga"),
         'volveria a reportar la misma plata dos veces');

/* Y el mismo camino disparaba InitiateCheckout DESPUES del Purchase: un embudo
   al reves, imposible, que ensucia el modelo de atribucion. */
$srcF = (string)@file_get_contents(__DIR__ . '/api/fichas_lib.php');
chequear('fichas_lib no manda InitiateCheckout si la carga viene de una recarga',
         str_contains($srcF, "\$origen !== 'recarga'"),
         'Meta veria Purchase primero y InitiateCheckout despues');

// ===========================================================================
echo "\n=== 5. El event_id, que es lo que evita contar dos veces ===\n";

/* Meta deduplica por event_id. Tiene que ser el MISMO para el mismo hecho
   (reintento, doble click) y DISTINTO entre eventos distintos. */
$id1 = meta_event_id('Purchase', 'recarga:7');
$id2 = meta_event_id('Purchase', 'recarga:7');
$id3 = meta_event_id('Purchase', 'recarga:8');
$id4 = meta_event_id('Lead',     'recarga:7');
chequear('el mismo hecho da el mismo id',   $id1 === $id2);
chequear('otra recarga da otro id',         $id1 !== $id3);
chequear('otro evento del mismo hecho, otro id', $id1 !== $id4,
         'Lead y Purchase de la misma alta no pueden colisionar');
chequear('sin ref, el id es aleatorio',
         meta_event_id('Purchase', '') !== meta_event_id('Purchase', ''));

// ===========================================================================
echo "\n=== 6. El token de CAPI nunca sale al navegador ===\n";

cfg_crm_guardar($pdo, ['meta_activo' => '1', 'meta_pixel_id' => '123',
                       'meta_capi_token' => 'SECRETO-NO-MOSTRAR'], 'test');
$pub = meta_config_publica($pdo);
chequear('la config publica trae el pixel', ($pub['pixel_id'] ?? '') === '123');
chequear('pero NO el token',
         !str_contains(json_encode($pub), 'SECRETO'),
         'con el token en el HTML cualquiera manda eventos falsos a tu pixel');

echo "
=== Modo prueba: nada sale hacia afuera ===
";

/* EL INCIDENTE (17/09/2026). `scripts/simulacro.php` recorre el circuito de la
   plata en PRODUCCION con un jugador inventado, y lo hace bien: por las
   funciones de verdad. El problema es que acreditar una recarga dispara
   `rl_notificar_acreditada()`, que dispara un `Purchase`.

   Siete corridas del simulacro = SIETE conversiones falsas de $1.000 en la
   cuenta de publicidad, de jugadores `zzsim…` que no existen y que el propio
   script borra al terminar. Aparecieron auditando por que Meta reportaba mas
   conversiones que el CRM.

   No es un numero feo en un informe: Meta OPTIMIZA la pauta con esos eventos,
   asi que el simulacro le estaba enseñando al algoritmo a buscar gente
   parecida a un fantasma. Y no se puede deshacer -- un evento mandado a la
   CAPI no se retracta.

   LA REGLA QUE QUEDA: una prueba puede tocar NUESTRA base, nunca a un tercero.
   El flag se define antes de cargar nada y lo miran meta_evento() y
   tg_evento(). */
cfg_crm_guardar($pdo, ['meta_activo' => '1', 'meta_pixel_id' => '123',
                       'meta_capi_token' => 'SECRETO', 'meta_ev_purchase' => '1'], 'test');

chequear('con todo prendido, el evento se arma',
         meta_evento($pdo, 'Purchase', ['usuario' => 'test_meta2', 'valor' => 1000,
                                        'ref' => 'guardia:1']) !== '',
         'si esto ya daba vacio, el chequeo de abajo no prueba nada');

/* El flag es una constante y no se puede desdefinir, asi que el chequeo de
   comportamiento vive en un proceso aparte. El codigo hijo va a un ARCHIVO y
   no a `php -r`: en Windows escapeshellarg() envuelve en comillas dobles y el
   codigo lleva las suyas adentro, asi que el hijo moria con un parse error --
   y un hijo que no arranca hace fallar el chequeo por el motivo equivocado. */
$tmp = sys_get_temp_dir() . '/gp_modo_prueba_' . getmypid() . '.php';
$dir = str_replace(DIRECTORY_SEPARATOR, '/', __DIR__);
file_put_contents($tmp, <<<PHPHIJO
<?php
define('GP_MODO_PRUEBA', true);
\$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
\$GLOBALS['pdo'] = \$pdo;
require '{$dir}/api/config_crm.php';
require '{$dir}/api/meta_lib.php';
require '{$dir}/api/telegram_lib.php';
echo meta_evento(\$pdo, 'Purchase',
        ['usuario' => 'test_meta2', 'valor' => 1000, 'ref' => 'guardia:2']) === ''
     ? 'META_MUDO' : 'META_MANDO';
echo tg_evento(\$pdo, 'pago', 'titulo', []) === false ? '|TG_MUDO' : '|TG_MANDO';
PHPHIJO);

$salida = (string)@shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);

chequear('con GP_MODO_PRUEBA, meta_evento() no manda nada',
         str_contains($salida, 'META_MUDO'), trim($salida));
chequear('y tg_evento() tampoco',
         str_contains($salida, 'TG_MUDO'), trim($salida));

/* Y que el simulacro lo defina ANTES de cargar libs: si quedara despues de un
   require que ya llamo a meta_evento(), el flag no serviria de nada. */
$sim = (string)@file_get_contents(__DIR__ . '/scripts/simulacro.php');
$posFlag = strpos($sim, "define('GP_MODO_PRUEBA'");
$posReq  = strpos($sim, "require_once \$API");
chequear('el simulacro define el flag', $posFlag !== false);
chequear('y lo define ANTES del primer require',
         $posFlag !== false && $posReq !== false && $posFlag < $posReq,
         'un flag que llega tarde no apaga nada');

echo "\n=== Una carga a mano NO es una compra ===\n";

/* MEDIDO EL 18/09/2026, antes de prender la publicidad: en 14 dias se le
   mandaron a Meta 14 `Purchase` por cargas con origen 'crm', $106.007 en
   total. Ninguna la pago el jugador -- son cargas de prueba, correcciones,
   regalos, y las que el operador cubre a mano cuando el deposito automatico
   falla. Las compras de verdad (origen 'recarga', $364.120 en el mismo
   periodo) salen por rl_notificar_acreditada().

   El daño no es un numero feo: Meta OPTIMIZA la pauta con estos eventos.
   Decirle que 14 personas compraron sin haber comprado le enseña a buscar
   gente parecida a alguien que recibe fichas gratis, y eso se paga en cada
   impresion.

   El criterio es el mismo que se aplico hoy al watchdog de cargas: no se
   afirma lo que no se puede probar. Una carga 'crm' no tiene fila en
   `recargas` ni en `pagos`.

   Posicional: acciones_cola.php es un endpoint y no se puede requerir. */
$srcAC = file_get_contents(__DIR__ . '/api/acciones_cola.php');
$iP = strpos($srcAC, "meta_evento(" . chr(36) . "pdo, 'Purchase'");
chequear('acciones_cola sigue reportando Purchase', $iP !== false);
$guard = $iP !== false ? substr($srcAC, max(0, $iP - 900), 900) : '';
chequear('pero NO para una carga manual del CRM',
         str_contains($guard, 'esManual'),
         'una carga a mano no tiene pago detras: no se puede afirmar una compra');
chequear('la condicion mira origen = crm',
         str_contains($srcAC, "(" . chr(36) . "a['origen'] ?? '') === 'crm'"));

/* Y QUE NO SE LLEVE PUESTAS LAS EXCLUSIONES QUE YA ESTABAN: la carga que
   viene de una recarga (esa la reporta recargas_lib, seria doble) y la que es
   todo bono (un regalo de la casa, no un ingreso). */
chequear('sigue sin reportar la carga que viene de una recarga',
         str_contains($guard, 'deRecarga'),
         'si no, cada transferencia contaria dos veces');
chequear('y sigue sin reportar un deposito que es todo bono',
         str_contains($guard, 'esRegalo'));

cfg_crm_guardar($pdo, ['meta_activo' => '0', 'meta_pixel_id' => '',
                       'meta_capi_token' => ''], 'test');
limpiar($pdo);
$pdo->exec("DELETE FROM altas WHERE usuario = 'test_meta2'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
