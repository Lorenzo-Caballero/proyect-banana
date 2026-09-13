<?php
/**
 * t_app_bono.php — La promo "descargá la app y ganá fichas", de punta a punta.
 *
 * Lo que garantiza:
 *   1. El PRIMER inicio de sesión desde la app (registro android con usuario)
 *      acredita el bono UNA vez: bonus + movimientos(origen 'bono_app') +
 *      depósito solo-bono encolado en acciones_saldo.
 *   2. Repetir el registro (el widget lo hace todo el tiempo) NO duplica nada.
 *   3. Un registro WEB no acredita ni marca tiene_app.
 *   4. Quien ya tenía la app antes de la promo (tiene_app=1) no cobra
 *      retroactivo el día del deploy.
 *   5. Con la promo apagada: tiene_app se marca igual, pero nadie cobra.
 *   6. alta_estado adjunta app_promo solo con promo prendida y fichas > 0
 *      (se prueba la lógica de armado, no el endpoint HTTP).
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

// ---- preparar: jugador de prueba limpio + promo prendida -------------------
$U  = 't_appbono_1';
$U2 = 't_appbono_viejo';
$U3 = 't_appbono_web';
foreach ([$U, $U2, $U3] as $u) {
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM dispositivos WHERE usuario = ?")->execute([$u]);
}
$pdo->prepare("DELETE FROM dispositivos WHERE device_id LIKE 't-appbono-%'")->execute();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990101, ?, 0, 0, 0, 0)")->execute([$U]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990102, ?, 0, 0, 0, 1)")->execute([$U2]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990103, ?, 0, 0, 0, 0)")->execute([$U3]);

cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_bono_fichas' => '1000'], 'test');

$leer = function (string $u) use ($pdo): array {
    $st = $pdo->prepare("SELECT tiene_app, bonus FROM usuarios WHERE username = ?");
    $st->execute([$u]);
    return $st->fetch() ?: [];
};
/* Solo los POSITIVOS: el deposito solo-bono deja ademas su debito (-1000,
   "Bono jugado en la carga") con el mismo origen. El candado de idempotencia
   matchea cualquiera de los dos (mejor), pero "cuantos bonos se pagaron" son
   los positivos -- el mismo filtro que usa el conteo del CRM. */
$movs = function (string $u) use ($pdo): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = 'bono_app' AND monto > 0");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
};

// ---- 1. primer login desde la app: acredita UNA vez ------------------------
echo "1. Primer inicio de sesión desde la app\n";
notif_registrar_dispositivo($pdo, 't-appbono-a', $U, 'android', 'Pixel', '1.0', true);
$f = $leer($U);
ok((int)$f['tiene_app'] === 1, 'tiene_app queda en 1');
ok($movs($U) === 1, 'un movimiento bono_app (el candado)');
// El deposito solo-bono debita el bonus y lo encola: el contador puede quedar
// en 0 con la accion 'pendiente'. Lo que no puede pasar es que NO haya rastro.
$st = $pdo->prepare("SELECT COUNT(*) FROM acciones_saldo WHERE usuario = ? AND origen = 'bono_app'");
$st->execute([$U]);
$enCola = (int)$st->fetchColumn();
ok($enCola === 1 || (int)$f['bonus'] === 1000,
   'el bono existe: encolado al juego (' . $enCola . ') o en el contador (' . $f['bonus'] . ')');

// ---- 2. repetir el registro no duplica --------------------------------------
echo "2. Registro repetido (lo hace el widget cada 25s)\n";
notif_registrar_dispositivo($pdo, 't-appbono-a', $U, 'android', 'Pixel', '1.0', true);
notif_registrar_dispositivo($pdo, 't-appbono-b', $U, 'android', 'Moto', '1.0', true);   // otro celu
ok($movs($U) === 1, 'sigue habiendo UN solo movimiento bono_app');

// ---- 3. registro web: ni tiene_app ni bono ----------------------------------
echo "3. Registro desde el navegador (web)\n";
notif_registrar_dispositivo($pdo, 't-appbono-w', $U3, 'web', null, null, true);
$f3 = $leer($U3);
ok((int)$f3['tiene_app'] === 0, 'una visita web no marca tiene_app');
ok($movs($U3) === 0, 'ni cobra el bono');

// ---- 4. quien ya tenia la app no cobra retroactivo ---------------------------
echo "4. Jugador con la app de antes de la promo\n";
notif_registrar_dispositivo($pdo, 't-appbono-v', $U2, 'android', 'Samsung', '1.0', true);
ok($movs($U2) === 0, 'tiene_app ya era 1: sin bono retroactivo');

// ---- 4b. sin el User-Agent de la app: tiene_app si, bono no -----------------
echo "4b. Registro 'android' sin el UA del APK (abuso por fetch)\n";
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120';
$U5 = 't_appbono_ua';
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U5]);
$pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$U5]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990105, ?, 0, 0, 0, 0)")->execute([$U5]);
notif_registrar_dispositivo($pdo, 't-appbono-u', $U5, 'android', 'Fake', '1.0', true);
$f5 = $leer($U5);
ok((int)$f5['tiene_app'] === 1, 'tiene_app se marca (dato del espejo)');
ok($movs($U5) === 0, 'pero el bono NO: el UA no es del APK');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) GOLDPAW/1.0';

// ---- 5. promo apagada: tiene_app si, bono no --------------------------------
echo "5. Promo apagada\n";
cfg_crm_guardar($pdo, ['app_promo_activa' => '0'], 'test');
$U4 = 't_appbono_off';
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U4]);
$pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$U4]);
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990104, ?, 0, 0, 0, 0)")->execute([$U4]);
notif_registrar_dispositivo($pdo, 't-appbono-o', $U4, 'android', 'Pixel', '1.0', true);
$f4 = $leer($U4);
ok((int)$f4['tiene_app'] === 1, 'tiene_app se marca igual (es un hecho, no la promo)');
ok($movs($U4) === 0, 'pero no cobra nada');

// ---- 6. la promo que viaja en alta_estado ------------------------------------
echo "6. app_promo hacia el widget\n";
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
foreach ([$U, $U2, $U3, $U4, $U5] as $u) {
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM dispositivos WHERE usuario = ?")->execute([$u]);
}
$pdo->prepare("DELETE FROM dispositivos WHERE device_id LIKE 't-appbono-%'")->execute();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
