<?php
/**
 * Diagnostico del chatbot. Abrilo en el NAVEGADOR (pasa el WAF de Hostinger):
 *   https://TU-DOMINIO/gp-api/chatbot_diag.php?clave=ver-chatbot
 *
 * Prueba la conexion real con Cohere y muestra el error exacto que provoca el 502.
 * BORRALO del server cuando termines.
 */

require __DIR__ . '/config.php';

$ok = isset($_GET['clave']) && $_GET['clave'] === 'ver-chatbot';
if (!$ok) { http_response_code(404); exit; }

header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "=== ENTORNO ===\n";
echo "PHP     : " . PHP_VERSION . "\n";
echo "curl    : " . (function_exists('curl_init') ? 'si' : 'NO (ese es el problema)') . "\n";
echo "openssl : " . (extension_loaded('openssl') ? 'si' : 'NO') . "\n";

$key = cfg('COHERE_API_KEY');
$estadoKey = $key === '' ? 'VACIA'
    : (stripos($key, 'REEMPLAZA') !== false ? 'sigue el placeholder REEMPLAZA-ESTO'
    : (strlen($key) < 20 ? 'muy corta' : 'cargada (' . strlen($key) . ' chars)'));
echo "COHERE_API_KEY: $estadoKey\n";

if ($key === '' || strlen($key) < 20 || stripos($key, 'REEMPLAZA') !== false) {
    echo "\n=> Poné tu key real de Cohere en api/config.local.php (OPENAI... no, COHERE_API_KEY).\n";
    exit;
}

function probar_cohere(string $key, bool $conTools): void
{
    $body = [
        'model'    => 'command-r-08-2024',
        'messages' => [['role' => 'user', 'content' => 'Respondé solo con la palabra: hola']],
        'max_tokens' => 20,
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

    $ch = curl_init('https://api.cohere.com/v2/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
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
        echo "  (http=0 + error => el hosting bloquea la salida a api.cohere.com)\n";
    } else {
        echo "  respuesta = " . substr($raw, 0, 800) . "\n";
    }
}

echo "\n=== LLAMADA SIN TOOLS ===\n";
probar_cohere($key, false);

echo "\n=== LLAMADA CON TOOLS ===\n";
probar_cohere($key, true);

echo "\nListo. Copiame esta salida. Y acordate de borrar este archivo del server.\n";
