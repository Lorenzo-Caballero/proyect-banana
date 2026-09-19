<?php
/**
 * t_fidelizacion.php — La campaña de fidelización, de punta a punta.
 *
 * Lo que garantiza:
 *   1. Al cruzar un escalón: bono pct pendiente + push + mensaje de chat +
 *      candado en fidelizacion_avisos. Una sola vez por escalón y racha.
 *   2. Al cruzar el siguiente escalón: el bono SE MEJORA (misma fila, % más
 *      alto), no se apila. Escalón con ruleta regala el giro de cortesía.
 *   3. Si vuelve a jugar (ultima_actividad cambia), la próxima racha avisa
 *      de nuevo.
 *   4. Campaña apagada / jugador activo / sin ultima_actividad: nada.
 *   5. La acreditación: crmnotif_bono_aplicar_en_recarga devuelve el MONTO
 *      (pct sobre la carga), marca 'aplicado', avisa por push, y rl_acreditar
 *      lo manda al juego vía $recarga['bono'] (se prueba la pieza, el viaje
 *      completo carga→juego ya lo cubren t_bono/t_bono_e2e).
 *   6. fid_parsear_tramos rechaza basura (días repetidos, % fuera de rango).
 *
 *     T_PORT=3399 php t_fidelizacion.php
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
require_once __DIR__ . '/api/crm_lib.php';
require_once __DIR__ . '/api/crm_notificaciones.php';
require_once __DIR__ . '/api/notificaciones_lib.php';
require_once __DIR__ . '/api/fidelizacion_lib.php';

$fallas = 0;
function ok(bool $c, string $m): void
{
    global $fallas;
    echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n";
    if (!$c) { $fallas++; }
}

$U = 't_fid_1';
$limpiar = function () use ($pdo, $U): void {
    foreach (['usuarios' => 'username', 'movimientos' => 'usuario', 'bonos_pendientes' => 'usuario',
              'fidelizacion_avisos' => 'usuario', 'ruleta_giros_cortesia' => 'usuario',
              'notificaciones' => 'usuario'] as $t => $col) {
        try { $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$U]); } catch (Throwable $e) {}
    }
    $pdo->prepare("DELETE m FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?")->execute([$U]);
    $pdo->prepare("DELETE FROM conversaciones WHERE clave = ?")->execute([$U]);
    try { $pdo->prepare("DELETE FROM dispositivos WHERE usuario = ?")->execute([$U]); } catch (Throwable $e) {}
};
$limpiar();
/* CON LA APP Y LAS NOTIFICACIONES PRENDIDAS, porque desde el 18/09/2026 ese
   es el publico de la campaña por default. El jugador de prueba tenia
   tiene_app=0 y con eso los 15 chequeos de abajo dejaron de pasar -- que es
   justo la prueba de que el filtro nuevo funciona. Ver la seccion "a quien le
   habla" mas abajo, que cubre el caso contrario. */
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990501, ?, 0, 0, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 3 DAY))")->execute([$U]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$U, $U, 't-sess-fid']);
/* UN CELULAR DE VERDAD, no solo la marca. La entrega es POR DISPOSITIVO
   (notificaciones_entregas), asi que `tiene_app=1` sin un android con permiso
   es una push que se encola y no ve nadie. */
$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
               VALUES ('t-dev-fid-1', ?, 'android', 1)")->execute([$U]);
cfg_crm_guardar($pdo, [
    'fid_activa'   => '1',
    'fid_publico'  => 'app',
    'fid_dias_max' => '30',
    /* SIN VENTANA HORARIA: un test que depende del reloj de pared pasa de
       mañana y falla de madrugada, que es justo cuando uno lo corre para
       arreglar algo. La ventana se prueba aparte, abajo. */
    'fid_hora_desde' => '',
    'fid_hora_hasta' => '',
    'fid_tramos' => '[{"dias":2,"pct":20},{"dias":3,"pct":25},{"dias":4,"pct":30},{"dias":7,"pct":40},{"dias":8,"pct":50,"ruleta":1}]',
], 'test');

