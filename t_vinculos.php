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

/* EL CELULAR DE UN BLOQUEADO (18/09/2026). Es la señal que corta la cadena en
   el caso real: el abusador creaba cuenta tras cuenta desde la MISMA
   instalación (3 cuentas con el mismo device_id en producción). */
$usuario('tv_malo2');
vin_anotar_dispositivo($pdo, 'dev-test-bloq', 'tv_malo2');
vin_bloquear($pdo, 'tv_malo2', true, 'nahuel', 'multicuenta');
chequear('el celular de un bloqueado frena el alta',
         vin_bloqueado_por_senal($pdo, ['device_id' => 'dev-test-bloq']) === 'tv_malo2');
chequear('otro celular no frena nada',
         vin_bloqueado_por_senal($pdo, ['device_id' => 'dev-test-otro']) === null);
$pdo->exec("DELETE FROM dispositivos_usuarios WHERE device_id = 'dev-test-bloq'");

/* Y EL FRENO TIENE QUE ESTAR CONECTADO. vin_bloqueado_por_senal() existió
   desde la migración 69 con el docblock "es el chequeo del alta nueva"... y
   NADIE la llamaba: el freno estaba escrito y desenchufado, y el bloqueado
   siguió abriendo cuentas. Posicional sobre el código, porque ningún test de
   comportamiento ve una función que nadie llama. */
$srcCrearCta = file_get_contents(__DIR__ . '/api/crear_cuenta.php');
$srcChatEndp = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('crear_cuenta.php (landing) llama al freno por señal',
         str_contains($srcCrearCta, 'vin_bloqueado_por_senal('));
chequear('el alta por chat también lo llama',
         str_contains($srcChatEndp, 'vin_bloqueado_por_senal('));
chequear('el chat corta al bloqueado antes de la IA',
         str_contains($srcChatEndp, 'vin_bloqueado($pdo, $usuarioCliente)'));
/* Sin el device_id del navegador la señal no existe: los cuatro fronts que
   crean cuentas o conversan lo tienen que mandar. */
chequear('el widget manda device_id en el turno del chat',
         str_contains(file_get_contents(__DIR__ . '/landing/widget.js'),
                      'device_id: ls("goldpaw_device")'));
foreach (['lp.html', 'bono.html', 'registro.html'] as $pag) {
    chequear("$pag manda el device con el alta",
             str_contains(file_get_contents(__DIR__ . '/landing/' . $pag),
                          "localStorage.getItem('goldpaw_device')"));
}

echo "\n=== 5g. Mas de DOS cuentas la misma persona = ni chat ===\n";

/* Pedido del dueño (18/09/2026): "que ni siquiera pueda hablar al chat si
   tiene mas de dos cuentas la misma persona". ES un corte automatico —
   decision del dueño que dio vuelta el "nada se bloquea solo" de este
   archivo; el costo (telefono compartido legitimo) lo absorbe el agente,
   porque el chat sigue entrando al CRM. */
$limpiar();
$usuario('tv_mc1'); $usuario('tv_mc2'); $usuario('tv_mc3');
vin_anotar_dispositivo($pdo, 'dev-mc', 'tv_mc1');
vin_anotar_dispositivo($pdo, 'dev-mc', 'tv_mc2');
chequear('DOS cuentas en el aparato no cortan nada',
         !vin_multicuenta_excedida($pdo, '', 'dev-mc'));
vin_anotar_dispositivo($pdo, 'dev-mc', 'tv_mc3');
chequear('la TERCERA corta el chat, incluso anonimo (asi opera el que abre cuentas)',
         vin_multicuenta_excedida($pdo, '', 'dev-mc'));
chequear('otro aparato sigue como si nada',
         !vin_multicuenta_excedida($pdo, '', 'dev-ajeno'));

$huella('tv_mc1', '20999888777', '');
$huella('tv_mc2', '20999888777', '');
$huella('tv_mc3', '20999888777', '');
chequear('tres cuentas pagando del MISMO banco cortan por usuario, sin device',
         vin_multicuenta_excedida($pdo, 'tv_mc1', ''));
chequear('max=0 lo apaga entero (MULTICUENTA_MAX en config)',
         !vin_multicuenta_excedida($pdo, 'tv_mc1', 'dev-mc', 0));
chequear('sin usuario y sin device no corta a nadie',
         !vin_multicuenta_excedida($pdo, '', ''));

