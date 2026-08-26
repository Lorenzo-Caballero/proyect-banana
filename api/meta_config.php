<?php
/**
 * meta_config.php — Lo que el navegador necesita para el Pixel. PUBLICO.
 *
 * Devuelve SOLO el pixel_id y dónde corresponde el PageView. El token de
 * Conversions API NUNCA sale de acá: con él, cualquiera podría mandarle
 * eventos falsos al pixel del cliente y arruinarle la optimización de la
 * campaña. Ese token vive en el server y lo usa meta_lib.php.
 *
 * Es público porque lo lee registro.html, que es una página sin sesión.
 *
 * GET -> { activo:false }  |  { activo:true, pixel_id, pageview_en }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/meta_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Cachea poco: si el agente prende el pixel, que se note en minutos y no al
// otro día. Pero algo cachea, porque esto lo pide cada visita.
header('Cache-Control: public, max-age=300');

try {
    echo json_encode(meta_config_publica($pdo), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('meta_config: ' . $e->getMessage());
    // Ante la duda, apagado: es mejor perder eventos que romper la página.
    echo json_encode(['activo' => false]);
}
