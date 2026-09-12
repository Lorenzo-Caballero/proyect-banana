<?php
/**
 * inactivos.php — ¿Cuáles de estos jugadores están REALMENTE inactivos?
 *
 * Lo consume bot_recaudar.py antes de retirarle el saldo a nadie.
 *
 * POR QUÉ EXISTE
 * El bot ve el panel de ganamos, que NO sabe cuándo fue la última vez que la
 * persona hizo algo: ordena por saldo y nada más. Recaudar mirando sólo eso
 * significa elegir por "cuánto tiene", que no es lo mismo que "hace meses que
 * no aparece" -- y el día que la lista quede corta, el primero de la página 4
 * puede ser alguien que cargó ayer.
 *
 * Acá está el dato de verdad: `usuarios.ultima_actividad` (migración 46), que
 * escriben las cinco señales de que la persona está del otro lado -- reporta
 * saldo desde el juego, pide fichas, se le acredita una recarga, escribe por
 * el chat o inicia sesión. Este endpoint cruza la lista que vio el bot contra
 * esa columna y devuelve SÓLO los que llevan >= `dias` sin aparecer.
 *
 * Auth: header X-API-Key = BOT_API_KEY (mueve plata: nunca abrirlo).
 *
 * POST ?accion=filtrar  {"usuarios":["a","b"],"dias":30}
 *   -> { ok, dias, inactivos:[{usuario, dias_inactivo, ultima_actividad}],
 *        activos:[{usuario, dias_inactivo}], desconocidos:[...] }
 *
 * `desconocidos` = los que el bot vio en el panel y NO están en nuestro espejo
 * `usuarios`. NO se devuelven como inactivos a propósito: de esos no sabemos
 * nada, y "no sé" nunca puede autorizar un retiro.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();   // corta si la X-API-Key no coincide con BOT_API_KEY

$accion = (string)($_GET['accion'] ?? 'filtrar');
if ($accion !== 'filtrar' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'usar POST ?accion=filtrar']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$usuarios = array_values(array_filter(array_map(
    static fn($u) => mb_substr(trim((string)$u), 0, 50),
    (array)($body['usuarios'] ?? [])
), static fn($u) => $u !== ''));
$dias = (int)($body['dias'] ?? 30);
if ($dias < 1) { $dias = 1; }

if (!$usuarios) {
    echo json_encode(['ok' => true, 'dias' => $dias,
                      'inactivos' => [], 'activos' => [], 'desconocidos' => []]);
    exit;
}
if (count($usuarios) > 200) {
    $usuarios = array_slice($usuarios, 0, 200);
}

try {
    $marcas = implode(',', array_fill(0, count($usuarios), '?'));
    /* ultima_actividad NULL = nunca se le vio una señal. NO cuenta como
       inactivo automáticamente: puede ser un jugador nuevo que el backfill de
       la migración 46 no alcanzó a sembrar. Se informa aparte (`sin_dato`)
       para que el bot lo saltee, que es lo prudente cuando hay plata. */
    $st = $pdo->prepare(
        "SELECT username,
                ultima_actividad,
                TIMESTAMPDIFF(DAY, ultima_actividad, NOW()) AS dias_inactivo
           FROM usuarios
          WHERE username IN ($marcas)"
    );
    $st->execute($usuarios);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('inactivos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no se pudo consultar']);
    exit;
}

$porNombre = [];
foreach ($filas as $f) { $porNombre[(string)$f['username']] = $f; }

$inactivos = []; $activos = []; $desconocidos = []; $sinDato = [];
foreach ($usuarios as $u) {
    if (!isset($porNombre[$u])) { $desconocidos[] = $u; continue; }
    $f = $porNombre[$u];
    if ($f['ultima_actividad'] === null) { $sinDato[] = $u; continue; }
    $d = (int)$f['dias_inactivo'];
    $reg = ['usuario' => $u, 'dias_inactivo' => $d,
            'ultima_actividad' => (string)$f['ultima_actividad']];
    if ($d >= $dias) { $inactivos[] = $reg; } else { $activos[] = $reg; }
}

echo json_encode([
    'ok'           => true,
    'dias'         => $dias,
    'inactivos'    => $inactivos,
    'activos'      => $activos,
    'desconocidos' => $desconocidos,
    'sin_dato'     => $sinDato,
], JSON_UNESCAPED_UNICODE);
