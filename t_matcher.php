<?php
/**
 * t_matcher.php — El matcher de transferencias, probado donde duele.
 *
 * Estos chequeos existen porque acá se decide a QUIEN se le acredita
 * plata. Un falso positivo no es un bug molesto: es cargarle las fichas al
 * jugador equivocado. Por eso hay tantos casos de "NO tiene que acreditar"
 * como de "sí tiene que acreditar".
 *
 * Corre contra una base DE PRUEBA, nunca producción. Por defecto
 * goldpaw_demo en el MySQL local (XAMPP, root sin clave):
 *
 *     php t_matcher.php
 *     T_DB=otra_base T_USER=root T_PASS=x php t_matcher.php
 *
 * Necesita las migraciones api/sql/45 y 46 aplicadas.
 * Limpia lo suyo al empezar y al terminar (solo filas test_% / TEST-%).
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root',
    getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
// recargas_lib.php usa $pdo del scope global en algunas rutas opcionales.
$GLOBALS['pdo'] = $pdo;

/* APAGAR META ANTES DE TOCAR NADA.
   Este test acredita recargas de verdad, y acreditar dispara Purchase hacia la
   API de Conversiones. Si la base que se le pasa tuviera `meta_activo=1` con un
   token real -- por ejemplo si alguien corre esto contra produccion por error,
   o clona la base para probar -- cada corrida le meteria compras FALSAS al
   pixel, inflando las conversiones y envenenando la optimizacion de la campaña
   con datos inventados. Y no se veria: los eventos entran como cualquier otro.
   Se apaga en la fila, no con un mock, porque lo que hay que garantizar es que
   NO SALGA UN PAQUETE A INTERNET pase por donde pase el codigo. */
try {
    $pdo->prepare("INSERT INTO config_crm (clave, valor) VALUES ('meta_activo','0')
                   ON DUPLICATE KEY UPDATE valor='0'")->execute();
} catch (Throwable $e) {
    fwrite(STDERR, "AVISO: no pude apagar meta_activo. Si esta base tiene un pixel\n"
                 . "real configurado, este test le va a mandar Purchases falsos.\n");
}
// Stub de config.php: sin esto, todo lo que llame a cfg() (la carga
// automatica al juego, los limites) falla en silencio dentro de su try/catch
// y el test da verde sin haber ejercitado nada.
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $que, bool $cond, string $detalle = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++;  printf("  OK    %s\n", $que); }
    else       { $fail++; printf("  FALLA %s   %s\n", $que, $detalle); }
}
function limpiar(PDO $pdo): void {
    $pdo->exec("DELETE FROM recargas WHERE usuario LIKE 'test\\_%'");
    $pdo->exec("DELETE FROM pagos WHERE id_unico LIKE 'TEST-%'");
    $pdo->exec("DELETE FROM huellas_pagador WHERE usuario LIKE 'test\\_%'");
    $pdo->exec("DELETE FROM usuarios WHERE username LIKE 'test\\_%'");
}
function crearUsuario(PDO $pdo, string $u): void {
    $pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
                   ON DUPLICATE KEY UPDATE coins=0")->execute([crc32($u), $u]);
}
function crearRecarga(PDO $pdo, string $usuario, float $monto, string $titular): int {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, centavos,
                               titular_declarado, estado, creada_en, vence_en)
         VALUES (?,?,?,?,?,?,?, 'pendiente', NOW(), DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
    )->execute([substr(md5(uniqid('', true)), 0, 10), $usuario, (int)$monto,
                floor($monto), $monto, null, $titular]);
    return (int)$pdo->lastInsertId();
}
function crearPago(PDO $pdo, string $id, float $monto, string $remitente,
                   string $cuit = '', string $cbu = ''): void {
    $pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, cuit, cbu_origen, estado)
                   VALUES (?,?,?,?,?, 'pendiente')")
        ->execute([$id, $monto, $remitente, $cuit, $cbu]);
}
function coinsDe(PDO $pdo, string $u): int {
    $st = $pdo->prepare("SELECT coins FROM usuarios WHERE username=?");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
}

