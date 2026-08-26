<?php
/**
 * meta_lib.php — Eventos a Meta Ads por Conversions API (server-side).
 *
 * No es un endpoint: son funciones que llaman chatbot.php, fichas_lib.php y
 * crear_cuenta.php cuando pasa algo que a la campaña le importa.
 *
 * POR QUE SERVER-SIDE Y NO SOLO EL PIXEL
 * El Pixel vive en el navegador: lo bloquea cualquier adblocker, iOS le recorta
 * la atribución, y sobre todo NO SABE lo que pasó de verdad. Una carga de
 * fichas se confirma en nuestro backend minutos después de que el jugador la
 * pidió; el navegador ya se fue. Un Purchase disparado desde el browser cuando
 * el jugador "pide" la carga miente: optimiza la campaña contra intenciones,
 * no contra plata que entró.
 *
 * DEDUPLICACIÓN
 * El mismo evento puede llegar por el Pixel y por acá. Meta los junta si
 * comparten `event_id`, y cuenta uno. Por eso el event_id se genera acá y se
 * guarda en `meta_eventos` con UNIQUE: si algo reintenta, el INSERT choca y no
 * se manda dos veces.
 *
 * NADA DE ESTO PUEDE ROMPER EL FLUJO
 * Si Meta no contesta, si falta el token, si la migración no corrió: se loguea
 * y se sigue. Que la campaña pierda un evento es molesto; que un jugador no
 * pueda cargar fichas porque Facebook está caído, no.
 *
 * Requiere las migraciones sql/38_config_crm.sql y sql/39_meta_ads.sql.
 */

declare(strict_types=1);

require_once __DIR__ . '/config_crm.php';

/** Versión de la Graph API. Meta mantiene cada una ~2 años. */
const META_API_VER = 'v21.0';

/**
 * Meta exige SHA-256 en minúsculas para los datos personales, y pide que el
 * valor venga normalizado ANTES de hashear (sin espacios, en minúscula) o el
 * match no engancha.
 */
function meta_hash(string $v): string
{
    $v = trim(mb_strtolower($v));
    return $v === '' ? '' : hash('sha256', $v);
}

/** Un id único por evento, para deduplicar contra el Pixel. */
function meta_event_id(string $evento, string $ref = ''): string
{
    // El ref (id de la carga, del alta) hace el id REPRODUCIBLE: si el mismo
    // hecho se reporta dos veces, sale el mismo id y Meta lo cuenta una sola
    // vez. Sin ref se cae a random, que sirve para eventos sin identidad
    // propia (un PageView).
    $semilla = $ref !== '' ? ($evento . ':' . $ref) : bin2hex(random_bytes(12));
    return substr(hash('sha256', $semilla), 0, 40);
}

/** ¿Está configurado y prendido? */
function meta_activo(PDO $pdo): bool
{
    return cfg_crm_activo($pdo, 'meta_activo')
        && trim((string)cfg_crm($pdo, 'meta_pixel_id')) !== ''
        && trim((string)cfg_crm($pdo, 'meta_capi_token')) !== '';
}

/**
 * Manda un evento a la Conversions API.
 *
 * $datos:
 *   usuario   string  nombre de jugador, si se conoce
 *   email     string  opcional
 *   telefono  string  opcional
 *   valor     float   Purchase / InitiateCheckout
 *   moneda    string  default ARS
 *   ref       string  id del hecho (carga, alta) -> event_id reproducible
 *   fbp/fbc   string  cookies del Pixel, si el front las mandó
 *   url       string  de dónde vino
 *
 * Devuelve el event_id, o '' si no se mandó nada.
 */
