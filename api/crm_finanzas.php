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
 *     -- monto_pedido (no monto_base) porque acá se mide la CAJA: es la plata
 *     -- exacta que entró al banco, centavos identificadores incluidos.
 *     -- publicidad_lib.php suma monto_base sobre las MISMAS recargas, y
 *     -- también está bien: ahí se mide el valor de una campaña, no la caja.
 *     -- Por eso los dos módulos difieren en centavos. No es un descuadre y
 *     -- no hay que unificarlos: responden preguntas distintas.
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
/* Para el bloque "salud del negocio": la pauta y el corte entre jugadores
   nuevos y base acumulada salen de ahi, no se recalculan aca. */
require_once __DIR__ . '/publicidad_lib.php';

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

/**
 * Ingresos: TODA la plata que cargaron los jugadores en [desde, hasta].
 *
 * LAS DOS VIAS, y esto es nuevo (13/09/2026). Hasta hoy esta consulta -- y otras
 * ocho de este archivo -- miraban solo la tabla `recargas`, o sea el camino del
 * chatbot. La carga que el jugador pide con el boton "Depositos" de adentro del
 * juego no crea ninguna fila ahi: la acredita la plataforma sobre el saldo real
 * y de este lado queda solo la linea en `movimientos` (origen='peticion').
 *
 * O sea que Finanzas venia mostrando un negocio mas chico del que es. Medido en
 * esta base el 13/09/2026: $88.901 por transferencia contra $10.100 desde el
 * juego, un 10% que no figuraba en los ingresos, ni en la ganancia, ni en los
 * jugadores activos, ni en la retencion, ni en ningun grafico.
 *
 * El mismo agujero ya se habia arreglado en Publicidad, que por esto mostraba
 * cero conversiones con la gente cargando de verdad. Ahora las dos pantallas
 * usan la MISMA definicion (publicidad_sql_cargas), para que no puedan volver a
 * separarse.
 */
function fn_ingresos(PDO $pdo, string $desde, string $hasta): array
{
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(monto),0) monto, COALESCE(AVG(monto),0) promedio
           FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto'], 'promedio' => (float)$r['promedio']];
}

/**
 * ¿Hasta dónde atrás llega el libro del panel (`operaciones_panel`)?
 *
 * Existe porque el libro se llena hacia atrás con un backfill y después se
 * mantiene con una ventana móvil. Si alguien mira un período ANTERIOR a lo que
 * el libro alcanza, el libro va a decir "cero retiros" — y cero no es un dato,
 * es una ausencia. Mostrarlo como si fuera un dato convertiría un mes viejo en
 * un mes de ganancia récord.
 *
 * Devuelve null si la tabla no existe, está vacía, o no llega tan atrás.
 *
 * SIN CACHÉ, aunque se llame varias veces por request. La migración 67 le puso
 * un índice propio a `cuando` justamente para esto: MIN() sobre una columna
 * indexada es leer una fila del índice, más barato que cualquier caché. Y un
 * caché estático acá sería peor que inútil — no se puede invalidar, así que
 * cualquier test que llene el libro a mitad de camino vería el valor viejo.
 */
function fn_libro_desde(PDO $pdo): ?string
{
    try {
        $v = $pdo->query("SELECT MIN(cuando) FROM operaciones_panel")->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $e) {
        return null;            // sin migración 67: se sigue como antes
    }
}

/**
 * Lo que costaron las fichas ENTREGADAS en [desde, hasta].
 *
 * SALE DEL LIBRO DEL PANEL, y eso cambió el 14/09/2026. Antes se estimaba como
 * (ingresos + bonos) × costo_por_ficha, o sea: "el jugador pagó $100, entonces
 * entregamos 100 fichas". Esa cuenta deja afuera todo lo que el operador carga
 * A MANO desde el panel, que no pasa por ninguna tabla nuestra.
 *
 * Medido el 13/09/2026: la plataforma entregó $53.750 en fichas y nuestra cola
 * solo había mandado $34.250. Los $19.500 de diferencia fueron tres cargas
 * hechas a mano, una de ellas a `holalourdes220` -- el mismo jugador del
 * incidente documentado en PARA-FAUNO-deposito.md, al que el bot le descontó
 * las fichas sin depositarlas y hubo que cargárselas de nuevo. O sea que el
 * costo de ese bug lo estaba pagando el negocio sin que apareciera en ningún
 * número.
 *
 * Con la estimación vieja ese día daba $7.270 de costo; lo que realmente salió
 * del stock fueron $10.750. Casi $3.500 por día sin contar.
 *
 * Un depósito del libro es una ficha que salió del stock del agente, sin
 * importar por qué: la carga de una recarga, un bono que el jugador jugó, o
 * una compensación a mano. Todas cuestan lo mismo al proveedor.
 */
