<?php
/**
 * t_bono.php — El bono de bienvenida se paga UNA vez, en la primera carga,
 * por todos los caminos que acreditan plata del camino B.
 *
 * LOS BUGS QUE CUBRE, encontrados el 3/9/2026 auditando el flujo completo:
 *
 *  - rl_acreditar_directo() (Comprobantes a mano) y hg_webhook.php (HG Cash)
 *    acreditaban SIN pasar por rl_acreditar(): la primera carga de un jugador
 *    de landing resuelta por esos caminos no daba bono nunca... y como no
 *    crean fila en `recargas`, la SEGUNDA carga (por recarga normal) contaba
 *    como "primera" y regalaba el bono sobre el monto equivocado. Ahora la
 *    logica vive en rl_bono_bienvenida_aplicar(), con un candado de UNA SOLA
 *    VEZ POR JUGADOR (el movimiento origen='bono_bienvenida', consultado con
 *    FOR UPDATE) que corta el doble pago entre caminos y entre carreras.
 *
 *  - crm_cargar() abria transaccion incondicional: llamada desde el hook de
 *    bonos prometidos DENTRO de la transaccion de rl_acreditar(), PDO lanzaba
 *    "already active" y el catch hacia rollBack() de la transaccion DEL
 *    CALLER — la acreditacion entera deshecha. El bono prometido no se aplico
 *    JAMAS en el camino B por esto.
 *
 *  - El reintento de un alta en 'error' conservaba el `origen` viejo: como
 *    alta_usuario_disponible() trata esas filas como nombre libre, otra
 *    persona re-registrando el mismo nombre heredaba (o perdia) el bono de
 *    una landing que jamas vio.
 *
 *  - La consulta del origen no filtraba estado: una fila zombie en 'error'
 *    le regalaba el bono a un tocayo creado despues por el panel.
 *
 * Corre contra la base de prueba. Limpia lo suyo (prefijo tbono).
 *
 *     php t_bono.php
 *     T_PORT=3307 php t_bono.php     (instancia descartable)
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
require_once __DIR__ . '/api/recargas_lib.php';   // trae landings_lib, crm_lib, crm_notificaciones
require_once __DIR__ . '/api/altas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

function limpiar(PDO $pdo): void {
    foreach (["DELETE FROM movimientos WHERE usuario LIKE 'tbono%'",
              "DELETE FROM recargas WHERE usuario LIKE 'tbono%'",
              "DELETE FROM pagos WHERE id_unico LIKE 'tbono%'",
              "DELETE FROM altas WHERE usuario LIKE 'tbono%'",
              "DELETE FROM usuarios WHERE username LIKE 'tbono%'",
              "DELETE FROM landings WHERE slug LIKE 'tbono%'",
              "DELETE FROM bonos_pendientes WHERE usuario LIKE 'tbono%'",
              "DELETE FROM notificaciones WHERE usuario LIKE 'tbono%'"] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}
limpiar($pdo);

// ---------------------------------------------------------------------------
// utilitarios: el mundo minimo que rl_acreditar() espera
// ---------------------------------------------------------------------------
$UID = 9100001;   // usuarios.id es el id de ganamos, sin auto_increment
function jugador(PDO $pdo, string $u): void {
    global $UID;
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus) VALUES (?, ?, 0, 0)")
        ->execute([$UID++, $u]);
}
function alta(PDO $pdo, string $u, string $origen, string $estado = 'ok'): void {
    $pdo->prepare("INSERT INTO altas (usuario, password, origen, estado) VALUES (?, 'x', ?, ?)")
        ->execute([$u, $origen, $estado]);
}
function recarga(PDO $pdo, string $u, int $coins, string $ref): array {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, estado, vence_en)
         VALUES (?, ?, ?, ?, ?, 'pendiente', DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
    )->execute([$ref, $u, $coins, $coins, $coins]);
    return ['id' => (int)$pdo->lastInsertId(), 'usuario' => $u, 'coins' => $coins, 'referencia' => $ref];
}
function pago(PDO $pdo, string $idUnico, int $monto, string $estado = 'pendiente'): void {
    $pdo->prepare("INSERT INTO pagos (id_unico, monto, estado) VALUES (?, ?, ?)")
        ->execute([$idUnico, $monto, $estado]);
}
/** Acredita como lo hace el matcher: rl_acreditar() DENTRO de una transaccion. */
function acreditar(PDO $pdo, array &$rec, string $idUnico): void {
    $pdo->beginTransaction();
    rl_acreditar($pdo, $rec, $idUnico, 'test', null, 'alta');
    $pdo->commit();
}
function bonus(PDO $pdo, string $u): int {
    $st = $pdo->prepare("SELECT bonus FROM usuarios WHERE username = ?");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
}
function movs_bono(PDO $pdo, string $u): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = ? AND origen = 'bono_bienvenida'");
    $st->execute([$u]);
    return (int)$st->fetchColumn();
}

