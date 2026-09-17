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

/* ESTO CAMBIO EL 16/09/2026, Y LA DECISION VIEJA ERA BUENA CUANDO SE TOMO.
   Acá se chequeaba que un fallo ajeno al nombre --una sesion caida-- no
   renombrara NUNCA, "pase lo que pase". El motivo era real: con MAX_INTENTOS
   en 3, cada renombre inutil se comia uno de los tres y un bache de sesion de
   dos minutos dejaba a la persona sin cuenta.

   Dos cosas lo dieron vuelta:

   1. MAX_INTENTOS paso de 3 a 10. Renombrar de mas ya no deja a nadie sin
      cuenta: quedan siete intentos.

   2. El mensaje puede MENTIR, y se vio entero. El 16/09 el fast-path detecto
      "nombre ya existente" en tres altas del chat, fue igual al formulario con
      el mismo nombre, el WAF se lo tapo, y lo que quedo guardado fue "no
      aparecio el formulario de alta". El diagnostico correcto existia y lo
      piso un error posterior. Sin renombre, esas tres altas iban a reintentar
      con el mismo nombre hasta rendirse: horas de espera para nada.

   O sea que apoyarse SOLO en el texto es fragil cuando un error puede pisar a
   otro. La regla nueva no adivina el motivo: dice que despues de DOS fallos
   con el mismo nombre da igual cual sea. Si era el nombre, renombrar lo
   arregla; si era otra cosa, renombrar no lo empeora.

   Lo que se conserva es lo que de verdad importaba: los dos primeros intentos
   siguen respetando el nombre que eligio la persona, y ahi se resuelven los
   fallos transitorios. Lo que se pierde es su nombre en un caso raro; lo que
   se gana es que nadie se quede sin cuenta. */
chequear('una sesion caida NO renombra en el primer intento',
         alta_debe_renombrar('Sesion caida, sin re-login', 1) === false);
chequear('pero al segundo fallo se renombra igual, diga lo que diga',
         alta_debe_renombrar('Sesion caida, sin re-login', 2) === true);

/* EL CASO QUE PIDIO EL CAMBIO, tal cual quedo guardado en la base. */
$wafTapo = 'Excepcion: No aparecio el formulario de alta en https://agents.ganamos';
chequear('el error que tapo al diagnostico no matchea ninguna pista',
         alta_parece_nombre_ocupado($wafTapo) === false, 'y por eso hacia falta la regla nueva');
chequear('aun asi, al segundo fallo el alta se renombra y sale',
         alta_debe_renombrar($wafTapo, 2) === true);
chequear('y no antes: el primer intento le respeta el nombre',
         alta_debe_renombrar($wafTapo, 1) === false);

/* La certeza sigue mandando desde el primer intento: cuando la plataforma lo
   dice con todas las letras no hay por que gastar un intento. */
chequear('con certeza se renombra igual en el primero',
         alta_debe_renombrar('User with username: Juan - already exist', 1) === true);


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
echo "\n=== 7. El alta por chat: nombre como la landing, y sin duplicar ===\n";

/* LA POLITICA (decision del dueño, 16/09/2026): "no me importa que si se llaman
   Juan el usuario siempre sea holajuan123, siempre y cuando sea rapido. Como la
   landing. Necesito que falle lo menos posible."

   Revierte el camino intermedio de esa misma mañana --respetar el nombre
   elegido si estaba libre-- que parecia lo mejor de los dos mundos y resulto lo
   peor: `alta_nombre_tomado()` solo ve NUESTRO espejo, y el username es unico
   en TODA la plataforma. "Libre para nosotros" no dice nada del panel, asi que
   nombres como Javierso o Bejarano pasaban el chequeo, se encolaban, y la
   plataforma los rechazaba despues -- cuando ya costaba horas.

   ESTO ES POSICIONAL Y NO HAY OTRA FORMA: ejecutar_tool() vive en chatbot.php,
   que se conecta a la base y atiende el request apenas se incluye. Se lee el
   bloque como texto, igual que hace t_contexto.php con el prompt. */
$src = file_get_contents(__DIR__ . '/api/chatbot.php');
$ini = strpos($src, "if (\$nombre === 'crear_cuenta') {");
$fin = strpos($src, "if (\$nombre === 'crear_recarga') {", $ini ?: 0);
chequear('se encuentra el bloque crear_cuenta', $ini !== false && $fin !== false);
$blq = ($ini !== false && $fin !== false) ? substr($src, $ini, $fin - $ini) : '';

