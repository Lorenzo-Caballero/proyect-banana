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

echo "\n=== 8. Todo el gasto junto, de TODAS las campañas ===\n";
/* POR QUE EXISTE (Nahuel, 14/09/2026): "desde el CRM publicista dia uno hay un
   gasto de sesenta y seis mil pesos, eso fue una campaña que hicimos mal... los
   datos estan un poco sucios, me gustaria ver si hay alguna forma de editarlo".

   El dia por dia muestra SOLO la campaña seleccionada, asi que para corregir el
   gasto de una vieja habia que acordarse de que existia y encontrar su solapa.
   Una campaña que ya no se usa es justamente la que nadie va a ir a buscar -- y
   la que ensucia el total sin que se note. */
$limpiar();
publicidad_gasto_guardar($pdo, 0,     '2026-09-10', 4000.0, 'nahuel', 't_lp_bono50');
publicidad_gasto_guardar($pdo, 0,     '2026-09-11', 6000.0, 'nahuel', 't_lp_otra');
publicidad_gasto_guardar($pdo, 99002, '2026-09-11', 66000.0, 'nahuel');

$todo = publicidad_gasto_todo($pdo, '2026-09-01', '2026-09-30');
$mios = array_values(array_filter($todo, fn($g) =>
    ($g['landing'] ?? '') === 't_lp_bono50' || ($g['landing'] ?? '') === 't_lp_otra'
    || ($g['publicista_id'] ?? 0) === 99002));
chequear('trae las tres filas, sin importar la campaña', count($mios) === 3,
         (string)count($mios));

/* LO QUE HACE POSIBLE EDITARLAS: cada fila tiene que decir a que campaña
   pertenece. Sin eso, corregir el gasto de una vieja se lo cargaria a la
   campaña que este abierta -- creando plata donde no la hay y borrandola donde
   si. Es el bug mas caro que podria tener esta pantalla. */
$conDestino = array_filter($mios, fn($g) => $g['landing'] !== null || $g['publicista_id'] !== null);
chequear('cada fila sabe a que campaña pertenece', count($conDestino) === 3);

$delPub = array_values(array_filter($mios, fn($g) => $g['clase'] === 'publicista'));
chequear('la del publicista se distingue', count($delPub) === 1 && $delPub[0]['landing'] === null,
         json_encode($delPub));
chequear('y trae su id para poder borrarla',
         ($delPub[0]['publicista_id'] ?? 0) === 99002);

/* Ordenado por fecha descendente: lo mas reciente arriba, que es lo que uno
   viene a corregir. */
chequear('viene ordenado por fecha, lo nuevo primero',
         $mios[0]['fecha'] >= $mios[count($mios) - 1]['fecha'],
         $mios[0]['fecha'] . ' -> ' . $mios[count($mios) - 1]['fecha']);

chequear('guarda quien lo cargo', ($mios[0]['operador'] ?? '') === 'nahuel',
         (string)($mios[0]['operador'] ?? 'null'));

/* LA INVARIANTE: esta lista tiene que sumar lo mismo que la Pauta del periodo
   de Finanzas. Si no coincidieran, el operador borraria filas persiguiendo un
   numero que nunca va a cerrar. */
$sumaTodo  = array_sum(array_column($mios, 'monto'));
$sumaTotal = publicidad_gasto_total($pdo, '2026-09-01', '2026-09-30')['total'];
chequear('suma lo mismo que el total de la pauta',
         abs($sumaTodo - $sumaTotal) < 0.01, "lista=$sumaTodo total=$sumaTotal");

/* Y que se pueda borrar la fila del publicista con lo que la lista devuelve,
   que es el caso concreto de Nahuel: la campaña que salio mal. */
chequear('se puede borrar con lo que devuelve la lista',
         publicidad_gasto_borrar($pdo, (int)$delPub[0]['publicista_id'], $delPub[0]['fecha'], ''));
$despues = publicidad_gasto_total($pdo, '2026-09-01', '2026-09-30')['total'];
chequear('y el total baja exactamente lo borrado',
         abs($despues - ($sumaTotal - 66000.0)) < 0.01, "ahora=$despues");

