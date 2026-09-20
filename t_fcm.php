<?php
/**
 * t_fcm.php — El empujón de Firebase: que suene, y que callarse no rompa nada.
 *
 * QUÉ VINO A ARREGLAR FIREBASE (medido el 19/09/2026 sobre los 42 celulares con
 * la app y el permiso dado). Con la app cerrada el aviso lo iba a buscar el
 * propio teléfono cada 15 minutos, y el administrador de batería del fabricante
 * decidía si lo dejaba:
 *
 *     Samsung    5 sondeos de  6 esperados
 *     Motorola   2 sondeos de 13 esperados
 *     Xiaomi     1 sondeo  de 20 esperados
 *
 * LO QUE SE PRUEBA ACÁ NO ES QUE LLEGUE —eso depende de Google y se ve en un
 * teléfono de verdad— sino las dos propiedades que lo hacen seguro de tener
 * prendido:
 *
 *   1. SIN CREDENCIALES, TODO SIGUE ANDANDO. Un cliente que no configuró
 *      Firebase, o el server justo después del deploy y antes de subir la
 *      clave, tienen que comportarse exactamente como el 19/09: notificación
 *      encolada, cero excepciones, y el sondeo de 15 minutos haciendo el resto.
 *      Es el caso importante: si esto falla, un timbre roto rompe una carga.
 *
 *   2. EL PUSH VA VACÍO. Es la decisión de diseño de la que cuelga todo lo
 *      demás —la entrega única, `solo_app`, que el texto no viaje por Google— y
 *      es la que es fácil de deshacer sin querer: agregarle `notification` al
 *      mensaje "para que se vea mejor" parece una mejora y rompe las cuatro
 *      cosas a la vez. Hay un test posicional sobre el fuente por eso.
 *
 * Usa su propia base temporal: `dispositivos` es una tabla compartida y un test
 * que le escriba tokens de mentira a la base de demo ensucia lo que miran los
 * otros.
 *
 *     T_PORT=3306 php t_fcm.php
 */
declare(strict_types=1);

$host = getenv('T_HOST') ?: '127.0.0.1';
$port = getenv('T_PORT') ?: '3306';
$usr  = getenv('T_USER') ?: 'root';
$pw   = getenv('T_PASS') ?: '';