$bono = function () use ($pdo, $U): ?array {
    $st = $pdo->prepare("SELECT id, tipo, valor, estado FROM bonos_pendientes
                          WHERE usuario = ? AND prometido_por = 'fidelizacion' AND tipo = 'pct'
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$U]);
    return $st->fetch() ?: null;
};
$mensajes = function () use ($pdo, $U): array {
    $st = $pdo->prepare("SELECT m.texto FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id
                          WHERE c.clave = ? ORDER BY m.id");
    $st->execute([$U]);
    return array_column($st->fetchAll(), 'texto');
};
$avisos = function () use ($pdo, $U): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM fidelizacion_avisos WHERE usuario = ?");
    $st->execute([$U]);
    return (int)$st->fetchColumn();
};

// ---- 1. jugador inactivo 3 dias: escalon del 25% -----------------------------
echo "1. Inactivo hace 3 días (escalón 25%)\n";
$r = fid_correr($pdo);
ok(($r['avisados'] ?? 0) >= 1, 'la pasada avisó a alguien (' . ($r['avisados'] ?? '?') . ')');
$b = $bono();
ok($b !== null && (int)$b['valor'] === 25 && $b['estado'] === 'pendiente', 'bono pct 25 pendiente');
$m = $mensajes();
ok(count($m) === 1 && strpos($m[0], '25%') !== false, 'mensaje de Camila en el chat con el 25%');
$st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario = ? AND origen = 'fidelizacion'");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 1, 'push encolada');
ok($avisos() === 1, 'candado reservado');

// ---- 2. correr de nuevo: nada nuevo ------------------------------------------
echo "2. Segunda pasada del cron (mismo día)\n";
fid_correr($pdo);
ok($avisos() === 1 && count($mensajes()) === 1, 'no duplica ni aviso ni mensaje');

// ---- 3. sigue inactivo: 8 dias -> mejora a 50% + giro ------------------------
echo "3. Llega a 8 días (50% + giro de ruleta)\n";
$pdo->prepare("UPDATE usuarios SET ultima_actividad = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE username = ?")->execute([$U]);
// misma racha: los candados viejos apuntan a OTRO actividad_ref, este es nuevo
fid_correr($pdo);
$b = $bono();
ok($b !== null && (int)$b['valor'] === 50 && $b['estado'] === 'pendiente', 'el MISMO bono mejorado a 50 (no hay dos)');
$st = $pdo->prepare("SELECT COUNT(*) FROM bonos_pendientes WHERE usuario = ? AND tipo = 'pct' AND prometido_por = 'fidelizacion'");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 1, 'sigue habiendo UN solo bono de fidelización');
$st = $pdo->prepare("SELECT COUNT(*) FROM ruleta_giros_cortesia WHERE usuario = ? AND estado = 'pendiente'");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 1, 'giro de cortesía regalado');
$m = $mensajes();
ok(count($m) === 2 && strpos($m[1], '50%') !== false && strpos($m[1], 'ruleta') !== false,
   'el chat cuenta el 50% y el giro');

// ---- 4. transfiere: el bono se aplica, con monto y push ----------------------
echo "4. Hace una carga de 1000 (se aplica el 50%)\n";
$monto = crmnotif_bono_aplicar_en_recarga($pdo, $U, 12345, 1000);
ok($monto === 500, 'devuelve el monto para el deposito al juego (500 = 50% de 1000), dio ' . var_export($monto, true));
$b = $bono();
ok($b !== null && $b['estado'] === 'aplicado', 'el bono quedó aplicado');
$st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario = ? AND origen = 'crm_bono'");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 1, 'push de "bono aplicado"');
$st = $pdo->prepare("SELECT bonus FROM usuarios WHERE username = ?");
$st->execute([$U]);
ok((int)$st->fetchColumn() === 500, 'el contador bonus recibió los 500 (el depósito después los debita)');

// ---- 5. vuelve a jugar y se inactiva de nuevo: nueva racha -------------------
echo "5. Jugó de nuevo y volvió a inactivarse (nueva racha)\n";
$pdo->prepare("UPDATE usuarios SET ultima_actividad = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE username = ?")->execute([$U]);
fid_correr($pdo);
$b = $bono();
ok($b !== null && (int)$b['valor'] === 20 && $b['estado'] === 'pendiente', 'nueva racha: bono nuevo del 20%');

