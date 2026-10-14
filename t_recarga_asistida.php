<?php
/**
 * t_recarga_asistida.php — El modo asistido: la IA atiende, el humano aprueba.
 *
 * QUÉ CUIDA:
 *  1. El candado rl_recarga_auto() -- quién decide si el matcher acredita solo
 *     o deja la recarga esperando aprobación. Mismo patrón default-allow que el
 *     de IA, pero acá pesa más: un control caído NO puede trabar la plata de
 *     todos. Se corre la función REAL con control SQLite inyectado.
 *  2. El gate del matcher: reservar en vez de acreditar, ANTES de sumar fichas.
 *  3. La aprobación idempotente: el candado contra acreditar dos veces la misma
 *     transferencia (dos agentes, o doble click) es el rowCount de un UPDATE
 *     condicional, no un chequeo previo.
 *
 * Lo estructural se chequea sobre el código (el flujo completo de acreditación
 * necesita MariaDB con todo el esquema; acá no hay).
 *
 *     php t_recarga_asistida.php
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

// ---- subproceso: evalúa rl_recarga_auto() con un control fresco por caso ----
if (($argv[1] ?? '') === '--caso') {
    $d = unserialize(base64_decode($argv[2]));
    $GLOBALS['TENANT_DB'] = $d['db'];
    if ($d['nullctl']) {
        $GLOBALS['HG_CONTROL_OVERRIDE'] = new PDO('sqlite::memory:');   // sin tabla -> lanza
    } else {
        $pdo = new PDO('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $colR = $d['col'] ? '' : ', recarga_auto INTEGER DEFAULT 1';   // $d['col'] = sinColumna
        $pdo->exec("CREATE TABLE clientes (db_nombre TEXT, estado TEXT$colR)");
        foreach ($d['filas'] as $f) {
            if ($d['col']) {
                $pdo->prepare("INSERT INTO clientes (db_nombre, estado) VALUES (?,?)")
                    ->execute([$f['db'], $f['estado'] ?? 'activo']);
            } else {
                $pdo->prepare("INSERT INTO clientes (db_nombre, estado, recarga_auto) VALUES (?,?,?)")
                    ->execute([$f['db'], $f['estado'] ?? 'activo', $f['auto'] ?? 1]);
            }
        }
        $GLOBALS['HG_CONTROL_OVERRIDE'] = $pdo;
    }
    echo rl_recarga_auto() ? '1' : '0';
    exit;
}
function auto(array $filas, string $db, bool $sinCol = false, bool $ctlCaido = false): bool {
    $pay = base64_encode(serialize(['filas' => $filas, 'col' => $sinCol, 'db' => $db, 'nullctl' => $ctlCaido]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --caso ' . escapeshellarg($pay);
    return trim((string)shell_exec($cmd)) === '1';
}

// ===========================================================================
echo "=== 1. El candado rl_recarga_auto ===\n";
chequear('recarga_auto=1 -> el matcher acredita solo (lo de siempre)',
         auto([['db' => 'gp_auto', 'auto' => 1]], 'gp_auto') === true);
chequear('recarga_auto=0 -> la recarga espera aprobación',
         auto([['db' => 'gp_asis', 'auto' => 0]], 'gp_asis') === false);

echo "\n=== 2. Default-allow: una duda NUNCA traba la plata de todos ===\n";
/* Si esto devolviera false ante un problema, un control caído dejaría toda
   recarga esperando una aprobación que nadie sabe que debe dar -- la plata de
   la gente trabada. Por eso solo un 0 explícito activa el modo manual. */
chequear('control caído -> acredita solo (no traba)',
         auto([], 'gp_x', false, true) === true,
         'un control caído no puede trabar las recargas de todos');
chequear('sin la columna (migración 17 sin correr) -> acredita solo',
         auto([['db' => 'gp_x']], 'gp_x', true) === true);
chequear('tenant sin fila en el control -> acredita solo',
         auto([['db' => 'otro', 'auto' => 0]], 'gp_ganamoscrm') === true);
chequear('un cliente pausado con auto=0 NO traba a otro tenant',
         auto([['db' => 'gp_p', 'estado' => 'baja', 'auto' => 0]], 'gp_otro') === true);

// ===========================================================================
echo "\n=== 3. El gate del matcher: reservar antes de acreditar ===\n";
$lib = file_get_contents(__DIR__ . '/api/recargas_lib.php');
/* El gate tiene que estar ANTES de rl_acreditar en rl_matchear_y_acreditar:
   si estuviera después, ya habría sumado las fichas. */
