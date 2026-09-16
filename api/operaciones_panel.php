<?php
/**
 * operaciones_panel.php — recibe el LIBRO de lo que la plataforma ejecutó.
 *
 * POST (X-API-Key: BOT_API_KEY)
 *   {"operaciones": [
 *      {"id":234638975,"type":1,"username":"holanahuel265","amount":199,
 *       "name":"","cbu":null,"comment":"direct withdrawal",
 *       "created_at":"2026-09-14 01:33"}, ...]}
 *   -> {"ok":true,"recibidas":44,"guardadas":44,"ignoradas":0}
 *
 * QUIÉN LO LLAMA
 * `colector/aprobar_cargas.py`, que ya corre cada minuto con la sesión del
 * panel abierta. Lee
 *     GET {PANEL_API}/agent_admin/payment/requests/history/?type=0|1&...
 * y manda lo que vuelve, tal cual, sin interpretar nada. La decisión de qué
 * significa cada fila vive acá, del mismo modo que la decisión sobre las cargas
 * vive en peticiones_cola.php y no en el worker: cuando esto se decidía en
 * Python terminamos con dos criterios distintos que se separaron solos.
 *
 * POR QUÉ ES IDEMPOTENTE Y POR QUÉ IMPORTA
 * El worker reenvía la ventana ENTERA cada vez, no solo lo nuevo. Es a
 * propósito: así una pasada que se pierde (el WAF, un reinicio) se recupera
 * sola en la siguiente sin ningún estado que mantener. El precio es que las
 * mismas filas llegan una y otra vez, y por eso `payment_id` es PK y se
 * escribe con INSERT ... ON DUPLICATE KEY UPDATE. Acá se suma plata: una fila
 * duplicada sería un retiro contado dos veces en Finanzas.
 *
 * NO BORRA NADA. Una operación que dejó de venir en la ventana no es una
 * operación que se deshizo: es una que quedó fuera del rango de fechas. Este
 * libro solo crece.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Solo POST']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$filas = $body['operaciones'] ?? null;

if (!is_array($filas)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el arreglo `operaciones`']);
    exit;
}

/**
 * "2026-09-14 01:33" -> "2026-09-14 01:33:00", o null si no se entiende.
 *
 * NO SE INVENTA UNA FECHA SI NO SE PUEDE PARSEAR, y por eso devuelve null en
 * vez de NOW(): `cuando` es por donde filtran Finanzas y Auditoría, así que una
 * fecha inventada metería un retiro en el período equivocado. Mejor una fila
 * sin fecha —que no aparece en ningún rango y se nota— que una fila que le
 * cambia el número a un mes.
 *
 * Las fechas del panel vienen en NUESTRA misma zona (verificado el 14/09/2026
 * con dos cruces exactos contra `acciones_saldo` y `retiros_panel`), así que no
 * hay ninguna conversión que hacer.
 */
function op_fecha(?string $crudo): ?string
{
    $crudo = trim((string)$crudo);
    if ($crudo === '') { return null; }
    // Formatos aceptados, del que manda el panel hacia atrás.
    foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'd/m/Y H:i'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $crudo);
        if ($d instanceof DateTime && $d->format($fmt) === $crudo) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

$recibidas = count($filas);
$guardadas = 0;
$ignoradas = 0;

try {
    $st = $pdo->prepare(
        "INSERT INTO operaciones_panel
                (payment_id, tipo, username, monto, titular, destino,
                 comentario, creada_api, cuando)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
                username   = VALUES(username),
                monto      = VALUES(monto),
                titular    = VALUES(titular),
                destino    = VALUES(destino),
                comentario = VALUES(comentario),
                creada_api = VALUES(creada_api),
                /* visto_en SE REFRESCA EN CADA PASADA, y hasta el 16/09/2026 no:
                   solo se ponia al INSERT. La columna se llama asi --cuando la
                   vimos-- y significaba cuando la vimos POR PRIMERA VEZ, que no
                   es lo mismo y se lee mal.

                   Costo real: el simulacro de produccion uso MAX(visto_en) para
                   saber si el colector estaba sincronizando y dio 'hace 124
                   min' con el colector andando perfecto cada 16 minutos. Lo que
                   medía era cuando aparecio la ultima operacion NUEVA, que de
                   madrugada es otra cosa.

                   Asi MAX(visto_en) pasa a ser lo que hacia falta: la ultima
                   vez que el colector trajo el libro. Sin columnas nuevas ni un
                   latido aparte. */
                visto_en   = NOW(),
                cuando     = VALUES(cuando)"
    );
} catch (Throwable $e) {
    error_log('operaciones_panel: no pude preparar el INSERT: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta la migracion 67']);
    exit;
}

foreach ($filas as $f) {
    if (!is_array($f)) { $ignoradas++; continue; }

    $id   = isset($f['id']) ? (int)$f['id'] : 0;
    $tipo = isset($f['type']) ? (int)$f['type'] : -1;

    /* Sin id no hay idempotencia posible, y un `type` que no es ni depósito ni
       retiro es una operación que no sabemos leer: guardarla sería meter plata
       de significado desconocido en la suma de Finanzas. */
    if ($id <= 0 || !in_array($tipo, [0, 1], true)) { $ignoradas++; continue; }

    try {
        $st->execute([
            $id,
            $tipo,
            mb_substr((string)($f['username'] ?? ''), 0, 60),
            (float)($f['amount'] ?? 0),
            ($f['name']    ?? '') !== '' ? mb_substr((string)$f['name'], 0, 160) : null,
            ($f['cbu']     ?? null) !== null && $f['cbu'] !== ''
                ? mb_substr((string)$f['cbu'], 0, 120) : null,
            ($f['comment'] ?? '') !== '' ? mb_substr((string)$f['comment'], 0, 60) : null,
            mb_substr((string)($f['created_at'] ?? ''), 0, 40),
            op_fecha($f['created_at'] ?? null),
        ]);
        $guardadas++;
    } catch (Throwable $e) {
        error_log('operaciones_panel: fila ' . $id . ': ' . $e->getMessage());
        $ignoradas++;
    }
}

/* Qué tan atrás llega el libro. Lo usa Finanzas para saber si puede confiar en
   él para un período viejo o si tiene que caer a `acciones_saldo` -- ver
   fn_libro_cubre(). Se devuelve acá para que el worker lo loguee y se note si
   el backfill inicial nunca se corrió. */
$desde = null;
try {
    $desde = $pdo->query("SELECT MIN(cuando) FROM operaciones_panel")->fetchColumn() ?: null;
} catch (Throwable $e) { /* da igual: es informativo */ }

echo json_encode([
    'ok'         => true,
    'recibidas'  => $recibidas,
    'guardadas'  => $guardadas,
    'ignoradas'  => $ignoradas,
    'libro_desde' => $desde,
]);
