<?php
/**
 * Reenviador de Hostinger -> VPS. (Fase 0, se activa EN EL CORTE.)
 *
 * Después del corte, la API real vive en el VPS. Este archivo hace que todo lo
 * que le siga pegando a Hostinger — sobre todo la APK, que está COMPILADA con la
 * URL de Hostinger y no se puede cambiar sin redistribuir — se reenvíe al VPS.
 * Así TODO escribe en la única base del VPS; sin esto, la APK seguiría
 * escribiendo en la base vieja de Hostinger y las dos bases divergirían.
 *
 * Cómo funciona: el .htaccess de al lado manda todos los /api/* acá; este script
 * rearma la ruta, reenvía método + headers + cuerpo al VPS por HTTPS, y devuelve
 * la respuesta tal cual. Un salto extra de red, nada más.
 *
 * Es TEMPORAL: cuando la APK se actualice para pegarle directo al VPS, esto sale.
 */

// A dónde va la API de verdad: el `location ^~ /gp-api/` del nginx del VPS.
const VPS_API = 'https://ganamos.faunotattoo.com/gp-api/';

// --- 1) qué archivo pidieron: /api/chatbot.php -> chatbot.php ---
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$path = preg_replace('#^.*?/api/#', '', $uri);   // saca todo hasta /api/ (incl.)
$path = ltrim((string) $path, '/');
if ($path === '' || $path === '_forward.php') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'ruta vacia']);
    exit;
}

$destino = VPS_API . $path;
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs !== '') {
    $destino .= '?' . $qs;
}

// --- 2) armar el reenvío ---
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ct     = $_SERVER['CONTENT_TYPE'] ?? '';
$esMultipart = stripos($ct, 'multipart/form-data') !== false;

$ch = curl_init($destino);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $metodo,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => false,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
]);

// Headers a pasar. La X-API-Key es la que autentica del otro lado, así que es
// la más importante. El Content-Type NO se reenvía en multipart: curl arma uno
// nuevo con su propio boundary y mandar el viejo rompería el parseo.
$headers = [];
if ($ct !== '' && !$esMultipart) {
    $headers[] = 'Content-Type: ' . $ct;
}
foreach ([
    'HTTP_X_API_KEY'     => 'X-API-Key',
    'HTTP_AUTHORIZATION' => 'Authorization',
    'HTTP_ACCEPT'        => 'Accept',
] as $sv => $h) {
    if (!empty($_SERVER[$sv])) {
        $headers[] = $h . ': ' . $_SERVER[$sv];
    }
}
// Que el VPS vea la IP real del jugador, no la de Hostinger.
if (!empty($_SERVER['REMOTE_ADDR'])) {
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
}
$headers[] = 'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'ganamos-forward');

// Cuerpo.
if ($metodo !== 'GET' && $metodo !== 'HEAD') {
    if ($esMultipart) {
        // subir.php: rearmar campos + archivo para que curl haga el multipart.
        $campos = $_POST;
        foreach ($_FILES as $name => $f) {
            if (isset($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
                $campos[$name] = new CURLFile($f['tmp_name'], $f['type'] ?? '', $f['name'] ?? 'archivo');
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $campos);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// --- 3) reenviar y devolver la respuesta ---
$respuesta = curl_exec($ch);
$codigo    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctResp    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err       = curl_error($ch);
curl_close($ch);

if ($respuesta === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'no pude contactar al VPS', 'detalle' => $err]);
    exit;
}

http_response_code($codigo ?: 502);
if ($ctResp) {
    header('Content-Type: ' . $ctResp);
}
echo $respuesta;
