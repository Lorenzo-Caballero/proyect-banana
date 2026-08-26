<?php
/**
 * admin_usuarios.php — Datos para el panel de administracion de usuarios.
 *
 * Lee todo de la tabla `usuarios`: saldo (balance), fichas (coins) y bonos
 * son columnas de esa misma tabla.
 *
 * Exige sesión de operador (crm_auth.php), igual que el resto del CRM: sin
 * eso, cualquiera con la URL se llevaba la lista completa de jugadores con
 * sus saldos, y ?accion=exportar se la daba en CSV de una sola pasada.
 *
 * GET  ?accion=listar   (default)
 *      q=texto            -> busca por username (LIKE)
 *      filtro=todos|con_saldo|sin_saldo|baneados|con_bono_pendiente|con_bonos
 *             |inactivos_15|inactivos_30|inactivos_90
 *             (inactivo = sin recarga acreditada en N dias, misma
 *              definicion que el push masivo)
 *      orden=username|balance|coins|bonus|total_deposits|creation_date|actualizado_en
 *      dir=asc|desc
 *      pagina=1..
 *      por_pagina=25|50|100|200
 *   -> { ok, items:[...], total, pagina, por_pagina, paginas, resumen:{...} }
 *
 * GET  ?accion=exportar  -> CSV con TODO lo que matchea el filtro (sin paginar).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

// Este endpoint devuelve la tabla `usuarios` entera (saldos incluidos) y sabe
// exportarla en CSV: va detrás de la sesión del CRM, como todo lo demás.
// La vista "Usuarios" de crm.html ya lo llama con apiFetch(), que manda la
// cookie de sesión sola -- no hace falta tocar el frontend.
exigir_operador();

// ----------------------------- Parametros ----------------------------------
$accion  = (string)($_GET['accion'] ?? 'listar');
$q       = trim((string)($_GET['q'] ?? ''));
$filtro  = (string)($_GET['filtro'] ?? 'todos');
$orden   = (string)($_GET['orden'] ?? 'balance');
$dir     = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$porPag  = (int)($_GET['por_pagina'] ?? 25);
if (!in_array($porPag, [25, 50, 100, 200], true)) {
    $porPag = 25;
}

// Whitelist de columnas de orden: NUNCA interpolar entrada del usuario en SQL.
$columnas = [
    'username'       => 'u.username',
    'balance'        => 'u.balance',
    'bono_pendiente' => 'bp.suma',
    'coins'          => 'u.coins',
    'bonus'          => 'u.bonus',
    'total_deposits' => 'u.total_deposits',
    'creation_date'  => 'u.creation_date',
    'actualizado_en' => 'u.actualizado_en',
];
$ordenSql = $columnas[$orden] ?? 'u.balance';

// WHERE dinamico (con parametros ligados)
$where  = [];
$params = [];
if ($q !== '') {
    $where[]  = 'u.username LIKE ?';
    $params[] = '%' . $q . '%';
}
switch ($filtro) {
    case 'con_saldo':          $where[] = 'u.balance > 0'; break;
    case 'sin_saldo':          $where[] = 'u.balance = 0'; break;
    case 'baneados':           $where[] = 'u.is_banned = 1'; break;
    case 'con_bono_pendiente': $where[] = 'bp.suma > 0'; break;
    case 'con_bonos':          $where[] = 'u.bonus > 0'; break;

    // Inactivos: MISMA definicion que usa el push masivo
    // (crmnotif_alcance_inactivos en crm_notificaciones.php) -- "no hizo una
    // recarga acreditada en los ultimos N dias". Tiene que ser identica en los
    // dos lados: si el filtro de la tabla y el del push contaran distinto, el
    // agente ve 40 inactivos y le llega el aviso a 60.
    //
    // El COLLATE no es decorativo: `usuarios` quedo en uca1400 y las tablas
    // del CRM en utf8mb4_unicode_ci, asi que el JOIN sin el tira "Illegal mix
    // of collations" (ver CLAUDE.md).
    case 'inactivos_15':
    case 'inactivos_30':
    case 'inactivos_90':
        $diasInact = (int)substr($filtro, strrpos($filtro, '_') + 1);
        $where[] = "NOT EXISTS (
                       SELECT 1 FROM recargas r
                        WHERE r.usuario = u.username COLLATE utf8mb4_unicode_ci
                          AND r.estado = 'acreditada'
                          AND r.acreditada_en > DATE_SUB(NOW(), INTERVAL ? DAY)
                     )";
        $params[] = $diasInact;
        break;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// bono_pendiente: bonos_pendientes sin acreditar (prometidos por
// notificación, esperan la próxima recarga del jugador) -- ver
// bono_pendiente_total() en crm.php para el mismo concepto en la ficha de
// conversación. Acá, a diferencia de la ficha, se muestra ya sumado en
// coins-equivalente para poder ordenar/listar sin desglose por tipo (la
// tabla es de muchas filas, no un detalle de un único usuario). Solo cuenta
// tipo='fichas' -- 'pct' no tiene monto fijo hasta que el jugador carga, y
// 'giro' no es coins. LEFT JOIN + COALESCE: un usuario sin bonos pendientes
// no desaparece del listado. COLLATE explícito: usuarios está en collation
// distinta a bonos_pendientes (ver CLAUDE.md, choque de collations).
/* `bonos_pendientes` es de la migración 33. Si una base de cliente todavía
   no la corrió, este JOIN hace fallar TODAS las consultas de la pantalla --
   no solo la columna de bono pendiente: la lista entera, el conteo y el CSV.
   La pantalla de Usuarios quedaba en "Error al cargar" por una columna
   accesoria.

   Se detecta una vez y se arma la query con o sin el JOIN. El resumen de
   arriba ya hacía esto con su propio try/catch; faltaba acá, que es lo que
   de verdad tumbaba la vista. */
