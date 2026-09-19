<?php
/**
 * crm_comprobantes.php — Backend del módulo "Comprobantes sin resolver".
 *
 * Pagos que colector_mail.php capturó pero que rl_matchear_y_acreditar() no
 * pudo casar solo con ninguna recarga pendiente (quedaron en estado
 * 'revision'). Este endpoint deja que un operador los asigne a mano.
 *
 * Fase A, Módulo 1 (ver CRM_DESIGN.md).
 *
 * GET  ?accion=badge                              -> { ok, cantidad }
 * GET  ?accion=listar                             -> { ok, items:[...] }
 * GET  ?accion=detalle&pago_id=<id_unico>&q=texto  -> { ok, pago, candidatas:[...] }
 * POST { accion:"asignar", pago_id, recarga_id }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recargas_lib.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * EL NOMBRE QUE HAY ADENTRO DEL NOMBRE DE USUARIO.
 *
 * Nuestros jugadores se llaman `hola` + Nombre + 3 dígitos por construcción
 * (alta_usuario_disponible), así que `holahector301` lleva "hector" adentro --
 * y el remitente del banco dice "HECTOR RAFAEL BAREIRO". Es la pista más obvia
 * de todas y no la usaba nadie.
 *
 * ACÁ DECÍA QUE ESTO ERA RUIDO, y con razón en su momento: el CRM comparaba el
 * titular del banco contra el nombre de usuario CRUDO, que suele ser un apodo
 * ("elkakas") y no se parece a nada, así que avisaba "no coincide" casi
 * siempre. La diferencia es sacar el `hola` y los dígitos primero: lo que queda
 * es el nombre que la persona escribió al registrarse.
 *
 * Devuelve '' cuando no queda nada aprovechable (un usuario viejo, un apodo).
 * Es una PISTA para ordenar y explicar, nunca una acreditación automática: el
 * que decide es el operador, y por eso la pantalla sugiere en vez de aplicar.
 */
function comp_nombre_de_usuario(string $usuario): string
{
    $u = trim($usuario);
    if ($u === '') { return ''; }
    if (stripos($u, 'hola') === 0) { $u = substr($u, 4); }
    $u = rtrim($u, '0123456789');
    return mb_strlen($u) >= 3 ? $u : '';
}

