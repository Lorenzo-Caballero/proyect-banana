<?php
/**
 * t_bono_app_claro.php — El bono por instalar la app, explicado sin ambigüedad.
 *
 * EL CASO REAL (19/09/2026, holadaianjauregui878). Cargó 1.000 a las 02:30,
 * leyó la invitación de la app, la instaló a las 02:39, entró con su cuenta —
 * que es exactamente lo que la invitación le pedía — y pasó 45 minutos
 * preguntando dónde estaban sus 1.000 fichas, hasta que lo atendió un agente.
 *
 * Tres cosas fallaban a la vez, y ninguna era el modelo inventando:
 *
 *   1. La INVITACIÓN del chat prometía las fichas *"solas, apenas entres con
 *      tu cuenta"*. El bono no funciona así: instalar lo deja reservado y lo
 *      paga la carga siguiente. El jugador tenía por escrito lo contrario de
 *      lo que el bot le decía, y le creía al texto.
 *   2. El bono de la app NO figuraba en BONOS PENDIENTES —vive en un marcador
 *      de `movimientos`, no en `bonos_pendientes`—, así que la lista que las
 *      reglas fijas le presentan al bot como "el dato exacto" no lo incluía.
 *      Lo único que lo mencionaba decía "YA ESTA ACTIVO", que el bot tradujo
 *      a "ya está cargado".
 *   3. Y el bot no tenía cómo saber que su carga fue ANTES de instalar. Le
 *      preguntaba "¿ya hiciste tu primera carga?", el jugador contestaba que
 *      sí —con razón— y la conversación se trababa ahí.
 *
 * Nada de esto lo agarra `php -l` ni un string-match: son números y fechas que
 * hay que cruzar. Por eso el caso se arma entero.
 *
 *     T_PORT=3399 php t_bono_app_claro.php
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
require_once __DIR__ . '/api/chatbot_contexto.php';

/* chatbot.php es un endpoint: se ejecuta al incluirlo. Se le saca sólo el
   bloque de funciones que interesa, igual que hacen otras suites con los
   archivos que no se pueden requerir. */
$src = file_get_contents(__DIR__ . '/api/chatbot.php');
foreach (['chatbot_bono_app_reservado', 'chatbot_bloque_bonos', 'chatbot_bloque_estado_app'] as $fn) {
    $i = strpos($src, 'function ' . $fn . '(');
    if ($i === false) { fwrite(STDERR, "no encontre $fn\n"); exit(1); }
    /* El cierre es la primera llave sola en columna 0 después del arranque —
       el estilo de todo el archivo. `strpos` desde $i y no desde 0: si no,
       encuentra el cierre de una función anterior. */
    $j = strpos($src, "\n}", $i);
    eval(substr($src, $i, $j - $i + 2));
}

$fallas = 0;
function ok(bool $c, string $m): void
{
    global $fallas;
    echo ($c ? '  OK   ' : '  FALLA ') . $m . "\n";
    if (!$c) { $fallas++; }
}

const U = 't_appclaro';
$limpiar = function () use ($pdo): void {
    foreach (['movimientos' => 'usuario', 'recargas' => 'usuario',
              'bonos_pendientes' => 'usuario', 'usuarios' => 'username'] as $tb => $col) {
        try { $pdo->prepare("DELETE FROM $tb WHERE $col = ?")->execute([U]); } catch (Throwable $e) {}
    }
};
$limpiar();
cfg_crm_guardar($pdo, ['app_promo_activa' => '1', 'app_bono_fichas' => '1000'], 'test');

$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones)
               VALUES (991300, ?, 0, 0, 0, 1, 1)")->execute([U]);

/* El marcador que deja la instalación: origen 'bono_app', monto 0. */
$marcar = function (string $hace) use ($pdo): void {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES (?, 'bono', 0, 'Bono de la app: espera su próxima carga', 'bono_app', NOW() - INTERVAL $hace)"
    )->execute([U]);
};
$cargar = function (string $hace, string $ref) use ($pdo): void {
    $pdo->prepare(
        "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado, referencia, creada_en, acreditada_en)
         VALUES (?, 1000, 1000.00, 1000.00, 'acreditada', ?, NOW() - INTERVAL $hace, NOW() - INTERVAL $hace)"
    )->execute([U, $ref]);
};

/* =========================================================================
   1. EL CASO DE DAIAN: cargó ANTES de instalar
   ========================================================================= */
echo "\n=== 1. Cargó ANTES de instalar: la carga vieja no lo cobra ===\n";
$cargar('60 MINUTE', 'TAC1');   // carga a las 02:30
$marcar('51 MINUTE');           // instala a las 02:39, nueve minutos DESPUES

