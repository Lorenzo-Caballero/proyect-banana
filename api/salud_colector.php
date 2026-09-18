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

/* ---- 2. ¿Alguna lectura quedó tan vieja que ya se nota? ------------------ */
$avisados = [];
$estado   = [];
foreach (SC_LIMITES as $k => $lim) {
    $edad = null;
    try {
        $v = trim((string)cfg_crm($pdo, 'colector_' . $k . '_en'));
        if ($v !== '') {
            $t = strtotime($v);
            if ($t !== false) { $edad = max(0, (int)floor((time() - $t) / 60)); }
        }
    } catch (Throwable $e) { /* sin config_crm no se avisa, no se rompe */ }

    $estado[$k] = ['hace_min' => $edad, 'limite_min' => $lim['min']];

    /* `null` es «nunca lo vimos», y eso NO se avisa: es lo que pasa la primera
       vez que corre este archivo, y un aviso ahí sería ruido el día del
       deploy. Lo que se avisa es una fecha que existe y quedó vieja. */
    if ($edad === null || $edad < $lim['min']) { continue; }

    /* La clave identifica el PROBLEMA, no el momento: mientras el WAF siga
       tapando, `tg_avisar_una_vez` no lo repite (salvo el re-aviso de
       `tg_repetir_min`, que existe para el que se pasa por alto). */
    $ok = tg_evento($pdo, 'salud', '🕸️ El WAF nos está tapando la lectura del panel', [
        'Qué no estamos pudiendo leer' => $lim['que'],
        'Hace'                         => $edad . ' minutos',
        'Qué significa'                => $lim['duele'],
        'Qué hacer'                    => 'Suele aflojar solo. Si sigue, mirá '
                                        . '/var/log/goldpaw-aprobar.log: cada challenge '
                                        . 'queda anotado con su reintento.',
    ], 'colector_' . $k . '_viejo');
    if ($ok) { $avisados[] = $k; }
}

sc_salir(['ok' => true, 'estado' => $estado, 'avisados' => $avisados]);
