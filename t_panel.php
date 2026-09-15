<?php
/**
 * t_panel.php — El panel del dueño: que lo que se carga LLEGUE al cliente.
 *
 * POR QUE EXISTE (15/09/2026). El modal de «Nuevo cliente» pedía dos cosas que
 * después no leía nadie:
 *
 *   - «Cohere API key» -> `clientes.cohere_key`. Cohere dejó de ser el proveedor
 *     en agosto de 2026 (hoy es Qwen), y encima el campo era decorativo:
 *     cargaras lo que cargaras, TODOS los clientes hablaban con la clave global
 *     del VPS. Peor todavía: pegar ahí una clave de Cohere de verdad es la
 *     trampa que documenta chatbot_diag.php — se la manda igual a Qwen, Qwen la
 *     rechaza con 401, y el chat queda mudo "con la clave cargada".
 *   - «Coins por peso» -> `clientes.coins_por_peso`. El cálculo del monto usaba
 *     la constante RL_COINS_POR_PESO. Un cliente que ponía 2 seguía cobrando
 *     1 a 1, sin un solo error en ningún log.
 *
 * Un campo que no hace nada es peor que un campo que falta: el que lo completa
 * se queda creyendo que lo configuró.
 *
 * ACTUALIZACIÓN (15/09/2026, más tarde ese mismo día): el chat de producción
 * corre sobre CLAUDE (CHAT_MODEL + ANTHROPIC_API_KEY), no sobre Qwen, así que
 * la clave por cliente pasó al lugar de Anthropic (ia_key_anthropic) y el
 * panel DEJÓ de pedir clave de IA: todos los clientes usan la global del
 * server. clientes.ia_key queda como override sin UI. El lugar de Qwen
 * (ia_key_qwen, el respaldo del chat) es solo de claves globales — una clave
 * de Anthropic contra DashScope da 401, la trampa de Cohere repetida.
 *
 * CADA ESCENARIO CORRE EN SU PROPIO PROCESO, y no es capricho: ia_key() y
 * rl_cliente_actual() cachean por proceso, que es lo correcto para un request
 * (resuelve una vez y listo). Probar el ORDEN de resolución dentro de un mismo
 * proceso mediría el caché y no la lógica. Un proceso por escenario es, además,
 * lo que hace producción.
 *
 *     T_PORT=3399 php t_panel.php
 */
declare(strict_types=1);

/* El escenario llega por argv y se planta en el entorno ANTES de que se cargue
   nada: cfg() lee getenv(), y putenv() alcanza porque es el mismo proceso. Va
   por argv y no por el entorno del shell a propósito — armar "VAR=x cmd" a mano
   es distinto en Windows y en POSIX, y no hace falta. */
$__caso = $argv[1] ?? '';
foreach (json_decode($argv[2] ?? '{}', true) ?: [] as $k => $v) {
    putenv('TP_' . $k . '=' . $v);
}

// cfg() STUB: tiene que estar ANTES de cargar config.php, que define la suya
// con if(!function_exists).
function cfg($clave, $porDefecto = '') {
    $v = getenv('TP_' . $clave);
    return ($v === false || $v === '') ? $porDefecto : $v;
}

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;

