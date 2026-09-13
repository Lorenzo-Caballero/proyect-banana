<?php
/**
 * t_reintento.php — que una carga cortada por el WAF vuelva SOLA a la cola.
 *
 * EL ESCENARIO QUE BLINDA, textual de Nahuel: "no quiero que le pase a un
 * jugador a las cuatro de la mañana cuando yo estoy durmiendo y no haya
 * ninguna gente para responderle y cargarle manualmente".
 *
 * Antes, un challenge del WAF dejaba la carga en 'revisar' esperando a una
 * persona. El jugador ya había transferido. A las 4 AM no hay persona.
 *
 * Lo que se fija acá:
 *   - la acción vuelve a 'pendiente' y la vuelve a tomar el worker;
 *   - el contador sube y el TOPE se respeta (si no, un problema permanente
 *     reintentaría para siempre, y cada reintento mueve plata);
 *   - agotado el tope queda en 'revisar' y ahí sí hace falta una persona;
 *   - las fichas NO se devuelven en el camino: la carga se sigue debiendo.
 *
 *     php t_reintento.php
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

const TOPE = 5;   // igual que ACCION_REINTENTOS_MAX en acciones_cola.php

/* COPIA del UPDATE de acciones_cola.php (acción 'reintentar'). No se puede
   incluir el archivo (corre el request al incluirse). Si algún día divergen,
   este test deja de proteger nada: mantenerlos iguales. */
function pedir_reintento(PDO $pdo, int $id, string $msg = 'WAF'): array {
    $pdo->prepare(
        "UPDATE acciones_saldo
            /* El IF va ANTES del incremento: MySQL evalua el SET de izquierda
               a derecha, asi que despues de `intentos = intentos + 1` la
               columna ya vale el valor NUEVO y la comparacion cortaba un
               intento antes de tiempo. Lo agarro t_reintento.php. */
            SET estado   = IF(intentos + 1 >= ?, 'revisar', 'pendiente'),
                intentos = intentos + 1,
                tomada_en = NULL,
                mensaje  = ?
          WHERE id = ? AND estado IN ('pendiente','procesando')"
    )->execute([TOPE, $msg, $id]);
    $q = $pdo->prepare("SELECT estado, intentos FROM acciones_saldo WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: [];
}

$U = 'test_reint';
$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES (?,'cargar',3750,'procesando',NOW())")->execute([$U]);
$id = (int)$pdo->lastInsertId();

echo "\n=== 1. El WAF corta: la carga vuelve SOLA a la cola ===\n";
$r = pedir_reintento($pdo, $id);
chequear('vuelve a pendiente, no queda esperando a una persona',
         ($r['estado'] ?? '') === 'pendiente', json_encode($r));
chequear('y cuenta el intento', (int)($r['intentos'] ?? 0) === 1, json_encode($r));

// Lo que hace la cola al entregarla de nuevo: pendiente -> procesando.
$tomar = fn() => $pdo->prepare(
    "UPDATE acciones_saldo SET estado='procesando', tomada_en=NOW()
      WHERE id=? AND estado='pendiente'")->execute([$id]);
$tomar();
$e = $pdo->query("SELECT estado FROM acciones_saldo WHERE id=$id")->fetchColumn();
chequear('el worker la vuelve a tomar en la proxima pasada', $e === 'procesando', (string)$e);

echo "\n=== 2. El tope: no reintenta para siempre ===\n";
for ($i = 2; $i < TOPE; $i++) {
    $r = pedir_reintento($pdo, $id);
    $tomar();
}
chequear('antes del tope sigue reintentando',
         (int)($r['intentos'] ?? 0) === TOPE - 1 && ($r['estado'] ?? '') === 'pendiente',
         json_encode($r));
$r = pedir_reintento($pdo, $id);
chequear('en el tope deja de reintentar', (int)($r['intentos'] ?? 0) === TOPE, json_encode($r));
chequear('y queda en revisar (ahi si hace falta una persona)',
         ($r['estado'] ?? '') === 'revisar', json_encode($r));

echo "\n=== 3. Una vez en revisar, no se reanima sola ===\n";
/* Si volviera a entrar a la cola desde 'revisar', el tope no serviria de nada:
   alcanzaria con que el worker insistiera para saltearlo. */
$r = pedir_reintento($pdo, $id);
chequear('pedir reintento sobre una revisar no la mueve',
         ($r['estado'] ?? '') === 'revisar' && (int)($r['intentos'] ?? 0) === TOPE,
         json_encode($r));

echo "\n=== 4. Las fichas no se devuelven en el camino ===\n";
/* Devolverlas en cada reintento y volver a cobrarlas seria un ida y vuelta del
   saldo del jugador por algo que todavia se le debe. La carga sigue en pie. */
$n = (int)$pdo->query(
    "SELECT COUNT(*) FROM acciones_saldo WHERE id=$id AND tipo='cargar' AND monto=3750")->fetchColumn();
chequear('la accion sigue siendo la misma, por el mismo monto', $n === 1);

echo "\n=== 5. El watchdog cuenta lo que tiene que contar ===\n";
/* scripts/monitor-cargas.sh avisa cuando hay cargas que el jugador pago y no
   recibio. Lo que se fija aca es SU CONSULTA: si cuenta de mas, avisa siempre
   y nadie lo lee; si cuenta de menos, no avisa cuando importa. */
$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);
$meter = function (string $tipo, string $estado, string $edad) use ($pdo, $U) {
    $pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
                   VALUES (?,?,1000,?, NOW() - INTERVAL $edad)")->execute([$U, $tipo, $estado]);
};
$contar = function () use ($pdo, $U): int {
    // Copia de la consulta de monitor-cargas.sh. Si divergen, esto no protege nada.
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM acciones_saldo
          WHERE usuario = ? AND tipo='cargar' AND estado IN ('pendiente','procesando')
            AND creada_en < NOW() - INTERVAL 10 MINUTE
            AND creada_en > NOW() - INTERVAL 1 DAY");
    $st->execute([$U]);
    return (int)$st->fetchColumn();
};

$meter('cargar', 'pendiente', '2 MINUTE');
chequear('una carga recien creada NO cuenta (todavia esta a tiempo)', $contar() === 0);

$meter('cargar', 'pendiente', '40 MINUTE');
chequear('una carga de 40 min SI cuenta', $contar() === 1);

/* Un retiro espera la aprobacion de un agente: puede estar horas en pendiente y
   es lo normal. Si contara, el watchdog avisaria todos los dias. */
$meter('retirar', 'pendiente', '40 MINUTE');
chequear('un retiro esperando aprobacion NO cuenta', $contar() === 1);

/* Hay acciones viejas trabadas de epocas en que el worker no corria. Sin el
   corte de 1 dia, el watchdog avisaria para siempre por historia antigua -- y
   un aviso que suena siempre no se lee. */
$meter('cargar', 'pendiente', '40 DAY');
chequear('una carga de hace 40 dias NO cuenta (es historia, no una emergencia)',
         $contar() === 1);

$meter('cargar', 'hecha', '40 MINUTE');
chequear('una carga ya hecha NO cuenta', $contar() === 1);


$pdo->prepare("DELETE FROM acciones_saldo WHERE usuario = ?")->execute([$U]);

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
