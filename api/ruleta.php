<?php
/**
 * ruleta.php — Ruleta diaria. El premio se acredita en BONOS.
 *
 * Flujo nuevo (girar primero, reclamar despues):
 *   GET                         -> { ok, premios:[{label,bonus}] }  (para dibujar)
 *   POST { accion:"girar", session_id }
 *        -> el SERVIDOR elige el premio, lo guarda con un token y lo devuelve
 *           SIN acreditar. 1 giro por sesion por dia.
 *        { ok, indice, token, bonus, label, ya_giro, reclamado, mensaje }
 *   POST { accion:"reclamar", token, usuario }
 *        -> valida el usuario y ACREDITA el premio en usuarios.bonus.
 *           1 reclamo por usuario por dia.
 *        { ok, bonus, usuario }  o  { ok:false, error/codigo }
 *
 * Seguridad: el premio se decide en el server y queda atado al token; el cliente
 * no puede elegir cuanto gana ni reclamar un premio que no giro.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
// crm_lib es opcional: si esta, registra el movimiento en el historial.
$crmLib = __DIR__ . '/crm_lib.php';
if (is_file($crmLib)) { require_once $crmLib; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// =====================  EDITA LOS PREMIOS  ================================
// El ORDEN importa: el cliente dibuja los sectores en este mismo orden.
// 'bonus' = cuantos bonos se acreditan. 'peso' = probabilidad relativa.
const PREMIOS = [
    ['label' => '400 🎁',   'bonus' => 400,  'peso' => 30],
    ['label' => '1.000 🎁', 'bonus' => 1000, 'peso' => 30],
    ['label' => 'Nada 😢',   'bonus' => 0,    'peso' => 5],
    ['label' => '500 🎁',   'bonus' => 500,  'peso' => 25],
    ['label' => '2.000 🎁', 'bonus' => 2000, 'peso' => 10],
];
// ==========================================================================

function premios_publicos(): array
{
    return array_map(fn($p) => ['label' => $p['label'], 'bonus' => $p['bonus']], PREMIOS);
}

/** Elige un indice segun los pesos. random_int es criptografico. */
function elegir_indice(): int
{
    $total = 0;
    foreach (PREMIOS as $p) { $total += $p['peso']; }
    $r = random_int(1, $total);
    $acc = 0;
    foreach (PREMIOS as $i => $p) {
        $acc += $p['peso'];
        if ($r <= $acc) { return $i; }
    }
    return count(PREMIOS) - 1;
}

