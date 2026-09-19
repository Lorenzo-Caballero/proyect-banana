<?php
/**
 * t_efectividad.php — ¿Sirven los avisos? Que el número diga lo que dice.
 *
 * POR QUÉ EXISTE. La pantalla de Notificaciones mostraba "42,5% cargaron
 * después del aviso". Nahuel pidió corroborarlo (19/09/2026) y al medir
 * producción resultó que el número no probaba nada: casi todo era el aviso
 * "te contestamos" del chat —que va con `solo_app=1` y el widget ni dibuja— y
 * en la mitad de los casos el aviso se había creado DESPUÉS de la carga.
 *
 * Nada de eso lo agarraba un test: `t_salud.php` solo chequea que la función
 * EXISTA. Un porcentaje mal armado pasa `php -l` y pasa un string-match; lo
 * único que lo agarra es armar el caso y mirar el número que sale.
 *
 * Lo que garantiza, y cada uno es un error que ya se cometió:
 *   1. Se mide desde la ENTREGA, no desde que se creó el aviso. Una carga que
 *      pasó en el medio (hasta 20 horas en producción) no cuenta.
 *   2. Un aviso que nadie ve (`solo_app=1`) no cuenta como aviso.
 *   3. Los transaccionales van aparte de las promos: "te acreditamos la carga"
 *      llegó PORQUE cargó, no al revés.
 *   4. `reclamo` cuenta el bono que el aviso prometió y el jugador usó, que es
 *      la única medida atribuible.
 *   5. `toque` compara a los que abrieron el aviso contra los que no.
 *
 *     T_PORT=3399 php t_efectividad.php
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
require_once __DIR__ . '/api/publicidad_lib.php';
require_once __DIR__ . '/api/crm_notificaciones.php';

$fallas = 0;
function ok(bool $c, string $m): void
{
    global $fallas;
    echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n";
    if (!$c) { $fallas++; }
}

/* ------------------------------------------------------------------ fixture */
const PREF = 't_ef_';
$limpiar = function () use ($pdo): void {
    $like = PREF . '%';
    $pdo->prepare("DELETE e FROM notificaciones_entregas e
                    JOIN dispositivos d ON d.device_id = e.device_id
                   WHERE d.usuario LIKE ?")->execute([$like]);
    foreach (['dispositivos' => 'usuario', 'notificaciones' => 'usuario',
              'bonos_pendientes' => 'usuario', 'movimientos' => 'usuario',
              'recargas' => 'usuario', 'usuarios' => 'username'] as $t => $col) {
        try { $pdo->prepare("DELETE FROM $t WHERE $col LIKE ?")->execute([$like]); } catch (Throwable $e) {}
    }
};
$limpiar();

$id = 991000;
/** Crea el jugador con su celular. */
$jugador = function (string $u) use ($pdo, &$id): string {
    $pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones)
                   VALUES (?,?,0,0,0,1,1)")->execute([$id++, $u]);
    $dev = 'dev_' . $u;
    $pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
                   VALUES (?,?, 'android', 1)")->execute([$dev, $u]);
    return $dev;
};
/** Un aviso ya entregado hace $horasEntrega horas. Devuelve su id. */
$aviso = function (string $u, string $dev, string $origen, int $horasCreado,
                   int $horasEntrega, int $soloApp = 0, bool $toco = false) use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO notificaciones (usuario, titulo, cuerpo, tipo, origen, solo_app, creada_en)
         VALUES (?,?,?,'promo',?,?, NOW() - INTERVAL ? HOUR)"
    )->execute([$u, 'x', 'y', $origen, $soloApp, $horasCreado]);
    $nid = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO notificaciones_entregas (notificacion_id, device_id, entregada_en, leida_en)
         VALUES (?,?, NOW() - INTERVAL ? HOUR, " . ($toco ? "NOW() - INTERVAL ? HOUR" : "NULL") . ")"
    )->execute($toco ? [$nid, $dev, $horasEntrega, $horasEntrega] : [$nid, $dev, $horasEntrega]);
    return $nid;
};
/** Una carga acreditada hace $horas horas. */
$nref = 0;
$carga = function (string $u, int $horas, int $monto = 1000) use ($pdo, &$nref): void {
    /* `referencia` es UNIQUE (uq_ref) y cadena vacia choca con cadena vacia:
       la segunda carga del fixture moria por eso, no por la logica. */
    $pdo->prepare(
        "INSERT INTO recargas (usuario, monto_base, monto_pedido, estado, referencia, creada_en, acreditada_en)
         VALUES (?,?,?, 'acreditada', ?, NOW() - INTERVAL ? HOUR, NOW() - INTERVAL ? HOUR)"
    )->execute([$u, $monto, $monto, PREF . (++$nref), $horas, $horas]);
};
/** La fila de un origen dentro de por_origen. */
$fila = function (array $ef, string $origen): ?array {
    foreach ($ef['por_origen'] as $o) { if ($o['origen'] === $origen) { return $o; } }
    return null;
};

