<?php
/**
 * Proxy del chatbot -> Cohere, CON herramientas (tool use).
 *
 * El bot no solo conversa: puede EJECUTAR acciones contra nuestra API.
 * Hoy tiene dos herramientas:
 *   - crear_recarga(usuario, coins)      crea el pedido y da el monto a transferir
 *   - consultar_recarga(referencia_o_usuario)  dice en que estado esta
 *
 * La key vive en el server (nunca en el HTML). El navegador le pega a este
 * archivo y este habla con Cohere.
 *
 * POST { "mensajes": [ {"role":"user","content":"..."} ] } -> { ok, respuesta }
 *
 * CONFIG:
 *   1) COHERE_API_KEY en api/config.local.php
 *   2) El contexto del juego -> constante CONTEXTO de aca abajo
 *   3) Datos de la cuenta a transferir -> en recargas_lib.php (RL_ALIAS, etc.)
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recargas_lib.php';
require __DIR__ . '/fichas_lib.php';
// El CRM es opcional: si crm_lib.php no esta subido, el chat sigue funcionando.
$crmLib = __DIR__ . '/crm_lib.php';
if (is_file($crmLib)) { require_once $crmLib; }
// auth_lib para verificar el token del login (opcional).
$authLib = __DIR__ . '/auth_lib.php';
if (is_file($authLib)) { require_once $authLib; }
// Notificaciones: avisarle al que ya cerro la app que le contestamos (opcional).
$notifLib = __DIR__ . '/notificaciones_lib.php';
if (is_file($notifLib)) { require_once $notifLib; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

// =====================  EDITA ESTO LIBREMENTE  =============================
const CONTEXTO = <<<TXT
Sos CAMILA, del equipo de atención al cliente de ganamos, un videojuego de
guerra de drones incrementales. Ayudás a los jugadores con dudas y con la CARGA
DE FICHAS. Las "fichas" son la moneda del juego. Ademas existen "bonos" (fichas
de regalo).

TU TONO: argentino, PROFESIONAL, serio y educado. Hablás de "vos", con respeto y
calidez, sin exagerar. Nada de jerga de más, ni mayúsculas gritadas, ni una
catarata de emojis (como mucho uno, y no siempre). La PRIMERA vez que hablás con
alguien presentate ("Hola, soy Camila del equipo de atención"), pero NO repitas
tu nombre en cada mensaje.

--- REEMPLAZA CON LA INFO REAL DE TU JUEGO ---
- De que trata el juego, como se juega, para que sirven las fichas y los bonos.
----------------------------------------------

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

CARGAR FICHAS:
Cuando diga "cargame 500 fichas", "quiero cargar 1000" o similar CON una
cantidad, llama a cargar_al_juego con esa cantidad.
- NO le pidas que transfiera nada. NO uses crear_recarga para esto.
- Lo unico que necesitas es la CANTIDAD. Si no la dijo, preguntasela. Nada mas.
- NUNCA le pidas el nombre de usuario para cargar: el server ya sabe quien es.
- Si devuelve 'sin_fichas': recien AHI ofrecele comprar (ver mas abajo).
- Si devuelve 'en_curso': ya tiene una carga en camino, que espere a que llegue.
- Si devuelve 'sin_sesion': pedile que inicie sesion en la pagina.
- Cuando sale bien, la carga NO es instantanea: decile que en un ratito la ve en
  su saldo. Nunca digas que ya esta acreditada.

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

COMPRAR FICHAS POR TRANSFERENCIA:
Esto es SOLO para cuando el jugador pide expresamente comprar/recargar con
plata, o cuando cargar_al_juego devolvio 'sin_fichas'. Si no estas en uno de
esos dos casos, no lo menciones.
1. Necesitas DOS datos: el nombre de usuario del juego y cuantas fichas quiere.
   Si falta alguno, pedilo. No inventes ninguno.
2. Cuando tengas los dos, llama a crear_recarga (el parametro se llama 'coins'
   pero para el usuario son "fichas").
3. Con lo que devuelve, decile que transfiera EXACTAMENTE el 'monto_pedido'
   (insisti en que respete los centavos, es lo que identifica su pago) al
   alias/CBU y titular indicados. Avisale que vence en 'vence_min' minutos y que
   las fichas se acreditan SOLAS cuando llega la transferencia.
4. Si crear_recarga devuelve codigo 'sin_usuario', decile que primero se
   registre en el juego (con el boton de acceso) y despues vuelva.
5. Si pregunta si ya llego su pago o en que estado esta, usa consultar_recarga.
   Solo digas que se acreditaron las fichas si el estado es 'acreditada'.

Reglas de estilo:
- Respondé en español rioplatense, breve, claro y amable, pero SIEMPRE profesional.
- Sos Camila: seria, educada y cordial. Sin jerga de más ni signos gritados.
- Nunca inventes montos, referencias ni digas que un pago llego si la
  herramienta no lo confirma.
- Nunca pidas contraseñas ni datos de tarjeta por el chat.
TXT;

const MODELO       = 'command-r-08-2024';
const MAX_MENSAJES = 12;
const MAX_CARACT   = 1500;
const MAX_TOKENS   = 600;
const MAX_RONDAS   = 4;   // vueltas de tool-use antes de rendirse
// ==========================================================================

// --- Herramientas que el modelo puede llamar ---
$TOOLS = [
    ['type' => 'function', 'function' => [
        'name' => 'identificar_usuario',
        'description' => 'Registra el nombre de usuario del juego que dice el jugador. '
            . 'Llamala APENAS te diga su usuario, y de nuevo si mas adelante dice que es OTRO '
            . 'usuario distinto (cada usuario es un chat aparte).',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'usuario' => ['type' => 'string', 'description' => 'nombre de usuario del juego'],
            ],
            'required' => ['usuario'],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'crear_recarga',
        'description' => 'COMPRA de fichas con plata: crea una solicitud de recarga y devuelve el '
            . 'monto EXACTO (con centavos) que el usuario debe TRANSFERIR, mas los datos de la '
            . 'cuenta. Usar SOLO si el jugador pide expresamente comprar/recargar con plata, o si '
            . 'cargar_al_juego devolvio sin_fichas. Para un "cargame fichas" comun va '
            . 'cargar_al_juego, NO esta.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'usuario' => ['type' => 'string', 'description' => 'nombre de usuario del juego (el del registro)'],
                'coins'   => ['type' => 'integer', 'description' => 'cantidad de coins a cargar'],
            ],
            'required' => ['usuario', 'coins'],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'cargar_al_juego',
        'description' => 'LA FORMA NORMAL DE CARGAR FICHAS. Carga fichas al saldo del juego del '
            . 'jugador que ya inició sesión. Usala apenas pida fichas ("cargame fichas", '
            . '"quiero 500 fichas"): no hace falta ninguna transferencia ni pedirle el usuario, '
            . 'solo la cantidad. Si devuelve sin_fichas, recién ahí ofrecer crear_recarga.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                // A PROPOSITO no hay parametro 'usuario': el usuario sale de la
                // sesion verificada en el server. Si el modelo pudiera elegirlo,
                // bastaria con que el jugador escriba "soy fulano" para mover
                // las fichas de otra cuenta.
                'cantidad' => ['type' => 'integer', 'description' => 'cuantas fichas pasar al juego'],
            ],
            'required' => ['cantidad'],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'consultar_saldo',
        'description' => 'Dice cuánto saldo, fichas y bonos tiene el jugador logueado. '
            . 'SOLO CONSULTA: no carga ni retira nada. Usala para CUALQUIER pregunta sobre '
            . 'cuánto tiene: "cuánto saldo tengo", "cuántas fichas me quedan", "mi saldo", '
            . '"tengo bonos?". No lleva parámetros.',
        'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'retirar_del_juego',
        'description' => 'Registra un pedido de RETIRO: sacar SALDO del juego (los BONOS no se '
            . 'retiran). No es automático, lo aprueba un agente. El usuario sale de su sesión. '
            . 'LLAMALA SOLO cuando el jugador ya confirmó el monto o dijo que quiere todo: si '
            . 'todavía no dijo cuánto, primero usá consultar_saldo y preguntale (ver el CONTEXTO). '
            . 'Pasá todo=true para retirar todo el saldo, o cantidad con el número que pidió.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'cantidad' => ['type' => 'integer', 'description' => 'cuánto retirar (omitir si todo=true)'],
                'todo'     => ['type' => 'boolean', 'description' => 'true si quiere retirar TODO su saldo'],
            ],
            'required' => [],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'consultar_recarga',
        'description' => 'Consulta el estado de una recarga por su referencia (codigo corto) o '
            . 'por el nombre de usuario del juego. Usar cuando preguntan si llego el pago.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'referencia_o_usuario' => ['type' => 'string', 'description' => 'la referencia o el nombre de usuario'],
            ],
            'required' => ['referencia_o_usuario'],
        ],
    ]],
];

// --- La key vive en el server ---
$key = cfg('COHERE_API_KEY');
if ($key === '' || strlen($key) < 20) {
    http_response_code(500);
    error_log('chatbot: falta COHERE_API_KEY en api/config.local.php');
    echo json_encode(['ok' => false, 'error' => 'El chatbot no esta configurado']);
    exit;
}

if (!limite_por_ip(20, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Vas muy rapido, espera un momento.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$historial = (isset($body['mensajes']) && is_array($body['mensajes'])) ? $body['mensajes'] : [];
$sessionId = trim((string)($body['session_id'] ?? ''));
// Usuario ya logueado en la plataforma. Si viene un token JWT valido, ese usuario
// MANDA (login real verificado en el server); si no, caemos al 'usuario' suelto.
$usuarioCliente = mb_substr(trim((string)($body['usuario'] ?? '')), 0, 50);
$tokenCli = (string)($body['token'] ?? '');
// OJO: sin token, $usuarioCliente es lo que mando el navegador (el widget lo
// lee del header de la plataforma). Sirve para conversar, pero NO es prueba de
// nada: cualquiera puede mandar el nombre que quiera. Por eso se lleva aparte
// si el token se verifico de verdad, y las herramientas que mueven plata
// pueden exigirlo (ver fichas_exige_token()).
$sesionVerificada = false;
if ($tokenCli !== '' && function_exists('jwt_verificar')) {
    $claims = jwt_verificar($tokenCli, cfg('JWT_SECRET'));
    if ($claims && !empty($claims['username'])) {
        $usuarioCliente   = mb_substr((string)$claims['username'], 0, 50);
        $sesionVerificada = true;
    }
}

// La identidad va AL FINAL y en dos versiones excluyentes. Antes convivian dos
// instrucciones contradictorias -"pedile siempre el usuario" arriba y "ya sabes
// quien es" abajo- y el modelo obedecia las dos: saludaba por el nombre y acto
// seguido preguntaba como se llamaba.
$sys = CONTEXTO;
if ($usuarioCliente !== '') {
    $sys .= "\n\nIDENTIDAD (esto manda sobre todo lo anterior):\n"
          . "El jugador con el que estas hablando es '" . $usuarioCliente . "'. "
          . "Ya inicio sesion y el server lo tiene confirmado.\n"
          . "- NO le pidas el nombre de usuario. Nunca. Ya lo sabes.\n"
          . "- NO llames a identificar_usuario.\n"
          . "- Saludalo por su nombre y pasa directo a lo que necesite.\n"
          . "- Usa ese usuario para las recargas y las consultas.";
} else {
    $sys .= "\n\nIDENTIDAD:\n"
          . "Todavia no sabes con quien estas hablando.\n"
          . "- Saluda y pedile amablemente su nombre de usuario del juego.\n"
          . "- Apenas te lo diga, llama a identificar_usuario con ese nombre y no\n"
          . "  se lo vuelvas a pedir.\n"
          . "- Si mas adelante dice ser OTRO usuario, volve a llamar a\n"
          . "  identificar_usuario con el nuevo (cada usuario es un chat aparte).";
}
$mensajes = [['role' => 'system', 'content' => $sys]];
foreach (array_slice($historial, -MAX_MENSAJES) as $m) {
    $role    = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $content = trim(mb_substr((string)($m['content'] ?? ''), 0, MAX_CARACT));
    if ($content !== '') {
        $mensajes[] = ['role' => $role, 'content' => $content];
    }
}
if (count($mensajes) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No hay mensaje']);
    exit;
}

// --- Como hablar con Cohere y como ejecutar cada herramienta ---
$llamarCohere = function (array $mensajes) use ($key, $TOOLS): array {
    return cohere_chat($key, $mensajes, $TOOLS);
};
// Vinculamos la conversacion al jugador: arrancamos con el usuario logueado
// (del token) y lo pisamos si el bot llama una herramienta con otro 'usuario'.
$usuarioDetectado = $usuarioCliente !== '' ? $usuarioCliente : null;
// Si en este turno se ENCOLA una carga, guardamos su id para que el front pueda
// narrar el proceso (sondea carga_estado.php hasta que el bot la deposita).
$cargaInfo = null;
$ejecutarTool = function (string $nombre, array $args) use ($pdo, &$usuarioDetectado, $usuarioCliente, $sesionVerificada, &$cargaInfo): array {
    if (!empty($args['usuario'])) { $usuarioDetectado = (string)$args['usuario']; }
    // $usuarioCliente sale de la sesion (token o header), NUNCA de lo que el
    // modelo haya sacado de la charla: si el jugador escribe "soy fulano", eso
    // llega en $args y para las fichas no se mira.
    $res = ejecutar_tool($pdo, $nombre, $args, $usuarioCliente, $sesionVerificada);
    if ($nombre === 'cargar_al_juego' && !empty($res['ok']) && !empty($res['id'])) {
        $cargaInfo = ['id' => (int)$res['id'], 'monto' => (int)($res['monto'] ?? 0)];
    }
    return $res;
};

try {
    $texto = procesar_chat($mensajes, $llamarCohere, $ejecutarTool, MAX_RONDAS);
} catch (Throwable $e) {
    error_log('chatbot: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Guardar el turno en el CRM (si falla, no afecta la respuesta al usuario).
$ultimoUser = '';
for ($i = count($mensajes) - 1; $i >= 0; $i--) {
    if (($mensajes[$i]['role'] ?? '') === 'user' && !empty($mensajes[$i]['content'])) {
        $ultimoUser = (string)$mensajes[$i]['content'];
        break;
    }
}
if (function_exists('crm_registrar_turno')) {
    crm_registrar_turno($pdo, $sessionId, $ultimoUser, $texto, $usuarioDetectado);
}

/* Aviso de "te contestamos". Va SIEMPRE, pero marcado como solo_app: si el
   jugador sigue en la app, el widget lo consume sin mostrarlo (ya esta leyendo
   la respuesta). Si cerro antes de leerla, se la muestra el worker en la barra.
   Solo sirve con usuario: a un chat anonimo no hay a quien notificarle. */
