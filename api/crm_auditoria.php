<?php
/**
 * crm_auditoria.php — Backend del módulo "Auditoría" (reemplaza al módulo
 * "Transacciones" de crm_movimientos.php, que queda deprecado pero vivo).
 *
 * Unifica TRES fuentes de solo lectura en una sola vista cronológica:
 *   - recargas         (depósitos por transferencia, Módulo 3)
 *   - acciones_saldo   (retiros del panel de ganamos, Módulo 2, tipo='retirar')
 *   - movimientos      (fichas/bono/saldo cargados a mano, ruleta, chatbot)
 *
 * Todo vía UNION ALL con las mismas 9 columnas en el mismo orden para las
 * tres ramas. Es 100% lectura -- no hay POST en este archivo.
 *
 * OJO OPERADOR/ACTOR: ninguna de las 3 tablas tiene una columna "operador"
 * uniforme. Cada una expone el dato distinto:
 *   - recargas: `cancelada_por` (solo si se canceló a mano) o, para
 *     acreditadas, `pagos.asignado_por` vía el pago vinculado (NULL = la
 *     acreditó el matcher automático solo). Ver migración 34.
 *   - acciones_saldo: no tiene columna propia -- el operador que aprobó/
 *     canceló queda embebido como texto dentro de `mensaje`
 *     ("cancelada por USERNAME: nota", ver crm_retiros.php). Se extrae con
 *     REGEXP_REPLACE porque no hay forma limpia de tenerlo aparte sin migrar
 *     datos históricos (fuera de alcance de este módulo, que es de lectura).
 *   - movimientos: columna `operador` directa (puede venir NULL si el
 *     origen es 'ruleta'/'chatbot'/'sistema', o si es una fila vieja de
 *     antes de que existiera la columna).
 *
 * Fila sin ningún rastro de operador = "—" en el front. No se infiere nada
 * (decisión explícita: mejor honesto que adivinado).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
$operador = exigir_operador();

const AU_PER_PAGE = 25;

// Origenes/valores que cuentan como "automático" (bot o sistema), el resto
// con un username no vacío es "manual" (humano). Todo en minúsculas para
// comparar con LOWER() -- mismo criterio de casing que ya usa crm_movimientos.php.
const AU_AUTOMATICOS = ['sistema', 'bot', ''];

function au_salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * La query central: UNION ALL de las 3 fuentes, con exactamente las mismas
 * columnas (fecha, tipo, subtipo, usuario, monto, operador, actor_tipo,
 * detalle, referencia, fuente) en el mismo orden. COLLATE utf8mb4_unicode_ci
 * explícito en toda columna de texto -- las 3 tablas no comparten collation
 * (ver CLAUDE.md), sin esto MySQL tira "Illegal mix of collations" apenas
 * el UNION ALL intenta comparar/ordenar.
 *
 * "monto" siempre positivo (el signo lo decide el tipo en el front); el
 * actor_tipo se calcula acá mismo con CASE para no repetir la lógica en PHP
 * y en SQL.
 */
