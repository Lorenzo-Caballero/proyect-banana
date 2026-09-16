<?php
/**
 * t_vinculos.php — Cuándo dos cuentas son la misma persona, y qué corta un bloqueo.
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026): *"descubrí que holasofito763,
 * holajuan969 y holaleiva89 son la misma persona"*. Lo descubrió a mano.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es lo que duele si se rompe:
 *
 *  1. **Que el bloqueo NO se escriba en `is_banned`.** Esa columna es el espejo
 *     del baneo de la plataforma y `usuarios_sync.php` la pisa cada 5 minutos.
 *     Un bloqueo escrito ahí desaparece solo, sin error y sin rastro — el peor
 *     tipo de bug: el operador cree que bloqueó y el jugador sigue jugando.
 *
 *  2. **Que las señales no se traten como iguales.** La cuenta bancaria es
 *     fuerte; la IP compartida no prueba nada. Si la IP pesara lo mismo,
 *     bloquear "al que está vinculado" se llevaría puestos a dos hermanos que
 *     juegan del mismo WiFi.
 *
 *  3. **Que los vacíos no aten a nadie.** Un `cuit = ''` matcheando contra otro
 *     `cuit = ''` acusa de multicuenta a todos los que no informaron nada. Es
 *     el error clásico de este tipo de cruce y es silencioso.
 *
 * Corre contra la base de prueba. Limpia lo suyo.
 *
 *     php t_vinculos.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
require_once __DIR__ . '/api/vinculos_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* La migración 69 tiene que estar corrida en la base de prueba. Si no está, se
   dice con todas las letras en vez de dar 20 fallas sin explicación. */
try { $pdo->query("SELECT bloqueado FROM usuarios LIMIT 0"); }
catch (Throwable $e) {
    fwrite(STDERR, "Falta la migración 69 en la base de prueba:\n"
                 . "  mysql ... < api/sql/69_bloqueo_vinculos.sql\n");
    exit(1);
}

$limpiar = function () use ($pdo) {
    foreach (['usuarios' => 'username', 'huellas_pagador' => 'usuario',
              'dispositivos_usuarios' => 'usuario', 'altas' => 'usuario'] as $tabla => $col) {
        try { $pdo->exec("DELETE FROM $tabla WHERE $col LIKE 'tv_%'"); } catch (Throwable $e) {}
    }
};
$limpiar();