function existe_usuario(PDO $pdo, string $usuario): bool
{
    $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    return (bool)$st->fetchColumn();
}

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------- GET: premios para dibujar ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    salir(['ok' => true, 'premios' => premios_publicos()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string)($body['accion'] ?? 'girar');
$ip     = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    // ============================ GIRAR ====================================
    if ($accion === 'girar') {
        $session = substr(trim((string)($body['session_id'] ?? '')), 0, 64);
        if ($session === '') {
            salir(['ok' => false, 'error' => 'Falta session_id'], 400);
        }

        // ¿Ya giró esta sesión hoy? Devolvemos el mismo giro (no re-tira).
        $st = $pdo->prepare("SELECT indice, token, premio_bonus, reclamado
                             FROM ruleta_giros WHERE session_id = ? AND dia = CURDATE() LIMIT 1");
        $st->execute([$session]);
        $g = $st->fetch(PDO::FETCH_ASSOC);

        if ($g) {
            $i = (int)$g['indice'];
            salir([
                'ok'        => true,
                'indice'    => $i,
                'token'     => $g['token'],
                'bonus'     => (int)$g['premio_bonus'],
                'label'     => PREMIOS[$i]['label'],
                'ya_giro'   => true,
                'reclamado' => (bool)$g['reclamado'],
                'mensaje'   => $g['reclamado'] ? 'Ya reclamaste tu premio de hoy. Volvé mañana. 🕛' : '',
            ]);
        }

        $indice = elegir_indice();
        $bonus  = (int)PREMIOS[$indice]['bonus'];
        $token  = bin2hex(random_bytes(16));
        try {
            $pdo->prepare(
                "INSERT INTO ruleta_giros (session_id, dia, indice, premio_bonus, token, ip)
                 VALUES (?, CURDATE(), ?, ?, ?, ?)"
            )->execute([$session, $indice, $bonus, $token, $ip]);
        } catch (PDOException $e) {
            // Carrera: otra request creó el giro. Lo leemos.
            if (($e->errorInfo[1] ?? 0) == 1062) {
                $st->execute([$session]);
                $g = $st->fetch(PDO::FETCH_ASSOC);
                $i = (int)$g['indice'];
                salir(['ok' => true, 'indice' => $i, 'token' => $g['token'],
                       'bonus' => (int)$g['premio_bonus'], 'label' => PREMIOS[$i]['label'],
                       'ya_giro' => true, 'reclamado' => (bool)$g['reclamado']]);
            }
            throw $e;
        }

        salir(['ok' => true, 'indice' => $indice, 'token' => $token,
               'bonus' => $bonus, 'label' => PREMIOS[$indice]['label'], 'ya_giro' => false]);
    }

    // ============================ RECLAMAR =================================
    if ($accion === 'reclamar') {
        $token   = substr(trim((string)($body['token'] ?? '')), 0, 64);
        $usuario = trim((string)($body['usuario'] ?? ''));
        if ($token === '' || $usuario === '') {
            salir(['ok' => false, 'error' => 'Falta el token o el usuario'], 400);
        }

        // El giro tiene que existir, ser de hoy y no estar reclamado.
        $st = $pdo->prepare("SELECT id, indice, premio_bonus, reclamado
                             FROM ruleta_giros WHERE token = ? AND dia = CURDATE() LIMIT 1");
        $st->execute([$token]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            salir(['ok' => false, 'error' => 'Ese giro no es válido o venció. Girá de nuevo.'], 400);
        }
        if ((int)$g['reclamado'] === 1) {
            salir(['ok' => false, 'codigo' => 'ya_reclamado', 'error' => 'Ese premio ya fue reclamado.']);
        }

        $bonus = (int)$g['premio_bonus'];
        if ($bonus <= 0) {
            // "Nada": no hay nada que acreditar, pero marcamos el giro.
            $pdo->prepare("UPDATE ruleta_giros SET reclamado = 1, usuario = ?, reclamado_en = NOW()
                           WHERE id = ? AND reclamado = 0")->execute([substr($usuario, 0, 50), (int)$g['id']]);
            salir(['ok' => true, 'bonus' => 0, 'usuario' => $usuario, 'mensaje' => 'Esta vez no tocó premio. ¡Suerte mañana!']);
        }

        if (!existe_usuario($pdo, $usuario)) {
            salir(['ok' => false, 'codigo' => 'sin_usuario',
                   'error' => 'Ese usuario no existe. Registrate primero para reclamar.']);
        }

        // Un reclamo por usuario por dia.
        $st = $pdo->prepare("SELECT 1 FROM ruleta_giros
                             WHERE usuario = ? AND dia = CURDATE() AND reclamado = 1 LIMIT 1");
        $st->execute([$usuario]);
        if ($st->fetchColumn()) {
            salir(['ok' => false, 'codigo' => 'ya_reclamo_hoy',
                   'error' => 'Ese usuario ya reclamó un premio hoy. Volvé mañana. 🕛']);
        }

        // Marcamos el giro (guarda anti doble-reclamo por affected rows) y acreditamos.
        $upd = $pdo->prepare("UPDATE ruleta_giros SET reclamado = 1, usuario = ?, reclamado_en = NOW()
                              WHERE id = ? AND reclamado = 0");
        $upd->execute([substr($usuario, 0, 50), (int)$g['id']]);
        if ($upd->rowCount() !== 1) {
            salir(['ok' => false, 'error' => 'Ese premio ya fue reclamado.']);
        }

        if (function_exists('crm_cargar')) {
            $r = crm_cargar($pdo, $usuario, 'bono', $bonus, 'Ruleta diaria', 'ruleta');
            if (!$r['ok']) { salir(['ok' => false, 'error' => $r['error'] ?? 'No se pudo acreditar'], 400); }
        } else {
            $pdo->prepare("UPDATE usuarios SET bonus = bonus + ? WHERE username = ?")
                ->execute([$bonus, $usuario]);
        }

        salir(['ok' => true, 'bonus' => $bonus, 'usuario' => $usuario]);
    }

    salir(['ok' => false, 'error' => 'accion desconocida'], 400);
} catch (Throwable $e) {
    error_log('ruleta: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'No se pudo procesar', 'detalle' => $e->getMessage()], 500);
}