function fn_costo_fichas(PDO $pdo, string $desde, string $hasta,
                         float $costoPorFicha, float $ingresos, float $bonos): float
{
    $libroDesde = fn_libro_desde($pdo);
    if ($libroDesde !== null && $libroDesde <= $desde . ' 00:00:00') {
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(monto),0) FROM operaciones_panel
                  WHERE tipo = 0 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY"
            );
            $st->execute([$desde, $hasta]);
            return (float)$st->fetchColumn() * $costoPorFicha;
        } catch (Throwable $e) {
            error_log('fn_costo_fichas (libro): ' . $e->getMessage());
        }
    }
    // Sin libro que alcance: la estimación de siempre, que subcuenta.
    return ($ingresos + $bonos) * $costoPorFicha;
}

/**
 * Fichas entregadas POR FUERA de nuestro sistema, en pesos de fichas.
 *
 * Es lo que la plataforma entregó menos lo que nuestra cola dice haber
 * mandado. LOS DOS SIGNOS IMPORTAN y hasta hoy los dos eran invisibles:
 *
 *   POSITIVO  el operador cargó fichas a mano desde el panel. Salieron del
 *             stock y no las pagó nadie de este lado. Puede ser legítimo (una
 *             compensación, un regalo) o puede no serlo, pero tiene que verse.
 *
 *   NEGATIVO  PEOR: nuestra cola dice "hecha" y la plataforma no tiene registro
 *             de esa entrega. Es exactamente el bug del documento para Fauno --
 *             el depósito que el WAF cortó y quedó marcado como exitoso -- y
 *             significa que hay un jugador al que le descontamos las fichas sin
 *             dárselas. Cada peso negativo acá es un reclamo esperando.
 *
 * Se compara por TOTALES y no fila por fila porque no hay id compartido: un
 * depósito directo del agente no nace de ninguna solicitud. Para un indicador
 * de control alcanza; para perseguir un caso puntual están las dos tablas.
 */
function fn_fichas_fuera(PDO $pdo, string $desde, string $hasta): ?array
{
    $libroDesde = fn_libro_desde($pdo);
    if ($libroDesde === null || $libroDesde > $desde . ' 00:00:00') { return null; }
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(monto),0) FROM operaciones_panel
              WHERE tipo = 0 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY"
        );
        $st->execute([$desde, $hasta]);
        $libro = (float)$st->fetchColumn();

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(monto),0) FROM acciones_saldo
              WHERE tipo = 'cargar' AND estado = 'hecha'
                AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY"
        );
        $st->execute([$desde, $hasta]);
        $cola = (float)$st->fetchColumn();

        return ['libro' => round($libro, 2), 'cola' => round($cola, 2),
                'diferencia' => round($libro - $cola, 2)];
    } catch (Throwable $e) {
        error_log('fn_fichas_fuera: ' . $e->getMessage());
        return null;
    }
}