/* SE MIRA EL CODIGO, NO LOS COMENTARIOS. La primera version de estos chequeos
   daba falso negativo: el comentario que explica el arreglo NOMBRA las
   funciones de las que habla, y strpos() encontraba esas menciones antes que
   las llamadas reales. Con el lexer de PHP no hay forma de equivocarse. */
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

$pSid      = strpos($blq, 'entrega_sid = ?');
$pNombre   = strpos($blq, 'alta_usuario_disponible(');
$pEncolar  = strpos($blq, 'alta_encolar(');

chequear('el chat genera el nombre con la MISMA función que la landing',
         $pNombre !== false);
/* Incondicional: es toda la decisión. Un `if` alrededor sería volver al camino
   intermedio que fallaba. */
chequear('y lo hace SIEMPRE, no solo cuando el nombre está tomado',
         (bool)preg_match('/\n\s*\$u = alta_usuario_disponible\(\$pdo, \$u\);/', $blq),
         'si esto falla, alguien le puso una condición encima');
chequear('ya no se pregunta por nuestro espejo, que no sabe lo que importa',
         !str_contains($blq, 'alta_nombre_tomado('));

/* LA GUARDA QUE SOSTIENE TODO LO DEMAS. Con el nombre crudo, dos llamadas del
   modelo chocaban contra el 409 y no pasaba nada. Ahora cada llamada genera un
   nombre NUEVO y unico por construccion: nada choca, y dos llamadas serian dos
   cuentas. Un refactor que mueva esto abajo del nombre no rompe ningun test de
   comportamiento y sale a produccion creando cuentas de a dos. */
chequear('LA GUARDA POR SID VA ANTES DE GENERAR EL NOMBRE (si no: cuentas duplicadas)',
         $pSid !== false && $pNombre !== false && $pSid < $pNombre,
         "sid=$pSid nombre=$pNombre");
chequear('la guarda pregunta por el chat y no por el nombre',
         str_contains($blq, 'entrega_sid = ?'),
         'preguntar por el nombre no serviría: cada vuelta trae uno distinto');
chequear('y se acota en el tiempo, para no dejarlo sin pedir cuenta nunca más',
         str_contains($blq, 'pedido_en >'));
/* AGREGADO EL 16/09/2026: la guarda tiene que ver TAMBIEN las 'ok'. Con el
   fast-path un alta queda 'ok' en segundos; si la guarda solo mira
   pendiente/procesando, el modelo re-llamando ("no me llego nada") genera
   otro nombre unico y crea una SEGUNDA cuenta. Las 'error' quedan afuera a
   proposito: ahi no se creo nada y reintentar es legitimo. */
chequear("la guarda cubre las 'ok': una cuenta ya creada en este chat no se duplica",
         (bool)preg_match("/estado IN \('pendiente', 'procesando', 'ok'\)/", $blq));
chequear("pero las 'error' siguen afuera (ahi el reintento es legitimo)",
         !str_contains($blq, "'pendiente', 'procesando', 'ok', 'error'"));
chequear('el nombre se resuelve antes de encolar',
         $pEncolar !== false && $pNombre < $pEncolar);

/* El filtro de placeholders sigue ANTES de todo: un "jugador123" inventado por
   el modelo no se convierte en "holaJugador123", se rechaza y se pregunta. */
$pPlace = strpos($blq, 'alta_nombre_es_placeholder(');
chequear('los nombres inventados por el modelo se frenan antes de generar nada',
         $pPlace !== false && $pPlace < $pNombre, "place=$pPlace nombre=$pNombre");


// ===========================================================================
echo "\n=== 8. La cola demorada: no prometer lo que no controlamos ===\n";

/* EL CASO (16/09/2026). El bot le dijo a un jugador "en un par de minutos te
   aparecen los datos" y la cuenta no salio: el alta choco contra el challenge
   del WAF y quedo esperando el rescate automatico. El jugador se quedo mirando
   el chat.

   Lo peculiar es que ese plazo NO estaba escrito en ningun lado: lo invento el
   modelo, y con razon, porque encolar es instantaneo y nada le decia que la
   cuenta la crea OTRO sistema. La unica forma de que deje de inventarlo es
   decirle como viene la cola. */
limpiar($pdo);

