<?php
/**
 * crm.php — Backend del CRM de conversaciones.
 *
 * Acceso directo (sin login), igual que admin_usuarios.php.
 *
 * GET  ?accion=conversaciones&q=&estado=todas|abierta|pendiente|cerrada
 *        -> { ok, items:[{id,usuario,session_id,estado,preview,no_leidos,actualizada_en}], resumen }
 * GET  ?accion=conversacion&id=N
 *        -> { ok, conversacion, mensajes:[...], usuario:{...}|null, movimientos:[...] }
 *
 * POST { accion:"nota",         id, notas }
 * POST { accion:"estado",       id, estado }
 * POST { accion:"cargar_fichas",usuario, monto, motivo, conversacion_id? }
 * POST { accion:"cargar_bono",  usuario, monto, motivo, conversacion_id? }
 * POST { accion:"notificar",    usuario|todos, titulo, cuerpo, tipo? }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/notificaciones_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Trae la ficha del usuario desde `usuarios`: saldo, fichas (coins), bonos, flags. */
function ficha_usuario(PDO $pdo, string $usuario): ?array
{
    $st = $pdo->prepare(
        "SELECT id AS ganamos_id, username AS nombre_usuario,
                COALESCE(coins, 0)  AS fichas,
                COALESCE(bonus, 0)  AS bonus,
                COALESCE(balance,0) AS saldo,
                COALESCE(total_deposits,0) AS total_deposits,
                role, is_banned, tiene_app, notificaciones,
                creation_date, ultima_actividad
         FROM usuarios WHERE username = ? LIMIT 1"
    );
    $st->execute([$usuario]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }

    $r['fichas']         = (int)$r['fichas'];
    $r['bonus']          = (int)$r['bonus'];
    $r['saldo']          = (float)$r['saldo'];
    $r['total_deposits'] = (float)$r['total_deposits'];
    $r['is_banned']      = (bool)$r['is_banned'];
    $r['tiene_app']      = (bool)($r['tiene_app'] ?? false);
    $r['notificaciones'] = (bool)($r['notificaciones'] ?? false);
    $r['registrado_sitio'] = true;
    return $r;
}

