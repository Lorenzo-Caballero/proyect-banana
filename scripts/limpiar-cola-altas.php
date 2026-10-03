<?php
/**
 * limpiar-cola-altas.php — Vaciar la cola de altas de un cliente.
 *
 * CUÁNDO SIRVE Y CUÁNDO NO, porque es fácil confundirlo. Una cola larga NO
 * traba al bot: el bot toma de a lotes y, si no procesa, es por otra cosa
 * (típicamente que el panel le rechaza el login). Vaciarla no arregla nada de
 * eso — lo único que cambia es que dejás de ver los pedidos.
 *
 * Donde SÍ sirve: después de probar, para sacar del medio las cuentas de
 * prueba y que la cola vuelva a mostrar solo lo real. Que es justamente lo que
 * hace útil este script: muestra QUIÉN está esperando antes de tocar nada.
 *
 * ============================================================================
 * CADA FILA ES UNA PERSONA QUE SE REGISTRÓ Y ESTÁ ESPERANDO SU CUENTA. Esa es
 * toda la razón del dry-run y de que imprima los usuarios uno por uno: borrar
 * el pedido de un jugador real lo deja afuera para siempre y sin rastro — él no
 * se entera de nada, simplemente nunca puede entrar.
 *
 * Por eso tampoco borra las que ya salieron (`ok`): esas son historia, y el
 * espejo y los movimientos se apoyan en ellas.
 *
 *   php scripts/limpiar-cola-altas.php --slug=leandro            # muestra
 *   php scripts/limpiar-cola-altas.php --slug=leandro --aplicar  # borra
 *   ... --solo-prueba    borra únicamente las que parecen de prueba
 */

declare(strict_types=1);

$opts = getopt('', ['slug:', 'aplicar', 'solo-prueba']);
$slug    = (string)($opts['slug'] ?? '');
$aplicar = isset($opts['aplicar']);
$soloPru = isset($opts['solo-prueba']);

if ($slug === '') {
    fwrite(STDERR, "Falta --slug=<cliente>. Ej: php scripts/limpiar-cola-altas.php --slug=leandro\n");
    exit(1);
}

$cfgPath = getenv('GP_PANEL_CONFIG') ?: (__DIR__ . '/../panel/panel_config.php');
$cfg = require $cfgPath;

try {
    $ctl = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'No pude conectar al control: ' . $e->getMessage() . "\n");
    exit(1);
}

$q = $ctl->prepare("SELECT db_nombre, nombre FROM clientes WHERE slug = ? AND estado = 'activo'");
$q->execute([$slug]);
$filas = $q->fetchAll();

if (count($filas) !== 1) {
    fwrite(STDERR, count($filas) === 0
        ? "No hay un cliente activo con slug '$slug'.\n"
        : "Hay " . count($filas) . " clientes activos con ese slug: resolvelo antes de borrar nada.\n");
    exit(1);
}
$db = (string)$filas[0]['db_nombre'];

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$db};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "No pude abrir la base $db: " . $e->getMessage() . "\n");
    exit(1);
}

/* Lo que PARECE de prueba. Deliberadamente conservador: en la duda NO es de
   prueba, porque el error caro es borrar la de alguien real. */
function es_prueba(string $u): bool
{
    return (bool)preg_match('/prueba|test|zzp|asdf|qwer/i', $u);
}

$pend = $pdo->query(
    "SELECT id, usuario, estado, intentos, origen,
            TIMESTAMPDIFF(MINUTE, pedido_en, NOW()) AS espera
       FROM altas
      WHERE estado IN ('pendiente', 'procesando')
      ORDER BY pedido_en"
)->fetchAll();

if (!$pend) {
    echo "La cola de '$slug' ya está vacía.\n";
    exit(0);
}

echo ($aplicar ? "=== BORRANDO ===" : "=== VISTA PREVIA (no se toca nada) ===") . "\n";
echo "Cliente: {$filas[0]['nombre']} ($slug), base $db\n\n";

$aBorrar = [];
foreach ($pend as $p) {
    $pru = es_prueba((string)$p['usuario']);
    $va  = !$soloPru || $pru;
    printf("  %s %-32s %-12s %2d intento(s)  %4d min  %s%s\n",
        $va ? 'x' : '·',
        $p['usuario'], $p['estado'], (int)$p['intentos'], (int)$p['espera'],
        (string)$p['origen'], $pru ? '  (parece de prueba)' : '');
    if ($va) { $aBorrar[] = (int)$p['id']; }
}

echo "\n" . count($aBorrar) . ' de ' . count($pend) . " se " . ($aplicar ? 'borran' : 'borrarían') . ".\n";

/* EL AVISO QUE IMPORTA. Una fila que no parece de prueba es, hasta donde este
   script puede saber, una persona que se registró y está esperando. */
$reales = count($aBorrar) - count(array_filter($pend, fn($p) => es_prueba((string)$p['usuario'])
                                                             && in_array((int)$p['id'], $aBorrar, true)));
if ($reales > 0) {
    echo "\nOJO: $reales no parece(n) de prueba. Si son jugadores de verdad, se registraron\n";
    echo "y están esperando su cuenta: borrarlos los deja afuera sin que se enteren.\n";
    echo "Para borrar SOLO las de prueba: agregá --solo-prueba\n";
}

if (!$aBorrar) { exit(0); }

if (!$aplicar) {
    echo "\nPara aplicarlo, repetí el comando con --aplicar\n";
    exit(0);
}

$in = implode(',', $aBorrar);
$n = $pdo->exec("DELETE FROM altas WHERE id IN ($in) AND estado IN ('pendiente','procesando')");
echo "\nBorradas: $n\n";
echo "Recordá: esto NO destraba al bot. Si no procesaba, sigue sin procesar —\n";
echo "mirá `bot_altas` en salud_bot.php para saber por qué.\n";
exit(0);
