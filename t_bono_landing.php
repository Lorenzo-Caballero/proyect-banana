<?php
/**
 * t_bono_landing.php — cada landing con SU bono de bienvenida, y el bot sin
 * prometer lo que no se va a pagar.
 *
 * EL PEDIDO (Nahuel, 18/09/2026): *"que se pueda configurar diferentes bonos de
 * bienvenida según la landing... si tenemos una que ofrece 50%, otra 30% y otra
 * que no ofrece ninguno, queremos ver los diferentes resultados y analizarlos,
 * ver cómo varían nuestros costos"*.
 *
 * Y el riesgo que anticipó él mismo, que es lo que este archivo cuida: *"no
 * quiero que una persona venga desde una landing que no tiene bono y el bot le
 * diga: tenés un 50% de bono de bienvenida. El bot debe consultar antes eso."*
 *
 * LA MITAD QUE YA ESTABA. Acreditar ya era por landing desde hace tiempo
 * (`landings.bono_pct`). Lo que faltaba era que el bot pudiera consultarlo: el
 * porcentaje que decía salía del texto libre que el operador escribe en las
 * indicaciones, uno solo para todos. O sea que el que promete y el que paga
 * leían cosas distintas, y con dos landings con bonos distintos eso era una
 * promesa incumplida garantizada.
 *
 * Ahora los dos llaman a `rl_bono_bienvenida_pct()`. Este archivo prueba que
 * las dos puntas den lo mismo, que es lo único que importa.
 *
 *     php t_bono_landing.php
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
require_once __DIR__ . '/api/landings_lib.php';
require_once __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// ---- tres landings: una con 50, una con 30, una sin bono ------------------
$SLUGS = ['t_lp_50', 't_lp_30', 't_lp_0'];
$USUS  = ['t_bl_cincuenta', 't_bl_treinta', 't_bl_cero', 't_bl_chat', 't_bl_panel'];
$limpiar = function () use ($pdo, $SLUGS, $USUS): void {
    foreach ($SLUGS as $s) { $pdo->prepare("DELETE FROM landings WHERE slug = ?")->execute([$s]); }
    foreach ($USUS as $u) {
        $pdo->prepare("DELETE FROM altas WHERE usuario = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM recargas WHERE usuario = ?")->execute([$u]);
    }
};
$limpiar();

foreach ([['t_lp_50', 50], ['t_lp_30', 30], ['t_lp_0', 0]] as [$slug, $pct]) {
    $pdo->prepare("INSERT INTO landings (slug, nombre, bono_pct, activa) VALUES (?,?,?,1)")
        ->execute([$slug, 'test ' . $pct, $pct]);
}
/* El alta es lo que ata al jugador con su promo: `origen = 'lp:<slug>'`. Es el
   único lado donde consta por dónde entró. */
$altaOk = function (string $usuario, string $origen) use ($pdo): void {
    $pdo->prepare("INSERT INTO altas (usuario, origen, estado, creado_en_panel)
                   VALUES (?,?, 'ok', 1)")->execute([$usuario, $origen]);
};
$altaOk('t_bl_cincuenta', 'lp:t_lp_50');
$altaOk('t_bl_treinta',   'lp:t_lp_30');
$altaOk('t_bl_cero',      'lp:t_lp_0');
$altaOk('t_bl_chat',      'chatbot');
$altaOk('t_bl_panel',     'panel');

cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '50'], 'test');

// ===========================================================================
echo "\n=== 1. Cada landing paga LO SUYO ===\n";

chequear('la de 50% da 50', rl_bono_bienvenida_pct($pdo, 't_bl_cincuenta') === 50);
chequear('la de 30% da 30', rl_bono_bienvenida_pct($pdo, 't_bl_treinta') === 30,
         'es el punto del pedido: poder comparar costos entre promos');
chequear('la que no da bono, da 0', rl_bono_bienvenida_pct($pdo, 't_bl_cero') === 0,
         'y 0 tiene que ser 0, no caer al general del casino');

echo "\n=== 2. Y los que no vienen de una landing ===\n";
chequear('el que se creo la cuenta por el chat lleva el bono general',
         rl_bono_bienvenida_pct($pdo, 't_bl_chat') === 50,
         'el bot le promete el bono al crear la cuenta: hay que pagarlo');
chequear('el que lo creo un operador en el panel no lleva ninguno',
         rl_bono_bienvenida_pct($pdo, 't_bl_panel') === 0,
         'ese camino no promete nada: darselo seria regalar fichas sin querer');
chequear('y un usuario que no existe tampoco',
         rl_bono_bienvenida_pct($pdo, 't_bl_fantasma') === 0);