chequear('un periodo sin gasto da lista vacia',
         publicidad_gasto_todo($pdo, '2026-01-01', '2026-01-31') === []);

echo "\n=== Borrar una landing: solo la que no trajo a nadie ===\n";

/* Nahuel (18/09/2026): *"si tenemos muchisimas landings es medio molesto
   cuando entramos a ese apartado"*. Al mirar habia seis: dos con historia
   real y CUATRO vacias creadas probando, tres llamadas «50».

   PERO BORRAR UNA CON HISTORIA ROMPE LOS REPORTES, y en silencio. El vinculo
   con los datos es el slug: `altas.origen` guarda lp:<slug> y
   `gasto_diario.landing_slug` el mismo texto, sin clave foranea -- a
   proposito, para que el historial sobreviva a editar o pausar la landing. Al
   borrar la fila, esos registros quedan apuntando a un slug que ya no existe
   y Publicidad muestra altas y gasto que no se pueden atribuir a nada. */
require_once __DIR__ . '/api/landings_lib.php';
$pdo->exec("DELETE FROM landings WHERE slug LIKE 'tlp-b%'");
$pdo->exec("DELETE FROM altas WHERE origen = 'lp:tlp-b-con'");
$pdo->exec("DELETE FROM gasto_diario WHERE landing_slug LIKE 'tlp-b%'");

$pdo->prepare("INSERT INTO landings (slug, nombre, plantilla, bono_pct, activa)
               VALUES ('tlp-b-sin', 'sin historia', 'bono', 50, 0)")->execute();
$id1 = (int)$pdo->lastInsertId();
$r = landings_borrar($pdo, $id1);
chequear('una landing sin historia se borra', !empty($r['ok']), json_encode($r));
$q = $pdo->prepare('SELECT COUNT(*) FROM landings WHERE id = ?'); $q->execute([$id1]);
chequear('y desaparece de verdad', (int)$q->fetchColumn() === 0);

$pdo->prepare("INSERT INTO landings (slug, nombre, plantilla, bono_pct, activa)
               VALUES ('tlp-b-con', 'con historia', 'bono', 50, 0)")->execute();
$id2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO altas (usuario, password, estado, origen, pedido_en)
               VALUES ('tlp_bu1', 'clave123456', 'ok', 'lp:tlp-b-con', NOW())")->execute();
$r = landings_borrar($pdo, $id2);
chequear('una landing con registros NO se borra', empty($r['ok']), json_encode($r));
chequear('dice cuantos registros trajo', (int)($r['altas'] ?? 0) === 1, json_encode($r));
chequear('y ofrece pausarla en vez de borrarla',
         str_contains((string)($r['error'] ?? ''), 'Pausala'),
         'sin una salida, el operador queda trabado con la landing ahi');
$q->execute([$id2]);
chequear('sigue existiendo', (int)$q->fetchColumn() === 1);

/* Y la que no trajo gente pero SI tiene plata de pauta cargada tampoco: ese
   gasto es parte del calculo de CPA y ROAS. */
$pdo->exec("DELETE FROM altas WHERE origen = 'lp:tlp-b-con'");
$pdo->prepare("INSERT INTO gasto_diario (fecha, landing_slug, monto)
               VALUES (CURDATE(), 'tlp-b-con', 5000)")->execute();
$r = landings_borrar($pdo, $id2);
chequear('con pauta cargada tampoco se borra', empty($r['ok']), json_encode($r));

/* El CRM no ofrece el boton donde va a rebotar: ofrecerlo y despues negarlo
   es peor que no ofrecerlo. */
$crmSrc = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('el CRM solo muestra Borrar si no trajo nada',
         str_contains($crmSrc, '(l.altas > 0 || l.gasto > 0) ? "" :'));
chequear('y muestra el slug, porque los nombres se repiten',
         str_contains($crmSrc, 'Es lo que va en el link'));

$pdo->exec("DELETE FROM gasto_diario WHERE landing_slug LIKE 'tlp-b%'");
$pdo->exec("DELETE FROM landings WHERE slug LIKE 'tlp-b%'");
$limpiar();
echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
