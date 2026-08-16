<?php
/**
 * Cola de jugadores pendientes de alta en el panel de agentes.
 * Este es el endpoint que consume bot_crear_jugador.py
 *
 * GET  ?accion=pendientes&limite=10   -> reclama y devuelve registros
 * POST ?accion=marcar                 -> {"id":1,"estado":"ok","mensaje":"..."}
 *
 * Ambos requieren header:  X-API-Key: <clave>
 *
 * El estado 'ok' es el que pone  creado = 1  y borra la clave en claro.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

const MAX_INTENTOS   = 3;
const MINUTOS_ZOMBIE = 15;   // reintentar los que quedaron colgados en 'procesando'

// Sin clave configurada NO se abre: este endpoint devuelve las contraseñas en
// claro, y una clave por defecto seria una clave publica (esta en el codigo).
exigir_api_key();

$accion = $_GET['accion'] ?? '';

// ---------------------------------------------------------------------------
// ver: espia la cola SIN reclamar nada. Para diagnosticar.
//
// 'pendientes' cambia el estado a 'procesando' como efecto necesario (evita
// que dos bots agarren el mismo registro), asi que no sirve para mirar: un
// simple test dejaria la cola vacia por 15 minutos.
// ---------------------------------------------------------------------------
if ($accion === 'ver' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    $limite = min(max((int)($_GET['limite'] ?? 10), 1), 50);

    $resumen = $pdo->query(
        "SELECT COUNT(*)                                              AS total,
                SUM(creado = 1)                                       AS en_panel,
                SUM(creado = 0 AND panel_estado = 'pendiente'
                    AND panel_password IS NOT NULL)                   AS listos,
                SUM(panel_estado = 'procesando')                      AS tomados,
                SUM(panel_estado = 'error')                           AS con_error,
                SUM(creado = 0 AND panel_password IS NULL)            AS sin_clave
           FROM jugadores"
    )->fetch();

    $filas = $pdo->query(
        "SELECT id, nombre_usuario AS usuario, correo AS email,
                nombre, apellido, creado, panel_estado, panel_intentos,
                panel_mensaje, panel_tomado_en,
                (panel_password IS NOT NULL) AS tiene_clave
           FROM jugadores
          ORDER BY id ASC
          LIMIT $limite"
    )->fetchAll();

    echo json_encode(
        ['resumen' => array_map('intval', $resumen), 'datos' => $filas],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---------------------------------------------------------------------------
// liberar: devuelve a 'pendiente' los que quedaron tomados, sin esperar los
// 15 minutos. Util despues de un diagnostico o si cortaste el bot a mano.
// ---------------------------------------------------------------------------
if ($accion === 'liberar' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $n = $pdo->exec(
        "UPDATE jugadores
            SET panel_estado   = 'pendiente',
                panel_intentos = 0,
                panel_mensaje  = NULL
          WHERE creado = 0
            AND panel_estado = 'procesando'
            AND panel_password IS NOT NULL"
    );
    echo json_encode(['ok' => true, 'liberados' => (int)$n]);
    exit;
}

// ---------------------------------------------------------------------------
// pendientes: marca como 'procesando' ANTES de devolver, asi dos instancias
// del bot nunca agarran el mismo registro y no se duplica el alta en el panel.
// ---------------------------------------------------------------------------
if ($accion === 'pendientes' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    $limite = min(max((int)($_GET['limite'] ?? 10), 1), 50);

    try {
        // Rescatar zombies: quedaron en 'procesando' porque el bot se cayo.
        // Va DENTRO del try: si falla aca y queda afuera, la excepcion no la
        // atrapa nadie y el server tira un 500 sin JSON ni explicacion.
        // Los valores se interpolan porque son constantes del archivo, no
        // entrada del usuario, y MySQL no acepta placeholders en INTERVAL.
        $pdo->exec(
            "UPDATE jugadores
                SET panel_estado = 'pendiente'
              WHERE panel_estado = 'procesando'
                AND creado = 0
                AND panel_tomado_en < DATE_SUB(NOW(), INTERVAL " . MINUTOS_ZOMBIE . " MINUTE)
                AND panel_intentos < " . MAX_INTENTOS
        );

        $pdo->beginTransaction();
        // Solo los que NO estan en el panel y tienen la clave en claro:
        // sin panel_password el bot no puede completar el formulario.
        $sel = $pdo->prepare(
            "SELECT id FROM jugadores
              WHERE creado = 0
                AND panel_estado = 'pendiente'
                AND panel_intentos < ?
                AND panel_password IS NOT NULL
              ORDER BY id ASC
              LIMIT $limite
              FOR UPDATE"
        );
        $sel->execute([MAX_INTENTOS]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);

        if (!$ids) {
            $pdo->commit();
            echo json_encode(['datos' => []]);
            exit;
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            "UPDATE jugadores
                SET panel_estado    = 'procesando',
                    panel_tomado_en = NOW(),
                    panel_intentos  = panel_intentos + 1
              WHERE id IN ($marcas)"
        )->execute($ids);

        // Los alias son a proposito: el bot espera usuario/password/email.
        $get = $pdo->prepare(
            "SELECT id,
                    nombre_usuario AS usuario,
                    panel_password AS password,
                    correo         AS email,
                    nombre,
                    apellido
               FROM jugadores
              WHERE id IN ($marcas)
              ORDER BY id ASC"
        );
        $get->execute($ids);
        $datos = $get->fetchAll();

        $pdo->commit();
        echo json_encode(['datos' => $datos], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        // rollBack() explota si la transaccion ya se cerro, y esa excepcion
        // tapa la original, que es la que interesa.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        error_log('cola_panel pendientes: ' . $e->getMessage());
        // El detalle va en la respuesta a proposito: para llegar hasta aca hay
        // que haber pasado exigir_api_key(), y quien tiene la clave ya recibe
        // las contrasenas en claro. Sin esto, diagnosticar es a ciegas.
        echo json_encode([
            'ok'      => false,
            'error'   => 'Fallo al reclamar registros',
            'detalle' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------------------------------------------------------------------------
// marcar: el bot informa el resultado
// ---------------------------------------------------------------------------
if ($accion === 'marcar' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id      = (int)($body['id'] ?? 0);
    $estado  = $body['estado'] ?? '';
    $mensaje = mb_substr((string)($body['mensaje'] ?? ''), 0, 500);

    if (!$id || !in_array($estado, ['ok', 'error'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
        exit;
    }

    if ($estado === 'ok') {
        // Alta confirmada: prendemos el booleano y BORRAMOS la clave en claro,
        // que era lo unico que justificaba tenerla guardada.
        $sql = "UPDATE jugadores
                   SET creado          = 1,
                       panel_estado    = 'ok',
                       panel_creado_en = NOW(),
                       panel_mensaje   = ?,
                       panel_password  = NULL
                 WHERE id = ?";
    } else {
        // Si fallo pero le quedan intentos, vuelve a la cola.
        $sql = "UPDATE jugadores
                   SET panel_estado  = IF(panel_intentos >= " . MAX_INTENTOS . ", 'error', 'pendiente'),
                       panel_mensaje = ?
                 WHERE id = ? AND creado = 0";
    }

    $pdo->prepare($sql)->execute([$mensaje, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Accion desconocida']);
