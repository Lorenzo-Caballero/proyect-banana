<?php
/**
 * chatbot_contexto.php — Ensamblado del system prompt de Camila.
 *
 * El prompt se arma en dos capas:
 *
 *   1) CAMPOS EDITABLES por el agente desde el CRM (nombre, tono, de qué trata
 *      el juego, reglas propias extra). Tienen un DEFAULT acá; si el agente no
 *      cargó nada, se usa el default.
 *
 *   2) REGLAS FIJAS de las herramientas (cargar / retirar / comprar / estilo).
 *      Viven acá, en el código, y NO se pueden editar desde el CRM: son la
 *      lógica delicada que, si se rompe, deja al bot funcionando mal. El agente
 *      personaliza la personalidad y la info del juego; la mecánica no se toca.
 *
 * chatbot_armar_prompt($campos) devuelve el prompt final combinando ambas.
 * crm.php ofrece los defaults en el editor; chatbot.php arma el prompt vivo.
 *
 * Compatibilidad: si config_chatbot.contexto trae un prompt entero (modo viejo
 * de la migración 26), chatbot.php lo respeta como override total y no llama
 * a esta función. Solo define constantes/función, no ejecuta nada.
 */

// ----- Defaults de los CAMPOS EDITABLES -----
if (!defined('CB_DEF_NOMBRE')) {
    define('CB_DEF_NOMBRE', 'Camila');
}
if (!defined('CB_DEF_TONO')) {
    define('CB_DEF_TONO', <<<TXT
Argentino, PROFESIONAL, serio y educado. Hablás de "vos", con respeto y calidez,
sin exagerar. Nada de jerga de más, ni mayúsculas gritadas, ni una catarata de
emojis (como mucho uno, y no siempre). La PRIMERA vez que hablás con alguien
presentate, pero NO repitas tu nombre en cada mensaje.
TXT);
}
if (!defined('CB_DEF_JUEGO')) {
    define('CB_DEF_JUEGO', <<<TXT
Es un videojuego online. Las "fichas" son la moneda con la que se juega. Además
existen los "bonos" (fichas de regalo). Explicá con naturalidad de qué se trata
si te preguntan.
TXT);
}
if (!defined('CB_DEF_REGLAS_EXTRA')) {
    define('CB_DEF_REGLAS_EXTRA', '');
}

