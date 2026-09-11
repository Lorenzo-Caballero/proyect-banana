<?php
/**
 * t_bono_crm.php — El bono cargado a mano desde el CRM LLEGA AL JUEGO.
 *
 * El agujero: crm.php cargar_bono sumaba usuarios.bonus y ahi moria — el
 * jugador veia el numero en el chat y en el juego nada. Ahora el CRM encola
 * un deposito SOLO-BONO (fichas_pedir_carga con monto=0), y ademas existe
 * bonos_al_juego para rescatar bonos acumulados (ruleta, cargas viejas).
 *
 *     T_PORT=3399 php t_bono_crm.php
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
require_once __DIR__ . '/api/fichas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
$U = 'test_bcrm';
function limpiar(PDO $pdo, string $u): void {
    foreach (['acciones_saldo', 'movimientos'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654392, ?, 0, 0, 0)")
        ->execute([$u]);
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}

echo "=== 1. Deposito SOLO-BONO: monto=0 + bono=500 ===\n";
limpiar($pdo, $U);
$pdo->prepare("UPDATE usuarios SET coins = 200, bonus = 500 WHERE username = ?")->execute([$U]);
$r = fichas_pedir_carga($pdo, $U, 0, 'crm', false, 500);
chequear('entra (sin chocar con el minimo de carga)', !empty($r['ok']), json_encode($r));
$a = fila($pdo, "SELECT * FROM acciones_saldo WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U]);
chequear('monto del deposito = 500', (int)($a['monto'] ?? 0) === 500, json_encode($a));
chequear('bono_debitado = 500, coins_debitados = 0',
         (int)($a['bono_debitado'] ?? -1) === 500 && (int)($a['coins_debitados'] ?? -1) === 0);
chequear("motivo dice 'Bonos al juego'", strpos((string)($a['motivo'] ?? ''), 'Bonos al juego') !== false, (string)($a['motivo'] ?? ''));
$u = fila($pdo, "SELECT coins, bonus FROM usuarios WHERE username = ?", [$U]);
chequear('bonus quedo en 0 y coins INTACTOS (200)', (int)$u['bonus'] === 0 && (int)$u['coins'] === 200, json_encode($u));
$mov = fila($pdo, "SELECT COUNT(*) c FROM movimientos WHERE usuario = ? AND motivo = 'Carga al juego'", [$U]);
chequear("sin movimiento de fichas de \$0", (int)$mov['c'] === 0);

echo "\n=== 2. Si el panel falla, el bono vuelve a bonus ===\n";
$dev = fichas_devolver($pdo, (int)$a['id']);
chequear('devuelve 500', $dev === 500, (string)$dev);
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('bonus vuelve a 500', (int)$u['bonus'] === 500, json_encode($u));

echo "\n=== 3. Solo-bono sin bonos: rechazado, nada encolado ===\n";
limpiar($pdo, $U);
$r = fichas_pedir_carga($pdo, $U, 0, 'crm', false, 300);
chequear("codigo sin_bonos", ($r['codigo'] ?? '') === 'sin_bonos', json_encode($r));
$n = fila($pdo, "SELECT COUNT(*) c FROM acciones_saldo WHERE usuario = ?", [$U]);
chequear('cero acciones encoladas', (int)$n['c'] === 0);

echo "\n=== 4. Solo-bono con carga en curso: en_curso, contador intacto ===\n";
limpiar($pdo, $U);
$pdo->prepare("UPDATE usuarios SET bonus = 400 WHERE username = ?")->execute([$U]);
$pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, estado) VALUES (?, 'cargar', 1000, 'pendiente')")
    ->execute([$U]);
$r = fichas_pedir_carga($pdo, $U, 0, 'crm', false, 400);
chequear("codigo en_curso", ($r['codigo'] ?? '') === 'en_curso', json_encode($r));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('el bonus NO se debito', (int)$u['bonus'] === 400, json_encode($u));

echo "\n=== 5. Guardas que no cambian ===\n";
limpiar($pdo, $U);
$r = fichas_pedir_carga($pdo, $U, -50, 'crm', false, 100);
chequear('monto negativo rechazado', empty($r['ok']), json_encode($r));
$r = fichas_pedir_carga($pdo, $U, 50, 'chatbot');
chequear('el minimo de carga sigue aplicando a cargas normales', ($r['codigo'] ?? '') === 'monto_bajo', json_encode($r));

limpiar($pdo, $U);
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
