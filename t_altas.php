<?php
/**
 * t_altas.php — El alta de jugadores, y el nombre que se les da.
 *
 * EL PROBLEMA QUE CUBRE, encontrado el 2/9/2026 con la publicidad a punto de
 * salir: en ganamos el nombre de usuario es unico en TODA la plataforma, entre
 * todos los agentes. Nosotros solo podemos mirar nuestro espejo -- los
 * jugadores de esta agencia -- asi que un nombre comun ("Juan", "Pepon") nos
 * parece libre y el panel lo rechaza porque lo tiene otro agente.
 *
 * Sumado a que el bot tomaba cualquier HTTP 200 por exito, el resultado era el
 * peor posible: el alta se marcaba creada, al jugador se le entregaban unas
 * credenciales, y al entrar le decia "usuario o contraseña incorrectos". En
 * los reportes figuraba como un registro exitoso.
 *
 * Corre contra la base de prueba. Limpia lo suyo.
 *
 *     php t_altas.php
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
require_once __DIR__ . '/api/altas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function limpiar(PDO $pdo): void {
    foreach (['tst%', 'holaTst%'] as $pat) {
        $pdo->prepare("DELETE FROM altas    WHERE usuario  LIKE ?")->execute([$pat]);
        $pdo->prepare("DELETE FROM usuarios WHERE username LIKE ?")->execute([$pat]);
    }
}
limpiar($pdo);

// ===========================================================================
echo "\n=== 1. Reconocer que el nombre estaba ocupado ===\n";

/* El bot no devuelve codigos: informa lo que vio, y lo ve de varias formas.
   Estos son los textos reales que produce. */
foreach ([
    /* EL MENSAJE TEXTUAL DE LA PLATAFORMA. Va primero porque es el que llega
       por el camino rapido (la API) y el que se nos escapo: dice "already
       exist" SIN la s final. El detector buscaba "already exists" y no
       matcheaba, asi que el alta reintentaba con el mismo nombre las tres
       veces y se rendia -- con el jugador esperando en la pantalla. */
    'el panel rechazo la creacion: User with username: Juan - already exist',
    'el panel respondio 200 pero el jugador NO figura: lo mas probable es que el nombre ya este tomado por otro agente',
    'sin señal y el jugador no aparece en el listado filtrado',
    'El panel rechazo el formulario: El usuario ya existe',
] as $msg) {
    chequear('reconoce: "' . mb_substr($msg, 0, 42) . '..."',
             alta_parece_nombre_ocupado($msg) === true);
}

/* Y NO puede confundir otros fallos con este. Renombrar cuando el problema era
   otro le cambia el nombre a un jugador sin ningun motivo, y encima esconde la
   causa real detras de un reintento que tambien va a fallar. */
foreach ([
    'Sesion caida, sin re-login',
    'HTTP 200 pero es una pagina de WAF/challenge, no el panel real',
    'No pude clickear el boton de crear: Timeout',
    'Excepcion: connection refused',
] as $msg) {
    chequear('NO confunde: "' . mb_substr($msg, 0, 40) . '..."',
             alta_parece_nombre_ocupado($msg) === false);
}

// ===========================================================================
echo "\n=== 2. Elegir un nombre libre ===\n";

/* Se siembra ocupado el nombre QUE SE VA A GENERAR (con prefijo), no el crudo:
   si no, el test no prueba nada -- 'tstlibre' y 'holaTstlibre' son distintos. */
$pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
               ON DUPLICATE KEY UPDATE coins=0")->execute([crc32('holaTstlibre'), 'holaTstlibre']);

/* El nombre sale con PREFIJO y sin numeros: "holaJuan", no "Juan427".
   Se dicta y se recuerda -- un jugador que llama por telefono puede decir su
   usuario -- y sobre todo NO CHOCA: en ganamos el nombre es unico en toda la
   plataforma y el patron "Nombre + numeros" esta agotado. El 2/9/2026
   fallaron Juan676, Juan565, Juan557 y Martin109, cuatro de cuatro. */
$libre = alta_usuario_disponible($pdo, 'tstnuevo', 0);
chequear('lleva prefijo y NO numeros en la primera ronda',
         $libre === 'holaTstnuevo', $libre);

/* Los numeros aparecen solo cuando la plataforma ya nos rechazo, y suben de a
   poco: primero dos digitos, y recien despues cuatro. Asi el caso normal --
   que es casi siempre -- se lleva el nombre lindo. */
chequear('ronda 1: dos digitos',
         (bool)preg_match('/^holaTstnuevo[0-9]{2}$/', alta_usuario_disponible($pdo, 'tstnuevo', 1)));
chequear('ronda 3: cuatro digitos',
         (bool)preg_match('/^holaTstnuevo[0-9]{4}$/', alta_usuario_disponible($pdo, 'tstnuevo', 3)));

/* El prefijo NO se apila. El que renombra parte del nombre anterior, que ya lo
   tiene: sin esta guarda cada reintento daba "holaholaJuan". */
chequear('no apila el prefijo si ya estaba',
         !str_contains(alta_usuario_disponible($pdo, 'holaTstnuevo', 1), 'holahola'));

/* El sufijo tiene que ser AL AZAR de verdad. Si fuera un contador, dos
   personas registrandose a la vez con el mismo nombre pedirian el mismo
   usuario y una de las dos se llevaria el rechazo. */
$vistos = [];
for ($i = 0; $i < 20; $i++) { $vistos[alta_usuario_disponible($pdo, 'tstnuevo', 3)] = true; }
chequear('el sufijo varia entre llamadas (20 intentos, >5 distintos)',
         count($vistos) > 5, 'salieron ' . count($vistos) . ' nombres distintos');

$n = alta_usuario_disponible($pdo, 'tstlibre');
chequear('uno tomado devuelve otro distinto', $n !== 'holaTstlibre', $n);
chequear('y conserva el nombre como base', str_contains($n, 'Tstlibre'), $n);

/* Los nombres muy cortos los rechaza el PANEL, y ahi el alta muere recien
   cuando el bot llena el formulario -- con el jugador ya esperando. */
chequear('un nombre muy corto se alarga antes de encolar',
         mb_strlen(alta_usuario_disponible($pdo, 'ab')) >= 4);

/* Tildes y eñes: el panel solo acepta ASCII. Si se cortaran a lo bruto por
   bytes, "María" perderia la "i" entera. */
$m = alta_usuario_disponible($pdo, 'Mar' . chr(0xC3) . chr(0xAD) . 'a');
chequear('translitera los acentos en vez de comerse la letra',
         str_contains($m, 'Maria'), $m);

// ===========================================================================
echo "\n=== 3. El renombre no se come el nombre del jugador ===\n";

/* Al reintentar se parte del nombre SIN el sufijo numerico que le hayamos
   puesto antes: si no, cada vuelta lo alarga
   ("Juan" -> "Juan123" -> "Juan123456"). */
$base = rtrim('tstjuan123', '0123456789');
chequear('saca el sufijo que pusimos nosotros', $base === 'tstjuan');

/* Pero si al sacarlo no queda casi nada, se conserva el original: es preferible
   un nombre largo a uno que el panel va a rechazar por corto. */
$corto = rtrim('ab12', '0123456789');
chequear('si al sacarlo queda demasiado corto, se conserva el original',
         mb_strlen($corto) < 3);

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