/* Y el chat lo usa de verdad (posicional). */
chequear('chatbot.php corta al multicuenta antes de la IA',
         str_contains(file_get_contents(__DIR__ . '/api/chatbot.php'),
                      'vin_multicuenta_excedida('));

/* Ademas el CAMPO de escribir se deshabilita (18/09/2026, "que directamente
   no le permita enviar ni escribir"): mis_mensajes reporta chat_cerrado en
   cada sondeo y el widget cierra (y reabre) la entrada con eso. */
chequear('mis_mensajes.php reporta chat_cerrado',
         str_contains(file_get_contents(__DIR__ . '/api/mis_mensajes.php'),
                      "'chat_cerrado' => \$chatCerrado"));
$srcW2 = file_get_contents(__DIR__ . '/landing/widget.js');
chequear('el widget deshabilita la entrada al verlo',
         str_contains($srcW2, 'function chatEntradaCerrada(')
         && str_contains($srcW2, 'chat_cerrado'));
chequear('y el sondeo manda el device para cortar tambien al anonimo',
         str_contains($srcW2, '"&device=" + encodeURIComponent(ls("goldpaw_device")'));

echo "
=== 5b. La IP NO vincula a nadie, y eso es a proposito ===
";

/* ACA SE PROBABA QUE DOS CUENTAS DESDE LA MISMA IP SE VINCULABAN. Se dio vuelta
   el 16/09/2026 y vale entender por que, porque la version anterior de este
   bloque estaba bien razonada y aun asi protegia algo roto.

   El razonamiento viejo era: la IP es un indicio debil pero real, asi que se
   muestra etiquetada como debil y se descartan las IP con muchas cuentas
   (VIN_IP_MAX_CUENTAS), que son conexiones compartidas y no personas.

   Lo que ese razonamiento daba por sentado es que `altas.ip` tenia IPs de
   jugadores. NO LAS TENIA. El sitio quedo detras de Cloudflare y guardabamos el
   edge de la CDN:

       162.158.195.184   124 cuentas
       172.69.255.142     45 cuentas
       198.41.230.150     16 cuentas

   21 "IPs compartidas" tocando 237 cuentas, todas rangos de Cloudflare. El
   corte de VIN_IP_MAX_CUENTAS tapaba las peores; las de 2, 3 y 4 cuentas
   pasaban limpias y salian al CRM como "parecen ser la misma persona". Cuentas
   legitimas, acusadas por compartir un servidor de la CDN.

   Y el test no lo veia porque inventaba la IP: `$ponerAlta('tv_par1',
   '200.1.2.3')` escribe una IP de jugador perfecta, que en produccion no
   existia. UN TEST QUE SE FABRICA SUS DATOS NO PUEDE DESCUBRIR QUE LOS DATOS
   REALES SON OTRA COSA. Por eso ahora se chequea la ausencia de la señal, que
   es lo unico que no depende de que fixture se arme.

   `alta_ip()` ya quedo arreglado (api/ip_cliente.php), asi que la columna va a
   tener IPs de verdad. La señal no vuelve igual: una IP correcta sigue sin ser
   corroborable --familia, WiFi, NAT-- y el criterio es que el aviso solo diga
   cosas que el operador pueda poner sobre la mesa. */
$limpiar();
$ponerAlta = function (string $u, string $ip) use ($pdo) {
    $pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, ip, pedido_en)
                   VALUES (?, 'clave123456', 'ok', 'chatbot', ?, NOW())")->execute([$u, $ip]);
};

$usuario('tv_par1'); $usuario('tv_par2');
$ponerAlta('tv_par1', '200.1.2.3');
$ponerAlta('tv_par2', '200.1.2.3');
chequear('dos cuentas desde la misma IP NO se vinculan',
         vin_relacionados($pdo, 'tv_par1') === [],
         json_encode(vin_relacionados($pdo, 'tv_par1')));

chequear("y 'ip' ya no es una fuerza posible",
         !isset(VIN_FUERZA['ip']),
         'si vuelve, tiene que volver con una decision escrita al lado');

/* LO QUE NO SE LLEVO PUESTO. Sacar una señal es facil de hacer de mas: lo que
   importa es que las corroborables sigan intactas sobre los mismos datos. */