$hayBonosPend = true;
try {
    $pdo->query("SELECT 1 FROM bonos_pendientes LIMIT 1")->fetchColumn();
} catch (Throwable $e) {
    $hayBonosPend = false;
    error_log('admin_usuarios: sin tabla bonos_pendientes (¿falta la migración 33?). '
            . 'Se lista sin la columna de bono pendiente.');
}

if ($hayBonosPend) {
    $base = "FROM usuarios u
             LEFT JOIN (
               SELECT usuario, SUM(valor) AS suma
                 FROM bonos_pendientes
                WHERE estado = 'pendiente' AND tipo = 'fichas'
                GROUP BY usuario
             ) bp ON bp.usuario = u.username COLLATE utf8mb4_unicode_ci
             $whereSql";
    $selBono = "COALESCE(bp.suma, 0) AS bono_pendiente";
} else {
    // Sin la tabla no hay con qué filtrar ni ordenar por bono pendiente: se
    // devuelve 0 para que el frontend siga mostrando la columna sin romperse.
    // El filtro "con bono pendiente" tambien mira bp.suma: sin la tabla no
    // puede haber ninguno, asi que la condicion se vuelve imposible en vez de
    // referenciar una columna que no existe.
    $where = array_map(
        static fn($c) => $c === 'bp.suma > 0' ? '1 = 0' : $c,
        $where
    );
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $base = "FROM usuarios u $whereSql";
    $selBono = "0 AS bono_pendiente";
    if ($ordenSql === 'bp.suma') {
        $ordenSql = 'u.balance';
    }
}

$SELECT = "SELECT u.id, u.username, u.balance, u.bonus, u.total_deposits, u.role,
                  u.is_banned, u.creation_date, u.actualizado_en,
                  $selBono";

