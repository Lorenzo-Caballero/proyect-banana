<?php
/**
 * Cola de altas de jugadores. Es el endpoint que consume bot_crear_jugador.py.
 *
 * Reemplaza a cola_panel.php, que quedo roto cuando la migracion 07 borro la
 * tabla `jugadores`. El contrato HTTP es EL MISMO a proposito: el bot no se
 * toca, solo cambia la API_URL de su .env.
 *
 *   POST ?accion=encolar      -> {"usuario":"x","password":"y","email":"..."}
 *   GET  ?accion=ver&limite=20     -> espia la cola sin reclamar nada
 *   GET  ?accion=pendientes&limite=10  -> RECLAMA y devuelve registros
 *   POST ?accion=liberar      -> destraba los que quedaron en 'procesando'
 *   POST ?accion=marcar       -> {"id":1,"estado":"ok","mensaje":"..."}
 *
 * Todas exigen el header:  X-API-Key: <BOT_API_KEY>
 *
 * Este es el endpoint PRIVADO. El que usa la landing es crear_cuenta.php, que
 * no lleva clave: no puede ser el mismo archivo, porque 'ver' y 'pendientes'
 * devuelven las contrasenas en claro de toda la cola.
 *
 * Los nombres de columna son los nuevos (usuario, estado, password), pero el
 * JSON conserva los viejos (panel_estado, tiene_clave, creado) via alias,
 * porque son los que lee el bot.
 *
 * Requiere la migracion sql/13_cola_altas.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/altas_lib.php';

header('Content-Type: application/json; charset=utf-8');

const MAX_INTENTOS   = 3;
const MINUTOS_ZOMBIE = 15;   // reintentar los que quedaron colgados en 'procesando'

// Sin clave configurada NO se abre: este endpoint devuelve las contraseñas en
// claro, y una clave por defecto seria una clave publica (esta en el codigo).
exigir_api_key();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// encolar: pedir el alta de un jugador. Lo llama el CRM / el chatbot.
//
// Sin limite por IP, al reves que crear_cuenta.php: para llegar hasta aca hay
// que tener la BOT_API_KEY, y frenar al agente en medio de una tanda seria
// pelearse con el unico que tiene derecho a encolar de a muchos.
// ---------------------------------------------------------------------------
if ($accion === 'encolar' && $metodo === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $r = alta_encolar($pdo, [
        'usuario'  => $body['usuario']  ?? '',
        'password' => $body['password'] ?? '',
        'email'    => $body['email']    ?? '',
        'nombre'   => $body['nombre']   ?? '',
        'apellido' => $body['apellido'] ?? '',
        'origen'   => $body['origen']   ?? 'crm',
        'ip'       => alta_ip(),
    ]);

    http_response_code($r['http']);
    echo json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// reintentar: devuelve a la cola un alta que quedo en 'error' por haber agotado
// los intentos, SIN tocar la password.
//
// Encolar de nuevo el mismo usuario tambien lo revive, pero pisa la clave: si
// ese pedido vino de la landing, el jugador ya tiene anotada la vieja y se
// quedaria afuera de su propia cuenta. Por eso existe esta accion aparte.
// ---------------------------------------------------------------------------
if ($accion === 'reintentar' && $metodo === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el id']);
        exit;
    }

    // Sin password no hay nada que reintentar: el bot no puede tipear lo que
    // no tiene. Y estado='ok' no se toca ni por error: crearia un duplicado.
    $n = $pdo->prepare(
        "UPDATE altas
            SET estado    = 'pendiente',
                intentos  = 0,
                mensaje   = NULL,
                tomado_en = NULL
          WHERE id = ?
            AND estado IN ('error', 'procesando')
            AND password IS NOT NULL"
    );
    $n->execute([$id]);

    if ($n->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ese alta no se puede reintentar (ya esta hecha, no existe, o perdio la clave)']);
        exit;
    }

    echo json_encode(['ok' => true, 'id' => $id, 'estado' => 'pendiente']);
    exit;
}

// ---------------------------------------------------------------------------
// ver: espia la cola SIN reclamar nada. Para diagnosticar.
//
// 'pendientes' cambia el estado a 'procesando' como efecto necesario (evita
// que dos bots agarren el mismo registro), asi que no sirve para mirar: un
// simple test dejaria la cola vacia por 15 minutos.
// ---------------------------------------------------------------------------
if ($accion === 'ver' && $metodo === 'GET') {

    $limite = min(max((int)($_GET['limite'] ?? 10), 1), 50);

    $resumen = $pdo->query(
        "SELECT COUNT(*)                                        AS total,
                SUM(estado = 'ok')                              AS en_panel,
                SUM(estado = 'pendiente' AND password IS NOT NULL) AS listos,
                SUM(estado = 'procesando')                      AS tomados,
                SUM(estado = 'error')                           AS con_error,
                SUM(estado <> 'ok' AND password IS NULL)        AS sin_clave
           FROM altas"
    )->fetch();

    // Los alias son a proposito: el bot lee panel_estado / tiene_clave / creado.
    $filas = $pdo->query(
        "SELECT id, usuario, email, nombre, apellido,
                (estado = 'ok')        AS creado,
                estado                 AS panel_estado,
                intentos               AS panel_intentos,
                mensaje                AS panel_mensaje,
                tomado_en              AS panel_tomado_en,
                (password IS NOT NULL) AS tiene_clave
           FROM altas
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
if ($accion === 'liberar' && $metodo === 'POST') {

    $n = $pdo->exec(
        "UPDATE altas
            SET estado   = 'pendiente',
                intentos = 0,
                mensaje  = NULL
          WHERE estado   = 'procesando'
            AND password IS NOT NULL"
    );
    echo json_encode(['ok' => true, 'liberados' => (int)$n]);
    exit;
}

// ---------------------------------------------------------------------------
// pendientes: marca como 'procesando' ANTES de devolver, asi dos instancias
// del bot nunca agarran el mismo registro y no se duplica el alta en el panel.
// ---------------------------------------------------------------------------
if ($accion === 'pendientes' && $metodo === 'GET') {

    $limite = min(max((int)($_GET['limite'] ?? 10), 1), 50);

    try {
        // Rescatar zombies: quedaron en 'procesando' porque el bot se cayo.
        // Va DENTRO del try: si falla aca y queda afuera, la excepcion no la
        // atrapa nadie y el server tira un 500 sin JSON ni explicacion.
        // Los valores se interpolan porque son constantes del archivo, no
        // entrada del usuario, y MySQL no acepta placeholders en INTERVAL.
        $pdo->exec(
            "UPDATE altas
                SET estado = 'pendiente'
              WHERE estado = 'procesando'
                AND tomado_en < DATE_SUB(NOW(), INTERVAL " . MINUTOS_ZOMBIE . " MINUTE)
                AND intentos < " . MAX_INTENTOS
        );

        $pdo->beginTransaction();
        // Sin password el bot no puede completar el formulario: esos quedan
        // afuera de la cola aunque figuren como pendientes.
        $sel = $pdo->prepare(
            "SELECT id FROM altas
              WHERE estado = 'pendiente'
                AND intentos < ?
                AND password IS NOT NULL
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
            "UPDATE altas
                SET estado    = 'procesando',
                    tomado_en = NOW(),
                    intentos  = intentos + 1
              WHERE id IN ($marcas)"
        )->execute($ids);

        $get = $pdo->prepare(
            "SELECT id, usuario, password, email, nombre, apellido
               FROM altas
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
        error_log('altas_cola pendientes: ' . $e->getMessage());
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
if ($accion === 'marcar' && $metodo === 'POST') {

    $body    = json_decode(file_get_contents('php://input'), true) ?: [];
    $id      = (int)($body['id'] ?? 0);
    $estado  = $body['estado'] ?? '';
    $mensaje = mb_substr((string)($body['mensaje'] ?? ''), 0, 500);

    if (!$id || !in_array($estado, ['ok', 'error'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
        exit;
    }

    if ($estado === 'ok') {
        // Alta confirmada: BORRAMOS la clave en claro, que era lo unico que
        // justificaba tenerla guardada. El jugador real llega a `usuarios`
        // cuando pase sync_usuarios.py; esta fila queda solo como historial.
        // creado_en_panel = 1 es LA bandera que habilita entregar credenciales.
        // Se escribe aca y en ningun otro lado: este es el unico punto del
        // sistema donde consta que el panel de agentes confirmo el alta.
        $sql = "UPDATE altas
                   SET estado          = 'ok',
                       creado_en_panel = 1,
                       hecho_en        = NOW(),
                       mensaje         = ?,
                       password        = NULL
                 WHERE id = ?";
    } else {
        // Si fallo pero le quedan intentos, vuelve a la cola.
        $sql = "UPDATE altas
                   SET estado  = IF(intentos >= " . MAX_INTENTOS . ", 'error', 'pendiente'),
                       mensaje = ?
                 WHERE id = ? AND estado <> 'ok'";
    }

    $pdo->prepare($sql)->execute([$mensaje, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Accion desconocida']);
