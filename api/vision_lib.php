<?php
/**
 * vision_lib.php — Lectura de comprobantes de transferencia con IA de visión.
 *
 * No es un endpoint: lo usa chatbot.php (herramienta verificar_comprobante).
 * El jugador sube la FOTO del comprobante al chat (subir.php ya la guarda en
 * api/uploads/); esta lib se la manda a Claude (Anthropic) y saca los datos:
 * monto, titular que transfirió, número de operación, banco/billetera.
 *
 * SEGURIDAD — regla de oro: la imagen solo DECLARA, nunca CONFIRMA. Un
 * comprobante se puede editar con cualquier app; el que confirma la plata es
 * el mail del banco (colector -> pagos.php). Por eso lo extraído acá termina
 * en rl_declarar_pago(), que acredita ÚNICAMENTE si existe un pago real
 * (tabla pagos) que coincida. Si el pago todavía no llegó, la declaración
 * queda guardada y el matcher la usa cuando llegue el mail.
 *
 * CONFIG: 'ANTHROPIC_API_KEY' en api/config.local.php (de console.anthropic.com).
 * Sin la key, vision_disponible() da false y el chatbot pide los datos por
 * texto (informar_transferencia) — todo lo demás sigue funcionando.
 */

declare(strict_types=1);

// Modelo con visión, rápido y barato: un comprobante es una imagen simple.
const VISION_MODELO = 'claude-haiku-4-5-20251001';
// Límite de la API de Anthropic por imagen (5 MB); subir.php ya corta en 8 MB.
const VISION_MAX_BYTES = 5 * 1024 * 1024;

/** ¿Está configurada la key de Anthropic? */
function vision_disponible(): bool
{
    return function_exists('cfg') && strlen((string)cfg('ANTHROPIC_API_KEY', '')) > 20;
}

/**
 * Convierte "monto" en cualquier formato que devuelva el modelo (o que diga un
 * comprobante) a float en pesos: "1.500,50", "$ 1500.50", 1500.5 -> 1500.50.
 * Devuelve null si no hay número.
 */
function vision_monto_a_float($v): ?float
{
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/[^\d.,]/', '', $s);
    if ($s === '' || $s === null) {
        return null;
    }
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        // formato argentino: punto de miles, coma decimal
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    } elseif (substr_count($s, '.') > 1) {
        // "1.500.000" -> puntos de miles
        $s = str_replace('.', '', $s);
    } elseif (preg_match('/\.\d{3}$/', $s) && strlen($s) > 4) {
        // "1.500" solo: mucho más probable $1500 que $1.50
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Lee un comprobante (imagen local) y devuelve los datos extraídos.
 *
 * Devuelve:
 *   ['ok'=>true, 'datos'=>[
 *       'es_comprobante'  => bool,   // ¿la imagen ES un comprobante?
 *       'monto'           => float|null,
 *       'remitente'       => string, // titular de la cuenta que TRANSFIRIÓ
 *       'destinatario'    => string, // titular que RECIBIÓ (para chequear que sea nuestra cuenta)
 *       'nro_transaccion' => string, // solo dígitos (mismo formato que pagos.id_unico)
 *       'fecha'           => string,
 *       'entidad'         => string, // banco o billetera
 *   ]]
 *   o ['ok'=>false, 'codigo'=>..., 'error'=>...] con mensaje apto para el bot.
 */
function vision_extraer_comprobante(string $ruta): array
{
    if (!vision_disponible()) {
        return ['ok' => false, 'codigo' => 'sin_api', 'error' =>
            'La lectura automatica de comprobantes no esta configurada. '
            . 'Pedile los datos por texto: titular de su cuenta y numero de operacion.'];
    }
    if (!is_file($ruta)) {
        return ['ok' => false, 'codigo' => 'sin_archivo', 'error' =>
            'No encontre el archivo del comprobante. Que lo vuelva a subir.'];
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $ruta);
            finfo_close($fi);
        }
    }
    if ($mime === 'application/pdf') {
        return ['ok' => false, 'codigo' => 'pdf', 'error' =>
            'El comprobante llego en PDF. Pedile una FOTO o captura de pantalla '
            . 'del comprobante (imagen), que asi lo puedo leer.'];
    }
    $permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $permitidos, true)) {
        return ['ok' => false, 'codigo' => 'formato', 'error' =>
            'Ese archivo no es una imagen que pueda leer. Pedile una foto o '
            . 'captura del comprobante (JPG o PNG).'];
    }
    if (filesize($ruta) > VISION_MAX_BYTES) {
        return ['ok' => false, 'codigo' => 'muy_grande', 'error' =>
            'La imagen es muy pesada para leerla. Pedile una captura de pantalla '
            . 'del comprobante (pesa menos que la foto).'];
    }

    $b64 = base64_encode((string)file_get_contents($ruta));

    $instrucciones = <<<TXT
