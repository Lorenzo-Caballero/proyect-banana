<?php
/**
 * t_invita_app.php — al acreditar una carga va la confirmación y, si
 *                    corresponde, la invitación a instalar la app.
 *
 * ESTE ARCHIVO SE DIO VUELTA DOS VECES EN UN DÍA, y la segunda es la buena.
 *
 * Por la mañana del 15/09/2026 se SACÓ la invitación por chat, razonando que
 * el CARTEL del widget dice lo mismo en el mismo segundo. El error de ese
 * razonamiento: el cartel sólo existe si el jugador está mirando. La carga
 * acredita MINUTOS después de que transfirió (el mail del banco es
 * asincrónico) y el widget no dibuja nada con la pestaña cerrada — o sea que
 * en el caso normal el cartel se pierde y no queda NADA.
 *
 * Por la tarde volvió, con dos diferencias respecto de la versión original:
 *   - sale en TODAS las cargas y no sólo en la primera (lo pidió Nahuel);
 *   - pero UNA VEZ POR DÍA, que es el mismo freno que tiene el cartel. Quien
 *     carga cinco veces en un día no necesita cinco invitaciones idénticas.
 *
 * Lo que garantiza:
 *   - la confirmación trae las fichas Y el bono, que es la buena noticia;
 *   - la invitación sale con su monto y su link completo;
 *   - el freno diario aguanta la segunda carga del mismo día;
 *   - pasado el día, vuelve a salir — no es "una sola vez en la vida";
 *   - al que ya tiene la app no se le promete nada (el regalo ya lo cobró);
 *   - promo apagada o sin `app_url`: sólo la confirmación, nunca un link
 *     inventado.
 *
 *     T_PORT=3399 php t_invita_app.php
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
require_once __DIR__ . '/api/crm_lib.php';

$fallas = 0;
function ok(bool $c, string $m): void
{
    global $fallas;
    echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n";
    if (!$c) { $fallas++; }
}

$U = 't_invita_app';
$limpiar = function () use ($pdo, $U): void {
    foreach (['usuarios' => 'username', 'movimientos' => 'usuario',
              'recargas' => 'usuario', 'acciones_saldo' => 'usuario'] as $t => $col) {
        $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$U]);
    }
    $pdo->prepare("DELETE m FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?")->execute([$U]);
    $pdo->prepare("DELETE FROM conversaciones WHERE clave = ?")->execute([$U]);
};
$limpiar();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app) VALUES (990401, ?, 0, 0, 0, 0)")->execute([$U]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$U, $U, 't-sess-inv']);
$cfgViejo = [
    'app_promo_activa' => cfg_crm($pdo, 'app_promo_activa'),
    'app_bono_fichas'  => cfg_crm($pdo, 'app_bono_fichas'),
    'app_url'          => cfg_crm($pdo, 'app_url'),
];
cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_bono_fichas' => '1000',
                       'app_url' => 'ganamoscrm.online/descargar.html'], 'test');

$mensajes = function () use ($pdo, $U): array {
    $st = $pdo->prepare(
        "SELECT m.texto FROM mensajes m
          JOIN conversaciones c ON c.id = m.conversacion_id
         WHERE c.clave = ? ORDER BY m.id");
    $st->execute([$U]);
    return array_column($st->fetchAll(), 'texto');
};

/* Envejece la marca `app_invite` para que el freno diario no tape el caso
   siguiente. Es lo que pasaria de verdad al dia siguiente. */
$pasoUnDia = function () use ($pdo, $U) {
    $pdo->prepare(
        "UPDATE mensajes m JOIN conversaciones c ON c.id = m.conversacion_id
            SET m.creado_en = m.creado_en - INTERVAL 2 DAY
          WHERE c.clave = ?"
    )->execute([$U]);
};

echo "\n=== 1. La carga acreditada: confirmacion + invitacion ===\n";
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 5000, 'bono' => 2500, 'es_primera' => 1]);
$m = $mensajes();
ok(count($m) === 2, 'salen los DOS mensajes (' . count($m) . ')');
ok(isset($m[0]) && strpos($m[0], '5.000') !== false && strpos($m[0], '2.500') !== false,
   'la confirmacion trae las fichas y el bono, que es la buena noticia');
ok(isset($m[1]) && strpos($m[1], '1.000') !== false
   && strpos($m[1], 'https://ganamoscrm.online/descargar.html') !== false,
   'la invitacion trae el monto y el link con https (se configuro sin esquema)');

echo "\n=== 2. El freno diario ===\n";
/* Sin esto, quien carga cinco veces en un dia se lleva cinco invitaciones
   identicas -- que es el spam que se vino sacando toda la semana. */
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 3000, 'bono' => 0, 'es_primera' => 0]);
$m = $mensajes();
ok(count($m) === 3, 'la segunda carga del MISMO dia: solo la confirmacion (' . count($m) . ')');
ok(isset($m[2]) && strpos($m[2], 'descargar.html') === false,
   'y esa confirmacion no lleva link');

echo "\n=== 3. Pasado el dia, vuelve a invitar ===\n";
/* Y ACA ESTA EL CAMBIO QUE PIDIO NAHUEL: antes la invitacion iba con el gate
   `es_primera`, o sea una sola vez en la vida del jugador. Ahora sale siempre
   que corresponda, con el freno del dia de por medio. Notar que `es_primera`
   viene en 0: esta NO es su primera carga. */
$pasoUnDia();
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 2000, 'bono' => 0, 'es_primera' => 0]);
$m = $mensajes();
ok(count($m) === 5, 'vuelven a salir los dos (' . count($m) . ')');
ok(isset($m[4]) && strpos($m[4], 'descargar.html') !== false,
   'y el segundo es la invitacion, aunque NO sea su primera carga');

echo "\n=== 4. A quien ya la tiene no se le promete nada ===\n";
$pasoUnDia();
$pdo->prepare("UPDATE usuarios SET tiene_app = 1 WHERE username = ?")->execute([$U]);
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 0]);
ok(count($mensajes()) === 6, 'con la app instalada: solo la confirmacion');

echo "\n=== 5. Apagada o sin URL: nunca un link inventado ===\n";
$pdo->prepare("UPDATE usuarios SET tiene_app = 0 WHERE username = ?")->execute([$U]);
$pasoUnDia();
cfg_crm_guardar($pdo, ['app_promo_activa' => '0'], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 0]);
ok(count($mensajes()) === 7, 'promo apagada: solo la confirmacion');

$pasoUnDia();
cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_url' => ''], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 0]);
ok(count($mensajes()) === 8, 'sin app_url: solo la confirmacion, nunca un link inventado');

// Dejar la config como estaba (otra suite puede depender de ella).
cfg_crm_guardar($pdo, array_map(fn($v) => (string)($v ?? ''), $cfgViejo), 'test');
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
