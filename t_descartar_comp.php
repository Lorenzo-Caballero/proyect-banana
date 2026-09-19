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

chequear('y el encabezado nombra las DOS salidas, no solo asignar',
         str_contains($crmH, '¿De quién es esta transferencia?')
         && str_contains($crmH, 'acreditársela directo'),
         'si ninguna candidata sirve, la pantalla parecia no tener salida');

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

printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
