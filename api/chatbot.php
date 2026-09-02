<?php
/**
 * Proxy del chatbot -> Qwen, CON herramientas (tool use).
 *
 * El bot no solo conversa: puede EJECUTAR acciones contra nuestra API
 * (identificar al jugador, crear una recarga, cargar fichas, pedir un retiro,
 * consultar saldo, crear una cuenta). Cada una esta declarada en $TOOLS.
 *
 * La key vive en el server (nunca en el HTML). El navegador le pega a este
 * archivo y este habla con el modelo.
 *
 * POST { "mensajes": [ {"role":"user","content":"..."} ] } -> { ok, respuesta }
 *
 * QUE MODELO Y POR QUE
 * Se uso Cohere (command-r-08-2024) hasta agosto 2026 y se cambio porque se
 * le perdia el hilo en dialogos con ramas -- el caso testigo: preguntaba "¿ya
 * tenes cuenta?", el jugador respondia "si" y contestaba "ok, te creo una".
 *
 * Ahora va Qwen por su modo COMPATIBLE CON OPENAI, que es otro formato de
 * mensajes: la respuesta viene en choices[0].message (Cohere la ponia en
 * message) y los errores en error.message (Cohere, en message). Si algun dia
 * se vuelve a cambiar de proveedor, eso es lo que hay que mirar -- esta todo
 * en ia_chat(), ia_texto() y procesar_chat().
 *
 * CONFIG (config.local.php o entorno):
 *   1) QWEN_API_KEY   la clave. Obligatoria.
 *   2) QWEN_BASE_URL  opcional, default en QWEN_BASE_DEF.
 *   3) QWEN_MODEL     opcional, default en QWEN_MODEL_DEF.
 *   4) El contexto del juego -> chatbot_contexto.php (editable desde el CRM)
 *   5) Datos de la cuenta a transferir -> los trae recargas_lib.php desde el
 *      panel de ganamos (ver rl_banco_panel).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
/* TODOS con require_once, y no es cosmetico: recargas_lib.php carga por su
   cuenta varias de estas libs (fichas_lib, meta_lib, publicidad_lib...). Un
   `require` pelado despues de el las carga por SEGUNDA vez y PHP muere con
   "Cannot redeclare function" -- el chat entero deja de responder.
   Ya paso dos veces en este archivo: primero con meta_lib.php y despues con
   fichas_lib.php. Si sumas una lib aca, que sea require_once siempre. */
require_once __DIR__ . '/recargas_lib.php';
require_once __DIR__ . '/fichas_lib.php';
require_once __DIR__ . '/altas_lib.php';
require_once __DIR__ . '/config_crm.php';
// require_once: recargas_lib.php (arriba) ya lo carga solo si esta -- un
// require simple aca redeclararia las funciones y tiraria fatal error.
require_once __DIR__ . '/meta_lib.php';
require_once __DIR__ . '/publicidad_lib.php';
// Avisos al agente. require_once como todo lo demas: recargas_lib carga varias
// de estas por su cuenta y un require pelado tira "Cannot redeclare", que ya
// dejo el chat muerto en produccion dos veces.
require_once __DIR__ . '/crm_lib.php';
require_once __DIR__ . '/telegram_lib.php';
// El CRM es opcional: si crm_lib.php no esta subido, el chat sigue funcionando.
$crmLib = __DIR__ . '/crm_lib.php';
if (is_file($crmLib)) { require_once $crmLib; }
// auth_lib para verificar el token del login (opcional).
$authLib = __DIR__ . '/auth_lib.php';
if (is_file($authLib)) { require_once $authLib; }
// Notificaciones: avisarle al que ya cerro la app que le contestamos (opcional).
$notifLib = __DIR__ . '/notificaciones_lib.php';
if (is_file($notifLib)) { require_once $notifLib; }
// Vision: leer la foto del comprobante con IA (opcional). Sin el archivo o
// sin ANTHROPIC_API_KEY, el bot pide los datos por texto y todo sigue igual.
$visionLib = __DIR__ . '/vision_lib.php';
if (is_file($visionLib)) { require_once $visionLib; }
// Telegram: avisarle al agente cuando el bot deriva una conversacion (opcional).
// Sin el archivo o sin credenciales, la derivacion igual queda marcada en el CRM.
$tgLib = __DIR__ . '/telegram_lib.php';
if (is_file($tgLib)) { require_once $tgLib; }

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

// El system prompt por defecto (constante CONTEXTO) vive en un archivo aparte
// para compartirlo con crm.php sin ejecutar este endpoint. El contexto VIVO,
// editable desde el CRM, se guarda en la tabla config_chatbot y se lee más
// abajo (chatbot_config); si está vacío, se cae a esta constante.
require __DIR__ . '/chatbot_contexto.php';

/* Qwen (Alibaba Model Studio) por su modo COMPATIBLE CON OPENAI. Se dejo
   Cohere (command-r-08-2024) porque se le perdia el hilo en dialogos con
   ramas: preguntaba "¿ya tenes cuenta?", el jugador decia "si" y contestaba
   "ok, te creo una".

   Los tres valores se pueden pisar desde config.local.php o el entorno, sin
   tocar codigo: si un modelo resulta flojo con las herramientas, se cambia
   QWEN_MODEL y listo. qwen-vl-max ademas VE imagenes, que es lo que permite
   analizar comprobantes (ver comprobante_leer.php).

   OJO si cambias de modelo: el chatbot depende de tool calling. Un modelo
   que no lo soporte deja de poder cargar fichas o crear recargas -- va a
   conversar igual, que es peor, porque parece que anda. */