$usuario = function (string $u) use ($pdo) {
    $pdo->prepare("INSERT INTO usuarios (id, username, coins) VALUES (?,?,0)
                   ON DUPLICATE KEY UPDATE coins = 0")->execute([crc32($u), $u]);
};
$huella = function (string $u, string $cuit, string $cbu = '', string $nombre = '') use ($pdo) {
    $pdo->prepare("INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE usos = usos + 1")->execute([$u, $cuit, $cbu, $nombre]);
};

// ===========================================================================
echo "\n=== 1. El bloqueo NO puede vivir en is_banned ===\n";

/* ESTO ES LO PRIMERO POR ALGO. `is_banned` ya existía y era lo obvio para
   reusar. Usarla habría sido un bug invisible: el sync la pisa cada 5 minutos
   con lo que diga la plataforma, así que el bloqueo se borra solo. El operador
   cree que bloqueó, el jugador sigue jugando, y nada falla en ningún log. */
$usuario('tv_uno');
$pdo->prepare("UPDATE usuarios SET is_banned = 0 WHERE username = 'tv_uno'")->execute();

$r = vin_bloquear($pdo, 'tv_uno', true, 'nahuel', 'abrió tres cuentas');
chequear('bloquear devuelve ok', !empty($r['ok']), json_encode($r));
chequear('queda bloqueado', vin_bloqueado($pdo, 'tv_uno') === true);

$f = $pdo->query("SELECT is_banned, bloqueado, bloqueado_por, bloqueado_motivo
                    FROM usuarios WHERE username = 'tv_uno'")->fetch();
chequear('se escribió en `bloqueado`', (int)$f['bloqueado'] === 1);
chequear('y NO se tocó `is_banned` (la pisa el sync cada 5 min)',
         (int)$f['is_banned'] === 0, 'is_banned=' . $f['is_banned']);
chequear('queda quién lo bloqueó', $f['bloqueado_por'] === 'nahuel');
chequear('y por qué', $f['bloqueado_motivo'] === 'abrió tres cuentas');

/* Simular la pasada del espejo: pisa is_banned y NO puede llevarse el bloqueo. */
$pdo->prepare("UPDATE usuarios SET is_banned = 0, balance = 100 WHERE username = 'tv_uno'")->execute();
chequear('sobrevive a una pasada del espejo', vin_bloqueado($pdo, 'tv_uno') === true);

/* Al desbloquear se limpia el motivo: si quedara, la ficha seguiría diciendo
   "bloqueado por nahuel: abrió tres cuentas" sobre alguien que ya no lo está. */
vin_bloquear($pdo, 'tv_uno', false, 'nahuel');
$f = $pdo->query("SELECT bloqueado, bloqueado_por, bloqueado_motivo
                    FROM usuarios WHERE username = 'tv_uno'")->fetch();
chequear('desbloquear lo deja libre', vin_bloqueado($pdo, 'tv_uno') === false);
chequear('y borra el motivo, que si no se lee como que sigue bloqueado',
         $f['bloqueado_motivo'] === null && $f['bloqueado_por'] === null);

chequear('un usuario que no existe no se puede bloquear',
         empty(vin_bloquear($pdo, 'tv_fantasma', true, 'nahuel')['ok']));

// ===========================================================================
echo "\n=== 2. La señal fuerte: la misma cuenta bancaria ===\n";
$limpiar();
foreach (['tv_sofito', 'tv_juan', 'tv_leiva', 'tv_ajeno'] as $u) { $usuario($u); }

/* El caso real: tres cuentas pagando desde el mismo CUIT. */
$huella('tv_sofito', '20304050607', '', 'JUAN PEREZ');
$huella('tv_juan',   '20304050607', '', 'JUAN PEREZ');
$huella('tv_leiva',  '20304050607', '', 'JUAN PEREZ');
$huella('tv_ajeno',  '27111222333', '', 'OTRA PERSONA');

$v = vin_relacionados($pdo, 'tv_sofito');
$nombres = array_column($v, 'usuario');
sort($nombres);
chequear('encuentra las otras dos', $nombres === ['tv_juan', 'tv_leiva'], json_encode($nombres));
chequear('no se incluye a sí mismo', !in_array('tv_sofito', $nombres, true));
chequear('no arrastra a quien paga desde otra cuenta', !in_array('tv_ajeno', $nombres, true));
chequear('la marca como señal fuerte', (int)$v[0]['fuerza'] === VIN_FUERZA['pago'], (string)$v[0]['fuerza']);
chequear('y dice de dónde sale, no solo que existe',
         str_contains($v[0]['detalle'], 'misma cuenta bancaria'), $v[0]['detalle']);

/* Por CBU también: un comprobante puede traer uno, el otro o los dos. */
$limpiar();
$usuario('tv_a'); $usuario('tv_b');
$huella('tv_a', '', '0001112223334445556667');
$huella('tv_b', '', '0001112223334445556667');
chequear('también cruza por CBU', count(vin_relacionados($pdo, 'tv_a')) === 1);

// ===========================================================================
echo "\n=== 3. Los vacíos NO atan a nadie ===\n";
/* EL ERROR CLÁSICO de este cruce, y silencioso: si '' matchea con '', todos los
   que nunca informaron CUIT quedan vinculados entre sí. Con una base real eso
   es acusar de multicuenta a media cartera. */
$limpiar();
$usuario('tv_x'); $usuario('tv_y'); $usuario('tv_z');
$huella('tv_x', '', '');
$huella('tv_y', '', '');
$huella('tv_z', '20999888777', '');
chequear('dos sin CUIT ni CBU no quedan vinculados',
         vin_relacionados($pdo, 'tv_x') === [], json_encode(vin_relacionados($pdo, 'tv_x')));

// ===========================================================================
echo "\n=== 4. El mismo celular ===\n";
$limpiar();
$usuario('tv_cel1'); $usuario('tv_cel2');
vin_anotar_dispositivo($pdo, 'dev-test-001', 'tv_cel1');
vin_anotar_dispositivo($pdo, 'dev-test-001', 'tv_cel2');
vin_anotar_dispositivo($pdo, 'dev-test-001', 'tv_cel1');   // repetido: suma usos, no duplica

$v = vin_relacionados($pdo, 'tv_cel1');
chequear('encuentra la otra cuenta del mismo aparato', count($v) === 1, json_encode($v));
chequear('el celular es señal fuerte pero menos que el banco',
         VIN_FUERZA['dispositivo'] < VIN_FUERZA['pago']);
$n = (int)$pdo->query("SELECT usos FROM dispositivos_usuarios
                        WHERE device_id='dev-test-001' AND usuario='tv_cel1'")->fetchColumn();
chequear('anotar dos veces suma usos en vez de duplicar', $n === 2, "usos=$n");
$pdo->exec("DELETE FROM dispositivos_usuarios WHERE device_id = 'dev-test-001'");

// ===========================================================================
echo "\n=== 5. Qué frena un alta nueva (y qué NO) ===\n";
$limpiar();
$usuario('tv_malo'); $usuario('tv_bueno');
$huella('tv_malo', '20555444333', '');
vin_bloquear($pdo, 'tv_malo', true, 'nahuel', 'multicuenta');

chequear('la cuenta bancaria de un bloqueado frena el alta',
         vin_bloqueado_por_senal($pdo, ['cuit' => '20555444333']) === 'tv_malo');
chequear('un CUIT cualquiera no frena nada',
         vin_bloqueado_por_senal($pdo, ['cuit' => '20000000001']) === null);
chequear('vacíos no frenan nada',
         vin_bloqueado_por_senal($pdo, ['cuit' => '', 'cbu' => '', 'device_id' => '']) === null);

/* LA IP NO SE ACEPTA A PROPÓSITO, y es la decisión más importante de todo el
   archivo. Frenar altas por IP deja afuera al hermano, al vecino y a medio
   barrio detrás del NAT de la telefónica: se pierden clientes reales para
   atajar a uno falso. Para eso está el aviso, que lo mira una persona. */
chequear('la IP NO es una señal que frene un alta',
         !in_array('ip', VIN_SENALES_DURAS, true));
chequear('pasarla como señal no bloquea igual',
         vin_bloqueado_por_senal($pdo, ['ip' => '1.2.3.4']) === null);

/* Un vinculado que NO está bloqueado tampoco frena: se bloquea a una persona,
   no a un grupo entero por parecerse. */
$huella('tv_bueno', '20555444333', '');
vin_bloquear($pdo, 'tv_malo', false, 'nahuel');
chequear('sin nadie bloqueado, la misma cuenta bancaria no frena nada',
         vin_bloqueado_por_senal($pdo, ['cuit' => '20555444333']) === null);

echo "\n=== 5b. Una IP con muchas cuentas no describe a una persona ===\n";

/* MEDIDO EN PRODUCCIÓN el 16/09/2026, primera corrida del script: a
   @holasofito763 lo vinculó con QUINCE cuentas por IP, entre ellas varias de
   prueba evidentes (holaTesttet262, holaTeeettttgf695) y tres altas del chat de
   esa misma madrugada. No es una persona con quince cuentas: es una IP por la
   que pasan todos.

   Sin este corte el aviso se vuelve ruido --si todos están vinculados con
   todos, no dice nada-- y encima invita a bloquear inocentes. */
$limpiar();
$ponerAlta = function (string $u, string $ip) use ($pdo) {
    $pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, ip, pedido_en)
                   VALUES (?, 'clave123456', 'ok', 'chatbot', ?, NOW())")->execute([$u, $ip]);
};

/* Dos cuentas desde la misma IP: eso SÍ es un indicio. */
$usuario('tv_par1'); $usuario('tv_par2');
$ponerAlta('tv_par1', '200.1.2.3');
$ponerAlta('tv_par2', '200.1.2.3');
$v = vin_relacionados($pdo, 'tv_par1');
chequear('dos cuentas desde la misma IP sí se vinculan', count($v) === 1, json_encode($v));
chequear('y va marcada como indicio débil, no como señal fuerte',
         (int)($v[0]['fuerza'] ?? 0) === VIN_FUERZA['ip'], (string)($v[0]['fuerza'] ?? '?'));

/* La misma IP, pero con más cuentas de las que tiene una familia: deja de
   contar entera, también para los dos primeros. */
foreach (range(3, 9) as $i) {
    $usuario("tv_par$i");
    $ponerAlta("tv_par$i", '200.1.2.3');
}
chequear('con 9 cuentas, esa IP deja de vincular a nadie',
         vin_relacionados($pdo, 'tv_par1') === [],
         json_encode(array_column(vin_relacionados($pdo, 'tv_par1'), 'usuario')));

/* Y no se lleva puestas a las otras señales: el que además comparte cuenta
   bancaria sigue apareciendo, que es lo que de verdad importa. */
$huella('tv_par1', '20123123123', '');
$huella('tv_par5', '20123123123', '');
$v = vin_relacionados($pdo, 'tv_par1');
chequear('pero la cuenta bancaria sigue viéndose igual',
         count($v) === 1 && $v[0]['usuario'] === 'tv_par5', json_encode($v));
chequear('y esa sí es señal fuerte', (int)($v[0]['fuerza'] ?? 0) === VIN_FUERZA['pago']);

// ===========================================================================
echo "\n=== 5c. El mismo comprobante desde dos cuentas ===\n";

/* CÓMO SE DESCUBRIÓ (Nahuel, 16/09/2026): *"me di cuenta porque mandó un
   comprobante con los mismos datos, el mismo desde varias cuentas"*.

   Es la señal MÁS fuerte de todas, y no porque identifique mejor a una persona
   sino porque no tiene lectura inocente: compartir banco puede ser una familia,
   pero declarar el MISMO número de operación desde dos cuentas es reclamar la
   misma transferencia dos veces.

   `pagos.id_unico` es UNIQUE, así que el banco acredita una sola vez. El riesgo
   real es que un operador vea el comprobante en el CRM y lo asigne a mano sin
   saber que ya se usó -- por eso hace falta que se VEA. */
$limpiar();
$usuario('tv_tramposo1'); $usuario('tv_tramposo2'); $usuario('tv_honesto');

$recarga = function (string $u, string $trx) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                               trx_declarada, estado, creada_en, vence_en)
         VALUES (?, ?, 1280, 1280, 1280, ?, 'pendiente', NOW(), NOW() + INTERVAL 45 MINUTE)"
    )->execute([substr('tv' . md5($u . $trx), 0, 12), $u, $trx]);
};

