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
        . ';port=' . (getenv('T_PORT') ?: '3306')
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
echo "\n=== 1b. Sospecha vs certeza: cuando SI se le cambia el nombre ===\n";

/* EL BUG, del log del 4/9/2026 a las 22:58: cuatro intentos seguidos de la
   misma persona -- holaChela1560, 6877, 2768, 1879 -- todos marcados "el
   nombre ya esta tomado", y minutos despues la API creaba cuentas al primer
   intento. El nombre NUNCA estuvo ocupado: se habia caido la sesion.

   La frase que los disparo la escribe el bot cuando manda el formulario y
   despues no encuentra al jugador en el listado. Pero si la sesion murio, el
   listado tampoco anda: "no figura" ahi no prueba que el nombre este tomado,
   prueba que no pudo mirar.

   Creerle costaba caro. Cada sospecha renombraba y quemaba un intento, asi
   que dos minutos de sesion caida se llevaban puestos los diez reintentos y
   la persona se quedaba sin cuenta -- que es exactamente lo que paso. */
$dicePlataforma = 'el panel rechazo la creacion: User with username: Juan - already exist';
$deduceElBot    = 'el panel respondio 200 pero el jugador NO figura: lo mas probable es que el nombre ya este tomado';

chequear('lo que dice la plataforma es CERTEZA',
         alta_nombre_ocupado_seguro($dicePlataforma) === true);
chequear('lo que deduce el bot es SOSPECHA, no certeza',
         alta_nombre_ocupado_seguro($deduceElBot) === false
         && alta_nombre_ocupado_sospecha($deduceElBot) === true);

/* Con certeza se renombra de una: no hay nada que confirmar y cada vuelta con
   el mismo nombre es un rechazo asegurado. */
chequear('con certeza renombra en el PRIMER intento',
         alta_debe_renombrar($dicePlataforma, 1) === true);

/* Con sospecha se le da una vuelta mas con el MISMO nombre. Si fue la sesion,
   el reintento sale bien -- y sale bien con el nombre que la persona eligio,
   que es mejor que salir bien con uno inventado. */
chequear('con sospecha NO renombra en el primero',
         alta_debe_renombrar($deduceElBot, 1) === false);
chequear('pero si vuelve a fallar, ahi si renombra',
         alta_debe_renombrar($deduceElBot, 2) === true);

/* Un fallo que no tiene nada que ver no renombra nunca, pase lo que pase. */
chequear('una sesion caida declarada NO renombra ni al quinto intento',
         alta_debe_renombrar('Sesion caida, sin re-login', 5) === false);


// ===========================================================================
echo "\n=== 2. Elegir un nombre libre ===\n";

/* Se siembra ocupado el nombre QUE SE VA A GENERAR (con prefijo), no el crudo:
   si no, el test no prueba nada -- 'tstlibre' y 'holaTstlibre' son distintos. */
$pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
               ON DUPLICATE KEY UPDATE coins=0")->execute([crc32('holaTstlibre'), 'holaTstlibre']);

/* El nombre sale con PREFIJO y sufijo de 3 digitos DESDE LA PRIMERA ronda:
   "holaJuan847". Decision del dueño (6/9/2026): el nombre "lindo" sin numeros
   chocaba con la plataforma (el nombre es unico entre TODOS los agentes) y
   cada choque disparaba la maquinaria lenta -- verificar el listado (~15-20s),
   renombrar, reintentar -- con el jugador esperando. Unico por construccion =
   camino rapido siempre. */
$libre = alta_usuario_disponible($pdo, 'tstnuevo', 0);
chequear('prefijo + 3 digitos desde la primera ronda',
         (bool)preg_match('/^holaTstnuevo[0-9]{3}$/', $libre), $libre);

/* Si aun asi la plataforma rechaza, la entropia sube: 4 digitos. */
chequear('ronda 1: sigue con 3 digitos',
         (bool)preg_match('/^holaTstnuevo[0-9]{3}$/', alta_usuario_disponible($pdo, 'tstnuevo', 1)));
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

// ===========================================================================
echo "
=== 4. Nombres placeholder que el modelo NO puede colar como cuenta ===
";

/* El chatbot rechaza estos: cuando el jugador pide "haceme una cuenta" sin dar
   nombre, el modelo a veces inventa uno generico en vez de preguntar (visto en
   produccion 12/9: creo "nuevojugador123"). alta_nombre_es_placeholder los
   caza para que el handler no cree cuentas con nombres que la persona no eligio. */
foreach (['nuevojugador123', 'jugador123', 'usuario', 'nuevousuario',
          'player1', 'miusuario', 'cuenta123', 'user'] as $ph) {
    chequear('rechaza placeholder: ' . $ph, alta_nombre_es_placeholder($ph) === true);
}
/* Y NO puede confundir un nombre real con un placeholder. */
foreach (['holaJuan', 'juan123', 'pedro', 'santucruz275', 'lucas20206',
          'jugadorcito', 'juanusuario'] as $real) {
    chequear('acepta nombre real: ' . $real, alta_nombre_es_placeholder($real) === false);
}

