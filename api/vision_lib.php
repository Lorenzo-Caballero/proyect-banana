<?php
/**
 * vision_lib.php — Lectura de comprobantes de transferencia con IA de visión.
 *
 * No es un endpoint: lo usa chatbot.php (herramienta verificar_comprobante).
 * El jugador sube la FOTO/PDF del comprobante al chat (subir.php ya la guarda
 * en api/uploads/); esta lib extrae con IA los datos:
 * monto, titular que transfirió, número de operación, banco/billetera.
 *
 * SEGURIDAD — regla de oro: la imagen solo DECLARA, nunca CONFIRMA. Un
 * comprobante se puede editar con cualquier app; el dinero solo se confirma
 * con un movimiento bancario leído por el colector o un webhook verificado de
 * HG Cash. Por eso lo extraído acá termina en rl_declarar_pago(), que nunca
 * acredita por la imagen: solo puede casar con un pago real ya registrado.
 *
 * Usa la misma key y el mismo modelo GPT efectivo que el chat, tanto para
 * fotos como para PDFs. No depende de Anthropic.
 */

declare(strict_types=1);

// subir.php limita los adjuntos a 8 MB.
const VISION_MAX_BYTES = 8 * 1024 * 1024; // mismo tope que subir.php

/** ¿Está activo el mismo modelo GPT que usa el chat? */
function vision_disponible(): bool
{
    if (!function_exists('cfg')) { return false; }
    require_once __DIR__ . '/ia_key.php';
    return ia_chat_openai_activo(ia_chat_model_actual(), ia_key_openai());
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
 *       'cuit'            => string, // identificador de origen, para comparación interna
 *       'cbu_origen'      => string, // identificador de origen, para comparación interna
 *       'confianza'       => alta|media|baja,
 *       'alertas'         => array,
 *   ]]
 *   o ['ok'=>false, 'codigo'=>..., 'error'=>...] con mensaje apto para el bot.
 */
