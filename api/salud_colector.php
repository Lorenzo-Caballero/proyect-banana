<?php
/**
 * salud_colector.php — ¿el colector está pudiendo LEER el panel?
 *
 * EL HUECO QUE TAPA (Nahuel, 18/09/2026): *"quiero estar seguro de que si eso
 * da algún problema, haya algún método extra o alguna solución para cada uno
 * de los casos. No quiero que nada se rompa por culpa del WAF."*
 *
 * Las ESCRITURAS contra el panel ya estaban cubiertas desde antes: un
 * challenge en aprobar, rechazar o retirar nunca marca "hecha", cae en
 * 'revisar' y lo mira una persona (`t_deposito.py`, `t_retiro_api.py`). Lo que
 * no tenía red eran las LECTURAS, y ahí el problema no es que se rompa algo:
 * es que NO SE ROMPE NADA VISIBLE.
 *
 * Si el WAF nos tapa el espejo de saldos media hora, el sistema sigue
 * "andando": el CRM abre, el chat contesta, las cargas se aprueban. Lo único
 * que pasa es que los saldos envejecen en silencio, el bot le discute el saldo
 * a gente que sí tiene plata, y un jugador recién creado no aparece. Eso ya
 * pasó y nadie se enteró hasta que un operador lo reportó.
 *
 * Los watchdogs que había miran otra cosa: `monitor-altas.sh` mira la cola de
 * altas y `monitor-cargas.sh` el latido del worker de cargas. Los dos dan
 * VERDE mientras el WAF nos tiene ciegos, porque el worker está vivo y la cola
 * está vacía.
 *
 * Así que el colector avisa acá qué pudo hacer en cada pasada, y este archivo
 * decide si eso amerita un Telegram.
 *
 *   POST (X-API-Key)   { "espejo": "ok"|"parcial"|"waf", "challenges": N,
 *                        "libro": "ok"|"waf", "stock": "ok"|"waf" }
 *
 * Cada clave que llega se guarda con su fecha en `config_crm`, y las que están
 * atrasadas disparan un aviso. Todos los campos son opcionales: una pasada que
 * no tocó el libro (corre cada 5 minutos) simplemente no lo manda, y su fecha
 * queda como estaba.
 *
 * **El aviso NO se dispara con una pasada fallada.** El WAF desafía de a
 * ráfagas y una pasada perdida se recupera sola en la siguiente: avisar de eso
 * sería exactamente el Telegram repetido que ya molestó una vez. Se avisa
 * cuando pasó tanto tiempo sin UNA lectura buena que la consecuencia ya es
 * visible para un jugador. El dedupe de `tg_avisar_una_vez` hace el resto.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';
require_once __DIR__ . '/telegram_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (function_exists('exigir_api_key')) { exigir_api_key(); }

/* CUANTO PUEDE ENVEJECER CADA LECTURA ANTES DE QUE IMPORTE.
   No son múltiplos de la cadencia: son el momento en que la consecuencia se
   vuelve visible del lado del jugador.

   - El espejo corre cada 5 minutos. A los 20 ya hay saldos de casi media hora
     y el bot empieza a contestar cualquier cosa.
   - El libro cada 5. A los 30 el aviso de «esto ya figura hecho en el panel»
     deja de proteger contra pagar un retiro dos veces, que es para lo único
     que existe.
   - El stock cada 5. A los 45 importa poco: solo alimenta un aviso de stock
     bajo, que es informativo. */