const QWEN_BASE_DEF  = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
const QWEN_MODEL_DEF = 'qwen-vl-max';
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
        'description' => 'COMPRA de fichas con plata: crea una solicitud de recarga. Si la '
            . 'respuesta trae link_pago, ESE LINK es la forma de pagar: pasaselo al jugador '
            . '(paga ahi con transferencia y se acredita solo). Si no hay link_pago, dale el '
            . 'monto EXACTO a transferir y los datos de la cuenta. Usar SOLO si '
            . 'el jugador pide expresamente comprar/recargar con plata, o si cargar_al_juego '
            . 'devolvio sin_fichas. Para un "cargame fichas" comun va cargar_al_juego, NO esta.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'usuario' => ['type' => 'string', 'description' => 'nombre de usuario del juego (el del registro)'],
                'coins'   => ['type' => 'integer', 'description' => 'cantidad de coins a cargar'],
                'titular' => ['type' => 'string', 'description' =>
                    'OPCIONAL. Nombre y apellido del titular de la cuenta desde la que va a '
                    . 'transferir, SOLO si el jugador ya lo dijo. NO lo preguntes antes de crear '
                    . 'la recarga: primero dale los datos para transferir, que es lo que vino a '
                    . 'buscar. NUNCA lo inventes ni lo deduzcas del nombre de usuario: si no lo '
                    . 'dijo, mandalo vacio. Si esta herramienta devuelve el codigo '
                    . '"falta_titular", ahi si preguntaselo y volve a llamarla con ese dato.'],
            ],
            // titular NO es obligatorio: pedirlo antes de dar el alias es
            // friccion que el empleado humano no hace ("¿me cargas?" -> "te
            // paso el alias"). Sin el, el pago se identifica igual por los
            // centavos unicos; el titular es el desempate para cuando dos
            // jugadores piden el mismo monto a la vez.
            'required' => ['usuario', 'coins'],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'cargar_al_juego',
        'description' => 'CASI NUNCA SE USA. Solo sirve si a un jugador le quedo saldo suelto '
            . 'comprado de antes, cosa que ya no pasa: hoy la transferencia acreditada carga '
            . 'las fichas sola. Cuando alguien dice "cargame fichas" NO es esto: quiere '
            . 'transferir, y va crear_recarga. Usala unicamente si el jugador dice que le quedo '
            . 'saldo sin cargar y quiere pasarlo al juego.',
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
                'cbu_o_alias' => ['type' => 'string', 'description'
                    => 'CBU/CVU de 22 dígitos o alias bancario donde quiere recibir la plata. '
                     . 'Pedíselo si no lo dio; si la respuesta trae falta_destino=true, volvé a pedirlo.'],
            ],
            'required' => [],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'crear_cuenta',
        'description' => 'Crea una cuenta NUEVA en la plataforma para alguien que TODAVIA NO TIENE. '
            . 'Usala SOLO si el jugador dijo EXPLICITAMENTE que no tiene cuenta / es nuevo / quiere '
            . 'registrarse. Si en cambio dijo que YA TIENE cuenta, NO llames a esta herramienta bajo '
            . 'ningun motivo: pedile el nombre de usuario para identificarlo (identificar_usuario). '
            . 'Lo unico que hay que pedirle para crear la cuenta es el NOMBRE DE USUARIO que quiere: '
            . 'la contrasena la genera el sistema y te la devuelve esta herramienta. NUNCA le pidas '
            . 'que el elija la contrasena ni que la escriba en el chat. Si el usuario ya esta '
            . 'ocupado, devuelve ocupado y hay que pedirle otro.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'usuario' => ['type' => 'string', 'description' => 'el nombre de usuario que quiere, 3 a 64 caracteres'],
            ],
            'required' => ['usuario'],
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
    /* Derivar a un humano. Es una HERRAMIENTA y no solo una frase del modelo a
       proposito: decir "ya se lo paso a un agente" y no dejar rastro en ningun
       lado es la peor combinacion posible -- el jugador se queda esperando algo
       que nadie sabe que tiene que atender.
       Al llamarla pasan dos cosas: la conversacion queda marcada en el CRM (se
       ordena primero) y le suena el Telegram al agente. */
    ['type' => 'function', 'function' => [
        'name' => 'pasar_a_agente',
        'description' => 'Deriva la conversacion a un agente humano y le avisa. Usar SIEMPRE que '
            . 'le digas al jugador que lo vas a pasar con una persona: por un reclamo de pago, '
            . 'porque no podes resolver algo, o porque el jugador lo pide.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'motivo' => ['type' => 'string',
                             'description' => 'en una linea, que necesita el jugador'],
            ],
            'required' => ['motivo'],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'verificar_comprobante',
        'description' => 'El jugador SUBIO la foto del comprobante de su transferencia al chat '
            . '(boton del clip). Lee esa imagen con IA, saca monto/titular/nro de operacion y '
            . 'los matchea con su recarga: si el pago real ya llego, la acredita al instante. '
            . 'Llamala apenas diga que subio/mando/adjunto el comprobante ("listo", "ahi va", '
            . '"te lo mande"). No le pidas los datos por texto si ya subio la foto.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'usuario' => ['type' => 'string', 'description'
                    => 'nombre de usuario del juego. Solo si NO inicio sesion; con sesion el server ya sabe quien es.'],
            ],
            'required' => [],
        ],
    ]],
    ['type' => 'function', 'function' => [
        'name' => 'informar_transferencia',
        'description' => 'El jugador dice que YA transfirio su recarga y pasa datos por TEXTO: '
            . 'el nombre del TITULAR de la cuenta desde la que transfirio y/o el numero de '
            . 'operacion/transaccion del comprobante. Registra esos datos y, si el pago real '
            . 'ya llego, acredita al instante. Llamala con lo que haya dado, aunque sea un '
            . 'solo dato. Si tiene el comprobante en imagen, mejor que lo suba al chat con el '
            . 'clip y usa verificar_comprobante.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'titular' => ['type' => 'string', 'description'
                    => 'nombre y apellido del titular de la cuenta DESDE la que transfirio'],
                'nro_transaccion' => ['type' => 'string', 'description'
                    => 'numero de operacion / transaccion / comprobante'],
                'usuario' => ['type' => 'string', 'description'
                    => 'nombre de usuario del juego. Solo si NO inicio sesion; con sesion el server ya sabe quien es.'],
            ],
            'required' => [],
        ],
    ]],
];