$raiz = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $usr, $pw,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$base = 't_fcm_' . substr((string)getmypid(), -5) . '_' . random_int(100, 999);
$raiz->exec("CREATE DATABASE `$base` DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Se borra pase lo que pase, incluso si el test explota a la mitad.
register_shutdown_function(function () use ($raiz, $base) {
    try { $raiz->exec("DROP DATABASE IF EXISTS `$base`"); } catch (Throwable $e) {}
});

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$base;charset=utf8mb4", $usr, $pw,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/* La tabla como queda DESPUES de la migración 77. Se escribe acá y no se lee
   el .sql para que el test falle si alguien cambia la forma de la columna sin
   avisar. */
$pdo->exec("CREATE TABLE dispositivos (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(64)  NOT NULL,
  usuario     VARCHAR(50)  NULL,
  plataforma  ENUM('android','web') NOT NULL DEFAULT 'web',
  modelo      VARCHAR(80)  NULL,
  version     VARCHAR(20)  NULL,
  fcm_token   VARCHAR(255) NULL DEFAULT NULL,
  fcm_en      DATETIME     NULL DEFAULT NULL,
  permitido   TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device (device_id),
  KEY ix_usuario (usuario),
  KEY ix_fcm (fcm_token(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Se apunta a un archivo que NO existe: el estado "sin Firebase configurado",
   que es el que tiene que degradar bien. Más abajo se lo cambia por uno de
   mentira para probar la firma. */
$falsa = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'goldpaw_t_fcm_' . getmypid() . '.json';
define('FCM_CREDENCIALES', $falsa);
require_once __DIR__ . '/api/fcm_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// =========================================================================
echo "\n=== Sin credenciales: se calla, no se rompe ===\n";
// =========================================================================
@unlink($falsa);
fcm_olvidar();

chequear('sin el archivo, no hay credenciales', fcm_credenciales() === null);
chequear('y fcm_disponible() dice que no', fcm_disponible() === false);

$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, fcm_token, permitido)
               VALUES ('dev-1','holajuan123','android','tok-abc',1)")->execute();

$lanzo = false;
try {
    $n = fcm_despertar($pdo, 'holajuan123');
} catch (Throwable $e) {
    $lanzo = true; $n = -1;
}
chequear('fcm_despertar no lanza nunca', $lanzo === false);
chequear('y devuelve 0 sin tocar la red', $n === 0,
    'con un jugador que SI tiene token: lo que frena es la falta de credenciales');

/* EL CASO QUE IMPORTA DE VERDAD: encolar un aviso tiene que seguir andando.
   Si esto falla, un timbre roto hace que una carga de fichas parezca fallida. */
$pdo->exec("CREATE TABLE notificaciones (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NULL, titulo VARCHAR(120) NOT NULL, cuerpo VARCHAR(400) NOT NULL,
  tipo ENUM('bono','fichas','recarga','ruleta','promo','aviso') NOT NULL DEFAULT 'aviso',
  url VARCHAR(300) NULL, origen VARCHAR(20) NOT NULL DEFAULT 'crm',
  expira_en DATETIME NULL, solo_app TINYINT(1) NOT NULL DEFAULT 0,
  programada_en DATETIME NULL, creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

require_once __DIR__ . '/api/notificaciones_lib.php';
$id = notif_crear($pdo, 'holajuan123', 'Te acreditamos', '+1.000 fichas', 'fichas');
chequear('una notificacion se encola igual sin Firebase', $id > 0,
    'ESTE es el que no puede fallar: el timbre no puede romper la carga');

// =========================================================================
echo "\n=== El topico: un canal por cliente, no uno para todos ===\n";
// =========================================================================
$GLOBALS['TENANT_DB'] = 'goldpaw_ganamoscrm';
$t1 = fcm_topico();
$GLOBALS['TENANT_DB'] = 'goldpaw_casinotest';
$t2 = fcm_topico();

chequear('el topico sale de la base del cliente', $t1 === 'gp_goldpaw_ganamoscrm', $t1);
chequear('dos clientes NO comparten topico', $t1 !== $t2,
    'si lo compartieran, la promo de un casino le llegaria a los jugadores de otro');

/* TENANT_SLUG viene vacio para los clientes con dominio propio, que es
   exactamente por lo que el topico NO sale de ahi. */
$GLOBALS['TENANT_DB'] = '';
$GLOBALS['TENANT_SLUG'] = '';
$GLOBALS['TENANT_HOST'] = 'casinodelcliente.com';
chequear('sin base resuelta cae al host, no a un topico comun',
    fcm_topico() === 'gp_casinodelcliente.com', fcm_topico());

$GLOBALS['TENANT_DB'] = 'raro/con espacios&simbolos';
chequear('el nombre se sanea a lo que Firebase acepta',
    preg_match('/^[a-zA-Z0-9\-_.~%]+$/', fcm_topico()) === 1, fcm_topico());

// =========================================================================
echo "\n=== La firma del JWT ===\n";
// =========================================================================
/* EL TEST SE ESCRIBE SU PROPIO openssl.cnf. PHP necesita uno para generar
   claves y en esta maquina no lo encuentra (`configuration file routines::no
   such file`), asi que sin esto la parte mas importante del archivo se salteaba
   en silencio -- que es la peor forma de no tener un test. Uno minimo alcanza,
   y asi no depende de donde este instalado XAMPP ni de que exista un openssl
   del sistema. */
$cnf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 't_fcm_openssl_' . getmypid() . '.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n");
register_shutdown_function(static fn() => @unlink($cnf));

$par = @openssl_pkey_new(['config' => $cnf, 'private_key_bits' => 2048,
                          'private_key_type' => OPENSSL_KEYTYPE_RSA]);
chequear('se puede generar un par de claves para probar', $par !== false,
    'sin esto no se puede verificar la firma, que es de lo que depende TODO');

if ($par !== false) {
    openssl_pkey_export($par, $priv, null, ['config' => $cnf]);
    $pub = openssl_pkey_get_details($par)['key'];

    $jwt = fcm_firmar_jwt(['iss' => 'x@y.iam.gserviceaccount.com', 'exp' => time() + 3600], $priv);
    chequear('firma y devuelve un JWT', is_string($jwt) && substr_count((string)$jwt, '.') === 2);

    $partes = explode('.', (string)$jwt);
    $des = static fn(string $s): string =>
        (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));

    $cab = json_decode($des($partes[0]), true);
    chequear('el algoritmo es RS256', ($cab['alg'] ?? '') === 'RS256',
        'Google rechaza cualquier otro');

    /* SIN PADDING `=` Y CON -_ EN VEZ DE +/. No es cosmetico: base64 comun
       rompe el JWT y Google contesta 401 sin decir por que. */
    chequear('el base64 es url-safe y sin relleno',
        !str_contains((string)$jwt, '=') && !str_contains((string)$jwt, '+')
        && !str_contains((string)$jwt, '/'),
        'base64 comun rompe el JWT y Google contesta 401 sin explicar nada');

    $firmaOk = openssl_verify($partes[0] . '.' . $partes[1], $des($partes[2]), $pub,
                              OPENSSL_ALGO_SHA256) === 1;
    chequear('la firma verifica con la clave publica', $firmaOk,
        'si esto falla, Google contesta 401 y ningun aviso sale');

    /* Los claims del canje los arma fcm_access_token(), no esta funcion, asi
       que se chequean sobre el fuente. El scope importa: con uno equivocado
       Google devuelve el token igual y recien falla el ENVIO, con un 403 que
       no explica nada. */
    $srcTok = (string)file_get_contents(__DIR__ . '/api/fcm_lib.php');
    chequear('el canje pide el scope de mensajeria',
        str_contains($srcTok, "'scope' => 'https://www.googleapis.com/auth/firebase.messaging'"),
        'con otro scope Google da el token y falla recien al enviar, con un 403 mudo');
    chequear('y el JWT no dura mas de una hora',
        str_contains($srcTok, "'exp'   => \$ahora + 3600"),
        'Google rechaza los que piden mas');

    chequear('una clave rota devuelve null y no lanza',
        fcm_firmar_jwt(['iss' => 'x'], 'esto no es una clave') === null);

    // ---- y ahora el archivo de credenciales entero ----
    echo "\n=== Leer la clave de cuenta de servicio ===\n";

    file_put_contents($falsa, (string)json_encode([
        'type' => 'service_account', 'project_id' => 'goldpaw-b9f67',
        'client_email' => 'x@goldpaw-b9f67.iam.gserviceaccount.com',
        'private_key' => $priv, 'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    fcm_olvidar();
    chequear('con el archivo puesto, ahora si hay credenciales',
        (fcm_credenciales()['project_id'] ?? '') === 'goldpaw-b9f67');
    chequear('y fcm_disponible() dice que si', fcm_disponible() === true);

    /* UNA CLAVE A MEDIAS ES PEOR QUE NINGUNA: si se aceptara, cada aviso
       gastaria cinco segundos de timeout contra Google para terminar
       fallando, y el sondeo -- que funciona -- quedaria detras de esa espera.
       Mejor decir que no hay Firebase. */
    file_put_contents($falsa, '{"type":"service_account","project_id":"x"}');
    fcm_olvidar();
    chequear('una clave sin private_key se descarta entera',
        fcm_credenciales() === null,
        'aceptarla costaria 5 s de timeout por cada aviso, para nada');

    file_put_contents($falsa, 'esto no es json');
    fcm_olvidar();
    chequear('un archivo corrupto no lanza, devuelve null', fcm_credenciales() === null);

    @unlink($falsa);
    fcm_olvidar();
}

// =========================================================================
echo "\n=== Guardar el token que manda el celular ===\n";
// =========================================================================
chequear('guarda el token del aparato', fcm_guardar_token($pdo, 'dev-1', 'tok-nuevo'));
$f = $pdo->query("SELECT fcm_token, fcm_en FROM dispositivos WHERE device_id='dev-1'")->fetch();
chequear('queda escrito', ($f['fcm_token'] ?? '') === 'tok-nuevo', json_encode($f));
chequear('y con fecha', !empty($f['fcm_en']));

chequear('un device_id vacio no escribe nada', fcm_guardar_token($pdo, '', 'x') === false);
chequear('un token vacio tampoco', fcm_guardar_token($pdo, 'dev-1', '') === false);

/* Un aparato que todavia no registro el widget: rowCount 0. Tiene que
   contestar que SI igual, o el APK reintenta en loop en cada arranque. */
chequear('un aparato desconocido no es un error', fcm_guardar_token($pdo, 'dev-nunca-visto', 'tok-x'));

// =========================================================================
echo "\n=== Las invariantes del diseño (sobre el fuente) ===\n";
// =========================================================================
// =========================================================================
echo "\n=== El presupuesto: una campana masiva no puede colgar el CRM ===\n";
// =========================================================================
/* crm.php arma las difusiones filtradas con un foreach sobre los
   destinatarios, llamando a notif_crear() una vez por jugador. Como
   notif_crear() toca el timbre, una campana a 300 jugadores dispara 300
   consultas y hasta 300 llamadas a Google ADENTRO del request del agente.
   Sin tope, el CRM se cuelga -- y justo cuando el negocio crece, que es
   cuando peor viene. */
fcm_presupuesto_reiniciar();
chequear('con el presupuesto entero, se puede tocar el timbre',
    fcm_sin_presupuesto() === false);

fcm_gastar(FCM_PRESUPUESTO_SEG + 1);
chequear('agotado el presupuesto, se deja de tocar',
    fcm_sin_presupuesto() === true,
    'al que queda sin empujon le llega por el sondeo, como antes de la 1.7');

$lanzoP = false;
try { $nP = fcm_despertar($pdo, 'holajuan123'); }
catch (Throwable $e) { $lanzoP = true; $nP = -1; }
chequear('y fcm_despertar corta sin lanzar', $lanzoP === false && $nP === 0);

fcm_presupuesto_reiniciar();
chequear('reiniciar lo devuelve al estado inicial', fcm_sin_presupuesto() === false);

$src = (string)file_get_contents(__DIR__ . '/api/fcm_lib.php');
/* El corte tiene que poder pasar DENTRO del bucle de aparatos: un solo jugador
   con varios telefonos y Google lento gastaria todo el presupuesto. */
chequear('tambien corta en medio del bucle de aparatos',
    str_contains($src, 'if (fcm_sin_presupuesto($t0)) { break; }'),
    'sin esto, un jugador con 5 aparatos podria consumirlo entero');
chequear('el aviso al log sale una sola vez por request',
    substr_count($src, '__fcm_aviso') >= 2 && str_contains($src, 'error_log(sprintf('),
    '300 lineas identicas hacen que el log deje de servir cuando hay algo que mirar');


/* LA MAS IMPORTANTE DE TODO EL ARCHIVO. Un mensaje con bloque `notification`
   lo dibuja Android solo, con el texto que venga adentro, y MensajesFCM ni se
   entera. Eso romperia de una sola vez: la entrega unica (el mismo aviso
   dibujado por el push Y por el sondeo), `solo_app`, el chequeo de permiso, y
   haria viajar por Google cuanta plata se le acredito a quien. */
chequear('el push NO lleva bloque notification',
    !preg_match("/'notification'\s*=>/", $src),
    'agregarlo rompe la entrega unica, solo_app, y hace viajar el texto por Google');
chequear('manda data pura', str_contains($src, "'data'    => ['gp' => '1']"));
chequear('y con prioridad alta', str_contains($src, "'priority' => 'HIGH'"),
    'sin esto el mensaje espera a la proxima ventana de Doze: el problema original');

/* Un token muerto se borra; una caida de Google NO. Confundirlos significa que
   diez minutos de Google caido le borran el token a todo el parque. */
chequear('solo borra el token con UNREGISTERED o 404',
    str_contains($src, "UNREGISTERED") && str_contains($src, "return 'invalido'")
    && str_contains($src, "/* 'error' no se toca"),
    'un corte de red no puede costar los tokens de todos');

$srcLib = (string)file_get_contents(__DIR__ . '/api/notificaciones_lib.php');
/* notif_crear tiene DOS caminos de exito (el normal y el de compatibilidad sin
   `programada_en`). Un timbre en uno solo andaria en desarrollo y fallaria en
   la base que le falta una migracion. */
chequear('notif_crear toca el timbre en SUS DOS caminos de exito',
    substr_count($srcLib, 'notif_empujar($pdo, $usuario, $id, $prog)') === 2,
    'si queda en uno solo, anda en desarrollo y falla donde falta la migracion 29');
chequear('y no suena para las programadas',
    str_contains($srcLib, 'if ($id <= 0 || $prog !== null) { return; }'),
    'despertar por un aviso que todavia no se entrega lo quema: el telefono pide y no hay nada');

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