const SC_LIMITES = [
    'espejo' => ['min' => 20, 'que' => 'el saldo de los jugadores',
                 'duele' => 'El bot les contesta con saldos viejos y un jugador recién creado no aparece en el CRM.'],
    'libro'  => ['min' => 30, 'que' => 'el libro de operaciones del panel',
                 'duele' => 'Retiros pendientes deja de avisar «esto ya figura hecho en el panel»: se puede pagar dos veces.'],
    'stock'  => ['min' => 45, 'que' => 'nuestro stock de fichas',
                 'duele' => 'No vamos a enterarnos si nos estamos quedando sin fichas para pagar.'],
    /* Este espeja cada hora, así que el umbral es otro. Y es el más caro de
       los cuatro: si la billetera del panel cambió y el espejo no corrió, el
       chat le sigue dictando la anterior a cada jugador que quiere cargar.

       `clave` propia porque este no lo reporta el colector: lo escribe
       `bancos_sync.php` cuando corre su cron, y esa clave ya existía desde la
       migración 47 exactamente para esto.

       NO MIRAR `bancos_ganamos.visto_en`: es `ON UPDATE CURRENT_TIMESTAMP`, o
       sea que mide cuándo CAMBIÓ la billetera, no cuándo la leímos -- MySQL no
       lo dispara si la fila queda igual. Con una billetera estable esa columna
       se queda en la fecha del día que se cargó (medido: 31/08, con el sync
       corriendo), y un chequeo apoyado ahí avisaría para siempre. Es el mismo
       error que ya costó `usuarios.actualizado_en`, y la migración 47 lo deja
       escrito: por eso existe `bancos_sync_en` aparte. */
    'bancos' => ['min' => 240, 'clave' => 'bancos_sync_en',
                 'que' => 'la billetera de cobro del panel',
                 'duele' => 'Si cambió el alias, el chat le está dictando el viejo y esa plata no se acredita.'],
];

/* ---- LAS TAREAS QUE CORREN SOLAS ----------------------------------------
   Lo mismo que arriba pero para los crons del server, y por el mismo motivo.

   EL 18/09/2026 APARECIERON TRES TAREAS APUNTANDO A LA NADA, y las tres se
   encontraron mirando a mano, no porque algo avisara:

     · el cron de `sync_bancos.py` iba a un contenedor apagado hace dos dias y
       fallaba una vez por hora en un log que nadie lee;
     · el de `fidelizacion.php` NUNCA se instalo: la promo figura prendida en
       el CRM, corrio una sola vez a mano, y desde entonces nada;
     · el espejo de saldos moria en el primer challenge del WAF.

   Un proceso que no corre no se queja. Simplemente no pasa nada -- y eso se ve
   exactamente igual que "no habia nada que hacer". Por eso lo que se mira acá
   NO es si el proceso vive, sino CUANDO FUE LA ULTIMA VEZ QUE FUNCIONO. Es el
   unico criterio que hubiera atrapado las tres.

   `activa_si` es lo que hace que esto no moleste: una promo apagada no tiene
   por que correr, asi que no se la vigila. Si el dueño la prende desde el CRM,
   empieza a vigilarse sola.

   `arreglo` va en el mensaje. Un aviso que dice "algo no corre" y no dice que
   hacer se aprende a ignorar en dos dias. */
const SC_TAREAS = [
    'fidelizacion' => [
        'clave'     => 'fid_visto_en',
        'min'       => 180,               // corre cada hora; a las 3 ya no es un tropiezo
        'activa_si' => 'fid_activa',
        'que'       => 'la promo de fidelización',
        'duele'     => 'Los jugadores que cumplen la racha no reciben el bono que la promo les promete.',
        'arreglo'   => 'bash /opt/goldpaw/scripts/instalar-cron-fidelizacion.sh',
    ],
    'difusiones' => [
        'clave'     => 'difusiones_visto_en',
        'min'       => 60,                // corre cada 10 min
        'que'       => 'las difusiones programadas del chat',
        'duele'     => 'Una difusión con hora puesta no le llega a nadie, y no queda ningún error.',
        'arreglo'   => 'revisar el cron de difusiones_chat_procesar.php',
    ],
    'ruleta_aviso' => [
        'clave'     => 'ruleta_aviso_visto_en',
        'min'       => 1560,              // una vez por dia: 26 h de margen
        'que'       => 'el aviso diario de la ruleta',
        'duele'     => 'Los jugadores dejan de recibir el recordatorio del giro gratis.',
        'arreglo'   => 'revisar el cron de ruleta_recordatorio.php',
    ],
];