/* =========================================================================
   1. SE MIDE DESDE LA ENTREGA, NO DESDE QUE SE CREO EL AVISO
   ========================================================================= */
echo "\n=== 1. La ventana arranca cuando le LLEGA, no cuando se crea ===\n";

/* Este es el caso exacto de la campaña: el aviso se arma de madrugada y el
   celular recien sondea a la mañana. En el medio el jugador cargo. Esa carga
   no la causo el aviso -- todavia no lo habia visto. */
$u1 = PREF . 'antes';
$d1 = $jugador($u1);
$aviso($u1, $d1, 'fidelizacion', 100, 90);   // creado hace 100h, entregado hace 90h
$carga($u1, 95);                             // cargo hace 95h: DESPUES de crear, ANTES de entregar

$ef = crmnotif_efectividad($pdo, 30);
$f = $fila($ef, 'fidelizacion');
ok($f !== null && (int)$f['avisados'] === 1, 'el aviso entregado cuenta como avisado');
ok($f !== null && (int)$f['cargaron'] === 0,
   'la carga anterior a la entrega NO cuenta: ' . (int)($f['cargaron'] ?? -1));

/* Y una carga posterior a la entrega si cuenta. */
$u2 = PREF . 'despues';
$d2 = $jugador($u2);
$aviso($u2, $d2, 'fidelizacion', 100, 90);
$carga($u2, 80);                             // 10 horas despues de recibirlo

$ef = crmnotif_efectividad($pdo, 30);
$f = $fila($ef, 'fidelizacion');
ok($f !== null && (int)$f['avisados'] === 2 && (int)$f['cargaron'] === 1,
   'la carga posterior a la entrega si cuenta');

/* Y una MUY posterior, no: la ventana es de 7 dias. */
$u3 = PREF . 'tarde';
$d3 = $jugador($u3);
$aviso($u3, $d3, 'fidelizacion', 400, 400);  // hace 16 dias
$carga($u3, 100);                            // 12 dias despues del aviso

$ef = crmnotif_efectividad($pdo, 30);
$f = $fila($ef, 'fidelizacion');
ok($f !== null && (int)$f['cargaron'] === 1,
   'una carga 12 dias despues no se le cuelga al aviso');

/* =========================================================================
   2. UN AVISO QUE NADIE VE NO ES UN AVISO
   ========================================================================= */
echo "\n=== 2. solo_app=1: el widget lo consume y no lo dibuja ===\n";

/* Es lo que inflaba el 42,5%: 84 de 87 "avisados" eran esto. */
$u4 = PREF . 'invisible';
$d4 = $jugador($u4);
$aviso($u4, $d4, 'chatbot', 100, 90, 1);
$carga($u4, 80);

$ef = crmnotif_efectividad($pdo, 30);
ok($fila($ef, 'chatbot') === null, 'el aviso invisible no aparece en ningun conteo');

/* =========================================================================
   3. TRANSACCIONALES APARTE DE LAS PROMOS
   ========================================================================= */
echo "\n=== 3. 'te acreditamos la carga' llego PORQUE cargo ===\n";

$u5 = PREF . 'trx';
$d5 = $jugador($u5);
$aviso($u5, $d5, 'carga', 100, 90, 0);
$carga($u5, 80);

$ef = crmnotif_efectividad($pdo, 30);
$f = $fila($ef, 'carga');
ok($f !== null && $f['clase'] === 'transaccional', 'el aviso de carga queda marcado transaccional');
ok((int)$ef['transaccional']['cargaron'] === 1, 'y suma del lado transaccional');
/* UNA sola: de los tres jugadores con promo, u1 cargo ANTES de la entrega
   y u3 doce dias despues de la ventana. Queda u2. */
ok((int)$ef['promo']['cargaron'] === 1,
   'la carga transaccional no se cuela en las promos (1), dio ' . (int)$ef['promo']['cargaron']);

/* =========================================================================
   4. LA MEDIDA DURA: USO EL BONO QUE EL AVISO LE PROMETIO
   ========================================================================= */