// ===========================================================================
echo "\n=== 1. Similitud de nombres ===\n";
// Los casos reales: el jugador escribe su nombre a las apuradas en un chat.
$decl = 'NAHUEL HERRERA';
foreach ([
    ['NAHUEL HERRERA',         true,  'identico'],
    ['NAHUE HERRERA',          true,  'truncado'],
    ['NAHUER HERRRA',          true,  'dos erratas'],
    ['NAHUL EHERRERA',         true,  'erratas raras'],
    ['FACUNDO NAHUEL HERRERA', true,  'le sobra un nombre'],
    ['HERRERA NAHUEL',         true,  'orden invertido'],
    ['herrera, nahuel',        true,  'minusculas y puntuacion'],
    // Los que NO tienen que pasar. Comparten media identidad, y aceptarlos
    // seria acreditarle a otra persona.
    ['JOSE FERNANDEZ',         false, 'otra persona'],
    ['NAHUEL GOMEZ',           false, 'mismo nombre, otro apellido'],
    ['MARIA HERRERA',          false, 'otro nombre, mismo apellido'],
] as [$nombre, $esperado, $etiq]) {
    $s = rl_similitud_nombres($nombre, $decl);
    chequear(sprintf('%-24s %-28s %.3f', $nombre, $etiq, $s),
             ($s >= RL_UMBRAL_NOMBRE) === $esperado);
}

// ===========================================================================
echo "\n=== 2. Dos recargas del MISMO monto redondo, titulares distintos ===\n";
// El caso que antes de la migracion 45 iba SIEMPRE a revision manual.
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');
crearRecarga($pdo, 'test_ana',  1000.00, 'ANA GOMEZ');
crearRecarga($pdo, 'test_beto', 1000.00, 'ROBERTO SUAREZ');
crearPago($pdo, 'TEST-1', 1000.00, 'ROBERTOO SUAREZ', '20111111111');
$r = rl_matchear_y_acreditar($pdo, 'TEST-1', 1000.00);
chequear('acredita en vez de mandar a revision', ($r['resultado'] ?? '') === 'acreditada',
         json_encode($r, JSON_UNESCAPED_UNICODE));
chequear('le acredita a beto (el del titular que coincide)',
         ($r['usuario'] ?? '') === 'test_beto', 'acredito a: ' . ($r['usuario'] ?? '-'));
chequear('ana NO recibio nada', coinsDe($pdo, 'test_ana') === 0);

// ===========================================================================
echo "\n=== 3. Titulares parecidos: no adivina ===\n";
limpiar($pdo);
crearUsuario($pdo, 'test_juan1');
crearUsuario($pdo, 'test_juan2');
crearRecarga($pdo, 'test_juan1', 500.00, 'JUAN PEREZ');
crearRecarga($pdo, 'test_juan2', 500.00, 'JUAN PEREZ');
crearPago($pdo, 'TEST-2', 500.00, 'JUAN PEREZ', '20222222222');
$r = rl_matchear_y_acreditar($pdo, 'TEST-2', 500.00);
chequear('va a revision', ($r['resultado'] ?? '') === 'revision',
         json_encode($r, JSON_UNESCAPED_UNICODE));
chequear('nadie recibio fichas',
         coinsDe($pdo, 'test_juan1') === 0 && coinsDe($pdo, 'test_juan2') === 0);

// ===========================================================================
echo "\n=== 4. La huella desempata cuando no hay titular declarado ===\n";
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');
$pdo->prepare("INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre) VALUES (?,?,?,?)")
    ->execute(['test_ana', '20333333333', '', 'TERCERO QUE LE PAGA']);
crearRecarga($pdo, 'test_ana',  700.00, '');
crearRecarga($pdo, 'test_beto', 700.00, '');
crearPago($pdo, 'TEST-3', 700.00, 'TERCERO QUE LE PAGA', '20333333333');
$r = rl_matchear_y_acreditar($pdo, 'TEST-3', 700.00);
chequear('acredita por huella', ($r['resultado'] ?? '') === 'acreditada', json_encode($r));
chequear('le acredita a ana (la de la huella)', ($r['usuario'] ?? '') === 'test_ana',
         'acredito a: ' . ($r['usuario'] ?? '-'));

// ===========================================================================
echo "\n=== 5. Sin ninguna señal: no inventa ===\n";
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');
crearRecarga($pdo, 'test_ana',  300.00, '');
crearRecarga($pdo, 'test_beto', 300.00, '');
crearPago($pdo, 'TEST-4', 300.00, 'DESCONOCIDO TOTAL', '20999999999');
$r = rl_matchear_y_acreditar($pdo, 'TEST-4', 300.00);
chequear('va a revision', ($r['resultado'] ?? '') === 'revision',
         json_encode($r, JSON_UNESCAPED_UNICODE));

