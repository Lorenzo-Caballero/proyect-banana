<?php
/**
 * landing_publica.php — Config pública de una landing (para landing/lp.html).
 *
 * GET ?slug=<slug>  ->  { ok, landing: { slug, plantilla, bono_pct, config } }
 *
 * SIN auth a propósito: esto es lo que la página publicada le muestra a
 * cualquier visitante de todos modos (colores, textos, el % del bono). Lo que
 * NO sale de acá: el nombre interno, ids, ni nada del resto del CRM. Una
 * landing pausada responde 404, igual que una inexistente — pausar ES
 * despublicar.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/landings_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// La config casi no cambia y esta URL la pega cada visitante que entra por un
// anuncio: 60s de cache le saca la carga al PHP sin que un cambio de estética
// tarde en notarse más que eso.
header('Cache-Control: public, max-age=60');

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '' || !preg_match('/^[a-z0-9-]{1,24}$/', $slug)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el slug']);
    exit;
}

$l = landings_por_slug($pdo, $slug);   // solo activas: pausada = 404
if (!$l) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Esa página no existe']);
    exit;
}

echo json_encode(['ok' => true, 'landing' => [
    'slug'      => $l['slug'],
    'plantilla' => $l['plantilla'],
    'bono_pct'  => (int)$l['bono_pct'],
    'config'    => landings_config_completa((string)$l['plantilla'], $l['config']),
]], JSON_UNESCAPED_UNICODE);