Extraé los datos de este comprobante de transferencia bancaria argentino.
Respondé SOLO este JSON (sin markdown, sin texto extra):
{"es_comprobante": true/false,
 "monto": número en pesos con punto decimal (ej 1500.50) o null,
 "remitente": "nombre del titular de la cuenta que ENVIÓ la plata" o "",
 "destinatario": "nombre del titular que RECIBIÓ" o "",
 "nro_transaccion": "número de operación/transacción/comprobante tal cual figura" o "",
 "fecha": "fecha y hora que figura" o "",
 "entidad": "banco o billetera del emisor" o ""}
Reglas: es_comprobante=false si la imagen NO es un comprobante de transferencia
(un meme, un chat, una foto cualquiera). No inventes datos: si un campo no se
lee, va vacío o null. El monto es el importe transferido, no el saldo.
TXT;

    $payload = json_encode([
        'model'      => VISION_MODELO,
        'max_tokens' => 400,
        'system'     => 'Sos un extractor de datos de comprobantes bancarios. '
                      . 'Respondés únicamente JSON válido, nada más.',
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $mime, 'data' => $b64,
                ]],
                ['type' => 'text', 'text' => $instrucciones],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . cfg('ANTHROPIC_API_KEY'),
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 45,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http !== 200) {
        error_log('vision_extraer_comprobante: HTTP ' . $http . ' ' . $err . ' ' . substr((string)$raw, 0, 300));
        return ['ok' => false, 'codigo' => 'api', 'error' =>
            'No pude leer el comprobante en este momento. Pedile los datos por '
            . 'texto: titular de su cuenta y numero de operacion.'];
    }

    $data  = json_decode((string)$raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') {
            $texto .= (string)($b['text'] ?? '');
        }
    }
    // Por si el modelo igual envolvió el JSON en ```...```
    $texto = trim($texto);
    $texto = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $texto);
    // Y por si agregó una frase antes/después: quedarse con el {...} exterior
    $ini = strpos($texto, '{');
    $fin = strrpos($texto, '}');
    if ($ini !== false && $fin !== false && $fin > $ini) {
        $texto = substr($texto, $ini, $fin - $ini + 1);
    }
    $d = json_decode($texto, true);
    if (!is_array($d)) {
        error_log('vision_extraer_comprobante: respuesta no-JSON: ' . substr($texto, 0, 300));
        return ['ok' => false, 'codigo' => 'parseo', 'error' =>
            'No pude interpretar el comprobante. Pedile los datos por texto: '
            . 'titular de su cuenta y numero de operacion.'];
    }

    return ['ok' => true, 'datos' => [
        'es_comprobante'  => !empty($d['es_comprobante']),
        'monto'           => vision_monto_a_float($d['monto'] ?? null),
        'remitente'       => mb_substr(trim((string)($d['remitente'] ?? '')), 0, 120),
        'destinatario'    => mb_substr(trim((string)($d['destinatario'] ?? '')), 0, 120),
        // solo dígitos: mismo formato que el id_unico que genera el colector
        'nro_transaccion' => substr(preg_replace('/\D+/', '', (string)($d['nro_transaccion'] ?? '')), 0, 64),
        'fecha'           => mb_substr(trim((string)($d['fecha'] ?? '')), 0, 60),
        'entidad'         => mb_substr(trim((string)($d['entidad'] ?? '')), 0, 60),
    ]];
}