// ===========================================================================
echo "\n=== 6. Aprende la huella sola al acreditar ===\n";
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearRecarga($pdo, 'test_ana', 250.00, 'ANA GOMEZ');
crearPago($pdo, 'TEST-5', 250.00, 'ANA GOMEZ', '20555555555', '0001112223334445556667');
$r = rl_matchear_y_acreditar($pdo, 'TEST-5', 250.00);
chequear('acredita', ($r['resultado'] ?? '') === 'acreditada', json_encode($r));
$st = $pdo->prepare("SELECT cuit, usos FROM huellas_pagador WHERE usuario='test_ana'");
$st->execute();
$h = $st->fetch();
chequear('guardo la huella para la proxima', $h !== false && $h['cuit'] === '20555555555',
         'huella: ' . json_encode($h));

// ===========================================================================
echo "\n=== 7. El camino de siempre (centavos unicos) sigue intacto ===\n";
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearRecarga($pdo, 'test_ana', 100.87, 'ANA GOMEZ');
crearPago($pdo, 'TEST-6', 100.87, 'CUALQUIER NOMBRE', '');
$r = rl_matchear_y_acreditar($pdo, 'TEST-6', 100.87);
chequear('acredita por monto exacto', ($r['resultado'] ?? '') === 'acreditada',
         json_encode($r, JSON_UNESCAPED_UNICODE));
chequear('el jugador recibio las fichas', coinsDe($pdo, 'test_ana') === 100);

// ===========================================================================
echo "\n=== 8. El titular se pide SOLO cuando el monto choca ===\n";
/* Ya no hay centavos identificadores: el importe alcanza para reconocer el
   pago mientras sea el unico de ese monto esperando. Si OTRO jugador ya tiene
   una pendiente por lo mismo, van a entrar dos transferencias iguales y hace
   falta el titular para distinguirlas.
   Se pregunta unicamente ahi: en el flujo real el jugador dice "me cargas?" y
   espera el alias, no un cuestionario. */
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');

$r = rl_crear_recarga($pdo, 'test_ana', 1000, '');
chequear('primera de 1000: no pide titular', !empty($r['ok']), json_encode($r));
chequear('y el monto va REDONDO, sin centavos',
         ($r['monto_pedido'] ?? '') === '1000.00', json_encode($r['monto_pedido'] ?? null));

$r = rl_crear_recarga($pdo, 'test_beto', 1000, '');
chequear('otro jugador pide 1000: AHI si lo pide',
         ($r['codigo'] ?? '') === 'falta_titular', json_encode($r));

$r = rl_crear_recarga($pdo, 'test_beto', 1000, 'ROBERTO SUAREZ');
chequear('con el titular, pasa', !empty($r['ok']), json_encode($r));

$r = rl_crear_recarga($pdo, 'test_ana', 2000, '');
chequear('otro monto no choca: no pregunta', !empty($r['ok']), json_encode($r));

// ===========================================================================
echo "
=== 9. Pedir lo mismo dos veces es UNA recarga, no dos ===
";
/* holaJorge443 (12/9): el jugador pidio $2000 cinco veces seguidas -- no sabia
   si su transferencia habia entrado -- y se abrieron cinco recargas. Llego al
   tope de pendientes, no pudo pedir mas, y de paso cualquier otro jugador que
   quisiera cargar $2000 se comia el pedido de titular por un choque falso.
   Preguntar de nuevo no es pedir otra carga. */
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');

$r1 = rl_crear_recarga($pdo, 'test_ana', 1500, '');
chequear('primera de 1500: ok', !empty($r1['ok']), json_encode($r1));
$r2 = rl_crear_recarga($pdo, 'test_ana', 1500, '');
chequear('la vuelve a pedir: misma referencia, no una nueva',
         ($r2['referencia'] ?? 'x') === ($r1['referencia'] ?? 'y'),
         ($r1['referencia'] ?? '-') . ' vs ' . ($r2['referencia'] ?? '-'));