$recarga('tv_tramposo1', '100000010277176');
$recarga('tv_tramposo2', '100000010277176');   // el MISMO comprobante
$recarga('tv_honesto',   '100000010999999');

$v = vin_relacionados($pdo, 'tv_tramposo1');
chequear('encuentra a quien declaró la misma transferencia',
         count($v) === 1 && $v[0]['usuario'] === 'tv_tramposo2', json_encode($v));
chequear('es la señal más fuerte de todas',
         (int)$v[0]['fuerza'] === VIN_FUERZA['comprobante']);
chequear('más que compartir cuenta bancaria',
         VIN_FUERZA['comprobante'] > VIN_FUERZA['pago']);
chequear('y dice QUÉ operación, para poder ir a mirarla',
         str_contains($v[0]['detalle'], '100000010277176'), $v[0]['detalle']);
chequear('el que declaró otra cosa no aparece',
         vin_relacionados($pdo, 'tv_honesto') === []);

/* Un número corto lo tipea cualquiera: "1", "123" atarían a desconocidos. */
$limpiar();
$usuario('tv_c1'); $usuario('tv_c2');
$recarga('tv_c1', '123');
$recarga('tv_c2', '123');
chequear('un número de operación corto NO vincula a nadie',
         vin_relacionados($pdo, 'tv_c1') === [],
         json_encode(vin_relacionados($pdo, 'tv_c1')));

