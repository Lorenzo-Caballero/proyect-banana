<?php
/**
 * mis_mensajes.php — El chat del sitio consulta si un AGENTE le respondió.
 *
 * GET  ?session_id=XXX&desde=<id>[&usuario=nombre]
 *   -> { ok, mensajes:[{id,texto,adjunto,creado_en}], ultimo_id }
 *
 * Solo devuelve mensajes con rol 'agente' (las respuestas humanas y los avisos
 * del sistema: difusiones, bonos acreditados) con id mayor a `desde`.
 *
 * DOS formas de matchear la conversacion, y las dos hacen falta:
 *
 *  - por session_id: la de siempre. PERO la conversacion guarda el sid del
 *    ultimo mensaje que el jugador ESCRIBIO: un aviso insertado por el sistema
 *    (el bono acreditado, una difusion) no le llegaba nunca a un jugador que
 *    abrio el chat en una sesion nueva y todavia no escribio nada -- el aviso
 *    quedaba en el CRM y el widget sondeaba contra un sid que no matcheaba.
 *  - por usuario (si el widget lo manda): entrega lo RECIENTE (24 h) de la
 *    conversacion de ese nombre, sin depender del sid. Mismo modelo de
 *    confianza que ya rige todo el chat: la conversacion es una por nombre y
 *    quien dice ser otro cae en el chat de ese otro (ver CLAUDE.md). El corte
 *    de 24 h evita volcar historiales enteros en un chat recien abierto.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sessionId = trim((string)($_GET['session_id'] ?? ''));
$usuario   = mb_substr(trim((string)($_GET['usuario'] ?? '')), 0, 50);
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
         WHERE (c.session_id = ?
                OR (? <> '' AND c.usuario = ?
                    AND m.creado_en >= NOW() - INTERVAL 1 DAY))
           AND m.rol = 'agente' AND m.id > ?
         ORDER BY m.id ASC LIMIT 50"
    );
    $st->execute([$sessionId, $usuario, $usuario, $desde]);
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