function au_query_base(): string
{
    return "
      SELECT
        COALESCE(r.acreditada_en, r.creada_en)                                 AS fecha_orden,
        COALESCE(r.acreditada_en, r.creada_en)                                 AS fecha,
        'deposito'                                                             AS tipo,
        r.estado COLLATE utf8mb4_unicode_ci                                    AS subtipo,
        r.usuario COLLATE utf8mb4_unicode_ci                                   AS usuario,
        r.monto_pedido                                                         AS monto,
        COALESCE(r.cancelada_por, p.asignado_por) COLLATE utf8mb4_unicode_ci   AS operador,
        CASE
          WHEN COALESCE(r.cancelada_por, p.asignado_por) IS NULL THEN 'sistema'
          ELSE 'humano'
        END                                                                    AS actor_tipo,
        CONCAT(
          'Recarga ', r.estado, ' — \$', FORMAT(r.monto_pedido, 0),
          CASE WHEN r.mensaje IS NOT NULL AND r.mensaje <> ''
               THEN CONCAT(' | ', r.mensaje) ELSE '' END
        ) COLLATE utf8mb4_unicode_ci                                           AS detalle,
        r.id                                                                   AS referencia,
        'recargas' COLLATE utf8mb4_unicode_ci                                  AS fuente
      FROM recargas r
      LEFT JOIN pagos p ON p.id_unico = r.pago_id

      UNION ALL

      SELECT
        COALESCE(a.ejecutada_en, a.tomada_en, a.creada_en)                     AS fecha_orden,
        COALESCE(a.ejecutada_en, a.tomada_en, a.creada_en)                     AS fecha,
        'retiro'                                                               AS tipo,
        a.estado COLLATE utf8mb4_unicode_ci                                    AS subtipo,
        a.usuario COLLATE utf8mb4_unicode_ci                                   AS usuario,
        a.monto                                                                AS monto,
        REGEXP_REPLACE(a.mensaje, '^.*cancelada por ([^:]+):.*$', '\\\\1')
          COLLATE utf8mb4_unicode_ci                                           AS operador,
        CASE
          WHEN a.mensaje REGEXP '^cancelada por [^:]+:' THEN 'humano'
          ELSE 'sistema'
        END                                                                    AS actor_tipo,
        CONCAT(
          'Retiro \$', FORMAT(a.monto, 0), ' — ', a.estado,
          CASE WHEN a.aprobado = 0 AND a.estado = 'pendiente'
               THEN ' (sin aprobar)' ELSE '' END,
          CASE WHEN a.mensaje IS NOT NULL AND a.mensaje <> ''
               THEN CONCAT(' | ', a.mensaje) ELSE '' END
        ) COLLATE utf8mb4_unicode_ci                                           AS detalle,
        a.id                                                                   AS referencia,
        'acciones_saldo' COLLATE utf8mb4_unicode_ci                            AS fuente
      FROM acciones_saldo a
      WHERE a.tipo = 'retirar'

      UNION ALL

      SELECT
        m.creado_en                                                            AS fecha_orden,
        m.creado_en                                                            AS fecha,
        CASE m.tipo WHEN 'ficha' THEN 'ajuste' WHEN 'saldo' THEN 'ajuste' ELSE m.tipo END
                                                                                AS tipo,
        m.origen COLLATE utf8mb4_unicode_ci                                    AS subtipo,
        m.usuario COLLATE utf8mb4_unicode_ci                                   AS usuario,
        ABS(m.monto)                                                           AS monto,
        m.operador COLLATE utf8mb4_unicode_ci                                  AS operador,
        CASE
          WHEN m.operador IS NOT NULL AND m.operador <> '' THEN 'humano'
          WHEN m.origen = 'ruleta' THEN 'sistema'
          WHEN m.origen = 'chatbot' THEN 'bot'
          ELSE 'sistema'
        END                                                                    AS actor_tipo,
        COALESCE(m.motivo, CONCAT(m.tipo, ' ', IF(m.monto>=0,'+',''), m.monto))
          COLLATE utf8mb4_unicode_ci                                           AS detalle,
        m.id                                                                   AS referencia,
        'movimientos' COLLATE utf8mb4_unicode_ci                               AS fuente
      FROM movimientos m
    ";
}

/**
 * WHERE aplicado AFUERA del UNION ALL (sobre la subquery ya armada) para no
 * repetir cada filtro 3 veces con columnas de nombre distinto por rama.
 * Devuelve [whereSql, params].
 */