$pdo->exec("DELETE FROM recargas WHERE usuario LIKE 'tv_%'");

// ===========================================================================
echo "\n=== 5d. El bono de la app, una vez por CELULAR ===\n";

/* EL AGUJERO (16/09/2026). El candado del bono es por `usuario`, así que una
   cuenta nueva = un bono nuevo: registrarse, instalar la app, cobrar, repetir.
   La condición de la primera carga lo encarece pero no lo cierra -- con mínimo
   1.280 y bono 1.000, repetir la vuelta sigue conviniendo.

   El celular sí lo cierra, porque es lo único que no se multiplica gratis.

   Se prueba la CONSULTA que decide, no notif_app_instalada() entero: esa
   función depende de config_crm, de la promo prendida y del candado tiene_app,
   y lo que puede romperse acá es el cruce. */
$limpiar();
$usuario('tv_app1'); $usuario('tv_app2'); $usuario('tv_appsolo');
vin_anotar_dispositivo($pdo, 'dev-trampa-1', 'tv_app1');
vin_anotar_dispositivo($pdo, 'dev-trampa-1', 'tv_app2');
vin_anotar_dispositivo($pdo, 'dev-otro-2',   'tv_appsolo');

$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
               VALUES ('tv_app1', 'bono', 1000, 'Bono por instalar la app', 'bono_app')")->execute();

