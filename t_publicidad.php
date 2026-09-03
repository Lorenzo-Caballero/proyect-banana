<?php
/**
 * t_publicidad.php — El embudo cuenta las cargas de los DOS caminos.
 *
 * EL BUG QUE CUBRE, encontrado el 3/9/2026 con la pauta ya corriendo: el
 * embudo salia solo de `recargas`, y por ahi pasa un solo camino -- el que
 * arranca en el chatbot. La carga que el jugador pide con el boton
 * "Depositos" DENTRO del juego no crea ninguna fila en `recargas`, asi que
 * Publicidad mostraba 0 conversiones mientras la gente cargaba de verdad.
 *
 * No es un numero feo en una pantalla: es un CPA inflado y un ROAS en cero,
 * y con eso se apaga una campaña que estaba funcionando.
 *
 * El otro riesgo que se prueba aca es el opuesto: al unir las dos fuentes, no
 * contar dos veces al mismo jugador como "nuevo".
 *
 * Corre contra la base de prueba. Limpia lo suyo.
 *
 *     php t_publicidad.php
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
require_once __DIR__ . '/api/publicidad_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// ---------------------------------------------------------------------------
const PUB_ID = 990099;          // publicista de prueba, no choca con los reales
const DIA    = '2026-09-03';
$ini = DIA . ' 00:00:00';
$fin = DIA . ' 23:59:59';

function limpiar(PDO $pdo): void {
    foreach (['altas' => 'usuario', 'recargas' => 'usuario',
              'movimientos' => 'usuario'] as $tabla => $col) {
        $pdo->prepare("DELETE FROM $tabla WHERE $col LIKE 'tpub%'")->execute();
    }
}
function alta(PDO $pdo, string $u): void {
    $pdo->prepare(
        "INSERT INTO altas (usuario, estado, publicista_id, pedido_en)
         VALUES (?, 'ok', ?, ?)"
    )->execute([$u, PUB_ID, DIA . ' 09:00:00']);
}
/** Camino B: la recarga por transferencia que arranca en el chatbot. */
function recarga(PDO $pdo, string $u, float $monto, string $hora): void
{
    $pdo->prepare(
        "INSERT INTO recargas (usuario, referencia, monto_base, monto_pedido,
                               coins, estado, acreditada_en, creada_en, vence_en)
         VALUES (?, ?, ?, ?, 0, 'acreditada', ?, ?, ?)"
    )->execute([$u, 'tp' . random_int(100000, 999999), $monto, $monto,
                DIA . ' ' . $hora, DIA . ' ' . $hora, DIA . ' 23:59:59']);
}
/** Camino A: el boton "Depositos" de la plataforma. Solo deja movimiento. */
function peticion(PDO $pdo, string $u, int $monto, string $hora,
                  string $origen = 'peticion'): void
{
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES (?, 'saldo', ?, 'test', ?, ?)"
    )->execute([$u, $monto, $origen, DIA . ' ' . $hora]);
}

limpiar($pdo);

// ===========================================================================
echo "\n=== 1. La carga del boton 'Depositos' TIENE que contar ===\n";

/* El caso exacto de Vero: se registro desde la publicidad y cargo pidiendo la
   plata desde adentro del juego. Antes de este arreglo era invisible. */
alta($pdo, 'tpubvero');
peticion($pdo, 'tpubvero', 2000, '14:00:00');

$m = publicidad_metricas($pdo, PUB_ID, DIA, DIA);
chequear('cuenta como carga total',      (int)$m['cargas_totales'] === 1, json_encode($m));
chequear('cuenta como PRIMERA carga',    (int)$m['primeras_cargas'] === 1,     json_encode($m));
chequear('y suma al depositado',         (float)$m['depositado'] === 2000.0, json_encode($m));

// ===========================================================================
echo "\n=== 2. Los dos caminos suman, y el jugador es nuevo UNA sola vez ===\n";

