<?php
/**
 * admin_usuarios.php — Datos para el panel de administracion de usuarios.
 *
 * Lee todo de la tabla `usuarios`: saldo (balance), fichas (coins) y bonos
 * son columnas de esa misma tabla.
 *
 * Acceso directo (sin login).
 *
 * GET  ?accion=listar   (default)
 *      q=texto            -> busca por username (LIKE)
 *      filtro=todos|con_saldo|sin_saldo|baneados|con_coins|registrados
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

// Sin login: el panel es de acceso directo. Si algun dia querés cerrarlo,
// aca iria un chequeo de clave (X-Admin-Pass vs cfg('ADMIN_PASS')).

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
    case 'con_saldo':  $where[] = 'u.balance > 0'; break;
    case 'sin_saldo':  $where[] = 'u.balance = 0'; break;
    case 'baneados':   $where[] = 'u.is_banned = 1'; break;
    case 'con_coins':  $where[] = 'u.coins > 0'; break;
    case 'con_bonos':  $where[] = 'u.bonus > 0'; break;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
// Ahora todo vive en `usuarios`: fichas (coins) y bonos son columnas de la tabla.
$base = "FROM usuarios u $whereSql";

$SELECT = "SELECT u.id, u.username, u.balance, u.bonus, u.total_deposits, u.role,
                  u.is_banned, u.creation_date, u.actualizado_en,
                  COALESCE(u.coins, 0) AS coins";

/** Castea los tipos de una fila para que el JSON salga prolijo. */
function tipar(array $it): array
{
    $it['id']             = (int)$it['id'];
    $it['balance']        = (float)$it['balance'];
    $it['bonus']          = (float)$it['bonus'];
    $it['total_deposits'] = (float)$it['total_deposits'];
    $it['coins']          = (int)$it['coins'];
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
    fputcsv($out, ['id', 'usuario', 'saldo', 'fichas', 'bonos', 'depositos_total',
                   'rol', 'baneado', 'alta_ganamos', 'actualizado']);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $r = tipar($row);
        fputcsv($out, [
            $r['id'], $r['username'], $r['balance'], $r['coins'], $r['bonus'],
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

    echo json_encode([
        'ok'         => true,
        'items'      => $items,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $porPag,
        'paginas'    => (int)ceil($total / max(1, $porPag)),
        'resumen'    => [
            'total_usuarios' => (int)$u['total'],
            'con_saldo'      => (int)$u['con_saldo'],
            'saldo_total'    => (float)$u['saldo_total'],
            'baneados'       => (int)$u['baneados'],
            'con_coins'      => (int)$u['con_coins'],
            'coins_total'    => (int)$u['coins_total'],
            'bonos_total'    => (int)$u['bonos_total'],
            'ultima_sync'    => $ultima,
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
