<?php
/**
 * t_chat_identidad.php — El chat deja de figurar anónimo apenas inicia sesión.
 *
 * EL REPORTE (Nahuel, 20/09/2026): *"no detecta rápido el login. Cuando inicio
 * sesión y entro, desde el CRM veo un chat anónimo. Luego ahí se actualiza y
 * funciona bien"*.
 *
 * NO ERA LENTO, y ese fue el hallazgo. El widget sabe quién es el jugador a
 * ~1,2 s de que inicia sesión, y ya venía mandando el nombre en cada sondeo a
 * `mis_mensajes.php` (cada 6 s). El dato estaba llegando. Lo que pasaba es que
 * la adopción vivía SOLO en el camino del mensaje: `crm_conversacion_id()`
 * adopta perfecto, pero a quien se la llama es a `chatbot.php`, o sea cuando el
 * jugador VUELVE A ESCRIBIR. Entre una cosa y la otra —minutos, o nunca— el
 * operador ve en la bandeja un chat anónimo de alguien ya identificado, y si
 * contesta ahí contesta en una conversación que después cambia de dueño.
 *
 * `crm_adoptar_anon()` hace ese renombre desde el sondeo. Lo que se prueba acá
 * son sobre todo las cosas que NO tiene que hacer, porque corre en un endpoint
 * que se llama cada seis segundos por cada jugador con el chat abierto:
 *
 *   · NO crea conversaciones. Desde un sondeo, eso le abriría un chat vacío a
 *     cada jugador logueado que nunca escribió — la bandeja llena de hilos sin
 *     una sola palabra.
 *   · NO fusiona. Si el jugador ya tenía un chat con su nombre, no se toca:
 *     mezclar dos historiales es una decisión con consecuencias y no algo que
 *     deba pasar solo, en un sondeo, sin que nadie lo pida.
 *   · NO puede tumbar la entrega de mensajes. Ese endpoint es el que le lleva
 *     las respuestas del agente al jugador.
 *
 * Usa su propia base temporal: `conversaciones` es la tabla más compartida del
 * sistema y un test que le escriba hilos de mentira ensucia lo que miran los
 * otros.
 *
 *     php t_chat_identidad.php
 */
declare(strict_types=1);

$host = getenv('T_HOST') ?: '127.0.0.1';
$port = getenv('T_PORT') ?: '3306';
$usr  = getenv('T_USER') ?: 'root';
$pw   = getenv('T_PASS') ?: '';

