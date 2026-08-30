<?php
/**
 * meta_pageview.php — PageView por Conversions API, PUBLICO.
 *
 * Complementa al PageView del Pixel (fbq('track','PageView')): si un
 * bloqueador de anuncios frena fbevents.js, el Pixel nunca se dispara, pero
 * este pedido SÍ llega (lo manda meta-pixel.js con fetch, no con el script
 * de Meta). El navegador manda el MISMO event_id que uso en el
 * fbq('track', 'PageView', {}, {eventID}) -- así Meta deduplica los dos
 * lados y no cuenta el PageView dos veces.
 *
 * Es público porque lo llama meta-pixel.js desde registro.html, que es una
 * página sin sesión. No hace falta CSRF: no cambia nada en la cuenta del
 * cliente, solo le reporta una visita a Meta.
 *
 * POST { event_id, pub?, fbp?, fbc? } -> { ok:true } | { ok:false }
 * Ante cualquier problema, ok:false silencioso -- la página no depende de
 * esta respuesta (fire-and-forget desde el navegador).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/meta_lib.php';
require __DIR__ . '/publicidad_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

    $eventId = trim((string)($body['event_id'] ?? ''));
    if ($eventId === '') {
        echo json_encode(['ok' => false]);
        exit;
    }

    $slug = trim((string)($body['pub'] ?? ''));
    $publicista = $slug !== '' ? publicidad_por_slug($pdo, $slug) : null;

    meta_evento($pdo, 'PageView', [
        'event_id' => $eventId,
        'fbp'      => (string)($body['fbp'] ?? ''),
        'fbc'      => (string)($body['fbc'] ?? ''),
        'pixel'    => publicidad_pixel_propio($publicista),
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('meta_pageview: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
