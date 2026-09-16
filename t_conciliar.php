<?php
/**
 * t_conciliar.php — Cerrar solas las acciones que el LIBRO dice que sí pasaron.
 *
 * EL PROBLEMA (16/09/2026). Cuando el WAF corta un depósito, el worker recibe
 * HTTP 200 con el HTML del challenge: no puede confirmar y marca `revisar`, que
 * es el lado seguro. Pero *"no pude confirmar"* no es *"no pasó"* — la request
 * pudo llegar igual, y llega. Dos acciones de holaDiego858 quedaron en
 * `revisar` con el depósito ya ejecutado en el panel, y ahí se iban a quedar.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, en orden de lo que más duele:
 *
 *  1. **Que un depósito del panel cierre UNA sola acción.** Las dos de
 *     holaDiego858 son de 750 y el libro tiene UN depósito de 750. Cerrar las
 *     dos diría que entraron 1.500, y quien lea ese historial en un mes va a
 *     concluir que se pagó de más. Lo garantiza el UNIQUE de la migración 70,
 *     no el PHP — que la base rechace el duplicado es lo único confiable.
 *
 *  2. **Que solo CIERRE, nunca falle nada.** Una ausencia en el libro puede ser
 *     "todavía no sincronizó": el libro tiene ventana móvil y backfill. Cerrar
 *     de más se nota; marcar un fracaso falso le saca la plata a alguien que la
 *     tiene.
 *
 *  3. **Que no toque las vivas.** `pendiente` y `procesando` pueden estar
 *     ejecutándose en este instante.
 *
 *  4. **Que no cruce un retiro con un depósito.** Nuestra 'cargar' es tipo 0 en
 *     el panel y 'retirar' es tipo 1. Al revés, cerraría un retiro contra un
 *     depósito: la peor confusión posible acá.
 *
 * Corre contra la base de prueba. Limpia lo suyo.
 *
 *     php t_conciliar.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
require_once __DIR__ . '/api/conciliar_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

try { $pdo->query("SELECT payment_id FROM acciones_saldo LIMIT 0"); }
catch (Throwable $e) {
    fwrite(STDERR, "Falta la migración 70 en la base de prueba:\n"
                 . "  php scripts/migrar.php 70   (o corré el .sql a mano)\n");
    exit(1);
}

$limpiar = function () use ($pdo) {
    $pdo->exec("DELETE FROM acciones_saldo WHERE usuario LIKE 'tc_%'");
    $pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 970099");
};
$limpiar();

/** Una acción trabada, como la deja el worker cuando el WAF le corta. */
$accion = function (string $u, string $tipo, float $monto, string $estado = 'revisar',
                    string $hace = '10 MINUTE') use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO acciones_saldo (usuario, tipo, monto, estado, mensaje, creada_en)
         VALUES (?,?,?,?,'deposito por API (200) <!DOCTYPE html>', NOW() - INTERVAL $hace)"
    )->execute([$u, $tipo, $monto, $estado]);
    return (int)$pdo->lastInsertId();
};
/** Una fila del libro del panel. tipo 0 = depósito, 1 = retiro. */
$libro = function (int $pid, string $u, float $monto, int $tipo = 0,
                   string $hace = '8 MINUTE') use ($pdo): void {
    $pdo->prepare(
        "INSERT INTO operaciones_panel (payment_id, tipo, username, monto, cuando)
         VALUES (?,?,?,?, NOW() - INTERVAL $hace)
         ON DUPLICATE KEY UPDATE monto = VALUES(monto)"
    )->execute([$pid, $tipo, $u, $monto]);
};
$estado = function (int $id) use ($pdo) {
    $s = $pdo->prepare("SELECT estado, payment_id, mensaje FROM acciones_saldo WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch();
};

// ===========================================================================
echo "\n=== 1. El caso real: quedó en 'revisar' pero el depósito entró ===\n";
$limpiar();
$id = $accion('tc_diego', 'cargar', 750);
$libro(970001, 'tc_diego', 750);

$r = conc_conciliar($pdo, 7);
chequear('conciliar responde ok', !empty($r['ok']), json_encode($r));
chequear('cierra la acción', (int)$r['cerradas'] === 1, json_encode($r));
$e = $estado($id);
chequear('queda en hecha', $e['estado'] === 'hecha', (string)$e['estado']);
chequear('y anota CUÁL movimiento del panel la respalda',
         (int)$e['payment_id'] === 970001, (string)$e['payment_id']);
chequear('el mensaje dice de dónde salió',
         str_contains((string)$e['mensaje'], '970001'), (string)$e['mensaje']);
/* El mensaje viejo no se pierde: es el que cuenta por qué se trabó. */
chequear('conserva el error original como contexto',
         str_contains((string)$e['mensaje'], 'DOCTYPE'), (string)$e['mensaje']);

/* Correr de nuevo no puede volver a tocarla ni contarla otra vez. */
$r2 = conc_conciliar($pdo, 7);
chequear('correrlo de nuevo no hace nada', (int)$r2['cerradas'] === 0, json_encode($r2));

// ===========================================================================
echo "\n=== 2. UN depósito cierra UNA acción (lo que más importa) ===\n";
/* EL CASO EXACTO: holaDiego858 tenía DOS acciones de 750 en 'revisar' y el
   libro UN solo depósito de 750. Cerrar las dos diría que entraron 1.500. */
$limpiar();
$a1 = $accion('tc_dos', 'cargar', 750, 'revisar', '20 MINUTE');
$a2 = $accion('tc_dos', 'cargar', 750, 'revisar', '18 MINUTE');
$libro(970010, 'tc_dos', 750, 0, '15 MINUTE');

$r = conc_conciliar($pdo, 7);
chequear('cierra UNA sola, no las dos', (int)$r['cerradas'] === 1, json_encode($r));
$e1 = $estado($a1); $e2 = $estado($a2);
$cerradas = (int)($e1['estado'] === 'hecha') + (int)($e2['estado'] === 'hecha');
chequear('exactamente una quedó en hecha', $cerradas === 1,
         $e1['estado'] . ' / ' . $e2['estado']);
chequear('la otra sigue abierta para que la mire una persona',
         $e1['estado'] === 'revisar' || $e2['estado'] === 'revisar');

/* Y con DOS depósitos de verdad, sí se cierran las dos: el jugador cargó dos
   veces y las dos entraron. */
$limpiar();
$a1 = $accion('tc_dosreal', 'cargar', 500, 'revisar', '30 MINUTE');
$a2 = $accion('tc_dosreal', 'cargar', 500, 'revisar', '28 MINUTE');
$libro(970020, 'tc_dosreal', 500, 0, '25 MINUTE');
$libro(970021, 'tc_dosreal', 500, 0, '24 MINUTE');
$r = conc_conciliar($pdo, 7);
chequear('con dos movimientos reales sí cierra las dos',
         (int)$r['cerradas'] === 2, json_encode($r));

// ===========================================================================
echo "\n=== 3. Solo cierra. Nunca marca un fracaso ===\n";
/* Una ausencia en el libro NO prueba que no pasó: el libro tiene ventana móvil
   y backfill. Marcar error acá le sacaría la plata a alguien que la tiene. */
$limpiar();
$id = $accion('tc_sinlibro', 'cargar', 1000);
$r = conc_conciliar($pdo, 7);
chequear('sin movimiento en el libro no la toca', (int)$r['cerradas'] === 0);
chequear('y la deja como estaba, NO en error',
         $estado($id)['estado'] === 'revisar', (string)$estado($id)['estado']);

// ===========================================================================
echo "\n=== 4. Las vivas no se tocan ===\n";
/* 'pendiente' y 'procesando' pueden estar ejecutándose en este instante:
   cerrarlas sería declarar hecho algo que está en vuelo. */
$limpiar();
$p = $accion('tc_viva', 'cargar', 300, 'pendiente');
$c = $accion('tc_viva2', 'cargar', 300, 'procesando');
$libro(970030, 'tc_viva', 300);
$libro(970031, 'tc_viva2', 300);
$r = conc_conciliar($pdo, 7);
chequear('no cierra ninguna viva', (int)$r['cerradas'] === 0, json_encode($r));
chequear('la pendiente sigue pendiente', $estado($p)['estado'] === 'pendiente');
chequear('la procesando sigue procesando', $estado($c)['estado'] === 'procesando');

// ===========================================================================
echo "\n=== 5. Un retiro NO se cierra contra un depósito ===\n";
/* Nuestra 'cargar' es tipo 0 en el panel y 'retirar' es tipo 1. Cruzarlos
   cerraría un retiro contra un depósito: la peor confusión posible acá. */
$limpiar();
$ret = $accion('tc_cruce', 'retirar', 2000);
$libro(970040, 'tc_cruce', 2000, 0);        // un DEPOSITO del mismo monto
$r = conc_conciliar($pdo, 7);
chequear('un depósito no cierra un retiro', (int)$r['cerradas'] === 0, json_encode($r));

$libro(970041, 'tc_cruce', 2000, 1);        // ahora sí, el retiro
$r = conc_conciliar($pdo, 7);
chequear('el retiro del libro sí lo cierra', (int)$r['cerradas'] === 1, json_encode($r));
chequear('y se ató al movimiento correcto',
         (int)$estado($ret)['payment_id'] === 970041, (string)$estado($ret)['payment_id']);

// ===========================================================================
echo "\n=== 6. La ventana de tiempo ===\n";
/* Un movimiento ANTERIOR al pedido no puede respaldarlo -- salvo unos minutos
   de gracia por el reloj del panel, que da la hora al minuto. */
$limpiar();
$id = $accion('tc_viejo', 'cargar', 400, 'revisar', '10 MINUTE');
$libro(970050, 'tc_viejo', 400, 0, '120 MINUTE');   // dos horas ANTES del pedido
$r = conc_conciliar($pdo, 7);
chequear('un movimiento anterior al pedido no lo respalda',
         (int)$r['cerradas'] === 0, json_encode($r));

$limpiar();
$id = $accion('tc_lejos', 'cargar', 400, 'revisar', '600 MINUTE');
$libro(970051, 'tc_lejos', 400, 0, '60 MINUTE');    // 9 h después: demasiado
$r = conc_conciliar($pdo, 7);
chequear('uno demasiado posterior tampoco', (int)$r['cerradas'] === 0, json_encode($r));

// ===========================================================================
echo "\n=== 7. El monto tiene que ser el mismo ===\n";
$limpiar();
$id = $accion('tc_monto', 'cargar', 750);
$libro(970060, 'tc_monto', 700);
$r = conc_conciliar($pdo, 7);
chequear('un monto distinto no cierra nada', (int)$r['cerradas'] === 0, json_encode($r));

$limpiar();
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
