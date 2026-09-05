<?php
/**
 * Diagnostico del chatbot. Abrilo en el NAVEGADOR (pasa el WAF/Cloudflare):
 *   https://ganamoscrm.online/gp-api/chatbot_diag.php?clave=ver-chatbot
 *
 * Prueba el camino REAL del chat (Qwen, con la MISMA resolucion de key que
 * chatbot.php) y muestra el error exacto que provoca el 502 -- incluido un
 * fatal que no se ve en la respuesta porque mata al worker de PHP (ahi
 * Cloudflare devuelve su "error code: 502" y el motivo real queda solo en el
 * error_log del server, que este archivo tambien vuelca).
 *
 * BORRALO del server cuando termines.
 */

require __DIR__ . '/config.php';

$ok = isset($_GET['clave']) && $_GET['clave'] === 'ver-chatbot';
if (!$ok) { http_response_code(404); exit; }

header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Las mismas constantes que usa chatbot.php, por si este diag corre suelto.
if (!defined('QWEN_BASE_DEF')) {
    define('QWEN_BASE_DEF', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1');
}
if (!defined('QWEN_MODEL_DEF')) {
    define('QWEN_MODEL_DEF', 'qwen-vl-max');
}

echo "=== ENTORNO ===\n";
echo "PHP          : " . PHP_VERSION . "\n";
echo "curl         : " . (function_exists('curl_init') ? 'si' : 'NO (ese es el problema)') . "\n";
echo "openssl      : " . (extension_loaded('openssl') ? 'si' : 'NO') . "\n";
echo "memory_limit : " . ini_get('memory_limit') . "\n";
echo "error_log    : " . (ini_get('error_log') ?: '(el del php-fpm/servidor)') . "\n";

// ---------------------------------------------------------------------------
// La KEY, resuelta IGUAL que chatbot.php: QWEN_API_KEY y, si falta, la vieja
// COHERE_API_KEY. Ese fallback es la trampa mas probable: si en el server solo
// esta la key de Cohere, se le manda a Qwen y Qwen la rechaza (401).
// ---------------------------------------------------------------------------
$keyQwen   = (string)cfg('QWEN_API_KEY');
$keyCohere = (string)cfg('COHERE_API_KEY');
$key = $keyQwen !== '' ? $keyQwen : $keyCohere;
$cual = $keyQwen !== '' ? 'QWEN_API_KEY' : ($keyCohere !== '' ? 'COHERE_API_KEY (fallback viejo)' : 'NINGUNA');

$base   = rtrim((string)cfg('QWEN_BASE_URL', QWEN_BASE_DEF), '/');
$modelo = (string)cfg('QWEN_MODEL', QWEN_MODEL_DEF);

echo "\n=== CONFIG DEL MODELO ===\n";
echo "QWEN_API_KEY   : " . ($keyQwen !== '' ? 'cargada (' . strlen($keyQwen) . ' chars)' : 'VACIA') . "\n";
echo "COHERE_API_KEY : " . ($keyCohere !== '' ? 'cargada (' . strlen($keyCohere) . ' chars)' : 'VACIA') . "\n";
echo "SE USA         : $cual\n";
echo "QWEN_BASE_URL  : $base\n";
echo "QWEN_MODEL     : $modelo\n";

if ($keyQwen === '' && $keyCohere !== '') {
    echo "\n  >> OJO: no hay QWEN_API_KEY y se esta cayendo a la de Cohere.\n";
    echo "     Esa key NO sirve contra Qwen (DashScope): da 401 y el chat 502.\n";
    echo "     Arreglo: agregar 'QWEN_API_KEY' => 'sk-...' en api/config.local.php\n";
}
if ($key === '' || strlen($key) < 20) {
    echo "\n=> No hay una key usable. Pone QWEN_API_KEY en api/config.local.php y listo.\n";
    volcar_log();
    exit;
}

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

echo "\n=== LLAMADA A QWEN (sin tools) ===\n";
probar_qwen($base, $modelo, $key, false);

echo "\n=== LLAMADA A QWEN (con tools, como el chat real) ===\n";
probar_qwen($base, $modelo, $key, true);

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
