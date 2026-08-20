<?php
/**
 * crm_finanzas.php — Backend del módulo "Finanzas" (Fase A, Módulo 6.A).
 *
 * 100% lectura. Ningún endpoint de este archivo escribe en `recargas`,
 * `acciones_saldo`, `movimientos` ni `usuarios` — solo `verificar_password`
 * escribe, y únicamente en la sesión propia ($_SESSION['finanzas_ok']).
 *
 * MODELO CONTABLE (definido con Nahuel, ver CRM_DESIGN.md / conversación de
 * Módulo 6 M.1 — no cambiar estas fórmulas sin su ok):
 *
 *   Efectivo neto histórico = SUM(recargas.monto_pedido, acreditada)
 *                              - SUM(acciones_saldo.monto, retirar+hecha)
 *   Fichas en poder de jugadores (pasivo) = SUM(usuarios.balance)
 *     -- OJO: usuarios.coins es un contador residual/experimental, NO
 *     -- representa el pasivo real. Nunca sumarlo acá.
 *   Patrimonio neto = Efectivo neto - Fichas en poder de jugadores
 *     -- En M6.A el stock de fichas propio (activo) todavía no se mide
 *     -- (llega en M6.B con `agencia_estado`), así que este patrimonio es
 *     -- una subestimación a propósito, no un error.
 *   Costo de fichas del período = (Ingresos + Bonos) × FINANZAS_COSTO_POR_FICHA
 *   Ganancia (hoy/período) = Ingresos - Retiros - Costo de fichas
 *
 * POR QUÉ 'hecha' y no cualquier estado de acciones_saldo: confirmado
 * leyendo acciones_cola.php — 'hecha' se escribe SOLO después de que el bot
 * leyó el saldo real en el panel de ganamos (comprobante real, no una
 * promesa). 'pendiente'/'procesando'/'revisar' NO son plata que salió.
 *
 * POR QUÉ movimientos y no acciones_saldo para bonos: crm_cargar() inserta
 * el movimiento en el momento de la carga, sin cola de por medio (no es
 * plata real de ganamos, es "de la casa") — no hay equivalente a 'hecha'
 * que esperar.
 *
 * COLLATE: `recargas`/`pagos`/`usuarios` quedaron en utf8mb4_uca1400_ai_ci;
 * `acciones_saldo`/`movimientos` en utf8mb4_unicode_ci (confirmado con
 * SHOW TABLE STATUS). Toda comparación/UNION que cruce `recargas.usuario`
 * contra las otras dos lleva COLLATE utf8mb4_unicode_ci explícito.
 *
 * Seguridad: exigir_operador() en TODO (incluida verificar_password).
 * Además, todo salvo verificar_password exige $_SESSION['finanzas_ok']
 * (se pone en true recién al re-tipear la contraseña correcta). Al hacer
 * logout, crm_auth.php::operador_logout() vacía $_SESSION entero — se
 * lleva finanzas_ok con eso, no hace falta nada extra acá.
 *
 * POST { accion:"verificar_password", password }  -> { ok, error? }
 * GET  ?accion=foto                                -> activos/pasivos/patrimonio actuales
 * GET  ?accion=hoy                                 -> operación del día (DEPRECADO desde el
 *                                                      rediseño de dashboard unificado -- "Hoy"
 *                                                      ahora es un filtro más de ?accion=rango.
 *                                                      Queda vivo, sin uso desde el frontend
 *                                                      nuevo. Ver TODO_FASE_A.md.)
 * GET  ?accion=rango&desde=YYYY-MM-DD&hasta=YYYY-MM-DD&filtro=
 *      -> KPIs del período + alertas + comparación contra el período anterior.
 *         `filtro` es opcional, afecta SOLO cómo se calcula el período de
 *         comparación (ver fn_periodo_anterior()): 'mes' = tramo contra
 *         tramo del mes calendario anterior; 'todo' = sin comparación
 *         (variacion: null); cualquier otro valor (o ausente) = mismo
 *         largo de días inmediatamente antes de $desde.
 * GET  ?accion=graficos&desde=&hasta=              -> series para los 5 gráficos
 *                                                      (por_dia + por_hora)
 * GET  ?accion=export_csv&desde=&hasta=            -> CSV de detalle del período
 * GET  ?accion=export_json&desde=&hasta=           -> JSON pensado para pegar en un LLM
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';

$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Config con default defensivo: si no están en config.local.php, el módulo
// sigue andando con estos valores (los mismos que Nahuel confirmó).
$costoPorFicha   = (float)cfg('FINANZAS_COSTO_POR_FICHA', '0.20');
$umbralGrande    = (float)cfg('FINANZAS_UMBRAL_RETIRO_GRANDE', '50000');
$umbralMuyGrande = (float)cfg('FINANZAS_UMBRAL_RETIRO_MUY_GRANDE', '200000');
$umbralGanador   = (float)cfg('FINANZAS_UMBRAL_ALERTA_JUGADOR_GANADOR', '10000');

/** Valida desde/hasta de $_GET (YYYY-MM-DD). Corta la ejecución si es inválido. */
function fn_rango_fechas(): array
{
    $desde = (string)($_GET['desde'] ?? '');
    $hasta = (string)($_GET['hasta'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        salir(['ok' => false, 'error' => 'Rango de fechas inválido (usar YYYY-MM-DD)'], 400);
    }
    if ($desde > $hasta) {
        salir(['ok' => false, 'error' => 'La fecha "desde" no puede ser posterior a "hasta"'], 400);
    }
    return [$desde, $hasta];
}

/** Ingresos: recargas acreditadas en [desde, hasta] (inclusive). */
function fn_ingresos(PDO $pdo, string $desde, string $hasta): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(monto_pedido),0) monto, COALESCE(AVG(monto_pedido),0) promedio
           FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto'], 'promedio' => (float)$r['promedio']];
}