echo "\n=== 3. Cambiar el bono de la landing cambia lo que se paga ===\n";
/* Es lo que permite el experimento: mover el porcentaje de UNA promo sin tocar
   las otras ni el general. */
$pdo->prepare("UPDATE landings SET bono_pct = 20 WHERE slug = 't_lp_30'")->execute();
chequear('bajarla a 20 se refleja al instante',
         rl_bono_bienvenida_pct($pdo, 't_bl_treinta') === 20);
$pdo->prepare("UPDATE landings SET bono_pct = 0 WHERE slug = 't_lp_30'")->execute();
chequear('y apagarla del todo deja al jugador sin bono',
         rl_bono_bienvenida_pct($pdo, 't_bl_treinta') === 0);
$pdo->prepare("UPDATE landings SET bono_pct = 30 WHERE slug = 't_lp_30'")->execute();

echo "\n=== 4. El general NO pisa al de la landing ===\n";
/* El error facil: que apagar o subir el bono general del casino se cuele en
   las landings y arruine la comparacion. */
cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '0'], 'test');
chequear('con el general en 0, la landing de 50 sigue dando 50',
         rl_bono_bienvenida_pct($pdo, 't_bl_cincuenta') === 50);
chequear('pero el del chat, que SI usa el general, queda en 0',
         rl_bono_bienvenida_pct($pdo, 't_bl_chat') === 0);
cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '50'], 'test');

// ===========================================================================
echo "\n=== 5. El que PROMETE lee lo mismo que el que PAGA ===\n";

/* Esta es la invariante del pedido. Si estas dos puntas se separan, el bot
   promete un bono que despues no se acredita -- y eso es peor que no ofrecer
   nada, porque el jugador ya cargo creyendo otra cosa. */
$srcRl  = file_get_contents(__DIR__ . '/api/recargas_lib.php');
$srcBot = file_get_contents(__DIR__ . '/api/chatbot.php');

chequear('el que paga usa la funcion, no su propia cuenta',
         str_contains($srcRl, '$pctBono = rl_bono_bienvenida_pct($pdo, $usuario);'),
         'dos criterios en dos lugares se separan solos, ya paso con el matcher');
chequear('y el que promete tambien',
         str_contains($srcBot, 'rl_bono_bienvenida_pct($pdo, $usuario)'));
chequear('el bloque del prompt esta conectado a IDENTIDAD',
         str_contains($srcBot, 'chatbot_bloque_bienvenida($pdo, $usuarioCliente)'),
         'una funcion sin caller es el bug que ya tuvimos con vin_bloqueado_por_senal');

echo "\n=== 6. Lo que el bot le dice a cada uno ===\n";

/* La funcion vive en `chatbot.php`, que es un ENDPOINT: requerirlo entero
   dispararia el despacho. Se recorta y se evalua solo ella, igual que hace
   t_auditoria con la query de la auditoria. Asi se prueba la que de verdad
   corre y no una copia que puede quedar desincronizada. */
$i = strpos($srcBot, 'function chatbot_bloque_bienvenida(');
$j = strpos($srcBot, "\nfunction ", $i + 10);
eval(substr($srcBot, $i, $j - $i));

$dice = fn(string $u) => chatbot_bloque_bienvenida($pdo, $u);
chequear('sin usuario no dice nada del bono', $dice('') === '');

$txt50 = $dice('t_bl_cincuenta');
chequear('al de la landing de 50 le dice 50', str_contains($txt50, '50%'),
         $txt50);
chequear('y le aclara que ese es el numero exacto',
         str_contains(mb_strtolower($txt50), 'no digas otro'));

$txt0 = $dice('t_bl_cero');
chequear('al de la landing SIN bono le dice que NO tiene',
         str_contains(mb_strtolower($txt0), 'no tiene'), $txt0);
chequear('y que no le ofrezca ninguno',
         str_contains(mb_strtolower($txt0), 'no le ofrezcas'));
/* CALLARSE NO ALCANZA: el operador puede tener escrito "50% en tu primera
   carga" en las indicaciones extra, y sin una linea que lo contradiga el bot
   lo repite igual. Por eso el bloque tiene que decir explicitamente que no. */
chequear('y que eso manda sobre las promos escritas arriba',
         str_contains(mb_strtolower($txt0), 'manda sobre'),
         'callarse no alcanza: el texto del operador sigue ahi');
chequear('sin mencionar ningun porcentaje',
         !preg_match('/\d+\s*%/', $txt0), $txt0);

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
$limpiar();
exit($fail > 0 ? 1 : 0);
