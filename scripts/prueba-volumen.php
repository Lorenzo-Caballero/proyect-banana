<?php
/**
 * prueba-volumen.php — Meterle altas de verdad al sistema, a un ritmo elegido,
 * y medir qué pasa. Contra el tenant de PRUEBA.
 *
 * PARA QUÉ (Nahuel, 16/09/2026): *"la idea es revisar que todo funcione
 * correctamente en un escenario real con volumen, o un escenario durante la
 * noche… pero no podemos gastar en publicidad con usuarios reales para
 * testearlo"*.
 *
 * Y de paso resuelve la otra pregunta: **si el cambio a `agents.ganamos7.com`
 * de verdad saca los challenges de encima.** Se corre la misma prueba antes y
 * después de mover un contenedor, y se comparan los dos informes. Sin una
 * medición así, "hoy no hubo challenges" no significa nada — puede haber sido
 * una noche tranquila.
 *
 * ============================================================================
 * ESTO CREA CUENTAS DE VERDAD EN EL PANEL. NO ES UNA SIMULACIÓN.
 * ============================================================================
 * Y tiene que ser así: el WAF reacciona al patrón de tráfico real del bot, no
 * a una simulación. Un test que no toca el panel no prueba nada sobre el WAF.
 *
 * Lo que se hace para que el costo sea aceptable:
 *
 *   - Va contra el tenant **casinotest**, que es de prueba y tiene su propia
 *     base. No ensucia la del negocio.
 *   - Los nombres llevan un prefijo reconocible (`zzp` + fecha) para poder
 *     encontrarlas y borrarlas después en el panel.
 *   - **Por defecto NO hace nada**: dice qué haría. Crear pide `--si`.
 *   - Hay un tope duro de 60 altas por corrida.
 *
 * Las cuentas quedan en el panel de agentes con saldo 0. Son basura visual,
 * no plata. Conviene borrarlas cuando termina la prueba.
 *
 *   php scripts/prueba-volumen.php                      # qué haría
 *   php scripts/prueba-volumen.php --si 20              # 20 altas, una cada 20s
 *   php scripts/prueba-volumen.php --si 40 --cada 90    # 40 en una hora (nocturno)
 *   php scripts/prueba-volumen.php --informe            # medir lo que pasó
 *   php scripts/prueba-volumen.php --listar             # qué creó, para borrarlas
 */

$args = $argv;
array_shift($args);
$hacer    = in_array('--si', $args, true);
$informe  = in_array('--informe', $args, true);
$listar   = in_array('--listar', $args, true);

$valor = function (string $flag, int $def) use ($args): int {
    $i = array_search($flag, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? max(1, (int)$args[$i + 1]) : $def;
};
$cuantas = $valor('--si', 10);
$cada    = $valor('--cada', 20);          // segundos entre altas
const TOPE = 60;

/* El tenant de prueba. En CLI no hay request, así que el slug se pone a mano
   por el mismo camino que usa nginx (ver api/db.php): se lee de
   HTTP_X_TENANT_SLUG y nunca se parsea una URL. */
$_SERVER['HTTP_HOST'] = 'ganamoscrm.online';
$_SERVER['HTTP_X_TENANT_SLUG'] = 'casinotest';

$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/altas_lib.php';

$base = $GLOBALS['TENANT_DB'] ?? '(?)';
function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 74) . "\n"; }

echo "\nPrueba de volumen — tenant \033[1mcasinotest\033[0m  (base " . $base . ")\n";

/* Un prefijo por corrida: así dos pruebas del mismo día no se mezclan en el
   informe, y en el panel se ve de un vistazo cuáles borrar. */
$PREFIJO = 'zzp' . date('md');

// ===========================================================================
if ($listar) {
    titulo('Cuentas que creó esta herramienta');
    $st = $pdo->query(
        "SELECT usuario, estado, intentos, pedido_en,
                TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) tardo
           FROM altas WHERE usuario LIKE 'holaZzp%' OR usuario LIKE 'zzp%'
          ORDER BY id DESC LIMIT 200"
    );
    $n = 0;
    foreach ($st as $r) {
        $n++;
        printf("  %-26s %-10s int:%d  %s  %s\n", $r['usuario'], $r['estado'],
               (int)$r['intentos'], substr((string)$r['pedido_en'], 5, 14),
               $r['tardo'] !== null ? $r['tardo'] . 's' : '');
    }
    if (!$n) { echo "  Ninguna.\n"; }
    else {
        echo "\n  Para borrarlas: buscá 'zzp' en el panel de agentes.\n";
        echo "  Del lado nuestro, si querés limpiar la cola de prueba:\n";
        echo "      DELETE FROM altas WHERE usuario LIKE 'holaZzp%';\n";
    }
    echo "\n"; exit(0);
}