/* El plano de control vive en OTRA base en producción (goldpaw_control). Acá se
   monta la tabla en la misma base de prueba y se apunta el override:
   rl_control() devuelve este PDO y todo lo que consulta el control cae acá. Es
   el mismo global que ya usaban los tests de HG Cash. */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS clientes (
       id             INT AUTO_INCREMENT PRIMARY KEY,
       nombre         VARCHAR(120) NOT NULL DEFAULT 't',
       slug           VARCHAR(60)  NOT NULL,
       dominio        VARCHAR(190) NOT NULL DEFAULT 'x',
       db_nombre      VARCHAR(80)  DEFAULT NULL,
       metodo_cobro   ENUM('transferencia','hgcash') NOT NULL DEFAULT 'transferencia',
       coins_por_peso DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
       ia_key         VARCHAR(190) DEFAULT NULL,
       cohere_key     VARCHAR(190) DEFAULT NULL,
       cobro_alias    VARCHAR(120) DEFAULT NULL,
       cobro_cbu      VARCHAR(40)  DEFAULT NULL,
       cobro_titular  VARCHAR(120) DEFAULT NULL,
       cobro_modo     ENUM('azar','fija') NOT NULL DEFAULT 'azar',
       cobro_fija_id  INT DEFAULT NULL,
       hg_propio_activo TINYINT(1) NOT NULL DEFAULT 0,
       hg_propio_token TEXT DEFAULT NULL,
       hg_propio_account_id VARCHAR(80) DEFAULT NULL,
       hg_propio_webhook_secret VARCHAR(190) DEFAULT NULL,
       hg_propio_modo ENUM('prod','dev') NOT NULL DEFAULT 'prod',
       UNIQUE KEY uq_slug (slug)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$GLOBALS['HG_CONTROL_OVERRIDE'] = $pdo;

const TP_DB = 'gp_tpanel';
$GLOBALS['TENANT_DB'] = TP_DB;

function tp_limpiar(PDO $pdo): void {
    $pdo->exec("DELETE FROM clientes WHERE slug LIKE 'tpanel%'");
}
/** Deja UNA fila de cliente para este tenant, con los valores del escenario. */
function tp_cliente(PDO $pdo, ?string $iaKey, $coinsPorPeso = 1): void {
    tp_limpiar($pdo);
    $st = $pdo->prepare(
        "INSERT INTO clientes (nombre, slug, dominio, db_nombre, ia_key, coins_por_peso)
         VALUES ('Test panel', 'tpanel', 'x', ?, ?, ?)"
    );
    $st->execute([TP_DB, $iaKey, $coinsPorPeso]);
}

require_once __DIR__ . '/api/recargas_lib.php';   // trae rl_control / rl_cliente_actual
require_once __DIR__ . '/api/ia_key.php';

// ===========================================================================
//  HIJO: un escenario, un proceso. Imprime SOLO un JSON.
// ===========================================================================
if ($__caso !== '') {
    $r = ['ok' => false, 'detalle' => 'caso desconocido: ' . $__caso];

    if ($__caso === 'key_cliente') {
        tp_cliente($pdo, 'sk-ant-la-del-cliente-1234567890');
        $r = ['ok' => ia_key_anthropic() === 'sk-ant-la-del-cliente-1234567890' && ia_key_origen() === 'cliente',
              'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_global') {
        tp_cliente($pdo, null);
        $r = ['ok' => ia_key_anthropic() === getenv('TP_ANTHROPIC_API_KEY') && ia_key_origen() === 'ANTHROPIC_API_KEY',
              'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_vacia_no_cuenta') {
        tp_cliente($pdo, '');
        $r = ['ok' => ia_key_origen() === 'ANTHROPIC_API_KEY', 'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_ninguna') {
        tp_cliente($pdo, null);
        $r = ['ok' => ia_key_anthropic() === '' && ia_key_origen() === '', 'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_sin_fila') {
        tp_limpiar($pdo);
        $r = ['ok' => ia_key_origen() === 'ANTHROPIC_API_KEY', 'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_sin_control') {
        /* Control CAÍDO: se saca el override, así ia_key_del_cliente() intenta
           abrir la suya con datos que no resuelven. Tiene que caer a la global
           y no romper. */
        unset($GLOBALS['HG_CONTROL_OVERRIDE']);
        $r = ['ok' => ia_key_origen() === 'ANTHROPIC_API_KEY', 'detalle' => ia_key_origen()];

    } elseif ($__caso === 'key_sin_migracion') {
        /* EL HUECO DEL DEPLOY: el código sale con `git pull` y la migración 07
           del control la corre una persona a mano. En el medio, la columna no
           existe. Tiene que caer a la global, no tirar. Se restaura al final
           porque los escenarios comparten la tabla y corren en fila. */
        tp_cliente($pdo, null);
        $pdo->exec("ALTER TABLE clientes DROP COLUMN ia_key");
        $r = ['ok' => ia_key_origen() === 'ANTHROPIC_API_KEY', 'detalle' => ia_key_origen()];
        $pdo->exec("ALTER TABLE clientes ADD COLUMN ia_key VARCHAR(190) DEFAULT NULL");

    } elseif ($__caso === 'qwen_ignora_cliente') {
        /* LA GUARDA DEL 401: la clave del cliente es de ANTHROPIC. Si se
           colara en el lugar de Qwen, DashScope la rechaza y el respaldo
           muere justo cuando se lo necesita -- la trampa de Cohere de nuevo. */
        tp_cliente($pdo, 'sk-ant-la-del-cliente-1234567890');
        $r = ['ok' => ia_key_qwen() === getenv('TP_QWEN_API_KEY'),
              'detalle' => 'qwen dio: ' . ia_key_qwen()];

    } elseif ($__caso === 'qwen_legacy_cohere') {
        tp_cliente($pdo, null);
        $r = ['ok' => ia_key_qwen() === getenv('TP_COHERE_API_KEY'),
              'detalle' => 'qwen dio: ' . ia_key_qwen()];

    } elseif ($__caso === 'qwen_orden') {
        tp_cliente($pdo, null);
        $r = ['ok' => ia_key_qwen() === getenv('TP_QWEN_API_KEY'),
              'detalle' => 'qwen dio: ' . ia_key_qwen()];

    } elseif ($__caso === 'cpp_cliente') {
        tp_cliente($pdo, null, 2.5);
        $r = ['ok' => abs(rl_coins_por_peso() - 2.5) < 0.0001,
              'detalle' => (string)rl_coins_por_peso()];

    } elseif ($__caso === 'cpp_cero') {
        tp_cliente($pdo, null, 0);
        $r = ['ok' => abs(rl_coins_por_peso() - (float)RL_COINS_POR_PESO) < 0.0001,
              'detalle' => (string)rl_coins_por_peso()];

    } elseif ($__caso === 'cpp_negativo') {
        tp_cliente($pdo, null, -3);
        $r = ['ok' => abs(rl_coins_por_peso() - (float)RL_COINS_POR_PESO) < 0.0001,
              'detalle' => (string)rl_coins_por_peso()];

    } elseif ($__caso === 'cpp_sin_fila') {
        tp_limpiar($pdo);
        $r = ['ok' => abs(rl_coins_por_peso() - (float)RL_COINS_POR_PESO) < 0.0001,
              'detalle' => (string)rl_coins_por_peso()];

    } elseif ($__caso === 'cpp_manda_en_el_monto') {
        /* Lo que importa de verdad: que la tasa llegue AL MONTO que se le cobra
           al jugador. 3000 coins a 2 coins por peso son $1500, no $3000. */
        tp_cliente($pdo, null, 2);
        $r = ['ok' => (int)round(3000 / rl_coins_por_peso()) === 1500,
              'detalle' => (string)(int)round(3000 / rl_coins_por_peso())];
    }

    echo json_encode($r);
    exit(0);
}

// ===========================================================================
//  PADRE
// ===========================================================================
$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/** Corre un escenario en un proceso nuevo, con SU configuración.
 *
 *  proc_open CON ARRAY, no shell_exec: pasando los argumentos como lista no hay
 *  shell de por medio, así que no hay comillas que citar. Con shell_exec esto se
 *  colgaba en Windows — cmd.exe se come las comillas externas cuando el comando
 *  entero arranca con una, y el proceso quedaba esperando para siempre.
 *  El hijo hereda el entorno del padre (T_PORT y compañía); su escenario va por
 *  argv. */
function escenario(string $titulo, string $caso, array $cfg): void {
    $args = [PHP_BINARY, __FILE__, $caso, (string)json_encode($cfg)];
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($args, $desc, $tubos);
    if (!is_resource($p)) { chequear($titulo, false, 'no pude lanzar el subproceso'); return; }
    /* stdout y stderr SEPARADOS. Mezclarlos rompía el caso del control caído:
       ese escenario loguea a stderr a propósito (es lo que hace producción
       cuando la base maestra no responde) y ese texto se pegaba después del
       JSON, así que el veredicto se leía mal. El JSON es lo único que va a
       stdout; stderr solo se muestra cuando algo falla. */
    $out = trim((string)stream_get_contents($tubos[1]));
    $err = trim((string)stream_get_contents($tubos[2]));
    foreach ($tubos as $t) { fclose($t); }
    proc_close($p);

    $lineas = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
    $json = $lineas ? json_decode((string)end($lineas), true) : null;
    // Sin JSON se muestra TODO: un fatal del hijo tiene que verse, no aparecer
    // como una falla sin motivo.
    chequear($titulo, is_array($json) && !empty($json['ok']),
             is_array($json) ? (string)($json['detalle'] ?? '') : trim($out . ' ' . $err));
}

$ANT    = 'sk-ant-global-abcdefghijklmnopq';
$QWEN   = 'sk-qwen-global-abcdefghijklmnop';
$COHERE = 'sk-cohere-viejo-abcdefghijklmn';

echo "=== 1. La clave de ANTHROPIC (el proveedor real del chat) ===\n";
/* El panel ya NO pide clave por cliente: todos usan la ANTHROPIC_API_KEY
   global del server. clientes.ia_key queda como override sin UI, por si
   algún día un cliente necesita la suya. */
escenario('la del CLIENTE (si la hay en la base) gana sobre la global', 'key_cliente', ['ANTHROPIC_API_KEY' => $ANT]);
escenario('sin clave propia, usa ANTHROPIC_API_KEY del server (el caso de TODOS)', 'key_global', ['ANTHROPIC_API_KEY' => $ANT]);
escenario('una clave VACÍA en la base no cuenta como clave', 'key_vacia_no_cuenta', ['ANTHROPIC_API_KEY' => $ANT]);
escenario('sin ninguna, devuelve vacío (y el chat avisa)', 'key_ninguna', []);

echo "\n=== 2. Degrada hacia ARRIBA: nunca deja sin chatbot ===\n";
/* La dirección importa. Quedarse sin chatbot porque una base secundaria no
   responde sería cambiar un problema chico por uno grande. */
escenario('tenant sin fila en el control: cae a la global', 'key_sin_fila', ['ANTHROPIC_API_KEY' => $ANT]);
escenario('control CAÍDO: cae a la global, no rompe', 'key_sin_control', ['ANTHROPIC_API_KEY' => $ANT]);
escenario('migración 07 SIN correr: cae a la global, no tira', 'key_sin_migracion', ['ANTHROPIC_API_KEY' => $ANT]);

echo "\n=== 2b. El lugar de QWEN (el respaldo) es SOLO de claves globales ===\n";
escenario('la clave del cliente (de Anthropic) NO se cuela en Qwen', 'qwen_ignora_cliente',
          ['ANTHROPIC_API_KEY' => $ANT, 'QWEN_API_KEY' => $QWEN]);
escenario('QWEN_API_KEY gana sobre el nombre viejo', 'qwen_orden',
          ['QWEN_API_KEY' => $QWEN, 'COHERE_API_KEY' => $COHERE]);
escenario('sin QWEN_API_KEY todavía se acepta COHERE_API_KEY', 'qwen_legacy_cohere',
          ['COHERE_API_KEY' => $COHERE]);

echo "\n=== 3. Coins por peso: el campo del panel manda ===\n";
escenario('toma la tasa del cliente (2.5), no la constante', 'cpp_cliente', []);
escenario('y llega al MONTO: 3000 coins a 2 = 1500 pesos', 'cpp_manda_en_el_monto', []);

echo "\n=== 4. Guardas de la tasa (acá se divide) ===\n";
escenario('un 0 cargado a mano no divide por cero', 'cpp_cero', []);
escenario('un negativo tampoco', 'cpp_negativo', []);
escenario('sin fila, la constante de respaldo', 'cpp_sin_fila', []);

tp_limpiar($pdo);
$pdo->exec("DROP TABLE IF EXISTS clientes");
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
