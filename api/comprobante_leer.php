<?php
/**
 * comprobante_leer.php — Lee un comprobante de transferencia con la IA y
 *                        devuelve los datos ya separados.
 *
 * PARA QUE SIRVE
 * Cuando un pago cae en revision, el operador tiene que abrir la imagen que
 * mando el jugador y copiar a mano el monto, el titular, el CUIT y el numero
 * de operacion para buscar la recarga. Esto se lo lee y se lo entrega
 * ordenado.
 *
 * ES UNA AYUDA, NO UNA PRUEBA. Lo que dice un modelo mirando una foto no
 * acredita nada: la plata se acredita cuando el colector captura el mail del
 * banco (ver recargas_lib.php). Una imagen se edita en dos minutos, y un
 * comprobante "perfecto" puede ser de una transferencia que nunca existio.
 * Por eso este endpoint solo LEE y muestra; jamas acredita ni asigna nada.
 *
 * POST { "url": "/api/uploads/xxxx.jpg" }   (o "archivo": mismo valor)
 *   -> { ok, datos:{...}, resumen:"...", alertas:[...] }
 *
 * Solo operadores (exigir_operador) y solo imagenes: un PDF no lo puede
 * mirar el modelo de vision, y se avisa en vez de fallar raro.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
$operador = exigir_operador();

function salir($d, int $c = 200): void
{
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'Usa POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$url  = trim((string)($body['url'] ?? $body['archivo'] ?? ''));
if ($url === '') {
    salir(['ok' => false, 'error' => 'Falta la url del comprobante'], 400);
}

/* Solo archivos de api/uploads/, y por su NOMBRE, nunca por la ruta que
   mandaron. Aceptar la ruta tal cual dejaria leer cualquier archivo del
   servidor con ../../ -- incluido config.local.php, que tiene las claves. */
