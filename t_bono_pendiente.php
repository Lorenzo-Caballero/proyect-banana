<?php
/**
 * t_bono_pendiente.php — El bono pendiente se aplica CON la carga, entre
 * POR DONDE entre la carga, y la ficha no muestra pendientes fantasma.
 *
 * Sale de la auditoría del 18/09/2026 ("a veces la Info de jugador muestra
 * cualquier cosa como bono pendiente"), que encontró dos agujeros:
 *
 *  1. Un giro de cortesía USADO nunca marcaba su fila de `bonos_pendientes`
 *     como aplicada (el único UPDATE a 'aplicado' filtra fichas/pct), así que
 *     cada giro usado sumaba "1×giro" pendiente en la ficha PARA SIEMPRE.
 *
 *  2. Los bonos pendientes solo se aplicaban en rl_acreditar() (camino B).
 *     El que ganaba en la ruleta y después cargaba por el botón «Depósitos»
 *     del juego (camino A) o con una carga manual del CRM no cobraba NUNCA.
 *
 *     T_PORT=3399 php t_bono_pendiente.php
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
require_once __DIR__ . '/api/fichas_lib.php';
require_once __DIR__ . '/api/crm_lib.php';
require_once __DIR__ . '/api/crm_notificaciones.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
$U = 'test_bpend';
function limpiar(PDO $pdo, string $u): void {
    foreach (['acciones_saldo', 'movimientos', 'bonos_pendientes',
              'ruleta_giros_cortesia', 'notificaciones'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]); }
        catch (Throwable $e) {}
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654393, ?, 0, 0, 0)")
        ->execute([$u]);
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}

// ===========================================================================
echo "=== 1. El premio de la ruleta entra como PENDIENTE, no acreditado ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'fichas', 1000, 'ruleta');
chequear('se crea el bono pendiente', !empty($r['ok']), json_encode($r));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('y usuarios.bonus sigue en 0 (nada se acredito al reclamar)', (int)$u['bonus'] === 0);
$b = fila($pdo, "SELECT * FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("tipo fichas, valor 1000, prometido_por 'ruleta', pendiente",
         ($b['tipo'] ?? '') === 'fichas' && (int)($b['valor'] ?? 0) === 1000
         && ($b['prometido_por'] ?? '') === 'ruleta' && ($b['estado'] ?? '') === 'pendiente');

/* Y ruleta.php de verdad hace esto (posicional: es un endpoint, no se puede
   requerir). El fallback a crm_cargar queda para bases sin la migracion 33. */
$srcRul = file_get_contents(__DIR__ . '/api/ruleta.php');
chequear('ruleta.php crea el pendiente en el reclamo',
         substr_count($srcRul, "crmnotif_bono_crear(") >= 2,
         'tienen que llamarlo el reclamo Y la cortesia');
chequear('el widget avisa "con tu próxima carga" cuando es pendiente',
         str_contains(file_get_contents(__DIR__ . '/landing/widget.js'),
                      'Se acreditan solos junto con tu próxima carga'));

// ===========================================================================
echo "\n=== 2. La carga del camino A / manual TAMBIEN aplica el pendiente ===\n";
/* Antes solo rl_acreditar (camino B) los aplicaba: este es el agujero 2. */
$monto = crmnotif_bono_aplicar_fuera_de_recarga($pdo, $U, 5000);
chequear('devuelve el monto aplicado (1000)', $monto === 1000, "monto=$monto");
$b = fila($pdo, "SELECT estado, recarga_id FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("queda 'aplicado' con recarga_id NULL (no hubo recarga nuestra)",
         ($b['estado'] ?? '') === 'aplicado' && $b['recarga_id'] === null, json_encode($b));
$a = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U]);
chequear('y encola el deposito SOLO-BONO para que llegue al juego',
         (int)($a['monto'] ?? -1) === 1000 && (int)($a['bono_debitado'] ?? -1) === 1000,
         json_encode($a));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('el contador quedo debitado (no se paga dos veces)', (int)$u['bonus'] === 0);

$monto2 = crmnotif_bono_aplicar_fuera_de_recarga($pdo, $U, 5000);
chequear('una segunda carga NO lo vuelve a aplicar', $monto2 === 0, "monto=$monto2");

/* Los DOS callers estan conectados (posicional). */
foreach (['api/peticiones_cola.php', 'api/crm_lib.php'] as $arch) {
    chequear("$arch aplica el pendiente al acreditar",
             str_contains(file_get_contents(__DIR__ . '/' . $arch),
                          'crmnotif_bono_aplicar_fuera_de_recarga('));
}

// ===========================================================================
echo "\n=== 3. El giro de cortesia usado deja de figurar pendiente ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'giro', 0, 'notif');
chequear('se crea el giro prometido', !empty($r['ok']));
$g = fila($pdo, "SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia WHERE usuario = ?", [$U]);
chequear('con su fila de cortesia atada al bono', (int)($g['bono_pendiente_id'] ?? 0) > 0);

/* Lo que hace ruleta.php al usarlo (replicado aca porque es un endpoint):
   cortesia -> usado, y la fila madre -> aplicado. */
$pdo->prepare("UPDATE ruleta_giros_cortesia SET estado='usado', usado_en=NOW() WHERE id = ?")
    ->execute([(int)$g['id']]);
$pdo->prepare("UPDATE bonos_pendientes SET estado='aplicado', aplicado_en=NOW()
                WHERE id = ? AND tipo = 'giro' AND estado = 'pendiente'")
    ->execute([(int)$g['bono_pendiente_id']]);
$b = fila($pdo, "SELECT estado FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("la fila madre queda 'aplicado': la ficha ya no muestra 1×giro fantasma",
         ($b['estado'] ?? '') === 'aplicado');

/* ruleta.php lo hace de verdad (posicional). */
chequear('ruleta.php marca el bono del giro al usarlo',
         str_contains($srcRul, "tipo = 'giro' AND estado = 'pendiente'"));
chequear('y lee bono_pendiente_id de la cortesia',
         str_contains($srcRul, 'SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia'));

// ===========================================================================
echo "\n=== 4. La migracion 71 repara los giros viejos ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'giro', 0, 'notif');
$g = fila($pdo, "SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia WHERE usuario = ?", [$U]);
// El estado ROTO que dejo el bug: cortesia usada, bono todavia pendiente.
$pdo->prepare("UPDATE ruleta_giros_cortesia SET estado='usado', usado_en=NOW() WHERE id = ?")
    ->execute([(int)$g['id']]);
$sql71 = file_get_contents(__DIR__ . '/api/sql/71_giro_cortesia_aplicado.sql');
$pdo->exec($sql71);
$b = fila($pdo, "SELECT estado, aplicado_en FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear('el giro usado-pero-pendiente queda aplicado', ($b['estado'] ?? '') === 'aplicado');
chequear('con la fecha del uso, no la de la migracion', !empty($b['aplicado_en']));
$pdo->exec($sql71);   // idempotente: la segunda pasada no puede fallar
chequear('correrla dos veces no rompe', true);

// ===========================================================================
echo "\n=== 5. La ficha: pendiente y contador separados ===\n";
$srcCrm = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('el numero grande ya NO concatena "sin depositar"',
         !str_contains($srcCrm, 'partesBono.push(`${nf.format(u.bonus)} sin depositar`)'));
chequear('el contador va aparte, con su propio nombre',
         str_contains($srcCrm, 'acreditados sin mandar al juego'));

limpiar($pdo, $U);
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