$huella('tv_par1', '20123123123', '');
$huella('tv_par2', '20123123123', '');
$v = vin_relacionados($pdo, 'tv_par1');
chequear('pero la cuenta bancaria sigue viendose igual',
         count($v) === 1 && $v[0]['usuario'] === 'tv_par2', json_encode($v));
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
echo "\n=== 5e. Bloquear a la PERSONA, no a una cuenta ===\n";

/* LA PREGUNTA (Nahuel, 16/09/2026, con el aviso ya andando en producción):
   *"es la misma persona. ¿Cómo se puede hacer efectivo un bloqueo?"*.

   Bloquear una de tres cuentas no hace nada: sigue operando con las otras dos.
   Un bloqueo que deja puertas abiertas no es un bloqueo, es una molestia. */
$limpiar();
$usuario('tv_g1'); $usuario('tv_g2'); $usuario('tv_g3'); $usuario('tv_vecino');

/* El grupo real: una comparte cuenta bancaria, otra el celular. */
$huella('tv_g1', '20777666555', '', 'MARIANELA LEIVA');
$huella('tv_g2', '20777666555', '', 'MARIANELA LEIVA');
vin_anotar_dispositivo($pdo, 'dev-grupo-9', 'tv_g1');
vin_anotar_dispositivo($pdo, 'dev-grupo-9', 'tv_g3');

/* El vecino SOLO comparte IP: no puede caer en la redada. */
$pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, ip, pedido_en)
               VALUES (?, 'clave123456', 'ok', 'chatbot', '190.5.5.5', NOW())")->execute(['tv_g1']);
$pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, ip, pedido_en)
               VALUES (?, 'clave123456', 'ok', 'chatbot', '190.5.5.5', NOW())")->execute(['tv_vecino']);

$r = vin_bloquear_grupo($pdo, 'tv_g1', true, 'nahuel', 'multicuenta');
chequear('el bloqueo en grupo devuelve ok', !empty($r['ok']), json_encode($r));

$tocadas = $r['usuarios'] ?? [];
sort($tocadas);
chequear('alcanza a la del mismo banco y a la del mismo celular',
         $tocadas === ['tv_g1', 'tv_g2', 'tv_g3'], json_encode($tocadas));
chequear('las tres quedan bloqueadas de verdad',
         vin_bloqueado($pdo, 'tv_g1') && vin_bloqueado($pdo, 'tv_g2')
         && vin_bloqueado($pdo, 'tv_g3'));

/* LO MÁS IMPORTANTE DE ESTA SECCIÓN. Arrastrar por IP bloquearía de una sola
   vez a todos los que comparten una conexión -- en la base real son quince
   cuentas, la mayoría ajenas. Un solo click y quince clientes afuera. */
chequear('el que solo comparte IP NO cae en la redada (lo importante de esta seccion)',
         vin_bloqueado($pdo, 'tv_vecino') === false);
chequear('y tampoco figura entre las tocadas',
         !in_array('tv_vecino', $tocadas, true), json_encode($tocadas));

/* Queda anotado que fue por arrastre y de quién: el que lea la ficha del g2
   dentro de un mes tiene que poder reconstruir por qué está bloqueado. */
$m = $pdo->query("SELECT bloqueado_motivo FROM usuarios WHERE username = 'tv_g2'")->fetchColumn();
chequear('la arrastrada dice a quién estaba vinculada',
         str_contains((string)$m, 'tv_g1'), (string)$m);

/* Y se puede deshacer entero: bloquear en grupo y desbloquear de a una sería
   una trampa para el operador que se equivocó. */
$r = vin_bloquear_grupo($pdo, 'tv_g1', false, 'nahuel');
chequear('desbloquear en grupo libera a las tres',
         !vin_bloqueado($pdo, 'tv_g1') && !vin_bloqueado($pdo, 'tv_g2')
         && !vin_bloqueado($pdo, 'tv_g3'), json_encode($r));

$pdo->exec("DELETE FROM dispositivos_usuarios WHERE device_id LIKE 'dev-%'");

// ===========================================================================
echo "\n=== 5f. El bloqueado se entera AL PRINCIPIO, no al final ===\n";

/* EL CASO (16/09/2026). A un jugador bloqueado el bot le contestó "Tenés 1.400
   fichas disponibles para retirar. ¿Querés sacar todo o una parte?", le pidió
   el CBU, le confirmó el monto -- y recién al aceptar apareció "hay un bloqueo
   en tu cuenta". Lo llevó por todo el camino para chocarlo contra la pared al
   final.

   Es malo para los dos lados: el jugador se enoja más cuanto más avanzó, y el
   operador hereda una discusión que no hacía falta. El freno de
   fichas_pedir_retiro sigue estando --es el que protege la plata-- pero el
   modelo tiene que saberlo ANTES de ofrecer nada. */
