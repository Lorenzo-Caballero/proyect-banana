<?php
/**
 * t_descartar_comp.php — Descartar un comprobante que NO es de ningún jugador.
 *
 * Ejercita lo que hace crm_comprobantes.php (accion 'descartar'): una
 * transferencia propia o de un tercero que no juega queda en 'revision' para
 * siempre, sonando el aviso "sin resolver". Descartarla la SACA de revisión
 * SIN acreditarle a nadie, y queda auditable y reversible.
 *
 * crm_comprobantes.php corre auth al incluirse, así que acá se replica el UPDATE
 * exacto y se comprueban las invariantes contra la base real (goldpaw_demo).
 *
 *     T_PORT=3399 php t_descartar_comp.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// El UPDATE EXACTO de crm_comprobantes.php accion 'descartar'.
function descartar(PDO $pdo, string $idUnico, string $operador): int {
    $st = $pdo->prepare(
        "UPDATE pagos SET estado='usado', asignado_por=?, asignado_en=NOW()
          WHERE id_unico=? AND estado='revision'"
    );
    $st->execute(['descartado:' . mb_substr($operador, 0, 45), $idUnico]);
    return $st->rowCount();
}
// El COUNT que usan el badge y el aviso de "sin resolver".
function enRevision(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='revision'")->fetchColumn();
}

$ID = 't_desc_0001';
$pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE 't_desc_%'")->execute();

echo "=== 1. Un comprobante en revisión (transferencia sin dueño) ===\n";
$pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado, capturado_en)
               VALUES (?, 5000, 'FACUNDO NAHUEL HERRERA', 'revision', NOW())")->execute([$ID]);
$revAntes = enRevision($pdo);
chequear('arranca en revisión', (bool)$pdo->query("SELECT 1 FROM pagos WHERE id_unico='$ID' AND estado='revision'")->fetchColumn());

echo "\n=== 2. Descartarlo: sale de revisión, sin acreditar a nadie ===\n";
$n = descartar($pdo, $ID, 'nahuel');
chequear('el descarte afectó 1 fila', $n === 1, "filas=$n");
$row = $pdo->query("SELECT estado, asignado_por, recarga_id FROM pagos WHERE id_unico='$ID'")->fetch();
chequear('queda en estado usado (fuera de revisión)', $row['estado'] === 'usado', (string)$row['estado']);
chequear('queda la huella de quién lo descartó', $row['asignado_por'] === 'descartado:nahuel', (string)$row['asignado_por']);
chequear('NO se le asignó ninguna recarga (no acreditó plata)', $row['recarga_id'] === null);
chequear('el contador de revisión bajó en 1', enRevision($pdo) === $revAntes - 1);

echo "\n=== 3. No se puede descartar dos veces (ya no está en revisión) ===\n";
$n2 = descartar($pdo, $ID, 'otro');
chequear('el segundo descarte no afecta nada', $n2 === 0, "filas=$n2");
$sigue = $pdo->query("SELECT asignado_por FROM pagos WHERE id_unico='$ID'")->fetchColumn();
chequear('no se pisó quién lo descartó primero', $sigue === 'descartado:nahuel', (string)$sigue);

echo "\n=== 4. Es reversible: vuelve a la bandeja ===\n";
$pdo->prepare("UPDATE pagos SET estado='revision' WHERE id_unico=? AND asignado_por LIKE 'descartado:%'")->execute([$ID]);
chequear('volvió a revisión', (bool)$pdo->query("SELECT 1 FROM pagos WHERE id_unico='$ID' AND estado='revision'")->fetchColumn());
chequear('y se puede volver a descartar', descartar($pdo, $ID, 'nahuel') === 1);

echo "\n=== 5. Descartar NO toca un comprobante ya acreditado (usado real) ===\n";
$pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado, recarga_id, asignado_por, capturado_en)
               VALUES ('t_desc_0002', 999, 'OTRO', 'usado', 123, 'nahuel', NOW())")->execute();
$n3 = descartar($pdo, 't_desc_0002', 'nahuel');
chequear('un pago ya usado/acreditado no se descarta', $n3 === 0, "filas=$n3");
$r2 = $pdo->query("SELECT asignado_por, recarga_id FROM pagos WHERE id_unico='t_desc_0002'")->fetch();
chequear('mantiene su asignación original (recarga 123)', (int)$r2['recarga_id'] === 123);
chequear('no le pisó el asignado_por con un descarte', $r2['asignado_por'] === 'nahuel', (string)$r2['asignado_por']);

$pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE 't_desc_%'")->execute();

echo "\n---------------------------------------\n";

// ===========================================================================
echo "\n=== La salida de un comprobante tiene que VERSE ===\n";

/* EL REPORTE (Nahuel, 19/09/2026): *"acabo de encontrar un comprobante que
   está sin resolver, quiero resolverlo y no hay ningún botón para aprobarlo"*.

   El botón estaba. Estaba fuera de la pantalla: `.comp-cands` no tenía tope de
   alto, así que con 20 candidatas la lista medía ~1.600px y empujaba el bloque
   de «acreditar directo» tan abajo que no se encontraba. El operador miraba
   una lista de jugadores que no eran el suyo y concluía, con razón, que no
   había forma de resolverlo.

   Medido ese día sobre el comprobante real que lo disparó ($16.000 de HECTOR
   RAFAEL BAREIRO): el backend devolvía 5 candidatas y ninguna era la suya. */