function vision_extraer_comprobante(string $ruta): array
{
    if (!vision_disponible()) {
        return ['ok' => false, 'codigo' => 'sin_api', 'error' =>
            'Recibi el comprobante, pero el lector automatico no esta disponible '
            . 'ahora. No le pidas que vuelva a transferir ni que reenvie el comprobante; '
            . 'deja el caso para revision de un agente.'];
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
    // Los bancos suelen compartir el comprobante como PDF. El widget y
    // subir.php ya lo aceptan; la API de OpenAI recibe el PDF como parte file.
    $esPdf = ($mime === 'application/pdf');
    $permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!$esPdf && !in_array($mime, $permitidos, true)) {
        return ['ok' => false, 'codigo' => 'formato', 'error' =>
            'Ese archivo no lo puedo leer. Pedile una foto, una captura de '
            . 'pantalla o el PDF del comprobante.'];
    }
    $tamano = (int)filesize($ruta);
    if ($tamano > VISION_MAX_BYTES) {
        return ['ok' => false, 'codigo' => 'muy_grande', 'error' =>
            'El archivo es muy pesado para leerlo. Pedile una captura de pantalla '
            . 'del comprobante (pesa menos que la foto).'];
    }

    $b64 = base64_encode((string)file_get_contents($ruta));

    $instrucciones = <<<TXT
Lee este comprobante de pago/transferencia (bancos, Mercado Pago, Cuenta DNI,
Uala, otras billeteras y recibos de HG Cash). Devuelve SOLO JSON valido, sin
markdown, con esta forma:
{"es_comprobante":true/false,"monto":numero_en_pesos_o_null,
"remitente":"titular que envio el dinero o vacio",
"cuit":"CUIT/CUIL del remitente, solo digitos, o vacio",
"cbu_origen":"CBU/CVU de origen, solo digitos si figura, o vacio",
"destinatario":"titular que recibio o vacio",
"nro_transaccion":"ID/numero de operacion tal como figura o vacio",
"fecha":"AAAA-MM-DD HH:MM (24 horas), o AAAA-MM-DD si no se ve hora, o vacio",
"entidad":"banco/billetera/proveedor o vacio","confianza":"alta|media|baja",
"alertas":[]}
No inventes ni completes datos. El monto es lo transferido, no el saldo. Si la
fecha es corta interpreta dia/mes/ano. Marca confianza baja y explica en
alertas si esta cortado, borroso, parece modificado o no es comprobante. La
imagen/PDF solo declara: NO demuestra que el dinero entro. No declares fraude.
TXT;

    // El mismo proveedor, modelo y clave que usa el chat. Chat Completions
    // acepta imagen_url para fotos y file parts para PDFs en modelos con vision.
    require_once __DIR__ . '/ia_key.php';
    $modelo = ia_chat_model_actual();
    $key = ia_key_openai();
    $nombre = basename($ruta);
    $adjunto = $esPdf
        ? ['type' => 'file', 'file' => [
            'filename' => preg_match('/\.pdf$/i', $nombre) ? $nombre : 'comprobante.pdf',
            'file_data' => 'data:application/pdf;base64,' . $b64,
        ]]
        : ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . $mime . ';base64,' . $b64,
            'detail' => 'high',
        ]];
    $payload = json_encode([
        'model' => $modelo,
        'messages' => [[
            'role' => 'user',
            'content' => [$adjunto, ['type' => 'text', 'text' => $instrucciones]],
        ]],
        'max_completion_tokens' => 700,
        'reasoning_effort' => 'none',
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http !== 200) {
        error_log('vision_extraer_comprobante: OpenAI modelo=' . $modelo . ' HTTP=' . $http . ' ' . $err . ' ' . substr((string)$raw, 0, 300));
        return ['ok' => false, 'codigo' => 'api', 'error' =>
            'Recibi el comprobante, pero el modelo del chat no pudo leerlo ahora. No vuelvas a transferir ni reenvies el archivo; un agente revisara el caso.'];
    }
    $data = json_decode((string)$raw, true);
    $contenido = $data['choices'][0]['message']['content'] ?? '';
    if (is_array($contenido)) {
        $texto = '';
        foreach ($contenido as $bloque) { $texto .= is_array($bloque) ? (string)($bloque['text'] ?? '') : (string)$bloque; }
    } else { $texto = (string)$contenido; }
    if (trim($texto) === '') {
        return ['ok' => false, 'codigo' => 'api', 'error' =>
            'Recibi el comprobante, pero el modelo del chat no devolvio una lectura. No vuelvas a transferir ni reenvies el archivo; un agente revisara el caso.'];
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
            'Recibi el comprobante, pero no pude separar sus datos con seguridad. No vuelvas a transferir ni lo reenvies; un agente revisara el archivo que ya mandaste.'];
    }

    $cuit = preg_replace('/\D+/', '', (string)($d['cuit'] ?? ''));
    $cbuOrigen = preg_replace('/\D+/', '', (string)($d['cbu_origen'] ?? ''));
    return ['ok' => true, 'datos' => [
        'es_comprobante'  => !empty($d['es_comprobante']),
        'monto'           => vision_monto_a_float($d['monto'] ?? null),
        'remitente'       => mb_substr(trim((string)($d['remitente'] ?? '')), 0, 120),
        'destinatario'    => mb_substr(trim((string)($d['destinatario'] ?? '')), 0, 120),
        // solo dígitos: mismo formato que el id_unico que genera el colector
        'nro_transaccion' => substr(preg_replace('/\D+/', '', (string)($d['nro_transaccion'] ?? '')), 0, 64),
        'fecha'           => mb_substr(trim((string)($d['fecha'] ?? '')), 0, 60),
        'entidad'         => mb_substr(trim((string)($d['entidad'] ?? '')), 0, 60),
        // Solo devolver identificadores de largo exacto. Truncar un OCR
        // dudoso podría convertirlo en el CUIT/CBU real de otra persona.
        'cuit'            => strlen($cuit) === 11 ? $cuit : '',
        'cbu_origen'      => strlen($cbuOrigen) === 22 ? $cbuOrigen : '',
        'confianza'       => in_array(($d['confianza'] ?? ''), ['alta', 'media', 'baja'], true)
                           ? (string)$d['confianza'] : 'media',
        'alertas'         => is_array($d['alertas'] ?? null) ? array_slice($d['alertas'], 0, 5) : [],
    ]];
}