require_once __DIR__ . '/api/fichas_lib.php';
$limpiar();
$usuario('tv_bloq');
$pdo->prepare("UPDATE usuarios SET balance = 1400 WHERE username = 'tv_bloq'")->execute();

/* Sin bloqueo: la consulta responde normal y sin avisos. */
$c = fichas_consultar($pdo, 'tv_bloq');
chequear('sin bloqueo devuelve el saldo', (float)$c['saldo'] === 1400.0, json_encode($c));
chequear('y no inventa ningún aviso', ($c['aviso'] ?? '') === '', (string)($c['aviso'] ?? ''));
chequear('bloqueado viene en false', ($c['bloqueado'] ?? null) === false);

vin_bloquear($pdo, 'tv_bloq', true, 'nahuel', 'multicuenta');
$c = fichas_consultar($pdo, 'tv_bloq');

/* EL SALDO SE SIGUE DICIENDO. Preguntar cuánto tengo es inofensivo, y negarlo
   solo confirma que pasa algo raro -- que es justo lo que no se quiere. */
chequear('con bloqueo el saldo se sigue diciendo',
         !empty($c['ok']) && (float)$c['saldo'] === 1400.0, json_encode($c));
chequear('pero avisa que está bloqueado', ($c['bloqueado'] ?? null) === true);
chequear('y le dice al modelo que NO ofrezca retirar',
         str_contains((string)$c['aviso'], 'NO le ofrezcas retirar'), (string)$c['aviso']);

/* EL AVISO ES PARA EL MODELO Y NO PUEDE CONTAR EL MOTIVO. Quien abre tres
   cuentas aprende de cada mensaje que recibe: decirle "te detectamos por
   multicuenta" le enseña qué esconder la próxima vez. */
chequear('el aviso NO cuenta el motivo del bloqueo',
         !str_contains(mb_strtolower((string)$c['aviso']), 'multicuenta')
         && !str_contains(mb_strtolower((string)$c['aviso']), 'cuenta bancaria'),
         (string)$c['aviso']);

/* Y el freno de verdad sigue donde estaba: el aviso orienta al modelo, pero lo
   que protege la plata es que la operación no se pueda hacer. Un modelo que
   ignore el aviso igual choca contra esto. */
$r = fichas_pedir_retiro($pdo, 'tv_bloq', 500, 'chatbot');
chequear('el retiro sigue frenado aunque el modelo insista',
         empty($r['ok']) && ($r['codigo'] ?? '') === 'bloqueado', json_encode($r));
$r = fichas_pedir_carga($pdo, 'tv_bloq', 500, 'chatbot');
chequear('y la carga también', empty($r['ok']) && ($r['codigo'] ?? '') === 'bloqueado',
         json_encode($r));

/* Al desbloquear, todo vuelve a la normalidad sin dejar rastros en la consulta. */
vin_bloquear($pdo, 'tv_bloq', false, 'nahuel');
$c = fichas_consultar($pdo, 'tv_bloq');
chequear('desbloqueado vuelve a responder limpio',
         ($c['bloqueado'] ?? null) === false && ($c['aviso'] ?? '') === '');

// ===========================================================================
// ===========================================================================
echo "
=== 5e. Un bloqueado no hace sonar el Telegram ===
";

/* EL REPORTE (Nahuel, 16/09/2026): *"eliminá el mensaje molesto de Telegram que
   me llega a cada ratito sobre el jugador falso ese que supuestamente envió el
   dinero y no recibió las fichas. Nunca envió el dinero realmente, mandó varios
   comprobantes falsos con fecha y hora diferentes"*.

   Era `holajorge443` --una de las cinco cuentas de la misma persona, todas
   pagando desde la cuenta de DIEGO SANTILLAN--, ya bloqueado, repitiendo su
   reclamo cada pocos minutos.

   LO QUE HACE INTERESANTE ESTE BUG es que ningún freno estaba roto. El aviso de
   derivación ya tenía un límite de 5 minutos POR CHAT y lo respetaba perfecto.
   Lo que faltaba era una pregunta anterior: si ya decidimos no atender a esta
   persona, ¿por qué le pedimos a alguien que vuelva a decidirlo doce veces por
   hora? Un freno regula la frecuencia; no puede contestar si el aviso vale.

   NO LE SACA EL CHAT: la conversación sigue entrando al CRM --hace falta para
   ver qué está intentando, y para poder revertir un bloqueo equivocado--. Lo
   único que se corta es la interrupción. El CRM se mira, el Telegram te busca. */
