<?php
/**
 * t_doble_camino.php — Una transferencia reservada por el camino A no puede
 *                      pagar TAMBIÉN por el camino B.
 *
 * EL AGUJERO (auditoría del 16/09/2026): los dos caminos MANUALES de
 * acreditación (rl_asignar_manual, rl_acreditar_directo) chequean hace rato
 * que el pago no esté reclamado por una solicitud del botón «Depósitos»
 * (peticiones_carga). El matcher AUTOMÁTICO no lo chequeaba — y
 * rl_declarar_pago() lo hace correr sobre los pagos en 'revision', que es
 * justo el estado de un pago reclamado. Secuencia real: el jugador pide por
 * el botón del juego (camino A, reclama el pago), se impacienta, abre el
 * chat, crea una recarga y declara "ya transferí" → el rematch agarraba el
 * pago reservado y acreditaba coins → y el worker después aprobaba la
 * solicitud en el panel (balance). UNA transferencia, DOS acreditaciones.
 *
 * Lo que garantiza:
 *   1. Con el pago RECLAMADO por una petición viva ('esperando'/'revision'),
 *      rl_matchear_y_acreditar devuelve 'revision': no acredita, la recarga
 *      del chat sigue pendiente y el pago no se consume.
 *   2. Con la petición cerrada ('aprobada'/'cerrada'), el mismo pago SÍ
 *      matchea normal — la guarda no bloquea de más.
 *
 *     T_PORT=3399 php t_doble_camino.php
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

$fallas = 0;
function ok(bool $cond, string $msg): void
{
    global $fallas;
    echo ($cond ? '  OK   ' : '  FALLA ') . $msg . "\n";
    if (!$cond) { $fallas++; }
}

$U   = 't_dcamino_1';
$PG  = 't-dcamino-pago-1';
$RID = 990900001;

$limpiar = function () use ($pdo, $U, $PG, $RID): void {
    foreach (['recargas', 'movimientos', 'acciones_saldo'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$U]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico = ?")->execute([$PG]);
    try { $pdo->prepare("DELETE FROM peticiones_carga WHERE request_id = ?")->execute([$RID]); }
    catch (Throwable $e) {}
};
$limpiar();

$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus) VALUES (990901, ?, 0, 0, 0)")
    ->execute([$U]);

// La recarga del chat, pendiente, $500 — la candidata que el matcher querría.
rl_crear_recarga($pdo, $U, 500, 'Titular ' . $U, true);

// La solicitud del camino A, con el pago YA reclamado.
$pdo->prepare(
    "INSERT INTO peticiones_carga (request_id, username, monto, estado, pago_id_unico)
     VALUES (?, ?, 500, 'esperando', ?)"
)->execute([$RID, $U, $PG]);

// El pago del banco, en revision (como queda uno que el camino B no pudo casar
// y el camino A reclamó).
$pdo->prepare(
    "INSERT INTO pagos (id_unico, monto, remitente, estado, capturado_en)
     VALUES (?, 500, ?, 'revision', NOW())"
)->execute([$PG, 'Titular ' . $U]);

// ---- 1. reclamado por una petición viva: NO acredita -------------------------
echo "1. Pago reclamado por el camino A\n";
$res = rl_matchear_y_acreditar($pdo, $PG, 500.0);
ok(($res['resultado'] ?? '') === 'revision',
   "el matcher lo deja en revision (dio '" . ($res['resultado'] ?? '?') . "')");
$st = $pdo->prepare("SELECT estado FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st->execute([$U]);
ok((string)$st->fetchColumn() === 'pendiente', 'la recarga del chat sigue pendiente');
$st = $pdo->prepare("SELECT estado, recarga_id FROM pagos WHERE id_unico = ?");
$st->execute([$PG]);
$p = $st->fetch();
ok((string)$p['estado'] !== 'usado' && empty($p['recarga_id']),
   'el pago NO se consumió (queda para el camino A)');
$st = $pdo->prepare("SELECT coins FROM usuarios WHERE username = ?");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 0, 'y no se acreditó ni un coin');

// ---- 2. petición cerrada: el mismo pago matchea normal -----------------------
echo "2. La petición ya se cerró: la guarda no bloquea de más\n";
$pdo->prepare("UPDATE peticiones_carga SET estado = 'cerrada' WHERE request_id = ?")->execute([$RID]);
$res = rl_matchear_y_acreditar($pdo, $PG, 500.0);
ok(($res['resultado'] ?? '') === 'acreditada',
   "ahora sí acredita (dio '" . ($res['resultado'] ?? '?') . "')");
// Los coins acreditados se debitan al toque para mandarlos AL JUEGO
// (rl_cargar_al_juego_auto encola en acciones_saldo), así que el contador
// puede quedar en 0: la prueba de que la plata entró es el pago consumido
// y la recarga acreditada.
$st = $pdo->prepare("SELECT estado FROM pagos WHERE id_unico = ?");
$st->execute([$PG]);
ok((string)$st->fetchColumn() === 'usado', 'y el pago quedó consumido');

// ---- limpiar -----------------------------------------------------------------
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
