<?php
// DEPRECADO: reemplazado por crm_auditoria.php (Módulo Auditoría)
// Mantener por compatibilidad hasta confirmar que nada lo llama.
/**
 * crm_movimientos.php — Backend del módulo "Transacciones" (Fase A, Módulo 4).
 *
 * Vista de SOLO LECTURA sobre `movimientos` (fichas/bono/saldo cargados vía
 * CRM, ruleta, o el "cargar mis fichas al juego" del chatbot). Sin acciones
 * de escritura, no hay `POST` en este archivo.
 *
 * OJO DE ALCANCE: `movimientos` NO incluye recargas por transferencia (tabla
 * `recargas`/`pagos`, Módulo 3) ni retiros (tabla `acciones_saldo`, Módulo 2)
 * -- esas dos tienen sus propios módulos y sus propias tablas. Esta vista es
 * específicamente fichas/bono/saldo.
 *
 * OJO CASING: `movimientos.operador` guarda el username tal como se tecleó
 * al loguearse (bug de `crm_auth.php`, ver TODO_FASE_A.md) -- hoy conviven
 * variantes de casing para la misma persona. El filtro por operador compara
 * con LOWER() en los dos lados para no perder filas viejas por eso.
 *
 * GET ?accion=listar&tipo=&origen=&usuario=&operador=&desde=&hasta=&limit=&offset=
 *     -> { ok, items:[...] }
 * GET ?accion=contar&(mismos filtros, sin limit/offset)
 *     -> { ok, total }
 * GET ?accion=operadores
 *     -> { ok, operadores:[...] }   -- para poblar el dropdown de filtro
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
exigir_operador(); // solo exige sesion -- modulo de lectura, no hay nada que auditar por operador

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Arma WHERE/params a partir de $_GET, reusado por listar y contar (cero
 * duplicacion de los mismos filtros en dos queries distintas).
 */
function mv_filtros(): array
{
    $where  = [];
    $params = [];

    $tipo = (string)($_GET['tipo'] ?? '');
    if (in_array($tipo, ['ficha', 'bono', 'saldo'], true)) {
        $where[]  = 'tipo = ?';
        $params[] = $tipo;
    }

    $origen = (string)($_GET['origen'] ?? '');
    if (in_array($origen, ['crm', 'ruleta', 'chatbot', 'sistema'], true)) {
        $where[]  = 'origen = ?';
        $params[] = $origen;
    }

    $usuario = trim((string)($_GET['usuario'] ?? ''));
    if ($usuario !== '') {
        $where[]  = 'usuario LIKE ?';
        $params[] = '%' . $usuario . '%';
    }

    // LOWER() en los dos lados -- ver docblock arriba (bug de casing en
    // crm_auth.php). Con 7 filas hoy no pesa nada; si esto crece mucho y el
    // filtro por operador se usa siempre, evaluar normalizar en escritura
    // en vez de en cada lectura.
    $operador = trim((string)($_GET['operador'] ?? ''));
    if ($operador !== '') {
        $where[]  = 'LOWER(operador) = LOWER(?)';
        $params[] = $operador;
    }

    $desde = (string)($_GET['desde'] ?? '');
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[]  = 'creado_en >= ?';
        $params[] = $desde . ' 00:00:00';
    }

    $hasta = (string)($_GET['hasta'] ?? '');
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[]  = 'creado_en <= ?';
        $params[] = $hasta . ' 23:59:59';
    }

    return [$where, $params];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        if ($accion === 'listar') {
            [$where, $params] = mv_filtros();
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $limit  = min(max((int)($_GET['limit'] ?? 50), 1), 200);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $st = $pdo->prepare(
                "SELECT id, usuario, tipo, monto, motivo, operador, origen, creado_en
                   FROM movimientos
                   $whereSql
                  ORDER BY creado_en DESC, id DESC
                  LIMIT $limit OFFSET $offset"
            );
            $st->execute($params);
            $items = array_map(function ($r) {
                $r['id']    = (int)$r['id'];
                $r['monto'] = (int)$r['monto'];
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            salir(['ok' => true, 'items' => $items]);
        }

        if ($accion === 'contar') {
            [$where, $params] = mv_filtros();
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $st = $pdo->prepare("SELECT COUNT(*) FROM movimientos $whereSql");
            $st->execute($params);

            salir(['ok' => true, 'total' => (int)$st->fetchColumn()]);
        }

        if ($accion === 'operadores') {
            $rows = $pdo->query("SELECT username FROM operadores ORDER BY username")
                        ->fetchAll(PDO::FETCH_COLUMN);
            salir(['ok' => true, 'operadores' => $rows]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_movimientos GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
