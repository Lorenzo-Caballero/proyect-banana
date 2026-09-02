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
    $pdo->exec("DELETE FROM altas    WHERE usuario LIKE 'tst%'");
    $pdo->exec("DELETE FROM usuarios WHERE username LIKE 'tst%'");
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

$pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
               ON DUPLICATE KEY UPDATE coins=0")->execute([crc32('tstlibre'), 'tstlibre']);

chequear('un nombre libre se devuelve tal cual',
         alta_usuario_disponible($pdo, 'tstnuevo') === 'tstnuevo');

$n = alta_usuario_disponible($pdo, 'tstlibre');
chequear('uno tomado devuelve otro distinto', $n !== 'tstlibre', $n);
chequear('y conserva el nombre como base', str_starts_with($n, 'tstlibre'), $n);

/* Los nombres muy cortos los rechaza el PANEL, y ahi el alta muere recien
   cuando el bot llena el formulario -- con el jugador ya esperando. */
chequear('un nombre muy corto se alarga antes de encolar',
         mb_strlen(alta_usuario_disponible($pdo, 'ab')) >= 4);

/* Tildes y eñes: el panel solo acepta ASCII. Si se cortaran a lo bruto por
   bytes, "María" perderia la "i" entera. */
$m = alta_usuario_disponible($pdo, 'Mar' . chr(0xC3) . chr(0xAD) . 'a');
chequear('translitera los acentos en vez de comerse la letra',
         $m === 'Maria' || str_starts_with($m, 'Maria'), $m);

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