$ponerAlta = function (string $u, string $estado, int $haceMin, bool $conClave = true) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO altas (usuario, password, estado, origen, pedido_en)
         VALUES (?,?,?,'chatbot', NOW() - INTERVAL ? MINUTE)"
    )->execute([$u, $conClave ? 'clave123456' : null, $estado, $haceMin]);
};

chequear('cola vacía = sin atraso', alta_cola_atraso($pdo) === 0);

$ponerAlta('tstfresca', 'pendiente', 0);
chequear('un alta recién pedida no es atraso', alta_cola_atraso($pdo) === 0);

$ponerAlta('tstvieja', 'procesando', 7);
chequear('cuenta los minutos de la MÁS vieja, no de la última',
         alta_cola_atraso($pdo) === 7, (string)alta_cola_atraso($pdo));

/* Una que ya salió no habla del estado de la cola. Si contara, cualquier alta
   vieja y resuelta dejaría al chat diciendo "demorada" para siempre. */
limpiar($pdo);
$ponerAlta('tstlista', 'ok', 600);
chequear('una ya creada no cuenta como atraso', alta_cola_atraso($pdo) === 0);
limpiar($pdo);
$ponerAlta('tstfallada', 'error', 600);
chequear('una que se rindió tampoco', alta_cola_atraso($pdo) === 0);

/* Sin password el bot no puede tipear nada: esa fila no va a salir nunca y es
   otra clase de problema, no una cola lenta. */
limpiar($pdo);
$ponerAlta('tstsinclave', 'pendiente', 90, false);
chequear('una sin clave no se cuenta (no es cola lenta, es otra cosa)',
         alta_cola_atraso($pdo) === 0, (string)alta_cola_atraso($pdo));

limpiar($pdo);
$ponerAlta('tstjusto', 'pendiente', ALTA_DEMORA_MIN);
chequear('en el límite exacto ya se considera demorada',
         alta_cola_atraso($pdo) >= ALTA_DEMORA_MIN);

/* El aviso de Telegram y el mensaje del chat tienen que usar EL MISMO numero:
   si el chat dijera "puede demorar" antes de que suene la alerta, el operador
   se entera del problema por el jugador. */
$refl = new ReflectionFunction('alta_avisar_trabadas');
$porDefecto = $refl->getParameters()[1]->getDefaultValue();
chequear('el aviso de Telegram usa el mismo umbral que el chat',
         $porDefecto === ALTA_DEMORA_MIN, "aviso=$porDefecto chat=" . ALTA_DEMORA_MIN);

limpiar($pdo);

// ===========================================================================
echo "\n=== 9. Lo que el chat le dice al modelo ===\n";

/* Posicional otra vez, por lo mismo que la sección 7: ejecutar_tool() vive en
   chatbot.php, que atiende el request apenas se incluye. */
$srcCC = file_get_contents(__DIR__ . '/api/chatbot.php');
$i0 = strpos($srcCC, "if (\$nombre === 'crear_cuenta') {");
$i1 = strpos($srcCC, "if (\$nombre === 'crear_recarga') {", $i0 ?: 0);
$bloque = ($i0 !== false && $i1 !== false) ? substr($srcCC, $i0, $i1 - $i0) : '';

chequear('el chat mira el atraso de la cola antes de contestar',
         str_contains($bloque, 'alta_cola_atraso('));
chequear('y el mensaje depende de eso, no es uno solo',
         str_contains($bloque, "'mensaje' => \$demorada"));

/* Los dos textos van al MODELO, que los relata con sus palabras. El de la cola
   demorada tiene que prohibir el plazo explícitamente: sin eso vuelve a
   inventarlo, que es exactamente lo que pasó. */
$iMsj = strpos($bloque, "'mensaje' => \$demorada");
$textos = substr($bloque, $iMsj, 900);
chequear('con la cola demorada se le PROHIBE prometer un tiempo',
         (bool)preg_match('/NO le prometas/i', $textos), mb_substr($textos, 0, 120));
chequear('y se le ofrece qué decir en su lugar (un agente lo ayuda)',
         str_contains($textos, 'agente'));
chequear('con la cola sana sí puede decir el par de minutos',
         str_contains($textos, 'par de minutos'));

/* El aviso al operador se dispara en el chat, que hasta hoy era el único
   camino que no lo hacía -- la misma asimetría que tenía el nombre de usuario.
   Es donde más falta: es el único lugar donde a alguien se le PROMETIÓ algo. */
