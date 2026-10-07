<?php
/**
 * Diagnostico del chatbot. Solo para un administrador con sesión iniciada:
 *   https://ganamoscrm.online/gp-api/chatbot_diag.php?clave=ver-chatbot
 *
 * Prueba los caminos reales del chat (GPT, Claude y Qwen, con la MISMA
 * resolucion de keys que
 * chatbot.php) y muestra el error exacto que provoca el 502 -- incluido un
 * fatal que no se ve en la respuesta porque mata al worker de PHP (ahi
 * Cloudflare devuelve su "error code: 502" y el motivo real queda solo en el
 * error_log del server, que este archivo tambien vuelca).
 *
 * No publica claves, pero sí detalles de configuración y registros; por eso
 * exige la sesión admin del CRM antes de probar proveedores o leer logs.
 */

require __DIR__ . '/config.php';

$ok = isset($_GET['clave']) && $_GET['clave'] === 'ver-chatbot';
if (!$ok) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crm_auth.php';
exigir_admin();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Las mismas constantes que usa chatbot.php, por si este diag corre suelto.
if (!defined('QWEN_BASE_DEF')) {
    define('QWEN_BASE_DEF', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1');
}
if (!defined('QWEN_MODEL_DEF')) {
    define('QWEN_MODEL_DEF', 'qwen-plus');
}

echo "=== ENTORNO ===\n";
echo "PHP          : " . PHP_VERSION . "\n";
echo "curl         : " . (function_exists('curl_init') ? 'si' : 'NO (ese es el problema)') . "\n";
echo "openssl      : " . (extension_loaded('openssl') ? 'si' : 'NO') . "\n";
echo "memory_limit : " . ini_get('memory_limit') . "\n";
echo "error_log    : " . (ini_get('error_log') ?: '(el del php-fpm/servidor)') . "\n";

// ---------------------------------------------------------------------------
// Las KEYS, resueltas IGUAL que chatbot.php (misma lib, no una copia -- una
// copia es justo lo que hace que el diagnostico diga una cosa y el chat haga
// otra). Dos lugares distintos, ver api/ia_key.php:
//   - PRIMARIO Claude: CHAT_MODEL=claude-... + la clave de Anthropic
//     (la del cliente si cargo una, si no ANTHROPIC_API_KEY del server).
//   - RESPALDO Qwen: QWEN_API_KEY (o COHERE_API_KEY, el nombre viejo).
// ---------------------------------------------------------------------------
require_once __DIR__ . '/ia_key.php';
$modelConfig = trim((string)cfg('CHAT_MODEL', ''));
$chatModel   = ia_chat_model_actual();
$claudeKey   = ia_key_anthropic();
$claudeOn    = $chatModel !== '' && stripos($chatModel, 'claude') === 0
            && strlen(trim($claudeKey)) > 20;   // el MISMO predicado que ia_chat_claude_activo()
$origen      = ia_key_origen();
$openaiKey   = ia_key_openai();
$openaiOn    = ia_chat_openai_activo($chatModel, $openaiKey);

$keyQwen   = (string)cfg('QWEN_API_KEY');
$keyCohere = (string)cfg('COHERE_API_KEY');
$key       = ia_key_qwen();

$base   = rtrim((string)cfg('QWEN_BASE_URL', QWEN_BASE_DEF), '/');
$modelo = (string)cfg('QWEN_MODEL', QWEN_MODEL_DEF);

echo "\n=== MODELO PRINCIPAL CONFIGURADO ===\n";
echo "CHAT_MODEL config : " . ($modelConfig !== '' ? $modelConfig : '(vacio)') . "\n";
echo "Modelo efectivo   : " . ($chatModel !== '' ? $chatModel : '(Qwen)') . "\n";
echo "clave de OpenAI   : " . ($openaiKey !== '' ? 'cargada (' . strlen($openaiKey) . ' chars)' : 'VACIA') . "\n";
echo "GPT activo        : " . ($openaiOn ? 'SI' : 'NO') . "\n";
// Las claves NO se imprimen nunca, ni recortadas: este endpoint se abre para
// diagnosticar y una clave filtrada no se puede desfiltrar. Solo largo/origen.
echo "clave de Anthropic: " . ($claudeKey !== ''
        ? 'cargada (' . strlen($claudeKey) . ' chars), sale de '
          . ($origen === 'cliente' ? 'clientes.ia_key (la PROPIA de este cliente)' : 'ANTHROPIC_API_KEY (la global del server)')
        : 'VACIA') . "\n";
echo "Claude activo     : " . ($claudeOn ? 'SI' : 'NO') . "\n";

echo "\n=== RESPALDO: QWEN ===\n";
echo "QWEN_API_KEY   : " . ($keyQwen !== '' ? 'cargada (' . strlen($keyQwen) . ' chars)' : 'VACIA') . "\n";
echo "COHERE_API_KEY : " . ($keyCohere !== '' ? 'cargada (' . strlen($keyCohere) . ' chars)' : 'VACIA') . "\n";
echo "QWEN_BASE_URL  : $base\n";
echo "QWEN_MODEL     : $modelo\n";

if ($keyQwen === '' && $keyCohere !== '') {
    echo "\n  >> OJO: no hay QWEN_API_KEY y el respaldo cae a la clave de Cohere.\n";
    echo "     Esa key NO sirve contra Qwen (DashScope): da 401.\n";
    echo "     Arreglo: agregar 'QWEN_API_KEY' => 'sk-...' en api/config.local.php\n";
}
if (!$claudeOn && !$openaiOn && ($key === '' || strlen($key) < 20)) {
    echo "\n=> No hay NINGUN camino con clave usable: ni GPT (OPENAI_API_KEY),\n";
    echo "   ni Claude (CHAT_MODEL + Anthropic), ni Qwen. El chat esta caido.\n";
    volcar_log();
    exit;
}
if (($claudeOn || $openaiOn) && ($key === '' || strlen($key) < 20)) {
    echo "\n  >> Sin clave de Qwen usable: el chat vive solo del modelo principal, sin respaldo.\n";
}

// ---------------------------------------------------------------------------
// Llamada a OpenAI con una herramienta `ping` sin efectos secundarios. Fuerza
// el tool call para verificar que no solo responde texto, también función.
// ---------------------------------------------------------------------------
if ($openaiOn) {
    echo "\n=== LLAMADA A OPENAI (GPT + function calling) ===\n";
    $bodyO = [
        'model' => $chatModel,
        'messages' => [['role' => 'user', 'content' => 'Llama a ping ahora.']],
        'reasoning_effort' => 'none',
        'max_completion_tokens' => 40,
        'tool_choice' => 'required',
        'tools' => [[
            'type' => 'function',
            'function' => [
                'name' => 'ping',
                'description' => 'Prueba segura sin efectos secundarios.',
                'parameters' => [
                    'type' => 'object', 'properties' => [], 'required' => [],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
        ]],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($bodyO, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json',
            'Authorization: Bearer ' . $openaiKey,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $rawO = curl_exec($ch);
    $httpO = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errO = curl_error($ch);
    curl_close($ch);
    echo "  http = $httpO\n";
    if ($rawO === false) {
        echo "  curl_error = $errO\n";
    } else {
        $dataO = json_decode((string)$rawO, true) ?: [];
        $callsO = $dataO['choices'][0]['message']['tool_calls'] ?? [];
        echo "  tool_calls = " . (count($callsO) > 0 ? 'SI (' . count($callsO) . ')' : 'NO') . "\n";
        if ($httpO !== 200) {
            $msgO = $dataO['error']['message'] ?? '(sin error.message)';
            echo "  error = " . substr((string)$msgO, 0, 500) . "\n";
        }
    }
}

// ---------------------------------------------------------------------------
// LLAMADA REAL a Claude (si esta activo), igual que ia_chat() en chatbot.php:
// el endpoint compatible con OpenAI. Probar solo la config sin llamar es lo
// que dejaba pasar la clave muerta.
// ---------------------------------------------------------------------------
if ($claudeOn) {
    echo "\n=== LLAMADA A CLAUDE (el primario real del chat) ===\n";
    $cuerpoC = json_encode([
        'model'      => $chatModel,
        'messages'   => [['role' => 'user', 'content' => 'Responde solo con la palabra: hola']],
        'max_tokens' => 20,
    ], JSON_UNESCAPED_UNICODE);
    $hdrC = ['Content-Type: application/json', 'Accept: application/json',
             'Authorization: Bearer ' . $claudeKey];
    $ws = trim((string)cfg('ANTHROPIC_WORKSPACE_ID', ''));
    if ($ws !== '') { $hdrC[] = 'anthropic-workspace-id: ' . $ws; }
    $ch = curl_init('https://api.anthropic.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $cuerpoC, CURLOPT_HTTPHEADER => $hdrC,
        CURLOPT_TIMEOUT => 30,
    ]);
    $rawC  = curl_exec($ch);
    $httpC = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errC  = curl_error($ch);
    curl_close($ch);
    echo "  http = $httpC\n";
    if ($rawC === false) {
        echo "  curl_error = $errC (el server no llega a api.anthropic.com)\n";
    } else {
        echo "  respuesta = " . substr((string)$rawC, 0, 500) . "\n";
        if ($httpC !== 200) {
            echo "  >> Claude rechazo la llamada: con esto el chat cae al respaldo Qwen.\n";
            if ($httpC === 401) { echo "  >> Es la KEY de Anthropic (invalida o revocada).\n"; }
            if ($httpC === 400 && $ws === '') {
                echo "  >> Si la key es a nivel ORGANIZACION, falta ANTHROPIC_WORKSPACE_ID.\n";
            }
        }
    }
}

// (si no hay clave de Qwen, abajo se saltean SUS pruebas pero el chequeo de
// libs corre igual: un "Cannot redeclare" rompe el chat tambien con Claude)
$hayQwenUsable = ($key !== '' && strlen($key) >= 20);

// ---------------------------------------------------------------------------
// LLAMADA REAL a Qwen, igual que ia_chat() en chatbot.php.
// ---------------------------------------------------------------------------
function probar_qwen(string $base, string $modelo, string $key, bool $conTools): void
{
    $body = [
        'model'       => $modelo,
        'messages'    => [['role' => 'user', 'content' => 'Respondé solo con la palabra: hola']],
        'temperature' => 0.3,
        'max_tokens'  => 20,
    ];
    if ($conTools) {
        $body['tools'] = [[
            'type' => 'function',
            'function' => [
                'name' => 'ping',
                'description' => 'herramienta de prueba',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['x' => ['type' => 'string', 'description' => 'x']],
                    'required' => [],
                ],
            ],
        ]];
    }

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "  http = $http\n";
    if ($raw === false) {
        echo "  curl_error = $err\n";
        echo "  (http=0 + error => el server no llega a $base -- salida bloqueada o DNS)\n";
        return;
    }
    echo "  respuesta = " . substr($raw, 0, 900) . "\n";
    $d = json_decode($raw, true);
    if ($http !== 200) {
        $msg = $d['error']['message'] ?? ($d['message'] ?? '(sin campo error.message)');
        echo "  >> Qwen rechazo la llamada: " . (is_string($msg) ? $msg : json_encode($msg)) . "\n";
        if (stripos((string)$msg, 'api key') !== false || $http === 401) {
            echo "  >> Es la KEY. Poné una QWEN_API_KEY valida en config.local.php.\n";
        }
    }
}

if ($hayQwenUsable) {
    echo "\n=== LLAMADA A QWEN (el respaldo, sin tools) ===\n";
    probar_qwen($base, $modelo, $key, false);

    echo "\n=== LLAMADA A QWEN (el respaldo, con tools como el chat real) ===\n";
    probar_qwen($base, $modelo, $key, true);
} else {
    echo "\n(sin clave de Qwen usable: se saltean las pruebas del respaldo)\n";
}

// ---------------------------------------------------------------------------
// Cargar las MISMAS libs que chatbot.php, en el mismo orden. Un "Cannot
// redeclare" o un archivo faltante despues de un deploy a medias aparece aca
// como un fatal con nombre y linea, en vez de un 502 mudo.
// ---------------------------------------------------------------------------
echo "\n=== CARGA DE LIBS DEL CHAT ===\n";
$libs = ['db.php', 'recargas_lib.php', 'fichas_lib.php', 'altas_lib.php',
         'config_crm.php', 'meta_lib.php', 'publicidad_lib.php', 'crm_lib.php',
         'telegram_lib.php', 'referidos_lib.php', 'notificaciones_lib.php',
         'actividad_lib.php', 'chatbot_contexto.php'];
foreach ($libs as $l) {
    $ruta = __DIR__ . '/' . $l;
    if (!is_file($ruta)) { echo "  $l : NO ESTA subido\n"; continue; }
    try {
        require_once $ruta;
        echo "  $l : ok\n";
    } catch (Throwable $e) {
        echo "  $l : FATAL -> " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
    }
}

// ---------------------------------------------------------------------------
// El final: volcar las ultimas lineas del error_log del server. Ahi queda el
// motivo de un fatal que mato al worker (memory, segfault, redeclare) y que no
// se ve en ninguna respuesta HTTP.
// ---------------------------------------------------------------------------
volcar_log();

function volcar_log(): void
{
    echo "\n=== ULTIMAS LINEAS DEL error_log (si se puede leer) ===\n";
    $candidatos = array_filter([
        ini_get('error_log') ?: null,
        '/var/log/php-fpm/error.log',
        '/var/log/php8.3-fpm.log',
        '/var/log/php_errors.log',
        __DIR__ . '/../error_log',
        __DIR__ . '/error_log',
    ]);
    $vistos = 0;
    foreach ($candidatos as $ruta) {
        if ($ruta && is_file($ruta) && is_readable($ruta)) {
            echo "--- $ruta ---\n";
            $lineas = @file($ruta) ?: [];
            foreach (array_slice($lineas, -25) as $ln) { echo "  " . rtrim($ln) . "\n"; }
            $vistos++;
        }
    }
    if (!$vistos) {
        echo "  (no pude leer ningun error_log; el de php-fpm suele necesitar root)\n";
        echo "  En el VPS, mira a mano:  tail -n 40 /var/log/php*fpm*.log\n";
    }
    echo "\nListo. Copiame TODA esta salida. Y borra este archivo del server cuando termines.\n";
}
