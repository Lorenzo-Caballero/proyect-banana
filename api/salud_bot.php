<?php
/**
 * salud_bot.php — ¿El bot de altas está VIVO? Público y de solo lectura.
 *
 * Responde la pregunta que alargó los incidentes del 7/9 y 10/9/2026 y que
 * hasta ahora solo se contestaba entrando al VPS por SSH: si las altas no
 * salen, ¿el contenedor está muerto, o vive y algo más falla?
 *
 *   - bot_visto_hace_seg  segundos desde el último sondeo del bot a su cola
 *                         (altas_cola.php anota el latido en cada
 *                         accion=pendientes; el bot sondea cada ~3s).
 *                         null = nunca se lo vio (o falta desplegar el
 *                         latido). <10 = vivo. Cientos/miles = caído o
 *                         crash-loopeando.
 *   - altas_en_cola       pendientes + procesando ahora mismo.
 *   - alta_trabada        la más vieja en cola: usuario, intentos, espera y el
 *                         último mensaje del bot. CON intentos > 0 el bot la
 *                         está trabajando y el backoff (5/20/60 min) explica la
 *                         espera; con intentos = 0 no la está tomando, que es
 *                         otro problema. Sin esto, las dos se ven igual.
 *   - altas_ultima_falla  la última que quedó en 'error', con su motivo.
 *   - cargas_demora       cuánto TARDÓ cada carga de las últimas 6 h (promedio
 *                         y peor caso, en segundos), desde que se pidió hasta
 *                         que se ejecutó. Es lo que contesta "¿tardan?": la
 *                         cola en cero no lo contesta, porque una cola vacía
 *                         convive con cargas que tardaron ocho minutos.
 *   - altas_demora        lo mismo para las altas. Una sana sale en 2-10 s por
 *                         la API; si esto da minutos, está cayendo al formulario.
 *   - mas_vieja_min       minutos de la más vieja sin resolver. Con el bot
 *                         vivo esto tiene que ser ~0; si crece con latido
 *                         fresco, el bot vive pero el PANEL le rechaza el
 *                         trabajo (credenciales, sesión, panel caído).
 *
 *   - colector            qué pudo LEER del panel el worker de la plata, y
 *                         hace cuánto. Es lo que ningún otro indicador
 *                         contestaba: con el WAF tapándonos, todo lo de arriba
 *                         da verde (el worker late, la cola está vacía) y lo
 *                         único que pasa es que los saldos envejecen en
 *                         silencio. `espejo` viejo = el bot le va a discutir
 *                         el saldo a gente que sí tiene plata; `libro` viejo =
 *                         Retiros pendientes deja de avisar que algo ya se
 *                         pagó en el panel.
 *
 * NO es secreto: dice si la automatización anda, lo mismo que cualquiera
 * deduce registrándose y mirando el reloj. No expone nombres ni claves.
 * Mismo criterio de publicación que datos_cobro.php.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

/* Cada lectura del colector con su edad, para no obligar a nadie a restar
   fechas a ojo. Nunca lanza: si falta la config, viaja en null. */
$colector = [];
/* `bancos` no lo reporta el colector: lo escribe el cron de bancos_sync.php en
   su propia clave, que existe desde la migración 47 justamente para eso. Y NO
   se mira `bancos_ganamos.visto_en`, que es ON UPDATE CURRENT_TIMESTAMP y mide
   cuándo CAMBIÓ la billetera -- no cuándo la leímos. */
$CLAVES = ['espejo' => 'colector_espejo_en', 'libro' => 'colector_libro_en',
           'stock'  => 'colector_stock_en',  'bancos' => 'bancos_sync_en',
           /* Y las tareas que corren solas. El 18/09/2026 aparecieron TRES
              apuntando a la nada y ninguna daba error visible: un proceso que
              no corre no se queja, simplemente no pasa nada -- y eso se ve
              igual que "no habia nada que hacer". Por eso lo que se informa es
              cuando fue la ultima vez que FUNCIONO, no si vive. */
           'fidelizacion' => 'fid_visto_en',
           'difusiones'   => 'difusiones_visto_en',
           'ruleta_aviso' => 'ruleta_aviso_visto_en'];
foreach ($CLAVES as $k => $clave) {
    $edad = null;
    $est  = null;
    try {
        $v = trim((string)cfg_crm($pdo, $clave));
        if ($v !== '') {
            $tt = strtotime($v);
            if ($tt !== false) { $edad = max(0, time() - $tt); }
        }
        $e = trim((string)cfg_crm($pdo, 'colector_' . $k . '_estado'));
        if ($e !== '') { $est = $e; }
    } catch (Throwable $e) { /* sin config_crm se informa null */ }
    $colector[$k] = ['hace_seg' => $edad, 'estado' => $est];
}
try {
    $ch = trim((string)cfg_crm($pdo, 'colector_challenges'));
    $colector['challenges_ultima_pasada'] = $ch === '' ? null : (int)$ch;
} catch (Throwable $e) { $colector['challenges_ultima_pasada'] = null; }

