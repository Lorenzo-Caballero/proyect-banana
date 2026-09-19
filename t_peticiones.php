<?php
/**
 * t_peticiones.php — La decision del camino A, probada donde duele.
 *
 * Estos chequeos existen porque aca se decide QUE transferencia paga QUE
 * solicitud de carga. Equivocarse no es un bug molesto: es aprobarle la carga a
 * un jugador con la plata que transfirio otro. Por eso hay tantos casos de "NO
 * tiene que aprobar" como de "si tiene que aprobar".
 *
 * Corre contra una base DE PRUEBA, nunca produccion. Por defecto goldpaw_demo
 * en el MySQL local (XAMPP, root sin clave):
 *
 *     php t_peticiones.php
 *     T_DB=otra_base T_USER=root T_PASS=x php t_peticiones.php
 *
 * Necesita las migraciones api/sql/45 (huellas_pagador) y 48.
 * Limpia lo suyo al empezar y al terminar (solo filas test_%).
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root',
    getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require __DIR__ . '/api/peticiones_lib.php';

$ok = 0; $fail = 0;
function chequear(string $que, bool $cond, string $detalle = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++;  printf("  OK    %s\n", $que); }
    else       { $fail++; printf("  FALLA %s   %s\n", $que, $detalle); }
}
function limpiar(PDO $pdo): void {
    $pdo->exec("DELETE FROM huellas_pagador WHERE usuario LIKE 'test\\_%'");
    $pdo->exec("DELETE FROM peticiones_carga WHERE request_id BETWEEN 90000000 AND 90000999");
}
/** Un pago tal como sale del SELECT de candidatas en peticiones_cola.php. */
function pago(string $id, string $remitente, string $cuit = '',
              string $capturado = '2026-08-31 10:00:00'): array {
    return ['id_unico' => $id, 'monto' => 1000.0, 'remitente' => $remitente,
            'cuit' => $cuit, 'cbu_origen' => '', 'capturado_en' => $capturado];
}

limpiar($pdo);

// ===========================================================================
echo "\n=== 1. Sin transferencia todavia ===\n";

[$p, $conf, $motivo] = pc_elegir_pago($pdo, [], 'test_ana', 'Ana Perez', 1);
chequear('sin candidatas no elige nada', $p === null && $conf === '');
chequear('el motivo dice que no entro la plata',
         str_contains($motivo, 'todavia no entro'), $motivo);
chequear('"no entro la plata" NO es ambiguo (se sigue esperando)',
         !pc_es_ambiguo($motivo), $motivo);

// ===========================================================================
echo "\n=== 2. Huella: ya cargo antes desde esa cuenta ===\n";

$pdo->prepare("INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre) VALUES (?,?,?,?)")
    ->execute(['test_ana', '27305551234', '', 'ANA PEREZ']);

// El remitente viene con un nombre que NO se parece al declarado: la huella
// tiene que alcanzar igual. Es el caso del banco que informa la razon social.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-H1', 'COOPERATIVA XYZ SRL', '27305551234')],
    'test_ana', 'Ana Perez', 1);
chequear('la huella CUIT decide aunque el nombre no coincida',
         $p !== null && $p['id_unico'] === 'TEST-H1' && $conf === 'alta', $motivo);

// La misma huella pero de OTRO jugador no sirve: la huella se verifica contra
// el usuario que pidio la carga, no contra cualquiera.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-H2', 'COOPERATIVA XYZ SRL', '27305551234')],
    'test_otro', 'Ana Perez', 1);
chequear('la huella de otro jugador NO decide por este',
         $p === null || $conf !== 'alta', "conf=$conf $motivo");

// ===========================================================================
echo "\n=== 3. Titular declarado ===\n";