/** Retiros: acciones_saldo tipo=retirar CONFIRMADAS ('hecha') en [desde, hasta]. */
function fn_retiros(PDO $pdo, string $desde, string $hasta): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(monto),0) monto, COALESCE(AVG(monto),0) promedio
           FROM acciones_saldo
          WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto'], 'promedio' => (float)$r['promedio']];
}

/** Bonos regalados (positivos únicamente -- un monto negativo es un ajuste, no un regalo). */
function fn_bonos(PDO $pdo, string $desde, string $hasta): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(monto),0) monto
           FROM movimientos
          WHERE tipo='bono' AND monto > 0 AND creado_en >= ? AND creado_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto']];
}

/** Jugadores nuevos: alta en ganamos (usuarios.creation_date) dentro del período. */
function fn_nuevos(PDO $pdo, string $desde, string $hasta): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE creation_date >= ? AND creation_date < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    return (int)$st->fetchColumn();
}

/** Jugadores activos: recargó (acreditada) al menos una vez en el período -- opción A confirmada. */
function fn_activos(PDO $pdo, string $desde, string $hasta): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT usuario) FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    return (int)$st->fetchColumn();
}

/**
 * Retención: de los activos del período ANTERIOR (misma cantidad de días,
 * inmediatamente antes de $desde), cuántos volvieron a estar activos en
 * el período actual. `porcentaje` null si el período anterior no tuvo
 * ningún activo (división por cero evitada a propósito).
 */
function fn_retencion(PDO $pdo, string $desde, string $hasta): array
{
    $dias      = (int)((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
    $prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
    $prevDesde = date('Y-m-d', strtotime($prevHasta . ' -' . ($dias - 1) . ' days'));

    $activosPrev = fn_activos($pdo, $prevDesde, $prevHasta);
    if ($activosPrev === 0) {
        return ['retenidos' => 0, 'activos_periodo_anterior' => 0, 'porcentaje' => null,
                'periodo_anterior' => ['desde' => $prevDesde, 'hasta' => $prevHasta]];
    }

    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT act.usuario)
           FROM (SELECT DISTINCT usuario FROM recargas
                  WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY) act
           JOIN (SELECT DISTINCT usuario FROM recargas
                  WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY) prev
             ON act.usuario = prev.usuario"
    );
    $st->execute([$desde, $hasta, $prevDesde, $prevHasta]);
    $retenidos = (int)$st->fetchColumn();

    return [
        'retenidos'               => $retenidos,
        'activos_periodo_anterior' => $activosPrev,
        'porcentaje'              => round($retenidos / $activosPrev * 100, 1),
        'periodo_anterior'        => ['desde' => $prevDesde, 'hasta' => $prevHasta],
    ];
}