chequear('con la cola demorada se avisa al operador',
         str_contains($bloque, 'alta_avisar_trabadas('));
chequear('pero solo si está demorada, no en cada alta',
         (bool)preg_match('/if \(\$demorada && function_exists\(.alta_avisar_trabadas/', $bloque));

// ===========================================================================
echo "
=== 10. La entrega de credenciales se ve en el CRM, TAL CUAL ===
";

/* EL REPORTE ORIGINAL (Nahuel, 16/09/2026): *"el bot sí me da las credenciales
   de acceso, pero cuando intento ver ese mismo chat desde el CRM, hay mensajes
   como ese de las credenciales que no están visibles"*.

   La causa es de diseño: ese mensaje lo DIBUJA EL WIDGET en el navegador del
   jugador (`pintarVarios` en narrarAlta, widget.js) con lo que devuelve
   alta_estado.php. Nunca pasa por `mensajes`, así que el CRM --que muestra esa
   tabla-- no tenía nada que mostrar.

   EL PRIMER ARREGLO SE QUEDÓ CORTO y estos chequeos protegían lo contrario de
   lo que hay que proteger hoy. Anotaba un resumen ("credenciales entregadas,
   usuario X") y los tests exigían que la contraseña NO estuviera, por miedo a
   dejar una clave en `mensajes`.

   Ese miedo no se sostenía: la clave es `ALTA_CLAVE_FIJA` y vale lo mismo para
   TODOS los jugadores. El resumen ocultaba una constante que está en el código
   fuente, y a cambio el operador no veía lo mismo que el jugador tenía en
   pantalla. Nahuel, el mismo día: *"me gustaría que el mensaje que yo vea en el
   chat sea exactamente el mismo que recibe él... tal cual lo ve él, con el
   usuario y la contraseña"*.

   LO QUE ESTOS CHEQUEOS PROTEGEN AHORA es que los dos lados no se separen. El
   texto está escrito DOS VECES --en widget.js para el jugador, en
   alta_estado.php para el CRM-- y no hay fuente única posible: el widget los
   dibuja sin pasar por `mensajes`, que es justamente el bug que esto tapó.
   Dos copias que nadie compara se separan solas. */
$srcAE = file_get_contents(__DIR__ . '/api/alta_estado.php');
$srcW  = file_get_contents(__DIR__ . '/landing/widget.js');

chequear('la entrega se anota en la conversación',
         str_contains($srcAE, 'crm_mensaje('));
chequear('y se busca la conversación por el sid del chat',
         str_contains($srcAE, 'crm_conversacion_id($pdo, $sid'));

/* Se mira el código sin comentarios: los docblocks nombran estas palabras
   varias veces y harían pasar cualquier cosa. */
$codAE = '';
foreach (token_get_all($srcAE) as $tk) {
    if (is_array($tk)) {
        if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) { continue; }
        $codAE .= $tk[1];
    } else { $codAE .= $tk; }
}

chequear('el mensaje del CRM nombra el usuario',
         str_contains($codAE, "\$e['usuario']"));
chequear('Y TAMBIÉN LA CONTRASEÑA (es lo que se pidió ver)',
         str_contains($codAE, "\$e['password']"),
         'el operador tiene que ver lo mismo que el jugador');

/* LOS CUATRO RENGLONES, LOS MISMOS DE LOS DOS LADOS. Se comparan los textos
   fijos; las partes variables (usuario y clave) se interpolan distinto en PHP
   y en JS, así que se chequean aparte arriba. */
$renglones = [
    '¡Listo! Ya te creé la cuenta. Anotá estos datos:',
    'Usuario: ',
    'Contraseña: ',
    'Guardala bien, no te la voy a poder repetir.',
];
foreach ($renglones as $r) {
    chequear("el CRM escribe: «" . mb_substr($r, 0, 34) . "»",
             str_contains($codAE, $r));
    chequear("   y el widget le dice lo MISMO al jugador",
             str_contains($srcW, $r),
             'si cambiás uno, cambiá el otro: no hay fuente única');
}

/* LA DECISIÓN DE GUARDAR LA CLAVE SE APOYA EN QUE ES FIJA. El día que deje de
   serlo, una clave POR JUGADOR queda en el historial del CRM a la vista de
   cualquier agente -- que es otra cosa, y hay que volver a decidirla. Este
   chequeo existe para que ese día alguien se entere. */
