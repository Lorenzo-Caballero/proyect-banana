<?php
/**
 * acciones_cola.php — Cola de acciones de SALDO para el bot del VPS.
 *
 * El sitio encola en `acciones_saldo` (el chatbot al canjear fichas, el CRM a
 * mano) y bot_cargar_fichas.py las ejecuta en el panel de agentes, porque el
 * MySQL de Hostinger no acepta conexiones remotas.
 *
 * Auth:  header  X-API-Key = BOT_API_KEY.
 *
 * GET  ?accion=pendientes[&limite=20]  -> RECLAMA (pasa a 'procesando') y devuelve
 * POST ?accion=marcar   body {id, estado:'hecha'|'error'|'revisar', mensaje}
 * POST ?accion=liberar                 -> destraba las que quedaron 'procesando'
 *
 * ACA SE MUEVE PLATA. Tres reglas que no hay que aflojar:
 *
 *   - Se reclama ANTES de entregar. Si dos consumidores leen la misma fila
 *     'pendiente' (el colector viejo y este bot, por ejemplo), depositan los
 *     dos y el jugador cobra doble.
 *   - 'error' devuelve las fichas; 'revisar' NO. 'revisar' significa "el bot no
 *     pudo confirmar si el deposito entro": devolver ahi es regalar plata.
 *   - Una accion que se cayo a la mitad NO vuelve sola a 'pendiente'. Vuelve a
 *     'revisar', porque reintentar un deposito que quizas entro es depositar
 *     dos veces. Destrabar es una decision de una persona (?accion=liberar).
 *
 * Requiere sql/15_fichas_al_panel.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/fichas_lib.php';
// Opcional: si notificaciones_lib.php no esta subido, la cola sigue andando y
// simplemente no se avisa. Una carga acreditada nunca puede fallar por el aviso.
$notifLib = __DIR__ . '/notificaciones_lib.php';
if (is_file($notifLib)) { require_once $notifLib; }

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();   // corta si la X-API-Key no coincide con BOT_API_KEY

const MINUTOS_COLGADA = 15;   // una accion 'procesando' mas vieja que esto se revisa

$accion = (string)($_GET['accion'] ?? 'pendientes');
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    // ------------------------- pendientes -------------------------
    if ($accion === 'pendientes') {

        // Las que quedaron colgadas (el bot se cayo con el navegador abierto)
        // van a 'revisar', NO a 'pendiente': no sabemos si alcanzo a depositar.
        $pdo->exec(
            "UPDATE acciones_saldo
                SET estado  = 'revisar',
                    mensaje = 'El bot se cortó mientras la ejecutaba. Verificar en el panel.'
              WHERE estado = 'procesando'
                AND tomada_en < DATE_SUB(NOW(), INTERVAL " . MINUTOS_COLGADA . " MINUTE)"
        );

        $limite = (int)($_GET['limite'] ?? 20);
        if ($limite < 1 || $limite > 100) { $limite = 20; }

        $pdo->beginTransaction();

        // Los RETIROS necesitan aprobacion de un agente (aprobado=1) antes de
        // ejecutarse; las cargas se toman igual que siempre. Ver migracion
        // sql/24_retiro_aprobar.sql y crm_retiros.php?accion=aprobar.
        $sel = $pdo->prepare(
            "SELECT id FROM acciones_saldo
              WHERE estado = 'pendiente'
                AND (tipo <> 'retirar' OR aprobado = 1)
              ORDER BY id ASC
              LIMIT $limite
              FOR UPDATE"
        );
        $sel->execute();
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);

        if (!$ids) {
            $pdo->commit();
            echo json_encode(['ok' => true, 'datos' => []]);
            exit;
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            "UPDATE acciones_saldo
                SET estado = 'procesando', tomada_en = NOW()
              WHERE id IN ($marcas)"
        )->execute($ids);

        /* `usuario_id` es el id del jugador EN GANAMOS (usuarios.id, que es
           justamente ese id, no uno nuestro). Se manda para que el worker
           pueda depositar por API --
             POST /api/agent_admin/user/{usuario_id}/payment/
           -- en vez de tener que buscar al jugador en el panel con un
           navegador. Esa busqueda es la que venia fallando: el listado no
           terminaba de cargar y la carga moria con "no encontre al usuario".

           LEFT JOIN y no JOIN: si el jugador no esta espejado todavia, la
           accion se entrega igual con usuario_id en null y el worker decide
           que hacer -- perder la accion seria peor.

           El COLLATE es obligatorio: `usuarios` esta en la collation por
           defecto del servidor y `acciones_saldo` en utf8mb4_unicode_ci. */
        $get = $pdo->prepare(
            "SELECT a.id, a.usuario, a.tipo, a.monto, a.motivo, a.origen, a.creada_en,
                    u.id AS usuario_id
               FROM acciones_saldo a
               LEFT JOIN usuarios u
                      ON u.username = a.usuario COLLATE utf8mb4_unicode_ci
              WHERE a.id IN ($marcas)
              ORDER BY a.id ASC"
        );
        $get->execute($ids);

        $datos = array_map(function ($r) {
            $r['id']    = (int)$r['id'];
            $r['monto'] = (float)$r['monto'];
            $r['usuario_id'] = $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null;
            return $r;
        }, $get->fetchAll(PDO::FETCH_ASSOC));

        $pdo->commit();
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------- marcar -----------------------------
    if ($accion === 'marcar' && $metodo === 'POST') {

        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $id      = (int)($body['id'] ?? 0);
        $estado  = (string)($body['estado'] ?? '');
        $mensaje = mb_substr((string)($body['mensaje'] ?? ''), 0, 300);

        if (!$id || !in_array($estado, ['hecha', 'error', 'revisar'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Datos invalidos']);
            exit;
        }

        // El saldo que el bot leyo en el panel, como comprobante de la carga.
        // Puede no venir: si no pudo leerlo, se guarda NULL y no se inventa.
        $sAntes   = isset($body['saldo_antes'])   ? (float)$body['saldo_antes']   : null;
        $sDespues = isset($body['saldo_despues']) ? (float)$body['saldo_despues'] : null;

        // Solo se cierra lo que estaba en curso. Sin esto, un mensaje repetido
        // del bot vuelve a "cerrar" una accion ya cerrada y dispara otra
        // devolucion de fichas.
        $upd = $pdo->prepare(
            "UPDATE acciones_saldo
                SET estado = ?, mensaje = ?, ejecutada_en = NOW(),
                    saldo_antes = ?, saldo_despues = ?
              WHERE id = ? AND estado IN ('pendiente','procesando')"
        );
        $upd->execute([$estado, $mensaje !== '' ? $mensaje : null, $sAntes, $sDespues, $id]);

        if ($upd->rowCount() === 0) {
            echo json_encode(['ok' => true, 'ya_cerrada' => true]);
            exit;
        }

        // El saldo que el bot acaba de LEER EN EL PANEL es la mejor verdad que
        // vamos a tener: sale de la plataforma, no del navegador del jugador.
        // Guardarlo aca hace que el CRM y el chatbot dejen de mostrar un numero
        // viejo despues de cada operacion, sin esperar la pasada del sync.
        //
        // Va DESPUES del rowCount: el bot reintenta los POST, y un 'marcar'
        // repetido no tiene por que volver a pisar el saldo con una lectura
        // que a esa altura ya puede ser vieja.
        if ($sDespues !== null) {
            $u = $pdo->prepare("SELECT usuario FROM acciones_saldo WHERE id = ?");
            $u->execute([$id]);
            if ($quien = $u->fetchColumn()) {
                $pdo->prepare("UPDATE usuarios SET balance = ? WHERE username = ?")
                    ->execute([$sDespues, $quien]);
            }
        }

        // Fallo confirmado: el jugador recupera sus fichas.
        $devuelto = 0;
        if ($estado === 'error') {
            $devuelto = fichas_devolver($pdo, $id);
        }

        // Recien ACA se avisa. No cuando el chatbot encola ni cuando el bot
        // aprieta el boton: cuando el saldo del panel se movio de verdad. Es el
        // unico momento en que decirle "ya lo tenes" no puede ser mentira.
        if ($estado === 'hecha' && function_exists('notif_crear')) {
            $acc = $pdo->prepare("SELECT usuario, tipo, monto FROM acciones_saldo WHERE id = ?");
            $acc->execute([$id]);
            if ($a = $acc->fetch()) {
                /* Purchase para Meta: ACA, no cuando el chatbot encolo. Este
                   es el mismo punto donde se le avisa al jugador "ya lo tenes"
                   -- el unico momento en que decirlo no es mentira. Reportarlo
                   antes haria que la campaña optimice contra pedidos y no
                   contra plata acreditada.

                   Solo las cargas: un retiro no es una compra. */
                if (($a['tipo'] ?? '') === 'cargar') {
                    try {
                        require_once __DIR__ . '/meta_lib.php';
                        require_once __DIR__ . '/publicidad_lib.php';
                        $atrib = publicidad_atribucion_por_usuario($pdo, (string)$a['usuario']);
                        meta_evento($pdo, 'Purchase', [
                            'usuario' => (string)$a['usuario'],
                            'valor'   => (float)$a['monto'],
                            'ref'     => 'carga:' . $id,
                            'fbp'     => $atrib['fbp'],
                            'fbc'     => $atrib['fbc'],
                            'pixel'   => publicidad_pixel_propio($atrib['publicista']),
                        ]);
                    } catch (Throwable $e) {
                        error_log('meta Purchase: ' . $e->getMessage());
                    }
                }
                $cuanto = number_format((float)$a['monto'], 0, ',', '.');
                $saldo  = $sDespues !== null
                        ? ' Tu saldo quedó en ' . number_format($sDespues, 0, ',', '.') . '.'
                        : '';
                notif_crear(
                    $pdo,
                    $a['usuario'],
                    '¡Fichas acreditadas!',
                    'Ya te cargamos ' . $cuanto . ' fichas.' . $saldo . ' A jugar.',
                    'fichas',
                    null,
                    'carga'
                );
            }
        }

        echo json_encode(['ok' => true, 'fichas_devueltas' => $devuelto]);
        exit;
    }

    // ------------------------- liberar ----------------------------
    // Vuelve a 'pendiente' lo que quedo en 'procesando'. Es a mano y a
    // proposito: quien lo corra tiene que haber mirado el panel antes.
    if ($accion === 'liberar' && $metodo === 'POST') {
        $n = $pdo->exec(
            "UPDATE acciones_saldo
                SET estado = 'pendiente', tomada_en = NULL
              WHERE estado = 'procesando'"
        );
        echo json_encode(['ok' => true, 'liberadas' => (int)$n]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'accion desconocida']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('acciones_cola: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()]);
}