/** Castea los tipos de una fila para que el JSON salga prolijo. */
function tipar(array $it): array
{
    $it['id']             = (int)$it['id'];
    $it['balance']        = (float)$it['balance'];
    $it['bonus']          = (float)$it['bonus'];
    $it['bono_pendiente'] = (int)$it['bono_pendiente'];
    $it['total_deposits'] = (float)$it['total_deposits'];
    $it['is_banned']      = (bool)$it['is_banned'];
    return $it;
}

// ============================ EXPORTAR CSV ==================================
if ($accion === 'exportar') {
    $sql = "$SELECT $base ORDER BY $ordenSql $dir, u.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");   // BOM para que Excel abra bien los acentos
    fputcsv($out, ['id', 'usuario', 'saldo', 'bono_pendiente', 'bono_historico', 'depositos_total',
                   'rol', 'baneado', 'alta_ganamos', 'actualizado']);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $r = tipar($row);
        fputcsv($out, [
            $r['id'], $r['username'], $r['balance'], $r['bono_pendiente'], $r['bonus'],
            $r['total_deposits'], $r['role'], $r['is_banned'] ? 'si' : 'no',
            $r['creation_date'], $r['actualizado_en'],
        ]);
    }
    fclose($out);
    exit;
}

// ============================ LISTAR (JSON) =================================
header('Content-Type: application/json; charset=utf-8');

try {
    // Total que matchea el filtro (para la paginacion)
    $stTotal = $pdo->prepare("SELECT COUNT(*) $base");
    $stTotal->execute($params);
    $total = (int)$stTotal->fetchColumn();

    // Pagina de datos
    $offset = ($pagina - 1) * $porPag;
    $sql = "$SELECT $base ORDER BY $ordenSql $dir, u.id ASC LIMIT $porPag OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = array_map('tipar', $st->fetchAll(PDO::FETCH_ASSOC));

    // Resumen global (para las tarjetas de arriba). Todo sale de `usuarios`.
    $u = $pdo->query("SELECT COUNT(*) total, SUM(balance>0) con_saldo,
                             COALESCE(SUM(balance),0) saldo_total, SUM(is_banned) baneados,
                             SUM(coins>0) con_coins, COALESCE(SUM(coins),0) coins_total,
                             COALESCE(SUM(bonus),0) bonos_total
                      FROM usuarios")->fetch(PDO::FETCH_ASSOC);
    $ultima = $pdo->query("SELECT MAX(actualizado_en) FROM usuarios")->fetchColumn();

    // Bono pendiente total: suma de bonos_pendientes tipo='fichas' sin
    // acreditar, de TODOS los usuarios (no filtrado). Try/catch propio: la
    // tabla es de la migración 33, si alguna base de cliente todavía no la
    // corrió, el resumen sigue andando con este dato en 0 en vez de romper
    // toda la pantalla de Usuarios.
    try {
        $bonoPendienteTotal = (int)$pdo->query(
            "SELECT COALESCE(SUM(valor),0) FROM bonos_pendientes WHERE estado='pendiente' AND tipo='fichas'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $bonoPendienteTotal = 0;
    }

    echo json_encode([
        'ok'         => true,
        'items'      => $items,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $porPag,
        'paginas'    => (int)ceil($total / max(1, $porPag)),
        'resumen'    => [
            'total_usuarios'        => (int)$u['total'],
            'con_saldo'             => (int)$u['con_saldo'],
            'saldo_total'           => (float)$u['saldo_total'],
            'baneados'              => (int)$u['baneados'],
            'con_coins'             => (int)$u['con_coins'],
            'coins_total'           => (int)$u['coins_total'],
            'bonos_total'           => (int)$u['bonos_total'],
            'bono_pendiente_total'  => $bonoPendienteTotal,
            'ultima_sync'           => $ultima,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('admin_usuarios: ' . $e->getMessage());
    http_response_code(500);
    // 'detalle' ayuda a diagnosticar (tabla/columna que falta). Si te molesta
    // que se vea, borralo cuando ande.
    echo json_encode(['ok' => false, 'error' => 'Error al consultar',
                      'detalle' => $e->getMessage()]);
}