if (function_exists('notif_chat')) {
    notif_chat($pdo, $usuarioDetectado, $texto, false);
}

$salida = ['ok' => true, 'respuesta' => $texto];
if ($cargaInfo) { $salida['carga'] = $cargaInfo; }
echo json_encode($salida, JSON_UNESCAPED_UNICODE);


// ===========================================================================
//  NUCLEO (testeable: no toca red ni DB por si mismo, recibe closures)
// ===========================================================================

/**
 * Corre la conversacion resolviendo las herramientas que pida el modelo.
 * $llamarCohere(array $mensajes): array  -> ['http'=>int,'data'=>array,'raw'=>string]
 * $ejecutarTool(string $nombre, array $args): array
 * Devuelve el texto final. Lanza excepcion con mensaje util si Cohere falla.
 */
/**
 * Deja el resultado de una herramienta como lo quiere Cohere.
 *
 * Cohere v2 convierte cada tool result en un DOCUMENTO, y en un documento el
 * campo `id` tiene que ser un string. Si una herramienta devuelve
 * ['id' => 123] (un autoincrement, por ejemplo), la API contesta
 *     "a tool result outputs id field must be a string"
 * y el chat se cae entero, aunque la accion en la base haya salido perfecta.
 *
 * Se arregla aca y no en cada herramienta: cualquier tool que devuelva un id
 * numerico -hoy o el año que viene- pasa por este colador.
 */
