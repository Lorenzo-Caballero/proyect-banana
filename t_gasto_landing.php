<?php
/**
 * t_gasto_landing.php — el gasto de pauta, por LANDING además de por publicista.
 *
 * POR QUÉ EXISTE (Nahuel, 13/09/2026): "no tengo ningún publicista creado... la
 * función de publicista no sirve demasiado para este rubro. Sí es crucial poner
 * las métricas y medir qué tan buena es esa landing, para ver si no tiene tanta
 * conversión y probar con otra."
 *
 * El gasto estaba atado a `publicista`, un concepto pensado para medir la
 * campaña de OTRA persona con su propio pixel. Sin gasto no hay CPA ni ROAS —
 * las dos métricas que deciden si escalar o cortar una landing — así que quien
 * mide por landing las veía siempre en "—".
 *
 *     php t_gasto_landing.php
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
require __DIR__ . '/api/publicidad_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
$limpiar = fn() => $pdo->exec(
    "DELETE FROM gasto_diario WHERE landing_slug LIKE 't_lp%' OR publicista_id = 99002");
$limpiar();

echo "\n=== 1. Se puede cargar gasto de una LANDING ===\n";
chequear('guarda', publicidad_gasto_guardar($pdo, 0, '2026-09-13', 5000.0, 'test', 't_lp_bono50'));
chequear('y se lee', publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50') === 5000.0);

echo "\n=== 2. Cargar de nuevo el mismo dia CORRIGE, no suma ===\n";
/* El operador se equivoca y vuelve a cargar. Si sumara, el ROAS quedaria
   arruinado y no habria forma de arreglarlo desde la pantalla. */
publicidad_gasto_guardar($pdo, 0, '2026-09-13', 7000.0, 'test', 't_lp_bono50');
chequear('queda el ultimo valor, no la suma',
         publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50') === 7000.0,
         (string)publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50'));
$n = (int)$pdo->query("SELECT COUNT(*) FROM gasto_diario WHERE landing_slug='t_lp_bono50'")->fetchColumn();
chequear('y hay UNA sola fila', $n === 1, "filas=$n");

echo "\n=== 3. Cada campaña ve SOLO lo suyo ===\n";
/* Lo que hace util la comparacion: si el gasto se mezclara entre landings, el
   CPA de las dos seria el mismo y no habria nada que comparar. */
publicidad_gasto_guardar($pdo, 0,     '2026-09-13', 1000.0, 'test', 't_lp_otra');
publicidad_gasto_guardar($pdo, 99002, '2026-09-13', 3000.0, 'test');
chequear('la landing A no ve a la B',
         publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50') === 7000.0);
chequear('la landing B tiene lo suyo',
         publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_otra') === 1000.0);
chequear('el publicista sigue funcionando igual que antes',
         publicidad_gasto_periodo($pdo, 99002, '2026-09-01', '2026-09-30') === 3000.0);
chequear('y no se mezcla con las landings',
         publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50') === 7000.0);

echo "\n=== 4. Sin destino no se guarda nada ===\n";
/* Sin esto, un gasto sin dueño quedaria en la tabla sin aparecer en ninguna
   pantalla: plata gastada que no figura en ningun ROAS. */
chequear('ni publicista ni landing -> false',
         publicidad_gasto_guardar($pdo, 0, '2026-09-13', 100.0, 'test', '') === false);
chequear('sin fecha tampoco',
         publicidad_gasto_guardar($pdo, 0, '', 100.0, 'test', 't_lp_bono50') === false);

echo "\n=== 5. El dia por dia de una landing ===\n";
publicidad_gasto_guardar($pdo, 0, '2026-09-12', 2500.0, 'test', 't_lp_bono50');
$dias = publicidad_gasto_dias($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50');
chequear('trae los dos dias', count($dias) === 2, json_encode($dias));
chequear('en orden ascendente', ($dias[0]['fecha'] ?? '') === '2026-09-12');

echo "\n=== 6. Borrar el gasto de un dia ===\n";
/* POR QUE NO ALCANZA CON GUARDAR 0: la fila en cero sigue existiendo, y una
   fila existente cuenta como "dia con pauta" en publicidad_gasto_total(), que
   es el divisor del promedio diario del indicador de salud de Finanzas. Un dia
   sin pauta metido en ese divisor baja el promedio y hace parecer que la
   publicidad se paga sola antes de tiempo. */
publicidad_gasto_guardar($pdo, 0, '2026-09-14', 1234.0, 'test', 't_lp_bono50');
$n = (int)$pdo->query("SELECT COUNT(*) FROM gasto_diario WHERE landing_slug='t_lp_bono50'")->fetchColumn();
chequear('estaba cargado', $n === 3, "filas=$n");
chequear('borra', publicidad_gasto_borrar($pdo, 0, '2026-09-14', 't_lp_bono50'));
$n = (int)$pdo->query("SELECT COUNT(*) FROM gasto_diario WHERE landing_slug='t_lp_bono50'")->fetchColumn();
chequear('la fila desaparece, no queda en cero', $n === 2, "filas=$n");
chequear('y el total del periodo lo refleja',
         publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50') === 9500.0,
         (string)publicidad_gasto_periodo($pdo, 0, '2026-09-01', '2026-09-30', 't_lp_bono50'));

/* EL TEST QUE JUSTIFICA EL `IS NULL` DE LA CONSULTA: una landing y un
   publicista pueden tener gasto el MISMO dia. Sin ese filtro, borrar el de uno
   se llevaria puesto el del otro -- y nadie lo notaria hasta que el ROAS de la
   otra campaña no cerrara. */
publicidad_gasto_guardar($pdo, 0,     '2026-09-15', 800.0, 'test', 't_lp_bono50');
publicidad_gasto_guardar($pdo, 99002, '2026-09-15', 900.0, 'test');
publicidad_gasto_borrar($pdo, 0, '2026-09-15', 't_lp_bono50');
chequear('borrar el de la landing no toca el del publicista',
         publicidad_gasto_periodo($pdo, 99002, '2026-09-15', '2026-09-15') === 900.0);
chequear('y el de la landing si se fue',
         publicidad_gasto_periodo($pdo, 0, '2026-09-15', '2026-09-15', 't_lp_bono50') === 0.0);

echo "\n=== 7. Borrar sin destino o sin fecha no hace nada ===\n";
/* Mismo guard que al guardar. Un DELETE sin destino borraria por fecha sola:
   el gasto de TODAS las campañas de ese dia. */
chequear('sin destino', publicidad_gasto_borrar($pdo, 0, '2026-09-13', '') === false);
chequear('sin fecha',   publicidad_gasto_borrar($pdo, 0, '', 't_lp_bono50') === false);
$n = (int)$pdo->query("SELECT COUNT(*) FROM gasto_diario WHERE landing_slug='t_lp_bono50'")->fetchColumn();
chequear('no se borro nada de mas', $n === 2, "filas=$n");

chequear('borrar un dia que no existe no revienta',
         publicidad_gasto_borrar($pdo, 0, '2026-01-01', 't_lp_bono50') === true);

$limpiar();
echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