// ===========================================================================
echo "\n=== 5. Sanear el nombre SIN renombrarlo (el alta por chat) ===\n";

/* POR QUE EXISTE ESTA FUNCION APARTE (16/09/2026). alta_usuario_disponible()
   hace dos cosas pegadas: sanea el nombre Y le pone prefijo + sufijo. La
   landing quiere las dos --ahi nadie eligio nada--, pero el chatbot no: el
   jugador ESCRIBIO el nombre que quiere. Hasta este dia el chat no llamaba a
   ninguna de las dos y mandaba el nombre crudo al panel, que lo rechazaba por
   un acento o por una letra de menos; el modelo relataba ese error tecnico con
   sus palabras e inventaba motivos ("«Rodrigo» tiene menos de 4 caracteres",
   siete letras). Sanear siempre arregla eso sin cambiarle el nombre a nadie. */

chequear('un nombre normal NO se toca',
         alta_nombre_sanear('Rodrigo') === 'Rodrigo', alta_nombre_sanear('Rodrigo'));
chequear('ni le agrega prefijo ni sufijo',
         alta_nombre_sanear('Sabatino') === 'Sabatino', alta_nombre_sanear('Sabatino'));

/* Lo que SI arregla, que es lo que rechazaba alta_validar(). */
$tilde = alta_nombre_sanear('Mar' . chr(0xC3) . chr(0xAD) . 'a');   // María
chequear('transliterar el acento en vez de comerse la letra',
         $tilde === 'Maria', $tilde);
$enie = alta_nombre_sanear('Nu' . chr(0xC3) . chr(0xB1) . 'ez');    // Nuñez
chequear('la enie tampoco parte el nombre', $enie === 'Nunez', $enie);
chequear('los espacios se van',
         alta_nombre_sanear('juan carlos') === 'juancarlos', alta_nombre_sanear('juan carlos'));
chequear('el nombre corto se estira (el panel rechaza los de 3)',
         mb_strlen(alta_nombre_sanear('ab')) >= 4, alta_nombre_sanear('ab'));
chequear('punto, guion y guion bajo se conservan',
         alta_nombre_sanear('juan_perez.99-x') === 'juan_perez.99-x');
chequear('nunca pasa de 64 (el tope de alta_validar)',
         mb_strlen(alta_nombre_sanear(str_repeat('a', 200))) === 64);

/* Y lo saneado tiene que pasar alta_validar() SIEMPRE: si no, el saneado no
   sirve de nada y el 400 vuelve igual. */
foreach (['Rodrigo', 'Mar' . chr(0xC3) . chr(0xAD) . 'a', 'ab', 'juan carlos',
          '!!!??', str_repeat('z', 200), '.-_'] as $crudo) {
    chequear('lo saneado pasa alta_validar: "' . mb_substr($crudo, 0, 18) . '"',
             alta_validar(alta_nombre_sanear($crudo), 'clave123456', '') === null,
             alta_nombre_sanear($crudo));
}

/* Un nombre que al sanear no deja NADA queda en "jugador" pelado. El chatbot
   lo vuelve a pasar por el filtro de placeholders justo por esto: crear una
   cuenta llamada "jugador" es casi tan malo como que el modelo la invente. */
chequear('un nombre de puros simbolos cae en el filtro de placeholders',
         alta_nombre_es_placeholder(alta_nombre_sanear('!!!???')) === true,
         alta_nombre_sanear('!!!???'));

// ===========================================================================
echo "\n=== 6. \"¿Esta tomado?\" tiene que dar lo MISMO que el 409 ===\n";

/* La condicion esta escrita una sola vez (alta_nombre_tomado) porque la usan
   tres caminos. Si se separaran, el chat creeria que el nombre esta libre,
   encolaria igual, y el 409 le llegaria al jugador lo mismo que antes. Estos
   chequeos son la prueba de que no se separaron. */
limpiar($pdo);

$pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
               ON DUPLICATE KEY UPDATE coins=0")->execute([crc32('tstyaes'), 'tstyaes']);
chequear('el que ya es jugador da tomado', alta_nombre_tomado($pdo, 'tstyaes') === true);
$r = alta_encolar($pdo, ['usuario' => 'tstyaes', 'password' => 'clave123456']);
chequear('...y alta_encolar contesta 409', (int)$r['http'] === 409, json_encode($r));

chequear('uno libre da libre', alta_nombre_tomado($pdo, 'tstnadie') === false);
$r = alta_encolar($pdo, ['usuario' => 'tstnadie', 'password' => 'clave123456']);
chequear('...y alta_encolar lo encola', (int)$r['http'] === 200, json_encode($r));