function au_filtros(): array
{
    $where  = ['fecha_orden IS NOT NULL'];
    $params = [];

    $desde = (string)($_GET['desde'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) { $desde = date('Y-m-d', strtotime('-30 days')); }
    $where[]  = 'fecha_orden >= ?';
    $params[] = $desde . ' 00:00:00';

    $hasta = (string)($_GET['hasta'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) { $hasta = date('Y-m-d'); }
    $where[]  = 'fecha_orden <= ?';
    $params[] = $hasta . ' 23:59:59';

    $tipo = (string)($_GET['tipo'] ?? 'todos');
    if (in_array($tipo, ['deposito', 'retiro', 'bono', 'ajuste'], true)) {
        $where[]  = 'tipo = ?';
        $params[] = $tipo;
    }

    $actor = (string)($_GET['actor'] ?? 'todos');
    if ($actor === 'manual') {
        $where[] = "actor_tipo = 'humano'";
    } elseif ($actor === 'automatico') {
        $where[] = "actor_tipo IN ('bot','sistema')";
    }

    $op = trim((string)($_GET['operador'] ?? ''));
    if ($op !== '') {
        $where[]  = 'LOWER(operador) = LOWER(?)';
        $params[] = $op;
    }

    $usuario = trim((string)($_GET['usuario'] ?? ''));
    if ($usuario !== '') {
        $where[]  = 'usuario LIKE ?';
        $params[] = '%' . $usuario . '%';
    }

    return [implode(' AND ', $where), $params, $desde, $hasta];
}

/** Los KPIs comparten los mismos filtros de fecha/tipo/actor/operador/usuario
 *  que el listado -- se reusa au_filtros(), solo se descartan page/paginas
 *  (no aplican a un agregado). Nombrada aparte para que el llamador no
 *  tenga que saber que internamente es la misma función. */
function au_filtros_kpis(): array
{
    [$whereSql, $params] = au_filtros();
    return [$whereSql, $params];
}

function au_fila(array $r): array
{
    $operador = $r['operador'] !== null && $r['operador'] !== '' ? $r['operador'] : null;
    // "Bot"/"Sistema" como etiqueta legible para el front cuando no hay
    // operador humano -- el front decide el ícono según actor_tipo, esto
    // solo evita que 'operador' llegue vacío cuando sí sabemos QUIÉN (aunque
    // no sea una persona).
    if ($operador === null) {
        if ($r['actor_tipo'] === 'bot') { $operador = 'Bot'; }
        elseif ($r['actor_tipo'] === 'sistema') { $operador = 'Sistema'; }
    }
    return [
        'fecha'      => $r['fecha'],
        'tipo'       => $r['tipo'],
        'subtipo'    => $r['subtipo'],
        'usuario'    => $r['usuario'],
        'monto'      => (int)round((float)$r['monto']),
        'operador'   => $operador,
        'actor_tipo' => $r['actor_tipo'],
        'detalle'    => $r['detalle'],
        'referencia' => (int)$r['referencia'],
        'fuente'     => $r['fuente'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    au_salir(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$accion = (string)($_GET['accion'] ?? 'listar');

try {
    // ============================== KPIS ====================================
    if ($accion === 'kpis') {
        [$whereSql, $params] = au_filtros_kpis();
        $sql = "SELECT tipo, actor_tipo, COUNT(*) AS cantidad, SUM(monto) AS monto
                  FROM (" . au_query_base() . ") x
                 WHERE $whereSql
                 GROUP BY tipo, actor_tipo";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $total = 0; $depCant = 0; $depMonto = 0; $retCant = 0; $retMonto = 0;
        $manuales = 0; $automaticos = 0;
        foreach ($rows as $r) {
            $cant = (int)$r['cantidad'];
            $monto = (float)$r['monto'];
            $total += $cant;
            if ($r['tipo'] === 'deposito') { $depCant += $cant; $depMonto += $monto; }
            if ($r['tipo'] === 'retiro')   { $retCant += $cant; $retMonto += $monto; }
            if ($r['actor_tipo'] === 'humano') { $manuales += $cant; } else { $automaticos += $cant; }
        }

        au_salir(['ok' => true, 'kpis' => [
            'total_operaciones' => $total,
            'depositos' => ['cantidad' => $depCant, 'monto' => (int)round($depMonto)],
            'retiros'   => ['cantidad' => $retCant, 'monto' => (int)round($retMonto)],
            'manuales'    => $manuales,
            'automaticos' => $automaticos,
        ]]);
    }

    // ============================== LISTAR ===================================
    if ($accion === 'listar') {
        [$whereSql, $params] = au_filtros();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * AU_PER_PAGE;

        $base = "(" . au_query_base() . ") x WHERE $whereSql";

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM $base");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();
        $paginas = max(1, (int)ceil($total / AU_PER_PAGE));

        $st = $pdo->prepare(
            "SELECT * FROM $base ORDER BY fecha_orden DESC LIMIT " . AU_PER_PAGE . " OFFSET $offset"
        );
        $st->execute($params);
        $data = array_map('au_fila', $st->fetchAll(PDO::FETCH_ASSOC));

        // Operadores únicos del período (para el dropdown), sin volver a
        // filtrar por operador/actor -- si no, elegir un operador se
        // "come" al resto de las opciones del propio dropdown.
        $stOps = $pdo->prepare(
            "SELECT DISTINCT operador FROM (" . au_query_base() . ") x
              WHERE fecha_orden BETWEEN ? AND ? AND operador IS NOT NULL
                AND LOWER(operador) NOT IN ('bot','sistema')
              ORDER BY operador"
        );
        $stOps->execute([$params[0], $params[1]]);
        $operadores = $stOps->fetchAll(PDO::FETCH_COLUMN);

        au_salir([
            'ok' => true, 'data' => $data, 'total' => $total,
            'page' => $page, 'paginas' => $paginas, 'operadores' => $operadores,
        ]);
    }

    // ============================ EXPORT CSV =================================
    if ($accion === 'export_csv') {
        [$whereSql, $params, $desde, $hasta] = au_filtros();
        $sql = "SELECT * FROM (" . au_query_base() . ") x WHERE $whereSql ORDER BY fecha_orden DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auditoria_' . $desde . '_' . $hasta . '.csv"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
        fputcsv($out, ['Fecha', 'Tipo', 'Usuario', 'Monto', 'Estado', 'Operador', 'Actor', 'Detalle', 'Referencia', 'Fuente']);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $f = au_fila($r);
            fputcsv($out, [
                $f['fecha'], $f['tipo'], $f['usuario'], $f['monto'], $f['subtipo'],
                $f['operador'] ?? '—', $f['actor_tipo'], $f['detalle'], $f['referencia'], $f['fuente'],
            ]);
        }
        fclose($out);
        exit;
    }

    au_salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
} catch (Throwable $e) {
    error_log('crm_auditoria: ' . $e->getMessage());
    au_salir(['ok' => false, 'error' => 'Error al consultar', 'detalle' => $e->getMessage()], 500);
}