echo "\n=== 4. Reclamaron el bono del aviso ===\n";

$u6 = PREF . 'reclamo';
$d6 = $jugador($u6);
$n6 = $aviso($u6, $d6, 'fidelizacion', 50, 48);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, estado, prometido_por, notificacion_id)
               VALUES (?, 'pct', 25, 'aplicado', 'fidelizacion', ?)")->execute([$u6, $n6]);

$u7 = PREF . 'nocobro';
$d7 = $jugador($u7);
$n7 = $aviso($u7, $d7, 'fidelizacion', 50, 48);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, estado, prometido_por, notificacion_id)
               VALUES (?, 'pct', 25, 'pendiente', 'fidelizacion', ?)")->execute([$u7, $n7]);

/* Y uno SIN aviso atado: no entra al porcentaje, pero se informa aparte --
   si son muchos, el 0% de arriba no significa "nadie cobro" sino "no lo
   estamos midiendo". */
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, estado, prometido_por)
               VALUES (?, 'fichas', 300, 'aplicado', 'ruleta')")->execute([PREF . 'suelto']);

$ef = crmnotif_efectividad($pdo, 30);
$r = $ef['reclamo'];
ok((int)$r['prometidos'] === 2, 'cuenta los 2 bonos prometidos POR un aviso, dio ' . (int)$r['prometidos']);
ok((int)$r['reclamados'] === 1, 'y que 1 se reclamo');
ok(abs((float)$r['pct'] - 50.0) < 0.01, 'o sea 50%, dio ' . $r['pct']);
ok((int)$r['sin_atar'] >= 1, 'y avisa que hay bonos sin aviso atado, fuera de la cuenta');

/* =========================================================================
   5. EL TOQUE: ABRIR EL AVISO CONTRA NO ABRIRLO
   ========================================================================= */
echo "\n=== 5. Los que lo abrieron contra los que no ===\n";

$u8 = PREF . 'toco';
$d8 = $jugador($u8);
$aviso($u8, $d8, 'fidelizacion', 100, 90, 0, true);
$carga($u8, 80);

$ef = crmnotif_efectividad($pdo, 30);
$q = $ef['toque'];
ok((int)$q['tocaron'] === 1 && (int)$q['tocaron_cargaron'] === 1,
   'el que lo abrio y cargo cuenta de ese lado');
ok((int)$q['ignoraron'] >= 4, 'y los que no lo abrieron van al otro grupo');

/* LOS DOS GRUPOS SUMAN EL UNIVERSO, ni mas ni menos. Es lo que hace
   comparables los dos porcentajes: si un jugador cayera en los dos, el
   "abrieron" se llevaria gente que tambien esta del otro lado y la
   comparacion no diria nada. Se cuenta contra la base real. */
$universo = (int)$pdo->query(
    "SELECT COUNT(DISTINCT d.usuario)
       FROM notificaciones_entregas e
       JOIN notificaciones o ON o.id = e.notificacion_id AND COALESCE(o.solo_app,0) = 0
       JOIN dispositivos d ON d.device_id = e.device_id
      WHERE e.entregada_en >= NOW() - INTERVAL 30 DAY
        AND e.entregada_en <  NOW() - INTERVAL 1 DAY
        AND d.usuario LIKE '" . PREF . "%'"
)->fetchColumn();
ok((int)$q['tocaron'] + (int)$q['ignoraron'] === $universo,
   'los dos grupos suman el universo y no se pisan: '
   . $q['tocaron'] . '+' . $q['ignoraron'] . ' contra ' . $universo);

/* Y EL QUE ABRIO UNO Y OTRO NO cuenta una sola vez, del lado de los que
   abrieron: lo que se quiere saber es si abrir mueve la aguja, no cuantos
   avisos abrio. Sin esto el mismo jugador estaria en los dos lados. */
$ignoraronAntes = (int)$q['ignoraron'];
$aviso($u8, $d8, 'fidelizacion', 60, 50, 0, false);   // segundo aviso, sin abrir
$ef = crmnotif_efectividad($pdo, 30);
$q = $ef['toque'];
ok((int)$q['tocaron'] === 1 && (int)$q['ignoraron'] === $ignoraronAntes,
   'el que abrio uno y otro no sigue de un solo lado: '
   . $q['tocaron'] . ' / ' . $q['ignoraron'] . ' (antes ' . $ignoraronAntes . ')');

$limpiar();
printf("\n---------------------------------------\n%s\n",
       $fallas ? "$fallas FALLAS" : 'TODO OK');
exit($fallas > 0 ? 1 : 0);
