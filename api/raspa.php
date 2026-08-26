<?php
/**
 * raspa.php — Raspa y Gana. El premio se acredita en BONOS.
 *
 *   GET                      -> { ok, activa, premios:[...] }   (para dibujar)
 *   POST { accion:"crear",  token }  -> { ok, token, celdas, cobrado }
 *   POST { accion:"raspar", token, carton } -> { ok, bonus, label }
 *
 * DOS FASES, Y NO ES CAPRICHO. El carton se GENERA (con su premio ya sorteado
 * y guardado) y despues se COBRA. Asi:
 *   - recargar la pagina a mitad del rascado devuelve el MISMO carton, no uno
 *     nuevo con otro premio;
 *   - el doble toque no cobra dos veces (el UPDATE es condicional);
 *   - y el premio nunca depende de nada que mande el cliente.
 *
 * UNO GRATIS POR DIA, garantizado por el UNIQUE (usuario, dia_diario). No hay
 * SELECT previo: se INSERTA y se captura el 1062. Un SELECT antes de escribir
 * se cobra dos veces con dos pestanas abiertas.
 *
 * EXIGE SESION VERIFICADA (JWT). Esto reparte plata: el `usuario` suelto que
 * manda el navegador no alcanza, cualquiera escribiria el nombre de otro y le
 * gastaria el carton del dia.
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

// =====================  EDITA LOS PREMIOS  ================================
// 'bonus' = cuantos bonos se acreditan. 'peso' = probabilidad relativa.
//
// El valor esperado de esta tabla es ~265 bonos por carton
// ((0*40 + 100*25 + 300*20 + 800*10 + 2000*5) / 100). Es a proposito un tercio
// de la ruleta: sumar un juego del mismo tamano duplica el costo diario de
// golpe. El 40% de "nada" es lo que hace que el 5% de 2.000 se sienta.
const RASPA_PREMIOS = [
    ['label' => 'Nada 😢',  'bonus' => 0,    'peso' => 40],
    ['label' => '100 🎁',   'bonus' => 100,  'peso' => 25],
    ['label' => '300 🎁',   'bonus' => 300,  'peso' => 20],
    ['label' => '800 🎁',   'bonus' => 800,  'peso' => 10],
    ['label' => '2.000 🎁', 'bonus' => 2000, 'peso' => 5],
];
const RASPA_CELDAS = 6;   // cuantas casillas tiene el carton

// El simbolo que se dibuja en la casilla, UNO POR FILA DE RASPA_PREMIOS y en
// el mismo orden. Va aca y no en el widget porque `celdas` viaja como indices
// de esa tabla: si los simbolos vivieran en el JS, agregar un premio aca
// correria todos los dibujos sin que nadie se entere. Si editas los premios,
// edita esta linea en el mismo movimiento.
const RASPA_SIMBOLOS = ['🍋', '🍒', '🔔', '⭐', '💎'];
// ==========================================================================

/**
 * Las casillas que se dibujan bajo la lamina.
 *
 * Un raspa se entiende porque REPITE: tres iguales = ganaste. Si gano, el
 * simbolo del premio aparece 3 veces y el resto se rellena sin formar otro
 * trio. Si no gano, se arma un carton donde ningun simbolo llegue a 3.
 */
function raspa_celdas(int $indicePremio, bool $gano): array
{
    $n = count(RASPA_PREMIOS);
    $celdas = [];

    if ($gano) {
        for ($i = 0; $i < 3; $i++) { $celdas[] = $indicePremio; }
        // El relleno no puede formar otro trio ni repetir el ganador.
        $usos = [];
        while (count($celdas) < RASPA_CELDAS) {
            $c = random_int(0, $n - 1);
            if ($c === $indicePremio) { continue; }
            $usos[$c] = ($usos[$c] ?? 0) + 1;
            if ($usos[$c] > 2) { continue; }
            $celdas[] = $c;
        }
    } else {
        // Ningun simbolo puede aparecer 3 veces, o el carton mentiria.
        $usos = [];
        $vueltas = 0;
        while (count($celdas) < RASPA_CELDAS && $vueltas++ < 200) {
            $c = random_int(0, $n - 1);
            if (($usos[$c] ?? 0) >= 2) { continue; }
            $usos[$c] = ($usos[$c] ?? 0) + 1;
            $celdas[] = $c;
        }
        // Red de seguridad: si el bucle se agoto, se completa alternando.
        $i = 0;
        while (count($celdas) < RASPA_CELDAS) { $celdas[] = $i++ % $n; }
    }

    shuffle($celdas);
    return $celdas;
}

// ---------------------------------------------------------------- GET
// Solo la tabla de premios y si esta prendido: lo usa el widget para dibujar
// el carton antes de que el jugador pida el suyo.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jug_salir([
        'ok'      => true,
        'activa'  => cfg_crm_activo($pdo, 'raspa_activo') && jug_puede_acreditar(),
        'premios' => jug_premios_publicos(RASPA_PREMIOS),
        'simbolos'=> RASPA_SIMBOLOS,
        'celdas'  => RASPA_CELDAS,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jug_error('metodo', 'Metodo no permitido', 405);
}

$body   = jug_body();
$accion = (string)($body['accion'] ?? 'crear');