chequear('la clave sigue siendo fija para todos',
         (bool)preg_match('/function alta_clave_nueva\(\)[^}]*return ALTA_CLAVE_FIJA;/s',
                          file_get_contents(__DIR__ . '/api/altas_lib.php')),
         'si ya no es fija, revisá si la clave debe seguir yendo a `mensajes`');

/* Y se anota SOLO en la entrega de verdad. alta_entrega() también contesta con
   `entregada` cuando el jugador recarga la página, y anotar eso llenaría el
   chat de cuatro renglones repetidos por algo que pasó una sola vez. */
chequear('solo se anota cuando hay password (la entrega real, una vez)',
         (bool)preg_match('/!empty\(\$e\[.password.\]\)/', $codAE));
chequear('y el guard mira las tres condiciones juntas',
         (bool)preg_match(
             '/!empty\(\$e\[.listo.\]\)\s*&&\s*!empty\(\$e\[.password.\]\)\s*&&\s*!empty\(\$e\[.usuario.\]\)/',
             $codAE),
         'sin `listo` se anotaria un alta que todavia no salio');


// ===========================================================================
echo "\n=== 10. Tope de cuentas por persona (por IP) ===\n";

/* Pedido del dueño (17/09/2026): dos cuentas por persona como maximo; la
   tercera se rechaza. "La persona" es la IP real del jugador (altas.ip). El
   tope frena solo los caminos de autoservicio (landing y chat): el CRM no
   pasa por aca, para que un agente pueda hacer la excepcion a mano. */

$IP_TOPE = '203.0.113.77';   // TEST-NET: nunca es una IP real
$pdo->prepare("DELETE FROM altas WHERE ip IN (?, ?)")->execute([$IP_TOPE, '203.0.113.78']);

chequear('el default es 2 cuentas por IP', alta_max_por_ip() === 2);
chequear('sin IP no frena (fail-open: nunca dejar sin cuenta por un dato que falta)',
         alta_tope_cuentas_superado($pdo, '') === null);
chequear('con 0 cuentas puede', alta_tope_cuentas_superado($pdo, $IP_TOPE) === null);

$insT = $pdo->prepare("INSERT INTO altas (usuario, password, estado, ip) VALUES (?, '12345678', ?, ?)");
$insT->execute(['tstTope1', 'ok', $IP_TOPE]);
chequear('con 1 cuenta todavia puede', alta_tope_cuentas_superado($pdo, $IP_TOPE) === null);

$insT->execute(['tstTope2', 'pendiente', $IP_TOPE]);
chequear('con 2 (una todavia en curso) la tercera se RECHAZA',
         alta_tope_cuentas_superado($pdo, $IP_TOPE) !== null,
         'las pendientes tienen que contar: pedir seguido esquivaria el tope');
chequear('el mensaje no nombra la IP (no regalarle al que abusa como lo detectamos)',
         stripos((string)alta_tope_cuentas_superado($pdo, $IP_TOPE), 'ip') === false
         && stripos((string)alta_tope_cuentas_superado($pdo, $IP_TOPE), 'conexi') === false);

chequear('otra IP no se ve afectada', alta_tope_cuentas_superado($pdo, '203.0.113.78') === null);

$pdo->prepare("UPDATE altas SET estado = 'error' WHERE usuario = 'tstTope2'")->execute();
chequear("un alta fallida ('error') no cuenta: ahi no se creo nada",
         alta_tope_cuentas_superado($pdo, $IP_TOPE) === null);

$pdo->prepare("DELETE FROM altas WHERE ip IN (?, ?)")->execute([$IP_TOPE, '203.0.113.78']);

/* Y LOS DOS CAMINOS DE AUTOSERVICIO LO LLAMAN, DESPUES de su dedup por sid
   (un reintento sobre un alta en curso devuelve ESA, no un rechazo). Se mira
   el codigo: es un orden que ningun test de comportamiento protege. */
foreach (['api/crear_cuenta.php', 'api/chatbot.php'] as $arch) {
    $src = file_get_contents(__DIR__ . '/' . $arch);
    $posDedup = strpos($src, "estado IN ('pendiente', 'procesando', 'ok')");
    $posTope  = strpos($src, 'alta_tope_cuentas_superado(');
    chequear("$arch llama al tope, despues del dedup por sid",
             $posDedup !== false && $posTope !== false && $posTope > $posDedup);
}

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