$r = chatbot_bono_app_reservado($pdo, U);
ok($r['fichas'] === 1000, 've las 1.000 fichas reservadas, dio ' . var_export($r['fichas'], true));
ok($r['cargo_despues'] === false,
   'y sabe que la carga fue ANTES de instalar, asi que todavia no lo cobro');

$b = chatbot_bloque_bonos($pdo, U);
ok(str_contains($b, '1.000 fichas por instalar la app'),
   'el bono de la app FIGURA en BONOS PENDIENTES, con su monto');
ok(str_contains($b, 'RESERVADOS, no acreditados'),
   'la linea dice reservados y no acreditados');
ok(str_contains($b, 'no le digas que "ya estan cargados"')
   || str_contains($b, 'NO le digas que "ya estan cargados"'),
   'y le prohibe al bot decir que ya estan cargados');
ok(str_contains($b, 'DESPUES de instalarla') && str_contains($b, 'todavia no hizo ninguna'),
   'y le explica el paso exacto que falta, en vez de repetir la pregunta');

$e = chatbot_bloque_estado_app($pdo, U);
ok(str_contains($e, 'RESERVADO'), 'el bloque de la app dice RESERVADO');
ok(!str_contains($e, 'YA ESTA ACTIVO'),
   'y ya no dice solo "YA ESTA ACTIVO", que es lo que el bot tradujo a "ya esta cargado"');
ok(str_contains($e, 'PROXIMA') && str_contains($e, '1.000 fichas'),
   'con el monto y el momento en que entra');
ok(str_contains($e, 'tiene razon'),
   'y le pide que no trate de equivocado al que dice que ya cargo');

/* =========================================================================
   2. INSTALÓ Y DESPUÉS CARGÓ: ahí sí corresponde
   ========================================================================= */
echo "\n=== 2. Cargó DESPUES de instalar: ese es el disparador ===\n";
$cargar('10 MINUTE', 'TAC2');
$r = chatbot_bono_app_reservado($pdo, U);
ok($r['cargo_despues'] === true, 'ahora si reconoce la carga posterior');
$b = chatbot_bloque_bonos($pdo, U);
ok(!str_contains($b, 'todavia no hizo ninguna'),
   'y deja de decir que falta una carga');

/* =========================================================================
   3. YA COBRADO: no se promete de nuevo
   ========================================================================= */
echo "\n=== 3. Ya cobrado: se termina la historia ===\n";
$pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
               VALUES (?, 'bono', 1000, 'Bono por instalar la app', 'bono_app')")->execute([U]);
$r = chatbot_bono_app_reservado($pdo, U);
ok($r['fichas'] === 0, 'no queda nada reservado');
$b = chatbot_bloque_bonos($pdo, U);
ok(!str_contains($b, 'por instalar la app'), 'y sale de la lista de pendientes');
$e = chatbot_bloque_estado_app($pdo, U);
ok(str_contains($e, 'YA se le acredito'), 'el bloque de la app dice que ya lo cobro');

/* =========================================================================
   4. SIN PROMO: no se inventa un bono
   ========================================================================= */
echo "\n=== 4. Con la promo apagada no hay nada que prometer ===\n";
$limpiar();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus, tiene_app, notificaciones)
               VALUES (991300, ?, 0, 0, 0, 1, 1)")->execute([U]);
$marcar('30 MINUTE');
cfg_crm_guardar($pdo, ['app_bono_fichas' => '0'], 'test');
$r = chatbot_bono_app_reservado($pdo, U);
ok($r['fichas'] === 0, 'sin monto configurado no promete fichas');
cfg_crm_guardar($pdo, ['app_bono_fichas' => '1000'], 'test');

/* =========================================================================
   5. LA INVITACION DEL CHAT Y LA PUSH DICEN LO MISMO
   ========================================================================= */
echo "\n=== 5. Los dos mensajes coinciden entre si ===\n";
/* SIN COMENTARIOS. La frase vieja sigue citada en el comentario que explica
   por que se cambio --y tiene que seguir ahi, es la historia del bug--, asi
   que buscarla en el archivo crudo da un falso positivo. Mismo criterio que
   t_ip_cliente.php con REMOTE_ADDR: lo que importa es el codigo que corre. */
$rl = php_strip_whitespace(__DIR__ . '/api/recargas_lib.php');
ok(!str_contains($rl, 'apenas entres con tu cuenta'),
   'la invitacion ya NO promete las fichas al iniciar sesion');
ok(str_contains($rl, 'con tu próxima carga, la que hagas'),
   'sino con la carga siguiente a instalarla');
$nl = php_strip_whitespace(__DIR__ . '/api/notificaciones_lib.php');
ok(str_contains($nl, 'junto con tu próxima carga'),
   'y la push que le llega al instalar sigue diciendo lo mismo');

$limpiar();
printf("\n---------------------------------------\n%s\n",
       $fallas ? "$fallas FALLAS" : 'TODO OK');
exit($fallas > 0 ? 1 : 0);