$crmH = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('la lista de candidatas tiene tope y scroll propio',
         str_contains($crmH, '.comp-cands{display:flex;flex-direction:column;gap:8px;margin-top:12px;')
         && str_contains($crmH, 'max-height:min(46vh,340px);overflow-y:auto'),
         'sin tope, 20 candidatas empujan la salida fuera de la pantalla');

/* Y QUE SIEMPRE HAYA UNA SALIDA A LA VISTA. Antes el titulo decia solo
   «Asignar a una carga pedida», asi que si ninguna candidata era la correcta la
   pantalla parecia no tener salida -- y la que si tenia quedaba abajo de 20
   candidatas, fuera del borde de la pantalla.
   Con el rediseño el titulo ya no existe: o hay una sugerencia con sus dos
   botones, o se dice que no la hay y se abre la busqueda sola. */
chequear('sin sugerencia, la busqueda se abre sola',
         str_contains($crmH, 'No tengo con qué sugerirte un jugador')
         && str_contains($crmH, 'if(otro) otro.style.display = "block";'),
         'si ninguna candidata sirve, la pantalla no puede quedar sin salida');
chequear('y descartar sigue a mano para lo que no es de nadie',
         str_contains($crmH, 'id="cdDescartar"'));

/* Y que las tres acciones sigan existiendo del lado del server. */
$srcComp = file_get_contents(__DIR__ . '/api/crm_comprobantes.php');
foreach (['asignar' => 'asignarla a una carga pedida',
          'acreditar_directo' => 'acreditarle las fichas al jugador',
          'descartar' => 'sacarla de la bandeja sin dar plata'] as $acc => $que) {
    chequear("se puede $que", str_contains($srcComp, "\$accion === '" . $acc . "'"));
}

/* ACREDITAR DIRECTO NO ES SOLO SUMAR FICHAS: tiene que hacer lo mismo que una
   recarga normal, o el jugador resuelto a mano pierde lo que le corresponde.
   Ya paso: este camino se salteaba el bono de bienvenida y el prometido. */
$srcRl = file_get_contents(__DIR__ . '/api/recargas_lib.php');
$i = strpos($srcRl, 'function rl_acreditar_directo(');
/* `$j === false` cuando es la ULTIMA funcion del archivo, que es el caso.
   Sin este guard, substr($s, $i, false - $i) recibe un largo NEGATIVO y
   devuelve vacio: el test daba en rojo por su propio bug, no por el codigo. */
$j = strpos($srcRl, "\nfunction ", $i + 10);
$cuerpo = $j === false ? substr($srcRl, $i) : substr($srcRl, $i, $j - $i);
chequear('acreditar directo suma las fichas',
         str_contains($cuerpo, 'SET coins = coins + ?'));
chequear('y da el bono de bienvenida que corresponda',
         str_contains($cuerpo, 'rl_bono_bienvenida_aplicar('));
chequear('y aplica el bono que tuviera prometido',
         str_contains($cuerpo, 'crmnotif_bono_aplicar_en_recarga(')
         || str_contains($cuerpo, 'bono_aplicar'),
         'un comprobante resuelto a mano se salteaba el bono de fidelizacion');


// ===========================================================================
echo "\n=== UNA sugerencia, no una lista de veinte ===\n";

/* EL PEDIDO (Nahuel, 19/09/2026): *"en lugar de mostrarme todos los nombres de
   los usuarios abajo, debería simplemente sugerirme de quién puede ser esa
   carga... y ahí decirme si cargarle a ese usuario o no. Rechazar o aprobar."*

   Veinte nombres no son veinte opciones: son una lista que hay que descartar
   de a una, y el operador termina sin saber cuál mirar. */
$srcComp = file_get_contents(__DIR__ . '/api/crm_comprobantes.php');
$crmH    = file_get_contents(__DIR__ . '/landing/crm.html');

chequear('el server arma UNA sugerencia',
         str_contains($srcComp, "'sugerencia' => \$sug"));
chequear('con sus motivos escritos, no un puntaje',
         str_contains($srcComp, "'porques' => \$porques"));
chequear('la pantalla pregunta por esa sola',
         str_contains($crmH, '¿Esta transferencia es de'));