// --- La key vive en el server ---
// Se acepta COHERE_API_KEY como nombre viejo para no dejar el chat mudo entre
// que se despliega esto y alguien edita config.local.php. Cuando este cargada
// QWEN_API_KEY, la vieja se puede borrar.
$key = cfg('QWEN_API_KEY');
if ($key === '') { $key = cfg('COHERE_API_KEY'); }
if ($key === '' || strlen($key) < 20) {
    http_response_code(500);
    error_log('chatbot: falta QWEN_API_KEY en api/config.local.php');
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

// Config editable desde el CRM (tabla config_chatbot, migracion 26). Si la
// tabla no existe o el contexto esta vacio, se cae a la constante CONTEXTO de
// arriba, asi nada se rompe si no se corrio la migracion. `activo`=0 apaga la
// IA (el chat sigue, pero contesta un agente).
$cfgBot   = chatbot_config($pdo);
// Dos interruptores, y los dos tienen que estar prendidos: el del editor del
// chatbot (config_chatbot.activo) y el de Configuracion del CRM
// (config_crm.chat_activo). Son pantallas distintas para dos personas
// distintas -- el que edita el prompt y el que administra el sitio -- asi que
// ninguno pisa al otro: cualquiera de los dos apaga la IA.
$botActivo = $cfgBot['activo'] && cfg_crm_activo($pdo, 'chat_activo');
// El prompt son los dos contextos: el DINAMICO (nombre/tono/limites/info del
// operador, lo unico editable desde el CRM) y el FIJO (la dinamica del juego y
// la mecanica). Ver api/chatbot_contexto.php.
/* El `contexto` entero (modo viejo, migracion 26) ya no lo escribe nadie -- el
   CRM edita el contexto dinamico. Pero si quedo cargado en alguna base, antes
   se usaba como prompt COMPLETO y el contexto FIJO no se aplicaba: una forma
   silenciosa de desactivar el procedimiento de cobro y la seccion de juego
   responsable.

   Ahora se respeta lo que haya escrito, pero el contexto fijo se agrega al
   final igual. Mismo motivo que el orden de chatbot_armar_prompt(): lo ultimo
   pesa mas, asi que el procedimiento gana pase lo que pase. */
$contextoBase = ($cfgBot['contexto'] !== '')
    ? $cfgBot['contexto'] . "\n\n" . CB_CONTEXTO_FIJO
    // Los limites salen de la MISMA funcion que los aplica (fichas_limite),
    // asi el bot nunca le ofrece al jugador algo que el codigo va a rechazar.
    : chatbot_armar_prompt($cfgBot, [
        'carga_min'      => fichas_limite($pdo, 'lim_carga_min',  FICHAS_MIN_CARGA),
        'carga_max'      => fichas_limite($pdo, 'lim_carga_max',  FICHAS_MAX_CARGA),
        'retiro_min'     => fichas_limite($pdo, 'lim_retiro_min', FICHAS_MIN_CARGA),
        'retiro_max_dia' => fichas_limite($pdo, 'lim_retiro_max_dia', 0),
        // La franja horaria NO pasa por fichas_limite(): esa acepta 0 como
        // valor valido, y 0 es una hora legitima. Vacio = sin restriccion.
        'retiro_hora_desde' => (string)(cfg_crm($pdo, 'lim_retiro_hora_desde') ?? ''),
        'retiro_hora_hasta' => (string)(cfg_crm($pdo, 'lim_retiro_hora_hasta') ?? ''),
        // El link de la app sale de la config del cliente, no de las reglas
        // fijas: esas las comparten todos los casinos.
        'app_url'        => (string)(cfg_crm($pdo, 'app_url') ?? ''),
      ]);

// Chatbot DESACTIVADO: no se llama a Cohere. El mensaje del jugador igual
// queda en el CRM (para que lo vea y conteste un agente) y al jugador se le
// avisa que en breve lo atienden.
//
// Se apaga por DOS motivos: el switch GLOBAL (config_chatbot.activo) o el
// switch POR CHAT (conversaciones.ia_activa). El global manda: el por-chat
// solo puede APAGAR un chat puntual cuando el global está prendido.
$iaEsteChat = chatbot_ia_del_chat($pdo, $sessionId, $usuarioCliente);
if (!$botActivo || !$iaEsteChat) {
    $ultimoUser = '';
    for ($i = count($historial) - 1; $i >= 0; $i--) {
        if ((($historial[$i]['role'] ?? '') === 'user') && !empty($historial[$i]['content'])) {
            $ultimoUser = (string)$historial[$i]['content'];
            break;
        }
    }
    $aviso = 'En un momento te responde un agente. ¡Gracias por tu paciencia!';
    // Guardamos el turno con el aviso como "respuesta", asi el hilo del CRM
    // queda coherente y el agente ve el mensaje del jugador para contestarlo.
    if (function_exists('crm_registrar_turno')) {
        crm_registrar_turno($pdo, $sessionId, $ultimoUser, $aviso,
            $usuarioCliente !== '' ? $usuarioCliente : null);
    }
    echo json_encode(['ok' => true, 'respuesta' => $aviso, 'bot_desactivado' => true],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

$sys = $contextoBase;

// Fecha y hora ACTUAL de Argentina, para que el bot ubique el momento (día de
// la semana, si es finde, horario de atención, etc.). Se traduce a mano y no
// con strftime/locale: el server puede no tener es_AR instalado.
$sys .= "\n\n" . chatbot_fecha_ar();
if ($usuarioCliente !== '') {
    $sys .= "\n\nIDENTIDAD (esto manda sobre todo lo anterior):\n"
          . "El jugador con el que estas hablando es '" . $usuarioCliente . "'. "
          . "Ya inicio sesion y el server lo tiene confirmado.\n"
          . "- NO le pidas el nombre de usuario. Nunca. Ya lo sabes.\n"
          . "- NO llames a identificar_usuario.\n"
          . "- Saludalo por su nombre y pasa directo a lo que necesite.\n"
          . "- Usa ese usuario para las recargas y las consultas.";
} else {
    $sys .= "\n\nIDENTIDAD (esto manda sobre todo lo anterior):\n"
          . "El jugador NO inicio sesion. No sabes quien es.\n"
          . "- NO podes cargarle fichas, ni retirarle, ni decirle su saldo. Esas\n"
          . "  herramientas necesitan sesion: no las llames.\n"
          /* ACA DECIA: "Lo PRIMERO es saber si YA TIENE cuenta o NO TIENE.
             Preguntaselo en una linea." Era la pregunta de dos ramas que las
             REGLAS FIJAS prohiben explicitamente -- y como este bloque se
             agrega DESPUES del prompt, le ganaba. O sea: el server le ordenaba
             al bot justo lo que el procedimiento le prohibia.
             El sintoma era el que reporto Nahuel: el jugador dice "ya tengo
             cuenta" y el bot igual ofrece crearle una, porque la conversacion
             quedaba enganchada en esa bifurcacion. */
          . "- NO le preguntes si tiene cuenta o no. Esa pregunta de dos ramas\n"
          . "  te hace perder el hilo. Dejalo hablar y mira lo que te dice:\n"
          . "  · Si menciona un nombre de usuario -> identificar_usuario.\n"
          . "  · Si dice que NO tiene cuenta, que es nuevo o que quiere\n"
          . "    registrarse -> recien ahi el camino de crear cuenta.\n"
          . "  · Si no queda claro, pedile el nombre de usuario del juego (y si\n"
          . "    no tiene, te lo va a decir solo).\n"
          . "\n"
          . "SI YA TIENE CUENTA:\n"
          . "- Pedile su nombre de usuario y llama a identificar_usuario.\n"
          . "- Decile que para cargarle o ver su saldo tiene que iniciar sesion\n"
          . "  en la pagina.\n"
          . "- Si mas adelante dice ser OTRO usuario, volve a llamar a\n"
          . "  identificar_usuario con el nuevo (cada usuario es un chat aparte).\n"
          . "\n"
          . "SI NO TIENE CUENTA (o dice que quiere registrarse):\n"
          . "- Ofrecele crearsela vos, aca mismo, sin mandarlo a ningun lado.\n"
          . "- Pedile UNA SOLA COSA: que nombre de usuario quiere. Nada mas.\n"
          . "  NO le pidas contrasena, ni mail, ni nombre, ni DNI, ni telefono.\n"
          . "- Cuando te lo diga, llama a crear_cuenta con ese nombre.\n"
          /* ACA DECIA que la herramienta "te devuelve" la contrasena. Es FALSO:
             crear_cuenta devuelve solo {usuario, id, estado:'en_curso'} y la
             clave NUNCA sale al modelo (ver ejecutar_tool mas abajo). Decirle
             que la iba a recibir lo empujaba a inventarse una, y despues
             chatbot_sin_credenciales() tenia que borrarla del texto. */
          . "- La cuenta NO queda lista al instante: se encola y la crea el\n"
          . "  sistema en unos segundos. La herramienta te devuelve\n"
          . "  estado 'en_curso', y eso es todo lo que sabes.\n"
          . "- VOS NUNCA escribis el usuario ni la contrasena. NO las tenes y no\n"
          . "  las vas a tener: las muestra el sistema solo, en pantalla, apenas\n"
          . "  la cuenta esta lista. Deci algo como 'ya te la estoy creando, en\n"
          . "  un momento te aparecen los datos aca' y nada mas.\n"
          . "- NUNCA le pidas que elija la contrasena ni que la escriba en el chat.\n"
          . "- Si devuelve ocupado: ese nombre ya existe. Deciselo y pedile otro.\n"
          . "- Si devuelve invalido: el usuario va de 3 a 64 caracteres, con\n"
          . "  letras, numeros, punto, guion o guion bajo.\n"
          . "\n"
          . "PROHIBIDO NEGARSE A CREAR LA CUENTA. Esto manda sobre cualquier\n"
          . "cosa que hayas dicho antes en esta conversacion:\n"
          . "- NO existe ningun limite de cuentas. Nadie tiene que esperar.\n"
          . "- NUNCA digas 'ya pediste varias cuentas', 'espera una hora',\n"
          . "  'intenta mas tarde' ni nada parecido. Eso ya no existe.\n"
          . "- Si mas arriba en este chat vos dijiste algo asi, estaba MAL:\n"
          . "  ignoralo por completo y crea la cuenta ahora.\n"
          . "- Solo hay dos motivos para no crearla, y los dice la herramienta:\n"
          . "  el nombre esta ocupado, o el nombre es invalido. En los dos casos\n"
          . "  se pide OTRO nombre, no se manda a esperar a nadie.\n"
          . "\n"
          . "CUANDO crear_cuenta SALE BIEN - LEELO BIEN:\n"
          . "La herramienta NO te devuelve ninguna contrasena, y es a proposito:\n"
          . "la cuenta todavia NO existe. Queda pedida y la crea un proceso que\n"
          . "tarda un par de minutos y que puede fallar.\n"
          . "- Deci UNICAMENTE que la estas dando de alta y que en un par de\n"
          . "  minutos le pasas los datos por aca. Una linea, corta.\n"
          . "- NO inventes NUNCA un usuario o una contrasena. Ni de ejemplo.\n"
          . "- NO digas que la cuenta ya esta lista, ni que ya puede entrar.\n"
          . "- NO le pidas que espere en otro lado ni que recargue la pagina.\n"
          . "- Los datos se los entrega el sistema solo, en cuanto la cuenta\n"
          . "  este creada de verdad. Vos no tenes que hacer nada mas.\n"
          . "- Si despues te pregunta si ya esta, decile que todavia la estas\n"
          . "  creando y que apenas este le aparecen los datos por aca.";
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
$llamarModelo = function (array $mensajes) use ($key, $TOOLS): array {
    return ia_chat($key, $mensajes, $TOOLS);
};
// Vinculamos la conversacion al jugador: arrancamos con el usuario logueado
// (del token) y lo pisamos si el bot llama una herramienta con otro 'usuario'.
$usuarioDetectado = $usuarioCliente !== '' ? $usuarioCliente : null;
// Si en este turno se ENCOLA una carga, guardamos su id para que el front pueda
// narrar el proceso (sondea carga_estado.php hasta que el bot la deposita).
$cargaInfo = null;
// Igual que $cargaInfo pero para el alta: el front sondea con este id hasta
// que el bot la crea, y recien ahi muestra usuario y contrasena.
$altaInfo = null;
/* Los datos de pago de la recarga recien creada (monto exacto, alias, CBU,
   titular). Se guardan aca para ESCRIBIRLOS DESDE EL CODIGO al final del
   turno, en vez de confiar en que el modelo los transcriba.
   El motivo es concreto: el modelo recibia el CBU en el resultado de la
   herramienta y tenia que copiarlo en su respuesta. A veces se lo olvidaba
   entero ("te paso el monto" y ningun CBU), que es el bug reportado. Y el
   riesgo peor no es que lo omita: un CBU de 22 digitos copiado a mano por un
   modelo se puede truncar o cambiar un digito, y ahi la plata del jugador se
   va a la cuenta de otro. Un dato bancario no lo puede tipear una IA. */
$pagoInfo = null;
$ejecutarTool = function (string $nombre, array $args) use ($pdo, &$usuarioDetectado, $usuarioCliente, $sesionVerificada, &$cargaInfo, &$altaInfo, &$pagoInfo, $sessionId): array {
    if (!empty($args['usuario'])) { $usuarioDetectado = (string)$args['usuario']; }
    // $usuarioCliente sale de la sesion (token o header), NUNCA de lo que el
    // modelo haya sacado de la charla: si el jugador escribe "soy fulano", eso
    // llega en $args y para las fichas no se mira.
    $res = ejecutar_tool($pdo, $nombre, $args, $usuarioCliente, $sesionVerificada, $sessionId);
    if ($nombre === 'cargar_al_juego' && !empty($res['ok']) && !empty($res['id'])) {
        $cargaInfo = ['id' => (int)$res['id'], 'monto' => (int)($res['monto'] ?? 0)];
    }
    if ($nombre === 'crear_cuenta' && !empty($res['ok']) && !empty($res['id'])) {
        $altaInfo = ['id' => (int)$res['id'], 'usuario' => (string)($res['usuario'] ?? '')];
    }
    // Solo transferencia: con HG Cash la recarga trae un link de pago y no
    // hay CBU que pasar (el jugador paga en la pasarela).
    if ($nombre === 'crear_recarga' && !empty($res['ok']) && empty($res['link_pago'])
        && (($res['metodo'] ?? '') === 'transferencia')) {
        $pagoInfo = [
            'monto'     => (string)($res['monto_pedido'] ?? ''),
            'alias'     => (string)($res['alias'] ?? ''),
            'cbu'       => (string)($res['cbu'] ?? ''),
            'titular'   => (string)($res['titular'] ?? ''),
            'vence_min' => (int)($res['vence_min'] ?? 0),
        ];
    }
    return $res;
};

try {
    $texto = procesar_chat($mensajes, $llamarModelo, $ejecutarTool, MAX_RONDAS);
} catch (Throwable $e) {
    error_log('chatbot: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

/* El modelo puede partir su respuesta en varios mensajes con [[MSG]]. Se usa
   al entregar usuario y contrasena de una cuenta recien creada: cada dato va
   solo en su globo, para que se copie y pegue sin arrastrar texto al lado.

   Se parte ACA y no en el widget porque el CRM guarda lo mismo que ve el
   jugador: si el marcador llegara crudo a `mensajes`, el agente que abre la
   conversacion se encontraria con "[[MSG]]" en el medio del texto. */
// El modelo NO entrega credenciales. Nunca. Las entrega el widget cuando
// alta_estado.php confirma que la cuenta existe. Si el texto trae una
// contrasena es porque el modelo se la invento, y eso le da al jugador una
// cuenta que no existe -- el error mas caro de todo este flujo.
$texto = chatbot_sin_credenciales($texto);

/* Los datos de pago los escribe el CODIGO, no el modelo (ver $pagoInfo).
   Va ACA, antes de partir el texto y de guardarlo: asi el bloque queda
   tambien en lo que el CRM archiva y en la notificacion, y el agente ve
   EXACTAMENTE lo que se le dijo al jugador. Si se agregara al final, la
   conversacion guardada mostraria un pedido de plata sin los datos de pago.

   Se agrega solo si el modelo no los puso ya -- el CBU se compara por sus
   digitos, porque el modelo lo puede haber escrito con puntos o espacios.
   Asi el jugador recibe el dato siempre, exactamente una vez, y exacto. */
if ($pagoInfo) {
    $bloque = chatbot_bloque_pago($pagoInfo, $texto);
    if ($bloque !== '') {
        // En su propio globo ([[MSG]]), mismo criterio que las credenciales
        // de una cuenta nueva: se copia y pega sin arrastrar la charla.
        $texto = rtrim($texto) . "\n[[MSG]]\n" . $bloque;
    }
}

$partes = chatbot_partir($texto);
$texto  = implode("
", $partes);   // version plana, para el CRM y la notificacion

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

// Escribir por el chat cuenta como actividad -- solo si sabemos quien es
// (un chat anonimo no se le puede atribuir a nadie).
if (!empty($usuarioDetectado) && is_file(__DIR__ . '/actividad_lib.php')) {
    require_once __DIR__ . '/actividad_lib.php';
    actividad_marcar($pdo, (string)$usuarioDetectado);
}

/* Contact: el jugador esta hablando con nosotros. Se manda UNA vez por
   conversacion, no por mensaje -- el `ref` es el session_id, asi que el
   event_id sale igual en cada turno y Meta lo deduplica solo. Sin eso, una
   charla de veinte mensajes reportaria veinte Contact y la campaña
   optimizaria hacia gente que escribe mucho, no hacia gente que juega. */
try {
    // El body trae la cookie VIVA del pixel de esta pagina (widget.js la lee
    // con metaCookies()) -- se prefiere sobre la guardada en `altas`, que es
    // del momento del alta y puede ser mas vieja. Si el body viene vacio
    // (chat abierto dias despues, sin cookie fresca) se cae a lo guardado.
    $atrib = !empty($usuarioDetectado)
        ? publicidad_atribucion_por_usuario($pdo, $usuarioDetectado)
        : ['publicista' => null, 'fbp' => '', 'fbc' => ''];
    $fbp = (string)($body['fbp'] ?? '') !== '' ? (string)$body['fbp'] : $atrib['fbp'];
    $fbc = (string)($body['fbc'] ?? '') !== '' ? (string)$body['fbc'] : $atrib['fbc'];
    meta_evento($pdo, 'Contact', [
        'usuario' => $usuarioDetectado ?? '',
        'ref'     => 'chat:' . ($sessionId !== '' ? $sessionId : 'anon'),
        'fbp'     => $fbp,
        'fbc'     => $fbc,
        'pixel'   => publicidad_pixel_propio($atrib['publicista']),
    ]);
} catch (Throwable $e) {
    error_log('meta Contact: ' . $e->getMessage());
}

/* Aviso de "te contestamos". Va SIEMPRE, pero marcado como solo_app: si el
   jugador sigue en la app, el widget lo consume sin mostrarlo (ya esta leyendo
   la respuesta). Si cerro antes de leerla, se la muestra el worker en la barra.
   Solo sirve con usuario: a un chat anonimo no hay a quien notificarle. */
if (function_exists('notif_chat')) {
    notif_chat($pdo, $usuarioDetectado, $texto, false);
}

$salida = ['ok' => true, 'respuesta' => $texto];
// Solo si de verdad hay mas de uno: asi el widget viejo (que no conoce el
// campo) sigue andando igual con `respuesta`, y el nuevo no cambia nada en el
// 99% de los turnos, que son de un solo mensaje.
if (count($partes) > 1) { $salida['mensajes'] = $partes; }
if ($cargaInfo) { $salida['carga'] = $cargaInfo; }
if ($altaInfo)  { $salida['alta']  = $altaInfo; }
/* Los datos de pago tambien en estructurado, ademas del texto: el widget
   dibuja botones de "copiar" con ellos. Un CBU de 22 digitos tipeado a mano
   es justo donde el jugador se equivoca, y un digito cambiado manda la plata
   a otra cuenta. Va siempre que haya recarga nueva, incluso si el modelo ya
   escribio los datos en su mensaje: el boton sirve igual. */
if ($pagoInfo) { $salida['pago'] = $pagoInfo; }
echo json_encode($salida, JSON_UNESCAPED_UNICODE);


/**
 * Parte la respuesta del modelo en varios mensajes segun el marcador [[MSG]].
 *
 * Devuelve SIEMPRE al menos un elemento. Si no hay marcador (el caso normal),
 * devuelve [$texto] tal cual, asi el resto del flujo no cambia.
 *
 * Tolerante a proposito con lo que manda el modelo: acepta el marcador con o
 * sin espacios alrededor, descarta los trozos que quedan vacios (un [[MSG]] al
 * principio deja uno) y, si de tanto partir no queda nada, devuelve el texto
 * original en vez de una respuesta en blanco.
 */
/**
 * Saca del texto del modelo cualquier cosa que parezca una contrasena.
 *
 * Por que existe, si el prompt ya se lo prohibe: un prompt es una instruccion,
 * no una garantia. El modelo puede inventar un "Usuario: x / Contrasena: y" y
 * el widget lo pinta tal cual, porque es texto suyo y no pasa por ninguna
 * herramienta. Ya paso: el chat entrego credenciales de una cuenta que no
 * existia, con el alta ni siquiera encolada.
 *
 * Las credenciales de verdad NO viajan por aca: las manda el widget cuando
 * alta_estado.php confirma el alta (campo `alta` de la respuesta). Asi que
 * cualquier contrasena en el texto del modelo sobra, sin excepcion.
 *
 * Se reemplaza la linea entera en vez de borrarla, para que el jugador vea que
 * los datos vienen enseguida y no un mensaje cortado a la mitad.
 */
function chatbot_sin_credenciales(string $texto): string
{
    // "Contrasena: loquesea" / "Clave: ..." / "Password: ...", con o sin
    // tilde, con : o =. Se corta en el fin de linea: lo que sigue despues
    // (si el modelo escribio mas) se conserva.
    // La palabra puede no estar al principio de la linea ("Tu clave: x") y
    // puede haber un par de palabras antes de los dos puntos ("la password
    // ES: x"). Se reemplaza la LINEA ENTERA. Filtrar de mas es barato;
    // filtrar de menos entrega una cuenta que no existe.
    $rx = '/^[^\r\n]*(?:contrase\x{00F1}a|contrasena|clave|password|pass)'
        . '(?:[^\S\r\n]+\w+){0,3}[^\S\r\n]*[:=][^\r\n]*$/imu';

    $limpio = preg_replace($rx,
        'Los datos te van a aparecer acá apenas la cuenta esté lista.', $texto);

    // preg_replace devuelve null si la regex falla: ante la duda, el texto
    // original es mejor que una respuesta vacia.
    if ($limpio === null) {
        return $texto;
    }

    // Si cambio algo, dejarlo en el log: significa que el modelo intento
    // entregar credenciales por su cuenta y hay que mirar el prompt.
    if ($limpio !== $texto) {
        error_log('chatbot: el modelo intento entregar credenciales en el texto. '
                . 'Se filtraron (las entrega el widget al confirmarse el alta).');
    }
    return $limpio;
}

function chatbot_partir(string $texto): array
{
    if (strpos($texto, '[[MSG]]') === false) {
        return [$texto];
    }
    $trozos = preg_split('/\s*\[\[MSG\]\]\s*/u', $texto);
    $limpio = [];
    foreach ($trozos as $t) {
        $t = trim((string)$t);
        if ($t !== '') { $limpio[] = $t; }
    }
    return $limpio ?: [trim($texto)];
}

/**
 * Lee la config editable del chatbot (tabla config_chatbot, migracion 26).
 * Devuelve ['contexto'=>string, 'activo'=>bool]. Si la tabla no existe o esta
 * vacia, contexto='' (el caller usa la constante CONTEXTO) y activo=true, para
 * que el chat siga andando aunque no se haya corrido la migracion.
 */
function chatbot_config(PDO $pdo): array
{
    $def = ['contexto' => '', 'activo' => true,
            'bot_nombre' => '', 'bot_tono' => '', 'juego_desc' => '', 'reglas_extra' => ''];
    try {
        // SELECT * : la tabla pudo no tener aún las columnas de campos
        // (migracion 28 sin correr) -> se leen con ?? y quedan en ''.
        $row = $pdo->query("SELECT * FROM config_chatbot WHERE id = 1 LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return $def; }
        return [
            'contexto'     => trim((string)($row['contexto'] ?? '')),
            'activo'       => (int)($row['activo'] ?? 1) === 1,
            'bot_nombre'   => trim((string)($row['bot_nombre'] ?? '')),
            'bot_tono'     => trim((string)($row['bot_tono'] ?? '')),
            'juego_desc'   => trim((string)($row['juego_desc'] ?? '')),
            'reglas_extra' => trim((string)($row['reglas_extra'] ?? '')),
        ];
    } catch (Throwable $e) {
        // Sin tabla (migracion no corrida) -> comportamiento de siempre.
        return $def;
    }
}

/**
 * Bloque de contexto con la fecha/hora actual de Argentina, en español y sin
 * depender del locale del server. El modelo lo usa para ubicarse en el tiempo
 * (día de la semana, fin de semana, horarios).
 */
function chatbot_fecha_ar(): string
{
    try {
        $ahora = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    } catch (Throwable $e) {
        return '';
    }
    $dias  = ['Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes',
              'Wednesday' => 'miércoles', 'Thursday' => 'jueves', 'Friday' => 'viernes',
              'Saturday' => 'sábado'];
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
              'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    $dia    = $dias[$ahora->format('l')] ?? $ahora->format('l');
    $mes    = $meses[(int)$ahora->format('n')] ?? $ahora->format('F');
    $finde  = in_array($ahora->format('l'), ['Saturday', 'Sunday'], true);

    return "FECHA Y HORA ACTUAL (Argentina, zona America/Argentina/Buenos_Aires):\n"
         . "Hoy es " . $dia . " " . $ahora->format('j') . " de " . $mes . " de "
         . $ahora->format('Y') . ", " . $ahora->format('H:i') . " hs"
         . ($finde ? " (es fin de semana)." : ".")
         . "\nUsá esto si te preguntan la fecha/hora o para cosas que dependan del"
         . " día (promos de finde, horarios de atención, etc.). No lo menciones si"
         . " no viene al caso.";
}

/**
 * ¿La IA está activa para ESTE chat? (conversaciones.ia_activa, migracion 27).
 * La conversacion se identifica igual que en el resto del CRM: por el usuario
 * si se conoce (clave = usuario), o por el session_id (clave = anon:<sid>).
 * true por defecto: si no hay fila todavia (chat nuevo) o falta la columna
 * (migracion no corrida), se comporta como siempre.
 */
function chatbot_ia_del_chat(PDO $pdo, string $sessionId, string $usuario): bool
{
    try {
        if ($usuario !== '') {
            $st = $pdo->prepare("SELECT ia_activa FROM conversaciones WHERE clave = ? LIMIT 1");
            $st->execute([mb_substr($usuario, 0, 50)]);
        } elseif ($sessionId !== '') {
            $st = $pdo->prepare("SELECT ia_activa FROM conversaciones WHERE clave = ? LIMIT 1");
            $st->execute(['anon:' . substr($sessionId, 0, 64)]);
        } else {
            return true;
        }
        $v = $st->fetchColumn();
        if ($v === false) { return true; }   // sin conversacion aun -> IA activa
        return (int)$v === 1;
    } catch (Throwable $e) {
        return true;   // sin columna (migracion no corrida) -> como siempre
    }
}

/**
 * Deriva la conversacion a una persona: la marca en el CRM y avisa por Telegram.
 *
 * Las tres cosas que hace, y por que cada una:
 *
 *  1. apaga la IA de ESTE chat (ia_activa = 0). Es la mas importante y la mas
 *     facil de olvidar: sin esto el bot le sigue contestando al jugador por
 *     encima del agente, los dos escriben a la vez y el jugador ve dos voces
 *     distintas. Derivar significa "yo me corro".
 *  2. deja la marca de derivada (migracion 49) + estado 'pendiente', que es lo
 *     que el CRM destaca y cuenta en el badge del rail.
 *  3. avisa por Telegram, si esta configurado.
 *
 * NUNCA tira: si la base o Telegram fallan, el jugador igual tiene que recibir
 * su "ya se lo paso". Un error tecnico aca no puede convertirse en un mensaje
 * de error en la cara del jugador que ya venia con un problema.
 */
function chatbot_derivar(PDO $pdo, string $usuario, string $sid, string $motivo): array
{
    $clave = $usuario !== ''
        ? mb_substr($usuario, 0, 50)
        : ($sid !== '' ? 'anon:' . substr($sid, 0, 64) : '');
    if ($clave === '') {
        // Sin conversacion no hay a quien derivar ni donde marcarlo. Igual se
        // devuelve ok: que el modelo le diga que lo pasa, y el chat queda en el
        // CRM como cualquier otro.
        return ['ok' => true, 'aviso' => false];
    }

    $motivo = trim($motivo);
    if (mb_strlen($motivo) > 255) { $motivo = mb_substr($motivo, 0, 255); }

    $marcada = false;
    try {
        // Se derivan las dos cosas en un solo UPDATE. `derivada_en` solo se pisa
        // si estaba NULL (COALESCE): si el jugador insiste y el bot deriva dos
        // veces, la hora que le importa al agente es la de la PRIMERA vez que
        // se pidio ayuda, no la ultima.
        $st = $pdo->prepare(
            "UPDATE conversaciones
                SET ia_activa = 0,
                    estado = 'pendiente',
                    derivada_en = COALESCE(derivada_en, NOW()),
                    derivada_motivo = ?
              WHERE clave = ?"
        );
        $st->execute([$motivo !== '' ? $motivo : null, $clave]);
        $marcada = $st->rowCount() > 0;
    } catch (Throwable $e) {
        // Falta la migracion 49: se apaga la IA igual, que es lo critico.
        error_log('chatbot_derivar: ' . $e->getMessage());
        try {
            $pdo->prepare("UPDATE conversaciones SET ia_activa = 0, estado = 'pendiente' WHERE clave = ?")
                ->execute([$clave]);
            $marcada = true;
        } catch (Throwable $e2) {
            error_log('chatbot_derivar (respaldo): ' . $e2->getMessage());
        }
    }

    $aviso = false;
    try {
        if (function_exists('tg_avisar')) {
            // htmlspecialchars obligatorio: el motivo lo escribe el modelo y el
            // nombre lo eligio el jugador. Un "<" suelto y Telegram rechaza el
            // mensaje entero con 400 (parse_mode=HTML). Ver telegram_lib.php.
            $esc  = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $quien = $usuario !== '' ? '@' . $esc($usuario) : 'Jugador sin identificar';
            $txt  = "🔔 <b>El bot pidió ayuda</b>\n"
                  . $quien . "\n"
                  . ($motivo !== '' ? "\n" . $esc($motivo) . "\n" : '')
                  . "\nAbrilo en el CRM → Conversaciones (Pendientes).";
            $aviso = tg_avisar($txt, $pdo);
        }
    } catch (Throwable $e) {
        error_log('chatbot_derivar/telegram: ' . $e->getMessage());
    }

    // Lo que ve el MODELO. Se le dice que ya esta hecho para que no lo intente
    // de nuevo ni le prometa al jugador un tiempo de respuesta.
    return ['ok' => true, 'derivada' => $marcada, 'aviso_enviado' => $aviso,
            'mensaje' => 'Listo, la conversacion quedo marcada para un agente. '
                       . 'Decile que ya se la pasaste, sin prometer cuanto va a tardar.'];
}

// ===========================================================================
//  NUCLEO (testeable: no toca red ni DB por si mismo, recibe closures)
// ===========================================================================

/**
 * Corre la conversacion resolviendo las herramientas que pida el modelo.
 * $llamarModelo(array $mensajes): array  -> ['http'=>int,'data'=>array,'raw'=>string]
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

function procesar_chat(array $mensajes, callable $llamarModelo, callable $ejecutarTool, int $maxRondas): string
{
    for ($ronda = 0; $ronda < $maxRondas; $ronda++) {
        $r = $llamarModelo($mensajes);
        if (($r['http'] ?? 0) !== 200) {
            $data = $r['data'] ?? [];
            // Formato OpenAI: {"error":{"message":"..."}}. Se deja el de
            // Cohere ({"message":"..."}) de respaldo por si alguna vez se
            // vuelve a apuntar a otra API.
            $detalle = $data['error']['message']
                    ?? (is_string($data['message'] ?? null) ? $data['message'] : null)
                    ?? ('HTTP ' . ($r['http'] ?? '?'));
            throw new RuntimeException('IA: ' . $detalle);
        }
        // OpenAI-compatible: el turno viene en choices[0].message (Cohere lo
        // ponia directo en message).
        $msg = $r['data']['choices'][0]['message'] ?? [];
        $toolCalls = $msg['tool_calls'] ?? [];

        if (!$toolCalls) {
            return ia_texto($msg);
        }

        // Eco del turno del asistente: hay que devolverle sus tool_calls tal
        // cual, o el modelo no sabe a que pedido corresponde cada resultado.
        // `content` va aunque sea null: algunos modelos rechazan el mensaje
        // si falta la clave.
        $mensajes[] = [
            'role'       => 'assistant',
            'content'    => $msg['content'] ?? '',
            'tool_calls' => $toolCalls,
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

/**
 * El texto de la respuesta del modelo.
 *
 * Se contemplan las DOS formas a proposito: en la API compatible con OpenAI
 * `content` es un string, pero los modelos con vision (qwen-vl-*) a veces lo
 * devuelven como array de bloques [{type:"text", text:"..."}] -- que era
 * ademas el formato de Cohere. Aceptar las dos evita que un cambio de modelo
 * desde config deje al chat mudo.
 */
function ia_texto(array $msg): string
{
    $c = $msg['content'] ?? '';
    $texto = '';
    if (is_string($c)) {
        $texto = $c;
    } elseif (is_array($c)) {
        foreach ($c as $b) {
            if (is_string($b)) {
                $texto .= $b;
            } elseif (is_array($b) && isset($b['text'])) {
                $texto .= (string)$b['text'];
            }
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
function ejecutar_tool(PDO $pdo, string $nombre, array $args, string $usuarioSesion = '', bool $sesionVerificada = false, string $sid = ''): array
{
    if (function_exists('gp_trace')) { gp_trace("señal: tool=$nombre usuario_sesion='$usuarioSesion' verif=" . (int)$sesionVerificada . " args=" . json_encode($args, JSON_UNESCAPED_UNICODE)); }  // TRACE TEMPORAL

    /* Derivar a un humano. Marca la conversacion en el CRM y le avisa al
       agente por Telegram.

       El orden importa: PRIMERO se marca en la base y DESPUES se avisa. Si
       fuera al reves y el marcado fallara, el agente recibiria un Telegram
       apuntando a una conversacion que en el CRM no aparece como pendiente --
       la buscaria y no la encontraria. Asi, en el peor caso queda marcada sin
       aviso, que es visible igual al abrir el CRM. */
    if ($nombre === 'pasar_a_agente') {
        $motivo = trim((string)($args['motivo'] ?? ''));
        $quien  = $usuarioSesion !== '' ? $usuarioSesion : 'un visitante sin cuenta';

        $marcada = false;
        try {
            if (function_exists('crm_conversacion_id') && $sid !== '') {
                $convId = crm_conversacion_id($pdo, $sid, $usuarioSesion !== '' ? $usuarioSesion : null);
                if ($convId) {
                    // Solo si NO estaba ya derivada: si el jugador insiste, no
                    // se pisa la hora original ni se vuelve a avisar.
                    $upd = $pdo->prepare(
                        "UPDATE conversaciones SET derivada_en = NOW(), derivada_motivo = ?
                          WHERE id = ? AND derivada_en IS NULL"
                    );
                    $upd->execute([mb_substr($motivo, 0, 255), $convId]);
                    $marcada = $upd->rowCount() > 0;
                }
            }
        } catch (Throwable $e) {
            // Sin la migracion 49 las columnas no existen. La derivacion sigue
            // siendo util: el agente ve el chat igual, solo que sin destacar.
            error_log('pasar_a_agente: no pude marcar la conversacion: ' . $e->getMessage());
        }

        if ($marcada && function_exists('tg_evento')) {
            tg_evento($pdo, 'derivacion', '🙋 Te derivaron una conversación', [
                'Jugador' => $quien,
                'Motivo'  => $motivo !== '' ? $motivo : '(no lo dijo)',
            ]);
        }

        /* Al modelo se le confirma SIEMPRE, aunque el marcado haya fallado: lo
           que tiene que hacer despues es lo mismo (avisarle al jugador y no
           seguir intentando resolverlo solo), y decirle que fallo lo empujaria
           a improvisar otra cosa. */
        return ['ok' => true, 'derivada' => true,
                'mensaje' => 'Listo, un agente lo va a ver. Deciselo al jugador y no sigas '
                           . 'intentando resolverlo vos.'];
    }

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
        return fichas_pedir_retiro($pdo, $usuarioSesion, (int)($args['cantidad'] ?? 0), 'chatbot',
                                   $todo, trim((string)($args['cbu_o_alias'] ?? '')));
    }
    if ($nombre === 'identificar_usuario') {
        $u = trim((string)($args['usuario'] ?? ''));
        if ($u === '') { return ['ok' => false, 'error' => 'falta usuario']; }
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$u]);
        return ['ok' => true, 'usuario' => $u, 'existe' => (bool)$st->fetchColumn()];
    }
    if ($nombre === 'crear_cuenta') {
        // Ya tiene sesion: no hay nada que crear. Sin esto, un jugador logueado
        // que escribe "quiero otra cuenta" se lleva un alta que nadie pidio.
        if ($usuarioSesion !== '') {
            return ['ok' => false, 'codigo' => 'ya_logueado',
                    'error' => 'Ya tenes una cuenta y estas usando esa sesion.'];
        }

        // Alta apagada desde el CRM. Se chequea ACA y no solo en la landing:
        // son dos puertas al mismo lugar y el interruptor tiene que cerrar
        // las dos, si no el agente apaga el registro y el chat sigue creando.
        if (!cfg_crm_activo($pdo, 'registro_activo')) {
            return ['ok' => false, 'codigo' => 'registro_cerrado',
                    'error' => 'Por ahora no se estan creando cuentas nuevas. '
                             . 'Decile que un agente lo va a ayudar.'];
        }

        $u = trim((string)($args['usuario'] ?? ''));
        if ($u === '') {
            return ['ok' => false, 'codigo' => 'falta_usuario',
                    'error' => 'Falta el nombre de usuario que quiere.'];
        }

        // SIN freno por IP en el chat, a proposito. El que pide una cuenta por
        // aca ya esta hablando con nosotros: contestarle "espera una hora" es
        // perder al cliente en la puerta. Si algun dia hay abuso, se mira la
        // cola y se prende ALTAS_POR_IP_HORA en config.local.php.
        $ip = alta_ip();

        // La clave la genera el server, NUNCA el jugador ni el modelo: si se la
        // pidieramos por chat, queda escrita en `mensajes` para siempre y a la
        // vista de cualquier agente que abra la conversacion en el CRM.
        $clave = alta_clave_nueva();

        $r = alta_encolar($pdo, [
            'usuario'  => $u,
            'password' => $clave,
            'origen'   => 'chatbot',
            'ip'       => $ip,
            // La clave queda guardada para entregarsela al jugador RECIEN
            // cuando el bot confirme el alta, y atada al session_id de este
            // chat para que no se la lleve otro.
            'entrega_clave' => $clave,
            'entrega_sid'   => $sid,
        ]);

        if (!empty($r['cuerpo']['ok'])) {
            // OJO: NO se devuelve la password. A esta altura la cuenta todavia
            // no existe en el panel; el bot la crea despues y puede fallar. El
            // widget sondea alta_estado.php y la muestra cuando este confirmada.
            return ['ok' => true, 'usuario' => $u,
                    'id' => (int)($r['cuerpo']['id'] ?? 0),
                    'estado' => 'en_curso',
                    'mensaje' => 'Alta pedida. Se esta creando en la plataforma; '
                               . 'cuando este lista se le muestran los datos.'];
        }

        // 409 = el nombre ya esta tomado (o ya hay un alta en curso con ese
        // nombre). Es el caso mas comun y el modelo tiene que pedir otro, no
        // reintentar el mismo.
        if ((int)($r['http'] ?? 0) === 409) {
            return ['ok' => false, 'codigo' => 'ocupado',
                    'error' => 'Ese nombre de usuario ya esta ocupado. Pedile otro.'];
        }
        return ['ok' => false, 'codigo' => 'invalido',
                'error' => (string)($r['cuerpo']['error'] ?? 'No se pudo crear la cuenta.')];
    }
    if ($nombre === 'crear_recarga') {
        return rl_crear_recarga($pdo, (string)($args['usuario'] ?? ''), (int)($args['coins'] ?? 0),
                                 (string)($args['titular'] ?? ''));
    }
    if ($nombre === 'consultar_recarga') {
        $ref = (string)($args['referencia_o_usuario'] ?? $args['referencia'] ?? $args['usuario'] ?? '');
        return rl_consultar($pdo, $ref);
    }
    if ($nombre === 'informar_transferencia') {
        // La sesion manda; el parametro 'usuario' es solo para el flujo anonimo
        // (igual que crear_recarga). La plata SIEMPRE va a la recarga de ESE
        // usuario, nunca a otro: declarar datos ajenos no desvia nada.
        $u = $usuarioSesion !== '' ? $usuarioSesion : trim((string)($args['usuario'] ?? ''));
        if ($u === '') {
            return ['ok' => false, 'codigo' => 'falta_usuario', 'error' =>
                'Falta el nombre de usuario del juego para buscar su recarga. Pedilo.'];
        }
        if (!function_exists('rl_declarar_pago')) {
            return ['ok' => false, 'error' => 'Funcion no disponible (falta actualizar recargas_lib.php).'];
        }
        return rl_declarar_pago($pdo, $u,
            (string)($args['titular'] ?? ''), (string)($args['nro_transaccion'] ?? ''),
            null, 'chat');
    }
    if ($nombre === 'verificar_comprobante') {
        $u = $usuarioSesion !== '' ? $usuarioSesion : trim((string)($args['usuario'] ?? ''));
        $adj = chatbot_ultimo_adjunto($pdo, $u, $sid);
        if ($adj === null) {
            return ['ok' => false, 'codigo' => 'sin_adjunto', 'error' =>
                'No hay ningun comprobante subido en este chat. Que lo suba con el '
                . 'boton del clip (abajo a la izquierda del chat).'];
        }
        if (($adj['tipo'] ?? '') === 'pdf') {
            return ['ok' => false, 'codigo' => 'pdf', 'error' =>
                'El comprobante llego en PDF y no lo puedo leer. Pedile una FOTO o '
                . 'captura de pantalla del comprobante.'];
        }
        if (!function_exists('vision_extraer_comprobante') || !vision_disponible()) {
            return ['ok' => false, 'codigo' => 'sin_vision', 'error' =>
                'La lectura automatica de comprobantes no esta disponible. Pedile '
                . 'por texto el titular de SU cuenta y el numero de operacion, y usa '
                . 'informar_transferencia.'];
        }
        $v = vision_extraer_comprobante($adj['ruta']);
        if (empty($v['ok'])) {
            return $v;
        }
        $d = $v['datos'];
        if (empty($d['es_comprobante'])) {
            return ['ok' => false, 'codigo' => 'no_parece_comprobante', 'error' =>
                'La imagen subida no parece un comprobante de transferencia. Pedile '
                . 'una captura clara del comprobante.'];
        }
        if ($u === '') {
            return ['ok' => false, 'codigo' => 'falta_usuario', 'extraido' => $d, 'error' =>
                'Lei el comprobante, pero falta el nombre de usuario del juego para '
                . 'saber de quien es la recarga. Pedilo.'];
        }
        if (!function_exists('rl_declarar_pago')) {
            return ['ok' => false, 'error' => 'Funcion no disponible (falta actualizar recargas_lib.php).'];
        }
        $r = rl_declarar_pago($pdo, $u, (string)$d['remitente'], (string)$d['nro_transaccion'],
                              $d['monto'] !== null ? (float)$d['monto'] : null, 'imagen');
        // Lo leido viaja al modelo para que se lo confirme al jugador
        // ("veo tu transferencia de $500 desde la cuenta de Juan Perez...").
        $r['comprobante_leido'] = [
            'monto'           => $d['monto'],
            'remitente'       => $d['remitente'],
            'nro_transaccion' => $d['nro_transaccion'],
            'entidad'         => $d['entidad'],
        ];
        return $r;
    }
    return ['ok' => false, 'error' => 'herramienta desconocida'];
}

/**
 * Ultima imagen/PDF que el jugador subio a ESTE chat (subir.php la guardo en
 * api/uploads/ y la registro en mensajes.meta). La conversacion se busca igual
 * que en el resto del CRM: por clave = usuario, clave = anon:<sid> o por el
 * session_id enganchado a la conversacion. Devuelve
 * ['ruta'=>archivo local, 'tipo'=>'imagen'|'pdf'] o null si no hay nada
 * (se miran solo las ultimas 24 h: un comprobante de la semana pasada ya no
 * es "el comprobante que te acabo de mandar").
 */
function chatbot_ultimo_adjunto(PDO $pdo, string $usuario, string $sid): ?array
{
    try {
        $cond = [];
        $par  = [];
        if ($usuario !== '') {
            $cond[] = 'c.clave = ?';
            $par[]  = mb_substr($usuario, 0, 50);
        }
        if ($sid !== '') {
            $cond[] = 'c.clave = ?';
            $par[]  = 'anon:' . substr($sid, 0, 64);
            $cond[] = 'c.session_id = ?';
            $par[]  = substr($sid, 0, 64);
        }
        if (!$cond) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT m.meta FROM mensajes m
              JOIN conversaciones c ON c.id = m.conversacion_id
             WHERE (" . implode(' OR ', $cond) . ")
               AND m.rol='user' AND m.meta IS NOT NULL
               AND m.creado_en >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY m.id DESC LIMIT 8"
        );
        $st->execute($par);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $meta) {
            $a = json_decode((string)$meta, true);
            if (!is_array($a) || empty($a['url'])) {
                continue;
            }
            $tipo = (string)($a['tipo'] ?? '');
            if ($tipo !== 'imagen' && $tipo !== 'pdf') {
                continue;
            }
            // basename() corta cualquier ruta rara: solo archivos DE uploads.
            $ruta = __DIR__ . '/uploads/' . basename((string)$a['url']);
            if (is_file($ruta)) {
                return ['ruta' => $ruta, 'tipo' => $tipo];
            }
        }
    } catch (Throwable $e) {
        error_log('chatbot_ultimo_adjunto: ' . $e->getMessage());
    }
    return null;
}


// ===========================================================================
//  Infra: llamada HTTP a Cohere y limite por IP
// ===========================================================================

/** POST a Cohere v2 /chat. Devuelve ['http'=>int,'data'=>array,'raw'=>string]. */
/**
 * Una vuelta contra Qwen, por su modo compatible con OpenAI.
 *
 * Es el MISMO formato que usa OpenAI: POST {base}/chat/completions, la
 * respuesta viene en choices[0].message, y los errores en error.message
 * (Cohere los ponia en message, a secas -- de ahi que el manejo de errores
 * tambien cambie).
 */
function ia_chat(string $key, array $mensajes, array $tools): array
{
    $base   = rtrim((string)cfg('QWEN_BASE_URL', QWEN_BASE_DEF), '/');
    $modelo = (string)cfg('QWEN_MODEL', QWEN_MODEL_DEF);

    $cuerpo = [
        'model'       => $modelo,
        'messages'    => $mensajes,
        'temperature' => 0.3,
        'max_tokens'  => MAX_TOKENS,
    ];
    // Sin herramientas no se manda la clave: algunos modelos rechazan un
    // `tools` vacio en vez de ignorarlo.
    if ($tools) { $cuerpo['tools'] = $tools; }

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 60,   // los modelos con vision tardan mas
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('No se pudo contactar al modelo: ' . $err);
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