// ---- 6. campaña apagada / activo / sin dato ----------------------------------
echo "6. Los que NO deben recibir nada\n";
cfg_crm_guardar($pdo, ['fid_activa' => '0'], 'test');
$r = fid_correr($pdo);
ok(($r['avisados'] ?? -1) === 0 && ($r['motivo'] ?? '') === 'campaña apagada', 'apagada: no corre');
cfg_crm_guardar($pdo, ['fid_activa' => '1'], 'test');
$pdo->prepare("UPDATE usuarios SET ultima_actividad = NOW() WHERE username = ?")->execute([$U]);
$antes = $avisos();
fid_correr($pdo);
ok($avisos() === $antes, 'jugador activo: sin aviso');
$pdo->prepare("UPDATE usuarios SET ultima_actividad = NULL WHERE username = ?")->execute([$U]);
fid_correr($pdo);
ok($avisos() === $antes, 'sin ultima_actividad (no sabemos): sin aviso');

// ---- 7. la validacion de escalones -------------------------------------------
echo "7. fid_parsear_tramos\n";
ok(fid_parsear_tramos('[{"dias":2,"pct":20},{"dias":2,"pct":30}]') === null, 'días repetidos: rechazado');
ok(fid_parsear_tramos('[{"dias":2,"pct":0}]') === null, '0%: rechazado');
ok(fid_parsear_tramos('[{"dias":400,"pct":20}]') === null, '400 días: rechazado');
ok(fid_parsear_tramos('basura') === null, 'JSON roto: rechazado');
$t = fid_parsear_tramos('[{"dias":7,"pct":40},{"dias":2,"pct":20}]');
ok(is_array($t) && $t[0]['dias'] === 2, 'ordena por días ascendente');


// ===========================================================================
echo "\n== A QUIEN LE HABLA LA CAMPAÑA ==\n";

/* EL AGUJERO DE 300.000 FICHAS, medido el 18/09/2026 sobre la unica pasada que
   corrio (16/09):

       600 bonos del 50% prometidos
       600 notificaciones push creadas  ->  0 ENTREGADAS
       600 mensajes de chat escritos    ->  0 leidos
         0 jugadores volvieron

   Ninguno de los 600 tenia la app. Un bono que el jugador no sabe que tiene no
   incentiva nada: es una deuda y nada mas. La campaña es un EMPUJON, y un
   empujon que no llega no es un empujon.

   Nahuel: *"quiero que esa fidelizacion se le mande a la gente que tiene la
   aplicacion instalada... que ya podemos hacer que les lleguen
   notificaciones"*. */
$V = 't_fid_2';                       // el mismo caso, SIN la app
$limpiar2 = function () use ($pdo, $V): void {
    foreach (['usuarios' => 'username', 'bonos_pendientes' => 'usuario',
              'fidelizacion_avisos' => 'usuario', 'notificaciones' => 'usuario'] as $tb => $col) {
        try { $pdo->prepare("DELETE FROM $tb WHERE $col = ?")->execute([$V]); } catch (Throwable $e) {}
    }
    try { $pdo->prepare("DELETE FROM dispositivos WHERE usuario = ?")->execute([$V]); } catch (Throwable $e) {}
    $pdo->prepare("DELETE m FROM mensajes m JOIN conversaciones c ON c.id = m.conversacion_id WHERE c.clave = ?")->execute([$V]);
    $pdo->prepare("DELETE FROM conversaciones WHERE clave = ?")->execute([$V]);
};
$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 0, 0, DATE_SUB(NOW(), INTERVAL 3 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$V, $V, 't-sess-fid2']);

$bonoDe = function (string $u) use ($pdo): ?array {
    $st = $pdo->prepare("SELECT valor FROM bonos_pendientes WHERE usuario = ? AND prometido_por = 'fidelizacion'");
    $st->execute([$u]);
    return $st->fetch() ?: null;
};

cfg_crm_guardar($pdo, ['fid_publico' => 'app'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) === null, 'sin la app: NO se le promete nada');

/* Y con las notificaciones APAGADAS tampoco, aunque tenga la app: el
   SondeoWorker del APK chequea el permiso ANTES de pedir la lista, asi que la
   push no se ve (y encima se consumiria el aviso). Para esto, tener la app con
   las notificaciones apagadas es igual que no tenerla. */
