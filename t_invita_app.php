<?php
/**
 * t_invita_app.php — La invitación a la app en el chat, tras la PRIMERA carga.
 *
 * Después de "¡Listo! Ya te acredité tus N fichas 🎉 ..." va un segundo globo:
 * "🎁 ... instalá nuestra app y te acredito otras 1.000 fichas ... <app_url>".
 *
 * Lo que garantiza:
 *   - sale SOLO en la primera carga acreditada (es_primera);
 *   - no se le promete a quien ya tiene la app (el regalo ya lo cobró);
 *   - promo apagada o sin app_url => no sale (nunca se inventa un link);
 *   - el link viaja completo con https:// aunque el operador lo pegó sin esquema.
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

rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 5000, 'bono' => 2500, 'es_primera' => 1]);
$m = $mensajes();
ok(count($m) === 2, 'primera carga: confirmacion + invitacion (' . count($m) . ' mensajes)');
ok(isset($m[0]) && strpos($m[0], '5.000') !== false && strpos($m[0], '2.500') !== false,
   'la confirmacion trae fichas y bono');
ok(isset($m[1]) && strpos($m[1], '1.000') !== false
   && strpos($m[1], 'https://ganamoscrm.online/descargar.html') !== false,
   'la invitacion trae el monto y el link con https (aunque se configuro sin esquema)');

rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 3000, 'bono' => 0, 'es_primera' => 0]);
ok(count($mensajes()) === 3, 'segunda carga: sin invitacion');

$pdo->prepare("UPDATE usuarios SET tiene_app = 1 WHERE username = ?")->execute([$U]);
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 4, 'con la app instalada no se le promete de nuevo');

$pdo->prepare("UPDATE usuarios SET tiene_app = 0 WHERE username = ?")->execute([$U]);
cfg_crm_guardar($pdo, ['app_promo_activa' => '0'], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 5, 'promo apagada: sin invitacion');

cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_url' => ''], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 6, 'sin app_url no se inventa un link');

// Dejar la config como estaba (otra suite puede depender de ella).
cfg_crm_guardar($pdo, array_map(fn($v) => (string)($v ?? ''), $cfgViejo), 'test');
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
