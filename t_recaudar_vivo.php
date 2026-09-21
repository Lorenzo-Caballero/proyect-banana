<?php
/**
 * t_recaudar_vivo.php — El CRM muestra la recaudación MIENTRAS pasa.
 *
 * Pedido del dueño (21/09/2026): *"que desde el frontend vaya mostrando el
 * proceso, retiro por retiro, que se vea reflejado en el momento"* y *"si hay
 * algún log de error también mostrarlo en pantalla así depuramos"*.
 *
 * Antes el bot escribía UNA sola vez, al terminar: una recaudación de 25
 * jugadores eran varios minutos de «En curso…» y después todo junto. Y cuando
 * algo fallaba, el motivo quedaba en el log del contenedor, al que hay que
 * entrar por SSH.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN — todo alrededor de una regla: el avance no
 * puede tocar la MÁQUINA DE ESTADOS de una cola que mueve plata.
 *
 *  1. Un avance NO cierra la corrida. Si la dejara en otro estado,
 *     `pendientes` entregaría la siguiente creyendo que ésta terminó, y dos
 *     recaudaciones en paralelo se pisan el orden del listado (y retiran).
 *  2. Un avance que llega TARDE no pisa el resultado final ni reabre nada.
 *  3. Sólo `marcar` cierra.
 *
 *     T_PORT=3399 php t_recaudar_vivo.php
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

/* La lógica del endpoint (recaudar_cola.php exige API key al incluirse, así
   que se replica el UPDATE exacto que hace, igual que otros t_*). Lo que se
   prueba es la REGLA — el WHERE con estado='procesando' —, no el transporte. */
function avance(PDO $pdo, int $id, array $res): int {
    $st = $pdo->prepare(
        "UPDATE recaudaciones SET resultado=?, actualizada_en=NOW()
          WHERE id=? AND estado='procesando'"
    );
    $st->execute([json_encode($res, JSON_UNESCAPED_UNICODE), $id]);
    return $st->rowCount();
}
function marcar(PDO $pdo, int $id, string $estado, array $res): int {
    $st = $pdo->prepare(
        "UPDATE recaudaciones SET estado=?, resultado=?, actualizada_en=NOW()
          WHERE id=? AND estado='procesando'"
    );
    $st->execute([$estado, json_encode($res, JSON_UNESCAPED_UNICODE), $id]);
    return $st->rowCount();
}
function leer(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT estado, resultado FROM recaudaciones WHERE id=?");
    $st->execute([$id]);
    $f = $st->fetch() ?: [];
    $f['res'] = !empty($f['resultado']) ? json_decode((string)$f['resultado'], true) : null;
    return $f;
}

try { $pdo->query("SELECT 1 FROM recaudaciones LIMIT 0"); }
catch (Throwable $e) {
    fwrite(STDERR, "Falta la tabla `recaudaciones` en la base de prueba.\n");
    exit(2);
}
$pdo->exec("DELETE FROM recaudaciones WHERE pedido_por = 'tstvivo'");

$nueva = function (PDO $pdo, string $estado): int {
    $pdo->prepare(
        "INSERT INTO recaudaciones (estado, dry_run, dias, saltar, tope, min_saldo, pedido_por)
         VALUES (?,0,30,4,10,100,'tstvivo')"
    )->execute([$estado]);
    return (int)$pdo->lastInsertId();
};

// ===========================================================================
echo "=== 1. El avance se guarda sin cerrar la corrida ===\n";
$id = $nueva($pdo, 'procesando');
chequear('el avance escribe', avance($pdo, $id, [
    'fase' => 'retirando', 'paso' => 'Retirando 3 de 12', 'hechos' => 2, 'de' => 12,
    'log' => ['16:02:12 · holajugador200: retirando $900'],
]) === 1);
$f = leer($pdo, $id);
chequear('la corrida SIGUE en procesando', ($f['estado'] ?? '') === 'procesando',
         'si el avance cambiara el estado, la cola entregaría la siguiente y dos corridas se pisan');
chequear('y el progreso queda disponible para la pantalla',
         ($f['res']['hechos'] ?? null) === 2 && ($f['res']['de'] ?? null) === 12,
         json_encode($f['res']));