/**
 * Retiros del período: la plata que SALIÓ.
 *
 * SALE DEL LIBRO DEL PANEL, no de nuestra cola, y eso cambió el 14/09/2026.
 * `acciones_saldo` es NUESTRA cola: solo tiene los retiros que el jugador pide
 * por el chat y ejecuta el worker. El que pide con el botón de adentro del
 * juego, y el que el operador hace directo desde el panel, no pasan por ahí.
 *
 * Medido ese día contra el panel, sobre 60 días:
 *     el libro del panel  : 44 retiros por $157.630
 *     lo que veía Finanzas:  5 retiros por      $692
 *
 * O sea que la ganancia venía sobrestimada en casi todo lo que sale. No es un
 * error de redondeo: es el 99% del dinero saliente.
 *
 * `operaciones_panel` solo tiene operaciones EJECUTADAS (ver la migración 67:
 * se verificó buscando un depósito que rechazamos a mano y no figura, con sus
 * ids vecinos sí presentes). Así que sumar todo lo de tipo=1 es exactamente la
 * plata que salió, sin tener que decidir nada.
 *
 * EL LIBRO REEMPLAZA A LA COLA, NO SE SUMA A ELLA. Los retiros que ejecuta
 * nuestro worker también quedan registrados en el libro (verificado: las
 * acciones 98, 39 y 29 aparecen con el mismo minuto y monto), así que contar
 * las dos fuentes los contaría dos veces.
 *
 * `fuente` viaja en la respuesta para que la pantalla pueda decir de dónde
 * salió el número. Un período que el libro no alcanza cae a la cola vieja, que
 * subcuenta — pero subcontar avisando es mejor que inventar un cero.
 */
