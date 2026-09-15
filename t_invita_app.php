<?php
/**
 * t_invita_app.php — al acreditar una carga va UNA confirmación, y nada más.
 *
 * ACÁ HABÍA UNA SEGUNDA LÍNEA invitando a instalar la app, y se sacó el
 * 15/09/2026. El widget ahora muestra el CARTEL de la app en ese mismo momento
 * (al llegar la notificación de tipo 'recarga'), así que mandar además el
 * mensaje era decir lo mismo dos veces en el mismo segundo. El cartel gana:
 * tiene el botón de descarga ahí mismo y sale en todas las cargas, no solo en
 * la primera.
 *
 * Este test pasó a blindar lo contrario de lo que blindaba: que la
 * acreditación mande UN solo mensaje. Si alguien vuelve a agregar la
 * invitación por chat sin sacar el cartel, esto lo agarra -- el spam duplicado
 * es exactamente lo que se estuvo limpiando toda la semana.
 *
 * Lo que sigue garantizando:
 *   - la confirmación trae las fichas Y el bono, que es la buena noticia;
 *   - una carga acredita UN mensaje, sea la primera o la décima;
 *   - la config de la app (prendida, apagada, con o sin URL) ya no cambia
 *     nada de lo que se manda por chat.
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

/* La promo esta PRENDIDA y con URL: antes eso bastaba para que saliera la
   segunda linea. Ahora no sale ninguna, la muestre o no el widget. */
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 5000, 'bono' => 2500, 'es_primera' => 1]);
$m = $mensajes();
ok(count($m) === 1, 'primera carga: UN solo mensaje (' . count($m) . ')');
ok(isset($m[0]) && strpos($m[0], '5.000') !== false && strpos($m[0], '2.500') !== false,
   'y trae las fichas y el bono, que es la buena noticia');
ok(isset($m[0]) && stripos($m[0], 'app') === false
   && strpos($m[0], 'descargar.html') === false,
   'la confirmacion NO menciona la app: de eso se ocupa el cartel del widget');

rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 3000, 'bono' => 0, 'es_primera' => 0]);
ok(count($mensajes()) === 2, 'segunda carga: tambien un solo mensaje');

/* La config de la app ya no puede agregar ni sacar mensajes del chat. Los tres
   casos que antes cambiaban el resultado hoy dan todos lo mismo. */
$pdo->prepare("UPDATE usuarios SET tiene_app = 1 WHERE username = ?")->execute([$U]);
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 3, 'con la app ya instalada, igual');

$pdo->prepare("UPDATE usuarios SET tiene_app = 0 WHERE username = ?")->execute([$U]);
cfg_crm_guardar($pdo, ['app_promo_activa' => '0'], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 4, 'con la promo apagada, igual');

cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_url' => ''], 'test');
rl_notificar_acreditada($pdo, ['usuario' => $U, 'coins' => 1000, 'bono' => 0, 'es_primera' => 1]);
ok(count($mensajes()) === 5, 'sin app_url configurada, igual');

// Dejar la config como estaba (otra suite puede depender de ella).
cfg_crm_guardar($pdo, array_map(fn($v) => (string)($v ?? ''), $cfgViejo), 'test');
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
