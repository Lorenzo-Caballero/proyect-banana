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
         str_contains($src, "SELECT usuario, tipo, monto, origen"),
         'sin ese campo no puede distinguir de donde viene la carga');
chequear('y saltea el Purchase si viene de una recarga',
         str_contains($src, "\$deRecarga") && str_contains($src, "&& !\$deRecarga"),
         'volveria a reportar la misma plata dos veces');

/* Y el mismo camino disparaba InitiateCheckout DESPUES del Purchase: un embudo
   al reves, imposible, que ensucia el modelo de atribucion. */
$srcF = (string)@file_get_contents(__DIR__ . '/api/fichas_lib.php');
chequear('fichas_lib no manda InitiateCheckout si la carga viene de una recarga',
         str_contains($srcF, "if (\$origen !== 'recarga') {"),
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

cfg_crm_guardar($pdo, ['meta_activo' => '0', 'meta_pixel_id' => '',
                       'meta_capi_token' => ''], 'test');
limpiar($pdo);
$pdo->exec("DELETE FROM altas WHERE usuario = 'test_meta2'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