function fn_retiros(PDO $pdo, string $desde, string $hasta): array
{
    $libroDesde = fn_libro_desde($pdo);
    if ($libroDesde !== null && $libroDesde <= $desde . ' 00:00:00') {
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) cantidad, COALESCE(SUM(monto),0) monto,
                        COALESCE(AVG(monto),0) promedio
                   FROM operaciones_panel
                  WHERE tipo = 1 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY"
            );
            $st->execute([$desde, $hasta]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto'],
                    'promedio' => (float)$r['promedio'], 'fuente' => 'panel'];
        } catch (Throwable $e) {
            error_log('fn_retiros (libro): ' . $e->getMessage());
        }
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(monto),0) monto, COALESCE(AVG(monto),0) promedio
           FROM acciones_saldo
          WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY"
    );
    $st->execute([$desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['cantidad' => (int)$r['cantidad'], 'monto' => (float)$r['monto'],
            'promedio' => (float)$r['promedio'], 'fuente' => 'cola'];
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

/**
 * Jugadores activos: cargó al menos una vez en el período (opción A confirmada),
 * por CUALQUIERA de las dos vías -- ver fn_ingresos() sobre por qué.
 */
function fn_activos(PDO $pdo, string $desde, string $hasta): int
{
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT c.usuario) FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY"
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

    /* Las dos vias tambien aca, y es donde mas se notaba: el recorrido natural
       es cargar la primera vez por el chat y las siguientes con el boton de
       adentro del juego, que esta mas a mano. Mirando solo `recargas`, ese
       jugador -- el que mejor se retuvo -- figuraba como perdido. */
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT act.usuario)
           FROM (SELECT DISTINCT c.usuario FROM ($cargas) c
                  WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY) act
           JOIN (SELECT DISTINCT c.usuario FROM ($cargas) c
                  WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY) prev
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
    $cargas = publicidad_sql_cargas();
    $ing = $pdo->prepare(
        "SELECT DATE(c.cuando) fecha, SUM(c.monto) monto FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
          GROUP BY DATE(c.cuando)"
    );
    $ing->execute([$desde, $hasta]);
    $porIngresos = [];
    foreach ($ing->fetchAll(PDO::FETCH_ASSOC) as $r) { $porIngresos[$r['fecha']] = (float)$r['monto']; }

    /* Del libro del panel si alcanza, de la cola vieja si no -- misma regla
       que fn_retiros(), o el gráfico contaría una cosa y el KPI de arriba
       otra, que es peor que los dos equivocados igual. */
    $libroDesde = fn_libro_desde($pdo);
    $usaLibro   = $libroDesde !== null && $libroDesde <= $desde . ' 00:00:00';
    $ret = $usaLibro
        ? $pdo->prepare(
            "SELECT DATE(cuando) fecha, SUM(monto) monto FROM operaciones_panel
              WHERE tipo = 1 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY
              GROUP BY DATE(cuando)")
        : $pdo->prepare(
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
        "SELECT DATE(c.cuando) fecha, COUNT(DISTINCT c.usuario) activos FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
          GROUP BY DATE(c.cuando)"
    );
    $act->execute([$desde, $hasta]);
    $porActivos = [];
    foreach ($act->fetchAll(PDO::FETCH_ASSOC) as $r) { $porActivos[$r['fecha']] = (int)$r['activos']; }

    /* Las fichas entregadas, por dia, EN UNA SOLA CONSULTA. Llamar a
       fn_costo_fichas() dentro del bucle serian 90 consultas para un rango de
       90 dias, cada una para leer un numero. */
    $porCosto = null;
    $libroDesde = fn_libro_desde($pdo);
    if ($libroDesde !== null && $libroDesde <= $desde . ' 00:00:00') {
        try {
            $st = $pdo->prepare(
                "SELECT DATE(cuando) fecha, SUM(monto) monto FROM operaciones_panel
                  WHERE tipo = 0 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY
                  GROUP BY DATE(cuando)"
            );
            $st->execute([$desde, $hasta]);
            $porCosto = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $porCosto[$r['fecha']] = (float)$r['monto'];
            }
        } catch (Throwable $e) {
            error_log('fn_serie_por_dia (costo): ' . $e->getMessage());
            $porCosto = null;
        }
    }

    $serie   = [];
    $cursor  = new DateTime($desde);
    $fin     = new DateTime($hasta);
    while ($cursor <= $fin) {
        $f           = $cursor->format('Y-m-d');
        $ingresosDia = $porIngresos[$f] ?? 0.0;
        $bonosDia    = $porBonos[$f] ?? 0.0;
        $retirosDia  = $porRetiros[$f] ?? 0.0;
        /* Mismo criterio que el KPI de arriba, o el grafico contaria una cosa
           y la tarjeta otra: lo que REALMENTE salio del stock si el libro
           alcanza, la estimacion vieja si no. */
        $costoDia    = $porCosto !== null
            ? ($porCosto[$f] ?? 0.0) * $costoPorFicha
            : ($ingresosDia + $bonosDia) * $costoPorFicha;
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

    $cargas = publicidad_sql_cargas();
    $ing = $pdo->prepare(
        "SELECT HOUR(c.cuando) hora, COUNT(*) cantidad FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
          GROUP BY HOUR(c.cuando)"
    );
    $ing->execute([$desde, $hasta]);
    foreach ($ing->fetchAll(PDO::FETCH_ASSOC) as $r) { $horas[(int)$r['hora']] += (int)$r['cantidad']; }

    $libroDesde = fn_libro_desde($pdo);
    $ret = ($libroDesde !== null && $libroDesde <= $desde . ' 00:00:00')
        ? $pdo->prepare(
            "SELECT HOUR(cuando) hora, COUNT(*) cantidad FROM operaciones_panel
              WHERE tipo = 1 AND cuando >= ? AND cuando < ? + INTERVAL 1 DAY
              GROUP BY HOUR(cuando)")
        : $pdo->prepare(
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
/* ---- HG Cash: los numeros de la pasarela para ESTE cliente ----
   Salen del libro global (goldpaw_control.hg_transacciones), filtrado
   por la base del tenant. Es lo que el cliente necesita ver de su
   relacion con la plataforma: cuanto movio, cuanta comision pago y
   cuanto se le liquida. null si HG no esta configurado o el libro no
   existe: la vista Finanzas entera no puede caerse por la pasarela. */
function fn_hg(string $desde, string $hasta): ?array
{
    if (!is_file(__DIR__ . '/hgcash_lib.php')) { return null; }
    require_once __DIR__ . '/hgcash_lib.php';
    if (!hg_activo()) { return null; }
    $ctl = hg_control();
    $cli = hg_cliente_actual();
    if (!$ctl || !$cli) { return null; }
    try {
        $st = $ctl->prepare(
            "SELECT
                SUM(tipo='deposito' AND estado='completado')                 depositos,
                SUM(IF(tipo='deposito' AND estado='completado', monto, 0))   dep_bruto,
                SUM(tipo='deposito' AND estado='pendiente')                  dep_pendientes,
                SUM(tipo='retiro' AND estado='pagado')                       retiros_pagados,
                SUM(IF(tipo='retiro' AND estado='pagado', monto, 0))         ret_bruto,
                SUM(IF(estado IN ('completado','pagado'), comision, 0))      comision,
                SUM(IF(tipo='deposito' AND estado='completado', neto, 0))    neto
               FROM hg_transacciones
              WHERE cliente_id = ? AND creado_en BETWEEN ? AND ?"
        );
        $st->execute([(int)$cli['id'], $desde . ' 00:00:00', $hasta . ' 23:59:59']);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $creados = (int)($r['depositos'] ?? 0) + (int)($r['dep_pendientes'] ?? 0);
        return [
            'activo'          => true,
            'depositos'       => (int)($r['depositos'] ?? 0),
            'dep_bruto'       => (float)($r['dep_bruto'] ?? 0),
            'dep_pendientes'  => (int)($r['dep_pendientes'] ?? 0),
            'retiros_pagados' => (int)($r['retiros_pagados'] ?? 0),
            'ret_bruto'       => (float)($r['ret_bruto'] ?? 0),
            'comision'        => (float)($r['comision'] ?? 0),
            'neto'            => (float)($r['neto'] ?? 0),
            'comision_pct'    => hg_pcts()[0],
            // De cada 100 links creados, cuantos terminaron en plata.
            'conversion'      => $creados > 0
                ? round(100 * (int)($r['depositos'] ?? 0) / $creados, 1)
                : null,
        ];
    } catch (Throwable $e) {
        return null;    // libro sin migrar: Finanzas sigue andando
    }
}

function fn_foto(PDO $pdo): array
{
    $cargas = publicidad_sql_cargas();
    /* El histórico sale del libro del panel cuando existe. OJO con el alcance:
       si el backfill no llegó hasta el primer día del negocio, esto subcuenta
       los retiros viejos y el patrimonio sale optimista. Es el mismo trueque
       que en fn_retiros(), y se prefiere el libro porque la cola vieja
       subcuenta MUCHÍSIMO más (5 retiros contra 44 en la misma ventana). */
    $retirosSql = fn_libro_desde($pdo) !== null
        ? "SELECT monto FROM operaciones_panel WHERE tipo = 1"
        : "SELECT monto FROM acciones_saldo WHERE tipo='retirar' AND estado='hecha'";
    $row = $pdo->query(
        "SELECT
            (SELECT COALESCE(SUM(c.monto),0) FROM ($cargas) c) AS ingresos_historicos,
            (SELECT COALESCE(SUM(monto),0) FROM ($retirosSql) rr) AS retiros_historicos,
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
    /* Las cargas van por las dos vias: sin eso, un jugador que carga con el
       boton del juego y retira por el chat aparecia como ganador puro -- una
       alerta falsa sobre alguien que en realidad estaba pagando. */
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT u.usuario, COALESCE(rec.total, 0) AS recargas, COALESCE(ret.total, 0) AS retiros
           FROM (
                 SELECT c.usuario FROM ($cargas) c
                  WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
                 UNION
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
                ) u
           LEFT JOIN (
                 SELECT c.usuario, SUM(c.monto) AS total FROM ($cargas) c
                  WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY GROUP BY c.usuario
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
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT u.usuario,
                COALESCE(rec.monto, 0) AS recargas_monto, COALESCE(rec.cantidad, 0) AS recargas_cantidad,
                COALESCE(ret.monto, 0) AS retiros_monto, COALESCE(ret.cantidad, 0) AS retiros_cantidad
           FROM (
                 SELECT c.usuario FROM ($cargas) c
                  WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
                 UNION
                 SELECT usuario COLLATE utf8mb4_unicode_ci AS usuario FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='hecha' AND ejecutada_en >= ? AND ejecutada_en < ? + INTERVAL 1 DAY
                ) u
           LEFT JOIN (
                 SELECT c.usuario, SUM(c.monto) AS monto, COUNT(*) AS cantidad
                   FROM ($cargas) c WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
                  GROUP BY c.usuario
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
    /* En el CSV la via viaja en `estado`, que para una carga acreditada no
       aportaba nada (siempre decia 'acreditada'). Asi cada fila dice de donde
       salio la plata sin agregar una columna que rompa las planillas viejas. */
    $cargas = publicidad_sql_cargas();
    $st = $pdo->prepare(
        "SELECT c.cuando AS fecha, 'recarga' AS tipo, c.usuario AS usuario,
                c.monto AS monto, c.via AS estado, NULL AS operador, c.referencia AS referencia
           FROM ($cargas) c
          WHERE c.cuando >= ? AND c.cuando < ? + INTERVAL 1 DAY
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
            $costoFichas = fn_costo_fichas($pdo, $hoy, $hoy, $costoPorFicha,
                                           $ingresos['monto'], $bonos['monto']);

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

        /* ---- SALUD DEL NEGOCIO: la pauta contra lo que deja el casino ----
           Contesta la unica pregunta que decide si el modelo escala, en las
           palabras con que la planteo Nahuel: "mi modelo esta en ir
           adquiriendo jugadores hasta llegar a que mis gastos publicitarios
           diarios sean menores a las ganancias obtenidas".

           VA APARTE Y NO SUMADO a los numeros de Finanzas, a proposito. Lo de
           arriba es la CAJA del casino -- entro, salio, cuanto cuestan las
           fichas. La pauta es una decision de inversion. Mezclarlas haria que
           un mes de pauta fuerte pareciera un mes malo de casino, que son dos
           cosas distintas y se arreglan de formas distintas.

           Lo cargado SI da igual que arriba, y eso es nuevo: hasta el 13/09/2026
           fn_ingresos() miraba solo la tabla `recargas` -- el camino del
           chatbot -- y este bloque ya contaba las dos vias, asi que los dos
           numeros no cerraban. Ahora Finanzas usa la misma definicion
           (publicidad_sql_cargas), de modo que la unica diferencia entre esta
           caja y la de arriba es la PAUTA, que es justamente lo que esta caja
           agrega. `dep_transferencia` y `dep_en_el_juego` quedan igual: sirven
           para saber por donde entra la plata, no para explicar un descalce. */
        if ($accion === 'salud_pauta') {
            [$desde, $hasta] = fn_rango_fechas();

            $pauta   = publicidad_gasto_total($pdo, $desde, $hasta);
            /* El desglose viaja SIEMPRE, no solo cuando hay varias campañas:
               un total sin poder abrirlo no se puede auditar. */
            $detalle = publicidad_gasto_detalle($pdo, $desde, $hasta);
            $split   = publicidad_cargas_split($pdo, $desde, $hasta);
            $retiros = fn_retiros($pdo, $desde, $hasta);
            $bonos   = fn_bonos($pdo, $desde, $hasta);

            $depositado = (float)$split['depositado'];

            /* La parte que entro por el boton del juego, medida directo y no
               restando: `recargas` guarda monto_pedido y el UNION suma
               monto_base, y esa diferencia historica de centavos haria que la
               resta no cerrara nunca del todo. */
            $enJuego = 0.0;
            try {
                $st = $pdo->prepare(
                    "SELECT COALESCE(SUM(monto),0) FROM movimientos
                      WHERE origen='peticion' AND tipo='saldo' AND monto > 0
                        AND creado_en >= ? AND creado_en < ? + INTERVAL 1 DAY"
                );
                $st->execute([$desde, $hasta]);
                $enJuego = (float)$st->fetchColumn();
            } catch (Throwable $e) { error_log('salud_pauta juego: ' . $e->getMessage()); }

            $costoFichas  = fn_costo_fichas($pdo, $desde, $hasta, $costoPorFicha,
                                            $depositado, $bonos['monto']);
            $antesDePauta = $depositado - $retiros['monto'] - $costoFichas;
            $gasto        = (float)$pauta['total'];
            $sinGasto     = $gasto <= 0;

            /* Dias del periodo, del calendario. Los promedios diarios van los
               DOS sobre el mismo divisor o no se pueden comparar, y compararlos
               es todo el punto del indicador. `dias_con_pauta` viaja aparte
               para que se note si el gasto quedo a medio cargar: si dice 4 de
               30, el promedio diario esta diluido y el numero miente bajo. */
            $dias = (int)(new DateTime($hasta))->diff(new DateTime($desde))->days + 1;

            /* MARGEN: de cada peso que carga un jugador, cuanto queda despues
               de los retiros y del costo de las fichas. Es lo que convierte
               "deposito" en "ganancia", y sin eso no se puede saber que deja
               de verdad la base acumulada. Con deposito 0 no existe. */
            $margen = $depositado > 0 ? $antesDePauta / $depositado : null;

            /* LA BASE: lo que dejan los que YA estaban, sin gastar un peso hoy.
               Cuando esto por dia supera a la pauta por dia, la publicidad se
               paga sola con jugadores ya comprados -- que es exactamente la
               condicion de escalabilidad que describio Nahuel. */
            $gananciaBase = $margen === null ? 0.0 : (float)$split['dep_repeticion'] * $margen;
            $basePorDia   = $dias > 0 ? $gananciaBase / $dias : 0.0;
            $pautaPorDia  = $dias > 0 ? $gasto / $dias : 0.0;

            $nuevos = (int)$split['jugadores_nuevos'];

            salir(['ok' => true, 'salud' => [
                'desde' => $desde, 'hasta' => $hasta, 'dias' => $dias,

                // --- La pauta
                'pauta'          => round($gasto, 2),
                'dias_con_pauta' => (int)$pauta['dias'],
                'pauta_por_dia'  => round($pautaPorDia, 2),
                'pauta_detalle'  => $detalle,

                // --- Lo que entro, abierto por via y por tipo de jugador
                'depositado'        => round($depositado, 2),
                'dep_transferencia' => round($depositado - $enJuego, 2),
                'dep_en_el_juego'   => round($enJuego, 2),
                'dep_primeras'      => (float)$split['dep_primeras'],
                'dep_repeticion'    => (float)$split['dep_repeticion'],
                'cargas'            => (int)$split['cargas'],

                // --- Los jugadores. `jugadores` NO es nuevos + repiten: quien
                //     cargo por primera vez y volvio a cargar cuenta en los dos.
                'jugadores'         => (int)$split['jugadores'],
                'jugadores_nuevos'  => $nuevos,
                'jugadores_repiten' => (int)$split['jugadores_repiten'],

                // --- Lo que se va
                'retiros'      => round($retiros['monto'], 2),
                'bonos'        => round($bonos['monto'], 2),
                'costo_fichas' => round($costoFichas, 2),

                // --- El resultado
                'margen'         => $margen === null ? null : round($margen, 4),
                'antes_de_pauta' => round($antesDePauta, 2),
                'ganancia_real'  => round($antesDePauta - $gasto, 2),
                'cobertura'      => $sinGasto ? null : round($antesDePauta / $gasto, 4),

                // --- La escalabilidad
                'ganancia_base'  => round($gananciaBase, 2),
                'base_por_dia'   => round($basePorDia, 2),
                'autofinanciado' => $sinGasto ? null : ($basePorDia >= $pautaPorDia),

                // --- La adquisicion
                'costo_por_nuevo'  => ($sinGasto || $nuevos === 0) ? null : round($gasto / $nuevos, 2),
                'deja_nuevo'       => $nuevos === 0 ? null : round((float)$split['dep_primeras'] / $nuevos, 2),
                'recupero_primera' => ($sinGasto || $margen === null) ? null
                                      : round(((float)$split['dep_primeras'] * $margen) / $gasto, 4),
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
            $costoFichas   = fn_costo_fichas($pdo, $desde, $hasta, $costoPorFicha,
                                             $ingresos['monto'], $bonos['monto']);
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
                $costoFichasPrev   = fn_costo_fichas($pdo, $prevDesde, $prevHasta, $costoPorFicha,
                                                     $ingresosPrev['monto'], $bonosPrev['monto']);
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
                /* Control: fichas que entrego la plataforma y nuestra cola no
                   registra (o al reves). Null si el libro no cubre el periodo
                   -- no se puede afirmar nada sin con que comparar. */
                'fichas_fuera'      => fn_fichas_fuera($pdo, $desde, $hasta),
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
                'hg' => fn_hg($desde, $hasta),
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
            $costoFichas   = fn_costo_fichas($pdo, $desde, $hasta, $costoPorFicha,
                                             $ingresos['monto'], $bonos['monto']);
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