// La landing de prueba: 40% de bienvenida.
$pdo->exec("INSERT INTO landings (slug, nombre, plantilla, bono_pct, activa)
            VALUES ('tbono-mega', 'Test bono', 'oro', 40, 1)");

// ---------------------------------------------------------------------------
echo "-- landing del CRM ('lp:'): primera recarga paga el % de SU fila --\n";
jugador($pdo, 'tbonoUno');
alta($pdo, 'tbonoUno', 'lp:tbono-mega');
$r = recarga($pdo, 'tbonoUno', 1000, 'tb1');
pago($pdo, 'tbono-p1', 1000);
acreditar($pdo, $r, 'tbono-p1');
chequear('primera recarga lp: suma el 40% en bonus', bonus($pdo, 'tbonoUno') === 400,
         'bonus=' . bonus($pdo, 'tbonoUno'));
chequear('queda UN movimiento bono_bienvenida', movs_bono($pdo, 'tbonoUno') === 1);
chequear('la recarga quedo marcada es_primera=1', ($r['es_primera'] ?? null) === 1);

echo "-- la segunda recarga no repite el bono --\n";
$r2 = recarga($pdo, 'tbonoUno', 500, 'tb2');
pago($pdo, 'tbono-p2', 500);
acreditar($pdo, $r2, 'tbono-p2');
chequear('el bonus no se toca en la segunda', bonus($pdo, 'tbonoUno') === 400);
chequear('sigue habiendo UN solo movimiento', movs_bono($pdo, 'tbonoUno') === 1);
chequear('la segunda quedo es_primera=0', ($r2['es_primera'] ?? null) === 0);

echo "-- 'bono50' (la landing fija) sigue andando --\n";
jugador($pdo, 'tbonoDos');
alta($pdo, 'tbonoDos', 'bono50');
$r = recarga($pdo, 'tbonoDos', 1000, 'tb3');
pago($pdo, 'tbono-p3', 1000);
acreditar($pdo, $r, 'tbono-p3');
chequear('bono50 paga RL_BONO_BIENVENIDA_PCT', bonus($pdo, 'tbonoDos') === (int)floor(1000 * RL_BONO_BIENVENIDA_PCT / 100),
         'bonus=' . bonus($pdo, 'tbonoDos'));

echo "-- sin promo no hay bono --\n";
jugador($pdo, 'tbonoTres');
alta($pdo, 'tbonoTres', 'landing');
$r = recarga($pdo, 'tbonoTres', 1000, 'tb4');
pago($pdo, 'tbono-p4', 1000);
acreditar($pdo, $r, 'tbono-p4');
chequear('origen landing: bonus queda en 0', bonus($pdo, 'tbonoTres') === 0);

echo "-- pausar la landing corta altas nuevas, NO promesas ya hechas --\n";
jugador($pdo, 'tbonoCuatro');
alta($pdo, 'tbonoCuatro', 'lp:tbono-mega');
$pdo->exec("UPDATE landings SET activa = 0 WHERE slug = 'tbono-mega'");
$r = recarga($pdo, 'tbonoCuatro', 1000, 'tb5');
pago($pdo, 'tbono-p5', 1000);
acreditar($pdo, $r, 'tbono-p5');
chequear('registrado antes de pausar: cobra igual', bonus($pdo, 'tbonoCuatro') === 400);

echo "-- una fila zombie en 'error' no regala nada --\n";
jugador($pdo, 'tbonoCinco');
alta($pdo, 'tbonoCinco', 'bono50', 'error');   // el alta NUNCA se concreto
$r = recarga($pdo, 'tbonoCinco', 1000, 'tb6');
pago($pdo, 'tbono-p6', 1000);
acreditar($pdo, $r, 'tbono-p6');
chequear('alta en error: bonus queda en 0', bonus($pdo, 'tbonoCinco') === 0);

echo "-- Comprobantes a mano (rl_acreditar_directo): paga el bono, y UNA vez --\n";
// El caso del doble pago viejo: primera transferencia resuelta a mano (sin
// fila en recargas) y despues una recarga normal que cuenta "cero previas".
jugador($pdo, 'tbonoSeis');
alta($pdo, 'tbonoSeis', 'lp:tbono-mega');      // la landing sigue pausada: da igual
pago($pdo, 'tbono-p7', 2000, 'revision');
$res = rl_acreditar_directo($pdo, 'tbono-p7', 'tbonoSeis', 2000, 'tester');
chequear('directo: acredita', ($res['resultado'] ?? '') === 'acreditada', json_encode($res));
chequear('directo: paga el 40% de bienvenida', bonus($pdo, 'tbonoSeis') === 800,
         'bonus=' . bonus($pdo, 'tbonoSeis'));
$r = recarga($pdo, 'tbonoSeis', 1000, 'tb7');
pago($pdo, 'tbono-p8', 1000);
acreditar($pdo, $r, 'tbono-p8');
chequear('la recarga posterior cuenta como primera (sin fila previa)...', ($r['es_primera'] ?? null) === 1);
chequear('...pero el candado evita el SEGUNDO bono', bonus($pdo, 'tbonoSeis') === 800,
         'bonus=' . bonus($pdo, 'tbonoSeis'));
chequear('un solo movimiento bono_bienvenida entre los dos caminos', movs_bono($pdo, 'tbonoSeis') === 1);

echo "-- el helper pelado (camino HG Cash, autocommit): una sola vez --\n";
jugador($pdo, 'tbonoSiete');
alta($pdo, 'tbonoSiete', 'bono50');
$b1 = rl_bono_bienvenida_aplicar($pdo, 'tbonoSiete', 1000);
$b2 = rl_bono_bienvenida_aplicar($pdo, 'tbonoSiete', 1000);
chequear('primera llamada paga', $b1 === (int)floor(1000 * RL_BONO_BIENVENIDA_PCT / 100), "b1=$b1");
chequear('segunda llamada devuelve 0', $b2 === 0, "b2=$b2");
chequear('bonus refleja UNA sola', bonus($pdo, 'tbonoSiete') === $b1);

echo "-- bono prometido (bonos_pendientes) dentro del camino B --\n";
// Antes crm_cargar() reventaba por transaccion anidada y su catch deshacia
// la acreditacion ENTERA del caller. Con el savepoint, aplica y no rompe.
jugador($pdo, 'tbonoOcho');
alta($pdo, 'tbonoOcho', 'landing');            // sin bienvenida, para aislar
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, estado) VALUES ('tbonoOcho', 'fichas', 300, 'pendiente')")->execute();
$r = recarga($pdo, 'tbonoOcho', 1000, 'tb8');
pago($pdo, 'tbono-p9', 1000);
acreditar($pdo, $r, 'tbono-p9');
$st = $pdo->prepare("SELECT coins FROM usuarios WHERE username = 'tbonoOcho'");
$st->execute();
chequear('la acreditacion sobrevive (coins sumados)', (int)$st->fetchColumn() === 1000);
chequear('el bono prometido SE aplica en el camino B', bonus($pdo, 'tbonoOcho') === 300,
         'bonus=' . bonus($pdo, 'tbonoOcho'));
