<?php
/**
 * slot777.php — Tragamonedas 777. El premio se acredita en BONOS.
 *
 *   GET                     -> { ok, activa, simbolos, pagos, tiradas_dia }
 *   POST { accion:"tirar", token } -> { ok, rodillos, bonus, label, restantes }
 *
 * NO SE PAGA CON FICHAS, a proposito. Un tragamonedas que se juega con fichas
 * compradas por transferencia es apuesta por dinero operada por esta capa,
 * fuera de la plataforma, y compite con los juegos de ganamos, que son el
 * negocio. Este slot es una mecanica de RETENCION: se paga con tiradas que
 * regala la casa.
 *
 * EL PREMIO PRIMERO, LOS RODILLOS DESPUES. Se sortea la fila de pagos y de ahi
 * se derivan los simbolos. Al reves -- girar rodillos y ver que salio -- la
 * probabilidad real la fija el azar de los simbolos y no la tabla, y el costo
 * de la casa deja de ser algo que se pueda decidir.
 *
 * TRES POR DIA, garantizado por el UNIQUE (usuario, dia, nro): el server
 * intenta nro = 1, 2, 3 y captura el 1062. Sin contador y sin SELECT previo,
 * asi el doble click no regala una tirada extra.
 *
 * Requiere sql/40_juegos.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/juegos_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// =====================  EDITA EL JUEGO  ===================================
const SLOT_SIMBOLOS = ['🍒', '🍋', '🔔', '⭐', '7️⃣'];

// 'combo' = indices de SLOT_SIMBOLOS, o null para "nada".
// 'dos_cerezas' arma dos 🍒 en posiciones al azar (el premio chico que hace
// que el juego no se sienta una pared).
//
// Valor esperado ~164 por tirada -> ~492/dia con las 3 gratis. El 60% de
// "nada" es lo que hace que se sienta un tragamonedas y no un reparto; el 1%
// de 777 es el premio del que se habla.
const SLOT_PAGOS = [
    ['label' => '¡7 7 7! 🎉', 'combo' => [4, 4, 4], 'bonus' => 5000, 'peso' => 1],
    ['label' => '⭐⭐⭐',        'combo' => [3, 3, 3], 'bonus' => 1500, 'peso' => 2],
    ['label' => '🔔🔔🔔',        'combo' => [2, 2, 2], 'bonus' => 600,  'peso' => 5],
    ['label' => '🍋🍋🍋',        'combo' => [1, 1, 1], 'bonus' => 300,  'peso' => 8],
    ['label' => '🍒🍒🍒',        'combo' => [0, 0, 0], 'bonus' => 200,  'peso' => 9],
    ['label' => 'Dos 🍒',       'combo' => 'dos_cerezas', 'bonus' => 80, 'peso' => 15],
    ['label' => 'Nada 😢',      'combo' => null,      'bonus' => 0,    'peso' => 60],
];

const SLOT_TIRADAS_DIA = 3;      // gratis por usuario por dia
const SLOT_NRO_TICKET  = 1000;   // las de regalo arrancan aca, fuera del cupo
// ==========================================================================

/** ¿Estos tres rodillos caen en alguna fila que paga? */
function slot_paga(array $r): bool
{
    foreach (SLOT_PAGOS as $p) {
        $c = $p['combo'];
        if (is_array($c) && $c === $r) { return true; }
    }
    // Dos o mas cerezas tambien paga (fila 'dos_cerezas').
    $cerezas = 0;
    foreach ($r as $s) { if ($s === 0) { $cerezas++; } }
    return $cerezas >= 2;
}

/**
 * Los rodillos que corresponden a la fila sorteada.
 *
 * Para "nada" se generan al azar y se re-tira mientras caigan en algo que
 * paga: si no, el jugador veria 🍒🍒🍒 en pantalla y el server le diria que
 * no gano -- la peor experiencia posible en un juego de azar.
 */