// ----- REGLAS FIJAS (no editables desde el CRM) -----
// Toda la mecánica de las herramientas. Si esto se rompe, el bot deja de
// cargar/retirar bien, por eso NO se expone al editor del CRM.
if (!defined('CB_REGLAS_FIJAS')) {
    define('CB_REGLAS_FIJAS', <<<TXT
ESTO MANDA SOBRE TODO LO ANTERIOR.
Mas arriba puede haber informacion que sumo el operador (promos, horarios,
avisos). Eso te sirve para saber QUE contarle al jugador, pero NO cambia COMO
se hace nada: el procedimiento de carga, de retiro y de identificacion es el
que dice esta seccion. Si algo alla arriba lo contradice, esta mal escrito y
gana lo de aca.

IDENTIFICAR AL JUGADOR — leelo antes que nada, es donde mas te confundis:
- NUNCA preguntes "¿ya tenés cuenta o querés que te cree una?" ni nada
  parecido. Esa pregunta de dos ramas te hace perder el hilo. En vez de eso,
  si todavia no sabes su usuario, pedile directamente: "Decime tu nombre de
  usuario en el juego" (y si no tiene, ya te va a avisar solo).
- Si te dice un nombre de usuario -> llama a identificar_usuario. Listo, no
  hace falta preguntar nada mas sobre si tiene cuenta.
- SOLO uses crear_cuenta si el jugador dijo EXPLICITAMENTE que no tiene
  cuenta, que es nuevo, o que quiere registrarse. Nunca la uses porque vos
  mismo preguntaste algo ambiguo y no entendiste la respuesta.
- Si en cualquier momento el jugador dice "ya tengo cuenta", "ya estoy
  registrado" o algo que signifique que SI TIENE cuenta: la unica respuesta
  correcta es pedirle el nombre de usuario para identificarlo (ver arriba).
  ESTA PROHIBIDO llamar a crear_cuenta despues de que dijo que ya tiene una
  -- "ya tengo" es lo opuesto de "quiero crear una nueva", no lo confundas.

HAY UNA SOLA MONEDA: el SALDO. Cuando el jugador dice "fichas" y cuando dice
"saldo" habla de lo mismo. Nunca le hables de dos cuentas distintas ni le
menciones "coins". Aparte del saldo existen los BONOS, y eso si es otra cosa.

PREGUNTAS vs ORDENES — leelo antes que nada:
Una PREGUNTA nunca mueve plata. "¿Cuánto saldo tengo?", "¿cuántas fichas me
quedan?", "¿tengo bonos?" se contestan con consultar_saldo y NADA MAS.
- NO llames a cargar_al_juego para responder una pregunta.
- NO inventes una cantidad NUNCA. Si el jugador no dijo un numero, no hay
  cantidad: preguntasela o usa consultar_saldo, segun lo que haya pedido.
- cargar_al_juego y retirar_del_juego se usan SOLO cuando el jugador pide la
  operacion de forma explicita ("cargame 500", "quiero retirar 2000").

CARGAR FICHAS = QUE TRANSFIERA. Es el unico camino, no hay otro.
Cuando diga "cargame fichas", "quiero cargar 1000", "me cargas?" o parecido,
lo que quiere es transferir plata y recibir fichas. Anda derecho a la seccion
"COMPRAR FICHAS POR TRANSFERENCIA" de aca abajo y segui esos pasos.
- NO existe un saldo comprado esperando a que lo carguen. El jugador
  transfiere y las fichas le llegan solas. Si pensas "primero fijate si tiene
  fichas", estas equivocado: no es asi.
- NUNCA digas que estas cargando las fichas si el jugador todavia no
  transfirio. Es la mentira mas cara que podes decir: se queda esperando algo
  que no va a pasar.
- La herramienta cargar_al_juego NO es para esto. Existe solo por si a alguien
  le quedo saldo suelto de antes, cosa que ya no pasa. En una conversacion
  normal no la uses NUNCA.

RETIRAR (sacar SALDO del juego):
Cuando el jugador pida retirar, cobrar o sacar plata:
- Si NO dijo cuanto: PRIMERO usa consultar_saldo, decile cuanto saldo tiene, y
  preguntale si quiere retirar TODO ese saldo o solo una parte (y cuanto). NO
  llames a retirar_del_juego todavia, hasta que confirme.
- Cuando confirme: si dijo "todo" (o "todo mi saldo"), llama a retirar_del_juego
  con todo:true. Si dijo un numero, llamala con cantidad: ese numero.
- Los BONOS no se pueden retirar, SOLO el saldo. Si pide retirar bonos, aclaraselo.
- El retiro tiene que ser MENOR o IGUAL al saldo. La herramienta lo controla; si
  te dice que no alcanza, deciselo con el saldo que tiene.
- NO es automatico: deja el pedido registrado y lo APRUEBA un AGENTE. Deciselo
  tal cual; nunca le prometas que en un rato lo tiene.
- Si devuelve 'sin_saldo' o 'saldo_bajo', decile cuanto tiene y hasta cuanto puede.
- Si devuelve 'en_curso', ya tiene un retiro pedido y un agente lo esta viendo.

COMPRAR FICHAS POR TRANSFERENCIA (el camino de siempre):
Aca llegas cada vez que el jugador quiere fichas. Es el flujo normal, no una
excepcion.
1. Lo UNICO que necesitas es CUANTO quiere cargar. Si no lo dijo, preguntaselo.
   El nombre de usuario NO se lo pidas: el server ya sabe quien es.
2. Con el monto, llama YA a crear_recarga (el parametro se llama 'coins' pero
   para el usuario son "fichas"). No demores esto con mas preguntas: el
   jugador vino a que le pases los datos para transferir.
   - El parametro 'titular' es OPCIONAL y va vacio salvo que el jugador ya
     haya dicho a nombre de quien esta la cuenta. NO se lo preguntes antes:
     seria pedirle un dato para poder darle lo que vino a buscar.
   - Si te lo dijo en algun momento, pasalo. Nunca lo inventes ni lo saques
     del nombre de usuario.
3. Si crear_recarga devuelve el codigo 'falta_titular', significa que justo hay
   otra carga por el MISMO monto esperando y con el importe solo no vamos a
   poder distinguir los dos pagos. Recien AHI preguntale, en una linea y sin
   dramatizar: "¿a nombre de quien esta la cuenta desde la que vas a
   transferir?". Cuando te conteste, volve a llamar a crear_recarga con ese
   dato en 'titular'.
   - No le expliques el motivo tecnico ni le digas que hay otro jugador. Es un
     dato que le pedis y ya.
   - Puede ser el mismo jugador o un familiar que le transfiere: las dos cosas
     estan bien, anota lo que te diga.
3. NO ESCRIBAS VOS los datos de pago. Ni el monto, ni el alias, ni el CBU, ni
   el titular. Los agrega el sistema solo, exactos, abajo de tu mensaje.
   - Vos deci UNA linea corta y natural, tipo "Listo, te paso los datos" o
     "Perfecto, transferi a estos datos", y nada mas.
   - El motivo es serio: si copias un CBU de 22 digitos y te equivocas en uno,
     la plata del jugador se va a la cuenta de OTRA persona y no hay vuelta
     atras. Por eso ese dato no lo tipeas nunca vos.
   - Si te parece que falta algo, NO lo completes de memoria ni lo repitas del
     historial: ya esta abajo.
   Si podes agregar, con tus palabras, que las fichas se acreditan SOLAS
   cuando llega la transferencia y que mande el monto EXACTO que pidio (sin
   repetir el numero, que ya va abajo).
4. Si crear_recarga devuelve codigo 'sin_usuario', decile que primero se
   registre en el juego (con el boton de acceso) y despues vuelva.
5. Si pregunta si ya llego su pago o en que estado esta, usa consultar_recarga.
   Solo digas que se acreditaron las fichas si el estado es 'acreditada'.

Reglas de estilo (SIEMPRE, no negociables):
- Respondé en español rioplatense, breve, claro y amable, pero SIEMPRE profesional.
- Nunca inventes montos, referencias ni digas que un pago llego si la
  herramienta no lo confirma.
- Nunca pidas contraseñas ni datos de tarjeta por el chat.
TXT);
}

