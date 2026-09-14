<?php
/**
 * t_retiro_flujo.php — el retiro por chat, que llega en DOS mensajes.
 *
 * EL CASO REAL (13/09/2026). El jugador pide retirar y el CBU llega recién en
 * el mensaje siguiente:
 *
 *     — Quiero retirar        -> ¿todo o una parte?
 *     — Todo                  -> ¿cuál es tu CBU o alias?
 *     — Ganamos1010           -> listo
 *
 * Eso abre dos problemas opuestos, y hay que resolver los dos a la vez:
 *
 *   1. Si el bot ESPERA al CBU para registrar, un jugador que abandona la
 *      conversación deja un retiro que no existe en ningún lado. Pasó: el bot
 *      dijo "ya está" y no había nada.
 *   2. Si el bot registra apenas sabe el monto y NUNCA vuelve a llamar con el
 *      CBU, el aviso de Telegram sale sin destino — y sin CBU no se puede
 *      pagar, así que el aviso no sirve para nada.
 *
 * La solución: se registra enseguida (no se pierde) y el segundo llamado
 * COMPLETA el mismo pedido en vez de rebotar con "ya tenés uno". El aviso sale
 * recién ahí, con el dato adentro.
 *
 *     php t_retiro_flujo.php
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
require __DIR__ . '/api/config_crm.php';
require __DIR__ . '/api/fichas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$U = 't_ret_flujo';
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='$U'");
$pdo->exec("DELETE FROM usuarios WHERE username='$U'");
$pdo->exec("INSERT INTO usuarios (id,username,balance,coins) VALUES (987002,'$U',500,0)");
cfg_crm_guardar($pdo, ['lim_retiro_min' => '100', 'lim_retiro_max' => '0',
                       'lim_retiro_max_dia' => '0', 'lim_retiro_cant_dia' => '0',
                       'lim_retiro_hora_desde' => '', 'lim_retiro_hora_hasta' => ''], 'test');

echo "\n=== 1. Pide el retiro y todavia no dio el CBU ===\n";
$r1 = fichas_pedir_retiro($pdo, $U, 200, 'chatbot', false, '');
chequear('el pedido SE REGISTRA igual: no se pierde si abandona',
         !empty($r1['ok']), json_encode($r1));
chequear('y avisa que falta el destino', !empty($r1['falta_destino']));
$id = (int)($r1['id'] ?? 0);
chequear('queda sin destino en la base',
         (string)$pdo->query("SELECT COALESCE(destino,'') FROM acciones_saldo WHERE id=$id")
              ->fetchColumn() === '');

echo "\n=== 2. Manda el alias: COMPLETA el mismo pedido ===\n";
$r2 = fichas_pedir_retiro($pdo, $U, 200, 'chatbot', false, 'Ganamos1010');
chequear('no rebota con "ya tenes uno pedido"', !empty($r2['ok']), json_encode($r2));
chequear('es EL MISMO pedido, no uno nuevo', (int)($r2['id'] ?? 0) === $id,
         ($r2['id'] ?? '-') . " vs $id");
$n = (int)$pdo->query("SELECT COUNT(*) FROM acciones_saldo WHERE usuario='$U' AND tipo='retirar'")
        ->fetchColumn();
chequear('sigue habiendo UNO solo (no duplica)', $n === 1, "filas=$n");
chequear('ahora tiene el alias',
         (string)$pdo->query("SELECT destino FROM acciones_saldo WHERE id=$id")->fetchColumn()
         === 'Ganamos1010');

echo "\n=== 3. Con el destino ya cargado, no se pisa ===\n";
/* Si un tercer llamado pudiera reemplazarlo, alcanzaria con que el modelo se
   confunda para mandarle la plata a otra cuenta. */
$r3 = fichas_pedir_retiro($pdo, $U, 200, 'chatbot', false, 'OtroAlias');
chequear('un tercer intento vuelve a frenarse', ($r3['codigo'] ?? '') === 'en_curso',
         json_encode($r3));
chequear('y el alias original queda INTACTO',
         (string)$pdo->query("SELECT destino FROM acciones_saldo WHERE id=$id")->fetchColumn()
         === 'Ganamos1010');

echo "\n=== 4. Si da el CBU de entrada, todo en un paso ===\n";
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='$U'");
$r4 = fichas_pedir_retiro($pdo, $U, 150, 'chatbot', false, '0000003100010000000001');
chequear('se crea con destino', !empty($r4['ok']) && empty($r4['falta_destino']), json_encode($r4));
chequear('y no hace falta un segundo llamado',
         (string)$pdo->query("SELECT destino FROM acciones_saldo WHERE id=" . (int)$r4['id'])
              ->fetchColumn() === '0000003100010000000001');

$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='$U'");
$pdo->exec("DELETE FROM usuarios WHERE username='$U'");

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