$pdo->prepare("UPDATE usuarios SET tiene_app = 1, notificaciones = 0 WHERE username = ?")->execute([$V]);
fid_correr($pdo, 50);
ok($bonoDe($V) === null, 'con la app pero sin permiso de notificaciones: tampoco');

$pdo->prepare("UPDATE usuarios SET notificaciones = 1 WHERE username = ?")->execute([$V]);
fid_correr($pdo, 50);
ok($bonoDe($V) === null, 'con la marca pero SIN un celular registrado: tampoco');

/* LA MARCA NO ES EL CELULAR. Medido el 18/09/2026: 29 jugadores tienen
   tiene_app=1 y solo 21 tienen un android con el permiso puesto. Los otros 8
   desinstalaron o revocaron, y la push se les encolaria sin que la vea nadie.
   La entrega es POR DISPOSITIVO, no por el flag. */
$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
               VALUES ('t-dev-fid-2', ?, 'android', 1)")->execute([$V]);
fid_correr($pdo, 50);
ok($bonoDe($V) !== null, 'con la app, el permiso y un celular que sondea: ahora si');

/* 'contacto' abre la puerta al que tiene chat: el mensaje le queda esperando.
   Es el punto medio, y existe para poder elegirlo a sabiendas. */
$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 0, 0, DATE_SUB(NOW(), INTERVAL 3 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$V, $V, 't-sess-fid2']);
cfg_crm_guardar($pdo, ['fid_publico' => 'contacto'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) !== null, 'publico "contacto": al que tiene chat si le habla');

/* Y un valor raro en la config cae al lado seguro, que es el mas chico. Una
   config rota no puede abrir la campaña a los 3.000. */
$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 0, 0, DATE_SUB(NOW(), INTERVAL 3 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO conversaciones (clave, usuario, session_id) VALUES (?,?,?)")->execute([$V, $V, 't-sess-fid2']);
cfg_crm_guardar($pdo, ['fid_publico' => 'cualquier-cosa'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) === null, 'una config rara NO abre la campaña a todos');

echo "\n== HASTA CUANDO INSISTIR ==\n";

/* La primera pasada le dio a 600 personas el escalon MAS CARO de una: el motor
   le da a cada uno el mas alto que ya cumplio, asi que sin tope todo el
   backlog viejo entra directo al 50%. Alguien que hace meses que no aparece no
   es un jugador enfriado: es uno que se fue. */
$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 120 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
               VALUES ('t-dev-fid-2', ?, 'android', 1)")->execute([$V]);
cfg_crm_guardar($pdo, ['fid_publico' => 'app', 'fid_dias_max' => '30'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) === null, '120 dias sin aparecer: ya no se le gasta un bono');

$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 120 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
               VALUES ('t-dev-fid-2', ?, 'android', 1)")->execute([$V]);
cfg_crm_guardar($pdo, ['fid_dias_max' => '0'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) !== null, 'con el tope en 0 (sin tope) vuelve a entrar');

echo "
== A UN BLOQUEADO NO SE LE OFRECE NADA ==
";

/* Medido el 19/09/2026 en la primera pasada automatica: 4 de los 14 avisados
   estaban bloqueados en el CRM, con motivos como cuenta trucha o comprobantes
   truchos escritos a mano por Nahuel -- y les estabamos ofreciendo un bono
   para que vuelvan.
   El motor miraba `is_banned` (el flag de ganamos) y no `bloqueado` (el que
   pone el operador desde el CRM, que es el que se usa de verdad). */
$limpiar2();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones, bloqueado, ultima_actividad)
               VALUES (990502, ?, 0, 0, 0, 1, 1, 1, DATE_SUB(NOW(), INTERVAL 3 DAY))")->execute([$V]);
