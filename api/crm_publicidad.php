<?php
/**
 * crm_publicidad.php — Backend del módulo "Publicidad" del CRM.
 *
 * Embudo de Meta Ads por publicista: cada publicista es una landing propia
 * (registro.html?pub=<slug>) con su propio pixel/token (ver publicidad_lib.php
 * y meta_lib.php). Este archivo solo LEE ese embudo y administra el CRUD de
 * publicistas + el gasto que el operador carga a mano — nunca manda nada a
 * Meta directamente, eso lo hacen crear_cuenta.php/altas_cola.php/recargas_lib.php
 * en el momento de cada hecho real.
 *
 * Mismo gate que Finanzas (Publicidad expone gasto de campaña, CPA y ROAS,
 * información sensible de plata): reverificación de contraseña propia en
 * sesión, separada del login general. Login general en /verificar_password.
 *
 * POST { accion:"verificar_password", password }              -> { ok, error? }
 * POST { accion:"publicista_guardar", id?, nombre, pixel_id, capi_token,
 *        insights_token?, insights_ad_account?, activo }      -> { ok, id }
 *        (el link/slug lo genera el server, nunca se pide ni se edita)
 * POST { accion:"gasto_guardar", publicista_id, fecha, monto } -> { ok }
 *
 * GET ?accion=publicistas                                      -> lista para tabs + admin
 * GET ?accion=embudo&publicista_id=&desde=&hasta=               -> KPIs + rentabilidad
 * GET ?accion=dia_por_dia&publicista_id=&desde=&hasta=           -> tabla día por día
 * GET ?accion=export_json&publicista_id=&desde=&hasta=           -> todo el reporte en un JSON,
 *                                                                    pensado para pegar en un LLM
 *
 * Requiere sql/44_publicidad.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/publicidad_lib.php';
require __DIR__ . '/meta_lib.php';

$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Valida desde/hasta de $_GET (YYYY-MM-DD). Corta la ejecución si es inválido. */
function pub_rango_fechas(): array
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
 * Rentabilidad a partir del embudo + gasto: CPR, CPA, "deja cada jugador",
 * ROAS, ganancia. Con gasto=0 (el operador todavía no cargó nada) las
 * divisiones dan null -- el front las muestra como "—" en vez de $0 o
 * Infinity, que confundirían ("¿de verdad el CPA es cero?").
 */
