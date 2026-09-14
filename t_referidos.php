<?php
/**
 * t_referidos.php — El plan de referidos, de punta a punta.
 *
 * La cadena: A comparte su link (bono.html?ref=SU_CODIGO) → B se registra
 * con el codigo (queda en altas.ref_codigo) → B acredita su PRIMERA carga →
 * A cobra el bono AUTOMATICAMENTE: fila en `referidos` (el candado),
 * movimiento, contador, push, y — desde este arreglo — el bono viaja AL
 * JUEGO por el mismo deposito solo-bono que todo bono (antes moria en
 * usuarios.bonus y en la plataforma no aparecia nunca).
 *
 * Lo que garantiza:
 *   1. El codigo y el link se generan y resuelven ida y vuelta.
 *   2. La primera carga paga: candado + movimiento + bonus + push +
 *      ref_pago para el deposito.
 *   3. rl_cargar_al_juego_auto encola el deposito solo-bono del REFERIDOR.
 *   4. Pagar dos veces por el mismo amigo: imposible (UNIQUE).
 *   5. Sin ref_codigo en el alta / monto 0 / referidor baneado: no paga.
 *   6. Auto-referirse no paga.
 *
 *     T_PORT=3399 php t_referidos.php
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
require_once __DIR__ . '/api/referidos_lib.php';
require_once __DIR__ . '/api/notificaciones_lib.php';
require_once __DIR__ . '/api/fichas_lib.php';
require_once __DIR__ . '/api/recargas_lib.php';

$fallas = 0;
function ok(bool $c, string $m): void
{
    global $fallas;
    echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n";
    if (!$c) { $fallas++; }
}

$A = 't_ref_padrino';   // el que comparte el link
$B = 't_ref_amigo';     // el que entra con el link
$limpiar = function () use ($pdo, $A, $B): void {
    foreach ([$A, $B] as $u) {
        foreach (['usuarios' => 'username', 'movimientos' => 'usuario', 'altas' => 'usuario',
                  'acciones_saldo' => 'usuario', 'notificaciones' => 'usuario'] as $t => $col) {
            try { $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$u]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare("DELETE FROM referidos WHERE referido = ? OR referidor = ?")->execute([$u, $u]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM referidos_codigos WHERE usuario = ?")->execute([$u]); } catch (Throwable $e) {}
        try {
            $pdo->prepare("DELETE m FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM conversaciones WHERE clave = ?")->execute([$u]);
        } catch (Throwable $e) {}
    }
};
$limpiar();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus) VALUES (990601, ?, 0, 0, 0)")->execute([$A]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus) VALUES (990602, ?, 0, 0, 0)")->execute([$B]);
cfg_crm_guardar($pdo, ['ref_activo' => '1', 'ref_bono_monto' => '1500'], 'test');

// ---- 1. codigo y link, ida y vuelta ------------------------------------------
echo "1. El link del padrino\n";
$cod = ref_codigo_de($pdo, $A);
ok($cod !== '' && preg_match('/^[a-z0-9]{4,16}$/', $cod) === 1, "codigo generado ($cod)");
ok(ref_usuario_de_codigo($pdo, $cod) === $A, 'el codigo resuelve de vuelta al padrino');
ok(strpos(ref_link($cod), 'bono.html?ref=' . $cod) !== false, 'el link apunta a bono.html?ref=');

// ---- 2. el amigo se registra con el codigo (lo que hace crear_cuenta.php) ----
echo "2. El amigo entra con el link\n";
$pdo->prepare("INSERT INTO altas (usuario, estado, ref_codigo) VALUES (?, 'ok', ?)")
    ->execute([$B, $cod]);
ok(true, "alta de $B con ref_codigo=$cod");

// ---- 2b. el anotador compartido (landing Y chat pasan por aca) ---------------
echo "2b. ref_anotar_en_alta (la validacion compartida)\n";
require_once __DIR__ . '/api/crm_lib.php';
$st = $pdo->prepare("SELECT id FROM altas WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st->execute([$B]);
$altaId = (int)$st->fetchColumn();
ok(ref_anotar_en_alta($pdo, $altaId, 'A#$%!', $B) === false, 'formato invalido: no anota');
ok(ref_anotar_en_alta($pdo, $altaId, $cod, $A) === false, 'auto-referencia: no anota');
cfg_crm_guardar($pdo, ['ref_activo' => '0'], 'test');
ok(ref_anotar_en_alta($pdo, $altaId, $cod, $B) === false, 'plan apagado: no anota');
cfg_crm_guardar($pdo, ['ref_activo' => '1'], 'test');
ok(ref_anotar_en_alta($pdo, $altaId, strtoupper($cod), $B) === true, 'codigo valido (aun en mayusculas): anota');

/* Nota: "un re-alta posterior sin codigo enmascara al original" NO puede
   pasar: `altas` tiene UNIQUE por usuario (uq_alta_usuario), una sola fila
   por nombre. El filtro ref_codigo IS NOT NULL de ref_pagar queda como
   defensa, no como fix de un caso real. */

