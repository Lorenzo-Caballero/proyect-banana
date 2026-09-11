<?php
/**
 * t_declarar.php — rl_declarar_pago(): el jugador declara a nombre de quien
 * transfirio, y eso desempata su carga.
 *
 * LA PIEZA QUE FALTABA (11/9/2026): las herramientas del chatbot
 * (informar_transferencia, verificar_comprobante) llamaban a rl_declarar_pago()
 * y recibian "funcion no disponible" -- nunca se habia implementado. Por eso el
 * bot daba vueltas pidiendo el titular y el numero de operacion sin que sirviera
 * de nada (ver el chat de santucruz275).
 *
 * Declarar NO acredita por si mismo: solo guarda el titular en la recarga y
 * re-intenta casar los pagos que estaban trabados por ambiguedad. El unico que
 * confirma la plata sigue siendo el aviso del banco.
 *
 * Corre contra la base DE PRUEBA. Limpia lo suyo. Necesita la migracion 45.
 *
 *     php t_declarar.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
// Nada puede salir a internet desde un test: apagar Meta antes de acreditar.
try {
    $pdo->prepare("INSERT INTO config_crm (clave, valor) VALUES ('meta_activo','0')
                   ON DUPLICATE KEY UPDATE valor='0'")->execute();
} catch (Throwable $e) {}
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function limpiar(PDO $pdo): void {
    $pdo->exec("DELETE FROM recargas WHERE usuario LIKE 'tdec\\_%'");
    $pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'TDEC-%'");
    $pdo->exec("DELETE FROM huellas_pagador WHERE usuario LIKE 'tdec\\_%'");
    $pdo->exec("DELETE FROM usuarios WHERE username LIKE 'tdec\\_%'");
}
function usuario(PDO $pdo, string $u): void {
    $pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
                   ON DUPLICATE KEY UPDATE coins=0")->execute([crc32($u), $u]);
}
function recarga(PDO $pdo, string $usuario, float $monto): int {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, centavos,
                               estado, creada_en, vence_en)
         VALUES (?,?,?,?,?,0, 'pendiente', NOW(), DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
    )->execute([substr(md5(uniqid('', true)), 0, 10), $usuario, (int)$monto, floor($monto), $monto]);
    return (int)$pdo->lastInsertId();
}
function pagoRevision(PDO $pdo, string $id, float $monto, string $remitente): void {
    $pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado)
                   VALUES (?,?,?, 'revision')")->execute([$id, $monto, $remitente]);
}
function coins(PDO $pdo, string $u): int {
    $st = $pdo->prepare("SELECT coins FROM usuarios WHERE username=?");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
}
function titularDe(PDO $pdo, int $recId): string {
    $st = $pdo->prepare("SELECT titular_declarado FROM recargas WHERE id=?");
    $st->execute([$recId]);
    return (string)$st->fetchColumn();
}

limpiar($pdo);

// ===========================================================================
echo "\n=== 1. Declarar guarda el titular en la recarga pendiente ===\n";
usuario($pdo, 'tdec_carlos');
$rc = recarga($pdo, 'tdec_carlos', 2000.00);
$r = rl_declarar_pago($pdo, 'tdec_carlos', 'CARLOS LOPEZ');
chequear('estado pendiente (el pago todavia no entro)', ($r['estado'] ?? '') === 'pendiente', json_encode($r));
chequear('el titular quedo guardado en la recarga', titularDe($pdo, $rc) === 'CARLOS LOPEZ');
chequear('NO acredito nada (declarar no es el aviso del banco)', coins($pdo, 'tdec_carlos') === 0);

// ===========================================================================
echo "\n=== 2. El titular declarado DESEMPATA dos recargas del mismo monto ===\n";
/* El caso que justifica todo: ana y beto piden $1000 a la vez. El pago de ANA
   entra pero queda en 'revision' porque con el monto solo no se distingue.
   Cuando ANA declara su titular, su carga se desempata y se acredita -- la de
   beto NO se toca. */
limpiar($pdo);
usuario($pdo, 'tdec_ana');
usuario($pdo, 'tdec_beto');
recarga($pdo, 'tdec_ana',  1000.00);
recarga($pdo, 'tdec_beto', 1000.00);
pagoRevision($pdo, 'TDEC-1', 1000.00, 'ANA GOMEZ');   // el pago es de ana

$r = rl_declarar_pago($pdo, 'tdec_ana', 'ANA GOMEZ');
chequear('se acredita a ana al declarar su titular', ($r['estado'] ?? '') === 'acreditada', json_encode($r));
chequear('ana recibio las fichas',   coins($pdo, 'tdec_ana')  === 1000);
chequear('beto NO recibio nada',      coins($pdo, 'tdec_beto') === 0);

// ===========================================================================
echo "\n=== 3. Declarar el titular EQUIVOCADO no acredita a nadie ===\n";
/* Si el que declara no es el titular del pago en revision, NO se acredita:
   el matcher no adivina. Se guarda el dato y se espera. */
limpiar($pdo);
usuario($pdo, 'tdec_ceci');
usuario($pdo, 'tdec_dani');
recarga($pdo, 'tdec_ceci', 1500.00);
recarga($pdo, 'tdec_dani', 1500.00);
pagoRevision($pdo, 'TDEC-2', 1500.00, 'CECILIA ROMERO');  // el pago es de ceci

// dani declara SU titular: no coincide con el remitente del pago -> no acredita
$r = rl_declarar_pago($pdo, 'tdec_dani', 'DANIEL FERNANDEZ');
chequear('no acredita a dani (su titular no es el del pago)', ($r['estado'] ?? '') === 'pendiente', json_encode($r));
chequear('nadie recibio fichas todavia',
         coins($pdo, 'tdec_ceci') === 0 && coins($pdo, 'tdec_dani') === 0);

// y cuando ceci declara el suyo, SI se acredita (y solo a ella)
$r = rl_declarar_pago($pdo, 'tdec_ceci', 'CECILIA ROMERO');
chequear('ceci declara y se acredita', ($r['estado'] ?? '') === 'acreditada', json_encode($r));
chequear('solo ceci recibio', coins($pdo, 'tdec_ceci') === 1500 && coins($pdo, 'tdec_dani') === 0);

// ===========================================================================
echo "\n=== 4. Sin recarga pendiente ===\n";
limpiar($pdo);
usuario($pdo, 'tdec_solo');
$r = rl_declarar_pago($pdo, 'tdec_solo', 'ALGUIEN');
chequear('sin_pendiente cuando no pidio ninguna carga', ($r['estado'] ?? '') === 'sin_pendiente', json_encode($r));

// ===========================================================================
echo "\n=== 5. El numero de operacion NO hace falta para casar ===\n";
/* Se acepta y se guarda, pero el desempate sale por el titular. Declarar con
   SOLO el titular (sin nro) tiene que acreditar igual. */
limpiar($pdo);
usuario($pdo, 'tdec_evi');
usuario($pdo, 'tdec_fabi');
recarga($pdo, 'tdec_evi',  3000.00);
recarga($pdo, 'tdec_fabi', 3000.00);
pagoRevision($pdo, 'TDEC-3', 3000.00, 'EVELYN CRUZ');
$r = rl_declarar_pago($pdo, 'tdec_evi', 'EVELYN CRUZ');   // sin numero de operacion
chequear('acredita solo con el titular, sin nro de operacion',
         ($r['estado'] ?? '') === 'acreditada' && coins($pdo, 'tdec_evi') === 3000, json_encode($r));

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