chequear('con aprobar y rechazar',
         str_contains($crmH, 'id="csSi"') && str_contains($crmH, 'id="csNo"'));
chequear('y la lista larga queda detras de "no es el"',
         str_contains($crmH, 'id="compOtro" style="display:none"'));

/* SIN SEÑAL NO SE SUGIERE A NADIE. El de monto mas parecido no es un
   candidato: es el primero de una lista ordenada. Proponerlo invitaria a
   acreditarle plata a quien no la mando, que es el unico error caro de esta
   pantalla. */
chequear('sin ninguna señal NO se sugiere a nadie',
         str_contains($srcComp, "\$mejor['huella'] || \$mejor['parecido'] >= RL_UMBRAL_NOMBRE")
         && str_contains($crmH, 'No tengo con qué sugerirte un jugador'),
         'el de monto parecido no es un candidato, es el primero de una lista');

echo "\n=== La señal que faltaba: el nombre adentro del usuario ===\n";

/* Nuestros jugadores se llaman `hola` + Nombre + 3 digitos por construccion,
   asi que `holahector301` lleva "hector" adentro -- y el remitente del banco
   dice "HECTOR RAFAEL BAREIRO". Era la pista mas obvia y no la usaba nadie.

   Antes se habia probado comparar el titular contra el nombre de usuario CRUDO
   y era ruido, con razon: un apodo como "elkakas" no se parece a nada. La
   diferencia es sacar el `hola` y los digitos primero. */
$i = strpos($srcComp, 'function comp_nombre_de_usuario(');
$j = strpos($srcComp, "\n}", $i);
eval(substr($srcComp, $i, $j - $i + 2));

foreach ([
    ['holahector301',        'hector',        'el caso real que disparo esto'],
    ['holaoscardaniotti400', 'oscardaniotti', 'nombre largo'],
    ['kevin1622',            'kevin',         'sin el prefijo hola'],
    ['elkakas',              'elkakas',       'un apodo queda como esta'],
    ['hola12',               '',              'no queda nada aprovechable'],
] as [$u, $esperado, $porque]) {
    chequear("de '$u' saca '" . ($esperado ?: '(nada)') . "' ($porque)",
             comp_nombre_de_usuario($u) === $esperado,
             'dio: ' . var_export(comp_nombre_de_usuario($u), true));
}

require_once __DIR__ . '/api/recargas_lib.php';
chequear('y hector matchea con HECTOR RAFAEL BAREIRO',
         rl_similitud_nombres('HECTOR RAFAEL BAREIRO', comp_nombre_de_usuario('holahector301')) >= RL_UMBRAL_NOMBRE);
chequear('pero papa NO matchea con el mismo remitente',
         rl_similitud_nombres('HECTOR RAFAEL BAREIRO', comp_nombre_de_usuario('holapapa408')) < RL_UMBRAL_NOMBRE,
         'si matcheara cualquiera, la sugerencia seria una ruleta');

/* =========================================================================
   «YA SE LA CARGUE A MANO»: es de ese jugador, pero ya cobro por afuera
   =========================================================================
   EL PEDIDO (Nahuel, 19/09/2026): *"no me aparece alguna que diga descartar o
   ya le cargue a mano... porque no quiero descartarlo, pero tampoco volver a
   cargarle"*.

   Se llama la funcion REAL (rl_marcar_cargado_a_mano) y no una copia del SQL:
   el resto de esta suite replica el UPDATE de `descartar` porque
   crm_comprobantes.php corre auth al incluirse, y esa copia se puede separar
   del original sin que nadie se entere. Por eso esta accion vive en
   recargas_lib, igual que rl_acreditar_directo(). */
echo "\n=== Ya cargado a mano: resuelve sin mover un peso, y APRENDE ===\n";

$UM = 't_mano_jugador';
$PM = 't_mano_pago_1';
$limpiarMano = function () use ($pdo, $UM, $PM): void {
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE 't_mano_pago_%'")->execute();
    foreach (['recargas' => 'usuario', 'movimientos' => 'usuario',
              'huellas_pagador' => 'usuario', 'usuarios' => 'username'] as $tb => $col) {
        try { $pdo->prepare("DELETE FROM $tb WHERE $col = ?")->execute([$UM]); } catch (Throwable $e) {}
    }
};
$limpiarMano();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990777, ?, 0, 250, 40)")->execute([$UM]);
$pdo->prepare(
    "INSERT INTO pagos (id_unico, monto, remitente, cuit, cbu_origen, estado)
     VALUES (?, 16000.00, 'HECTOR RAFAEL BAREIRO', '20360960030', '0000003100010000000001', 'revision')"
)->execute([$PM]);

