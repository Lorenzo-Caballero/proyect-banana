<?php
/**
 * t_bono_carga.php â€” El bono de bienvenida LLEGA AL JUEGO.
 *
 * El bug que esto clava: el jugador de la landing bono50 transferÃ­a 3000 y en
 * la plataforma le aparecÃ­an 3000 â€” el 1500 del bono se sumaba en
 * usuarios.bonus y ahÃ­ morÃ­a, porque ningÃºn camino lo depositaba. Ahora el
 * auto-canje (rl_cargar_al_juego_auto -> fichas_pedir_carga) debita el bono y
 * lo suma al depÃ³sito: UNA carga por 4500.
 *
 *     T_PORT=3399 php t_bono_carga.php
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

$U = 'test_bono';
function limpiar(PDO $pdo, string $u): void {
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare(
        "INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654391, ?, 0, 0, 0)"
    )->execute([$u]);
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}

// ===========================================================================
echo "=== 1. fichas_pedir_carga con bono: UN deposito por el total ===\n";
limpiar($pdo, $U);
$pdo->prepare("UPDATE usuarios SET coins = 3000, bonus = 1500 WHERE username = ?")->execute([$U]);
$r = fichas_pedir_carga($pdo, $U, 3000, 'recarga', true, 1500);
chequear('la carga entra', !empty($r['ok']), json_encode($r));
chequear('el total del deposito es 4500', (int)($r['total'] ?? 0) === 4500, json_encode($r));
$a = fila($pdo, "SELECT * FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('acciones_saldo.monto = 4500 (lo que el bot deposita)', (int)$a['monto'] === 4500, json_encode($a));
chequear('coins_debitados = 3000', (int)$a['coins_debitados'] === 3000);
chequear('bono_debitado = 1500', (int)($a['bono_debitado'] ?? -1) === 1500);
$u = fila($pdo, "SELECT coins, bonus FROM usuarios WHERE username = ?", [$U]);
chequear('coins quedaron en 0', (int)$u['coins'] === 0, json_encode($u));
chequear('bonus quedo en 0 (se jugo en la carga)', (int)$u['bonus'] === 0, json_encode($u));

echo "\n=== 2. Si el panel falla, el bono vuelve a BONUS (no a coins) ===\n";
$dev = fichas_devolver($pdo, (int)$a['id']);
chequear('devuelve 4500 en total', $dev === 4500, (string)$dev);
$u = fila($pdo, "SELECT coins, bonus FROM usuarios WHERE username = ?", [$U]);
chequear('coins vuelven a 3000', (int)$u['coins'] === 3000, json_encode($u));
chequear('bonus vuelve a 1500', (int)$u['bonus'] === 1500, json_encode($u));
$a2 = fila($pdo, "SELECT coins_debitados, bono_debitado FROM acciones_saldo WHERE id = ?", [$a['id']]);
chequear('la accion queda en 0/0 (no se devuelve dos veces)',
         (int)$a2['coins_debitados'] === 0 && (int)$a2['bono_debitado'] === 0, json_encode($a2));
$dev2 = fichas_devolver($pdo, (int)$a['id']);
chequear('devolver de nuevo no regala nada', $dev2 === 0, (string)$dev2);

echo "\n=== 3. Bono capado a lo que el jugador tiene ===\n";
limpiar($pdo, $U);
$pdo->prepare("UPDATE usuarios SET coins = 3000, bonus = 800 WHERE username = ?")->execute([$U]);
$r = fichas_pedir_carga($pdo, $U, 3000, 'recarga', true, 1500);
$a = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('deposita 3800 (3000 + los 800 que habia)', (int)$a['monto'] === 3800, json_encode($a));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('bonus no queda negativo', (int)$u['bonus'] === 0, json_encode($u));

echo "\n=== 4. Punta a punta: recarga acreditada de jugador bono50 ===\n";
limpiar($pdo, $U);
$pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'bono50')")->execute([$U]);
$pdo->prepare(
    "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado, referencia, vence_en)
     VALUES (?, 3000, 3000.00, 3000.00, 'pendiente', 'TB1', DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
)->execute([$U]);
$recarga = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? LIMIT 1", [$U]);
$pdo->beginTransaction();
rl_acreditar($pdo, $recarga, 'pago-test-bono-1', 'test', null, null);
$pdo->commit();
chequear('es_primera = 1', (int)($recarga['es_primera'] ?? 0) === 1, json_encode($recarga['es_primera'] ?? null));
chequear('el bono viaja en la recarga (1500)', (int)($recarga['bono'] ?? 0) === 1500, json_encode($recarga['bono'] ?? null));
rl_cargar_al_juego_auto($pdo, $recarga);
$a = fila($pdo, "SELECT monto, coins_debitados, bono_debitado FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('EL DEPOSITO AL JUEGO ES 4500', (int)($a['monto'] ?? 0) === 4500, json_encode($a));
$u = fila($pdo, "SELECT coins, bonus FROM usuarios WHERE username = ?", [$U]);
chequear('contadores en 0 (todo viajo al juego)', (int)$u['coins'] === 0 && (int)$u['bonus'] === 0, json_encode($u));

echo "\n=== 5. Segunda recarga: SIN bono (una sola vez por jugador) ===\n";
// El bot ya deposito la primera: sin esto, la guarda de "una carga en curso
// por jugador" (correcta) bloquearia el segundo encolado y el caso no mide
// lo que quiere medir.
$pdo->prepare("UPDATE acciones_saldo SET estado='hecha' WHERE usuario = ?")->execute([$U]);
$pdo->prepare(
    "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado, referencia, vence_en)
     VALUES (?, 2000, 2000.00, 2000.00, 'pendiente', 'TB2', DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
)->execute([$U]);
$recarga2 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? AND referencia='TB2' LIMIT 1", [$U]);
$pdo->beginTransaction();
rl_acreditar($pdo, $recarga2, 'pago-test-bono-2', 'test', null, null);
$pdo->commit();
chequear('bono = 0 en la segunda', (int)($recarga2['bono'] ?? -1) === 0, json_encode($recarga2['bono'] ?? null));
rl_cargar_al_juego_auto($pdo, $recarga2);
$a = fila($pdo, "SELECT monto FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('deposito por 2000 pelado', (int)($a['monto'] ?? 0) === 2000, json_encode($a));

echo "\n=== 6. Sin promo: todo como siempre ===\n";
limpiar($pdo, $U);
$pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'registro')")->execute([$U]);
$pdo->prepare(
    "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado, referencia, vence_en)
     VALUES (?, 3000, 3000.00, 3000.00, 'pendiente', 'TB3', DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
)->execute([$U]);
$recarga3 = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? LIMIT 1", [$U]);
$pdo->beginTransaction();
rl_acreditar($pdo, $recarga3, 'pago-test-bono-3', 'test', null, null);
$pdo->commit();
rl_cargar_al_juego_auto($pdo, $recarga3);
$a = fila($pdo, "SELECT monto FROM acciones_saldo WHERE usuario = ? AND tipo='cargar' ORDER BY id DESC LIMIT 1", [$U]);
chequear('deposito por 3000, sin bono inventado', (int)($a['monto'] ?? 0) === 3000, json_encode($a));

limpiar($pdo, $U);
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);