$hace = null;
try {
    $visto = trim((string)cfg_crm($pdo, 'bot_altas_visto_en'));
    if ($visto !== '') {
        $t = strtotime($visto);
        if ($t !== false) {
            $hace = max(0, time() - $t);
        }
    }
} catch (Throwable $e) {
    error_log('salud_bot: ' . $e->getMessage());
}

$enCola = null;
$viejaMin = null;
try {
    $r = $pdo->query(
        "SELECT COUNT(*) AS c,
                TIMESTAMPDIFF(MINUTE, MIN(pedido_en), NOW()) AS m
           FROM altas
          WHERE estado IN ('pendiente', 'procesando')"
    )->fetch();
    if ($r) {
        $enCola  = (int)$r['c'];
        $viejaMin = ($enCola > 0 && $r['m'] !== null) ? (int)$r['m'] : null;
    }
} catch (Throwable $e) {
    error_log('salud_bot: ' . $e->getMessage());
}

/* POR QUE NO SALE EL ALTA QUE ESTA TRABADA.
   Las cargas ya contaban su motivo (`cargas_ultima_falla`) y las altas no, y
   esa asimetria cuesta exactamente en el momento en que importa: el 01/10/2026
   habia un alta esperando hace 60 minutos con el bot latiendo cada segundo, y
   desde afuera no habia forma de saber si era el nombre tomado, la sesion, el
   WAF o el backoff -- las cuatro se ven igual: un numero que crece.

   Se devuelve el estado de LA MAS VIEJA en cola (intentos y su ultimo mensaje)
   y, aparte, la ultima que quedo en 'error'. Son dos cosas distintas: la
   primera es el problema de ahora, la segunda es historia que puede no tener
   nada que ver.

   LOS INTENTOS SON LA CLAVE PARA NO LEER MAL EL NUMERO. Con intentos > 0 el
   bot SI la esta trabajando y el backoff (5/20/60 min) explica la espera: eso
   es el sistema funcionando, no un bot colgado. Con intentos = 0 y minutos
   altos, el bot no la esta tomando, que es otro problema completamente. */
$altaDet = null; $altaFalla = null;
try {
    $a = $pdo->query(
        "SELECT usuario, estado, intentos, mensaje,
                TIMESTAMPDIFF(MINUTE, pedido_en, NOW()) AS espera_min
           FROM altas
          WHERE estado IN ('pendiente', 'procesando')
          ORDER BY pedido_en ASC LIMIT 1"
    )->fetch();
    if ($a) {
        $altaDet = [
            'usuario'    => (string)$a['usuario'],
            'estado'     => (string)$a['estado'],
            'intentos'   => (int)$a['intentos'],
            'espera_min' => (int)$a['espera_min'],
            'mensaje'    => mb_substr((string)($a['mensaje'] ?? ''), 0, 160),
        ];
    }
    $af = $pdo->query(
        "SELECT usuario, intentos, mensaje,
                TIMESTAMPDIFF(MINUTE, COALESCE(tomado_en, pedido_en), NOW()) AS hace_min
           FROM altas WHERE estado = 'error' ORDER BY id DESC LIMIT 1"
    )->fetch();
    if ($af) {
        $altaFalla = 'error (hace ' . (int)$af['hace_min'] . ' min, '
                   . (int)$af['intentos'] . ' intentos) ' . (string)$af['usuario'] . ': '
                   . mb_substr((string)($af['mensaje'] ?? ''), 0, 120);
    }
} catch (Throwable $e) {
    error_log('salud_bot altas: ' . $e->getMessage());
}

/* El loop de DEPOSITOS (acciones_saldo), aparte del de altas: un bot viejo
   crea altas pero no deposita, y esa asimetria es invisible sin esto.
   `ultima_falla` trae el mensaje del ultimo error/revisar (truncado): dice
   en una linea POR QUE el panel rechaza, sin entrar al VPS. */
$cargasHace = null;
try {
    $visto = trim((string)cfg_crm($pdo, 'bot_cargas_visto_en'));
    if ($visto !== '') {
        $t = strtotime($visto);
        if ($t !== false) { $cargasHace = max(0, time() - $t); }
    }
} catch (Throwable $e) {}

$cargas = null; $cargasVieja = null; $falla = null;
try {
    $r = $pdo->query(
        "SELECT COUNT(*) AS c,
                TIMESTAMPDIFF(MINUTE, MIN(creada_en), NOW()) AS m
           FROM acciones_saldo
          WHERE tipo = 'cargar' AND estado IN ('pendiente', 'procesando')"
    )->fetch();
    if ($r) {
        $cargas = (int)$r['c'];
        $cargasVieja = ($cargas > 0 && $r['m'] !== null) ? (int)$r['m'] : null;
    }
    $f = $pdo->query(
        "SELECT estado, mensaje,
                TIMESTAMPDIFF(MINUTE, COALESCE(tomada_en, creada_en), NOW()) AS hace_min
           FROM acciones_saldo
          WHERE tipo = 'cargar' AND estado IN ('error', 'revisar')
          ORDER BY id DESC LIMIT 1"
    )->fetch();
    if ($f) {
        // Con la antiguedad: un error de hace dias es historia (p.ej. los
        // del bot viejo por listado), no el estado de ahora. Sin esto, un
        // fallo viejisimo parecia el problema vigente.
        $falla = $f['estado'] . ' (hace ' . (int)$f['hace_min'] . ' min): '
               . mb_substr((string)$f['mensaje'], 0, 120);
    }
} catch (Throwable $e) {
    error_log('salud_bot: ' . $e->getMessage());
}