// ===========================================================================
if ($informe) {
    titulo('Resultado de las altas de prueba');
    $st = $pdo->query(
        "SELECT COUNT(*) n,
                SUM(estado='ok') ok, SUM(estado='error') err,
                SUM(estado IN ('pendiente','procesando')) enCurso,
                AVG(CASE WHEN estado='ok' THEN TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) END) prom,
                MAX(CASE WHEN estado='ok' THEN TIMESTAMPDIFF(SECOND, pedido_en, hecho_en) END) peor,
                AVG(intentos) intentos
           FROM altas WHERE usuario LIKE 'holaZzp%' OR usuario LIKE 'zzp%'"
    );
    $r = $st->fetch();
    if (!(int)$r['n']) { echo "  No hay altas de prueba todavía.\n\n"; exit(0); }

    printf("  pedidas:        %d\n", (int)$r['n']);
    printf("  creadas:        %d  (%d%%)\n", (int)$r['ok'],
           (int)round(100 * $r['ok'] / max(1, $r['n'])));
    printf("  fallidas:       %d\n", (int)$r['err']);
    printf("  todavía en cola:%d\n", (int)$r['enCurso']);
    printf("  tiempo medio:   %ss   el peor: %ss\n",
           $r['prom'] !== null ? round((float)$r['prom']) : '—',
           $r['peor'] !== null ? (int)$r['peor'] : '—');
    printf("  intentos medios:%.2f   (1.00 = todas al primer intento)\n", (float)$r['intentos']);

    /* LO QUE SE VIENE A MEDIR. El challenge deja su firma en el mensaje: el
       HTML donde tendría que venir JSON, o la excepción del formulario que no
       carga porque el WAF lo tapó. */
    $ch = $pdo->query(
        "SELECT COUNT(*) FROM altas
          WHERE (usuario LIKE 'holaZzp%' OR usuario LIKE 'zzp%')
            AND (mensaje LIKE '%exhk%' OR mensaje LIKE '%DOCTYPE%'
              OR mensaje LIKE '%No aparecio el formulario%')"
    )->fetchColumn();
    printf("\n  \033[1mchallenges del WAF: %d de %d\033[0m\n", (int)$ch, (int)$r['n']);

    echo "\n  CÓMO SE LEE ESTO. Con todo sano un alta sale en 5-20 s y en UN\n";
    echo "  intento. Un promedio de intentos arriba de 1,2 o tiempos de minutos\n";
    echo "  significan que el bot está peleando, aunque al final salgan todas.\n";
    echo "\n  Y EL NÚMERO QUE IMPORTA para el cambio de dominio es el último:\n";
    echo "  corré la misma prueba antes y después de mover el contenedor y\n";
    echo "  compará challenges. Sin eso, \"hoy no hubo\" puede ser una noche\n";
    echo "  tranquila y no un arreglo.\n\n";
    exit(0);
}

// ===========================================================================
titulo('Lo que haría');
$cuantas = min($cuantas, TOPE);
printf("  %d altas de prueba, una cada %d segundos (%d min en total)\n",
       $cuantas, $cada, (int)ceil($cuantas * $cada / 60));
printf("  nombres: %sNNN  ->  el sistema los convierte en hola%sNNN###\n", $PREFIJO, ucfirst($PREFIJO));
echo "  tenant: casinotest (base de prueba, no la del negocio)\n";

if (!$hacer) {
    echo "\n  \033[1mNo se creó nada.\033[0m Para hacerlo de verdad:\n";
    printf("      php %s --si %d --cada %d\n", basename(__FILE__), $cuantas, $cada);
    echo "\n  OJO: crea cuentas REALES en el panel de agentes (con saldo 0).\n";
    echo "  Tiene que ser así -- el WAF reacciona al tráfico real del bot, no a\n";
    echo "  una simulación. Después se borran buscando 'zzp' en el panel.\n\n";
    exit(0);
}

titulo('Creando');
$hechas = 0; $fallos = 0;
for ($i = 1; $i <= $cuantas; $i++) {
    $u = $PREFIJO . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    /* Por el MISMO camino que la landing: alta_usuario_disponible() + encolar.
       Si se salteara eso, la prueba no estaría midiendo el sistema real. */
    $final = alta_usuario_disponible($pdo, $u);
    $r = alta_encolar($pdo, [
        'usuario'  => $final,
        'password' => alta_clave_nueva(),
        'origen'   => 'prueba',
        'ip'       => '127.0.0.1',
    ]);
    $ok = (int)($r['http'] ?? 0) === 200;
    if ($ok) { $hechas++; } else { $fallos++; }
    printf("  %2d/%d  %-26s %s\n", $i, $cuantas, $final,
           $ok ? 'encolada' : ('NO: ' . ($r['cuerpo']['error'] ?? '?')));
    if ($i < $cuantas) { sleep($cada); }
}

printf("\n  %d encoladas, %d rechazadas.\n", $hechas, $fallos);
echo "  El bot las va tomando solo. Esperá unos minutos y después:\n";
printf("      php %s --informe\n\n", basename(__FILE__));