$pdo->prepare("INSERT INTO dispositivos (device_id, usuario, plataforma, permitido)
               VALUES ('t-dev-fid-2', ?, 'android', 1)")->execute([$V]);
cfg_crm_guardar($pdo, ['fid_publico' => 'app', 'fid_dias_max' => '30'], 'test');
fid_correr($pdo, 50);
ok($bonoDe($V) === null, 'bloqueado en el CRM: no entra aunque tenga la app');

$pdo->prepare("UPDATE usuarios SET bloqueado = 0 WHERE username = ?")->execute([$V]);
fid_correr($pdo, 50);
ok($bonoDe($V) !== null, 'y al desbloquearlo vuelve a entrar');

cfg_crm_guardar($pdo, ['fid_publico' => 'app', 'fid_dias_max' => '30'], 'test');
$limpiar2();


echo "\n== NO SE LE HABLA A NADIE DE MADRUGADA ==\n";

/* MEDIDO EL 19/09/2026 A LAS 02:37: la pasada automatica de la 01:30 creo 14
   avisos y entrego CERO. No era un bug -- era la madrugada. La push se entrega
   cuando el celular sondea, y a esa hora no sondea nadie: los aparatos estaban
   dormidos desde las 21:26, la 01:05 y la 01:30.

   Y le pega mas a ESTA campaña que a ninguna otra, porque apunta justamente a
   los que hace dias que no abren la app. */
$pdo->prepare("UPDATE usuarios SET ultima_actividad = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE username = ?")->execute([$U]);
$pdo->prepare("DELETE FROM fidelizacion_avisos WHERE usuario = ?")->execute([$U]);

/* Una franja que NO incluye este momento, sea la hora que sea: se toma la hora
   argentina actual y se define una ventana de una hora que ya paso. Asi el
   test no depende del reloj de pared. */
$ar = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
$h  = (int)$ar->format('G');
$cerrada_ini = str_pad((string)(($h + 2) % 24), 2, '0', STR_PAD_LEFT) . ':00';
$cerrada_fin = str_pad((string)(($h + 3) % 24), 2, '0', STR_PAD_LEFT) . ':00';
cfg_crm_guardar($pdo, ['fid_hora_desde' => $cerrada_ini, 'fid_hora_hasta' => $cerrada_fin], 'test');
$v = fid_ventana($pdo);
ok($v['abierta'] === false, "fuera de la franja ($cerrada_ini a $cerrada_fin) esta cerrada");
$r = fid_correr($pdo, 50);
ok((int)$r['avisados'] === 0 && str_contains((string)($r['motivo'] ?? ''), 'fuera de horario'),
   'y no se le avisa a nadie: ' . ($r['motivo'] ?? ''));

/* PERO EL LATIDO SE SELLA IGUAL. Si no, la vigilancia de salud_colector.php
   confundiria "no es hora de avisar" con "el cron se murio" -- y avisaria por
   Telegram todas las noches. */
ok(trim((string)cfg_crm($pdo, 'fid_visto_en')) !== '',
   'pero la pasada queda registrada: no es que el cron se murio');

// Y con la franja abierta vuelve a avisar.
$abierta_ini = str_pad((string)(($h + 23) % 24), 2, '0', STR_PAD_LEFT) . ':00';
$abierta_fin = str_pad((string)(($h + 2) % 24), 2, '0', STR_PAD_LEFT) . ':00';
cfg_crm_guardar($pdo, ['fid_hora_desde' => $abierta_ini, 'fid_hora_hasta' => $abierta_fin], 'test');
ok(fid_ventana($pdo)['abierta'] === true, "dentro de la franja ($abierta_ini a $abierta_fin) esta abierta");
$r = fid_correr($pdo, 50);
ok((int)$r['avisados'] === 1, 'y ahi si le avisa');

/* Config incompleta o rota = sin restriccion. Una campaña frenada por un typo
   del operador es peor que un aviso de mas a las once de la noche. */
cfg_crm_guardar($pdo, ['fid_hora_desde' => '', 'fid_hora_hasta' => ''], 'test');
ok(fid_ventana($pdo)['abierta'] === true, 'sin franja configurada, a cualquier hora');
cfg_crm_guardar($pdo, ['fid_hora_desde' => 'cualquiera', 'fid_hora_hasta' => '22:00'], 'test');
ok(fid_ventana($pdo)['abierta'] === true, 'una franja mal escrita se ignora, no frena la campaña');
cfg_crm_guardar($pdo, ['fid_hora_desde' => '', 'fid_hora_hasta' => ''], 'test');

// ---- limpiar ------------------------------------------------------------------
cfg_crm_guardar($pdo, ['fid_activa' => '0'], 'test');
$limpiar();

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
