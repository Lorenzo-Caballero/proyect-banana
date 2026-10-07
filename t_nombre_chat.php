<?php
/**
 * t_nombre_chat.php — El nombre de la cuenta lo elige el jugador, no el modelo.
 *
 * LO QUE PASÓ (05/10/2026, medido contra producción). Al mensaje
 * "No tengo cuenta, quiero crear una" --sin ningún nombre-- el chat de un
 * cliente contestó "Dale, ya te la estoy creando" y creó `holaJuanperez584`.
 * Nadie pidió ese nombre: lo inventó el modelo.
 *
 * POR QUÉ LA DEFENSA VIEJA NO ALCANZÓ. `alta_nombre_es_placeholder()` atrapa
 * los genéricos ("jugador123", "nuevousuario") con una lista de palabras. Pero
 * el modelo no inventa genéricos: inventa nombres PLAUSIBLES. "Juanperez" pasa
 * cualquier lista, porque es exactamente igual a un nombre de verdad.
 *
 * EL CAMBIO DE PREGUNTA ES EL ARREGLO. En vez de "¿parece inventado?" --que se
 * contesta adivinando-- se pregunta "¿esto lo escribió el jugador?", que se
 * contesta mirando el historial, que llega en el mismo request.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, en los dos sentidos:
 *   1. QUE NO SE CREE UNA CUENTA QUE NADIE PIDIÓ.
 *   2. QUE NO SE RECHACE UN NOMBRE LEGÍTIMO. Una defensa que pide el nombre
 *      cuando el jugador ya lo dio es un bucle: lo repite y lo repite.
 *
 *     php t_nombre_chat.php
 */
declare(strict_types=1);

if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/altas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/** Historial como lo manda el widget. */
function hist(array $dichos): array {
    $h = [];
    foreach ($dichos as $d) { $h[] = ['role' => 'user', 'content' => $d]; }
    return $h;
}

// ===========================================================================
echo "=== 1. El caso real: nadie dijo ese nombre ===\n";
/* El mensaje EXACTO del incidente y el nombre EXACTO que el modelo inventó. */
$soloPedido = hist(['No tengo cuenta, quiero crear una']);
chequear('"quiero crear una" no autoriza el nombre que invente el modelo',
         alta_nombre_lo_dijo_el_jugador('holaJuanperez584', $soloPedido) === false,
         'es el caso de produccion: se creo holaJuanperez584 sin que nadie lo pidiera');
chequear('tampoco un nombre plausible cualquiera',
         alta_nombre_lo_dijo_el_jugador('Martina', $soloPedido) === false,
         'la lista de placeholders no atrapa los nombres que parecen reales');
chequear('ni con el historial vacío (el primer mensaje del chat)',
         alta_nombre_lo_dijo_el_jugador('holaPedro123', []) === false);

// ===========================================================================
echo "\n=== 2. Pero si lo dijo, se respeta ===\n";
/* UNA DEFENSA QUE RECHAZA LO LEGÍTIMO ES UN BUCLE: el jugador da el nombre, el
   bot se lo vuelve a pedir, y así. Peor que no tener defensa. */
chequear('lo dijo tal cual',
         alta_nombre_lo_dijo_el_jugador('Martina', hist(['quiero una cuenta', 'Martina'])) === true);
chequear('lo dijo en una frase',
         alta_nombre_lo_dijo_el_jugador('Martina', hist(['que se llame Martina porfa'])) === true);
/* El sistema le antepone `hola` y le agrega dígitos (alta_usuario_disponible):
   si eso no se descontara, NINGÚN nombre generado matchearía nunca. */
chequear('con el prefijo y los dígitos que agrega el sistema',
         alta_nombre_lo_dijo_el_jugador('holaMartina847', hist(['Martina'])) === true,
         'el `hola` y los numeros los pone el sistema, no el jugador');
/* El modelo normaliza lo que el jugador escribe: hay que comparar igual. */
chequear('con acentos y espacios de por medio',
         alta_nombre_lo_dijo_el_jugador('holaJuanperez12', hist(['me llamo Juan Pérez'])) === true,
         'el modelo manda "juanperez" y el jugador escribio "Juan Pérez"');
chequear('sin importar mayúsculas',
         alta_nombre_lo_dijo_el_jugador('holaCOCO99', hist(['coco'])) === true);

// ===========================================================================
echo "\n=== 3. Solo cuenta lo que dijo ÉL ===\n";
/* Si contara lo que dice el BOT, el modelo se autorizaría solo: propone un
   nombre en su mensaje y después lo crea citándose a sí mismo. */