$raiz = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $usr, $pw,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$base = 't_chatid_' . substr((string)getmypid(), -5) . '_' . random_int(100, 999);
$raiz->exec("CREATE DATABASE `$base` DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
register_shutdown_function(function () use ($raiz, $base) {
    try { $raiz->exec("DROP DATABASE IF EXISTS `$base`"); } catch (Throwable $e) {}
});

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$base;charset=utf8mb4", $usr, $pw,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pdo->exec("CREATE TABLE conversaciones (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_id     VARCHAR(64)  NOT NULL,
  clave          VARCHAR(80)  NULL,
  usuario        VARCHAR(50)  NULL,
  estado         ENUM('abierta','pendiente','cerrada') NOT NULL DEFAULT 'abierta',
  preview        VARCHAR(300) NULL,
  no_leidos      INT          NOT NULL DEFAULT 0,
  notas          TEXT         NULL,
  creada_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY ix_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

require_once __DIR__ . '/api/crm_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$nueva = function (string $sid, ?string $clave, ?string $usuario) use ($pdo): int {
    $pdo->prepare("INSERT INTO conversaciones (session_id, clave, usuario) VALUES (?,?,?)")
        ->execute([$sid, $clave, $usuario]);
    return (int)$pdo->lastInsertId();
};
$clave = fn(int $id) => (string)$pdo->query("SELECT clave FROM conversaciones WHERE id = $id")->fetchColumn();
$cuantas = fn() => (int)$pdo->query("SELECT COUNT(*) FROM conversaciones")->fetchColumn();

// =========================================================================
echo "\n=== El caso que reportó Nahuel ===\n";
// =========================================================================
/* Chateó sin sesión, después inició sesión. El hilo es de la misma persona:
   tiene que pasar a su nombre sin perder nada. */
$id = $nueva('sid-1', 'anon:sid-1', null);
chequear('adopta el chat anonimo de esa sesion',
    crm_adoptar_anon($pdo, 'sid-1', 'holajuan123') === true);
chequear('y queda con su nombre', $clave($id) === 'holajuan123', $clave($id));
chequear('sin abrir un hilo nuevo', $cuantas() === 1, (string)$cuantas());

chequear('llamarla de nuevo no hace nada',
    crm_adoptar_anon($pdo, 'sid-1', 'holajuan123') === false,
    'corre cada 6 segundos: tiene que ser barata cuando ya no hay nada que hacer');

// =========================================================================
echo "\n=== Lo que NO tiene que hacer ===\n";
// =========================================================================
/* LA MAS IMPORTANTE. Corre en un sondeo, por cada jugador con el chat abierto.
   Si creara la conversacion, cada jugador logueado que nunca escribio una
   palabra tendria un hilo vacio en la bandeja del operador. */
$antes = $cuantas();
chequear('NO crea nada si no hay chat anonimo',
    crm_adoptar_anon($pdo, 'sid-sin-chat', 'holapedro999') === false);
chequear('y la bandeja queda igual', $cuantas() === $antes,
    'un sondeo que crea conversaciones llena el CRM de hilos vacios');

/* NO FUSIONA. Si ya tiene un hilo con su nombre, mezclarlo con el anonimo es
   una decision con consecuencias --junta dos historias-- y no algo que deba
   pasar solo, en un sondeo. Queda como hoy: lo resuelve el mensaje siguiente. */
$viejo = $nueva('sid-viejo', 'holamaria77', 'holamaria77');
$anon  = $nueva('sid-2', 'anon:sid-2', null);
chequear('NO fusiona con un chat que ya existe',
    crm_adoptar_anon($pdo, 'sid-2', 'holamaria77') === false);
chequear('el anonimo queda intacto', $clave($anon) === 'anon:sid-2', $clave($anon));
chequear('y el de ella tambien', $clave($viejo) === 'holamaria77');

// =========================================================================
echo "\n=== Entradas que no tienen que romper nada ===\n";
// =========================================================================
chequear('sin usuario no hace nada', crm_adoptar_anon($pdo, 'sid-1', '') === false);
chequear('sin sesion tampoco', crm_adoptar_anon($pdo, '', 'holajuan123') === false);

/* Un jugador llamado "anon:x" renombrando el chat de otro. No puede pasar --los
   nombres los genera el sistema-- pero cuesta una linea y el dano seria que dos
   personas compartan hilo. */
$otro = $nueva('sid-3', 'anon:sid-3', null);
chequear('un nombre que empieza con anon: se rechaza',
    crm_adoptar_anon($pdo, 'sid-3', 'anon:sid-1') === false);
chequear('y ese chat queda como estaba', $clave($otro) === 'anon:sid-3');

// =========================================================================
echo "\n=== Que no pueda cortar la entrega de mensajes ===\n";
// =========================================================================
/* mis_mensajes.php es el endpoint que le lleva las respuestas del agente al
   jugador. Un fatal ahi no es "el chat figura anonimo": es que nadie recibe
   nada. Por eso la llamada va blindada y la funcion se traga sus errores. */
$src = (string)file_get_contents(__DIR__ . '/api/mis_mensajes.php');
chequear('mis_mensajes incluye la libreria que usa',
    str_contains($src, "require_once __DIR__ . '/crm_lib.php'"),
    'sin esto la funcion no existe y el sondeo entero muere con un fatal');
chequear('y ademas chequea que exista antes de llamarla',
    str_contains($src, "function_exists('crm_adoptar_anon')"),
    'si manana alguien la mueve de archivo, el peor caso tiene que ser cosmetico');

$lib = (string)file_get_contents(__DIR__ . '/api/crm_lib.php');
$desde = (int)strpos($lib, 'function crm_adoptar_anon');
chequear('la funcion se traga sus propios errores',
    str_contains(substr($lib, $desde, 2600), 'catch (Throwable'),
    'corre dentro del sondeo que entrega los mensajes');

/* La carrera: entre que se mira si existe el anonimo y que se lo renombra,
   puede entrar un mensaje del jugador y adoptarlo por el camino viejo. */
chequear('el UPDATE vuelve a exigir la clave anonima',
    str_contains(substr($lib, $desde, 2600), 'WHERE id = ? AND clave = ?'),
    'sin eso, dos renombres compiten y el segundo pisa al primero');

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
