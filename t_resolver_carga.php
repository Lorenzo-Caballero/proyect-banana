<?php
/**
 * t_resolver_carga.php — "Marcar resuelto" cierra una carga trabada y calla el
 * aviso de Telegram, sin depositar ni devolver.
 *
 * El watchdog (scripts/monitor-cargas.sh) manda "hay cargas que el jugador pagó
 * y no recibió" mientras haya filas `acciones_saldo` tipo='cargar'
 * estado='revisar' de las últimas 24h. Cuando el operador ya las resolvió a
 * mano, la acción 'resolver' de crm_recargas.php las pasa a 'cancelada' para
 * que el monitor deje de contarlas. Este test reproduce esa transición (la
 * misma SELECT+UPDATE del handler) y la consulta EXACTA del monitor.
 *
 *     T_PORT=3399 php t_resolver_carga.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$fallas = 0;
function ok(bool $c, string $m): void { global $fallas; echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n"; if (!$c) { $fallas++; } }

$U = 't_resolver_1';
$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);

// La consulta del watchdog, tal cual monitor-cargas.sh (revisar + últimas 24h).
$monitorCuenta = function () use ($pdo, $U): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM acciones_saldo
          WHERE tipo='cargar' AND estado='revisar' AND usuario = ?
            AND creada_en > NOW() - INTERVAL 1 DAY");
    $st->execute([$U]);
    return (int)$st->fetchColumn();
};
// La transición EXACTA del handler 'resolver' (SELECT la primera cargar
// >= creada_en, guard 'revisar', UPDATE a 'cancelada').
$resolver = function (string $desde) use ($pdo, $U): array {
    $sa = $pdo->prepare(
        "SELECT id, estado FROM acciones_saldo
          WHERE usuario = ? COLLATE utf8mb4_unicode_ci AND tipo = 'cargar'
            AND creada_en >= ? ORDER BY creada_en ASC LIMIT 1");
    $sa->execute([$U, $desde]);
    $acc = $sa->fetch();
    if (!$acc) { return ['ok' => false, 'error' => 'sin depósito encolado']; }
    if ($acc['estado'] !== 'revisar') { return ['ok' => false, 'error' => 'no está en revisar: ' . $acc['estado']]; }
    $up = $pdo->prepare(
        "UPDATE acciones_saldo SET estado='cancelada', mensaje='cerrada a mano por test'
          WHERE id = ? AND estado='revisar'");
    $up->execute([(int)$acc['id']]);
    return ['ok' => $up->rowCount() === 1, 'accion_id' => (int)$acc['id']];
};

// ---- 1. una carga trabada dispara el conteo del monitor -----------------------
echo "1. Carga en revisar\n";
$pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, estado) VALUES (?, 'cargar', 1000, 'revisar')")->execute([$U]);
ok($monitorCuenta() === 1, 'el monitor la cuenta (mandaría el aviso)');

// ---- 2. resolver la cierra y el monitor deja de contarla ----------------------
echo "2. Marcar resuelto\n";
$r = $resolver('2000-01-01');
ok(!empty($r['ok']), 'la transición cerró la carga');
ok($monitorCuenta() === 0, 'el monitor YA NO la cuenta (el aviso para)');
$st = $pdo->prepare("SELECT estado FROM acciones_saldo WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st->execute([$U]);
ok((string)$st->fetchColumn() === 'cancelada', 'quedó en cancelada (no hecha: no se depositó nada acá)');

// ---- 3. no se puede resolver dos veces (idempotente por el guard) -------------
echo "3. Resolver de nuevo no hace nada\n";
$r = $resolver('2000-01-01');
ok(empty($r['ok']) && strpos($r['error'] ?? '', 'no está en revisar') !== false,
   'una carga ya cerrada no se vuelve a tocar');

// ---- 4. el guard: solo desde 'revisar' ----------------------------------------
echo "4. El guard 'solo revisar'\n";
$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);
$pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, estado) VALUES (?, 'cargar', 500, 'hecha')")->execute([$U]);
$r = $resolver('2000-01-01');
ok(empty($r['ok']), 'una carga hecha NO se puede "resolver" (ya está depositada)');
$st = $pdo->prepare("SELECT estado FROM acciones_saldo WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st->execute([$U]);
ok((string)$st->fetchColumn() === 'hecha', 'y sigue en hecha, intacta');

$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);
echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
