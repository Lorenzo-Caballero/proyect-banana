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
 * SECCION 5 (15/09/2026): un jugador puede pedir el retiro desde ADENTRO del
 * juego, y eso vive en otra tabla (`retiros_panel`, espejo del panel). Esta
 * funcion solo miraba la cola nuestra, asi que se podian abrir los dos a la
 * vez. Paso: 4.000 pedidos en el juego y 4.280 por el chat, el agente
 * transfirio 4.280 al banco y resolvio en el panel el de 4.000 -- quedaron 280
 * fichas adentro y dos pedidos diciendo cosas distintas sobre la misma plata.
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

echo "\n=== 5. Ya pidio el retiro DENTRO DEL JUEGO ===\n";
/* EL CASO DEL 15/09. Dos colas distintas para la misma plata: si las dos
   quedan abiertas, la forma normal de equivocarse es pagar las dos. */
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='$U'");
$hayPanel = true;
try {
    $pdo->exec("DELETE FROM retiros_panel WHERE username='$U'");
    $pdo->prepare(
        "INSERT INTO retiros_panel (request_id, username, titular, monto, destino,
                                    estado, primera_vez)
         VALUES (?,?,?,?,?, 'abierto', NOW())"
    )->execute([234999001, $U, 'Tester', 4000, '0000003100045017569289']);
} catch (Throwable $e) {
    $hayPanel = false;
    echo "  (sin migracion 64 en esta base: se saltea)\n";
}

if ($hayPanel) {
    /* 400 y no 4.280: el jugador de prueba tiene 500 de saldo, y el chequeo
       de "no te alcanza" corre ANTES que este -- igual que corre antes del
       en_curso de la seccion 2. Lo que se prueba aca es el freno por duplicado,
       no el de saldo. */
    $r5 = fichas_pedir_retiro($pdo, $U, 400, 'chatbot', false, '0000003100045017569289');
    chequear('no abre un segundo pedido', ($r5['codigo'] ?? '') === 'en_curso',
             json_encode($r5));
    chequear('y dice que el otro es el del juego', !empty($r5['en_el_juego']));
    chequear('el mensaje trae el monto del pedido que ya existe',
             strpos((string)($r5['error'] ?? ''), '4.000') !== false,
             (string)($r5['error'] ?? ''));
    $n5 = (int)$pdo->query("SELECT COUNT(*) FROM acciones_saldo WHERE usuario='$U'")
                   ->fetchColumn();
    chequear('no quedo ninguna fila nuestra', $n5 === 0, "filas=$n5");

    /* Y CUANDO EL DEL JUEGO SE CIERRA, vuelve a poder pedir: esto frena
       mientras hay uno abierto, no para siempre. */
    $pdo->exec("UPDATE retiros_panel SET estado='cerrado' WHERE username='$U'");
    $r6 = fichas_pedir_retiro($pdo, $U, 300, 'chatbot', false, 'Ganamos1010');
    chequear('resuelto el del juego, ya puede pedir otro', !empty($r6['ok']),
             json_encode($r6));
    $pdo->exec("DELETE FROM retiros_panel WHERE username='$U'");
}

$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='$U'");
$pdo->exec("DELETE FROM usuarios WHERE username='$U'");

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