function meta_evento(PDO $pdo, string $evento, array $datos = []): string
{
    if (!meta_activo($pdo)) {
        return '';
    }
    // Cada evento se puede apagar por separado: una campaña que optimiza a
    // Purchase no quiere ruido de Contact, y al revés.
    $porEvento = [
        'Contact'              => 'meta_ev_contact',
        'CompleteRegistration' => 'meta_ev_registro',
        'InitiateCheckout'     => 'meta_ev_checkout',
        'Purchase'             => 'meta_ev_purchase',
    ];
    if (isset($porEvento[$evento]) && !cfg_crm_activo($pdo, $porEvento[$evento])) {
        return '';
    }

    $eventId = meta_event_id($evento, (string)($datos['ref'] ?? ''));
    $valor   = isset($datos['valor']) ? (float)$datos['valor'] : null;
    $moneda  = strtoupper(trim((string)($datos['moneda'] ?? 'ARS')));

    // Se registra ANTES de mandar. El UNIQUE de event_id es el candado: si este
    // hecho ya se reportó, el INSERT falla y salimos sin duplicar el evento en
    // Meta (que inflaría las conversiones de la campaña).
    try {
        $st = $pdo->prepare(
            "INSERT INTO meta_eventos (event_id, evento, usuario, valor, moneda)
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([
            $eventId, $evento,
            ($datos['usuario'] ?? '') !== '' ? mb_substr((string)$datos['usuario'], 0, 64) : null,
            $valor, $valor !== null ? $moneda : null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return $eventId;   // ya reportado: no es un error
        }
        error_log('meta: no pude registrar el evento (¿falta la migración 39?): ' . $e->getMessage());
        return '';
    }

    // ---- armado del payload ----
    $userData = [];
    // `external_id` es el identificador propio: sube bastante la calidad del
    // match aunque no tengamos mail ni teléfono, que es el caso normal acá.
    if (!empty($datos['usuario'])) {
        $userData['external_id'] = meta_hash((string)$datos['usuario']);
    }
    if (!empty($datos['email']))    { $userData['em']  = meta_hash((string)$datos['email']); }
    if (!empty($datos['telefono'])) { $userData['ph']  = meta_hash((string)$datos['telefono']); }
    // fbp/fbc son las cookies del Pixel: sin ellas Meta no puede atar el evento
    // al click del anuncio, y la atribución se pierde.
    if (!empty($datos['fbp'])) { $userData['fbp'] = (string)$datos['fbp']; }
    if (!empty($datos['fbc'])) { $userData['fbc'] = (string)$datos['fbc']; }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ip !== '') { $userData['client_ip_address']  = $ip; }
    if ($ua !== '') { $userData['client_user_agent']  = $ua; }

    $ev = [
        'event_name'       => $evento,
        'event_time'       => time(),
        'event_id'         => $eventId,
        'action_source'    => 'website',
        'user_data'        => $userData,
    ];
    if (!empty($datos['url'])) { $ev['event_source_url'] = (string)$datos['url']; }
    if ($valor !== null) {
        $ev['custom_data'] = ['value' => round($valor, 2), 'currency' => $moneda];
    }

    $payload = ['data' => [$ev]];
    $testCode = trim((string)cfg_crm($pdo, 'meta_test_code'));
    if ($testCode !== '') {
        // Con esto los eventos caen en "Eventos de prueba" del Administrador y
        // NO cuentan para la campaña. Dejarlo cargado en producción es el error
        // clásico: se ven los eventos y no optimizan nada.
        $payload['test_event_code'] = $testCode;
    }

    meta_enviar($pdo, $eventId, $payload);
    return $eventId;
}

/**
 * POST a la Graph API. Best-effort: cualquier problema queda en la fila del
 * evento y en el log, nunca sube al caller.
 */
function meta_enviar(PDO $pdo, string $eventId, array $payload): void
{
    $pixel = trim((string)cfg_crm($pdo, 'meta_pixel_id'));
    $token = trim((string)cfg_crm($pdo, 'meta_capi_token'));
    $url   = 'https://graph.facebook.com/' . META_API_VER . '/' . rawurlencode($pixel) . '/events';

    $estado = 'error';
    $resp   = '';
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(
                $payload + ['access_token' => $token], JSON_UNESCAPED_UNICODE
            ),
            // Corto a propósito: esto corre DENTRO del pedido del jugador. Que
            // Meta tarde no puede hacer esperar a alguien que está cargando
            // fichas.
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $resp = 'curl: ' . $err;
        } else {
            $resp   = mb_substr((string)$raw, 0, 800);
            $estado = ($http >= 200 && $http < 300) ? 'enviado' : 'error';
        }
    } catch (Throwable $e) {
        $resp = $e->getMessage();
    }

    if ($estado !== 'enviado') {
        error_log('meta: evento ' . $eventId . ' no entró -> ' . $resp);
    }
    try {
        $pdo->prepare("UPDATE meta_eventos SET estado = ?, respuesta = ? WHERE event_id = ?")
            ->execute([$estado, $resp, $eventId]);
    } catch (Throwable $e) {
        // Ni esto puede romper nada.
    }
}

/**
 * Lo que el navegador necesita para disparar el Pixel: id, si está prendido y
 * dónde corresponde el PageView. NUNCA devuelve el token de CAPI -- ese es de
 * servidor y en el HTML lo lee cualquiera.
 */
function meta_config_publica(PDO $pdo): array
{
    if (!cfg_crm_activo($pdo, 'meta_activo')) {
        return ['activo' => false];
    }
    $pixel = trim((string)cfg_crm($pdo, 'meta_pixel_id'));
    if ($pixel === '') {
        return ['activo' => false];
    }
    return [
        'activo'      => true,
        'pixel_id'    => $pixel,
        // registro | panel | ambos | off
        'pageview_en' => (string)cfg_crm($pdo, 'meta_pageview_en'),
    ];
}