function pub_rentabilidad(array $m): array
{
    $gasto = (float)$m['gasto'];
    $sinGasto = $gasto <= 0;
    return [
        'costo_por_registro' => ($sinGasto || $m['registros'] === 0) ? null : round($gasto / $m['registros'], 2),
        'costo_por_carga'    => ($sinGasto || $m['primeras_cargas'] === 0) ? null : round($gasto / $m['primeras_cargas'], 2),
        'deja_por_jugador'   => $m['registros'] === 0 ? null : round($m['depositado'] / $m['registros'], 2),
        'roas'               => $sinGasto ? null : round($m['depositado'] / $gasto, 4),
        'ganancia'           => $sinGasto ? null : round($m['depositado'] - $gasto, 2),
    ];
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================== POST ========================================
if ($metodo === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    // Único endpoint que NO exige publicidad_ok -- sería una paradoja
    // exigirlo para poder ponerlo en true. Mismo criterio que crm_finanzas.
    if ($accion === 'verificar_password') {
        try {
            if (!crm_rate_limite("publicidad_verify_$operador", 5, 900)) {
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
            $_SESSION['publicidad_ok'] = true;
            salir(['ok' => true]);
        } catch (Throwable $e) {
            error_log('crm_publicidad verificar_password: ' . $e->getMessage());
            salir(['ok' => false, 'error' => 'Error'], 500);
        }
    }

    if (empty($_SESSION['publicidad_ok'])) {
        salir(['ok' => false, 'error' => 'Reverificación requerida'], 403);
    }

    try {
        if ($accion === 'publicista_guardar') {
            $id     = isset($body['id']) ? (int)$body['id'] : null;
            $nombre = (string)($body['nombre'] ?? '');
            $pixel  = (string)($body['pixel_id']   ?? '');
            $token  = (string)($body['capi_token'] ?? '');
            $activo = !empty($body['activo']);
            $insightsToken     = (string)($body['insights_token'] ?? '');
            $insightsAdAccount = (string)($body['insights_ad_account'] ?? '');

            if (trim($nombre) === '') {
                salir(['ok' => false, 'error' => 'Falta el nombre'], 400);
            }
            $nuevoId = publicidad_guardar($pdo, $id, $nombre, $pixel, $token, $activo,
                                           $insightsToken, $insightsAdAccount);
            if (!$nuevoId) {
                salir(['ok' => false, 'error' => 'No se pudo guardar'], 500);
            }
            crm_bitacora($pdo, $operador, $id ? 'publicista_editar' : 'publicista_crear', "id=$nuevoId nombre=$nombre");
            salir(['ok' => true, 'id' => $nuevoId]);
        }

        if ($accion === 'gasto_guardar') {
            $publicistaId = (int)($body['publicista_id'] ?? 0);
            $fecha        = (string)($body['fecha'] ?? '');
            $monto        = (float)($body['monto'] ?? -1);

            if ($publicistaId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                salir(['ok' => false, 'error' => 'Faltan datos'], 400);
            }
            if ($monto < 0) {
                salir(['ok' => false, 'error' => 'El gasto no puede ser negativo'], 400);
            }
            if (!publicidad_gasto_guardar($pdo, $publicistaId, $fecha, $monto, $operador)) {
                salir(['ok' => false, 'error' => 'No se pudo guardar el gasto'], 500);
            }
            salir(['ok' => true]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_publicidad POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al guardar'], 500);
    }
}

// ============================== GET =========================================
if ($metodo === 'GET') {
    $accion = (string)($_GET['accion'] ?? '');

    if (empty($_SESSION['publicidad_ok'])) {
        salir(['ok' => false, 'error' => 'Reverificación requerida'], 403);
    }

    try {
        if ($accion === 'publicistas') {
            salir(['ok' => true, 'publicistas' => publicidad_listar($pdo)]);
        }

        if ($accion === 'embudo') {
            $publicistaId = (int)($_GET['publicista_id'] ?? 0);
            if ($publicistaId <= 0) {
                salir(['ok' => false, 'error' => 'Falta publicista_id'], 400);
            }
            [$desde, $hasta] = pub_rango_fechas();

            $m = publicidad_metricas($pdo, $publicistaId, $desde, $hasta);
            $conversion = $m['registros'] > 0 ? round($m['primeras_cargas'] / $m['registros'] * 100, 1) : null;
            $cargasPorJugador = $m['primeras_cargas'] > 0 ? round($m['cargas_totales'] / $m['primeras_cargas'], 2) : null;
            $ticketPromedio   = $m['cargas_totales'] > 0 ? round($m['depositado'] / $m['cargas_totales'], 2) : null;
            $volvioPct = $m['jugadores_con_carga'] > 0
                ? round($m['jugadores_volvieron'] / $m['jugadores_con_carga'] * 100, 1) : null;

            // Visitas de página: solo si el publicista tiene su propio pixel
            // Y credenciales de Insights cargadas -- sin eso, null (el front
            // muestra "—", no rompe nada). Ver meta_insights_pageviews().
            $visitas = null;
            $convVisitasRegistros = null;
            $publicistaConInsights = publicidad_con_insights($pdo, $publicistaId);
            if ($publicistaConInsights) {
                $visitas = meta_insights_pageviews($publicistaConInsights, $desde, $hasta);
                if ($visitas !== null && $visitas > 0) {
                    $convVisitasRegistros = round($m['registros'] / $visitas * 100, 1);
                }
            }

            salir(['ok' => true,
                'periodo'  => ['desde' => $desde, 'hasta' => $hasta],
                'embudo'   => array_merge($m, [
                    'visitas'                    => $visitas,
                    'conversion_visitas_pct'     => $convVisitasRegistros,
                    'conversion_pct'      => $conversion,
                    'cargas_por_jugador'  => $cargasPorJugador,
                    'ticket_promedio'     => $ticketPromedio,
                    'volvio_a_cargar_pct' => $volvioPct,
                ]),
                'rentabilidad' => pub_rentabilidad($m),
            ]);
        }

        if ($accion === 'dia_por_dia') {
            $publicistaId = (int)($_GET['publicista_id'] ?? 0);
            if ($publicistaId <= 0) {
                salir(['ok' => false, 'error' => 'Falta publicista_id'], 400);
            }
            [$desde, $hasta] = pub_rango_fechas();
            salir(['ok' => true, 'dias' => publicidad_por_dia($pdo, $publicistaId, $desde, $hasta)]);
        }

        // Todo el reporte de un publicista en un solo JSON, pensado para
        // pegar en un LLM o procesar con otra herramienta: mismo criterio
        // que crm_finanzas.php?accion=export_json -- estructura plana con
        // nombres de campo autoexplicativos, sin ids internos salvo los que
        // hacen falta para referenciar (publicista_id).
        if ($accion === 'export_json') {
            $publicistaId = (int)($_GET['publicista_id'] ?? 0);
            if ($publicistaId <= 0) {
                salir(['ok' => false, 'error' => 'Falta publicista_id'], 400);
            }
            [$desde, $hasta] = pub_rango_fechas();
            $publicista = publicidad_por_id($pdo, $publicistaId);
            if (!$publicista) {
                salir(['ok' => false, 'error' => 'Publicista inexistente'], 404);
            }

            $m = publicidad_metricas($pdo, $publicistaId, $desde, $hasta);
            $conversion = $m['registros'] > 0 ? round($m['primeras_cargas'] / $m['registros'] * 100, 1) : null;
            $cargasPorJugador = $m['primeras_cargas'] > 0 ? round($m['cargas_totales'] / $m['primeras_cargas'], 2) : null;
            $ticketPromedio   = $m['cargas_totales'] > 0 ? round($m['depositado'] / $m['cargas_totales'], 2) : null;
            $volvioPct = $m['jugadores_con_carga'] > 0
                ? round($m['jugadores_volvieron'] / $m['jugadores_con_carga'] * 100, 1) : null;

            $visitas = null;
            $convVisitasRegistros = null;
            $publicistaConInsights = publicidad_con_insights($pdo, $publicistaId);
            if ($publicistaConInsights) {
                $visitas = meta_insights_pageviews($publicistaConInsights, $desde, $hasta);
                if ($visitas !== null && $visitas > 0) {
                    $convVisitasRegistros = round($m['registros'] / $visitas * 100, 1);
                }
            }

            salir(['ok' => true,
                'publicista' => ['id' => $publicista['id'], 'nombre' => $publicista['nombre'], 'slug' => $publicista['slug']],
                'periodo'    => ['desde' => $desde, 'hasta' => $hasta],
                'embudo'     => [
                    'visitas_pagina'          => $visitas,
                    'conversion_visitas_a_registros_pct' => $convVisitasRegistros,
                    'registros'               => $m['registros'],
                    'primeras_cargas'         => $m['primeras_cargas'],
                    'conversion_registros_a_primera_carga_pct' => $conversion,
                    'cargas_totales'          => $m['cargas_totales'],
                    'cargas_por_jugador'      => $cargasPorJugador,
                    'jugadores_que_cargaron'  => $m['jugadores_con_carga'],
                    'jugadores_que_volvieron_a_cargar' => $m['jugadores_volvieron'],
                    'volvio_a_cargar_pct'     => $volvioPct,
                    'depositado_real'         => $m['depositado'],
                    'ticket_promedio'         => $ticketPromedio,
                ],
                'rentabilidad' => pub_rentabilidad($m),
                'gasto_del_periodo' => $m['gasto'],
                'dia_por_dia'  => publicidad_por_dia($pdo, $publicistaId, $desde, $hasta),
                'generado_en'  => date('Y-m-d H:i:s'),
            ]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_publicidad GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
