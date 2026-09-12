<?php
/**
 * t_bono_e2e.php — El bono de la landing, por el camino REAL de produccion.
 *
 * Los otros tests llaman a rl_acreditar() directo. Este recorre lo que pasa
 * de verdad, en el mismo orden y con las mismas funciones:
 *
 *   landing (alta con origen)  ->  chat (rl_crear_recarga)
 *     ->  mail del banco (rl_registrar_pago -> matcher)
 *       ->  acreditacion + bono  ->  deposito al juego (acciones_saldo)
 *
 * Si el bono "no se acredita", el corte tiene que aparecer ACA.
 *
 *     T_PORT=3399 php t_bono_e2e.php
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
require_once __DIR__ . '/api/landings_lib.php';

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
/** Deja al jugador como lo deja la landing: alta 'ok' con ese origen. */
function nacer(PDO $pdo, string $u, string $origen, int $id): void {
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(["e2e-$u-%"]);
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (?,?,0,0,0)")
        ->execute([$id, $u]);
    $pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', ?)")
        ->execute([$u, $origen]);
}
/** El mail del banco: paga el monto exacto de esa recarga, a nombre del titular. */
function pagarBanco(PDO $pdo, array $rec, string $titular, string $idu): array {
    return rl_registrar_pago($pdo, [
        'id_unico'  => $idu,
        'monto'     => (float)$rec['monto_pedido'],
        'remitente' => $titular,
        'dkim_pass' => 1,
        'mail_de'   => 'banco@test',
    ]);
}

// ===========================================================================
echo "=== 1. LANDING bono50: 3000 transferidos => 4500 en el juego ===\n";
$U = 'e2e_bono50';
nacer($pdo, $U, 'bono50', 987654401);
$r = rl_crear_recarga($pdo, $U, 3000, 'Juan Perez', true);
chequear('el chat crea la recarga', !empty($r['ok']), json_encode($r));
$rec = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U]);
$res = pagarBanco($pdo, $rec, 'Juan Perez', "e2e-$U-1");
chequear('el matcher la acredita sola', ($res['resultado'] ?? '') === 'acreditada', json_encode($res));
$u = fila($pdo, "SELECT coins, bonus FROM usuarios WHERE username = ?", [$U]);
$mb = fila($pdo, "SELECT monto FROM movimientos WHERE usuario = ? AND origen = 'bono_bienvenida'", [$U]);
chequear('el bono de bienvenida se acredito (1500)', (int)($mb['monto'] ?? 0) === 1500, json_encode($mb));
$a = fila($pdo, "SELECT monto, coins_debitados, bono_debitado, estado FROM acciones_saldo
                  WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('EL DEPOSITO AL JUEGO ES 4500 (3000 + 50%)', (int)($a['monto'] ?? 0) === 4500, json_encode($a));
chequear('desglosado: 3000 fichas + 1500 bono',
         (int)($a['coins_debitados'] ?? -1) === 3000 && (int)($a['bono_debitado'] ?? -1) === 1500, json_encode($a));
chequear('queda pendiente para el bot', ($a['estado'] ?? '') === 'pendiente', json_encode($a));
chequear('los contadores quedan en 0 (todo viajo al juego)',
         (int)$u['coins'] === 0 && (int)$u['bonus'] === 0, json_encode($u));

echo "\n=== 2. LANDING del CRM al 40%: 3000 => 4200 ===\n";
$U2 = 'e2e_lp40';
$pdo->exec("DELETE FROM landings WHERE slug = 'e2e40'");
$pdo->prepare("INSERT INTO landings (slug, nombre, plantilla, bono_pct, activa) VALUES ('e2e40','E2E 40','oro',40,1)")->execute();
nacer($pdo, $U2, 'lp:e2e40', 987654402);
rl_crear_recarga($pdo, $U2, 3000, 'Ana Lopez', true);
$rec2 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U2]);
$res2 = pagarBanco($pdo, $rec2, 'Ana Lopez', "e2e-$U2-1");
chequear('se acredita', ($res2['resultado'] ?? '') === 'acreditada', json_encode($res2));
$a2 = fila($pdo, "SELECT monto FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U2]);
chequear('EL DEPOSITO ES 4200 (el % de ESA landing)', (int)($a2['monto'] ?? 0) === 4200, json_encode($a2));

echo "\n=== 3. SEGUNDA carga del mismo jugador: SIN bono ===\n";
$pdo->prepare("UPDATE acciones_saldo SET estado='hecha' WHERE usuario = ?")->execute([$U]);
rl_crear_recarga($pdo, $U, 2000, 'Juan Perez', true);
$rec3 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? AND estado='pendiente' ORDER BY id DESC LIMIT 1", [$U]);
$res3 = pagarBanco($pdo, $rec3, 'Juan Perez', "e2e-$U-2");
chequear('se acredita', ($res3['resultado'] ?? '') === 'acreditada', json_encode($res3));
$a3 = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('deposita 2000 pelado (el bono es UNA vez)',
         (int)($a3['monto'] ?? 0) === 2000 && (int)($a3['bono_debitado'] ?? -1) === 0, json_encode($a3));
$nb = fila($pdo, "SELECT COUNT(*) c FROM movimientos WHERE usuario = ? AND origen='bono_bienvenida'", [$U]);
chequear('un solo movimiento de bono en toda su vida', (int)$nb['c'] === 1, json_encode($nb));

echo "\n=== 4. Registro SIN promo: no inventa bono ===\n";
$U4 = 'e2e_sinpromo';
nacer($pdo, $U4, 'landing', 987654404);
rl_crear_recarga($pdo, $U4, 3000, 'Luis Gomez', true);
$rec4 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U4]);
pagarBanco($pdo, $rec4, 'Luis Gomez', "e2e-$U4-1");
$a4 = fila($pdo, "SELECT monto FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U4]);
chequear('deposita 3000, sin bono inventado', (int)($a4['monto'] ?? 0) === 3000, json_encode($a4));

echo "\n=== 5. El % configurable manda sobre el default ===\n";
cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '70'], 'test');
$U5 = 'e2e_pct70';
nacer($pdo, $U5, 'bono50', 987654405);
rl_crear_recarga($pdo, $U5, 1000, 'Eva Diaz', true);
$rec5 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U5]);
pagarBanco($pdo, $rec5, 'Eva Diaz', "e2e-$U5-1");
$a5 = fila($pdo, "SELECT monto FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U5]);
chequear('con la config en 70: 1000 => 1700', (int)($a5['monto'] ?? 0) === 1700, json_encode($a5));
cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '50'], 'test');

// limpieza
foreach ([$U, $U2, $U4, $U5] as $x) {
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$x]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$x]);
}
$pdo->exec("DELETE FROM landings WHERE slug = 'e2e40'");
$pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'e2e-%'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
