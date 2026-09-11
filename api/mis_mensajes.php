<?php
/**
 * mis_mensajes.php — El chat del sitio consulta si un AGENTE le respondió.
 *
 * GET  ?session_id=XXX&desde=<id>[&usuario=nombre][&visto=1]
 *   -> { ok, mensajes:[{id,texto,adjunto,creado_en,efimero}], ultimo_id,
 *        leido_user_en }
 *
 * visto=1 = el chat esta ABIERTO delante del jugador: lo entregado (y lo ya
 * entregado antes, id <= desde) se estampa como VISTO (mensajes.visto_en,
 * migracion 57). El CRM lo muestra como "Visto HH:MM" en cada mensaje.
 *
 * efimero (segundos, sale del meta) = aviso que se AUTODESTRUYE ese tiempo
 * despues de visto -- lo usan los avisos de bonos. El widget lo saca de la
 * pantalla, y ACA se borran de la base los vencidos de estas conversaciones
 * en cada sondeo (sin cron; el registro contable vive en `movimientos`).
 *
 * leido_user_en = cuando el agente vio por ultima vez los mensajes DEL
 * jugador (lo estampa crm.php al abrir la conversacion): el widget pinta
 * las tildes azules con esto.
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
$visto = (string)($_GET['visto'] ?? '') === '1';
if ($sessionId === '') {
    echo json_encode(['ok' => true, 'mensajes' => [], 'ultimo_id' => $desde]);
    exit;
}

try {
    // Los ids de conversacion que matchean esta sesion/usuario: se resuelven
    // UNA vez y los reusan la entrega, el visto y el barrido de efimeros.
    $cv = $pdo->prepare(
        "SELECT id FROM conversaciones WHERE session_id = ? OR (? <> '' AND usuario = ?)"
    );
    $cv->execute([$sessionId, $usuario, $usuario]);
    $convIds = array_map('intval', $cv->fetchAll(PDO::FETCH_COLUMN));

    if (!$convIds) {
        echo json_encode(['ok' => true, 'mensajes' => [], 'ultimo_id' => $desde]);
        exit;
    }
    $enConv = implode(',', $convIds);   // ints propios, seguro interpolar

    /* Barrido de EFIMEROS vencidos de estas conversaciones: un aviso (bono)
       visto hace mas que su vida util se borra de la base -- del CRM
       tambien, a proposito: es una confirmacion, no historial (el registro
       contable esta en `movimientos`). Acotado a estas conversaciones y a
       filas con visto_en, o sea un punado: no hace falta cron. Best-effort. */
    try {
        $ef = $pdo->query(
            "SELECT id, meta, visto_en FROM mensajes
              WHERE conversacion_id IN ($enConv)
                AND visto_en IS NOT NULL AND meta LIKE '%efimero%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $borrar = [];
        foreach ($ef as $e2) {
            $m2 = json_decode((string)$e2['meta'], true);
            $vida = is_array($m2) ? (int)($m2['efimero'] ?? 0) : 0;
            if ($vida > 0 && strtotime((string)$e2['visto_en']) + $vida <= time()) {
                $borrar[] = (int)$e2['id'];
            }
        }
        if ($borrar) {
            $pdo->exec("DELETE FROM mensajes WHERE id IN (" . implode(',', $borrar) . ")");
        }
    } catch (Throwable $e2) { /* sin migracion 57 o meta raro: se sigue */ }

    // ¿Corrió la migración 58 (mensajes eliminados)? Sonda barata: sin la
    // columna, el filtro y las lápidas se saltean y todo sigue como antes.
    $hayBorrado = true;
    try { $pdo->query("SELECT borrado_en FROM mensajes LIMIT 0"); }
    catch (Throwable $e2) { $hayBorrado = false; }

    $st = $pdo->prepare(
        "SELECT m.id, m.texto, m.meta, m.creado_en
         FROM mensajes m
         WHERE m.conversacion_id IN ($enConv)
           AND (m.conversacion_id IN (SELECT id FROM conversaciones WHERE session_id = ?)
                OR m.creado_en >= NOW() - INTERVAL 1 DAY)
           AND m.rol = 'agente' AND m.id > ?"
           . ($hayBorrado ? " AND m.borrado_en IS NULL" : "") . "
         ORDER BY m.id ASC LIMIT 50"
    );
    $st->execute([$sessionId, $desde]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $msgs = [];
    $ultimo = $desde;
    $idsEntregados = [];
    foreach ($rows as $r) {
        $ultimo = (int)$r['id'];
        $meta = $r['meta'] ? json_decode($r['meta'], true) : null;
        if (is_array($meta) && !empty($meta['interno'])) {
            continue;   // nota interna del agente: no va al cliente
        }
        $idsEntregados[] = (int)$r['id'];
        $msgs[] = [
            'id'        => (int)$r['id'],
            'texto'     => $r['texto'],
            'adjunto'   => (is_array($meta) && isset($meta['url'])) ? $meta : null,
            'creado_en' => $r['creado_en'],
            'efimero'   => (is_array($meta) && (int)($meta['efimero'] ?? 0) > 0)
                           ? (int)$meta['efimero'] : 0,
        ];
    }

    /* VISTO: el chat esta abierto delante del jugador. Se estampa lo recien
       entregado Y lo entregado en sondeos anteriores (id <= desde) que quedo
       sin marcar -- el caso "llego con el chat cerrado y lo abrio despues".
       Best-effort: sin la migracion 57 no hay columna y no pasa nada. */
    if ($visto) {
        try {
            $marcar = $idsEntregados;
            $pdo->exec(
                "UPDATE mensajes SET visto_en = NOW()
                  WHERE conversacion_id IN ($enConv) AND rol = 'agente'
                    AND visto_en IS NULL
                    AND (id <= " . (int)$desde
                    . ($marcar ? " OR id IN (" . implode(',', $marcar) . ")" : "") . ")"
            );
        } catch (Throwable $e2) { /* sin migracion 57 */ }
    }

    /* Cuando el AGENTE vio por ultima vez los mensajes del jugador (lo
       estampa crm.php al abrir la conversacion): para las tildes azules. */
    $leidoUser = null;
    try {
        $leidoUser = $pdo->query(
            "SELECT MAX(visto_en) FROM mensajes
              WHERE conversacion_id IN ($enConv) AND rol = 'user' AND visto_en IS NOT NULL"
        )->fetchColumn() ?: null;
    } catch (Throwable $e2) { /* sin migracion 57 */ }

    /* LAPIDAS: mensajes eliminados desde el CRM en las ultimas 24 h. El
       widget saca esas burbujas de la pantalla y de la charla guardada del
       jugador que YA las habia recibido -- el "eliminar para todos".
       rol 'agente' Y 'bot': las respuestas de Camila llegan al widget por la
       respuesta del chatbot (no por este sondeo) pero se retraen por aca
       igual -- el widget ata cada burbuja del bot a su mensaje_id. */
    $borrados = [];
    if ($hayBorrado) {
        try {
            $borrados = array_map('intval', $pdo->query(
                "SELECT id FROM mensajes
                  WHERE conversacion_id IN ($enConv) AND rol IN ('agente', 'bot')
                    AND borrado_en IS NOT NULL
                    AND borrado_en >= NOW() - INTERVAL 1 DAY"
            )->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e2) { /* best-effort */ }
    }

    echo json_encode(['ok' => true, 'mensajes' => $msgs, 'ultimo_id' => $ultimo,
                      'leido_user_en' => $leidoUser,
                      'borrados' => $borrados], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mis_mensajes: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensajes' => [], 'ultimo_id' => $desde]);
}