$loDijoElBot = [
    ['role' => 'user',      'content' => 'quiero una cuenta'],
    ['role' => 'assistant', 'content' => 'te propongo el usuario Martina, ¿te sirve?'],
];
chequear('un nombre que propuso el BOT no alcanza',
         alta_nombre_lo_dijo_el_jugador('Martina', $loDijoElBot) === false,
         'si no, el modelo se autoriza solo: lo propone y lo crea citandose');
chequear('pero si el jugador lo confirma escribiéndolo, sí',
         alta_nombre_lo_dijo_el_jugador('Martina', array_merge($loDijoElBot,
             [['role' => 'user', 'content' => 'si, Martina']])) === true);

// ===========================================================================
echo "\n=== 4. Nada de falsos positivos por coincidencia ===\n";
/* Un nombre de 1-2 letras lo "contiene" cualquier texto: seria una defensa
   que no defiende. */
chequear('un nombre de dos letras no pasa por coincidencia',
         alta_nombre_lo_dijo_el_jugador('ab', hist(['quiero abrir una cuenta'])) === false);

// ===========================================================================
echo "\n=== 4b. Un nombre es una palabra, no una frase ===\n";
/* EL AGUJERO QUE ABRIO LA DEFENSA ANTERIOR. Al exigir que el nombre estuviera
   en lo que dijo el jugador, el modelo encontro la salida obvia: mandar el
   mensaje ENTERO. Resultado real (05/10/2026):
   `holaNotengocuentaquierocrearuna548`, armado con "No tengo cuenta, quiero
   crear una". Cumplia la regla y era igual de inventado. */
chequear('el caso real: el mensaje entero convertido en nombre',
         alta_nombre_parece_frase('holaNotengocuentaquierocrearuna548') === true,
         'paso la defensa del historial porque el jugador SI escribio eso');
chequear('la frase cruda tambien',
         alta_nombre_parece_frase('No tengo cuenta, quiero crear una') === true);
chequear('tres palabras ya es una frase',
         alta_nombre_parece_frase('quiero una cuenta') === true);
chequear('y lo que el jugador PIDE no es como se llama',
         alta_nombre_parece_frase('crearcuenta') === true);

/* Y NO puede rechazar nombres de verdad: una defensa que le discute el nombre
   a quien lo eligio bien es un bucle. */
foreach (['Martina', 'juanperez', 'Juan Perez', 'Coco', 'maximiliano',
          'holaMartina847', 'elpepe', 'lauri23'] as $bueno) {
    chequear("deja pasar un nombre real: $bueno",
             alta_nombre_parece_frase($bueno) === false);
}
/* El corte de largo tiene que separar los dos mundos sin pedirle a nadie que
   se acorte el nombre. */
chequear('un nombre largo pero razonable pasa (11 letras)',
         alta_nombre_parece_frase('maximiliano') === false);
chequear('y uno de 25 letras pegadas no',
         alta_nombre_parece_frase('estenombreesdemasiadolargo') === true);

/* El orden importa: si se mirara primero el historial, el mensaje entero
   pasaria, porque el jugador lo escribio. */
$cb0 = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('se chequea la FORMA antes que el historial',
         strpos($cb0, 'alta_nombre_parece_frase($u)') < strpos($cb0, 'alta_nombre_lo_dijo_el_jugador($u'),
         'al reves, el mensaje entero pasa: el jugador lo escribio');

// ===========================================================================
echo "\n=== 5. Está enganchado en el chat, y antes de crear ===\n";
$cb = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('crear_cuenta lo consulta',
         str_contains($cb, 'alta_nombre_lo_dijo_el_jugador($u'));
chequear('y corta antes de encolar nada',
         strpos($cb, 'alta_nombre_lo_dijo_el_jugador($u') < strpos($cb, "'codigo' => 'nombre_no_pedido'") + 400
         && strpos($cb, "'codigo' => 'nombre_no_pedido'") < strpos($cb, '$r = alta_encolar($pdo'));
/* El historial llega por GLOBALS porque ejecutar_tool() no lo recibe. Si eso
   se rompe, la funcion ve un array vacio y RECHAZA TODAS las altas -- una
   defensa que apaga la creacion de cuentas entera. */
chequear('el historial le llega de verdad (GLOBALS, definido antes)',
         str_contains($cb, "\$GLOBALS['CB_HISTORIAL'] = \$historial;")
         && strpos($cb, "\$GLOBALS['CB_HISTORIAL'] = \$historial;")
            < strpos($cb, "\$GLOBALS['CB_HISTORIAL'] ?? []"),
         'sin esto ve un historial vacio y rechaza TODAS las altas');
chequear('el mensaje que ve el jugador es la pregunta, no un error',
         str_contains($cb, "'error' => '¿Qué nombre de usuario querés para tu cuenta?'"));

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
