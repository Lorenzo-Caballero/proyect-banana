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

echo "\n=== 6. Retirar a mano con un pedido abierto: hay que decidir ===\n";

/* EL CASO (Nahuel, 18/09/2026): *"si una persona tiene cien mil fichas y
   solicita un retiro de veinte mil, un operador puede hacerle ese retiro a
   mano. Pero si ese jugador previamente hizo una solicitud desde el boton de
   retiros, esa solicitud queda activa y viene otro empleado y le vuelve a
   retirar otras 20.000 cuando la apruebe"*.

   Desde el 15/09 habia un aviso en el modal, y no alcanzaba: es un texto que
   se lee al abrir, y EL QUE PAGA DOS VECES ES EL SEGUNDO OPERADOR, que nunca
   lo vio. Avisarle al primero no protege del segundo.

   Lo que cierra el agujero son dos cosas, y las dos se chequean aca:
     - el server EXIGE `confirmado` para retirar a mano si hay algo abierto
       (una guarda de UI no protege a otro operador, ni a otro cliente);
     - y el retiro manual puede CANCELAR el pedido viejo en el mismo acto, que
       es lo que lo saca de la pantalla de Retiros para que nadie lo apruebe.

   Posicional sobre crm.php porque es un endpoint: requerirlo desde un test
   arranca una request. */
$srcCrm = file_get_contents(__DIR__ . "/api/crm.php");
$iGuard = strpos($srcCrm, "'codigo' => 'retiros_abiertos'");

chequear('el server rechaza el retiro manual si hay pedidos abiertos', $iGuard !== false);
chequear('y solo cuando el operador NO confirmo',
         str_contains($srcCrm, "empty(" . chr(36) . "body['confirmado'])"),
         'sin esto el operador no podria retirar nunca');
chequear('devuelve la lista, no solo el error',
         $iGuard !== false && str_contains(substr($srcCrm, $iGuard, 300), "'abiertos'"),
         'el operador tiene que ver QUE hay abierto para poder decidir');

/* La guarda mira LAS DOS colas. Mirar solo la nuestra deja pasar justo el caso
   del reporte: el pedido hecho desde el boton de adentro del juego. */
$bloque = $iGuard !== false ? substr($srcCrm, max(0, $iGuard - 2400), 2400) : '';
chequear('mira la cola nuestra (acciones_saldo)', str_contains($bloque, 'FROM acciones_saldo'));
chequear('y la del juego (retiros_panel)',       str_contains($bloque, 'FROM retiros_panel'));
chequear('la guarda es solo del retiro, no de la carga',
         str_contains($bloque, "if (" . chr(36) . "tipo === 'retirar')"));

/* La cancelacion: solo `pendiente`. Un `procesando` ya lo tiene el worker y
   cerrarlo en la base no lo frena en el panel -- quedaria "cancelado" de este
   lado y ejecutado del otro, que es peor que no cancelarlo. */
$iCanc = strpos($srcCrm, "SET estado = 'cancelada'");
chequear('puede cancelar el pedido viejo en el mismo acto', $iCanc !== false);
if ($iCanc !== false) {
    $c = substr($srcCrm, $iCanc, 460);
    chequear('solo los `pendiente`, nunca uno en curso',
             str_contains($c, "estado = 'pendiente'"),
             'un procesando ya lo tiene el worker: cancelarlo aca no lo frena');
    chequear('y solo los de ESE jugador',
             str_contains($c, 'usuario = ?'),
             'sin esto un id suelto cancela el retiro de cualquiera');
}

/* Y que la ficha mande el `id`: sin el, el modal puede AVISAR pero no CANCELAR
   -- que es exactamente lo que pasaba desde el 15/09. */
chequear('la ficha devuelve el id de cada pedido abierto',
         str_contains($srcCrm, "[] = ['id' => (int)" . chr(36) . "f['id']"),
         'sin id el aviso es solo texto');
chequear('y dice cual se puede cancelar', str_contains($srcCrm, "'cancelable' =>"));

echo "\n=== 7. Y la decision se entiende sin adivinar ===\n";

/* Nahuel, 18/09/2026: *"que al operador se le describa bien lo que significa
   la opcion aceptar y cancelar"*. Tenia razon: era un confirm() del
   navegador, que tiene dos botones con nombre FIJO --"Aceptar" y
   "Cancelar"-- y sobre plata ajena eso no dice nada (aceptar QUE). Peor: ahi
   "Cancelar" significaba "retirar igual y dejar el pedido abierto", o sea lo
   contrario de lo que la palabra sugiere.

   Ahora es un modal propio con tres opciones con nombre y una linea abajo
   explicando que hace cada una. */
$crm = file_get_contents(__DIR__ . "/landing/crm.html");

chequear('la decision tiene su propio modal', str_contains($crm, 'backRetDec'));
chequear('y ya no la resuelve un confirm() del navegador',
         !str_contains($crm, 'Aceptar = retiro y cancelo'),
         'confirm() no deja renombrar los botones');

/* Las TRES salidas tienen que existir. Sin la tercera, el operador que abre
   el modal por error no tiene forma de salir sin mover plata. */
chequear('opcion: retirar y cancelar el pedido', str_contains($crm, 'rdCancelar'));
chequear('opcion: retirar y dejarlo abierto',    str_contains($crm, 'rdDejar'));
chequear('opcion: no retirar nada',              str_contains($crm, 'rdNada'));

/* Y que cada una este DESCRIPTA, no solo nombrada: es todo el pedido. */
chequear('cada opcion lleva su descripcion', str_contains($crm, 'rdCancelarD')
                                          && str_contains($crm, 'rdDejarD'));
chequear('la descripcion dice cuanto se retira de MAS si se deja abierto',
         str_contains($crm, 'se le retiran '),
         'el riesgo tiene que estar en plata, no en abstracto');

/* Salirse sin elegir (Escape, click afuera) no puede dejar la promesa
   colgada ni retirar por las dudas: ante la duda, no se toca la plata. */
chequear('salirse sin elegir NO retira',
         str_contains($crm, 'porEsc') && str_contains($crm, 'porFuera'),
         'sin esto el modal se cierra y la promesa queda colgada');

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