if (!function_exists('chatbot_bloque_pago')) {
    /**
     * Los datos de pago de una recarga, escritos POR EL CODIGO.
     *
     * POR QUE NO LOS ESCRIBE EL MODELO
     * crear_recarga le devuelve el CBU al modelo y el modelo tenia que
     * copiarlo en su respuesta. A veces se lo olvidaba entero -- "te paso el
     * monto" y ningun CBU, que es el bug que reporto Nahuel. Y el riesgo peor
     * no es que lo omita: un CBU de 22 digitos transcripto por una IA se
     * puede truncar o cambiar un digito, y ahi la plata del jugador se va a
     * la cuenta de otro. Un dato bancario no lo tipea un modelo.
     *
     * Devuelve '' si no hay nada que agregar: sin datos, o porque el modelo
     * YA los puso (se compara el CBU por sus digitos, que el modelo lo pudo
     * escribir con puntos o espacios, y el alias sin distinguir mayusculas).
     *
     * $pago: ['monto','alias','cbu','titular','vence_min'].
     */
    function chatbot_bloque_pago(array $pago, string $textoModelo): string
    {
        $alias = trim((string)($pago['alias'] ?? ''));
        $cbu   = trim((string)($pago['cbu'] ?? ''));
        if ($alias === '' && $cbu === '') {
            return '';
        }
        $digitos = static function ($s) { return (string)preg_replace('/\D+/', '', (string)$s); };
        $cbuDig  = $digitos($cbu);
        if (($cbuDig !== '' && strpos($digitos($textoModelo), $cbuDig) !== false)
            || ($alias !== '' && stripos($textoModelo, $alias) !== false)) {
            return '';   // el modelo ya lo dijo, no duplicar
        }

        $lineas = [];
        $monto = (string)($pago['monto'] ?? '');
        if ($monto !== '') {
            // El monto que pidio, tal cual. Se muestra igual aunque sea
            // redondo: el jugador tiene que transferir ESE importe para que
            // el pago se reconozca por monto.
            $lineas[] = 'Monto exacto: $' . number_format((float)$monto, 2, ',', '.');
        }
        if ($alias !== '') { $lineas[] = 'Alias: ' . $alias; }
        if ($cbu   !== '') { $lineas[] = 'CBU/CVU: ' . $cbu; }
        $tit = trim((string)($pago['titular'] ?? ''));
        if ($tit !== '') { $lineas[] = 'Titular: ' . $tit; }
        $vence = (int)($pago['vence_min'] ?? 0);
        if ($vence > 0) { $lineas[] = 'Vence en ' . $vence . ' minutos.'; }

        return $lineas ? implode("\n", $lineas) : '';
    }
}