chequear('con el log para depurar', count($f['res']['log'] ?? []) === 1);

// ===========================================================================
echo "\n=== 2. Cada avance reemplaza al anterior (es una foto, no un historial) ===\n";
avance($pdo, $id, ['fase' => 'retirando', 'hechos' => 7, 'de' => 12,
                   'log' => ['a', 'b', 'c']]);
$f = leer($pdo, $id);
chequear('queda la foto más nueva', ($f['res']['hechos'] ?? null) === 7);
chequear('con su log acumulado (lo acumula el bot, no la base)',
         count($f['res']['log'] ?? []) === 3);

// ===========================================================================
echo "\n=== 3. Sólo `marcar` cierra ===\n";
chequear('marcar cierra la corrida',
         marcar($pdo, $id, 'hecha', ['retirados' => 12, 'total' => 18400, 'log' => ['fin']]) === 1);
$f = leer($pdo, $id);
chequear("queda 'hecha'", ($f['estado'] ?? '') === 'hecha');
chequear('con el resultado final', ($f['res']['retirados'] ?? null) === 12);

// ===========================================================================
echo "\n=== 4. Un avance TARDÍO no pisa el final ni reabre nada ===\n";
/* Pasa de verdad: el bot manda el último avance justo cuando la corrida ya
   cerró. Sin el WHERE, ese avance sobreescribiría el resultado final con una
   foto a medias -- y la pantalla mostraría 11 de 12 sobre algo terminado. */
chequear('el avance tardío no escribe', avance($pdo, $id, ['fase' => 'retirando', 'hechos' => 11]) === 0);
$f = leer($pdo, $id);
chequear('el resultado final quedó intacto', ($f['res']['retirados'] ?? null) === 12,
         json_encode($f['res']));
chequear("y sigue 'hecha'", ($f['estado'] ?? '') === 'hecha');

// ===========================================================================
echo "\n=== 5. Una corrida que todavía no arrancó no acepta avances ===\n";
$id2 = $nueva($pdo, 'pendiente');
chequear('pendiente no acepta avance (el bot todavía no la reclamó)',
         avance($pdo, $id2, ['fase' => 'buscando']) === 0);

// ===========================================================================
echo "\n=== 6. Las piezas están conectadas ===\n";
$cola = file_get_contents(__DIR__ . '/api/recaudar_cola.php');
chequear("el endpoint expone accion=avance", str_contains($cola, "\$accion === 'avance'"));
chequear('y su UPDATE exige procesando',
         (bool)preg_match('/SET resultado=\?, actualizada_en=NOW\(\)\s*\n\s*WHERE id=\? AND estado=\'procesando\'/', $cola),
         'sin ese WHERE, un avance tardío pisa el resultado final');

$bot = file_get_contents(__DIR__ . '/bot/bot_recaudar.py');
chequear('el bot reporta el avance', str_contains($bot, '"accion": "avance"'));
chequear('avisa ANTES de cada retiro (para ver en quién se cuelga)',
         (bool)preg_match('/_avisar\(reporte, f"\{j\[.usuario.\]\}: retirando/', $bot));
chequear('y manda el motivo del error a la pantalla',
         str_contains($bot, 'ERROR - {detalle}'));
chequear('el reporte en vivo NUNCA frena la recaudación (best-effort)',
         (bool)preg_match('/except Exception:\s*\n\s*pass\s*#\s*ver arriba/', $bot),
         'un POST de progreso que aborte la corrida deja jugadores sin saldo y la fila abierta');

$crm = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('la pantalla pinta la barra de progreso', str_contains($crm, 'function rcProgreso('));
chequear('y el log técnico', str_contains($crm, 'function rcLog('));
chequear('el log se abre solo cuando hay errores',
         str_contains($crm, 'hayError && !rcLogs.has(-r.id)'));
chequear('mientras busca la barra es indeterminada (no inventa un %)',
         str_contains($crm, 'rc-barra buscando') || str_contains($crm, '" buscando"'));
chequear('y el sondeo acelera mientras algo corre',
         str_contains($crm, 'rcHayCorrida ? 2000 : 8000'));

$pdo->exec("DELETE FROM recaudaciones WHERE pedido_por = 'tstvivo'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
