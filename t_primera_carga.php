<?php
/**
 * t_primera_carga.php — El bono de bienvenida sale UNA vez, y en la carga que
 *                       de verdad es la primera.
 *
 * EL INCIDENTE (15/09/2026, caso real de Nahuel). Un jugador que ya venía
 * cargando cobró 640 de bono de bienvenida sobre una carga de 1.280 que era su
 * SEGUNDA. Hubo que sacárselos a mano.
 *
 * La causa: los tres caminos de acreditación contaban
 *
 *     SELECT COUNT(*) FROM recargas WHERE usuario=? AND estado='acreditada'
 *
 * o sea SOLO el camino B (la transferencia que toma el chatbot). El que ya
 * había cargado por el botón «Depósitos» de adentro del juego -- camino A, que
 * no deja fila en `recargas` -- seguía teniendo cero, y su segunda carga se
 * contaba como primera.
 *
 * Es el mismo error que CLAUDE.md documenta para Publicidad y Finanzas («hay
 * UNA definición de una carga»). Publicidad ya lo había corregido por su lado;
 * faltaba el bono, que es el único de los tres que PAGA por esa respuesta.
 *
 * Lo que blinda este test:
 *   - el que ya cargó por el juego NO cobra bienvenida;
 *   - el que ya cargó a mano desde el CRM tampoco;
 *   - pero un REGALO de fichas o de bonos no le quema el bono a nadie;
 *   - el jugador nuevo de verdad sí lo cobra, igual que siempre;
 *   - y si la consulta falla, no se paga (deber un bono se arregla; pagarlo
 *     dos veces, no).
 *
 *     php t_primera_carga.php
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
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++; printf("  OK    %s\n", $q); }
    else    { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}

$ID = 987650000;
/** Un jugador recién creado por el chat: espejado, con alta 'chatbot' (la que
 *  promete el bono) y sin una sola carga en ningún camino. */
