<?php
/**
 * juegos_estado.php — Que juegos hay prendidos y que puede jugar HOY este
 * jugador. SOLO LECTURA. Publico.
 *
 * Existe para que el widget no tenga que pegarle a tres endpoints en cada
 * carga. Es de lectura pura: no sortea, no acredita, no consume nada.
 *
 * La whitelist de claves es EXPLICITA y nunca cfg_crm_todo(): esa funcion
 * arrastra meta_capi_token, que es un secreto de servidor.
 *
 * POST { token? } -> { ok, juegos: { ruleta:{...}, raspa:{...}, slot:{...} } }
 * (POST y no GET porque el JWT va en el body, igual que en el resto del sitio)
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/juegos_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// Sin cache: apagar un juego desde el CRM tiene que verse ya, no en 5 minutos.
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$body    = jug_body();
$usuario = jug_identidad($body);
$puede   = jug_puede_acreditar();

/** Cuantas tiradas de slot le quedan hoy. */
function slot_restantes(PDO $pdo, string $usuario, int $porDia): int
{
    if ($usuario === '') { return $porDia; }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM slot_tiradas
              WHERE usuario = ? AND dia = CURDATE() AND nro < 1000"
        );
        $st->execute([$usuario]);
        return max(0, $porDia - (int)$st->fetchColumn());
    } catch (Throwable $e) {
        return $porDia;   // sin la tabla, que el juego igual se ofrezca
    }
}

/** ¿Ya usó el cartón gratis de hoy? */
function raspa_usado(PDO $pdo, string $usuario): bool
{
    if ($usuario === '') { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT cobrado FROM raspa_cartones
              WHERE usuario = ? AND dia_diario = CURDATE() LIMIT 1"
        );
        $st->execute([$usuario]);
        $v = $st->fetchColumn();
        // Un cartón generado pero SIN cobrar no cuenta como usado: el jugador
        // lo dejó a medio raspar y tiene que poder volver a terminarlo.
        return $v !== false && (int)$v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $ruletaOn = cfg_crm_activo($pdo, 'ruleta_activa');
    $raspaOn  = cfg_crm_activo($pdo, 'raspa_activo') && $puede;
    $slotOn   = cfg_crm_activo($pdo, 'slot_activo')  && $puede;

    $restantes = $slotOn ? slot_restantes($pdo, $usuario, 3) : 0;

    $out = ['ok' => true, 'juegos' => [
        'ruleta' => [
            'activo'         => $ruletaOn,
            // La ruleta resuelve su propio "disponible" en ruleta.php: acá solo
            // se dice si está prendida, para no duplicar esa regla en dos lados.
            'requiere_login' => false,
            'mensaje'        => $ruletaOn ? '' : trim((string)cfg_crm($pdo, 'ruleta_mensaje')),
        ],
        'raspa' => [
            'activo'         => $raspaOn,
            'disponible'     => $raspaOn && $usuario !== '' && !raspa_usado($pdo, $usuario),
            'requiere_login' => true,
            'mensaje'        => $raspaOn ? '' : trim((string)cfg_crm($pdo, 'raspa_mensaje')),
        ],
        'slot' => [
            'activo'         => $slotOn,
            'disponible'     => $slotOn && $usuario !== '' && $restantes > 0,
            'restantes'      => $restantes,
            'requiere_login' => true,
            'mensaje'        => $slotOn ? '' : trim((string)cfg_crm($pdo, 'slot_mensaje')),
        ],
    ]];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('juegos_estado: ' . $e->getMessage());
    // Ante la duda, todo apagado: es mejor no ofrecer un juego que ofrecer uno
    // que después no va a andar.
    echo json_encode(['ok' => false, 'juegos' => []], JSON_UNESCAPED_UNICODE);
}