function tool_salida_limpia($v, int $prof = 0)
{
    if (!is_array($v) || $prof > 5) {
        return $v;
    }
    $limpio = [];
    foreach ($v as $k => $item) {
        if ($k === 'id' && (is_int($item) || is_float($item))) {
            $item = (string)$item;
        } elseif (is_array($item)) {
            $item = tool_salida_limpia($item, $prof + 1);
        }
        $limpio[$k] = $item;
    }
    return $limpio;
}

function procesar_chat(array $mensajes, callable $llamarCohere, callable $ejecutarTool, int $maxRondas): string
{
    for ($ronda = 0; $ronda < $maxRondas; $ronda++) {
        $r = $llamarCohere($mensajes);
        if (($r['http'] ?? 0) !== 200) {
            $data = $r['data'] ?? [];
            $detalle = is_string($data['message'] ?? null) ? $data['message'] : ('HTTP ' . ($r['http'] ?? '?'));
            throw new RuntimeException('Cohere: ' . $detalle);
        }
        $msg = $r['data']['message'] ?? [];
        $toolCalls = $msg['tool_calls'] ?? [];

        if (!$toolCalls) {
            return cohere_texto($msg);
        }

        // Eco del turno del asistente (Cohere exige devolverle sus tool_calls)
        $mensajes[] = [
            'role'       => 'assistant',
            'tool_calls' => $toolCalls,
            'tool_plan'  => $msg['tool_plan'] ?? '',
        ];

        // Ejecutar cada herramienta y devolver el resultado
        foreach ($toolCalls as $tc) {
            $nombre = $tc['function']['name'] ?? '';
            $args   = json_decode($tc['function']['arguments'] ?? '{}', true);
            if (!is_array($args)) { $args = []; }
            $salida = $ejecutarTool($nombre, $args);
            $mensajes[] = [
                'role'         => 'tool',
                'tool_call_id' => (string)($tc['id'] ?? ''),
                'content'      => json_encode(tool_salida_limpia($salida), JSON_UNESCAPED_UNICODE),
            ];
        }
    }
    // Demasiadas vueltas sin respuesta final
    return 'Estoy teniendo un problema para completar tu pedido. ¿Podés repetirlo?';
}