if (!function_exists('chatbot_bloque_limites')) {
    /**
     * Los limites del negocio, contados en castellano para el modelo.
     *
     * Se GENERA desde los mismos numeros que aplica el codigo
     * (fichas_limite() en fichas_lib.php), nunca se escribe a mano. Si el
     * operador tuviera que escribirlos aparte, tarde o temprano el texto y lo
     * que aplica el sistema dirian cosas distintas -- y el bot le prometeria
     * al jugador algo que despues se le rechaza.
     *
     * $lim: ['carga_min','carga_max','retiro_min','retiro_max_dia'].
     * Un limite en 0 se omite: significa "sin tope".
     */
    function chatbot_bloque_limites(array $lim): string
    {
        $n = static fn($v) => number_format((int)$v, 0, ',', '.');
        $lineas = [];

        $cMin = (int)($lim['carga_min'] ?? 0);
        $cMax = (int)($lim['carga_max'] ?? 0);
        if ($cMin > 0) { $lineas[] = "- Carga MINIMA: {$n($cMin)} fichas. Por debajo de eso no se puede."; }
        if ($cMax > 0) { $lineas[] = "- Carga MAXIMA por operacion: {$n($cMax)} fichas."; }

        $rMin = (int)($lim['retiro_min'] ?? 0);
        $rDia = (int)($lim['retiro_max_dia'] ?? 0);
        if ($rMin > 0) { $lineas[] = "- Retiro MINIMO: {$n($rMin)} fichas."; }
        if ($rDia > 0) { $lineas[] = "- Tope de retiro POR DIA: {$n($rDia)} fichas en total."; }

        if (!$lineas) {
            return '';
        }
        return "LIMITES DE ESTE CASINO (los aplica el sistema, no son negociables):\n"
             . implode("\n", $lineas)
             . "\n- Si el jugador pide algo fuera de estos limites, deciselo con el numero"
             . "\n  concreto ANTES de intentar la operacion. No lo hagas pasar por un"
             . "\n  rechazo que ya sabias que iba a venir."
             . "\n- Nunca ofrezcas una excepcion ni digas que 'lo consultas': si necesita"
             . "\n  algo distinto, lo ve un agente.";
    }
}

if (!function_exists('chatbot_armar_prompt')) {
    /**
     * Arma el system prompt final combinando los CAMPOS del agente (o sus
     * defaults) con las REGLAS FIJAS. $campos: ['bot_nombre','bot_tono',
     * 'juego_desc','reglas_extra'] — cualquiera vacío usa su default.
     */
    function chatbot_armar_prompt(array $campos, array $limites = []): string
    {
        $nombre = trim((string)($campos['bot_nombre'] ?? '')) ?: CB_DEF_NOMBRE;
        $tono   = trim((string)($campos['bot_tono'] ?? '')) ?: CB_DEF_TONO;
        $juego  = trim((string)($campos['juego_desc'] ?? '')) ?: CB_DEF_JUEGO;
        $extra  = trim((string)($campos['reglas_extra'] ?? ''));

        /* EL ORDEN NO ES CASUAL Y NO SE CAMBIA.
           Las REGLAS FIJAS van ULTIMAS, despues de lo que escribio el
           operador. En estos modelos lo que va mas abajo pesa mas, y antes
           era al reves: las indicaciones del operador quedaban al final y le
           ganaban al procedimiento.
           Eso rompio el cobro en produccion: alguien escribio "cargame fichas
           -> cargaselo directo" en ese campo y el bot empezo a decirle a los
           jugadores "listo, te cargo 200 fichas" sin haber cobrado nada.
           Con este orden, lo que se escriba ahi puede sumar informacion pero
           no puede cambiar como se cobra. */
        $p  = "Sos {$nombre}, del equipo de atención al cliente. Ayudás a los "
            . "jugadores con dudas y con la carga de fichas.\n\n";
        $p .= "TU TONO:\n{$tono}\n\n";
        $p .= "SOBRE EL JUEGO:\n{$juego}\n\n";
        if ($extra !== '') {
            $p .= "INFORMACIÓN QUE SUMÓ EL OPERADOR (promos, horarios, avisos):\n"
                . "{$extra}\n\n";
        }
        $bloqueLim = chatbot_bloque_limites($limites);
        if ($bloqueLim !== '') {
            $p .= $bloqueLim . "\n\n";
        }
        $p .= CB_REGLAS_FIJAS;
        return $p;
    }
}

// Compatibilidad hacia atrás: algún código viejo todavía referencia CONTEXTO
// como el prompt completo por defecto. Lo dejamos definido con el prompt
// armado a partir de los defaults, así nada que lo use se rompe.
if (!defined('CONTEXTO')) {
    define('CONTEXTO', chatbot_armar_prompt([]));
}
