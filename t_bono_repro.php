<?php
/**
 * t_bono_repro.php — Reproduce el bono que se PIERDE (incidente 12/9/2026).
 *
 * Sintoma reportado: un jugador carga 100 y recibe 150 (bien), pero otro
 * carga 7000 y recibe 7000 pelado -- el chat le habia prometido 3500 de bono.
 *
 * Hipotesis: el jugador que falla NO esta en el espejo `usuarios` (sync
 * atrasado o caido, tipico de una cuenta recien creada). Ahi:
 *   - rl_bono_bienvenida_aplicar hace UPDATE usuarios SET bonus=bonus+3500
 *     que NO afecta ninguna fila, pero igual devuelve 3500;
 *   - fichas_pedir_carga capa el bono contra el bonus leido del espejo
 *     ($fila = ['coins'=>0,'bonus'=>0] cuando no hay fila) => bono = 0;
 *   - se deposita SOLO la carga, y el bono se evapora sin un solo error.
 *
 *     T_PORT=3399 php t_bono_repro.php
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
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}
function limpiar(PDO $pdo, string $u): void {
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(["repro-$u-%"]);
}
/** El alta de la landing, SIN espejar en `usuarios` (sync atrasado). */
function nacerSinEspejo(PDO $pdo, string $u): void {
    limpiar($pdo, $u);
    $pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'bono50')")->execute([$u]);
}
function pagarBanco(PDO $pdo, array $rec, string $titular, string $idu): array {
    return rl_registrar_pago($pdo, [
        'id_unico' => $idu, 'monto' => (float)$rec['monto_pedido'],
        'remitente' => $titular, 'dkim_pass' => 1, 'mail_de' => 'banco@test',
    ]);
}

echo "=== A. Jugador ESPEJADO (el que funciono: 100 => 150) ===\n";
$A = 'repro_ok';
limpiar($pdo, $A);
$pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654501,?,0,0,0)")->execute([$A]);
$pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'bono50')")->execute([$A]);
rl_crear_recarga($pdo, $A, 100, 'Test Uno', true);
$rA = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$A]);
pagarBanco($pdo, $rA, 'Test Uno', "repro-$A-1");
$aA = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario=? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$A]);
chequear('deposita 150 (100 + 50 de bono)', (int)($aA['monto'] ?? 0) === 150, json_encode($aA));

echo "\n=== B. Jugador SIN espejar (el que fallo: 7000 => ¿7000 o 10500?) ===\n";
$B = 'repro_falla';
nacerSinEspejo($pdo, $B);
$rb = rl_crear_recarga($pdo, $B, 7000, 'Test Dos', true);
chequear('la recarga se crea igual', !empty($rb['ok']), json_encode($rb));
$rB = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$B]);
$resB = pagarBanco($pdo, $rB, 'Test Dos', "repro-$B-1");
chequear('el matcher la acredita', ($resB['resultado'] ?? '') === 'acreditada', json_encode($resB));
$mb = fila($pdo, "SELECT monto FROM movimientos WHERE usuario=? AND origen='bono_bienvenida'", [$B]);
chequear('el bono se CALCULO (movimiento de 3500)', (int)($mb['monto'] ?? 0) === 3500, json_encode($mb));
$aB = fila($pdo, "SELECT monto, coins_debitados, bono_debitado FROM acciones_saldo
                   WHERE usuario=? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$B]);
printf("  -> deposito encolado: %s\n", json_encode($aB));
chequear('EL DEPOSITO DEBE SER 10500 (7000 + 3500)', (int)($aB['monto'] ?? 0) === 10500,
         'deposito=' . (int)($aB['monto'] ?? 0) . ' bono_debitado=' . (int)($aB['bono_debitado'] ?? 0));

echo "\n=== C. Espejado pero con bonus ya gastado por otra via ===\n";
$C = 'repro_gastado';
limpiar($pdo, $C);
$pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654503,?,0,0,0)")->execute([$C]);
$pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'bono50')")->execute([$C]);
rl_crear_recarga($pdo, $C, 2000, 'Test Tres', true);
$rC = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$C]);
// Entre la acreditacion y el deposito, algo consume el bonus (ruleta, CRM...)
$pdo->prepare("UPDATE usuarios SET bonus = 0 WHERE username = ?")->execute([$C]);
pagarBanco($pdo, $rC, 'Test Tres', "repro-$C-1");
$aC = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario=? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$C]);
printf("  -> deposito encolado: %s\n", json_encode($aC));

foreach ([$A, $B, $C] as $x) { limpiar($pdo, $x); }
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