/** Extrae el texto de un message de Cohere v2 (content = array de bloques). */
function cohere_texto(array $msg): string
{
    $texto = '';
    foreach (($msg['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text' && isset($b['text'])) {
            $texto .= $b['text'];
        }
    }
    return trim($texto) !== '' ? trim($texto) : 'No pude generar una respuesta, intenta de nuevo.';
}

/**
 * Despacha la herramienta pedida.
 *
 * $usuarioVerificado sale del JWT que valido el server, NO de la charla. Las
 * herramientas que mueven plata usan ese y solo ese: lo que el modelo pasa en
 * $args viene, en ultima instancia, de lo que el jugador escribio.
 */
function ejecutar_tool(PDO $pdo, string $nombre, array $args, string $usuarioSesion = '', bool $sesionVerificada = false): array
{
    if (function_exists('gp_trace')) { gp_trace("señal: tool=$nombre usuario_sesion='$usuarioSesion' verif=" . (int)$sesionVerificada . " args=" . json_encode($args, JSON_UNESCAPED_UNICODE)); }  // TRACE TEMPORAL

    if ($nombre === 'cargar_al_juego') {
        if ($usuarioSesion === '') {
            return ['ok' => false, 'codigo' => 'sin_sesion',
                    'error' => 'Para cargar fichas al juego tenés que iniciar sesión primero.'];
        }
        if (fichas_exige_token() && !$sesionVerificada) {
            return ['ok' => false, 'codigo' => 'sin_sesion',
                    'error' => 'Necesito que inicies sesión en la página para poder cargarte fichas.'];
        }
        $r = fichas_pedir_carga($pdo, $usuarioSesion, (int)($args['cantidad'] ?? 0), 'chatbot');
        if (function_exists('gp_trace')) { gp_trace("carga: resultado -> " . json_encode($r, JSON_UNESCAPED_UNICODE)); }  // TRACE TEMPORAL
        return $r;
    }

    if ($nombre === 'consultar_saldo') {
        return fichas_consultar($pdo, $usuarioSesion);
    }

    if ($nombre === 'retirar_del_juego') {
        if ($usuarioSesion === '') {
            return ['ok' => false, 'codigo' => 'sin_sesion',
                    'error' => 'Para retirar tenés que iniciar sesión primero.'];
        }
        if (fichas_exige_token() && !$sesionVerificada) {
            return ['ok' => false, 'codigo' => 'sin_sesion',
                    'error' => 'Necesito que inicies sesión en la página para registrar el retiro.'];
        }
        $todo = !empty($args['todo']);
        return fichas_pedir_retiro($pdo, $usuarioSesion, (int)($args['cantidad'] ?? 0), 'chatbot', $todo);
    }
    if ($nombre === 'identificar_usuario') {
        $u = trim((string)($args['usuario'] ?? ''));
        if ($u === '') { return ['ok' => false, 'error' => 'falta usuario']; }
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$u]);
        return ['ok' => true, 'usuario' => $u, 'existe' => (bool)$st->fetchColumn()];
    }
    if ($nombre === 'crear_recarga') {
        return rl_crear_recarga($pdo, (string)($args['usuario'] ?? ''), (int)($args['coins'] ?? 0));
    }
    if ($nombre === 'consultar_recarga') {
        $ref = (string)($args['referencia_o_usuario'] ?? $args['referencia'] ?? $args['usuario'] ?? '');
        return rl_consultar($pdo, $ref);
    }
    return ['ok' => false, 'error' => 'herramienta desconocida'];
}


