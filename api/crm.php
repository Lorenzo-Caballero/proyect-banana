<?php
/**
 * crm.php — Backend del CRM de conversaciones.
 *
 * Exige login de operador (exigir_operador()/exigir_admin() de crm_auth.php).
 * Multi-agente: rol 'admin' gestiona agentes (agentes_listar/agente_crear/
 * agente_estado); rol 'agente' solo atiende chats.
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
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/notificaciones_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// El CRM ahora exige login de operador (multi-agente): cada acción sabe QUÉ
// agente la hizo. exigir_operador() corta con 401 (sin sesión) o 403 (CSRF
// inválido en POST) y el frontend ya reacciona mostrando el login.
// Se sirve del mismo origen que el CRM (no cross-origin), así la cookie de
// sesión viaja; por eso se quitó el 'Access-Control-Allow-Origin: *' que
// impedía mandar credenciales.
$operador = exigir_operador();

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

/**
 * Convierte la fecha/hora que tipeó el agente (hora de Argentina, formato
 * "YYYY-MM-DD HH:MM" o el "YYYY-MM-DDTHH:MM" de un <input datetime-local">) a
 * un datetime en UTC "YYYY-MM-DD HH:MM:SS", que es como se guarda y como se
 * compara en el sondeo (con UTC_TIMESTAMP()). Así no depende de la zona del
 * server ni de la conexión MySQL. Devuelve null si es inválida o ya pasó.
 */
function crm_parse_programada(string $raw): ?string
{
    $raw = trim(str_replace('T', ' ', $raw));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw)) { return null; }
    try {
        $ar  = new DateTime($raw, new DateTimeZone('America/Argentina/Buenos_Aires'));
        $now = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        // Margen de 1 min: si eligió "ahora mismo", lo tomamos como inmediato válido.
        if ($ar->getTimestamp() < $now->getTimestamp() - 60) { return null; }
        $ar->setTimezone(new DateTimeZone('UTC'));
        return $ar->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/** Marca a un agente como atendiendo un chat (idempotente). */
function crm_agente_tomar(PDO $pdo, int $convId, string $operador): void
{
    if ($operador === '') { return; }
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO conversacion_agentes (conversacion_id, operador) VALUES (?, ?)"
        )->execute([$convId, mb_substr($operador, 0, 60)]);
    } catch (Throwable $e) { /* sin tabla (mig 30 sin correr): se ignora */ }
}

/** Saca a un agente de un chat (relevo / pausa). */
function crm_agente_soltar(PDO $pdo, int $convId, string $operador): void
{
    if ($operador === '') { return; }
    try {
        $pdo->prepare(
            "DELETE FROM conversacion_agentes WHERE conversacion_id = ? AND operador = ?"
        )->execute([$convId, mb_substr($operador, 0, 60)]);
    } catch (Throwable $e) {}
}