/* Un jugador que carga por las dos vias sigue siendo UN jugador nuevo. Contar
   la primera de cada fuente lo duplicaria y el CPA saldria a la mitad -- que
   es el error caro en la direccion contraria. */
limpiar($pdo);
alta($pdo, 'tpubmix');
peticion($pdo, 'tpubmix', 1000, '10:00:00');   // primero por el panel
recarga($pdo, 'tpubmix', 3000, '18:00:00');    // despues por el chatbot

$m = publicidad_metricas($pdo, PUB_ID, DIA, DIA);
chequear('las dos cargas cuentan',        (int)$m['cargas_totales'] === 2, json_encode($m));
chequear('pero es UN jugador nuevo',      (int)$m['primeras_cargas'] === 1,     json_encode($m));
chequear('el depositado suma las dos',    (float)$m['depositado'] === 4000.0, json_encode($m));

/* Y "primera" es la primera DE VERDAD, mirando las dos fuentes juntas. Si se
   leyera recargas.es_primera, la del chatbot figuraria como primera aunque el
   jugador ya hubiera cargado por el panel a la mañana. */
$dias = publicidad_por_dia($pdo, PUB_ID, DIA, DIA);
$hoy  = $dias[0] ?? [];
chequear('el grafico por dia dice lo mismo que el total',
         (int)($hoy['primeras_cargas'] ?? -1) === 1
         && (float)($hoy['depositado'] ?? -1) === 4000.0, json_encode($hoy));

/* "Volvio a cargar" es la metrica que peor quedaba con una sola fuente. El
   recorrido natural es cargar la primera vez por el chatbot y las siguientes
   por el boton, que una vez adentro del juego esta mas a mano -- y ese
   jugador figuraba con una sola carga y como que nunca habia vuelto. */
chequear('cuenta como jugador que volvio a cargar',
         (int)$m['jugadores_volvieron'] === 1, json_encode($m));
chequear('y como un solo jugador con carga',
         (int)$m['jugadores_con_carga'] === 1, json_encode($m));

// ===========================================================================
echo "\n=== 3. Lo que NO es una carga no entra ===\n";

limpiar($pdo);
alta($pdo, 'tpubruido');
peticion($pdo, 'tpubruido', 500, '11:00:00', 'crm');    // carga de fichas a mano
peticion($pdo, 'tpubruido', -800, '12:00:00');          // ajuste negativo

$m = publicidad_metricas($pdo, PUB_ID, DIA, DIA);
chequear('una carga de fichas del CRM no es una conversion',
         (int)$m['cargas_totales'] === 0, json_encode($m));
chequear('y un ajuste negativo tampoco',
         (float)$m['depositado'] === 0.0, json_encode($m));

// ===========================================================================
echo "\n=== 4. Sigue siendo del publicista correcto ===\n";

/* La atribucion no se toca: solo cuentan los jugadores cuya ALTA vino de este
   publicista. Un jugador sin alta atribuida no puede aparecer en el reporte
   de nadie. */
limpiar($pdo);
alta($pdo, 'tpubmio');
peticion($pdo, 'tpubmio', 1500, '13:00:00');
peticion($pdo, 'tpubajeno', 9999, '13:30:00');   // sin alta: de nadie

$m = publicidad_metricas($pdo, PUB_ID, DIA, DIA);
chequear('solo cuenta al jugador atribuido',
         (int)$m['cargas_totales'] === 1 && (float)$m['depositado'] === 1500.0,
         json_encode($m));

// ===========================================================================
echo "\n=== 5. El rango de fechas se aplica sobre la carga, no sobre el alta ===\n";

/* Un jugador que se registro antes y carga hoy cuenta como carga de HOY. Es
   lo que ya hacia el camino B y no puede cambiar al sumar el otro. */
$m = publicidad_metricas($pdo, PUB_ID, '2026-09-01', '2026-09-02');
chequear('fuera del rango no aparece', (int)$m['cargas_totales'] === 0, json_encode($m));

limpiar($pdo);
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
