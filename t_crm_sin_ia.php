<?php
/**
 * t_crm_sin_ia.php — El candado del CRM "solo bandeja".
 *
 * QUÉ CUIDA. El dueño vende dos CRM: con chatbot IA y "solo bandeja" (lo
 * atiende una persona). El modo sin IA ya existía (apagando los switches
 * locales del cliente), pero el cliente podía volver a prenderlos desde su
 * CRM -- y como la clave de IA es la GLOBAL compartida, eso le regala nuestro
 * presupuesto de API. rl_crm_con_ia() es el candado: lo lee del CONTROL, así
 * el cliente no puede abrirlo.
 *
 * Se corre la función REAL (no una copia), con un control SQLite en memoria
 * inyectado por el mismo override que usan los tests de HG Cash.
 *
 *     php t_crm_sin_ia.php
 */
declare(strict_types=1);

if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/recargas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/** Un control SQLite con la tabla clientes y las filas que se le pidan. */
function control_con(array $filas, bool $conColumna = true): PDO {
    $pdo = new PDO('sqlite::memory:', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $colIa = $conColumna ? ', crm_con_ia INTEGER DEFAULT 1' : '';
    $pdo->exec("CREATE TABLE clientes (db_nombre TEXT, estado TEXT$colIa)");
    foreach ($filas as $f) {
        if ($conColumna) {
            $pdo->prepare("INSERT INTO clientes (db_nombre, estado, crm_con_ia) VALUES (?,?,?)")
                ->execute([$f['db'], $f['estado'] ?? 'activo', $f['ia'] ?? 1]);
        } else {
            $pdo->prepare("INSERT INTO clientes (db_nombre, estado) VALUES (?,?)")
                ->execute([$f['db'], $f['estado'] ?? 'activo']);
        }
    }
    return $pdo;
}

// Sub-proceso: evalúa UN caso y escribe "1"/"0". Así el cache static arranca
// limpio en cada uno sin trucos.
if (($argv[1] ?? '') === '--caso') {
    $d = unserialize(base64_decode($argv[2]));
    $GLOBALS['TENANT_DB'] = $d['db'];
    if (!$d['nullctl']) {
        // $d['col'] = sinColumna; conColumna es su negación.
        $GLOBALS['HG_CONTROL_OVERRIDE'] = control_con($d['filas'], !$d['col']);
    } else {
        // Control "caído": override a un PDO que falla al consultar.
        $bad = new PDO('sqlite::memory:');   // sin tabla clientes -> query lanza
        $GLOBALS['HG_CONTROL_OVERRIDE'] = $bad;
    }
    echo rl_crm_con_ia() ? '1' : '0';
    exit;
}

function evaluar(array $filas, string $tenantDb, bool $sinColumna = false, bool $ctlCaido = false): bool {
    $payload = base64_encode(serialize([
        'filas' => $filas, 'col' => $sinColumna, 'db' => $tenantDb, 'nullctl' => $ctlCaido,
    ]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --caso ' . escapeshellarg($payload);
    return trim((string)shell_exec($cmd)) === '1';
}

// ===========================================================================
echo "=== 1. El candado: con IA permite, sin IA bloquea ===\n";
chequear('un cliente con crm_con_ia=1 permite la IA',
         evaluar([['db' => 'gp_conia', 'ia' => 1]], 'gp_conia') === true);
chequear('un cliente con crm_con_ia=0 la bloquea',
         evaluar([['db' => 'gp_sinia', 'ia' => 0]], 'gp_sinia') === false,
         'ESTE es el candado: sin esto el cliente prende su switch y gasta nuestra API');

echo "\n=== 2. Default-allow: nunca apaga la IA de todos por una duda ===\n";
/* Si esto devolviera false ante cualquier problema, un control caído apagaría
   el chatbot de TODOS los clientes, incluido el nuestro -- el producto
   principal. Por eso solo un 0 EXPLÍCITO bloquea. */
chequear('un tenant que no está en el control permite (no es cliente del panel)',
         evaluar([['db' => 'otro', 'ia' => 0]], 'gp_ganamoscrm') === true,
         'nuestro propio sitio entra por dominio directo, sin fila: no se puede autobloquear');
chequear('sin la columna (migración 16 sin correr) permite',
         evaluar([['db' => 'gp_x']], 'gp_x', true) === true);
chequear('con el control caído permite (manda el switch local del cliente)',
         evaluar([], 'gp_x', false, true) === true,
         'un control caído no puede apagar el chatbot de todos');

echo "\n=== 3. Solo cuenta el cliente ACTIVO de ESE tenant ===\n";
chequear('un cliente pausado con ia=0 NO bloquea a otro tenant',
         evaluar([['db' => 'gp_pausado', 'estado' => 'baja', 'ia' => 0]], 'gp_otro') === true);
chequear('se resuelve por db_nombre, no se mezclan tenants',
         evaluar([['db' => 'gp_a', 'ia' => 0], ['db' => 'gp_b', 'ia' => 1]], 'gp_b') === true);
chequear('y el de al lado sí queda bloqueado',
         evaluar([['db' => 'gp_a', 'ia' => 0], ['db' => 'gp_b', 'ia' => 1]], 'gp_a') === false);

// ===========================================================================
echo "\n=== 4. El chatbot usa el candado en el gate ===\n";
/* El candado no sirve si el entry point no lo mira. Se chequea que $botActivo
   incluya rl_crm_con_ia() -- junto a los dos switches locales, con AND. */
$chat = file_get_contents(__DIR__ . '/api/chatbot.php');
chequear('botActivo incluye rl_crm_con_ia()',
         (bool)preg_match('/\$botActivo\s*=\s*\$cfgBot\[.activo.\]\s*&&\s*cfg_crm_activo\([^)]*\)\s*&&\s*rl_crm_con_ia\(\)/', $chat),
         'si el gate no lo mira, el candado no apaga nada');

// ===========================================================================
echo "\n=== 5. El panel guarda y edita el modo ===\n";
$panel = file_get_contents(__DIR__ . '/panel/panel.php');
chequear('crear acepta crm_con_ia (default 1 = con IA)',
         str_contains($panel, "array_key_exists('crm_con_ia', \$in)")
         && str_contains($panel, '$colsIa   = $hayIaCol'));
chequear('la landing no lleva modo IA (no tiene chat)',
         str_contains($panel, "(\$producto === 'landing') ? 1 :"));
chequear('editar lo toca solo si vino Y la columna existe',
         str_contains($panel, "array_key_exists('crm_con_ia', \$in) && tiene_col_clientes(\$pdo, 'crm_con_ia')"),
         'sin la guarda, un control sin migrar reventaría el UPDATE entero');

$prov = file_get_contents(__DIR__ . '/panel/provisionar.php');
chequear('provisionar apaga el chat solo de los sin-IA, nunca lo prende',
         str_contains($prov, 'crm_con_ia = 0')
         && str_contains($prov, "valor = '0'")
         && !str_contains($prov, "valor = '1'"),
         'tocar los con-IA pelearía con el toggle del cliente');

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