/** Lista de agentes atendiendo un chat, el que tomó primero al frente. */
function crm_agentes_de(PDO $pdo, int $convId): array
{
    try {
        $st = $pdo->prepare(
            "SELECT operador FROM conversacion_agentes WHERE conversacion_id = ? ORDER BY tomado_en ASC"
        );
        $st->execute([$convId]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Lista todos los operadores (admins y agentes) de este cliente. */
function crm_agentes_listar(PDO $pdo): array
{
    // rol puede no existir (migración 31 sin correr): se pide con COALESCE
    // vía un try/catch, así no revienta si falta la columna.
    try {
        $rows = $pdo->query(
            "SELECT username, rol, activo, ultimo_login, creado_en FROM operadores
             ORDER BY (rol = 'admin') DESC, username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = $pdo->query(
            "SELECT username, 'admin' AS rol, activo, ultimo_login FROM operadores ORDER BY username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return array_map(function ($r) {
        return [
            'username'     => $r['username'],
            'rol'          => in_array($r['rol'] ?? 'admin', ['admin', 'agente'], true) ? $r['rol'] : 'admin',
            'activo'       => (int)($r['activo'] ?? 1) === 1,
            'ultimo_login' => $r['ultimo_login'] ?? null,
            'creado_en'    => $r['creado_en'] ?? null,
        ];
    }, $rows);
}

/** Crea un agente humano (rol='agente') o resetea su clave si ya existe.
 *  SOLO agentes: crear otro admin no es cosa de este endpoint (ver nota en
 *  la acción agente_crear). */
function crm_agente_crear(PDO $pdo, string $usuario, string $password): array
{
    $usuario = trim($usuario);
    if ($usuario === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $usuario)) {
        return ['ok' => false, 'error' => 'Usuario inválido (3-60 caracteres, sin espacios)'];
    }
    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'error' => 'La contraseña necesita al menos 6 caracteres'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    $existe = (bool)$st->fetchColumn();

    try {
        if ($existe) {
            $pdo->prepare("UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?")
                ->execute([$hash, $usuario]);
        } else {
            $pdo->prepare(
                "INSERT INTO operadores (username, password_hash, rol, activo) VALUES (?, ?, 'agente', 1)"
            )->execute([$usuario, $hash]);
        }
    } catch (Throwable $e) {
        // Sin columna `rol` (migración 31 no corrida): igual se crea, va a
        // quedar con el DEFAULT de la tabla (que hoy es 'admin' en muchos
        // casos legacy) hasta que se corra la migración.
        if ($existe) {
            $pdo->prepare("UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?")
                ->execute([$hash, $usuario]);
        } else {
            $pdo->prepare(
                "INSERT INTO operadores (username, password_hash, activo) VALUES (?, ?, 1)"
            )->execute([$usuario, $hash]);
        }
    }
    return ['ok' => true, 'usuario' => $usuario, 'accion' => $existe ? 'actualizado' : 'creado'];
}

/** Activa/desactiva un operador (nunca a uno mismo, para no encerrarse). */
function crm_agente_estado(PDO $pdo, string $usuario, int $activo, string $quienLoHace): array
{
    if ($usuario === $quienLoHace) {
        return ['ok' => false, 'error' => 'No podés desactivarte a vos mismo'];
    }
    $pdo->prepare("UPDATE operadores SET activo = ? WHERE username = ?")->execute([$activo, $usuario]);
    return ['ok' => true];
}

/**
 * Cambia el rol de un operador (nunca el propio, para no encerrarse sin
 * ningún admin). $nuevaPassword opcional: si viene (≥6 chars), la resetea
 * en la misma pasada.
 */
function crm_agente_editar(PDO $pdo, string $usuario, string $rol, ?string $nuevaPassword, string $quienLoHace): array
{
    if (!in_array($rol, ['admin', 'agente'], true)) {
        return ['ok' => false, 'error' => 'Rol inválido'];
    }
    if ($usuario === $quienLoHace && $rol !== 'admin') {
        return ['ok' => false, 'error' => 'No podés sacarte el rol admin a vos mismo'];
    }
    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'Ese agente no existe']; }

    if ($nuevaPassword !== null && $nuevaPassword !== '') {
        if (mb_strlen($nuevaPassword) < 6) {
            return ['ok' => false, 'error' => 'La contraseña necesita al menos 6 caracteres'];
        }
        $hash = password_hash($nuevaPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE operadores SET rol = ?, password_hash = ? WHERE username = ?")
            ->execute([$rol, $hash, $usuario]);
    } else {
        $pdo->prepare("UPDATE operadores SET rol = ? WHERE username = ?")->execute([$rol, $usuario]);
    }
    return ['ok' => true];
}

/** Elimina un operador (nunca a uno mismo). Sus mensajes/conversaciones no se
 *  tocan: `mensajes.operador` y `conversacion_agentes.operador` son texto
 *  suelto, no FK, así que la auditoría vieja queda intacta. */
function crm_agente_eliminar(PDO $pdo, string $usuario, string $quienLoHace): array
{
    if ($usuario === $quienLoHace) {
        return ['ok' => false, 'error' => 'No podés eliminarte a vos mismo'];
    }
    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'Ese agente no existe']; }

    // Lo suelta de cualquier chat que estuviera atendiendo, y lo borra.
    try {
        $pdo->prepare("DELETE FROM conversacion_agentes WHERE operador = ?")->execute([$usuario]);
    } catch (Throwable $e) { /* sin tabla (mig 30): nada que soltar */ }
    $pdo->prepare("DELETE FROM operadores WHERE username = ?")->execute([$usuario]);
    return ['ok' => true];
}