jug_gate($pdo, 'raspa');

// Sin forma de acreditar, no se juega: prometer un premio que no se puede
// pagar es peor que no ofrecerlo.
if (!jug_puede_acreditar()) {
    jug_error('no_disponible', 'El juego no está disponible en este momento.', 503);
}

$usuario = jug_identidad($pdo, $body);
if ($usuario === '') {
    jug_error('sin_sesion', 'Iniciá sesión para jugar.', 403);
}

if (!jug_rate('raspa', 30, 60)) {
    jug_error('rapido', 'Esperá un momento.', 429);
}

try {
    // ======================= CREAR EL CARTON ==============================
    if ($accion === 'crear') {
        // El tope se mira ANTES de sortear: mejor decir "volvé mañana" que
        // sortear un premio y despues no poder pagarlo.
        if (!jug_tope_dia_ok($pdo, 'raspa')) {
            jug_error('sin_cupo', 'Por hoy se terminaron los cartones. ¡Volvé mañana!', 429);
        }

        $indice = jug_sortear(RASPA_PREMIOS);
        $premio = (int)RASPA_PREMIOS[$indice]['bonus'];
        $celdas = raspa_celdas($indice, $premio > 0);
        $token  = jug_token();

        try {
            $pdo->prepare(
                "INSERT INTO raspa_cartones
                   (usuario, dia, dia_diario, indice, premio_bonus, celdas, token, ip)
                 VALUES (?, CURDATE(), CURDATE(), ?, ?, ?, ?, ?)"
            )->execute([
                $usuario, $indice, $premio, implode(',', $celdas), $token,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { throw $e; }
            // Ya tiene el carton de hoy. Se devuelve EL MISMO -- recargar la
            // pagina a mitad del rascado no puede darle otro premio.
            $st = $pdo->prepare(
                "SELECT token, celdas, cobrado FROM raspa_cartones
                  WHERE usuario = ? AND dia_diario = CURDATE() LIMIT 1"
            );
            $st->execute([$usuario]);
            $prev = $st->fetch(PDO::FETCH_ASSOC);
            if (!$prev) {
                jug_error('sin_cupo', 'Ya usaste tu cartón de hoy. ¡Volvé mañana!', 409);
            }
            jug_salir([
                'ok'      => true,
                'token'   => $prev['token'],
                'celdas'  => array_map('intval', explode(',', (string)$prev['celdas'])),
                'simbolos'=> RASPA_SIMBOLOS,
                'cobrado' => (bool)(int)$prev['cobrado'],
                'repetido'=> true,
            ]);
        }

        jug_salir([
            'ok'      => true,
            'token'   => $token,
            'celdas'  => $celdas,
            'simbolos'=> RASPA_SIMBOLOS,
            'cobrado' => false,
        ]);
    }

    // ======================= COBRAR EL CARTON =============================
    if ($accion === 'raspar') {
        $token = trim((string)($body['carton'] ?? $body['token_carton'] ?? ''));
        if ($token === '') {
            jug_error('falta_token', 'Falta el cartón.');
        }

        // Se marca cobrado ANTES de acreditar, y el WHERE lleva cobrado = 0:
        // ese UPDATE condicional + rowCount es el candado contra el doble
        // toque. Se compara tambien el usuario: el token es opaco, pero un
        // token filtrado no puede cobrarse desde otra cuenta.
        $upd = $pdo->prepare(
            "UPDATE raspa_cartones SET cobrado = 1, cobrado_en = NOW()
              WHERE token = ? AND usuario = ? AND cobrado = 0"
        );
        $upd->execute([$token, $usuario]);

        $st = $pdo->prepare(
            "SELECT indice, premio_bonus, cobrado FROM raspa_cartones
              WHERE token = ? AND usuario = ? LIMIT 1"
        );
        $st->execute([$token, $usuario]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            jug_error('sin_carton', 'Ese cartón no existe.', 404);
        }

        $indice = (int)$fila['indice'];
        // El monto sale de la FILA, nunca de nada que haya mandado el cliente.
        $premio = (int)$fila['premio_bonus'];
        $label  = (string)(RASPA_PREMIOS[$indice]['label'] ?? '');

        if ($upd->rowCount() !== 1) {
            // Ya estaba cobrado: se responde lo mismo, sin acreditar de nuevo.
            jug_salir(['ok' => true, 'bonus' => $premio, 'label' => $label,
                       'ya_cobrado' => true]);
        }

        if ($premio > 0) {
            $ok = jug_acreditar($pdo, $usuario, $premio, 'Raspa y Gana', 'raspa');
            if (!$ok) {
                jug_error('sin_acreditar',
                    'Ganaste, pero no pude acreditarlo. Escribinos por el chat.', 500);
            }
            jug_dia_sumar($pdo, $usuario, 'raspa', $premio);
        }

        jug_salir(['ok' => true, 'bonus' => $premio, 'label' => $label]);
    }

    jug_error('accion', 'Acción desconocida');

} catch (Throwable $e) {
    // El detalle al log, nunca a la respuesta: acá contesta cualquiera.
    error_log('raspa: ' . $e->getMessage());
    jug_error('error', 'No pude procesar el cartón. Probá de nuevo.', 500);
}