function movimientos(PDO $pdo, string $usuario, int $limite = 30): array
{
    $st = $pdo->prepare("SELECT tipo, monto, motivo, origen, creado_en
                         FROM movimientos WHERE usuario = ?
                         ORDER BY creado_en DESC LIMIT $limite");
    $st->execute([$usuario]);
    return array_map(function ($m) {
        $m['monto'] = (int)$m['monto'];
        return $m;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** El contexto por defecto del chatbot (la constante compartida). Vacío si el
 *  archivo no está (no debería pasar, pero no rompe el CRM si falta). */
function crm_chatbot_default(): string
{
    $f = __DIR__ . '/chatbot_contexto.php';
    if (is_file($f)) { require_once $f; }
    return defined('CONTEXTO') ? CONTEXTO : '';
}

/** Lee la config del chatbot. Si la tabla no existe todavía (migración 26 sin
 *  correr), devuelve el default y activo=true, sin romper. */
function crm_chatbot_leer(PDO $pdo): array
{
    try {
        $row = $pdo->query("SELECT contexto, activo FROM config_chatbot WHERE id = 1 LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['contexto' => crm_chatbot_default(), 'activo' => true, 'usa_default' => true];
    }
    $ctx = $row ? trim((string)($row['contexto'] ?? '')) : '';
    return [
        // Si está vacío en la base, mostramos el default para que el agente lo
        // vea y edite desde ahí (guardar vacío = seguir usando el default).
        'contexto'   => $ctx !== '' ? $ctx : crm_chatbot_default(),
        'activo'     => $row ? ((int)($row['activo'] ?? 1) === 1) : true,
        'usa_default' => $ctx === '',
    ];
}

/** Guarda contexto (vacío = usar el default) y el flag activo. */
function crm_chatbot_guardar(PDO $pdo, string $contexto, int $activo): void
{
    // Si el texto es igual al default, guardamos NULL: así el cliente sigue el
    // default y futuras mejoras del prompt base le llegan sin re-guardar.
    $ctx = ($contexto === '' || $contexto === crm_chatbot_default()) ? null : $contexto;
    $pdo->prepare(
        "INSERT INTO config_chatbot (id, contexto, activo) VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE contexto = VALUES(contexto), activo = VALUES(activo)"
    )->execute([$ctx, $activo ? 1 : 0]);
}

// =============================== GET ========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'conversaciones');

    try {
        // ---- lista de conversaciones ----
        if ($accion === 'conversaciones') {
            $q      = trim((string)($_GET['q'] ?? ''));
            $estado = (string)($_GET['estado'] ?? 'todas');

            $where = [];
            $params = [];
            if ($q !== '') {
                $where[] = '(usuario LIKE ? OR session_id LIKE ? OR preview LIKE ?)';
                $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
            }
            if (in_array($estado, ['abierta', 'pendiente', 'cerrada'], true)) {
                $where[] = 'estado = ?';
                $params[] = $estado;
            }
            $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $st = $pdo->prepare(
                "SELECT id, session_id, usuario, estado, preview, no_leidos, fijada, actualizada_en
                 FROM conversaciones $wsql
                 ORDER BY fijada DESC, actualizada_en DESC LIMIT 200"
            );
            $st->execute($params);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['no_leidos'] = (int)$it['no_leidos'];
                $it['fijada'] = (bool)$it['fijada'];
            }
            unset($it);

            $res = $pdo->query(
                "SELECT COUNT(*) total,
                        SUM(estado='abierta')   abiertas,
                        SUM(estado='pendiente') pendientes,
                        SUM(no_leidos>0)        con_no_leidos
                 FROM conversaciones"
            )->fetch(PDO::FETCH_ASSOC);

            // Totales de la base (para las estadisticas del panel lateral).
            $g = $pdo->query(
                "SELECT COUNT(*) usuarios, COALESCE(SUM(balance),0) saldo,
                        COALESCE(SUM(coins),0) fichas, COALESCE(SUM(bonus),0) bonos
                 FROM usuarios"
            )->fetch(PDO::FETCH_ASSOC);

            salir(['ok' => true, 'items' => $items, 'resumen' => [
                'total'         => (int)$res['total'],
                'abiertas'      => (int)$res['abiertas'],
                'pendientes'    => (int)$res['pendientes'],
                'con_no_leidos' => (int)$res['con_no_leidos'],
                'usuarios'      => (int)$g['usuarios'],
                'saldo_interno' => (float)$g['saldo'],
                'fichas_total'  => (int)$g['fichas'],
                'bonos_total'   => (int)$g['bonos'],
            ]]);
        }

        // ---- una conversacion con su hilo + ficha del usuario ----
        if ($accion === 'conversacion') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $st = $pdo->prepare("SELECT * FROM conversaciones WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $conv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$conv) { salir(['ok' => false, 'error' => 'No existe'], 404); }
            $conv['id'] = (int)$conv['id'];
            $conv['no_leidos'] = (int)$conv['no_leidos'];
            $conv['fijada'] = (bool)($conv['fijada'] ?? false);

            // marcar como leida
            $pdo->prepare("UPDATE conversaciones SET no_leidos = 0 WHERE id = ?")->execute([$id]);

            $st = $pdo->prepare("SELECT rol, texto, meta, creado_en FROM mensajes
                                 WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
            $st->execute([$id]);
            $mensajes = array_map(function ($m) {
                $m['adjunto'] = $m['meta'] ? json_decode($m['meta'], true) : null;  // {tipo,url,nombre}
                unset($m['meta']);
                return $m;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            $usuario = trim((string)($conv['usuario'] ?? ''));
            $ficha = $usuario !== '' ? ficha_usuario($pdo, $usuario) : null;
            $movs  = $usuario !== '' ? movimientos($pdo, $usuario) : [];

            salir(['ok' => true, 'conversacion' => $conv, 'mensajes' => $mensajes,
                   'usuario' => $ficha, 'movimientos' => $movs]);
        }

        // ---- config del chatbot (contexto editable + on/off) ----
        if ($accion === 'chatbot_config') {
            $cfg = crm_chatbot_leer($pdo);
            salir(['ok' => true,
                   'contexto'         => $cfg['contexto'],
                   'activo'           => $cfg['activo'],
                   'contexto_default' => crm_chatbot_default(),
            ]);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar', 'detalle' => $e->getMessage()], 500);
    }
}

// =============================== POST =======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        // ---- notas internas ----
        if ($accion === 'nota') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $notas = mb_substr((string)($body['notas'] ?? ''), 0, 4000);
            $pdo->prepare("UPDATE conversaciones SET notas = ? WHERE id = ?")->execute([$notas, $id]);
            salir(['ok' => true]);
        }

        // ---- cambiar estado ----
        if ($accion === 'estado') {
            $id = (int)($body['id'] ?? 0);
            $estado = (string)($body['estado'] ?? '');
            if (!$id || !in_array($estado, ['abierta', 'pendiente', 'cerrada'], true)) {
                salir(['ok' => false, 'error' => 'Datos invalidos'], 400);
            }
            $pdo->prepare("UPDATE conversaciones SET estado = ? WHERE id = ?")->execute([$estado, $id]);
            salir(['ok' => true]);
        }

        // ---- cargar fichas o bono ----
        if ($accion === 'cargar_fichas' || $accion === 'cargar_bono') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $monto   = (int)($body['monto'] ?? 0);
            $motivo  = mb_substr((string)($body['motivo'] ?? ''), 0, 200);
            $tipo    = $accion === 'cargar_bono' ? 'bono' : 'ficha';
            if ($usuario === '' || $monto === 0) {
                salir(['ok' => false, 'error' => 'Falta usuario o monto (no puede ser 0)'], 400);
            }

            $r = crm_cargar($pdo, $usuario, $tipo, $monto, $motivo, 'crm');
            if (!$r['ok']) { salir($r, 400); }

            /* Avisarle al jugador. Solo cuando es un REGALO: un monto negativo
               es un ajuste del agente y no hay nada que festejar.
               notif_crear() no lanza nunca, asi que un problema con el aviso no
               puede hacer que una carga exitosa parezca fallida. */
            if ($monto > 0) {
                [$nt, $nc] = notif_texto_carga($tipo, $monto, $motivo);
                notif_crear($pdo, $usuario, $nt, $nc, $tipo === 'bono' ? 'bono' : 'fichas', null, 'crm');
            }

            // Dejar rastro en el hilo de la conversacion, si vino.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $etiqueta = $tipo === 'bono' ? 'bono' : 'fichas';
                $signo = $monto > 0 ? '+' : '';
                crm_mensaje($pdo, $convId, 'agente',
                    "Cargó $signo" . number_format($monto, 0, ',', '.') . " $etiqueta"
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true]);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")->execute([$convId]);
            }
            salir(['ok' => true, 'tipo' => $tipo, 'saldo' => $r['saldo']]);
        }

        // ---- cargar / retirar SALDO real (se encola para el worker de ganamos) ----
        if ($accion === 'cargar_saldo' || $accion === 'retirar_saldo') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $monto   = (float)($body['monto'] ?? 0);
            $motivo  = mb_substr((string)($body['motivo'] ?? ''), 0, 200);
            $tipo    = $accion === 'retirar_saldo' ? 'retirar' : 'cargar';
            $r = crm_saldo($pdo, $usuario, $tipo, $monto, $motivo);
            if (!$r['ok']) { salir($r, 400); }

            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $verbo = $tipo === 'retirar' ? 'Retiro de' : 'Carga de';
                crm_mensaje($pdo, $convId, 'agente',
                    "$verbo $" . number_format($monto, 0, ',', '.') . " de saldo (pendiente en ganamos)"
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true]);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")->execute([$convId]);
            }
            salir(['ok' => true, 'encolada' => true]);
        }

        // ---- responder al cliente (mensaje del agente) ----
        if ($accion === 'responder') {
            $id = (int)($body['id'] ?? 0);
            $texto = trim((string)($body['texto'] ?? ''));
            if (!$id || $texto === '') { salir(['ok' => false, 'error' => 'Falta id o texto'], 400); }
            crm_mensaje($pdo, $id, 'agente', mb_substr($texto, 0, 2000));
            $pdo->prepare("UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?")
                ->execute([mb_substr($texto, 0, 280), $id]);

            /* La respuesta del agente llega cuando llega: es el caso donde mas
               falta hace el aviso, porque el jugador casi nunca sigue mirando.
               El que esta adentro no lo ve dos veces: ya lo recibe por
               mis_mensajes.php y el widget consume el aviso sin dibujarlo. */
            $st = $pdo->prepare("SELECT usuario FROM conversaciones WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $destino = (string)($st->fetchColumn() ?: '');
            if ($destino !== '') { notif_chat($pdo, $destino, $texto, true); }

            salir(['ok' => true]);
        }

        /* ---- notificacion push a mano ----
           `todos: true` manda a todos los celulares con la app; si no, va al
           jugador indicado. Es UNA fila aunque vaya a mil: la entrega la
           resuelve notificaciones_entregas cuando cada celular sondea. */
        if ($accion === 'notificar') {
            $todos   = !empty($body['todos']);
            $usuario = $todos ? null : trim((string)($body['usuario'] ?? ''));
            $titulo  = trim((string)($body['titulo'] ?? ''));
            $cuerpo  = trim((string)($body['cuerpo'] ?? ''));

            if (!$todos && $usuario === '') {
                salir(['ok' => false, 'error' => 'Elegí un jugador o marcá "a todos"'], 400);
            }
            if ($titulo === '' || $cuerpo === '') {
                salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
            }
            if (!$todos) {
                $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
                $st->execute([$usuario]);
                if (!$st->fetchColumn()) {
                    salir(['ok' => false, 'error' => 'Ese usuario no existe'], 400);
                }
            }

            $id = notif_crear($pdo, $usuario, $titulo, $cuerpo,
                              (string)($body['tipo'] ?? 'promo'), null, 'crm');
            if (!$id) { salir(['ok' => false, 'error' => 'No se pudo encolar'], 500); }

            // Rastro en el hilo, para que despues se entienda por que escribio.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                crm_mensaje($pdo, $convId, 'agente',
                    "Envió una notificación: $titulo", ['interno' => true]);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")
                    ->execute([$convId]);
            }

            /* El alcance es informativo: cuenta los celulares ACTIVOS que la van
               a recibir. Puede ser 0 y estar todo bien — el aviso queda en cola
               y le llega al jugador la proxima vez que abra la app. */
            salir(['ok' => true, 'id' => $id, 'alcance' => notif_alcance($pdo, $usuario)]);
        }

        // ---- anclar / desanclar ----
        if ($accion === 'fijar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $fijar = !empty($body['fijar']) ? 1 : 0;
            $pdo->prepare("UPDATE conversaciones SET fijada = ? WHERE id = ?")->execute([$fijar, $id]);
            salir(['ok' => true, 'fijada' => (bool)$fijar]);
        }

        // ---- guardar config del chatbot (contexto + on/off) ----
        if ($accion === 'chatbot_guardar') {
            // Contexto vacio = usar el default del codigo (se guarda NULL).
            $ctx    = isset($body['contexto']) ? trim((string)$body['contexto']) : '';
            $activo = !empty($body['activo']) ? 1 : 0;
            crm_chatbot_guardar($pdo, $ctx, $activo);
            salir(['ok' => true, 'activo' => (bool)$activo]);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()], 500);
    }
}

salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
