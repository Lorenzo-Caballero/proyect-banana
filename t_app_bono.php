<?php
/**
 * t_app_bono.php — La promo "descargá la app y ganá fichas", de punta a punta.
 *
 * DESDE EL 16/09/2026 EL BONO ES "DESPUES DE LA PRIMERA CARGA" (pedido de
 * Nahuel): instalar la app ya no paga por sí solo. La condición se le cuenta
 * al jugador SOLO una vez instalada la app (una notificación), nunca en la
 * promo del navegador.
 *
 * Lo que garantiza:
 *   1. El primer inicio de sesión desde la app SIN carga previa NO acredita:
 *      deja el marcador (movimientos origen 'bono_app', monto 0) y el aviso
 *      "se acreditan con tu primera carga".
 *   2. Cuando entra su primera plata, notif_app_bono_liberar() paga UNA vez:
 *      bonus + movimientos(monto>0) + depósito solo-bono en acciones_saldo.
 *      Repetir la liberación no duplica.
 *   3. El que instala DESPUES de haber cargado cobra en el acto (como antes).
 *   4. Repetir el registro del dispositivo no duplica ni marcador ni bono.
 *   5. Un registro WEB no acredita ni marca tiene_app; el que cargó pero
 *      nunca instaló no cobra nada al liberar (sin marcador no hay promesa).
 *   6. Quien ya tenía la app antes de la promo (tiene_app=1) no cobra
 *      retroactivo el día del deploy.
 *   7. Sin el User-Agent del APK: tiene_app se marca, pero ni bono ni marcador.
 *   8. Con la promo apagada: tiene_app se marca igual, pero nadie cobra ni
 *      queda marcador.
 *   9. app_promo viaja al widget solo con promo prendida y fichas > 0.
 *
 *     T_PORT=3399 php t_app_bono.php
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
require_once __DIR__ . '/api/notificaciones_lib.php';

// El bono exige que la request venga del WebView del APK, que se identifica
// con el sufijo GOLDPAW en el User-Agent (un fetch de navegador no puede
// falsificar ese header). Los tests simulan ese origen.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) GOLDPAW/1.0';

$fallas = 0;
function ok(bool $cond, string $msg): void
{
    global $fallas;
    echo ($cond ? '  OK   ' : '  FALLA ') . $msg . "\n";
    if (!$cond) { $fallas++; }
}

// ---- preparar: jugadores de prueba limpios + promo prendida -----------------
$U  = 't_appbono_1';      // instala ANTES de cargar (el caso nuevo)
$U2 = 't_appbono_viejo';  // tenia la app de antes de la promo
$U3 = 't_appbono_web';    // solo navegador
$U6 = 't_appbono_carga';  // instala DESPUES de cargar
$U7 = 't_appbono_sinapp'; // cargo pero nunca instalo
$todos = [$U, $U2, $U3, $U6, $U7, 't_appbono_ua', 't_appbono_off'];
$limpiar = function () use ($pdo, $todos): void {
    foreach ($todos as $u) {
        $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM dispositivos WHERE usuario = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM notificaciones WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM dispositivos WHERE device_id LIKE 't-appbono-%'")->execute();
};
$limpiar();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990101, ?, 0, 0, 0, 0)")->execute([$U]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990102, ?, 0, 0, 0, 1)")->execute([$U2]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990103, ?, 0, 0, 0, 0)")->execute([$U3]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990106, ?, 0, 0, 0, 0)")->execute([$U6]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990107, ?, 0, 0, 0, 0)")->execute([$U7]);

cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_bono_fichas' => '1000'], 'test');

$leer = function (string $u) use ($pdo): array {
    $st = $pdo->prepare("SELECT tiene_app, bonus FROM usuarios WHERE username = ?");
    $st->execute([$u]);
    return $st->fetch() ?: [];
};
/* Solo los POSITIVOS = bonos PAGADOS: el deposito solo-bono deja ademas su
   debito (-1000, "Bono jugado en la carga") con el mismo origen, y desde el
   16/09 existe tambien el MARCADOR (monto 0, la promesa de la instalacion sin
   carga). "Cuantos bonos se pagaron" son los positivos -- el mismo filtro que
   usa el conteo del CRM. */
$movs = function (string $u) use ($pdo): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = 'bono_app' AND monto > 0");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
};
$marcas = function (string $u) use ($pdo): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = 'bono_app' AND monto = 0");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
};
/** Le entra plata al juego por el camino A (no toca `recargas`). */
$cargar = function (string $u) use ($pdo): void {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
         VALUES (?, 'saldo', 500, 'del test', 'peticion')"
    )->execute([$u]);
};
/** El bono existe de verdad: encolado al juego o en el contador. */
$bonoExiste = function (string $u) use ($pdo, $leer): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM acciones_saldo WHERE usuario = ? AND origen = 'bono_app'");
    $st->execute([$u]);
    $enCola = (int)$st->fetchColumn();
    $f = $leer($u);
    return $enCola === 1 || (int)$f['bonus'] === 1000;
};

// ---- 1. instala SIN carga previa: no cobra, queda la promesa ---------------
echo "1. Primer inicio de sesión desde la app, sin ninguna carga\n";
notif_registrar_dispositivo($pdo, 't-appbono-a', $U, 'android', 'Pixel', '1.0', true);
$f = $leer($U);
ok((int)$f['tiene_app'] === 1, 'tiene_app queda en 1');
ok($movs($U) === 0, 'NO cobra el bono todavia (no tiene primera carga)');
ok($marcas($U) === 1, 'queda el marcador (bono_app, monto 0)');
// La frase es del dueño (18/09/2026): "tu bono ya está activo, te lo
// acreditamos en tu próxima carga". El aviso tiene que decir eso.
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM notificaciones WHERE usuario = ? AND cuerpo LIKE '%próxima carga%'");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 1, 'y el aviso en la app que explica la condicion');

