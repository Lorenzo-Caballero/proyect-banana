<?php
/**
 * t_multicuenta_bono.php — Los bonos son POR PERSONA, y al multicuenta se le avisa.
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026, sobre la ficha de holajorge443 con «3
 * cuentas parecen ser la misma persona»): al detectar que un jugador se creó
 * varias cuentas, (1) avisarle —"nuestro sistema de bonificación es
 * anti-multicuenta, evitá hacer eso"— y (2) no volver a pagarle ningún bono
 * que la persona ya cobró con otra de sus cuentas.
 *
 * Lo que garantiza:
 *   1. vin_misma_persona() es identidad BANCARIA: la misma cuenta de banco
 *      une; el mismo celular NO (se presta) ni la IP.
 *   2. La bienvenida no se paga dos veces a la misma persona: si una cuenta
 *      vinculada por banco ya la cobró, la nueva devuelve 0.
 *   3. Un jugador sin vínculos cobra normal (el gate no bloquea inocentes).
 *   4. El bono de la app tampoco: ni al liberar el marcador con vínculo
 *      bancario, ni con el celular compartido (la defensa que ya existía).
 *      Compartir celular NO quema la bienvenida (bancaria pura).
 *   5. El aviso al jugador sale UNA vez por cuenta (push origen
 *      'multicuenta'), no sale para quien no tiene vínculos, y se dispara
 *      solo en el camino real (rl_aprender_huella).
 *
 *     T_PORT=3399 php t_multicuenta_bono.php
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
require_once __DIR__ . '/api/recargas_lib.php';       // trae fichas_lib -> vinculos_lib
require_once __DIR__ . '/api/notificaciones_lib.php';

$fallas = 0;
function ok(bool $cond, string $msg): void
{
    global $fallas;
    echo ($cond ? '  OK   ' : '  FALLA ') . $msg . "\n";
    if (!$cond) { $fallas++; }
}

// A = la cuenta vieja que YA cobró los dos bonos. B = su segunda cuenta
// (mismo banco). C = comparte CELULAR con A (un hermano, no la misma
// persona bancaria). D = jugador limpio, sin vínculos. E = tercera cuenta
// del dueño de A, descubierta por el camino real (rl_aprender_huella).
$A = 't_multi_a'; $B = 't_multi_b'; $C = 't_multi_c'; $D = 't_multi_d'; $E = 't_multi_e';
$CUIT = '20999888777';

$limpiar = function () use ($pdo, $A, $B, $C, $D, $E): void {
    foreach ([$A, $B, $C, $D, $E] as $u) {
        foreach (['usuarios' => 'username', 'movimientos' => 'usuario', 'altas' => 'usuario',
                  'huellas_pagador' => 'usuario', 'notificaciones' => 'usuario',
                  'acciones_saldo' => 'usuario', 'recargas' => 'usuario'] as $t => $col) {
            try { $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$u]); }
            catch (Throwable $e) {}
        }
        try { $pdo->prepare("DELETE FROM dispositivos_usuarios WHERE usuario = ?")->execute([$u]); }
        catch (Throwable $e) {}
    }
};
$limpiar();

$id = 990200;
foreach ([$A, $B, $C, $D, $E] as $u) {
    $pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (?, ?, 0, 0, 0, 0)")
        ->execute([++$id, $u]);
}
foreach ([$B, $C, $D] as $u) {
    $pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'chatbot')")->execute([$u]);
}
// A ya cobró los dos bonos (los candados que el grupo tiene que ver).
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen) VALUES (?, 'bono', 500, 'del test', 'bono_bienvenida')")->execute([$A]);
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen) VALUES (?, 'bono', 1000, 'del test', 'bono_app')")->execute([$A]);
// A y B pagan desde la misma cuenta bancaria; A y C comparten celular.
$pdo->prepare("INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre) VALUES (?, ?, '', 'TITULAR TEST')")->execute([$A, $CUIT]);
$pdo->prepare("INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre) VALUES (?, ?, '', 'TITULAR TEST')")->execute([$B, $CUIT]);
$pdo->prepare("INSERT INTO dispositivos_usuarios (device_id, usuario) VALUES ('t-multi-dev', ?)")->execute([$A]);
$pdo->prepare("INSERT INTO dispositivos_usuarios (device_id, usuario) VALUES ('t-multi-dev', ?)")->execute([$C]);

cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '50',
                       'app_promo_activa' => '1', 'app_bono_fichas' => '1000'], 'test');

$movs = function (string $u, string $origen) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = ? AND monto > 0");
    $st->execute([$u, $origen]);
    return (int)$st->fetchColumn();
};
$avisos = function (string $u) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario = ? AND origen = 'multicuenta'");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
};

// ---- 1. la definición de "misma persona" es bancaria ------------------------
echo "1. vin_misma_persona: banco sí, celular no\n";
$g = vin_misma_persona($pdo, $B);
ok(in_array($A, $g, true), 'B y A: misma cuenta bancaria = misma persona');
$g = vin_misma_persona($pdo, $C);
ok(!in_array($A, $g, true), 'C y A: compartir celular NO los hace la misma persona');

// ---- 2. la bienvenida no se paga dos veces a la misma persona ---------------
echo "2. Bono de bienvenida\n";
$bono = rl_bono_bienvenida_aplicar($pdo, $B, 1000);
ok($bono === 0, 'B no cobra: la persona ya lo cobró con A (devolvió ' . $bono . ')');
ok($movs($B, 'bono_bienvenida') === 0, 'y no quedó ningún movimiento suyo');
$bono = rl_bono_bienvenida_aplicar($pdo, $D, 1000);
ok($bono === 500, 'D (sin vínculos) cobra normal: ' . $bono);
$bono = rl_bono_bienvenida_aplicar($pdo, $C, 1000);
ok($bono === 500, 'C (solo celular compartido) también cobra la bienvenida: ' . $bono);

// ---- 3. el bono de la app tampoco -------------------------------------------
echo "3. Bono de la app (el marcador no es un cheque al portador)\n";
// B instaló antes de cargar: tiene el marcador. Su primera carga NO libera.
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen) VALUES (?, 'bono', 0, 'marcador del test', 'bono_app')")->execute([$B]);
notif_app_bono_liberar($pdo, $B);
ok($movs($B, 'bono_app') === 0, 'B: vínculo bancario con A (que ya cobró) => no se libera');
// C: mismo celular que A. La defensa por dispositivo del bono de la app sigue.
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen) VALUES (?, 'bono', 0, 'marcador del test', 'bono_app')")->execute([$C]);
notif_app_bono_liberar($pdo, $C);
ok($movs($C, 'bono_app') === 0, 'C: mismo celular que A => el bono de la APP no se libera');
// D limpio: su marcador se libera normal.
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen) VALUES (?, 'bono', 0, 'marcador del test', 'bono_app')")->execute([$D]);
notif_app_bono_liberar($pdo, $D);
ok($movs($D, 'bono_app') === 1, 'D (sin vínculos) libera su bono normal');

// ---- 4. el aviso al jugador --------------------------------------------------
echo "4. El aviso multicuenta\n";
vin_avisar_multicuenta($pdo, $B);
ok($avisos($B) === 1, 'a B se le avisa (push origen multicuenta)');
vin_avisar_multicuenta($pdo, $B);
ok($avisos($B) === 1, 'y UNA sola vez: repetir no duplica');
vin_avisar_multicuenta($pdo, $D);
ok($avisos($D) === 0, 'a D (sin vínculos) no se le dice nada');
vin_avisar_multicuenta($pdo, $C);
ok($avisos($C) === 0, 'a C (solo celular) tampoco: no es una acusación para familias');

// ---- 5. el camino real: la acreditación aprende la huella y avisa -----------
echo "5. El camino real: huella en la transacción, aviso post-commit\n";
// La huella se aprende ADENTRO de la transacción de acreditar; el aviso NO
// puede salir ahí (tg_evento es un curl de hasta 8s sosteniendo los locks).
// Sale en rl_notificar_acreditada, que todos los caminos llaman post-commit.
rl_aprender_huella($pdo, $E, ['cuit' => $CUIT, 'cbu_origen' => '', 'remitente' => 'TITULAR TEST']);
ok($avisos($E) === 0, 'aprender la huella sola NO avisa (corre dentro de la transacción)');
rl_notificar_acreditada($pdo, ['usuario' => $E, 'coins' => 100, 'referencia' => 'test', 'id' => 0]);
ok($avisos($E) === 1, 'el aviso sale con la notificación post-commit de la carga');

// ---- limpiar -----------------------------------------------------------------
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