$nombre = basename(parse_url($url, PHP_URL_PATH) ?: $url);
if ($nombre === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $nombre)) {
    salir(['ok' => false, 'error' => 'Nombre de archivo invalido'], 400);
}
$ruta = __DIR__ . '/uploads/' . $nombre;
if (!is_file($ruta)) {
    salir(['ok' => false, 'error' => 'No encuentro ese comprobante'], 404);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($ruta);
if (strpos($mime, 'image/') !== 0) {
    salir(['ok' => false, 'error' =>
        'Solo puedo leer imagenes. Si es un PDF, abrilo y sacale una captura.'], 400);
}
// El limite lo pone el modelo, no nosotros; 8 MB es el tope de subir.php.
$bytes = @file_get_contents($ruta);
if ($bytes === false) {
    salir(['ok' => false, 'error' => 'No pude leer el archivo'], 500);
}

$key = cfg('QWEN_API_KEY');
if ($key === '') { $key = cfg('COHERE_API_KEY'); }
if ($key === '' || strlen($key) < 20) {
    salir(['ok' => false, 'error' => 'La IA no esta configurada (falta QWEN_API_KEY)'], 500);
}

/* El prompt. Pide JSON y NO una tabla en markdown a proposito: asi el CRM
   puede usar los campos (autocompletar la busqueda del pago, comparar el
   monto contra la recarga) en vez de que el operador vuelva a copiar a mano
   lo que la IA acaba de leer -- que era justo el trabajo que queriamos
   sacarle. El `resumen` en castellano es para que ademas se lea de un
   vistazo.

   "No disponible" y no null cuando un dato no esta: obliga al modelo a decir
   que MIRO y no lo encontro, en vez de omitir la clave y dejar la duda de si
   se la salteo. */
$PROMPT = <<<TXT
Sos un analista de comprobantes de transferencias bancarias argentinas
(Mercado Pago, Cuenta DNI, Ualá, bancos, billeteras virtuales). Mirá la
imagen y extraé los datos.

Devolvé UNICAMENTE un JSON valido, sin texto antes ni despues, sin bloques
de codigo, con esta forma exacta:

{
  "tipo_documento": "comprobante de transferencia | factura | recibo | ticket | otro",
  "fecha": "fecha y hora de la operacion tal como figura",
  "monto": "solo el numero, con punto decimal si tiene centavos. Ej: 1000.47",
  "moneda": "ARS | USD | ...",
  "concepto": "motivo o descripcion, si figura",
  "remitente": {
    "nombre": "", "cuit": "", "cbu_cvu": "", "banco": ""
  },
  "destinatario": {
    "nombre": "", "cuit": "", "cbu_cvu": "", "banco": ""
  },
  "numero_operacion": "el ID o numero de la transaccion",
  "resumen": "Dos o tres frases en castellano rioplatense, como se lo contarias a un companero: quien le transfirio a quien, cuanto y cuando. Natural, sin sonar a robot ni a formulario.",
  "alertas": []
}

REGLAS QUE IMPORTAN:
- Si un dato NO figura en la imagen, poné exactamente "No disponible". Nunca
  lo inventes, ni lo deduzcas, ni lo completes con algo parecido. Un CUIT o un
  CBU inventado hace que se le acredite la plata a la persona equivocada.
- El "monto" va solo con numeros y punto decimal, sin simbolos ni separadores
  de miles: 1000.47, no "$ 1.000,47". Los CENTAVOS son importantisimos, no los
  redondees: son los que identifican de que recarga es el pago.
- En "alertas" poné, como frases sueltas, cualquier cosa que te haga ruido:
  que la imagen parezca editada o recortada, que falten datos clave, que el
  monto no se lea con seguridad, que sea una captura de una captura, o que el
  comprobante sea viejo. Si no ves nada raro, dejá la lista vacia.
- Si la imagen NO es un comprobante (una foto cualquiera, una captura del
  juego), poné "tipo_documento": "otro" y explicá en el resumen que no es un
  comprobante.
TXT;

$mensajes = [[
    'role' => 'user',
    'content' => [
        ['type' => 'image_url',
         'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
        ['type' => 'text', 'text' => $PROMPT],
    ],
]];

$base   = rtrim((string)cfg('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'), '/');
/* Su propio default, y NO hereda de QWEN_MODEL a proposito: el chat puede
   estar en un modelo de TEXTO (qwen-plus, qwen-max, que son mejores siguiendo
   procedimientos con herramientas) y ese no ve imagenes. Heredarlo dejaria
   este endpoint mandandole una foto a un modelo ciego. */
$modelo = (string)cfg('QWEN_MODEL_VISION', 'qwen-vl-max');

$ch = curl_init($base . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'    => $modelo,
        'messages' => $mensajes,
        // Temperatura 0: esto es extraccion de datos, no redaccion. No
        // queremos que "complete" lo que no se ve bien.
        'temperature' => 0,
        'max_tokens'  => 900,
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
    // Una imagen grande tarda: mas generoso que el chat.
    CURLOPT_TIMEOUT => 90,
]);
$raw  = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    error_log('comprobante_leer: ' . $err);
    salir(['ok' => false, 'error' => 'No pude contactar a la IA'], 502);
}
$data = json_decode($raw, true);
if ($http !== 200) {
    $detalle = $data['error']['message'] ?? ('HTTP ' . $http);
    error_log('comprobante_leer: ' . $detalle);
    salir(['ok' => false, 'error' => 'La IA no pudo leerlo: ' . $detalle], 502);
}

$texto = $data['choices'][0]['message']['content'] ?? '';
if (is_array($texto)) {   // modelos de vision a veces devuelven bloques
    $t = '';
    foreach ($texto as $b) { $t .= is_array($b) ? (string)($b['text'] ?? '') : (string)$b; }
    $texto = $t;
}
$texto = trim((string)$texto);

/* Pese al "solo JSON", los modelos suelen envolverlo en ```json. Se recorta
   entre la primera llave y la ultima en vez de exigir que venga limpio: es un
   detalle de formato, no vale fallar por eso y perder la lectura. */
$ini = strpos($texto, '{');
$fin = strrpos($texto, '}');
$datos = ($ini !== false && $fin !== false && $fin > $ini)
       ? json_decode(substr($texto, $ini, $fin - $ini + 1), true)
       : null;

if (!is_array($datos)) {
    // Se devuelve el texto igual: aunque no se pueda usar campo por campo,
    // al operador le sirve leerlo.
    salir(['ok' => true, 'datos' => null, 'resumen' => $texto,
           'alertas' => ['No pude separar los datos en campos; te dejo lo que leyó la IA.']]);
}

salir([
    'ok'       => true,
    'datos'    => $datos,
    'resumen'  => (string)($datos['resumen'] ?? ''),
    'alertas'  => is_array($datos['alertas'] ?? null) ? $datos['alertas'] : [],
    'operador' => $operador,
]);