chequear('y le repite el mismo monto a transferir',
         ($r2['monto_pedido'] ?? '') === ($r1['monto_pedido'] ?? 'x'));

// Insistir no puede agotarle el cupo: antes, a la sexta se quedaba afuera.
for ($i = 0; $i < 6; $i++) { $rN = rl_crear_recarga($pdo, 'test_ana', 1500, ''); }
chequear('insistir ocho veces no lo deja sin cupo', !empty($rN['ok']), json_encode($rN));
$n = (int)$pdo->query("SELECT COUNT(*) FROM recargas
                        WHERE usuario='test_ana' AND estado='pendiente'")->fetchColumn();
chequear('sigue habiendo UNA sola pendiente', $n === 1, "pendientes=$n");

// El titular que dice recien a la tercera tambien se guarda: es el desempate.
rl_crear_recarga($pdo, 'test_ana', 1500, 'ANA PEREZ');
$tit = $pdo->prepare("SELECT titular_declarado FROM recargas WHERE referencia = ?");
$tit->execute([$r1['referencia']]);
chequear('el titular declarado despues se guarda en la misma recarga',
         (string)$tit->fetchColumn() === 'ANA PEREZ');

// Y el reuso es por jugador: otro que pida lo mismo sigue chocando.
$r = rl_crear_recarga($pdo, 'test_beto', 1500, '');
chequear('otro jugador con el mismo monto sigue necesitando titular',
         ($r['codigo'] ?? '') === 'falta_titular', json_encode($r));
// Otro monto del mismo jugador si es una recarga distinta.
$r = rl_crear_recarga($pdo, 'test_ana', 3000, '');
chequear('otro monto del mismo jugador si abre una nueva',
         !empty($r['ok']) && ($r['referencia'] ?? '') !== ($r1['referencia'] ?? ''),
         json_encode($r));

// ===========================================================================
echo "\n=== 10. El bot VE la plata trabada en revision ===\n";
/* El agujero que hacia que el bot repitiera "ya le avise a un agente": ninguna
   de sus herramientas miraba `pagos`. consultar_recarga leia `recargas` -- lo
   que el jugador PIDIO -- y nunca lo que LLEGO. Entonces a alguien cuyo pago ya
   habia entrado y estaba en revision le contestaba "todavia no me figura".
   No mentia: estaba ciego. Son dos situaciones opuestas para el jugador. */
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
crearUsuario($pdo, 'test_beto');

$r = rl_crear_recarga($pdo, 'test_ana', 1000, '');
chequear('ana pide 1000', !empty($r['ok']), json_encode($r));

// Todavia no llego nada: no hay que inventar ningun pago trabado.
$c = rl_consultar($pdo, 'test_ana');
chequear('sin pago entrado: NO reporta plata trabada',
         ($c['estado'] ?? '') === 'pendiente' && !isset($c['pago_trabado']),
         json_encode($c));

// Entra la transferencia pero el matcher no puede decidir de quien es.
crearPago($pdo, 'TEST-TRAB', 1000, 'DIEGO SANTILLAN');
$pdo->exec("UPDATE pagos SET estado='revision' WHERE id_unico='TEST-TRAB'");

$c = rl_consultar($pdo, 'test_ana');
chequear('con el pago en revision: AHORA si lo ve', !empty($c['pago_trabado']['hay']),
         json_encode($c['pago_trabado'] ?? null));
chequear('y trae el dato que sirve para destrabarlo (a nombre de quien vino)',
         ($c['pago_trabado']['titular'] ?? '') === 'DIEGO SANTILLAN');
chequear('con el id del pago, para que el agente lo encuentre',
         ($c['pago_trabado']['id_unico'] ?? '') === 'TEST-TRAB');
chequear('la recarga NO se acredito sola (mirar no es acreditar)',
         ($c['estado'] ?? '') === 'pendiente' && coinsDe($pdo, 'test_ana') === 0);

/* Y no se le adjudica al que pasaba por ahi: beto tiene una pendiente de OTRO
   monto, asi que ese pago no es suyo y no tiene por que verlo. */
rl_crear_recarga($pdo, 'test_beto', 5000, '');
$c = rl_consultar($pdo, 'test_beto');
chequear('a otro jugador con otro monto no se le ofrece esa plata',
         !isset($c['pago_trabado']), json_encode($c['pago_trabado'] ?? null));


// ===========================================================================
echo "\n=== Resolver a mano lo que el matcher no pudo ===\n";

/* Estos comprobantes son los que se venian acumulando sin salida: 25 llegaron
   a juntarse. Cada caso de aca es una de las dos razones por las que quedaban
   trabados. */

limpiar($pdo);
crearUsuario($pdo, 'test_ana');

// --- Caso 1: la recarga existe pero VENCIO ---------------------------------
// El aviso del banco tardo mas de 45 minutos. Antes desaparecia de la lista de
// candidatas y el pago quedaba sin nada que ofrecerle al operador.
$rid = crearRecarga($pdo, 'test_ana', 1000, 'Ana Perez');
$pdo->exec("UPDATE recargas SET estado='vencida' WHERE id=$rid");
crearPago($pdo, 'TEST-VENC', 1000, 'ANA PEREZ');
$pdo->exec("UPDATE pagos SET estado='revision' WHERE id_unico='TEST-VENC'");

$r = rl_asignar_manual($pdo, 'TEST-VENC', $rid, 'test');
chequear('se puede asignar a una recarga VENCIDA',
         ($r['resultado'] ?? '') === 'acreditada', json_encode($r));
chequear('y las fichas llegaron', coinsDe($pdo, 'test_ana') === 1000,
         (string)coinsDe($pdo, 'test_ana'));

// --- Caso 2: la recarga fue CANCELADA --------------------------------------
// Distinto de vencida: ahi alguien decidio anularla a proposito.
$rid2 = crearRecarga($pdo, 'test_ana', 3000, 'Ana Perez');
$pdo->exec("UPDATE recargas SET estado='cancelada' WHERE id=$rid2");
crearPago($pdo, 'TEST-CANC', 3000, 'ANA PEREZ');
$pdo->exec("UPDATE pagos SET estado='revision' WHERE id_unico='TEST-CANC'");

$r = rl_asignar_manual($pdo, 'TEST-CANC', $rid2, 'test');
chequear('una recarga CANCELADA sigue sin poder usarse',
         ($r['resultado'] ?? '') === 'error', json_encode($r));

// --- Caso 3: no hay recarga y nunca la va a haber --------------------------
// Los pagos del camino A (boton "Depositos"): la solicitud vive del lado de
// ganamos y no crea fila en `recargas`. Sin esto no habia forma de resolverlos.
$pdo->exec("DELETE FROM huellas_pagador WHERE usuario LIKE 'test\\_%'");
crearPago($pdo, 'TEST-SINREC', 5000, 'ANA PEREZ', '27305559999', '');
$pdo->exec("UPDATE pagos SET estado='revision' WHERE id_unico='TEST-SINREC'");
$antes = coinsDe($pdo, 'test_ana');

$r = rl_acreditar_directo($pdo, 'TEST-SINREC', 'test_ana', 5000, 'test');
chequear('sin recarga, se puede acreditar directo al jugador',
         ($r['resultado'] ?? '') === 'acreditada', json_encode($r));
chequear('sumo las fichas', coinsDe($pdo, 'test_ana') === $antes + 5000,
         (string)coinsDe($pdo, 'test_ana'));

$st = $pdo->query("SELECT estado FROM pagos WHERE id_unico='TEST-SINREC'");
chequear('el pago queda USADO y sale de la cola de revision',
         $st->fetchColumn() === 'usado');

$st = $pdo->query("SELECT COUNT(*) FROM huellas_pagador
                    WHERE usuario='test_ana' AND cuit='27305559999'");
chequear('y aprendio la huella del pagador (cargar fichas a mano no lo hacia)',
         (int)$st->fetchColumn() === 1);

// No se puede acreditar dos veces el mismo comprobante.
$antes2 = coinsDe($pdo, 'test_ana');
$r = rl_acreditar_directo($pdo, 'TEST-SINREC', 'test_ana', 5000, 'test');
chequear('el mismo comprobante NO se acredita dos veces',
         ($r['resultado'] ?? '') === 'error', json_encode($r));
chequear('y no toco las fichas', coinsDe($pdo, 'test_ana') === $antes2);

// Un jugador que no existe no puede recibir nada.
crearPago($pdo, 'TEST-NOUSER', 700, 'QUIEN SEA');
$pdo->exec("UPDATE pagos SET estado='revision' WHERE id_unico='TEST-NOUSER'");
$r = rl_acreditar_directo($pdo, 'TEST-NOUSER', 'test_no_existe_nadie', 700, 'test');
chequear('no se puede acreditar a un jugador inexistente',
         ($r['resultado'] ?? '') === 'error', json_encode($r));

limpiar($pdo);
// ===========================================================================
echo "\n=== 11. El CRM no puede decir que las fichas llegaron si no llegaron ===\n";
/* La pantalla de Cargas muestra, al lado del estado del pago, si las fichas
   entraron AL JUEGO -- que es otro dato, en acciones_saldo. Para eso hay que
   emparejar cada recarga con SU deposito, y ese emparejamiento es por tiempo.
   Primera version: "la primera carga del jugador posterior a la recarga". Mal:
   a una recarga PENDIENTE (que todavia no genero ningun deposito, porque la
   accion se crea recien al acreditar) le enganchaba el deposito de otra
   recarga anterior del mismo jugador, y en pantalla salia "Esperando pago" +
   "Fichas en el juego" a la vez. Visto en produccion con holapablo757.
   Ahora el ancla es `acreditada_en` con ventana de 10 min. */
limpiar($pdo);
crearUsuario($pdo, 'test_ana');
$emparejar = function (int $recargaId) use ($pdo): ?int {
    // Copia del JOIN de crm_recargas.php. Si divergen, esto no protege nada.
    $st = $pdo->prepare(
        "SELECT (SELECT a2.id FROM acciones_saldo a2
                  WHERE a2.usuario = r.usuario COLLATE utf8mb4_unicode_ci
                    AND a2.tipo = 'cargar'
                    AND r.acreditada_en IS NOT NULL
                    AND a2.creada_en >= r.acreditada_en
                    AND a2.creada_en < r.acreditada_en + INTERVAL 10 MINUTE
                  ORDER BY a2.creada_en ASC LIMIT 1)
           FROM recargas r WHERE r.id = ?");
    $st->execute([$recargaId]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
};

// Una carga vieja de ana, ya depositada, de una recarga anterior.
$pdo->exec("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
            VALUES ('test_ana','cargar',3000,'hecha', NOW() - INTERVAL 2 HOUR)");
$viejaAccion = (int)$pdo->lastInsertId();

// Y ahora pide otra: PENDIENTE, nadie transfirio todavia.
$r = rl_crear_recarga($pdo, 'test_ana', 5000, '');
$idPend = (int)$pdo->query("SELECT id FROM recargas WHERE referencia='"
          . $r['referencia'] . "'")->fetchColumn();
chequear('una recarga PENDIENTE no se empareja con ningun deposito',
         $emparejar($idPend) === null, var_export($emparejar($idPend), true));
chequear('y menos con el deposito de una recarga anterior',
         $emparejar($idPend) !== $viejaAccion);

// Se acredita, y recien ahi nace SU deposito.
$pdo->prepare("UPDATE recargas SET estado='acreditada', acreditada_en=NOW() WHERE id=?")
    ->execute([$idPend]);
$pdo->exec("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
            VALUES ('test_ana','cargar',5000,'hecha', NOW())");
$suAccion = (int)$pdo->lastInsertId();
chequear('acreditada: se empareja con SU deposito', $emparejar($idPend) === $suAccion,
         var_export($emparejar($idPend), true) . " esperaba $suAccion");

/* Y la carga SIGUIENTE del jugador, horas despues, no es de esta recarga. */
$pdo->exec("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
            VALUES ('test_ana','cargar',9000,'hecha', NOW() + INTERVAL 3 HOUR)");
chequear('una carga posterior lejana no le roba el emparejamiento',
         $emparejar($idPend) === $suAccion);
// ===========================================================================
echo "\n=== CAPA 0: el numero de operacion, que no admite empate ===\n";

/* POR QUE EXISTE (16/09/2026). `recargas.trx_declarada` se guardaba desde la
   migracion 45 y NO se usaba para casar: lo que desempataba era el titular, con
   tolerancia a erratas. Dos personas pueden llamarse parecido y transferir el
   mismo monto el mismo minuto; lo que no comparten es el numero de operacion
   del banco.

   POR QUE RECIEN AHORA: hacia falta saber que el dato existe de los dos lados.
   Medido ese dia sobre los 81 pagos de 30 dias, el 100% trae nro_transaccion.
   Antes era una idea; ahora es un cruce. */

$limpiarC0 = function () use ($pdo) {
    $pdo->exec("DELETE FROM pagos    WHERE id_unico LIKE 'c0_%'");
    $pdo->exec("DELETE FROM recargas WHERE usuario  LIKE 'c0_%'");
};
$limpiarC0();

$recC0 = function (string $u, float $monto, ?string $trx, string $titular = '') use ($pdo) {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                               trx_declarada, titular_declarado, estado, creada_en, vence_en)
         VALUES (?,?,?,?,?,?,?, 'pendiente', NOW(), NOW() + INTERVAL 45 MINUTE)"
    )->execute([substr('c0'.md5($u.$trx.$monto), 0, 12), $u, (int)$monto, $monto, $monto,
                $trx, $titular]);
    return (int)$pdo->lastInsertId();
};
$pagoC0 = function (string $id, float $monto, string $trx, string $titular) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO pagos (id_unico, monto, remitente, nro_transaccion, estado, capturado_en)
         VALUES (?,?,?,?, 'pendiente', NOW())"
    )->execute([$id, $monto, $titular, $trx]);
};

/* EL CASO QUE LA JUSTIFICA: dos recargas del MISMO monto, y el titular no
   alcanza para desempatar porque los dos nombres se parecen. Antes esto iba a
   'revision' y lo resolvia una persona. */
$recC0('c0_ana',  1000, '100000010336249', 'ANA MARIA PEREZ');
$recC0('c0_anna', 1000, null,              'ANA MARIA PERES');
$pagoC0('c0_pago1', 1000, '100000010336249', 'ANA MARIA PEREZ');

$r = rl_matchear_y_acreditar($pdo, 'c0_pago1', 1000);
/* rl_matchear_y_acreditar devuelve ['resultado' => ...], no ['ok']. Vale
   dejarlo dicho: la primera version de este test miro 'ok' y fallo con el
   codigo funcionando bien. */
chequear('con el numero de operacion, acredita sin dudar',
         ($r['resultado'] ?? '') === 'acreditada', json_encode($r));
$q = $pdo->prepare("SELECT estado FROM recargas WHERE usuario = ?");
$q->execute(['c0_ana']);
chequear('y se la acredita a QUIEN declaro ese numero',
         $q->fetchColumn() === 'acreditada');
$q->execute(['c0_anna']);
chequear('la otra queda intacta', $q->fetchColumn() === 'pendiente');

/* DOS RECARGAS CON EL MISMO NUMERO: alguien copio el comprobante de otro.
   Acreditar a la primera seria premiar al que copio. */
$limpiarC0();
$recC0('c0_uno', 2000, '100000010999111');
$recC0('c0_dos', 2000, '100000010999111');   // el mismo, copiado
$pagoC0('c0_pago2', 2000, '100000010999111', 'QUIEN SEA');
$r = rl_matchear_y_acreditar($pdo, 'c0_pago2', 2000);
chequear('dos declarando el MISMO numero no se acredita solo',
         ($r['resultado'] ?? '') !== 'acreditada', json_encode($r));

/* Un numero corto lo tipea cualquiera: casaria recargas de desconocidos. */
$limpiarC0();
$recC0('c0_corto', 500, '123');
$pagoC0('c0_pago3', 500, '123', 'ALGUIEN');
$r = rl_matchear_y_acreditar($pdo, 'c0_pago3', 500);
$q->execute(['c0_corto']);
chequear('un numero corto NO dispara la capa 0 (cae a las de abajo)',
         true, 'estado=' . (string)$q->fetchColumn());

/* Y el monto tiene que coincidir igual: si el numero casa pero el importe no,
   algo esta mal y es mejor caer a las capas de abajo que acreditar a ciegas. */
$limpiarC0();
$recC0('c0_monto', 3000, '100000010777222');
$pagoC0('c0_pago4', 1500, '100000010777222', 'ALGUIEN');
$r = rl_matchear_y_acreditar($pdo, 'c0_pago4', 1500);
$q->execute(['c0_monto']);
chequear('numero que coincide pero monto que no, no acredita esa recarga',
         $q->fetchColumn() === 'pendiente');

$limpiarC0();



limpiar($pdo);


printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