/** Carga la lib del chatbot (defaults + armado del prompt) una sola vez. */
function crm_chatbot_lib(): void
{
    $f = __DIR__ . '/chatbot_contexto.php';
    if (is_file($f)) { require_once $f; }
}

/** Defaults de cada campo editable (para mostrar en el editor y para el botón
 *  "restaurar"). Vacíos si la lib no está (no rompe el CRM). */
function crm_chatbot_defaults(): array
{
    crm_chatbot_lib();
    return [
        'bot_nombre'   => defined('CB_DEF_NOMBRE')       ? CB_DEF_NOMBRE       : '',
        'bot_tono'     => defined('CB_DEF_TONO')         ? CB_DEF_TONO         : '',
        'juego_desc'   => defined('CB_DEF_JUEGO')        ? CB_DEF_JUEGO        : '',
        'reglas_extra' => defined('CB_DEF_REGLAS_EXTRA') ? CB_DEF_REGLAS_EXTRA : '',
    ];
}

/** Lee la config del chatbot por CAMPOS. Cada campo vacío en la base se
 *  muestra con su default, así el agente ve y edita desde algo concreto.
 *  Robusto si faltan la tabla (mig. 26) o las columnas (mig. 28). */
function crm_chatbot_leer(PDO $pdo): array
{
    $def = crm_chatbot_defaults();
    try {
        $row = $pdo->query("SELECT * FROM config_chatbot WHERE id = 1 LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return array_merge($def, ['activo' => true, 'default' => $def]);
    }
    $val = function($k) use ($row, $def){
        $v = $row ? trim((string)($row[$k] ?? '')) : '';
        return $v !== '' ? $v : ($def[$k] ?? '');
    };
    return [
        'bot_nombre'   => $val('bot_nombre'),
        'bot_tono'     => $val('bot_tono'),
        'juego_desc'   => $val('juego_desc'),
        // reglas_extra NO cae al default: es opcional y su default es vacío.
        'reglas_extra' => $row ? trim((string)($row['reglas_extra'] ?? '')) : '',
        'activo'       => $row ? ((int)($row['activo'] ?? 1) === 1) : true,
        'default'      => $def,   // para el botón "restaurar" del editor
    ];
}

/** Guarda los campos editables + el flag activo. Un campo igual a su default
 *  se guarda NULL, así el cliente hereda futuras mejoras del default. */
function crm_chatbot_guardar(PDO $pdo, array $campos, int $activo): void
{
    $def = crm_chatbot_defaults();
    $norm = function($k) use ($campos, $def){
        $v = trim((string)($campos[$k] ?? ''));
        // Vacío o igual al default -> NULL (sigue el default del código).
        return ($v === '' || $v === trim((string)($def[$k] ?? ''))) ? null : $v;
    };
    $pdo->prepare(
        "INSERT INTO config_chatbot (id, bot_nombre, bot_tono, juego_desc, reglas_extra, activo)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           bot_nombre = VALUES(bot_nombre), bot_tono = VALUES(bot_tono),
           juego_desc = VALUES(juego_desc), reglas_extra = VALUES(reglas_extra),
           activo = VALUES(activo)"
    )->execute([
        $norm('bot_nombre'), $norm('bot_tono'), $norm('juego_desc'),
        $norm('reglas_extra'), $activo ? 1 : 0,
    ]);
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
                $it['agentes'] = [];
            }
            unset($it);

            // Agentes atendiendo cada chat del listado, en UNA query (sin N+1).
            if ($items) {
                try {
                    $ids = array_column($items, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    $stA = $pdo->prepare(
                        "SELECT conversacion_id, operador FROM conversacion_agentes
                         WHERE conversacion_id IN ($ph) ORDER BY tomado_en ASC"
                    );
                    $stA->execute($ids);
                    $porConv = [];
                    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $porConv[(int)$r['conversacion_id']][] = $r['operador'];
                    }
                    foreach ($items as &$it2) {
                        $it2['agentes'] = $porConv[$it2['id']] ?? [];
                    }
                    unset($it2);
                } catch (Throwable $e) { /* sin tabla (mig 30): quedan [] */ }
            }

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
            // ia_activa puede no existir si la migracion 27 no se corrio: default true.
            $conv['ia_activa'] = !array_key_exists('ia_activa', $conv) || (int)$conv['ia_activa'] === 1;

            // marcar como leida
            $pdo->prepare("UPDATE conversaciones SET no_leidos = 0 WHERE id = ?")->execute([$id]);

            // `operador` por mensaje (migración 30). Si la columna no existe,
            // se reintenta sin ella para no romper el hilo.
            try {
                $st = $pdo->prepare("SELECT rol, operador, texto, meta, creado_en FROM mensajes
                                     WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
                $st->execute([$id]);
            } catch (Throwable $e) {
                $st = $pdo->prepare("SELECT rol, texto, meta, creado_en FROM mensajes
                                     WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
                $st->execute([$id]);
            }
            $mensajes = array_map(function ($m) {
                $m['adjunto'] = $m['meta'] ? json_decode($m['meta'], true) : null;  // {tipo,url,nombre}
                unset($m['meta']);
                return $m;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            $usuario = trim((string)($conv['usuario'] ?? ''));
            $ficha = $usuario !== '' ? ficha_usuario($pdo, $usuario) : null;
            $movs  = $usuario !== '' ? movimientos($pdo, $usuario) : [];

            salir(['ok' => true, 'conversacion' => $conv, 'mensajes' => $mensajes,
                   'usuario' => $ficha, 'movimientos' => $movs,
                   'agentes' => crm_agentes_de($pdo, $id),
                   'yo' => $operador]);
        }

        // ---- config del chatbot (campos editables + on/off) ----
        if ($accion === 'chatbot_config') {
            salir(array_merge(['ok' => true], crm_chatbot_leer($pdo)));
        }

        // ---- quién soy (para recuperar el rol tras un F5, CSRF/ROL no
        //      persisten en localStorage a propósito) ----
        if ($accion === 'yo') {
            salir(['ok' => true, 'operador' => $operador, 'rol' => operador_rol()]);
        }

        // ---- listar agentes/admins (solo admin) ----
        if ($accion === 'agentes_listar') {
            exigir_admin();
            salir(['ok' => true, 'agentes' => crm_agentes_listar($pdo)]);
        }

        // ---- difusiones programadas que todavía no salieron ----
        if ($accion === 'programadas_listar') {
            salir(['ok' => true, 'programadas' => notif_programadas_listar($pdo)]);
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

            $r = crm_cargar($pdo, $usuario, $tipo, $monto, $motivo, 'crm', $operador);
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
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true], $operador);
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
            $r = crm_saldo($pdo, $usuario, $tipo, $monto, $motivo, $operador);
            if (!$r['ok']) { salir($r, 400); }

            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $verbo = $tipo === 'retirar' ? 'Retiro de' : 'Carga de';
                crm_mensaje($pdo, $convId, 'agente',
                    "$verbo $" . number_format($monto, 0, ',', '.') . " de saldo (pendiente en ganamos)"
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")->execute([$convId]);
            }
            salir(['ok' => true, 'encolada' => true]);
        }

        // ---- responder al cliente (mensaje del agente) ----
        if ($accion === 'responder') {
            $id = (int)($body['id'] ?? 0);
            $texto = trim((string)($body['texto'] ?? ''));
            if (!$id || $texto === '') { salir(['ok' => false, 'error' => 'Falta id o texto'], 400); }
            crm_mensaje($pdo, $id, 'agente', mb_substr($texto, 0, 2000), null, $operador);
            // Responder = atender: si el agente aún no estaba asignado a este
            // chat, queda asignado solo (aparece su etiqueta sin tener que
            // tocar "Atender" a mano).
            crm_agente_tomar($pdo, $id, $operador);
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

            // Programación opcional (difusiones a futuro). Llega como
            // 'programada_en' en formato "YYYY-MM-DD HH:MM" (hora de Argentina,
            // lo que ve el agente). Se convierte a la hora del server para
            // guardar, porque NOW() en el sondeo corre en la zona del server.
            $progEn = null;
            $progRaw = trim((string)($body['programada_en'] ?? ''));
            if ($progRaw !== '') {
                $progEn = crm_parse_programada($progRaw);
                if ($progEn === null) {
                    salir(['ok' => false, 'error' => 'Fecha/hora de programación inválida'], 400);
                }
            }

            $id = notif_crear($pdo, $usuario, $titulo, $cuerpo,
                              (string)($body['tipo'] ?? 'promo'), null, 'crm', null, false, $progEn);
            if (!$id) {
                $err = $progEn
                    ? 'No se pudo programar (¿falta correr la migración 29_notif_programada.sql?)'
                    : 'No se pudo encolar';
                salir(['ok' => false, 'error' => $err], 500);
            }

            // Rastro en el hilo, para que despues se entienda por que escribio.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $rastro = $progEn ? "Programó una notificación ($progEn): $titulo"
                                  : "Envió una notificación: $titulo";
                crm_mensaje($pdo, $convId, 'agente', $rastro, ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")
                    ->execute([$convId]);
            }

            /* El alcance es informativo: cuenta los celulares ACTIVOS que la van
               a recibir. Puede ser 0 y estar todo bien — el aviso queda en cola
               y le llega al jugador la proxima vez que abra la app. */
            salir(['ok' => true, 'id' => $id, 'programada_en' => $progEn,
                   'alcance' => notif_alcance($pdo, $usuario)]);
        }

        // ---- anclar / desanclar ----
        if ($accion === 'fijar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $fijar = !empty($body['fijar']) ? 1 : 0;
            $pdo->prepare("UPDATE conversaciones SET fijada = ? WHERE id = ?")->execute([$fijar, $id]);
            salir(['ok' => true, 'fijada' => (bool)$fijar]);
        }

        // ---- atender / soltar un chat (asignación de agente, relevo) ----
        if ($accion === 'atender' || $accion === 'soltar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            // El agente que atiende/suelta es SIEMPRE el logueado, nunca uno que
            // venga por el body: así nadie asigna/desasigna en nombre de otro.
            if ($accion === 'atender') { crm_agente_tomar($pdo, $id, $operador); }
            else                       { crm_agente_soltar($pdo, $id, $operador); }
            salir(['ok' => true, 'agentes' => crm_agentes_de($pdo, $id)]);
        }

        // ---- prender/apagar la IA para UN chat puntual ----
        if ($accion === 'chatbot_ia_chat') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $activa = !empty($body['activa']) ? 1 : 0;
            $pdo->prepare("UPDATE conversaciones SET ia_activa = ? WHERE id = ?")
                ->execute([$activa, $id]);
            salir(['ok' => true, 'ia_activa' => (bool)$activa]);
        }

        // ---- guardar config del chatbot (campos + on/off) ----
        if ($accion === 'chatbot_guardar') {
            $activo = !empty($body['activo']) ? 1 : 0;
            crm_chatbot_guardar($pdo, [
                'bot_nombre'   => $body['bot_nombre']   ?? '',
                'bot_tono'     => $body['bot_tono']     ?? '',
                'juego_desc'   => $body['juego_desc']   ?? '',
                'reglas_extra' => $body['reglas_extra'] ?? '',
            ], $activo);
            salir(['ok' => true, 'activo' => (bool)$activo]);
        }

        // ---- crear un agente humano / resetear su clave (solo admin) ----
        if ($accion === 'agente_crear') {
            exigir_admin();
            $r = crm_agente_crear($pdo, trim((string)($body['usuario'] ?? '')),
                                  (string)($body['password'] ?? ''));
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- activar / desactivar un operador (solo admin) ----
        if ($accion === 'agente_estado') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            $activo  = !empty($body['activo']) ? 1 : 0;
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_estado($pdo, $usuario, $activo, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- editar rol (y opcionalmente resetear clave) de un operador ----
        if ($accion === 'agente_editar') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            $rol     = trim((string)($body['rol'] ?? ''));
            $pass    = array_key_exists('password', $body) ? (string)$body['password'] : null;
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_editar($pdo, $usuario, $rol, $pass, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- eliminar un operador ----
        if ($accion === 'agente_eliminar') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_eliminar($pdo, $usuario, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- cancelar una difusión programada antes de que salga ----
        if ($accion === 'programada_cancelar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = notif_programada_cancelar($pdo, $id);
            salir(['ok' => $ok, 'error' => $ok ? null : 'No se pudo cancelar (¿ya salió?)']);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()], 500);
    }
}

salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
