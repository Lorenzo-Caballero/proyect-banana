<?php
/**
 * mis_mensajes.php — El chat del sitio consulta si un AGENTE le respondió.
 *
 * GET  ?session_id=XXX&desde=<id>
 *   -> { ok, mensajes:[{id,texto,adjunto,creado_en}], ultimo_id }
 *
 * Solo devuelve mensajes con rol 'agente' (las respuestas humanas) de las
 * conversaciones de esa sesion, con id mayor a `desde`. Publico y acotado a la
 * propia sesion (cada navegador ve solo lo suyo).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sessionId = trim((string)($_GET['session_id'] ?? ''));
$desde = (int)($_GET['desde'] ?? 0);
if ($sessionId === '') {
    echo json_encode(['ok' => true, 'mensajes' => [], 'ultimo_id' => $desde]);
    exit;
}

try {
    $st = $pdo->prepare(
        "SELECT m.id, m.texto, m.meta, m.creado_en
         FROM mensajes m
         JOIN conversaciones c ON c.id = m.conversacion_id
         WHERE c.session_id = ? AND m.rol = 'agente' AND m.id > ?
         ORDER BY m.id ASC LIMIT 50"
    );
    $st->execute([$sessionId, $desde]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $msgs = [];
    $ultimo = $desde;
    foreach ($rows as $r) {
        $ultimo = (int)$r['id'];
        $meta = $r['meta'] ? json_decode($r['meta'], true) : null;
        if (is_array($meta) && !empty($meta['interno'])) {
            continue;   // nota interna del agente: no va al cliente
        }
        $msgs[] = [
            'id'        => (int)$r['id'],
            'texto'     => $r['texto'],
            'adjunto'   => (is_array($meta) && isset($meta['url'])) ? $meta : null,
            'creado_en' => $r['creado_en'],
        ];
    }
    echo json_encode(['ok' => true, 'mensajes' => $msgs, 'ultimo_id' => $ultimo], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mis_mensajes: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensajes' => [], 'ultimo_id' => $desde]);
}