function sc_salir(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sc_salir(['ok' => false, 'error' => 'Usa POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

/* ---- 1. Guardar lo que el colector pudo hacer ---------------------------- */
$guardar = [];
foreach (array_keys(SC_LIMITES) as $k) {
    // 'bancos' no lo reporta el colector (lo escribe su propio cron): si
    // llegara en el body seria un dato inventado.
    if (isset(SC_LIMITES[$k]['clave'])) { continue; }
    $estado = trim((string)($body[$k] ?? ''));
    if ($estado === '') { continue; }
    /* SOLO UNA LECTURA BUENA MUEVE LA FECHA. Un 'waf' o un 'parcial' no es un
       latido: es justamente lo que estamos tratando de detectar. Se guarda
       igual, pero aparte -- sirve para ver en salud_bot.php que el colector
       está vivo y peleando, que es distinto de que esté muerto. */
    if ($estado === 'ok') {
        $guardar['colector_' . $k . '_en'] = date('Y-m-d H:i:s');
    }
    $guardar['colector_' . $k . '_estado'] = $estado;
}
if (isset($body['challenges'])) {
    $guardar['colector_challenges'] = (string)max(0, (int)$body['challenges']);
}
$guardar['colector_visto_en'] = date('Y-m-d H:i:s');

try {
    if (function_exists('cfg_crm_guardar')) {
        cfg_crm_guardar($pdo, $guardar, 'colector');
    }
} catch (Throwable $e) {
    error_log('salud_colector/guardar: ' . $e->getMessage());
}

/* ---- 2. ¿Alguna lectura o tarea quedó tan vieja que ya se nota? ---------- */
$avisados = [];
$estado   = [];

/* DESDE CUANDO ESTAMOS MIRANDO, y por qué hace falta.
   «Nunca corrió» no dispara aviso, a propósito: el día del deploy todas las
   fechas están vacías y avisar ahí sería ruido garantizado. Pero eso deja un
   agujero que NO es teórico: una tarea cuyo cron nunca se instaló se queda en
   «nunca» para siempre y no avisa jamás.

   Es literalmente lo que pasó con la fidelización. Su cron nunca se puso, y
   solo se descubrió porque alguien la había corrido UNA vez a mano el 16/09 y
   esa fecha envejeció hasta que la miramos. Sin esa casualidad, seguiría
   invisible.

   Con este ancla, «nunca corrió» también envejece: pasada su ventana desde que
   la empezamos a mirar, se avisa igual -- y el mensaje dice otra cosa, porque
   el problema es otro (no es que se paró: es que nunca arrancó). */
$vigDesde = null;
try {
    $vd = trim((string)cfg_crm($pdo, 'tareas_vigilando_desde'));
    if ($vd === '') {
        cfg_crm_guardar($pdo, ['tareas_vigilando_desde' => date('Y-m-d H:i:s')], 'colector');
        $vigDesde = 0;
    } else {
        $tvd = strtotime($vd);
        if ($tvd !== false) { $vigDesde = max(0, (int)floor((time() - $tvd) / 60)); }
    }
} catch (Throwable $e) { /* sin config_crm no se avisa, no se rompe */ }

/* Las dos tablas se recorren con el MISMO bucle a proposito: la pregunta es la
   misma --"¿hace cuanto que esto no funciona?"-- y tener dos copias del
   chequeo es como se pierde una. Lo unico que cambia es el titulo del aviso. */
$aRevisar = [];
foreach (SC_LIMITES as $k => $lim) { $aRevisar[$k] = $lim + ['lectura' => true]; }
foreach (SC_TAREAS  as $k => $lim) { $aRevisar[$k] = $lim + ['lectura' => false]; }

foreach ($aRevisar as $k => $lim) {
    /* UNA TAREA APAGADA NO TIENE POR QUE CORRER. Sin esto, el aviso saltaria
       por cada promo que el dueño decidio no usar -- y un aviso que molesta
       por algo que esta bien es como se deja de mirar el canal. */
    if (isset($lim['activa_si'])) {
        try {
            if (!function_exists('cfg_crm_activo') || !cfg_crm_activo($pdo, $lim['activa_si'])) {
                $estado[$k] = ['hace_min' => null, 'limite_min' => $lim['min'], 'apagada' => true];
                continue;
            }
        } catch (Throwable $e) { continue; }
    }
    $edad = null;
    try {
        $v = trim((string)cfg_crm($pdo, $lim['clave'] ?? ('colector_' . $k . '_en')));
        if ($v !== '') {
            $t = strtotime($v);
            if ($t !== false) { $edad = max(0, (int)floor((time() - $t) / 60)); }
        }
    } catch (Throwable $e) { /* sin config_crm no se avisa, no se rompe */ }

    $estado[$k] = ['hace_min' => $edad, 'limite_min' => $lim['min']];

    /* NUNCA CORRIO. No se avisa el día del deploy --todas las fechas están
       vacías y eso sería ruido-- pero sí una vez que llevamos mirando más de
       lo que esa tarea tarda en correr. Ahí «nunca» deja de ser «todavía no» y
       pasa a ser «su cron no existe».
       Solo para tareas: una LECTURA sin fecha es el colector que todavía no
       reportó, y de eso ya avisa su propio indicador. */
    if ($edad === null) {
        if ($lim['lectura'] || $vigDesde === null || $vigDesde < $lim['min']) { continue; }
        $ok = tg_evento($pdo, 'salud', '⏰ Algo que tenía que correr solo NUNCA corrió', [
            'Qué nunca corrió' => $lim['que'],
            'Lo miramos desde' => 'hace ' . ($vigDesde >= 120 ? round($vigDesde / 60) . ' horas'
                                                              : $vigDesde . ' minutos'),
            'Qué significa'    => $lim['duele'],
            'Qué hacer'        => $lim['arreglo'] . ' (lo más probable: su cron no está puesto)',
        ], 'tarea_' . $k . '_nunca');
        if ($ok) { $avisados[] = $k; }
        continue;
    }
    if ($edad < $lim['min']) { continue; }

    /* La clave identifica el PROBLEMA, no el momento: mientras el WAF siga
       tapando, `tg_avisar_una_vez` no lo repite (salvo el re-aviso de
       `tg_repetir_min`, que existe para el que se pasa por alto). */
    $ok = $lim['lectura']
        ? tg_evento($pdo, 'salud', '🕸️ El WAF nos está tapando la lectura del panel', [
            'Qué no estamos pudiendo leer' => $lim['que'],
            'Hace'                         => $edad . ' minutos',
            'Qué significa'                => $lim['duele'],
            'Qué hacer'                    => 'Suele aflojar solo. Si sigue, mirá '
                                            . '/var/log/goldpaw-aprobar.log: cada challenge '
                                            . 'queda anotado con su reintento.',
        ], 'colector_' . $k . '_viejo')
        : tg_evento($pdo, 'salud', '⏰ Algo que tenía que correr solo dejó de correr', [
            'Qué dejó de correr' => $lim['que'],
            'Última vez'         => 'hace ' . ($edad >= 120 ? round($edad / 60) . ' horas' : $edad . ' minutos'),
            'Qué significa'      => $lim['duele'],
            'Qué hacer'          => $lim['arreglo'],
        ], 'tarea_' . $k . '_parada');
    if ($ok) { $avisados[] = $k; }
}

sc_salir(['ok' => true, 'estado' => $estado, 'avisados' => $avisados]);