$yaCobro = function (string $u) use ($pdo) {
    $q = $pdo->prepare(
        "SELECT o.usuario
           FROM dispositivos_usuarios d
           JOIN dispositivos_usuarios o
             ON o.device_id = d.device_id AND o.usuario <> d.usuario
           JOIN movimientos m
             ON m.usuario = o.usuario AND m.origen = 'bono_app' AND m.monto > 0
          WHERE d.usuario = ? LIMIT 1"
    );
    $q->execute([$u]);
    return (string)($q->fetchColumn() ?: '');
};

chequear('la segunda cuenta del mismo celular NO puede cobrarlo otra vez',
         $yaCobro('tv_app2') === 'tv_app1', $yaCobro('tv_app2'));
chequear('el que ya cobró no se bloquea a sí mismo', $yaCobro('tv_app1') === '');
chequear('otro celular cobra normal', $yaCobro('tv_appsolo') === '');

/* EL MARCADOR NO CUENTA COMO COBRO. Quien instaló antes de cargar deja una fila
   de monto 0 esperando su primera carga: si eso contara, el bono se le negaría
   a la persona equivocada -- a la que todavía no cobró nada. */
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
               VALUES ('tv_appsolo', 'bono', 0, 'Bono de la app: espera su primera carga', 'bono_app')")->execute();
$usuario('tv_appvecino');
vin_anotar_dispositivo($pdo, 'dev-otro-2', 'tv_appvecino');
chequear('un marcador pendiente (monto 0) no bloquea a nadie',
         $yaCobro('tv_appvecino') === '', $yaCobro('tv_appvecino'));

$pdo->exec("DELETE FROM dispositivos_usuarios WHERE device_id LIKE 'dev-%'");
$pdo->exec("DELETE FROM movimientos WHERE usuario LIKE 'tv_%'");

// ===========================================================================
// ===========================================================================
echo "\n=== 6. Nada de esto puede tumbar una ficha ===\n";
/* vin_relacionados corre al abrir CADA conversación del CRM. Un vínculo que no
   se pudo calcular no puede impedir que el operador vea a su jugador. */
$limpiar();
chequear('un usuario sin nada devuelve lista vacía, no error',
         vin_relacionados($pdo, 'tv_inexistente') === []);
chequear('usuario vacío tampoco explota', vin_relacionados($pdo, '') === []);
chequear('vin_bloqueado de un inexistente es false', vin_bloqueado($pdo, 'tv_nadie') === false);

$limpiar();
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