[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-N1', 'PEREZ ANA MARIA')], 'test_x', 'Ana Perez', 1);
chequear('titular que coincide -> alta',
         $p !== null && $conf === 'alta', "conf=$conf $motivo");

// Erratas: es lo que agrego el matcher de PHP sobre el original.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-N2', 'NAHUER HERRERA')], 'test_x', 'Nahuel Herrera', 1);
chequear('tolera una errata en el nombre',
         $p !== null && $conf === 'alta', "conf=$conf $motivo");

// ===========================================================================
echo "\n=== 4. Empate de nombres: la parte peligrosa ===\n";

// Dos personas DISTINTAS (distinto CUIT) con nombres igual de parecidos.
// Aprobar cualquiera es 50% de chance de robarle la plata al otro.
[$p, $conf, $motivo] = pc_elegir_pago($pdo, [
    pago('TEST-E1', 'PEREZ ANA', '20111111111'),
    pago('TEST-E2', 'PEREZ ANA', '20222222222'),
], 'test_x', 'Ana Perez', 1);
chequear('dos personas distintas con el mismo nombre -> NO aprueba',
         $p === null, "eligio " . ($p['id_unico'] ?? '-'));
chequear('y queda marcado como ambiguo (lo mira un operador)',
         pc_es_ambiguo($motivo), $motivo);

// Mismo nombre, MISMO CUIT: no es ambiguo, es el mismo pagador dos veces.
[$p, $conf, $motivo] = pc_elegir_pago($pdo, [
    pago('TEST-F1', 'PEREZ ANA', '20111111111', '2026-08-31 12:00:00'),
    pago('TEST-F2', 'PEREZ ANA', '20111111111', '2026-08-31 09:00:00'),
], 'test_x', 'Ana Perez', 1);
chequear('dos transferencias del mismo pagador -> toma la mas vieja (FIFO)',
         $p !== null && $p['id_unico'] === 'TEST-F2',
         "eligio " . ($p['id_unico'] ?? 'null'));

// ===========================================================================
echo "\n=== 5. El nombre no verifica ===\n";

// Una sola transferencia y una sola solicitud: el resto encaja (monto exacto,
// entro despues de pedir la carga) y no hay con que confundirla.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-M1', 'JUAN GOMEZ')], 'test_x', 'Ana Perez', 1);
chequear('unica transferencia + unica solicitud -> media, aprueba',
         $p !== null && $conf === 'media', "conf=$conf $motivo");

// La MISMA transferencia, pero con dos solicitudes abiertas por ese monto.
// Ahora si hay con que confundirla: esta es la guarda que el camino B no tiene.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-M2', 'JUAN GOMEZ')], 'test_x', 'Ana Perez', 2);
chequear('la misma transferencia con DOS solicitudes abiertas -> NO aprueba',
         $p === null, "eligio " . ($p['id_unico'] ?? '-'));
chequear('y es ambiguo, no "seguir esperando"',
         pc_es_ambiguo($motivo), $motivo);

// Sin titular declarado tampoco se puede verificar por nombre.
[$p, $conf, $motivo] = pc_elegir_pago(
    $pdo, [pago('TEST-M3', 'JUAN GOMEZ')], 'test_x', '', 1);
chequear('sin titular declarado sigue siendo media (no alta)',
         $p !== null && $conf === 'media', "conf=$conf $motivo");

// Varias transferencias y ninguna con ese titular: no hay como elegir.
[$p, $conf, $motivo] = pc_elegir_pago($pdo, [
    pago('TEST-V1', 'JUAN GOMEZ', '20111111111'),
    pago('TEST-V2', 'CARLOS DIAZ', '20222222222'),
], 'test_x', 'Ana Perez', 1);
chequear('varias transferencias y ninguna con ese titular -> NO aprueba',
         $p === null, "eligio " . ($p['id_unico'] ?? '-'));

// ===========================================================================
echo "\n=== 6. La constante de temporalidad ===\n";

// El filtro por fecha lo hace el SQL de peticiones_cola.php, pero si alguien
// pone la gracia en 0 la regla deja de tener sentido (el jugador que transfiere
// un minuto antes de pedir la carga nunca podria cobrarla), y si la pone muy
// grande se vuelve a poder agarrar plata vieja de otra operacion.
chequear('la gracia esta definida y es razonable (1..60 min)',
         defined('PC_GRACIA_ANTES_MIN') && PC_GRACIA_ANTES_MIN >= 1
         && PC_GRACIA_ANTES_MIN <= 60, 'PC_GRACIA_ANTES_MIN=' . PC_GRACIA_ANTES_MIN);
chequear('deposito es el tipo 0', PC_TIPO_DEPOSITO === 0);

// ===========================================================================
echo "\n=== 7. El reclamo de la transferencia (contra la tabla real) ===\n";

// Esto es lo que impide que dos solicitudes se cobren la misma transferencia.
// No se prueba con mocks a proposito: lo garantiza el UNIQUE de la migracion
// 48, asi que si el UNIQUE no esta, el test tiene que fallar.
$pdo->prepare("INSERT INTO peticiones_carga (request_id, username, titular, monto)
               VALUES (?,?,?,?), (?,?,?,?)")
    ->execute([90000001, 'test_ana', 'Ana Perez', 1000,
               90000002, 'test_bob', 'Bob Diaz',  1000]);

$reclamar = $pdo->prepare(
    "UPDATE peticiones_carga SET pago_id_unico = ?, confianza = 'alta', motivo = 'test'
      WHERE request_id = ? AND pago_id_unico IS NULL AND estado = 'esperando'"
);
$reclamar->execute(['TEST-R1', 90000001]);
chequear('la primera solicitud reclama la transferencia', $reclamar->rowCount() === 1);

$choco = false;
try {
    $reclamar->execute(['TEST-R1', 90000002]);
} catch (PDOException $e) {
    $choco = true;   // 23000: duplicate key. Es lo que tiene que pasar.
}
chequear('la segunda NO puede quedarse con la misma transferencia', $choco,
         'el UNIQUE uq_pago no esta haciendo su trabajo');

// 'error' (el panel rechazo) suelta la transferencia: no entro, asi que puede
// respaldar otra solicitud.
$pdo->prepare("UPDATE peticiones_carga SET estado='error', pago_id_unico=NULL
                WHERE request_id = ?")->execute([90000001]);
$reclamar->execute(['TEST-R1', 90000002]);
chequear("'error' suelta la transferencia y otra la puede tomar",
         $reclamar->rowCount() === 1);

// 'revisar' (no sabemos si entro) NO la suelta: soltarla podria acreditarsela
// a otro mientras esta ya se aprobo.
$pdo->prepare("UPDATE peticiones_carga SET estado='revision' WHERE request_id = ?")
    ->execute([90000002]);
$st = $pdo->query("SELECT pago_id_unico FROM peticiones_carga WHERE request_id = 90000002");
chequear("'revisar' NO suelta la transferencia",
         $st->fetchColumn() === 'TEST-R1');

// Una solicitud ya cerrada no se vuelve a tocar (la guarda de confirmar()).
$upd = $pdo->prepare("UPDATE peticiones_carga SET estado='aprobada'
                       WHERE request_id = ? AND estado = 'esperando'");
$upd->execute([90000002]);
chequear('no se puede cerrar dos veces la misma solicitud', $upd->rowCount() === 0);

// ===========================================================================
echo "\n=== 8. Que el camino B no pise una transferencia del camino A ===\n";

/* El caso que cuesta plata: el worker reserva una transferencia para una carga
   pedida desde la plataforma, y mientras tanto un operador la asigna a mano
   desde Comprobantes. El jugador cobraria DOS veces -- una por la recarga y
   otra cuando se apruebe la solicitud en el panel. */
$pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
               ON DUPLICATE KEY UPDATE coins=0")->execute([crc32('test_dob'), 'test_dob']);
$pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado)
               VALUES ('TEST-DOBLE', 1000, 'ANA PEREZ', 'revision')")->execute();
$pdo->prepare(
    "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                           titular_declarado, estado, creada_en, vence_en)
     VALUES ('testdob01','test_dob',1000,1000,1000,'Ana Perez','pendiente',
             NOW(), DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
)->execute();
$recargaId = (int)$pdo->lastInsertId();

// Sin reserva, la asignacion manual funciona: eso es el camino B de siempre.
$r = rl_asignar_manual($pdo, 'TEST-DOBLE', $recargaId, 'test');
chequear('sin reserva, el operador puede asignar a mano',
         ($r['resultado'] ?? '') === 'acreditada', json_encode($r));

// Ahora con la transferencia reservada por una solicitud del camino A.
$pdo->exec("UPDATE pagos SET estado='revision', recarga_id=NULL, asignado_por=NULL
             WHERE id_unico='TEST-DOBLE'");
$pdo->exec("UPDATE recargas SET estado='pendiente', pago_id=NULL WHERE id=$recargaId");
$pdo->prepare("INSERT INTO peticiones_carga (request_id, username, titular, monto, pago_id_unico)
               VALUES (90000010,'test_dob','Ana Perez',1000,'TEST-DOBLE')")->execute();

$r = rl_asignar_manual($pdo, 'TEST-DOBLE', $recargaId, 'test');
chequear('reservada por el camino A -> NO se puede asignar a mano',
         ($r['resultado'] ?? '') === 'error', json_encode($r));
chequear('y el error dice donde resolverla',
         str_contains($r['error'] ?? '', 'Cargas del panel'), $r['error'] ?? '');

// Una solicitud ya cerrada NO reserva nada: la transferencia vuelve a estar
// disponible para el camino B.
$pdo->exec("UPDATE peticiones_carga SET estado='cerrada' WHERE request_id=90000010");
$r = rl_asignar_manual($pdo, 'TEST-DOBLE', $recargaId, 'test');
chequear('si la solicitud se cerro, la transferencia se libera',
         ($r['resultado'] ?? '') === 'acreditada', json_encode($r));

$pdo->exec("DELETE FROM recargas WHERE usuario = 'test_dob'");
$pdo->exec("DELETE FROM pagos WHERE id_unico = 'TEST-DOBLE'");
$pdo->exec("DELETE FROM usuarios WHERE username = 'test_dob'");
$pdo->exec("DELETE FROM movimientos WHERE usuario = 'test_dob'");

/* =========================================================================
   ¿YA FIGURA HECHA EN EL PANEL? El cruce contra el libro
   =========================================================================
   Los retiros ya cruzaban contra `operaciones_panel` y avisaban "esto ya
   figura hecho en el panel". Los depositos no, y corren el MISMO riesgo con
   los dos signos: si la carga ya se ejecuto alla y aca sigue abierta,
   rechazarla cancela algo que el jugador ya cobro, y aprobarla se la paga dos
   veces.

   Aca el cruce es EXACTO, por id, y esa es la diferencia con los retiros: un
   retiro del chat no tiene id en el panel y hay que buscarlo por usuario +
   monto + una ventana de horas; una solicitud de carga ES del panel, asi que su
   `request_id` y el `payment_id` del libro son el mismo numero. Verificado
   contra produccion el 19/09/2026: las 5 solicitudes aprobadas matchean por id
   y con el mismo monto.

   Se prueba la MISMA consulta que arma el endpoint. crm_peticiones.php corre
   auth al incluirse, asi que no se puede requerir desde un test. */
echo "\n=== El cruce contra el libro del panel ===\n";

$pdo->exec("DELETE FROM peticiones_carga WHERE request_id IN (90000101, 90000102, 90000103)");
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id IN (90000101, 90000102, 90000103)");

$meter = function (int $rid, string $estado) use ($pdo): void {
    $pdo->prepare(
        "INSERT INTO peticiones_carga (request_id, username, monto, estado, primera_vez)
         VALUES (?, 'test_lib', 5000, ?, NOW())"
    )->execute([$rid, $estado]);
};
$meter(90000101, 'esperando');   // abierta Y ejecutada en el panel -> tiene que avisar
$meter(90000102, 'esperando');   // abierta y NO ejecutada         -> no avisa
$meter(90000103, 'aprobada');    // ya resuelta por el sistema     -> no hace falta el aviso

foreach ([90000101, 90000103] as $rid) {
    $pdo->prepare(
        "INSERT INTO operaciones_panel (payment_id, tipo, username, monto, cuando)
         VALUES (?, 0, 'test_lib', 5000, NOW())"
    )->execute([$rid]);
}

/* La consulta del endpoint: solo las ABIERTAS se cruzan. */
$abiertas = $pdo->query(
    "SELECT request_id FROM peticiones_carga
      WHERE username = 'test_lib' AND estado IN ('esperando','revision','error')"
)->fetchAll(PDO::FETCH_COLUMN);
$marcas = implode(',', array_fill(0, count($abiertas), '?'));
$st = $pdo->prepare("SELECT payment_id FROM operaciones_panel
                      WHERE tipo = 0 AND payment_id IN ($marcas)");
$st->execute($abiertas);
$enLibro = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

chequear('la solicitud abierta que YA se ejecuto en el panel queda marcada',
         in_array(90000101, $enLibro, true),
         'sin esto, rechazarla cancela algo que el jugador ya cobro');
chequear('la que no se ejecuto NO se marca',
         !in_array(90000102, $enLibro, true),
         'un aviso de mas en cada fila se vuelve ruido y se deja de leer');
chequear('y las ya resueltas ni se cruzan',
         !in_array(90000103, $enLibro, true),
         'el aviso es para decidir, y sobre una aprobada ya no hay nada que decidir');

/* UN RETIRO CON EL MISMO ID NO CUENTA. `tipo` es lo unico que separa las dos
   mitades del libro (0 deposito, 1 retiro) y olvidarlo haria que un retiro
   viejo marque una carga como "ya hecha". */
$pdo->prepare("UPDATE operaciones_panel SET tipo = 1 WHERE payment_id = 90000101")->execute();
$st->execute($abiertas);
$soloRetiro = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
chequear('una operacion de RETIRO no marca una carga como hecha',
         !in_array(90000101, $soloRetiro, true),
         'tipo=0 es deposito y tipo=1 retiro: el filtro no es opcional');

$pdo->exec("DELETE FROM peticiones_carga WHERE username = 'test_lib'");
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id IN (90000101, 90000102, 90000103)");

/* Y que el endpoint lo haga de verdad, no solo que la consulta sirva. */
$srcP = file_get_contents(__DIR__ . '/api/crm_peticiones.php');
chequear('el endpoint cruza contra el libro',
         str_contains($srcP, 'hecho_en_panel') && str_contains($srcP, 'tipo = 0 AND payment_id IN'));
chequear('y la pantalla lo muestra',
         str_contains(file_get_contents(__DIR__ . '/landing/crm.html'),
                      'p.hecho_en_panel'));

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