// ---- 2. repetir el registro no duplica la promesa ---------------------------
echo "2. Registro repetido (lo hace el widget cada 25s)\n";
notif_registrar_dispositivo($pdo, 't-appbono-a', $U, 'android', 'Pixel', '1.0', true);
notif_registrar_dispositivo($pdo, 't-appbono-b', $U, 'android', 'Moto', '1.0', true);   // otro celu
ok($marcas($U) === 1 && $movs($U) === 0, 'sigue habiendo UN solo marcador y ningun pago');

// ---- 3. llega su primera carga: se libera UNA vez ---------------------------
echo "3. Su primera carga libera el bono\n";
$cargar($U);
notif_app_bono_liberar($pdo, $U);
ok($movs($U) === 1, 'UN movimiento bono_app pagado (monto > 0)');
ok($bonoExiste($U), 'el bono existe: encolado al juego o en el contador');
notif_app_bono_liberar($pdo, $U);   // otra carga del mismo jugador
ok($movs($U) === 1, 'liberar de nuevo NO duplica');

// ---- 4. instala DESPUES de haber cargado: cobra en el acto ------------------
echo "4. Instala con una carga ya hecha\n";
$cargar($U6);
notif_registrar_dispositivo($pdo, 't-appbono-c', $U6, 'android', 'Pixel', '1.0', true);
ok($movs($U6) === 1, 'cobra en el acto (ya tenia su primera carga)');
ok($marcas($U6) === 0, 'sin marcador: no habia nada que prometer');

// ---- 5. registro web / cargo sin instalar -----------------------------------
echo "5. El navegador no participa\n";
notif_registrar_dispositivo($pdo, 't-appbono-w', $U3, 'web', null, null, true);
$f3 = $leer($U3);
ok((int)$f3['tiene_app'] === 0, 'una visita web no marca tiene_app');
ok($movs($U3) === 0 && $marcas($U3) === 0, 'ni cobra ni queda promesa');
$cargar($U7);
notif_app_bono_liberar($pdo, $U7);
ok($movs($U7) === 0, 'el que cargo pero nunca instalo no cobra al liberar');

// ---- 6. quien ya tenia la app no cobra retroactivo ---------------------------
echo "6. Jugador con la app de antes de la promo\n";
notif_registrar_dispositivo($pdo, 't-appbono-v', $U2, 'android', 'Samsung', '1.0', true);
ok($movs($U2) === 0 && $marcas($U2) === 0, 'tiene_app ya era 1: sin bono ni promesa retroactiva');
$cargar($U2);
notif_app_bono_liberar($pdo, $U2);
ok($movs($U2) === 0, 'y una carga suya tampoco le paga nada');

// ---- 7. sin el User-Agent de la app: tiene_app si, bono no ------------------
echo "7. Registro 'android' sin el UA del APK (abuso por fetch)\n";
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120';
$U5 = 't_appbono_ua';
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990105, ?, 0, 0, 0, 0)")->execute([$U5]);
notif_registrar_dispositivo($pdo, 't-appbono-u', $U5, 'android', 'Fake', '1.0', true);
$f5 = $leer($U5);
ok((int)$f5['tiene_app'] === 1, 'tiene_app se marca (dato del espejo)');
ok($movs($U5) === 0 && $marcas($U5) === 0, 'pero ni bono ni marcador: el UA no es del APK');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) GOLDPAW/1.0';

// ---- 8. promo apagada: tiene_app si, bono no --------------------------------
echo "8. Promo apagada\n";
cfg_crm_guardar($pdo, ['app_promo_activa' => '0'], 'test');
$U4 = 't_appbono_off';
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990104, ?, 0, 0, 0, 0)")->execute([$U4]);
notif_registrar_dispositivo($pdo, 't-appbono-o', $U4, 'android', 'Pixel', '1.0', true);
$f4 = $leer($U4);
ok((int)$f4['tiene_app'] === 1, 'tiene_app se marca igual (es un hecho, no la promo)');
ok($movs($U4) === 0 && $marcas($U4) === 0, 'pero no cobra ni queda promesa');

// ---- 9. la promo que viaja en el registro del dispositivo -------------------
echo "9. app_promo hacia el widget\n";
cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_bono_fichas' => '1000'], 'test');
$armar = function () use ($pdo): ?array {
    if (!cfg_crm_activo($pdo, 'app_promo_activa')) { return null; }
    $fichas = max(0, (int)(cfg_crm($pdo, 'app_bono_fichas') ?? 0));
    if ($fichas <= 0) { return null; }
    return ['fichas' => $fichas, 'url' => trim((string)(cfg_crm($pdo, 'app_url') ?? ''))];
};
$p = $armar();
ok($p !== null && $p['fichas'] === 1000, 'prendida: viaja con 1000 fichas');
cfg_crm_guardar($pdo, ['app_bono_fichas' => '0'], 'test');
ok($armar() === null, 'con 0 fichas no viaja (un cartel de $0 es peor que ninguno)');
cfg_crm_guardar($pdo, ['app_bono_fichas' => '1000', 'app_promo_activa' => '0'], 'test');
ok($armar() === null, 'apagada no viaja');

// ---- limpiar -----------------------------------------------------------------
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