/* Ya encolado, el mismo nombre pasa a estar tomado por el pedido en curso. */
chequear('un pedido vivo en la cola ocupa el nombre',
         alta_nombre_tomado($pdo, 'tstnadie') === true);
$r = alta_encolar($pdo, ['usuario' => 'tstnadie', 'password' => 'clave123456']);
chequear('...y repetirlo da 409', (int)$r['http'] === 409, json_encode($r));

/* Pero una fila en 'error' NO ocupa nada: esa cuenta no llego a crearse. Es la
   parte que mas facil se rompe al reescribir la consulta. */
$pdo->prepare("UPDATE altas SET estado = 'error' WHERE usuario = ?")->execute(['tstnadie']);
chequear('una fila en error deja el nombre libre',
         alta_nombre_tomado($pdo, 'tstnadie') === false);
$r = alta_encolar($pdo, ['usuario' => 'tstnadie', 'password' => 'clave123456']);
chequear('...y alta_encolar la reutiliza en vez de rechazar',
         (int)$r['http'] === 200, json_encode($r));

// ===========================================================================
echo "\n=== 7. El orden del alta por chat (lo que evita cuentas duplicadas) ===\n";

/* ESTO ES POSICIONAL Y NO SE PUEDE TESTEAR DE OTRA FORMA: ejecutar_tool() vive
   en chatbot.php, que se conecta a la base y atiende el request apenas se
   incluye. Asi que se lee el bloque como texto, igual que hace t_contexto.php
   con el prompt.

   Lo que se protege: renombrar al chocar (el arreglo del 16/09/2026) ABRE la
   puerta a crear cuentas duplicadas. El modelo llama dos veces a la
   herramienta, la segunda vuelta ve el nombre "ocupado" --por su propio pedido
   de hace diez segundos-- y crea una segunda cuenta con otro nombre. Lo unico
   que lo evita es que la pregunta "¿este chat ya tiene un alta en curso?" vaya
   ANTES del renombre. Un refactor que las reordene no rompe ningun test de
   comportamiento y sale a produccion creando cuentas de a dos. */
$src = file_get_contents(__DIR__ . '/api/chatbot.php');
$ini = strpos($src, "if (\$nombre === 'crear_cuenta') {");
$fin = strpos($src, "if (\$nombre === 'crear_recarga') {", $ini ?: 0);
chequear('se encuentra el bloque crear_cuenta', $ini !== false && $fin !== false);
$blq = ($ini !== false && $fin !== false) ? substr($src, $ini, $fin - $ini) : '';

/* SE MIRA EL CODIGO, NO LOS COMENTARIOS. La primera version de estos chequeos
   daba falso negativo: el comentario que explica el arreglo NOMBRA
   alta_usuario_disponible() para decir que justamente NO se la llama de
   entrada, y strpos() encontraba esa mencion antes que la llamada real. Con el
   lexer de PHP no hay forma de equivocarse. */
$blqCod = '';
foreach (token_get_all('<?php ' . $blq) as $tk) {
    if (is_array($tk)) {
        if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) { continue; }
        $blqCod .= $tk[1];
    } else {
        $blqCod .= $tk;
    }
}
$blq = $blqCod;

$pSanear   = strpos($blq, 'alta_nombre_sanear(');
$pSid      = strpos($blq, 'entrega_sid = ?');
$pTomado   = strpos($blq, 'alta_nombre_tomado(');
$pRenombre = strpos($blq, 'alta_usuario_disponible(');
$pEncolar  = strpos($blq, 'alta_encolar(');

chequear('el chat sanea el nombre', $pSanear !== false);
chequear('el chat pregunta si esta tomado', $pTomado !== false);
chequear('el chat puede renombrar', $pRenombre !== false);
chequear('LA GUARDA POR SID VA ANTES DEL RENOMBRE (si no: cuentas duplicadas)',
         $pSid !== false && $pRenombre !== false && $pSid < $pRenombre,
         "sid=$pSid renombre=$pRenombre");
chequear('se sanea antes de preguntar si esta tomado',
         $pSanear !== false && $pTomado !== false && $pSanear < $pTomado);
chequear('y todo eso antes de encolar',
         $pEncolar !== false && $pRenombre < $pEncolar);
chequear('el renombre esta condicionado a que este tomado, no es incondicional',
         (bool)preg_match('/if \(\$tomado\) \{ \$u = alta_usuario_disponible/', $blq));
/* La guarda por sid tiene que acotar el rato: un alta trabada en 'pendiente'
   no puede dejar al jugador sin poder pedir cuenta nunca mas. */
chequear('la guarda por sid se acota en el tiempo',
         str_contains($blq, 'pedido_en >'));

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