// ============================== GET =========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        /* Las transferencias reservadas para una carga del camino A (pedida
           desde el boton Depositos) NO se ofrecen acá: las resuelve
           aprobar_cargas.py y asignarlas a mano además haría cobrar dos veces.
           Se ven en «Cargas del panel». rl_asignar_manual() lo revalida por las
           dudas, pero mejor no ofrecer lo que después se va a rechazar.

           El LEFT JOIN va sin COLLATE: las dos tablas comparten collation (ver
           sql/48). Si la migración 48 no corrió, se cae al SELECT de siempre. */
        $reservadas =
            " LEFT JOIN peticiones_carga q
                     ON q.pago_id_unico = p.id_unico
                    AND q.estado IN ('esperando','revision') ";
        $noReservada = " AND q.request_id IS NULL ";

        if ($accion === 'badge') {
            try {
                $n = (int)$pdo->query(
                    "SELECT COUNT(*) FROM pagos p $reservadas
                      WHERE p.estado='revision' $noReservada"
                )->fetchColumn();
            } catch (PDOException $e) {
                $n = (int)$pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='revision'")->fetchColumn();
            }
            salir(['ok' => true, 'cantidad' => $n]);
        }

        if ($accion === 'listar') {
            $cols = "p.id, p.id_unico, p.monto, p.remitente, p.cuit, p.cbu_origen,
                     p.nro_transaccion, p.entidad, p.fecha_operacion, p.mail_de, p.capturado_en";
            try {
                $st = $pdo->query(
                    "SELECT $cols FROM pagos p $reservadas
                      WHERE p.estado = 'revision' $noReservada
                      ORDER BY p.capturado_en DESC
                      LIMIT 100"
                );
            } catch (PDOException $e) {
                $st = $pdo->query(
                    "SELECT $cols FROM pagos p
                      WHERE p.estado = 'revision'
                      ORDER BY p.capturado_en DESC
                      LIMIT 100"
                );
            }
            $items = array_map(function ($r) {
                $r['monto'] = (float)$r['monto'];
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));
            salir(['ok' => true, 'items' => $items]);
        }

        if ($accion === 'detalle') {
            $idUnico = trim((string)($_GET['pago_id'] ?? ''));
            if ($idUnico === '') { salir(['ok' => false, 'error' => 'Falta pago_id'], 400); }

            $st = $pdo->prepare(
                "SELECT id, id_unico, monto, remitente, cuit, cbu_origen, nro_transaccion,
                        entidad, fecha_operacion, mail_de, capturado_en
                   FROM pagos WHERE id_unico = ? AND estado = 'revision' LIMIT 1"
            );
            $st->execute([$idUnico]);
            $pago = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pago) { salir(['ok' => false, 'error' => 'Ese pago ya no está en revisión'], 404); }
            $pago['monto'] = (float)$pago['monto'];

            // Sin q: las 20 recargas pendientes mas parecidas en monto (la
            // candidata correcta suele aparecer primero sin escribir nada).
            // Con q: ademas filtra por usuario, referencia exacta o id.
            /* Se incluyen las VENCIDAS, no solo las pendientes.

               Una recarga vence a los 45 minutos. Ofrecer solo pendientes hacia
               que un comprobante cuyo aviso del banco tardo mas que eso quedara
               SIN NINGUNA candidata: imposible de resolver desde el CRM, para
               siempre. Asi se juntaron 25.

               Que este vencida no dice que el pago no sea de ella; dice que el
               jugador tardo en transferir. Se manda `estado` para que el CRM lo
               muestre y el operador decida sabiendo. */
            $q = trim((string)($_GET['q'] ?? ''));
            $st2 = $pdo->prepare(
                "SELECT id, referencia, usuario, coins, monto_base, monto_pedido, centavos,
                        titular_declarado, creada_en, vence_en, estado
                   FROM recargas
                  WHERE estado IN ('pendiente','vencida')
                    AND (? = '' OR usuario LIKE ? OR referencia = ? OR id = ?)
                  ORDER BY ABS(monto_pedido - ?) ASC, creada_en DESC
                  LIMIT 20"
            );
            $st2->execute([$q, '%' . $q . '%', strtoupper($q), (int)($q !== '' ? $q : 0), $pago['monto']]);
            $candidatas = array_map(function ($r) {
                $r['id']           = (int)$r['id'];
                $r['coins']        = (int)$r['coins'];
                $r['monto_base']   = (float)$r['monto_base'];
                $r['monto_pedido'] = (float)$r['monto_pedido'];
                return $r;
            }, $st2->fetchAll(PDO::FETCH_ASSOC));

            /* Por que este pago PODRIA ser de cada candidata.
               Si el matcher automatico no la eligio es porque algo no le
               alcanzo -- dos titulares parecidos, o ninguno declarado. Pero
               las mismas señales que el uso sirven igual para ordenarle la
               lista al operador y que no tenga que compararlas de memoria.
               ACA NO SE ACREDITA NADA: es solo el orden y la explicacion, la
               decision sigue siendo de la persona. */
            $conHuella = function_exists('rl_usuarios_por_huella')
                ? rl_usuarios_por_huella($pdo, $pago) : [];
            foreach ($candidatas as &$c) {
                $c['huella']  = in_array((string)$c['usuario'], $conHuella, true);
                $c['parecido'] = (function_exists('rl_similitud_nombres') && trim((string)$c['titular_declarado']) !== '')
                    ? rl_similitud_nombres((string)$pago['remitente'], (string)$c['titular_declarado'])
                    : 0.0;
                /* La CUARTA señal: el nombre que hay adentro del nombre de
                   usuario. `holahector301` lleva "hector", y el banco dice
                   "HECTOR RAFAEL BAREIRO". Es la más obvia y no se usaba. */
                $c['por_nombre'] = 0.0;
                $nom = comp_nombre_de_usuario((string)$c['usuario']);
                if ($nom !== '' && function_exists('rl_similitud_nombres')) {
                    $c['por_nombre'] = rl_similitud_nombres((string)$pago['remitente'], $nom);
                }
                if ($c['huella']) {
                    $c['motivo'] = 'ya cargó antes desde esta cuenta';
                } elseif ($c['parecido'] >= RL_UMBRAL_NOMBRE) {
                    $c['motivo'] = sprintf('el titular coincide (%.0f%%)', $c['parecido'] * 100);
                } elseif ($c['por_nombre'] >= RL_UMBRAL_NOMBRE) {
                    $c['motivo'] = 'el nombre del jugador coincide con el del remitente';
                } elseif (abs($c['monto_pedido'] - $pago['monto']) < 0.005) {
                    $c['motivo'] = 'el monto es exacto';
                } else {
                    $c['motivo'] = '';
                }
            }
            unset($c);

            // Primero la huella (señal exacta), despues el parecido de
            // nombre, y a igualdad el monto mas cercano -- el mismo orden de
            // confianza que usa el matcher automatico.
            usort($candidatas, function ($a, $b) use ($pago) {
                if ($a['huella'] !== $b['huella'])       { return $b['huella'] <=> $a['huella']; }
                if ($a['parecido'] !== $b['parecido'])   { return $b['parecido'] <=> $a['parecido']; }
                if ($a['por_nombre'] !== $b['por_nombre']) { return $b['por_nombre'] <=> $a['por_nombre']; }
                return abs($a['monto_pedido'] - $pago['monto']) <=> abs($b['monto_pedido'] - $pago['monto']);
            });

            /* Para el caso en que NO haya ninguna candidata: los jugadores que
               ya cargaron antes desde esta misma cuenta bancaria. Es la mejor
               pista que tenemos para acreditarlo directo, y sale de cargas
               anteriores ya confirmadas -- no de adivinar por el nombre.

               Pasa sobre todo con los pagos del camino A (boton "Depositos"),
               que no crean fila en `recargas` y por eso nunca tienen candidata
               que ofrecer. */
            /* UNA SOLA SUGERENCIA, y el resto escondido detrás de "no es él".
               EL PEDIDO (Nahuel, 19/09/2026): *"en lugar de mostrarme todos los
               nombres de los usuarios abajo, debería simplemente sugerirme de
               quién puede ser esa carga... y ahí decirme si cargarle a ese
               usuario o no"*.

               Veinte nombres no son veinte opciones: son una lista que hay que
               descartar de a una, y el operador termina sin saber cuál mirar.
               Una sugerencia con sus motivos escritos se acepta o se rechaza en
               dos segundos.

               La sugerencia existe SOLO si hay una señal de verdad. Sin señal
               NO se sugiere a nadie: proponer al azar el de monto más parecido
               invita a acreditarle plata a quien no la mandó, que es el único
               error caro de esta pantalla. */
            $sug = null;
            $mejor = $candidatas[0] ?? null;
            if ($mejor && ($mejor['huella'] || $mejor['parecido'] >= RL_UMBRAL_NOMBRE
                           || $mejor['por_nombre'] >= RL_UMBRAL_NOMBRE)) {
                $porques = [];
                if ($mejor['huella']) { $porques[] = 'ya cargó antes desde esta misma cuenta bancaria'; }
                if ($mejor['parecido'] >= RL_UMBRAL_NOMBRE) {
                    $porques[] = 'declaró que transfiere a nombre de ' . $mejor['titular_declarado'];
                }
                if ($mejor['por_nombre'] >= RL_UMBRAL_NOMBRE) {
                    $porques[] = 'su nombre coincide con el del remitente';
                }
                if (abs($mejor['monto_pedido'] - $pago['monto']) < 0.005) {
                    $porques[] = 'pidió exactamente este monto';
                } else {
                    $porques[] = 'pidió $' . number_format((float)$mejor['monto_pedido'], 0, ',', '.')
                               . ' (transfirió $' . number_format((float)$pago['monto'], 0, ',', '.') . ')';
                }
                $sug = ['modo' => 'recarga', 'recarga_id' => (int)$mejor['id'],
                        'usuario' => (string)$mejor['usuario'],
                        'coins' => (int)$mejor['coins'],
                        'referencia' => (string)$mejor['referencia'],
                        'estado' => (string)$mejor['estado'],
                        'porques' => $porques];
            } elseif ($conHuella) {
                /* Sin candidata con señal, pero alguien ya pagó antes desde
                   esta misma cuenta. Es la mejor pista que hay para los pagos
                   del camino A, que nunca crean recarga. */
                $sug = ['modo' => 'directo', 'usuario' => (string)$conHuella[0],
                        'coins' => (int)round((float)$pago['monto']),
                        'porques' => ['ya cargó antes desde esta misma cuenta bancaria']];
            }

            salir(['ok' => true, 'pago' => $pago, 'candidatas' => $candidatas,
                   'sugerencia' => $sug,
                   'sugeridos' => array_values($conHuella)]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_comprobantes GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

// ============================== POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    if ($accion === 'asignar') {
        $idUnico   = trim((string)($body['pago_id'] ?? ''));
        $recargaId = (int)($body['recarga_id'] ?? 0);
        if ($idUnico === '' || !$recargaId) {
            salir(['ok' => false, 'error' => 'Faltan datos'], 400);
        }

        $r = rl_asignar_manual($pdo, $idUnico, $recargaId, $operador);
        if ($r['resultado'] !== 'acreditada') {
            salir(['ok' => false, 'error' => $r['error'] ?? 'No se pudo asignar'], 409);
        }
        salir(array_merge(['ok' => true], $r));
    }

    /* Acreditar el comprobante DIRECTO a un jugador, sin recarga.
       Para las transferencias que no tienen ninguna y nunca la van a tener:
       las del boton "Depositos" de la plataforma (no crean fila en `recargas`)
       y las de quien transfiere sin pedir nada por el chat.

       Antes de esto la unica salida era cargar fichas desde la ficha del
       jugador, que acredita pero deja el pago en 'revision' para siempre y no
       aprende la huella. rl_acreditar_directo() cierra las dos cosas. */
    if ($accion === 'acreditar_directo') {
        $idUnico = trim((string)($body['pago_id'] ?? ''));
        $usuario = trim((string)($body['usuario'] ?? ''));
        $coins   = (int)($body['coins'] ?? 0);
        if ($idUnico === '' || $usuario === '' || $coins <= 0) {
            salir(['ok' => false, 'error' => 'Faltan el comprobante, el jugador o el monto'], 400);
        }

        $r = rl_acreditar_directo($pdo, $idUnico, $usuario, $coins, $operador);
        if ($r['resultado'] !== 'acreditada') {
            salir(['ok' => false, 'error' => $r['error'] ?? 'No se pudo acreditar'], 409);
        }
        salir(array_merge(['ok' => true], $r));
    }

    /* Descartar un comprobante que NO es de ningún jugador: una transferencia
       propia (del operador) o de un tercero que no juega. Sin esto queda en
       'revision' para siempre -- sonando el aviso "sin resolver" y ocupando la
       bandeja --, porque las otras dos salidas (asignar / acreditar) le dan
       plata a alguien, y esta transferencia no va para nadie.

       NO toca coins ni saldo. Lo saca de 'revision' marcándolo 'usado' (el ENUM
       de pagos no tiene un estado propio de descarte) con la huella de quién lo
       descartó en asignado_por, así queda AUDITABLE y REVERSIBLE: devolverlo a
       'revision' lo trae de vuelta a la bandeja. Mismo criterio que la limpieza
       del backlog del apagón. */
    if ($accion === 'descartar') {
        $idUnico = trim((string)($body['pago_id'] ?? ''));
        if ($idUnico === '') {
            salir(['ok' => false, 'error' => 'Falta el comprobante'], 400);
        }
        // Solo se descarta lo que sigue en 'revision': si otro ya lo resolvió
        // (asignó/acreditó) entremedio, no se pisa -- el WHERE no matchea y
        // rowCount queda en 0. asignado_por es VARCHAR(60): 'descartado:' (11) +
        // 45 del operador entra con margen.
        $st = $pdo->prepare(
            "UPDATE pagos
                SET estado = 'usado',
                    asignado_por = ?, asignado_en = NOW()
              WHERE id_unico = ? AND estado = 'revision'"
        );
        $st->execute(['descartado:' . mb_substr($operador, 0, 45), $idUnico]);
        if ($st->rowCount() === 0) {
            salir(['ok' => false, 'error' => 'Ese comprobante ya no está en revisión'], 409);
        }
        salir(['ok' => true, 'descartado' => true]);
    }

    salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