$limpiar();
$usuario('tv_mudo');
chequear('sin bloquear, el aviso sale',
         vin_avisos_mudos($pdo, 'tv_mudo') === false);

vin_bloquear($pdo, 'tv_mudo', true, 'nahuel', 'comprobantes falsos');
chequear('bloqueado, el aviso se calla',
         vin_avisos_mudos($pdo, 'tv_mudo') === true);

vin_bloquear($pdo, 'tv_mudo', false, 'nahuel');
chequear('y al desbloquear vuelve a avisar',
         vin_avisos_mudos($pdo, 'tv_mudo') === false);

/* ANTE LA DUDA SE AVISA. Perder el aviso de alguien que SI hay que atender es
   peor que uno de mas: el de mas molesta, el que falta deja a un jugador
   esperando a nadie. */
chequear('un anonimo (sin usuario) NO se calla',
         vin_avisos_mudos($pdo, '') === false);
chequear('un usuario que no existe tampoco',
         vin_avisos_mudos($pdo, 'tv_no_existe_jamas') === false);

/* Y QUE LA GUARDA ESTE EN LOS TRES AVISOS del chat que llevan nombre de
   jugador, no solo en el que se reporto. Es posicional sobre el codigo porque
   ningun test de comportamiento ve que falte en uno de los tres --y el que
   falte va a ser justo el que suene a las 4 de la mañana--. chatbot.php no se
   puede requerir: es un endpoint, arranca una request al cargarlo. */
$srcCb = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('la guarda esta en los 3 avisos con jugador',
         substr_count($srcCb, 'vin_avisos_mudos($pdo') === 3,
         substr_count($srcCb, 'vin_avisos_mudos($pdo') . ' usos');
chequear('y chatbot.php carga vinculos_lib para poder llamarla',
         str_contains($srcCb, "require_once __DIR__ . '/vinculos_lib.php';"));

// ===========================================================================
echo "\n=== 5g. Bloqueo por IP: el ultimo recurso ===\n";

/* EL PEDIDO (Nahuel, 18/09/2026): *"ver si se puede bloquear a un jugador
   pesado por IP, para que no pueda hablar al chat ni siquiera"*.

   Cubre al unico que los otros dos cortes no alcanzan: el que no tiene cuenta
   NI app, llega por el navegador, molesta, borra el session_id y vuelve.

   ESTO NO SE PODIA HACER AYER. Hasta el 18/09 REMOTE_ADDR era el edge de
   Cloudflare --una sola "IP" con 124 cuentas-- asi que bloquear una habria
   dejado sin chat a todos los que entraran por ahi, sin un error visible. Lo
   habilita ip_cliente(). Y aun asi una IP no es una persona, de ahi el
   vencimiento por defecto y el radio. */
$IPX = "200.45.77.90";
$pdo->prepare("DELETE FROM bloqueos_ip WHERE ip = ?")->execute([$IPX]);

chequear('una IP limpia no esta bloqueada', vin_ip_bloqueada($pdo, $IPX) === null);

$r = vin_ip_bloquear($pdo, $IPX, true, 'nahuel', 'molesta en el chat', 24);
chequear('se puede bloquear', !empty($r['ok']), json_encode($r));
chequear('y queda bloqueada', vin_ip_bloqueada($pdo, $IPX) === 'molesta en el chat');
chequear('con vencimiento, no para siempre', !empty($r['hasta']), json_encode($r));

$r = vin_ip_bloquear($pdo, $IPX, false, 'nahuel');
chequear('se puede levantar', vin_ip_bloqueada($pdo, $IPX) === null);

/* EL VENCIMIENTO TIENE QUE VENCER DE VERDAD: si no, "24 horas" es un bloqueo
   permanente con otro nombre y nadie se entera hasta que alguien reclama. */