/**
 * Rango del período de comparación, según el filtro que mandó el frontend.
 * Devuelve null si NO corresponde comparar (filtro=todo, o un rango que
 * excede cualquier período histórico razonable -- protección server-side
 * aunque el frontend no mande filtro=todo, por si alguien pega un rango
 * absurdo a mano en la URL).
 *
 * filtro='mes': tramo contra tramo del mes calendario anterior (mismos
 * días 1..N), recortado si el mes anterior tiene menos días (ej. 31 de
 * agosto compara contra el 28/29 de febrero, nunca un 31 de febrero
 * inexistente). Comparar contra el mes anterior COMPLETO sería engañoso
 * con un mes en curso parcial (Nahuel, confirmado).
 *
 * Cualquier otro filtro (hoy/ayer/7d/30d/90d/custom): mismo largo de
 * días, inmediatamente antes de $desde -- mismo mecanismo que ya usaba
 * fn_retencion() para su propio período anterior, reusado acá.
 */
function fn_periodo_anterior(string $filtro, string $desde, string $hasta): ?array
{
    $dias = (int)((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
    if ($filtro === 'todo' || $desde < '2024-01-01' || $dias > 5 * 365) {
        return null;
    }

    if ($filtro === 'mes') {
        $inicioMes           = new DateTime(substr($desde, 0, 8) . '01');
        $mesAnteriorInicio    = (clone $inicioMes)->modify('first day of last month');
        $diaActual            = (int)(new DateTime($hasta))->format('j');
        $ultimoDiaMesAnterior = (int)$mesAnteriorInicio->format('t');
        $diaFin               = min($diaActual, $ultimoDiaMesAnterior);
        $prevDesde = $mesAnteriorInicio->format('Y-m-d');
        $prevHasta = (clone $mesAnteriorInicio)->modify('+' . ($diaFin - 1) . ' days')->format('Y-m-d');
        return ['desde' => $prevDesde, 'hasta' => $prevHasta];
    }

    $prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
    $prevDesde = date('Y-m-d', strtotime($prevHasta . ' -' . ($dias - 1) . ' days'));
    return ['desde' => $prevDesde, 'hasta' => $prevHasta];
}

/**
 * Variación entre dos números, con los 4 casos especiales que definió
 * Nahuel (evitan un "+inf%" o un "-100%" enganañoso):
 *   previo=0, actual=0  -> sin_datos (el frontend muestra "—")
 *   previo=0, actual>0  -> nuevo     (el frontend muestra "Nuevo" en verde)
 *   previo>0, actual=0  -> -100% exacto, dir=down
 *   caso normal         -> pct real, redondeado a 1 decimal
 * `dir` es solo la dirección numérica (up/down/flat) -- el color
 * (bueno/malo) lo decide el frontend según el KPI (retiros invierte).
 */
function fn_variacion(float $actual, float $previo): array
{
    if ($previo === 0.0 && $actual === 0.0) {
        return ['dir' => 'sin_datos', 'pct' => null];
    }
    if ($previo === 0.0 && $actual > 0.0) {
        return ['dir' => 'nuevo', 'pct' => null];
    }
    if ($previo > 0.0 && $actual === 0.0) {
        return ['dir' => 'down', 'pct' => -100.0];
    }
    $pct = round((($actual - $previo) / $previo) * 100, 1);
    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    return ['dir' => $dir, 'pct' => $pct];
}

/**
 * Serie diaria para los gráficos de línea/barra por día. Cuatro queries
 * (una por fuente) mergeadas por fecha en PHP, sobre la secuencia
 * COMPLETA de días del rango -- un día sin actividad sale en 0, no falta
 * (un gráfico con huecos es peor que uno con ceros).
 */
function fn_serie_por_dia(PDO $pdo, string $desde, string $hasta, float $costoPorFicha): array
{
    $ing = $pdo->prepare(
        "SELECT DATE(acreditada_en) fecha, SUM(monto_pedido) monto FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
          GROUP BY DATE(acreditada_en)"
    );
    $ing->execute([$desde, $hasta]);
    $porIngresos = [];
    foreach ($ing->fetchAll(PDO::FETCH_ASSOC) as $r) { $porIngresos[$r['fecha']] = (float)$r['monto']; }

    $ret = $pdo->prepare(
        "SELECT DATE(ejecutada_en) fecha, SUM(monto) monto FROM acciones_saldo
          WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
          GROUP BY DATE(ejecutada_en)"
    );
    $ret->execute([$desde, $hasta]);
    $porRetiros = [];
    foreach ($ret->fetchAll(PDO::FETCH_ASSOC) as $r) { $porRetiros[$r['fecha']] = (float)$r['monto']; }

    $bon = $pdo->prepare(
        "SELECT DATE(creado_en) fecha, SUM(monto) monto FROM movimientos
          WHERE tipo='bono' AND monto > 0 AND creado_en >= ? AND creado_en < ? + INTERVAL 1 DAY
          GROUP BY DATE(creado_en)"
    );
    $bon->execute([$desde, $hasta]);
    $porBonos = [];
    foreach ($bon->fetchAll(PDO::FETCH_ASSOC) as $r) { $porBonos[$r['fecha']] = (float)$r['monto']; }

    $act = $pdo->prepare(
        "SELECT DATE(acreditada_en) fecha, COUNT(DISTINCT usuario) activos FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
          GROUP BY DATE(acreditada_en)"
    );
    $act->execute([$desde, $hasta]);
    $porActivos = [];
    foreach ($act->fetchAll(PDO::FETCH_ASSOC) as $r) { $porActivos[$r['fecha']] = (int)$r['activos']; }

    $serie   = [];
    $cursor  = new DateTime($desde);
    $fin     = new DateTime($hasta);
    while ($cursor <= $fin) {
        $f           = $cursor->format('Y-m-d');
        $ingresosDia = $porIngresos[$f] ?? 0.0;
        $bonosDia    = $porBonos[$f] ?? 0.0;
        $retirosDia  = $porRetiros[$f] ?? 0.0;
        $costoDia    = ($ingresosDia + $bonosDia) * $costoPorFicha;
        $serie[] = [
            'fecha'    => $f,
            'ingresos' => $ingresosDia,
            'retiros'  => $retirosDia,
            'ganancia' => $ingresosDia - $retirosDia - $costoDia,
            'bonos'    => $bonosDia,
            'activos'  => $porActivos[$f] ?? 0,
        ];
        $cursor->modify('+1 day');
    }
    return $serie;
}

/**
 * Serie por hora del día (0-23), recargas + retiros COMBINADOS (Nahuel,
 * confirmado -- sin separar por tipo, sin bonos: es "cuándo hay
 * movimiento de plata", no "cuándo regala el operador"). Agregado sobre
 * TODO el rango, no por día -- es un histograma de "a qué hora", no una
 * serie temporal.
 */
function fn_serie_por_hora(PDO $pdo, string $desde, string $hasta): array
{
    $horas = array_fill(0, 24, 0);

    $ing = $pdo->prepare(
        "SELECT HOUR(acreditada_en) hora, COUNT(*) cantidad FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
          GROUP BY HOUR(acreditada_en)"
    );
    $ing->execute([$desde, $hasta]);
    foreach ($ing->fetchAll(PDO::FETCH_ASSOC) as $r) { $horas[(int)$r['hora']] += (int)$r['cantidad']; }

    $ret = $pdo->prepare(
        "SELECT HOUR(ejecutada_en) hora, COUNT(*) cantidad FROM acciones_saldo
          WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
          GROUP BY HOUR(ejecutada_en)"
    );
    $ret->execute([$desde, $hasta]);
    foreach ($ret->fetchAll(PDO::FETCH_ASSOC) as $r) { $horas[(int)$r['hora']] += (int)$r['cantidad']; }

    $serie = [];
    foreach ($horas as $hora => $cantidad) {
        $serie[] = ['hora' => $hora, 'cantidad' => $cantidad];
    }
    return $serie;
}

/** Foto del momento: activos/pasivo históricos, sin rango de fecha. */
function fn_foto(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT
            (SELECT COALESCE(SUM(monto_pedido),0) FROM recargas WHERE estado='acreditada') AS ingresos_historicos,
            (SELECT COALESCE(SUM(monto),0) FROM acciones_saldo WHERE tipo='retirar' AND estado='hecha') AS retiros_historicos,
            (SELECT COALESCE(SUM(balance),0) FROM usuarios) AS fichas_jugadores"
    )->fetch(PDO::FETCH_ASSOC);

    $efectivoNeto    = (float)$row['ingresos_historicos'] - (float)$row['retiros_historicos'];
    $fichasJugadores = (float)$row['fichas_jugadores'];

    return [
        'efectivo_neto'    => $efectivoNeto,
        'stock_fichas'     => null,   // pendiente M6.B (agencia_estado)
        'valor_stock'      => null,   // pendiente M6.B
        'fichas_jugadores' => $fichasJugadores,
        'patrimonio_neto'  => $efectivoNeto - $fichasJugadores,
    ];
}

/**
 * Alertas del período, en una lista plana y autodescriptiva (cada elemento
 * lleva su propio "tipo") -- pensado para que tanto la UI como el export a
 * JSON usen la misma forma sin transformar nada.
 */
function fn_alertas(
    PDO $pdo,
    string $desde,
    string $hasta,
    float $umbralGrande,
    float $umbralMuyGrande,
    float $umbralGanador
): array {
    $alertas = [];

    // ---- jugadores ganadores acumulados: retiraron mas de lo que
    // recargaron en el periodo, por mas de $umbralGanador (usuario cruza
    // recargas <-> acciones_saldo, distinta collation -- ver docblock
    // arriba). El piso evita ensuciar la alerta con diferencias chicas
    // que no ameritan revisarse (ver FINANZAS_UMBRAL_ALERTA_JUGADOR_GANADOR). ----
    $st = $pdo->prepare(
        "SELECT u.usuario, COALESCE(rec.total, 0) AS recargas, COALESCE(ret.total, 0) AS retiros
           FROM (
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM recargas
                  WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
                 UNION
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
                ) u
           LEFT JOIN (
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario, SUM(monto_pedido) AS total FROM recargas
                  WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY GROUP BY usuario
                ) rec ON rec.usuario = u.usuario
           LEFT JOIN (
                 SELECT usuario, SUM(monto) AS total FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY GROUP BY usuario
                ) ret ON ret.usuario = u.usuario
          HAVING retiros > recargas AND (retiros - recargas) > ?
          ORDER BY (retiros - recargas) DESC"
    );
    $st->execute([$desde, $hasta, $desde, $hasta, $desde, $hasta, $desde, $hasta, $umbralGanador]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $alertas[] = [
            'tipo'     => 'mas_retiros_que_recargas',
            'usuario'  => $r['usuario'],
            'recargas' => (float)$r['recargas'],
            'retiros'  => (float)$r['retiros'],
        ];
    }

    // ---- retiros grandes/muy grandes TODAVÍA pendientes de ejecutar
    // (alerta operativa, no depende del rango de fecha del período) ----
    $st2 = $pdo->prepare(
        "SELECT id, usuario, monto, estado, creada_en, tomada_en,
                CASE WHEN monto > ? THEN 'muy_grande' ELSE 'grande' END AS categoria
           FROM acciones_saldo
          WHERE tipo='retirar' AND estado IN ('pendiente','procesando','revisar') AND monto > ?
          ORDER BY monto DESC"
    );
    $st2->execute([$umbralMuyGrande, $umbralGrande]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $alertas[] = [
            'tipo'      => $r['categoria'] === 'muy_grande' ? 'retiro_muy_grande' : 'retiro_grande',
            'id'        => (int)$r['id'],
            'usuario'   => $r['usuario'],
            'monto'     => (float)$r['monto'],
            'estado'    => $r['estado'],
            'creada_en' => $r['creada_en'],
        ];
    }

    return $alertas;
}

/**
 * Top 10 jugadores por monto recargado en el período (para dar contexto
 * concreto al pegar export_json en un LLM). Mismo cruce recargas<->acciones_saldo
 * que fn_alertas(), mismo COLLATE explícito por el mismo motivo.
 */
function fn_top_jugadores(PDO $pdo, string $desde, string $hasta): array
{
    $st = $pdo->prepare(
        "SELECT u.usuario,
                COALESCE(rec.monto, 0) AS recargas_monto, COALESCE(rec.cantidad, 0) AS recargas_cantidad,
                COALESCE(ret.monto, 0) AS retiros_monto, COALESCE(ret.cantidad, 0) AS retiros_cantidad
           FROM (
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM recargas
                  WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
                 UNION
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
                ) u
           LEFT JOIN (
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario, SUM(monto_pedido) AS monto, COUNT(*) AS cantidad
                   FROM recargas WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
                  GROUP BY usuario
                ) rec ON rec.usuario = u.usuario
           LEFT JOIN (
                 SELECT usuario, SUM(monto) AS monto, COUNT(*) AS cantidad
                   FROM acciones_saldo WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
                  GROUP BY usuario
                ) ret ON ret.usuario = u.usuario
          ORDER BY recargas_monto DESC
          LIMIT 10"
    );
    $st->execute([$desde, $hasta, $desde, $hasta, $desde, $hasta, $desde, $hasta]);

    return array_map(function ($r) {
        $recargasMonto = (float)$r['recargas_monto'];
        $retirosMonto  = (float)$r['retiros_monto'];
        return [
            'usuario'            => $r['usuario'],
            'recargas_monto'     => $recargasMonto,
            'recargas_cantidad'  => (int)$r['recargas_cantidad'],
            'retiros_monto'      => $retirosMonto,
            'retiros_cantidad'   => (int)$r['retiros_cantidad'],
            'neto'               => $recargasMonto - $retirosMonto,
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Detalle fila por fila del período, para el CSV. Une 3 fuentes distintas
 * (recargas/acciones_saldo/movimientos) bajo la misma forma de columnas --
 * mismo cuidado de COLLATE que fn_alertas() en la rama de recargas.
 */
function fn_detalle_periodo(PDO $pdo, string $desde, string $hasta): array
{
    $st = $pdo->prepare(
        "SELECT acreditada_en AS fecha, 'recarga' AS tipo, usuario COLLATE utf8mb4_unicode_ci AS usuario,
                monto_pedido AS monto, estado, NULL AS operador, referencia
           FROM recargas
          WHERE estado='acreditada' AND acreditada_en >= ? AND acreditada_en < ? + INTERVAL 1 DAY
         UNION ALL
         SELECT ejecutada_en AS fecha, 'retiro' AS tipo, usuario AS usuario,
                monto, estado, NULL AS operador, NULL AS referencia
           FROM acciones_saldo
          WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
         UNION ALL
         SELECT creado_en AS fecha, 'bono' AS tipo, usuario AS usuario,
                monto, NULL AS estado, operador, NULL AS referencia
           FROM movimientos
          WHERE tipo='bono' AND monto > 0 AND creado_en >= ? AND creado_en < ? + INTERVAL 1 DAY
          ORDER BY fecha DESC"
    );
    $st->execute([$desde, $hasta, $desde, $hasta, $desde, $hasta]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================== POST ========================================
// Solo vive acá "verificar_password" -- es el único endpoint que NO exige
// finanzas_ok (sería una paradoja: exigirlo para poder ponerlo en true).
if ($metodo === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    if ($accion !== 'verificar_password') {
        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    }

    try {
        if (!crm_rate_limite("finanzas_verify_$operador", 5, 900)) {
            salir(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.'], 429);
        }

        $password = (string)($body['password'] ?? '');
        if ($password === '') {
            salir(['ok' => false, 'error' => 'Falta la contraseña'], 400);
        }

        $st = $pdo->prepare("SELECT password_hash FROM operadores WHERE username = ? LIMIT 1");
        $st->execute([$operador]);
        $hash = $st->fetchColumn();

        if (!$hash || !password_verify($password, (string)$hash)) {
            salir(['ok' => false, 'error' => 'Contraseña incorrecta'], 401);
        }

        $_SESSION['finanzas_ok'] = true;
        salir(['ok' => true]);
    } catch (Throwable $e) {
        error_log('crm_finanzas verificar_password: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error'], 500);
    }
}

// ============================== GET =========================================
if ($metodo === 'GET') {
    $accion = (string)($_GET['accion'] ?? '');

    if (empty($_SESSION['finanzas_ok'])) {
        salir(['ok' => false, 'error' => 'Reverificación requerida'], 403);
    }

    try {
        if ($accion === 'foto') {
            salir(['ok' => true, 'foto' => fn_foto($pdo)]);
        }

        if ($accion === 'hoy') {
            $hoy      = date('Y-m-d');
            $ingresos = fn_ingresos($pdo, $hoy, $hoy);
            $retiros  = fn_retiros($pdo, $hoy, $hoy);
            $bonos    = fn_bonos($pdo, $hoy, $hoy);
            $costoFichas = ($ingresos['monto'] + $bonos['monto']) * $costoPorFicha;

            salir(['ok' => true, 'hoy' => [
                'fecha'           => $hoy,
                'recargas'        => $ingresos,
                'retiros'         => $retiros,
                'bonos'           => $bonos,
                'costo_fichas'    => $costoFichas,
                'ganancia'        => $ingresos['monto'] - $retiros['monto'] - $costoFichas,
                'costo_por_ficha' => $costoPorFicha,
            ]]);
        }

        if ($accion === 'rango') {
            [$desde, $hasta] = fn_rango_fechas();
            $filtro = (string)($_GET['filtro'] ?? '');

            $ingresos  = fn_ingresos($pdo, $desde, $hasta);
            $retiros   = fn_retiros($pdo, $desde, $hasta);
            $bonos     = fn_bonos($pdo, $desde, $hasta);
            $nuevos    = fn_nuevos($pdo, $desde, $hasta);
            $activos   = fn_activos($pdo, $desde, $hasta);
            $retencion = fn_retencion($pdo, $desde, $hasta);
            $costoFichas   = ($ingresos['monto'] + $bonos['monto']) * $costoPorFicha;
            $gananciaBruta = $ingresos['monto'] - $retiros['monto'] - $costoFichas;

            // Comparación contra el período anterior -- null si no
            // corresponde (filtro=todo, o el auto-detectado en
            // fn_periodo_anterior()). Reusa fn_ingresos/fn_retiros/
            // fn_activos ya escritas, cero duplicación de las queries.
            $periodoAnterior = fn_periodo_anterior($filtro, $desde, $hasta);
            $comparacion = null;
            $variacion   = null;
            if ($periodoAnterior !== null) {
                $ingresosPrev = fn_ingresos($pdo, $periodoAnterior['desde'], $periodoAnterior['hasta']);
                $retirosPrev  = fn_retiros($pdo, $periodoAnterior['desde'], $periodoAnterior['hasta']);
                $bonosPrev    = fn_bonos($pdo, $periodoAnterior['desde'], $periodoAnterior['hasta']);
                $activosPrev  = fn_activos($pdo, $periodoAnterior['desde'], $periodoAnterior['hasta']);
                $costoFichasPrev   = ($ingresosPrev['monto'] + $bonosPrev['monto']) * $costoPorFicha;
                $gananciaBrutaPrev = $ingresosPrev['monto'] - $retirosPrev['monto'] - $costoFichasPrev;

                $comparacion = [
                    'periodo_anterior'  => $periodoAnterior,
                    'ingresos'          => $ingresosPrev['monto'],
                    'retiros'           => $retirosPrev['monto'],
                    'ganancia_bruta'    => $gananciaBrutaPrev,
                    'jugadores_activos' => $activosPrev,
                ];
                $variacion = [
                    'ingresos'          => fn_variacion($ingresos['monto'], $ingresosPrev['monto']),
                    'retiros'           => fn_variacion($retiros['monto'], $retirosPrev['monto']),
                    'ganancia_bruta'    => fn_variacion($gananciaBruta, $gananciaBrutaPrev),
                    'jugadores_activos' => fn_variacion((float)$activos, (float)$activosPrev),
                ];
            }

            salir(['ok' => true, 'rango' => [
                'desde'             => $desde,
                'hasta'             => $hasta,
                'ingresos'          => $ingresos,
                'retiros'           => $retiros,
                'bonos'             => $bonos,
                'costo_fichas'      => $costoFichas,
                'ganancia_bruta'    => $gananciaBruta,
                'costo_por_ficha'   => $costoPorFicha,
                'jugadores_nuevos'  => $nuevos,
                'jugadores_activos' => $activos,
                'retencion'         => $retencion,
            ],
                'comparacion' => $comparacion,
                'variacion'   => $variacion,
                'alertas'     => fn_alertas($pdo, $desde, $hasta, $umbralGrande, $umbralMuyGrande, $umbralGanador),
                'umbrales'    => [
                    'retiro_grande'      => $umbralGrande,
                    'retiro_muy_grande'  => $umbralMuyGrande,
                    'jugador_ganador'    => $umbralGanador,
                ],
            ]);
        }

        if ($accion === 'graficos') {
            [$desde, $hasta] = fn_rango_fechas();

            salir(['ok' => true, 'graficos' => [
                'por_dia'  => fn_serie_por_dia($pdo, $desde, $hasta, $costoPorFicha),
                'por_hora' => fn_serie_por_hora($pdo, $desde, $hasta),
            ]]);
        }

        if ($accion === 'export_csv') {
            [$desde, $hasta] = fn_rango_fechas();
            $filas = fn_detalle_periodo($pdo, $desde, $hasta);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="finanzas_' . $desde . '_' . $hasta . '.csv"');
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, ['fecha', 'tipo', 'usuario', 'monto', 'estado', 'operador', 'referencia']);
            foreach ($filas as $f) {
                fputcsv($out, [
                    $f['fecha'], $f['tipo'], $f['usuario'], $f['monto'],
                    $f['estado'] ?? '', $f['operador'] ?? '', $f['referencia'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        }

        if ($accion === 'export_json') {
            [$desde, $hasta] = fn_rango_fechas();

            $ingresos  = fn_ingresos($pdo, $desde, $hasta);
            $retiros   = fn_retiros($pdo, $desde, $hasta);
            $bonos     = fn_bonos($pdo, $desde, $hasta);
            $nuevos    = fn_nuevos($pdo, $desde, $hasta);
            $activos   = fn_activos($pdo, $desde, $hasta);
            $retencion = fn_retencion($pdo, $desde, $hasta);
            $costoFichas   = ($ingresos['monto'] + $bonos['monto']) * $costoPorFicha;
            $gananciaBruta = $ingresos['monto'] - $retiros['monto'] - $costoFichas;
            $foto = fn_foto($pdo);

            salir(['ok' => true,
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'resumen' => [
                    'ingresos'             => ['monto' => $ingresos['monto'], 'cantidad' => $ingresos['cantidad']],
                    'retiros'              => ['monto' => $retiros['monto'], 'cantidad' => $retiros['cantidad']],
                    'bonos'                => ['monto' => $bonos['monto'], 'cantidad' => $bonos['cantidad']],
                    'costo_fichas'         => $costoFichas,
                    'costo_por_ficha'      => $costoPorFicha,
                    'ganancia_bruta'       => $gananciaBruta,
                    'recarga_promedio'     => $ingresos['promedio'],
                    'retiro_promedio'      => $retiros['promedio'],
                    'jugadores_activos'    => $activos,
                    'jugadores_nuevos'     => $nuevos,
                    'retencion_porcentaje' => $retencion['porcentaje'],
                ],
                'foto_actual' => [
                    'efectivo_neto'    => $foto['efectivo_neto'],
                    'fichas_jugadores' => $foto['fichas_jugadores'],
                    'patrimonio_neto'  => $foto['patrimonio_neto'],
                    'stock_fichas'     => null,
                    'notas'            => 'Stock de fichas pendiente de M6.B',
                ],
                'alertas'       => fn_alertas($pdo, $desde, $hasta, $umbralGrande, $umbralMuyGrande, $umbralGanador),
                'umbrales'      => [
                    'retiro_grande'     => $umbralGrande,
                    'retiro_muy_grande' => $umbralMuyGrande,
                    'jugador_ganador'   => $umbralGanador,
                ],
                'top_jugadores' => fn_top_jugadores($pdo, $desde, $hasta),
                'generado_en'   => date('Y-m-d H:i:s'),
            ]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_finanzas GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