$st = $pdo->prepare("SELECT estado FROM bonos_pendientes WHERE usuario = 'tbonoOcho'");
$st->execute();
chequear('bonos_pendientes queda aplicado', $st->fetchColumn() === 'aplicado');

echo "-- el reintento de un alta en 'error' pisa el origen viejo --\n";
$r = alta_encolar($pdo, ['usuario' => 'tbonoNueve', 'password' => 'clave123', 'origen' => 'lp:tbono-mega']);
chequear('primer alta entra', !empty($r['cuerpo']['ok']), json_encode($r['cuerpo'] ?? []));
$pdo->exec("UPDATE altas SET estado = 'error' WHERE usuario = 'tbonoNueve'");
$r = alta_encolar($pdo, ['usuario' => 'tbonoNueve', 'password' => 'clave456', 'origen' => 'landing']);
chequear('reintento entra', !empty($r['cuerpo']['ok']), json_encode($r['cuerpo'] ?? []));
$st = $pdo->prepare("SELECT origen, estado FROM altas WHERE usuario = 'tbonoNueve'");
$st->execute();
$fila = $st->fetch();
chequear('el origen es el del pedido NUEVO, no el zombie', ($fila['origen'] ?? '') === 'landing',
         'origen=' . ($fila['origen'] ?? '?'));
chequear('y vuelve a la cola', ($fila['estado'] ?? '') === 'pendiente');

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
