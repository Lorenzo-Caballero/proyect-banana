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
 * POST { accion:"notificar",    usuario|todos|filtro:{modo,dias?}, titulo, cuerpo, tipo? }
 * GET  ?accion=notif_historial&usuario=&desde=&hasta=&tipo=&pagina=
 * GET  ?accion=notif_alcance_inactivos&dias=N
 * GET  ?accion=notif_presets_listar
 * POST { accion:"notif_preset_guardar", nombre, filtro }
 * POST { accion:"notif_preset_borrar",  id }
 * GET  ?accion=bonos_listar&usuario=&estado=
 * POST { accion:"bono_crear",  usuario, tipo:fichas|pct|giro, valor }
 * POST { accion:"bono_editar", id, tipo, valor }
 * POST { accion:"bono_borrar", id }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/notificaciones_lib.php';
require __DIR__ . '/crm_notificaciones.php';

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

// crm_parse_programada() vive en crm_lib.php (compartida con el cron de
// difusiones de chat, que no puede incluir este archivo entero porque
// dispara exigir_operador()).

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
                $meta = $m['meta'] ? json_decode($m['meta'], true) : null;
                // adjunto: {tipo,url,nombre}. interno: true = rastro del agente
                // ("Fiorella cargó $500"), no una respuesta real al cliente.
                $m['adjunto'] = ($meta && isset($meta['url'])) ? $meta : null;
                $m['interno'] = (bool)($meta['interno'] ?? false);
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
            // El CSRF viaja acá porque el front lo pierde en cada F5 (vive solo
            // en memoria a propósito). Sin esto, después de recargar la página
            // cualquier POST que no pase por apiFetch() -- que reacciona al 403
            // mostrando el login -- se queda sin token y falla en silencio.
            salir(['ok' => true, 'operador' => $operador, 'rol' => operador_rol(),
                   'csrf' => csrf_token()]);
        }

        // ---- listar agentes/admins (solo admin) ----
        if ($accion === 'agentes_listar') {
            exigir_admin();
            salir(['ok' => true, 'agentes' => crm_agentes_listar($pdo)]);
        }

        // ---- difusiones programadas que todavía no salieron ----
        if ($accion === 'programadas_listar') {
            $push = array_map(function ($p) {
                $p['canal'] = 'push';
                return $p;
            }, notif_programadas_listar($pdo));
            $chat = array_map(function ($p) {
                $p['canal']  = 'chat';
                $p['titulo'] = $p['texto'];   // mismo campo que usa el front para mostrar
                return $p;
            }, crm_difusiones_chat_listar($pdo));
            $todas = array_merge($push, $chat);
            usort($todas, fn($a, $b) => strcmp($a['programada_en_ar'], $b['programada_en_ar']));
            salir(['ok' => true, 'programadas' => $todas]);
        }

        // ---- historial de notificaciones YA enviadas (a diferencia de
        //      programadas_listar, que solo mira las futuras) ----
        if ($accion === 'notif_historial') {
            $opts = [
                'usuario' => (string)($_GET['usuario'] ?? ''),
                'desde'   => (string)($_GET['desde'] ?? ''),
                'hasta'   => (string)($_GET['hasta'] ?? ''),
                'tipo'    => (string)($_GET['tipo'] ?? ''),
                'pagina'  => (int)($_GET['pagina'] ?? 1),
            ];
            salir(array_merge(['ok' => true], crmnotif_historial($pdo, $opts)));
        }

        // ---- preview de alcance para el filtro "inactivos hace N días" ----
        if ($accion === 'notif_alcance_inactivos') {
            $dias = (int)($_GET['dias'] ?? 0);
            salir(['ok' => true, 'alcance' => crmnotif_alcance_inactivos($pdo, $dias)]);
        }

        // ---- presets de filtro guardados ----
        if ($accion === 'notif_presets_listar') {
            salir(['ok' => true, 'presets' => crmnotif_presets_listar($pdo)]);
        }

        // ---- bonos pendientes (catálogo de lo prometido por notificación) ----
        if ($accion === 'bonos_listar') {
            $opts = [
                'usuario' => (string)($_GET['usuario'] ?? ''),
                'estado'  => (string)($_GET['estado'] ?? ''),
            ];
            salir(['ok' => true, 'bonos' => crmnotif_bono_listar($pdo, $opts)]);
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

        /* ---- difusión: push, mensaje de chat, o ambos ----
           `todos: true` manda a todos los celulares/conversaciones; si no, va
           al jugador indicado. El push es UNA fila aunque vaya a mil (la
           entrega la resuelve notificaciones_entregas al sondear); el chat
           masivo SÍ inserta un mensaje por conversación (ver
           crm_difusion_chat_aplicar), porque cada uno vive en su propio hilo. */
        if ($accion === 'notificar') {
            $todos   = !empty($body['todos']);
            $usuario = $todos ? null : trim((string)($body['usuario'] ?? ''));

            // Filtro de audiencia (Fase notificaciones avanzadas): si viene
            // filtro.modo="inactivos", el push NO va a "todos" ni a un
            // usuario puntual -- se resuelve la lista de inactivos y se
            // manda por crmnotif_enviar_masivo (una fila por destinatario,
            // agrupadas por lote_id). El canal chat/programación siguen el
            // camino de siempre para el resto de los modos.
            $filtro = is_array($body['filtro'] ?? null) ? $body['filtro'] : null;
            $modoFiltro = $filtro ? (string)($filtro['modo'] ?? '') : '';

            $canal = (string)($body['canal'] ?? 'push');
            if (!in_array($canal, ['push', 'chat', 'ambos'], true)) { $canal = 'push'; }
            $incluyePush = $canal === 'push' || $canal === 'ambos';
            $incluyeChat = $canal === 'chat' || $canal === 'ambos';

            $titulo = trim((string)($body['titulo'] ?? ''));
            $cuerpo = trim((string)($body['cuerpo'] ?? ''));
            // Para el chat, si no mandaron un texto propio, se usa el cuerpo
            // del push (evita pedir lo mismo dos veces cuando el canal es "ambos").
            $mensajeChat = trim((string)($body['mensaje_chat'] ?? '')) ?: $cuerpo;

            if ($modoFiltro === 'inactivos') {
                if ($incluyePush && ($titulo === '' || $cuerpo === '')) {
                    salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
                }
                $dias = max(0, (int)($filtro['dias'] ?? 0));
                $r = crmnotif_enviar_masivo($pdo, ['modo' => 'inactivos', 'dias' => $dias],
                                            $titulo, $cuerpo, (string)($body['tipo'] ?? 'promo'),
                                            'crm', $operador);
                if (!$r['ok']) { salir($r, 500); }
                salir(['ok' => true, 'alcance' => $r['alcance'], 'lote_id' => $r['lote_id'], 'canal' => 'push']);
            }

            if (!$todos && $usuario === '') {
                salir(['ok' => false, 'error' => 'Elegí un jugador o marcá "a todos"'], 400);
            }
            if ($incluyePush && ($titulo === '' || $cuerpo === '')) {
                salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
            }
            if ($incluyeChat && $mensajeChat === '') {
                salir(['ok' => false, 'error' => 'Falta el mensaje del chat'], 400);
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
            // lo que ve el agente). Se convierte a UTC para guardar y comparar.
            $progEn = null;
            $progRaw = trim((string)($body['programada_en'] ?? ''));
            if ($progRaw !== '') {
                $progEn = crm_parse_programada($progRaw);
                if ($progEn === null) {
                    salir(['ok' => false, 'error' => 'Fecha/hora de programación inválida'], 400);
                }
            }

            $pushId = null;
            if ($incluyePush) {
                $pushId = notif_crear($pdo, $usuario, $titulo, $cuerpo,
                                      (string)($body['tipo'] ?? 'promo'), null, 'crm', null, false, $progEn);
                if (!$pushId) {
                    $err = $progEn
                        ? 'No se pudo programar el push (¿falta correr la migración 29_notif_programada.sql?)'
                        : 'No se pudo encolar el push';
                    salir(['ok' => false, 'error' => $err], 500);
                }
            }

            $chatAlcance = null;
            if ($incluyeChat) {
                if ($progEn) {
                    // Programado: se encola, un cron lo aplica en el momento
                    // (ver difusiones_chat_procesar.php) -- insertarlo ya
                    // mismo lo mostraría antes de tiempo en el chat.
                    try {
                        $pdo->prepare(
                            "INSERT INTO difusiones_chat (usuario, texto, programada_en, creado_por) VALUES (?,?,?,?)"
                        )->execute([$usuario, $mensajeChat, $progEn, $operador]);
                    } catch (Throwable $e) {
                        salir(['ok' => false, 'error' => 'No se pudo programar el chat (¿falta correr la migración 32_difusiones_chat.sql?)'], 500);
                    }
                } else {
                    // Ahora mismo: se aplica en la misma request.
                    $chatAlcance = crm_difusion_chat_aplicar($pdo, $usuario, $mensajeChat);
                }
            }

            // Rastro en el hilo, para que despues se entienda por que escribio.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $partes = [];
                if ($incluyePush) { $partes[] = $progEn ? "programó un push ($progEn)" : "envió un push"; }
                if ($incluyeChat) { $partes[] = $progEn ? "programó un mensaje de chat ($progEn)" : "envió un mensaje de chat"; }
                $rastro = ucfirst(implode(' y ', $partes)) . ": " . ($titulo ?: $mensajeChat);
                crm_mensaje($pdo, $convId, 'agente', $rastro, ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")
                    ->execute([$convId]);
            }

            /* El alcance del push es informativo: celulares ACTIVOS que la
               van a recibir (puede ser 0 y estar todo bien). El del chat es
               real: conversaciones donde efectivamente se insertó el mensaje
               (null si quedó programado, todavía no se sabe). */
            salir(['ok' => true, 'id' => $pushId, 'programada_en' => $progEn, 'canal' => $canal,
                   'alcance' => $incluyePush ? notif_alcance($pdo, $usuario) : null,
                   'chat_alcance' => $chatAlcance]);
        }

        // ---- presets de filtro (guardar/editar reusan el mismo UPSERT) ----
        if ($accion === 'notif_preset_guardar') {
            $nombre = trim((string)($body['nombre'] ?? ''));
            $filtro = is_array($body['filtro'] ?? null) ? $body['filtro'] : [];
            $r = crmnotif_preset_guardar($pdo, $nombre, $filtro, $operador);
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'notif_preset_borrar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = crmnotif_preset_borrar($pdo, $id);
            salir(['ok' => $ok], $ok ? 200 : 400);
        }

        // ---- bonos pendientes (prometidos por notificación) ----
        if ($accion === 'bono_crear') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $tipo    = (string)($body['tipo'] ?? '');
            $valor   = (int)($body['valor'] ?? 0);
            $r = crmnotif_bono_crear($pdo, $usuario, $tipo, $valor, $operador,
                                     isset($body['notificacion_id']) ? (int)$body['notificacion_id'] : null);
            if ($r['ok']) {
                crm_bitacora($pdo, $operador, 'bono_crear', "$usuario · $tipo · $valor");
            }
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'bono_editar') {
            $id    = (int)($body['id'] ?? 0);
            $tipo  = (string)($body['tipo'] ?? '');
            $valor = (int)($body['valor'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $r = crmnotif_bono_editar($pdo, $id, $tipo, $valor);
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'bono_borrar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = crmnotif_bono_borrar($pdo, $id);
            if ($ok) { crm_bitacora($pdo, $operador, 'bono_borrar', "id=$id"); }
            salir(['ok' => $ok], $ok ? 200 : 400);
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
            $id    = (int)($body['id'] ?? 0);
            $canal = (string)($body['canal'] ?? 'push');
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = $canal === 'chat' ? crm_difusion_chat_cancelar($pdo, $id) : notif_programada_cancelar($pdo, $id);
            salir(['ok' => $ok, 'error' => $ok ? null : 'No se pudo cancelar (¿ya salió?)']);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()], 500);
    }
}

salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