$pdo->prepare("DELETE FROM bloqueos_ip WHERE ip = ?")->execute([$IPX]);
$pdo->prepare("INSERT INTO bloqueos_ip (ip, motivo, hasta)
               VALUES (?, 'ya vencido', DATE_SUB(NOW(), INTERVAL 1 HOUR))")->execute([$IPX]);
chequear('un bloqueo vencido NO corta', vin_ip_bloqueada($pdo, $IPX) === null);

/* Lo que NO se puede bloquear, porque dejaria al CRM hablando solo: las altas
   hechas por script llevan 127.0.0.1. */
$r = vin_ip_bloquear($pdo, '127.0.0.1', true, 'nahuel', 'x', 1);
chequear('no deja bloquear el loopback', empty($r['ok']), json_encode($r));
$r = vin_ip_bloquear($pdo, 'no-soy-una-ip', true, 'nahuel', 'x', 1);
chequear('ni una IP invalida', empty($r['ok']), json_encode($r));

/* EL RADIO: cuanta gente se lleva puesta. Es lo que el CRM muestra ANTES de
   que el operador apriete, y la diferencia entre bloquear a un pesado y
   bloquear a cinco que no hicieron nada. */
$pdo->prepare("DELETE FROM altas WHERE ip = ?")->execute(["200.45.77.91"]);
foreach (['tv_ip_a','tv_ip_b'] as $u) {
    $usuario($u);
    $pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, ip, pedido_en)
                   VALUES (?, 'clave123456', 'ok', 'landing', '200.45.77.91', NOW())")->execute([$u]);
}
chequear('el radio cuenta las cuentas de esa IP',
         vin_ip_cuantas_cuentas($pdo, '200.45.77.91') === 2,
         (string)vin_ip_cuantas_cuentas($pdo, '200.45.77.91'));
$pdo->prepare("DELETE FROM bloqueos_ip WHERE ip LIKE '200.45.%'")->execute();
$pdo->prepare("DELETE FROM altas WHERE ip = ?")->execute(["200.45.77.91"]);

echo "\n=== 5h. El boton de bloquear ofrece las opciones ===\n";

/* Nahuel (18/09/2026): *"si hay alguna otra opcion --bloqueo permanente, o
   por 24 horas, o bloquear con IP-- que nos de la opcion al momento de
   apretar el boton de bloquear"*.

   Antes eran un prompt() para el motivo y uno o dos confirm() encadenados: se
   podia bloquear la cuenta y arrastrar las vinculadas, pero las opciones no se
   veian juntas y la IP no existia como opcion. */
$crm = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('el bloqueo tiene su propio modal', str_contains($crm, 'backBloq'));
chequear('y ya no lo resuelve un prompt() del navegador',
         !str_contains($crm, 'prompt(`Bloquear a'),
         'un prompt no deja ver las opciones juntas');

chequear('opcion: arrastrar las cuentas vinculadas', str_contains($crm, 'bqVinc'));
chequear('opcion: bloquear tambien la IP',           str_contains($crm, 'bqIp'));

/* Las tres duraciones, que es lo que se pidio. Sin "sin vencimiento" el
   bloqueo permanente no se puede poner desde la pantalla. */
foreach ([['24', '24 horas'], ['72', '3 dias'], ['0', 'sin vencimiento']] as $d) {
    chequear('duracion ' . $d[1], str_contains($crm, 'data-h="' . $d[0] . '"'));
}

/* EL RADIO SE PIDE ANTES DE OFRECER LA IP. Una IP la comparten una familia o
   un WiFi: "alcanza a 4 cuentas" es el dato que decide si conviene. Sin esto
   la opcion estaria igual de disponible pero a ciegas. */
chequear('se consulta el radio de la IP antes de ofrecerla',
         str_contains($crm, 'ip_radio'),
         'sin el radio, bloquear una IP es a ciegas');
chequear('y se avisa cuando alcanza a varias cuentas',
         str_contains($crm, 'puede ser una familia o un WiFi compartido'));

/* La IP se manda APARTE del bloqueo de la cuenta: son dos tablas y dos
   decisiones. Si la IP falla, el bloqueo de la cuenta ya quedo hecho. */
chequear('la IP se bloquea con su propia accion',
         str_contains($crm, 'accion:"bloquear_ip"'));

/* Y que el backend tenga las dos acciones que la pantalla usa. */
$srcCrm = file_get_contents(__DIR__ . '/api/crm.php');
chequear('el server atiende ip_radio y bloquear_ip',
         str_contains($srcCrm, "'ip_radio'") && str_contains($srcCrm, "'bloquear_ip'"));

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