// ===========================================================================
//  Infra: llamada HTTP a Cohere y limite por IP
// ===========================================================================

/** POST a Cohere v2 /chat. Devuelve ['http'=>int,'data'=>array,'raw'=>string]. */
function cohere_chat(string $key, array $mensajes, array $tools): array
{
    $payload = json_encode([
        'model'       => MODELO,
        'messages'    => $mensajes,
        'tools'       => $tools,
        'temperature' => 0.3,
        'max_tokens'  => MAX_TOKENS,
    ]);

    $ch = curl_init('https://api.cohere.com/v2/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 40,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('No se pudo contactar a Cohere: ' . $err);
    }
    $data = json_decode($raw, true);
    return ['http' => $http, 'data' => is_array($data) ? $data : [], 'raw' => $raw];
}

/** Limite de tasa por IP con archivo temporal. */
function limite_por_ip($max, $ventanaSeg)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $f  = sys_get_temp_dir() . '/chatbot_rl_' . md5($ip);
    $ahora = time();
    $hits = array();
    if (is_file($f)) {
        foreach (explode(',', (string)@file_get_contents($f)) as $t) {
            if ($t !== '' && (int)$t > $ahora - $ventanaSeg) {
                $hits[] = (int)$t;
            }
        }
    }
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $ahora;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return true;
}