/* CUANTO TARDA UNA CARGA, DE PUNTA A PUNTA.
   `cargas_en_cola` dice cuántas esperan AHORA, y eso no contesta "¿tardan?":
   una cola vacía es perfectamente compatible con cargas que tardaron ocho
   minutos cada una -- el worker las despacha y la cola vuelve a cero, así que
   mirando el instantáneo todo se ve bien.

   Pasó el 01/10/2026: Nahuel reportó que las cargas tardaban y lo único que
   había para mirar era una cola en cero y un worker latiendo. Sin esta medida
   no se puede distinguir entre "el worker está trabado" y "el worker anda pero
   arranca tarde", que se arreglan en lugares distintos.

   Se mide desde que la carga se PIDIÓ hasta que se ejecutó, sobre las últimas
   6 horas. El promedio dice cómo viene el servicio; el peor caso dice si hay
   una cola de espera que el promedio esconde. */
$cargasLat = null;
try {
    $l = $pdo->query(
        "SELECT COUNT(*) AS n,
                ROUND(AVG(TIMESTAMPDIFF(SECOND, creada_en, ejecutada_en))) AS prom,
                MAX(TIMESTAMPDIFF(SECOND, creada_en, ejecutada_en)) AS peor
           FROM acciones_saldo
          WHERE tipo = 'cargar' AND estado = 'hecha'
            AND ejecutada_en IS NOT NULL
            AND ejecutada_en >= NOW() - INTERVAL 6 HOUR"
    )->fetch();
    if ($l && (int)$l['n'] > 0) {
        $cargasLat = [
            'hechas_6h'  => (int)$l['n'],
            'prom_seg'   => (int)$l['prom'],
            'peor_seg'   => (int)$l['peor'],
        ];
    }
} catch (Throwable $e) {
    error_log('salud_bot latencia: ' . $e->getMessage());
}

/* Lo mismo para las ALTAS: el tiempo real desde que se pidió la cuenta hasta
   que quedó creada. Una sana sale en 2-10 s por la API; si esto da minutos,
   está cayendo al formulario. */
$altasLat = null;
try {
    $l = $pdo->query(
        "SELECT COUNT(*) AS n,
                ROUND(AVG(TIMESTAMPDIFF(SECOND, pedido_en, hecho_en))) AS prom,
                MAX(TIMESTAMPDIFF(SECOND, pedido_en, hecho_en)) AS peor
           FROM altas
          WHERE estado = 'ok' AND hecho_en IS NOT NULL
            AND hecho_en >= NOW() - INTERVAL 6 HOUR"
    )->fetch();
    if ($l && (int)$l['n'] > 0) {
        $altasLat = [
            'hechas_6h' => (int)$l['n'],
            'prom_seg'  => (int)$l['prom'],
            'peor_seg'  => (int)$l['peor'],
        ];
    }
} catch (Throwable $e) {
    error_log('salud_bot latencia altas: ' . $e->getMessage());
}

// ¿Corrió la migración 56 (bono_debitado)? Sin ella el bono igual se
// deposita, pero la devolución automática y el desglose dependen del motivo.
$mig56 = null;
try {
    $mig56 = (bool)$pdo->query("SHOW COLUMNS FROM acciones_saldo LIKE 'bono_debitado'")->fetch();
} catch (Throwable $e) {}

echo json_encode([
    'ok'                        => true,
    'mig56_bono_debitado'       => $mig56,
    'bot_visto_hace_seg'        => $hace,
    'altas_en_cola'             => $enCola,
    'mas_vieja_min'             => $viejaMin,
    'alta_trabada'              => $altaDet,
    'altas_ultima_falla'        => $altaFalla,
    'bot_cargas_visto_hace_seg' => $cargasHace,
    'cargas_en_cola'            => $cargas,
    'cargas_mas_vieja_min'      => $cargasVieja,
    'cargas_demora'             => $cargasLat,
    'altas_demora'              => $altasLat,
    'cargas_ultima_falla'       => $falla,
    /* LO QUE EL COLECTOR PUDO LEER DEL PANEL. Lo escribe salud_colector.php
       con lo que le reporta el worker en cada pasada.

       Es la pregunta que ningún indicador contestaba: con el WAF tapándonos,
       TODO lo de arriba da verde --el worker late, la cola está vacía-- y lo
       único que pasa es que los saldos envejecen en silencio. Acá se ve.

       `hace_seg` null = nunca se lo vio (o falta desplegar esto). `estado` es
       cómo salió la ÚLTIMA pasada, que es distinto: 'waf' con un `hace_seg`
       chico es el caso normal (una ráfaga que se recupera sola); 'waf' con un
       `hace_seg` grande es el problema. */
    'colector'                  => $colector,
], JSON_UNESCAPED_UNICODE);