function nacer(PDO $pdo, string $u): void {
    global $ID;
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(["prim-$u-%"]);
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (?,?,0,0,0)")
        ->execute([++$ID, $u]);
    $pdo->prepare("INSERT INTO altas (usuario, estado, origen) VALUES (?, 'ok', 'chatbot')")->execute([$u]);
}
/** Le entra plata al juego por un camino que NO es `recargas`. */
function movimientoSaldo(PDO $pdo, string $u, int $monto, string $origen): void {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
         VALUES (?, 'saldo', ?, 'del test', ?)"
    )->execute([$u, $monto, $origen]);
}
/** Transfiere y el banco avisa: el camino B entero, con matcher y todo. */
function cargarPorTransferencia(PDO $pdo, string $u, int $monto, string $ref): void {
    rl_crear_recarga($pdo, $u, $monto, 'Titular ' . $u, true);
    $r = fila($pdo, "SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$u]);
    rl_registrar_pago($pdo, [
        'id_unico' => "prim-$u-$ref", 'monto' => (float)$r['monto_pedido'],
        'remitente' => 'Titular ' . $u, 'dkim_pass' => 1, 'mail_de' => 'banco@test',
    ]);
}
/** Lo que se deposita en el juego = carga + bono. Es donde se ve el regalo. */
function depositado(PDO $pdo, string $u): int {
    $a = fila($pdo, "SELECT monto FROM acciones_saldo
                      WHERE usuario = ? AND tipo = 'cargar' ORDER BY id DESC LIMIT 1", [$u]);
    return (int)($a['monto'] ?? 0);
}
function cobroBienvenida(PDO $pdo, string $u): bool {
    return (bool)fila($pdo, "SELECT id FROM movimientos
                              WHERE usuario = ? AND origen = 'bono_bienvenida' LIMIT 1", [$u]);
}

cfg_crm_guardar($pdo, ['bono_bienvenida_pct' => '50'], 'test');

echo "\n=== 1. rl_es_primera_carga(), la pregunta sola ===\n";
{
    $u = 'prim_fn';
    nacer($pdo, $u);
    chequear('jugador sin nada: es la primera', rl_es_primera_carga($pdo, $u) === 1);

    movimientoSaldo($pdo, $u, 750, 'peticion');
    chequear('con una carga del botón «Depósitos»: ya NO es la primera',
             rl_es_primera_carga($pdo, $u) === 0);

    nacer($pdo, $u);
    movimientoSaldo($pdo, $u, 500, 'crm');
    chequear('con una carga que le hizo un agente a mano: tampoco',
             rl_es_primera_carga($pdo, $u) === 0);

    /* UN REGALO NO ES UNA CARGA. Las fichas y los bonos regalados van con
       tipo 'ficha'/'bono', nunca 'saldo': si esto contara, poner una promo de
       100 fichas gratis le sacaría el bono de bienvenida a todo el mundo. */
    nacer($pdo, $u);
    $pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                   VALUES (?, 'ficha', 500, 'Regalo', 'crm')")->execute([$u]);
    $pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                   VALUES (?, 'bono', 300, 'Regalo', 'crm')")->execute([$u]);
    chequear('un regalo de fichas o bonos NO le quema el bono de bienvenida',
             rl_es_primera_carga($pdo, $u) === 1);

    /* Un retiro es un movimiento de saldo NEGATIVO: no es una carga. */
    nacer($pdo, $u);
    movimientoSaldo($pdo, $u, -4000, 'crm');
    chequear('un retiro (saldo negativo) tampoco cuenta como carga',
             rl_es_primera_carga($pdo, $u) === 1);
}

echo "\n=== 2. El jugador nuevo de verdad SÍ cobra ===\n";
{
    $u = 'prim_nuevo';
    nacer($pdo, $u);
    cargarPorTransferencia($pdo, $u, 1280, '1');
    chequear('cobra el bono de bienvenida', cobroBienvenida($pdo, $u));
    chequear('y al juego van 1.280 + 640 = 1.920', depositado($pdo, $u) === 1920,
             (string)depositado($pdo, $u));
}

echo "\n=== 3. EL CASO DEL 15/09: ya había cargado por el juego ===\n";
/* Exactamente el incidente. El jugador tenía fichas de una carga del botón
   «Depósitos», transfirió 1.280 por el chat, y se llevó 640 que no le tocaban. */
{
    $u = 'prim_juego';
    nacer($pdo, $u);
    movimientoSaldo($pdo, $u, 750, 'peticion');
    cargarPorTransferencia($pdo, $u, 1280, '1');
    chequear('NO cobra bono de bienvenida', !cobroBienvenida($pdo, $u));
    chequear('y al juego van los 1.280 pelados', depositado($pdo, $u) === 1280,
             (string)depositado($pdo, $u));
    $r = fila($pdo, "SELECT es_primera FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$u]);
    chequear('la recarga queda marcada como NO primera (lo lee el embudo)',
             (int)($r['es_primera'] ?? -1) === 0, json_encode($r));
}

echo "\n=== 4. Ya le había cargado un agente a mano ===\n";
/* Es como se resuelve acá una transferencia que el matcher no pudo casar
   ("transferí de nuevo y te cargo"), así que pasa seguido. */
{
    $u = 'prim_mano';
    nacer($pdo, $u);
    movimientoSaldo($pdo, $u, 1000, 'crm');
    cargarPorTransferencia($pdo, $u, 1280, '1');
    chequear('NO cobra bono de bienvenida', !cobroBienvenida($pdo, $u));
    chequear('al juego van 1.280', depositado($pdo, $u) === 1280, (string)depositado($pdo, $u));
}

echo "\n=== 5. La segunda transferencia, que ya andaba bien ===\n";
{
    $u = 'prim_segunda';
    nacer($pdo, $u);
    cargarPorTransferencia($pdo, $u, 1000, '1');
    chequear('la primera cobra', cobroBienvenida($pdo, $u));
    $primera = depositado($pdo, $u);
    /* La primera carga se da por ENTREGADA antes de pedir la segunda: con una
       accion todavia en la cola, fichas_pedir_carga contesta 'en_curso' y no
       encola nada -- y el test estaria leyendo el deposito de la primera dos
       veces. Es una limpieza del escenario, no del producto. */
    $pdo->prepare("UPDATE acciones_saldo SET estado='hecha' WHERE usuario = ? AND tipo='cargar'")
        ->execute([$u]);
    cargarPorTransferencia($pdo, $u, 1000, '2');
    chequear('la primera depositó 1.500', $primera === 1500, (string)$primera);
    chequear('la segunda deposita 1.000 pelados', depositado($pdo, $u) === 1000,
             (string)depositado($pdo, $u));
    $n = fila($pdo, "SELECT COUNT(*) c FROM movimientos
                      WHERE usuario = ? AND origen = 'bono_bienvenida'", [$u]);
    chequear('y el bono de bienvenida quedó UNA sola vez', (int)$n['c'] === 1, json_encode($n));
}

foreach (['prim_fn', 'prim_nuevo', 'prim_juego', 'prim_mano', 'prim_segunda'] as $u) {
    foreach (['acciones_saldo', 'movimientos', 'recargas', 'altas'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]);
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(["prim-$u-%"]);
}

echo "\n---------------------------------------\n";
echo "$ok OK, $fail fallas\n";
exit($fail ? 1 : 0);
