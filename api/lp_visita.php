<?php
/**
 * lp_visita.php — Registra UNA visita a una landing del CRM (lp.html).
 *
 * POST { slug, vid }  ->  { ok, contada }
 *
 * SIN auth a propósito: lo llama el navegador de cualquier visitante al abrir
 * lp.html, igual que crear_cuenta.php. No devuelve nada del CRM.
 *
 * Existe porque el pageview de una landing NO puede venir de Meta: Insights da
 * las visitas de una CUENTA DE ANUNCIOS, y una landing es una URL suelta (ver
 * la migración 54). Así que las contamos nosotros: el embudo (crm_publicidad,
 * segmento landing) cuenta estas filas por fecha, y de ahí sale la conversión
 * visita -> registro.
 *
 * DEDUP: una visita = una persona que abrió la página, no cada F5. La lógica
 * está en la UNIQUE (slug, visita_id, dia) de la tabla + INSERT IGNORE; acá
 * solo se sanea y se inserta. Un ping repetido no es un error: no cuenta.
 *
 * Best-effort de punta a punta: si falta la migración 52 (landings) o la 54
 * (visitas), responde ok sin contar, nunca un 500. La landing no puede romperse
 * porque el contador de visitas no esté listo.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usá POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$slug = strtolower(trim((string)($body['slug'] ?? '')));

// Mismo alfabeto que el slug de una landing (migración 52). Un slug inválido
// no se registra: no inflamos la tabla con basura del querystring.
if (!preg_match('/^[a-z0-9-]{1,24}$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'slug inválido']);
    exit;
}

// El id de visita lo genera el navegador y solo sirve para deduplicar. Se
// sanea a un token corto; si no vino (localStorage bloqueado), se genera uno
// por request -- esa persona puede contar como varias, nunca de menos.
$vid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($body['vid'] ?? ''));
$vid = substr((string)$vid, 0, 40);
if ($vid === '') {
    $vid = bin2hex(random_bytes(8));
}

// La landing tiene que existir. Sin la tabla `landings` (migración 52 sin
// correr) no hay landings que visitar: se responde ok sin contar.
try {
    $st = $pdo->prepare("SELECT 1 FROM landings WHERE slug = ? LIMIT 1");
    $st->execute([$slug]);
    if (!$st->fetchColumn()) {
        echo json_encode(['ok' => true, 'contada' => false]);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => true, 'contada' => false]);
    exit;
}

// CURDATE()/reloj del SERVER, igual que altas.pedido_en: el embudo cuenta
// registros y visitas con el mismo reloj, así la conversión visita->registro
// cuadra. No se mezcla con time() de PHP (eso ya causó bugs en el proyecto).
try {
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO landing_visitas (slug, visita_id, dia)
         VALUES (?, ?, CURDATE())"
    );
    $ins->execute([$slug, $vid]);
    echo json_encode(['ok' => true, 'contada' => $ins->rowCount() > 0]);
} catch (Throwable $e) {
    // Sin la migración 54 la tabla no existe: la landing sigue andando, solo
    // que todavía no se cuentan las visitas.
    error_log('lp_visita: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'contada' => false]);
}
