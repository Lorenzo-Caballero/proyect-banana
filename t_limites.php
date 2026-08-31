<?php
/**
 * t_limites.php — Los limites de carga y retiro, por cliente.
 *
 * Dos cosas que importan y por eso se prueban las dos:
 *
 *  1. Que los limites configurados se APLIQUEN. Un limite que solo esta
 *     escrito en el prompt es una sugerencia: el modelo lo puede ignorar.
 *  2. Que sin configurar nada, el comportamiento sea EL DE ANTES. Estos
 *     limites eran constantes en el codigo; si el default cambiara la
 *     conducta, cada cliente que ya esta andando se veria afectado por un
 *     despliegue que no pidio.
 *
 *     php t_limites.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/fichas_lib.php';
require_once __DIR__ . '/api/chatbot_contexto.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
function ponerLimite(PDO $pdo, string $clave, string $valor): void {
    cfg_crm_guardar($pdo, [$clave => $valor], 'test');
}
function limpiarLimites(PDO $pdo): void {
    cfg_crm_guardar($pdo, [
        'lim_carga_min' => '100', 'lim_carga_max' => '500000',
        'lim_retiro_min' => '100', 'lim_retiro_max_dia' => '0',
    ], 'test');
}

$ID = 987654323;
function prepararJugador(PDO $pdo, int $id, float $balance): void {
    $pdo->exec("DELETE FROM usuarios WHERE id=$id");
    $pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
    $pdo->prepare("INSERT INTO usuarios (id,username,coins,balance) VALUES (?,?,?,?)")
        ->execute([$id, 'test_lim', 1000000, $balance]);
}

// ===========================================================================
echo "\n=== 1. Sin configurar: los valores de siempre (no cambia nadie) ===\n";
limpiarLimites($pdo);
chequear('carga minima 100',    fichas_limite($pdo, 'lim_carga_min', FICHAS_MIN_CARGA) === 100);
chequear('carga maxima 500000', fichas_limite($pdo, 'lim_carga_max', FICHAS_MAX_CARGA) === 500000);
chequear('sin tope diario',     fichas_limite($pdo, 'lim_retiro_max_dia', 0) === 0);

echo "\n=== 2. Un valor invalido NO se convierte en 'sin limite' ===\n";
// El lado seguro: si alguien deja basura, se usa el default, no se abre todo.
ponerLimite($pdo, 'lim_carga_min', 'muchas');
chequear('texto -> cae al default', fichas_limite($pdo, 'lim_carga_min', 100) === 100);
ponerLimite($pdo, 'lim_carga_min', '');
chequear('vacio -> cae al default', fichas_limite($pdo, 'lim_carga_min', 100) === 100);

// ===========================================================================
echo "\n=== 3. Carga minima de 500: 400 se rechaza, 500 pasa ===\n";
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_carga_min', '500');
prepararJugador($pdo, $ID, 0);
$r = fichas_pedir_carga($pdo, 'test_lim', 400, 'test');
chequear('400 se rechaza',       empty($r['ok']) && ($r['codigo'] ?? '') === 'monto_bajo', json_encode($r));
chequear('avisa el minimo real', strpos((string)($r['error'] ?? ''), '500') !== false, (string)($r['error'] ?? ''));
$r = fichas_pedir_carga($pdo, 'test_lim', 500, 'test');
chequear('500 pasa',             !empty($r['ok']), json_encode($r));

// ===========================================================================
echo "\n=== 4. Retiro minimo propio, distinto del de carga ===\n";
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_carga_min',  '100');
ponerLimite($pdo, 'lim_retiro_min', '2000');
prepararJugador($pdo, $ID, 50000);
$r = fichas_pedir_retiro($pdo, 'test_lim', 1000, 'test');
chequear('retiro de 1000 se rechaza', empty($r['ok']) && ($r['codigo'] ?? '') === 'monto_bajo', json_encode($r));
chequear('avisa 2.000, no 100',       strpos((string)($r['error'] ?? ''), '2.000') !== false, (string)($r['error'] ?? ''));

// ===========================================================================
echo "\n=== 5. Tope diario: cuenta lo que ya cobro hoy ===\n";
// Escenario real: retiro 8.000 mas temprano (ya pagado) y ahora pide 5.000.
// Se usa 'hecha' y no 'pendiente' porque hay una guarda anterior que impide
// tener dos retiros pendientes a la vez -- el tope se suma igual sobre los
// pendientes, pero por ese camino no se llega.
limpiarLimites($pdo);
ponerLimite($pdo, 'lim_retiro_min', '100');
ponerLimite($pdo, 'lim_retiro_max_dia', '10000');
prepararJugador($pdo, $ID, 500000);
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',8000,'hecha',NOW())")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('8000 cobrados + 5000 supera el tope',
         empty($r['ok']) && ($r['codigo'] ?? '') === 'tope_diario', json_encode($r));
chequear('dice cuanto le queda hoy (2.000)',
         strpos((string)($r['error'] ?? ''), '2.000') !== false, (string)($r['error'] ?? ''));
$r = fichas_pedir_retiro($pdo, 'test_lim', 2000, 'test');
chequear('justo el remanente (2000) SI pasa', !empty($r['ok']), json_encode($r));

echo "\n=== 5b. Lo de AYER no cuenta para el tope de hoy ===\n";
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',9000,'hecha',DATE_SUB(NOW(), INTERVAL 1 DAY))")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('el tope arranca de cero cada dia', !empty($r['ok']), json_encode($r));

echo "\n=== 6. Un retiro rechazado NO consume el tope del dia ===\n";
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->prepare("INSERT INTO acciones_saldo (usuario,tipo,monto,estado,creada_en)
               VALUES ('test_lim','retirar',9000,'error',NOW())")->execute();
$r = fichas_pedir_retiro($pdo, 'test_lim', 5000, 'test');
chequear('el rechazado no cuenta', !empty($r['ok']), json_encode($r));

// ===========================================================================
echo "\n=== 7. El prompt: las REGLAS FIJAS van al final ===\n";
// Si las indicaciones del operador quedaran despues, le ganarian al
// procedimiento -- que es exactamente como se rompio el cobro en produccion.
$p = chatbot_armar_prompt(
    ['reglas_extra' => 'MARCA_DEL_OPERADOR'],
    ['carga_min' => 500, 'carga_max' => 0, 'retiro_min' => 2000, 'retiro_max_dia' => 100000]
);
$posOperador = strpos($p, 'MARCA_DEL_OPERADOR');
$posFijas    = strpos($p, 'ESTO MANDA SOBRE TODO LO ANTERIOR');
chequear('las reglas fijas van DESPUES de lo del operador',
         $posOperador !== false && $posFijas !== false && $posFijas > $posOperador,
         "operador=$posOperador fijas=$posFijas");
chequear('el prompt dice la carga minima',   strpos($p, '500') !== false);
chequear('el prompt dice el tope diario',    strpos($p, '100.000') !== false);
chequear('no inventa un maximo si es 0',     strpos($p, 'Carga MAXIMA') === false);

// ===========================================================================
limpiarLimites($pdo);
$pdo->exec("DELETE FROM usuarios WHERE id=$ID");
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario='test_lim'");
$pdo->exec("DELETE FROM movimientos WHERE usuario='test_lim'");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