$antes = enRevision($pdo);
$r = rl_marcar_cargado_a_mano($pdo, $PM, $UM, 0, 'nahuel');
chequear('lo marca ok', !empty($r['ok']), json_encode($r));
chequear('y sale de la bandeja', enRevision($pdo) === $antes - 1);

/* NO SE LE ACREDITA NADA. Es el punto entero: el jugador ya cobro. */
$u = $pdo->prepare("SELECT coins, bonus FROM usuarios WHERE username = ?");
$u->execute([$UM]);
$saldo = $u->fetch(PDO::FETCH_ASSOC);
chequear('NO le suma fichas ni bonos: 250/40 intactos',
         (int)$saldo['coins'] === 250 && (int)$saldo['bonus'] === 40, json_encode($saldo));
$mv = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = ?");
$mv->execute([$UM]);
chequear('ni deja un movimiento de plata', (int)$mv->fetchColumn() === 0);

/* LO QUE SI HACE, Y ES EL MOTIVO DE QUE NO SEA UN DESCARTE: aprende de que
   cuenta paga esa persona, asi la proxima se resuelve sola. */
chequear('dice que aprendio la huella', !empty($r['huella_aprendida']), json_encode($r));
$h = $pdo->prepare("SELECT COUNT(*) FROM huellas_pagador WHERE usuario = ? AND cuit = '20360960030'");
$h->execute([$UM]);
chequear('y la huella CUIT -> jugador quedo guardada', (int)$h->fetchColumn() === 1,
         'sin esto, la proxima transferencia de esa persona vuelve a revision');

/* SE DISTINGUE DE UN DESCARTE EN LA AUDITORIA. Si los dos quedaran iguales no
   habria forma de saber, mirando para atras, si esa plata era de alguien. */
$pg = $pdo->prepare("SELECT estado, asignado_por FROM pagos WHERE id_unico = ?");
$pg->execute([$PM]);
$fila = $pg->fetch(PDO::FETCH_ASSOC);
chequear("queda 'usado' con la marca a_mano:nahuel",
         $fila['estado'] === 'usado' && $fila['asignado_por'] === 'a_mano:nahuel', json_encode($fila));
chequear('que NO se confunde con un descarte', !str_starts_with((string)$fila['asignado_por'], 'descartado:'));

// Y no se puede marcar dos veces: ya no esta en revision.
$r2 = rl_marcar_cargado_a_mano($pdo, $PM, $UM, 0, 'nahuel');
chequear('marcarlo dos veces no hace nada', empty($r2['ok']), json_encode($r2));

/* LA CARGA PEDIDA QUE ESPERABA ESTA PLATA SE CANCELA. Si quedara abierta, una
   transferencia posterior del mismo monto puede casar con ella y acreditarse
   de nuevo -- exactamente lo que el operador vino a evitar. */
$PM2 = 't_mano_pago_2';
$pdo->prepare(
    "INSERT INTO pagos (id_unico, monto, remitente, cuit, estado)
     VALUES (?, 5000.00, 'ALGUIEN', '20111111112', 'revision')"
)->execute([$PM2]);
$pdo->prepare(
    "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado, referencia, vence_en)
     VALUES (?, 5000, 5000.00, 5000.00, 'pendiente', 'TMANO1', DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
)->execute([$UM]);
$rid = (int)$pdo->lastInsertId();
$r3 = rl_marcar_cargado_a_mano($pdo, $PM2, $UM, $rid, 'nahuel');
chequear('cancela la carga pedida que esperaba esa plata',
         (int)($r3['recargas_canceladas'] ?? 0) === 1, json_encode($r3));
$rc = $pdo->prepare("SELECT estado FROM recargas WHERE id = ?");
$rc->execute([$rid]);
$est = (string)$rc->fetchColumn();
chequear("y la deja 'cancelada', NO 'acreditada'", $est === 'cancelada',
         'acreditada la meteria en Finanzas como una carga que este camino nunca hizo; dio ' . $est);

/* Un comprobante sin CUIT ni CBU se resuelve igual: no hay nada que aprender,
   pero eso no puede impedir sacarlo de la bandeja. */
$PM3 = 't_mano_pago_3';
$pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado)
               VALUES (?, 700.00, 'SIN DATOS', 'revision')")->execute([$PM3]);
$r4 = rl_marcar_cargado_a_mano($pdo, $PM3, $UM, 0, 'nahuel');
chequear('sin CUIT ni CBU se resuelve igual', !empty($r4['ok']), json_encode($r4));
chequear('pero avisa que no aprendio nada', empty($r4['huella_aprendida']), json_encode($r4));

// Sin jugador no se marca: eso seria un descarte, que ya tiene su propia salida.
$r5 = rl_marcar_cargado_a_mano($pdo, $PM3, '', 0, 'nahuel');
chequear('sin jugador no se marca (para eso esta descartar)', empty($r5['ok']));

$limpiarMano();

printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