// el padrino tiene chat abierto: el aviso del pago tambien le llega por ahi
$pdo->prepare("DELETE m FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?")->execute([$A]);
$pdo->prepare("DELETE FROM conversaciones WHERE clave = ?")->execute([$A]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$A, $A, 't-sess-ref']);

// ---- 3. primera carga acreditada: paga completo ------------------------------
echo "3. Primera carga del amigo: el padrino cobra\n";
$quien = null;
$monto = ref_pagar_por_primera_carga($pdo, $B, $quien);
ok($monto === 1500, "pago 1500 (dio $monto)");
ok($quien === $A, 'y dice a quien (el out-param para el deposito)');
$st = $pdo->prepare("SELECT bono FROM referidos WHERE referido = ?"); $st->execute([$B]);
ok((int)$st->fetchColumn() === 1500, 'candado en `referidos` con el monto');
$st = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = 'bono_referido'"); $st->execute([$A]);
ok((int)$st->fetchColumn() === 1, 'movimiento del bono');
$st = $pdo->prepare("SELECT bonus FROM usuarios WHERE username = ?"); $st->execute([$A]);
ok((int)$st->fetchColumn() === 1500, 'contador bonus del padrino +1500');
$st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario = ? AND origen = 'referidos'"); $st->execute([$A]);
ok((int)$st->fetchColumn() === 1, 'push "tu amigo ya juega"');
$st = $pdo->prepare("SELECT COUNT(*) FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?");
$st->execute([$A]);
ok((int)$st->fetchColumn() === 1, 'y el aviso en el CHAT del padrino (para el que no tiene la app)');

// ---- 4. el deposito AL JUEGO (rl_cargar_al_juego_auto con ref_pago) ----------
echo "4. El bono entra AL JUEGO\n";
// En el flujo real la acreditacion ya le sumo los coins al amigo ANTES de
// este deposito (rl_acreditar); aca se repone ese estado a mano.
$pdo->prepare("UPDATE usuarios SET coins = 3000 WHERE username = ?")->execute([$B]);
$recarga = ['usuario' => $B, 'coins' => 3000, 'referencia' => 't-ref', 'id' => 0,
            'bono' => 0, 'ref_pago' => ['usuario' => $A, 'monto' => $monto]];
rl_cargar_al_juego_auto($pdo, $recarga);
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM acciones_saldo WHERE usuario = ? AND origen = 'referidos' AND bono_debitado = 1500");
$st->execute([$A]);
ok((int)$st->fetchColumn() === 1, 'deposito solo-bono del PADRINO encolado (1500 al juego)');
$st = $pdo->prepare("SELECT bonus FROM usuarios WHERE username = ?"); $st->execute([$A]);
ok((int)$st->fetchColumn() === 0, 'el contador quedo debitado (no se juega dos veces)');
$st = $pdo->prepare("SELECT COUNT(*) FROM acciones_saldo WHERE usuario = ?"); $st->execute([$B]);
ok((int)$st->fetchColumn() === 1, 'y la carga del AMIGO tambien se encolo (3000)');

// ---- 5. no se paga dos veces --------------------------------------------------
echo "5. Segunda carga del amigo\n";
$quien2 = null;
ok(ref_pagar_por_primera_carga($pdo, $B, $quien2) === 0 && $quien2 === null,
   'el candado UNIQUE no deja pagar de nuevo');

// ---- 6. los que NO pagan ------------------------------------------------------
echo "6. Casos que no pagan\n";
$C = 't_ref_solo';
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$C]);
$pdo->prepare("DELETE FROM altas WHERE usuario = ?")->execute([$C]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus) VALUES (990603, ?, 0, 0, 0)")->execute([$C]);
$pdo->prepare("INSERT INTO altas (usuario, estado) VALUES (?, 'ok')")->execute([$C]);
ok(ref_pagar_por_primera_carga($pdo, $C) === 0, 'alta sin ref_codigo: no paga');

$pdo->prepare("UPDATE altas SET ref_codigo = ? WHERE usuario = ?")->execute([$cod, $C]);
cfg_crm_guardar($pdo, ['ref_bono_monto' => '0'], 'test');
ok(ref_pagar_por_primera_carga($pdo, $C) === 0, 'monto en 0 (plan cerrado de facto): no paga');
cfg_crm_guardar($pdo, ['ref_bono_monto' => '1500'], 'test');

$pdo->prepare("UPDATE usuarios SET is_banned = 1 WHERE username = ?")->execute([$A]);
ok(ref_pagar_por_primera_carga($pdo, $C) === 0, 'referidor baneado: no cobra');
$pdo->prepare("UPDATE usuarios SET is_banned = 0 WHERE username = ?")->execute([$A]);

// auto-referido: un alta del propio padrino con su propio codigo
$pdo->prepare("INSERT INTO altas (usuario, estado, ref_codigo) VALUES (?, 'ok', ?)")
    ->execute([$A, $cod]);
ok(ref_pagar_por_primera_carga($pdo, $A) === 0, 'auto-referirse: no paga');

// ---- limpiar ------------------------------------------------------------------
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$C]);
$pdo->prepare("DELETE FROM altas WHERE usuario = ?")->execute([$C]);
$pdo->prepare("DELETE FROM referidos WHERE referido = ?")->execute([$C]);
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