function slot_rodillos($combo): array
{
    $n = count(SLOT_SIMBOLOS);

    if (is_array($combo)) { return $combo; }

    if ($combo === 'dos_cerezas') {
        $r = [0, 0, random_int(1, $n - 1)];   // el tercero nunca cereza
        shuffle($r);
        return $r;
    }

    // "Nada": tres al azar que NO formen premio. El bucle es acotado; si en 40
    // vueltas no sale (imposible en la practica), se fuerza una combinacion sin
    // premio a mano.
    for ($i = 0; $i < 40; $i++) {
        $r = [random_int(0, $n - 1), random_int(0, $n - 1), random_int(0, $n - 1)];
        if (!slot_paga($r)) { return $r; }
    }
    return [1, 2, 3];   // 🍋 🔔 ⭐: ni trio ni cerezas
}

// ---------------------------------------------------------------- GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jug_salir([
        'ok'          => true,
        'activa'      => cfg_crm_activo($pdo, 'slot_activo') && jug_puede_acreditar(),
        'simbolos'    => SLOT_SIMBOLOS,
        'pagos'       => jug_premios_publicos(SLOT_PAGOS),
        'tiradas_dia' => SLOT_TIRADAS_DIA,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jug_error('metodo', 'Metodo no permitido', 405);
}

$body = jug_body();
jug_gate($pdo, 'slot');

if (!jug_puede_acreditar()) {
    jug_error('no_disponible', 'El juego no está disponible en este momento.', 503);
}

$usuario = jug_identidad($pdo, $body);
if ($usuario === '') {
    jug_error('sin_sesion', 'Iniciá sesión para jugar.', 403);
}

if (!jug_rate('slot', 40, 60)) {
    jug_error('rapido', 'Esperá un momento.', 429);
}

if (((string)($body['accion'] ?? 'tirar')) !== 'tirar') {
    jug_error('accion', 'Acción desconocida');
}

try {
    if (!jug_tope_dia_ok($pdo, 'slot')) {
        jug_error('sin_cupo', 'Por hoy se terminaron los premios. ¡Volvé mañana!', 429);
    }

    $indice   = jug_sortear(SLOT_PAGOS);
    $fila     = SLOT_PAGOS[$indice];
    $rodillos = slot_rodillos($fila['combo']);
    $premio   = (int)$fila['bonus'];

    // El INSERT ES el consumo de la tirada: se intenta nro = 1..N y el primero
    // que no choque con el UNIQUE es el que corresponde. Sin contador previo,
    // asi dos clicks simultaneos no se llevan dos tiradas del mismo numero.
    $ins = $pdo->prepare(
        "INSERT INTO slot_tiradas (usuario, dia, nro, indice, rodillos, premio_bonus, ip)
         VALUES (?, CURDATE(), ?, ?, ?, ?, ?)"
    );

    $nro = 0;
    for ($i = 1; $i <= SLOT_TIRADAS_DIA; $i++) {
        try {
            $ins->execute([
                $usuario, $i, $indice, implode(',', $rodillos), $premio,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $nro = $i;
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { throw $e; }
            continue;   // ese numero ya esta usado hoy: probar el siguiente
        }
    }

    if ($nro === 0) {
        jug_salir([
            'ok'        => false,
            'codigo'    => 'sin_tiradas',
            'error'     => 'Ya usaste tus tiradas de hoy. ¡Volvé mañana!',
            'restantes' => 0,
        ], 429);
    }

    // Acreditar DESPUES de que la tirada quedo escrita: si algo revienta acá,
    // se pierde el premio en vez de duplicarlo.
    if ($premio > 0) {
        $ok = jug_acreditar($pdo, $usuario, $premio, 'Tragamonedas 777', 'slot777');
        if (!$ok) {
            jug_error('sin_acreditar',
                'Ganaste, pero no pude acreditarlo. Escribinos por el chat.', 500);
        }
        jug_dia_sumar($pdo, $usuario, 'slot', $premio);
    }

    jug_salir([
        'ok'        => true,
        'rodillos'  => $rodillos,
        'simbolos'  => SLOT_SIMBOLOS,
        'bonus'     => $premio,
        'label'     => (string)$fila['label'],
        'restantes' => max(0, SLOT_TIRADAS_DIA - $nro),
    ]);

} catch (Throwable $e) {
    error_log('slot777: ' . $e->getMessage());
    jug_error('error', 'No pude procesar la tirada. Probá de nuevo.', 500);
}
