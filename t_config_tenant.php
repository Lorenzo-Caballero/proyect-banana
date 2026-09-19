<?php
/**
 * t_config_tenant.php — La config de un cliente no se le filtra a otro.
 *
 * EL CASO (19/09/2026). A Nahuel le llegaba a Telegram *"hace 35 horas que no
 * hay NINGUNA actividad"* mientras su negocio estaba trabajando normal. El
 * aviso no era suyo: era del tenant `ganamos`, que está parado y no tiene
 * Telegram configurado — pero heredaba el token y el chat_id de `ganamoscrm`.
 *
 * LA CAUSA: `cfg_crm_todo()` cacheaba en UN solo `$GLOBALS['__cfg_crm_cache']`
 * para todo el proceso, ignorando el `$pdo`. En un endpoint no se nota, porque
 * un request es un solo cliente. Pero `panel/provisionar.php` recorre TODOS
 * los tenants en una sola corrida, cada minuto: el primero que se leía le
 * dejaba su configuración a todos los demás.
 *
 * Y no era sólo el Telegram: cualquier `cfg_crm()` después del primer tenant
 * contestaba con la config equivocada — promos, límites, la cuenta de cobro.
 *
 * POR QUÉ NO LO AGARRABA NADA. Todas las suites usan UNA base. Este bug
 * necesita DOS conexiones vivas a la vez en el mismo proceso, que es
 * exactamente la forma del cron y de ninguna otra cosa. Por eso acá se arma esa
 * situación y no otra.
 *
 *     T_PORT=3399 php t_config_tenant.php
 */
declare(strict_types=1);

$HOST = getenv('T_HOST') ?: '127.0.0.1';
$PORT = getenv('T_PORT') ?: '3306';
$USER = getenv('T_USER') ?: 'root';
$PASS = getenv('T_PASS') ?: '';
$DB1  = getenv('T_DB')   ?: 'goldpaw_demo';
$DB2  = $DB1 . '_tenant2';

$raiz = new PDO("mysql:host=$HOST;port=$PORT;charset=utf8mb4", $USER, $PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* La segunda base es de mentira y se borra al final: sólo necesita la tabla
   `config_crm`, que es lo único que este test mira. */
$raiz->exec("DROP DATABASE IF EXISTS `$DB2`");
$raiz->exec("CREATE DATABASE `$DB2` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$abrir = fn(string $db) => new PDO("mysql:host=$HOST;port=$PORT;dbname=$db;charset=utf8mb4", $USER, $PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pdo  = $abrir($DB1);
$pdo2 = $abrir($DB2);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';

/* Se COPIA el esquema real en vez de escribirlo a mano: una tabla inventada
   se separa de la de verdad y el test empieza a fallar por sus propias
   columnas, no por lo que vino a probar. */
$pdo2->exec("CREATE TABLE `$DB2`.config_crm LIKE `$DB1`.config_crm");

/* CLIENTE A tiene Telegram y una promo; CLIENTE B no tiene nada, como el
   tenant real que disparó el aviso. */
cfg_crm_guardar($pdo, ['tg_bot_token' => 'AAA:tokenDeA', 'tg_chat_id' => '-111111',
                       'app_bono_fichas' => '1000'], 'test');
// B queda con su tabla vacía a propósito: tiene que ver los DEFAULTS, no lo de A.

echo "\n=== 1. Dos clientes vivos en el mismo proceso ===\n";

/* EL ORDEN IMPORTA Y ES EL DEL BUG: primero se lee A (el que tiene config),
   después B. Al revés el bug no se ve. */
$aToken = cfg_crm($pdo,  'tg_bot_token');
$bToken = cfg_crm($pdo2, 'tg_bot_token');

chequear('el cliente A ve SU token', $aToken === 'AAA:tokenDeA', var_export($aToken, true));
chequear('y el cliente B NO hereda el de A',
         $bToken !== 'AAA:tokenDeA',
         'heredó "' . (string)$bToken . '": ese es el bug que le mandaba los avisos de un '
         . 'cliente al Telegram de otro');
chequear('B ve el default vacío, que es lo que le corresponde',
         (string)$bToken === '' && (string)cfg_crm($pdo2, 'tg_chat_id') === '',
         'token=' . var_export($bToken, true));

/* No es sólo el Telegram: era CUALQUIER ajuste. */
cfg_crm_guardar($pdo2, ['app_bono_fichas' => '250'], 'test');
chequear('cada uno ve su propio monto de bono (A=1000, B=250)',
         cfg_crm($pdo, 'app_bono_fichas') === '1000'
         && cfg_crm($pdo2, 'app_bono_fichas') === '250',
         'A=' . var_export(cfg_crm($pdo, 'app_bono_fichas'), true)
         . ' B=' . var_export(cfg_crm($pdo2, 'app_bono_fichas'), true));

echo "\n=== 2. Guardar en uno no le ensucia el cache al otro ===\n";
cfg_crm_guardar($pdo, ['app_bono_fichas' => '7777'], 'test');
chequear('A ve lo que acaba de guardar', cfg_crm($pdo, 'app_bono_fichas') === '7777');
chequear('y B sigue viendo lo suyo', cfg_crm($pdo2, 'app_bono_fichas') === '250',
         'dio ' . var_export(cfg_crm($pdo2, 'app_bono_fichas'), true));

echo "\n=== 3. El cache sigue existiendo (una lectura por conexión) ===\n";
/* Si el arreglo hubiera sido "sacar el cache", esto pasaría igual pero el sitio
   haría una consulta por cada cfg_crm() -- y hay muchas por request. Se mide
   sobre la tabla real: se cambia el valor POR DEBAJO y el cache tiene que
   seguir devolviendo el viejo. */
$pdo->prepare("UPDATE config_crm SET valor = '9999' WHERE clave = 'app_bono_fichas'")->execute();
chequear('una segunda lectura NO vuelve a consultar la tabla',
         cfg_crm($pdo, 'app_bono_fichas') === '7777',
         'dio ' . var_export(cfg_crm($pdo, 'app_bono_fichas'), true)
         . ': si devuelve 9999 el cache se perdió y son varias consultas por request');

echo "\n=== 4. Una conexión nueva a la MISMA base lee de nuevo ===\n";
$pdo3 = $abrir($DB1);
chequear('la conexión nueva ve el valor real de la tabla',
         cfg_crm($pdo3, 'app_bono_fichas') === '9999',
         'dio ' . var_export(cfg_crm($pdo3, 'app_bono_fichas'), true));

// Limpieza.
cfg_crm_guardar($pdo, ['tg_bot_token' => '', 'tg_chat_id' => '', 'app_bono_fichas' => '1000'], 'test');
$pdo2 = null; $pdo3 = null;
$raiz->exec("DROP DATABASE IF EXISTS `$DB2`");

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