$posGate = strpos($lib, 'if (!rl_recarga_auto()) {');
$posAcred = strpos($lib, 'rl_acreditar($pdo, $recarga, $idUnico, $conf, null, $confianza);');
chequear('el gate asistido existe en el matcher',
         $posGate !== false);
chequear('y decide ANTES de acreditar',
         $posGate !== false && $posAcred !== false && $posGate < $posAcred,
         'si decide después, ya acreditó: el modo no sirve');
chequear('reservar NO suma fichas (no toca usuarios.coins)',
         (bool)preg_match('/function rl_reservar_para_aprobar.*?\n\}/s', $lib, $m)
         && !str_contains($m[0], 'coins = coins'),
         'si sumara coins acá, no sería "esperar aprobación"');
chequear('reservar ata el pago a la recarga (para que el click no adivine)',
         isset($m[0]) && str_contains($m[0], "estado='por_aprobar'")
         && str_contains($m[0], 'pago_id=?'));

// ===========================================================================
echo "\n=== 4. La aprobación, a prueba de doble acreditación ===\n";
/* EL riesgo central: acreditar dos veces la misma transferencia (dos agentes,
   o un doble click). La guarda es un UPDATE condicional a 'por_aprobar' y su
   rowCount -- no un SELECT previo que otra request pueda pisar en el medio. */
preg_match('/function rl_aprobar_recarga.*?\n\}/s', $lib, $ap);
$apSrc = $ap[0] ?? '';
chequear('toma la recarga con UPDATE condicional a por_aprobar',
         (bool)preg_match("/UPDATE recargas SET estado='pendiente'\s*WHERE id=\? AND estado='por_aprobar'/s", $apSrc));
chequear('y el rowCount es el candado (si no cambió 1 fila, corta)',
         str_contains($apSrc, 'rowCount() !== 1'),
         'sin esto, dos agentes acreditan dos veces');
chequear('reusa rl_acreditar (un solo camino de acreditación)',
         str_contains($apSrc, 'rl_acreditar($pdo, $recarga, $idUnico'),
         'un segundo camino de acreditar se iría separando del automático');
chequear('corre los mismos pasos post-acreditación',
         str_contains($apSrc, 'rl_cargar_al_juego_auto')
         && str_contains($apSrc, 'rl_notificar_acreditada')
         && str_contains($apSrc, 'rl_reportar_purchase'));
chequear('distingue ya_resuelta de no_existe (para el CRM)',
         str_contains($apSrc, "'ya_resuelta'") && str_contains($apSrc, "'no_existe'"));

// ===========================================================================
echo "\n=== 5. El endpoint y la migración ===\n";
chequear('crm_por_aprobar.php existe con badge/listar/aprobar',
         is_file(__DIR__ . '/api/crm_por_aprobar.php')
         && str_contains(file_get_contents(__DIR__ . '/api/crm_por_aprobar.php'), "accion === 'aprobar'"));
chequear('exige operador (no es un endpoint abierto)',
         str_contains(file_get_contents(__DIR__ . '/api/crm_por_aprobar.php'), 'exigir_operador()'));
chequear('la migración 80 agrega el estado por_aprobar a los dos ENUM',
         is_file(__DIR__ . '/api/sql/80_recarga_por_aprobar.sql')
         && substr_count(file_get_contents(__DIR__ . '/api/sql/80_recarga_por_aprobar.sql'), 'por_aprobar') >= 4);
chequear('la migración 17 del control agrega recarga_auto',
         is_file(__DIR__ . '/panel/sql/17_recarga_asistida.sql'));

// ===========================================================================
echo "\n=== 6. El panel: selector de 3 modos ===\n";
$ph = file_get_contents(__DIR__ . '/panel/panel.html');
chequear('hay un selector de modo (completo/asistido/bandeja)',
         str_contains($ph, 'value="completo"') && str_contains($ph, 'value="asistido"')
         && str_contains($ph, 'value="bandeja"'));
chequear('asistido = crm_con_ia 1 + recarga_auto 0',
         (bool)preg_match('/modo === "asistido".*crm_con_ia:1,\s*recarga_auto:0/s', $ph));
chequear('completo = los dos en 1 (default de siempre)',
         (bool)preg_match('/return \{ crm_con_ia:1, recarga_auto:1 \}/', $ph));
$pp = file_get_contents(__DIR__ . '/panel/panel.php');
chequear('el panel guarda recarga_auto (condicional a la columna)',
         str_contains($pp, "array_key_exists('recarga_auto', \$in)")
         && str_contains($pp, "tiene_col_clientes(\$pdo, 'recarga_auto')"));

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
