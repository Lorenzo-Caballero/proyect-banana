<?php
/**
 * notificaciones.php — La cara del dispositivo (celular / navegador).
 *
 * POST { accion:"registrar", device_id, usuario?, plataforma?, modelo?, version?, permitido?, soltar? }
 *        -> { ok }
 *        Lo llama el widget desde adentro del WebView. Es un navegador real, asi
 *        que el WAF de Hostinger no lo corta como cortaria a un curl.
 *        `soltar:true` = cerro sesion, desatar el celular del jugador. Sin eso,
 *        un registro sin usuario NO borra la vinculacion (puede ser que el
 *        widget todavia no haya visto quien es).
 *
 * GET  ?accion=pendientes&device_id=XXX[&limite=N]
 *        -> { ok, notificaciones:[{id,titulo,cuerpo,tipo,url,creada_en}] }
 *        Devuelve SOLO lo que este celular todavia no vio, y en la misma pasada
 *        lo marca como entregado. Lo consumen el worker del APK (cada ~15 min,
 *        aunque la app este cerrada) y el widget (cada 25 s con la app abierta).
 *
 * POST { accion:"token", device_id, token }     -> { ok, topico }
 * POST { accion:"leida", device_id, id }        -> { ok }
 *
 * POST { accion:"enviar", usuario|todos, titulo, cuerpo, tipo?, url? }
 *        -> { ok, id, alcance }     header: X-API-Key
 *        Para scripts (colector, cron). El agente NO usa esto: manda desde el
 *        CRM, que tiene su propia accion "notificar" en crm.php.
 *
 * OJO: el usuario de un dispositivo se define al REGISTRARLO desde la sesion,
 * nunca en el sondeo. Si no fuera asi, cualquiera podria leer las
 * notificaciones de otro jugador pasando su nombre por la URL.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/notificaciones_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------- GET ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'pendientes');
    if ($accion !== 'pendientes') {
        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    }

    $deviceId = (string)($_GET['device_id'] ?? '');
    if (trim($deviceId) === '') {
        salir(['ok' => false, 'error' => 'Falta device_id'], 400);
    }

    $limite = (int)($_GET['limite'] ?? NOTIF_MAX_POR_SONDEO);
    $resp = ['ok' => true, 'notificaciones' => notif_pendientes($pdo, $deviceId, $limite)];
    /* El estado de la RULETA viaja en el sondeo para que el APK no invente:
       sus recordatorios locales (Enganche) tienen textos que prometen un giro,
       y con la ruleta apagada del CRM esa promesa es falsa. El celular guarda
       este flag y saltea esos textos. Best-effort: sin config_crm no viaja y
       el APK asume prendida (el comportamiento de siempre). */
    try {
        require_once __DIR__ . '/config_crm.php';
        if (function_exists('cfg_crm_activo')) {
            $resp['ruleta'] = cfg_crm_activo($pdo, 'ruleta_activa');
        }
    } catch (Throwable $e) { /* sin flag */ }
    salir($resp);
}

// ------------------------------- POST --------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string)($body['accion'] ?? '');

// ---- alta del celular ----
if ($accion === 'registrar') {
    $deviceId = (string)($body['device_id'] ?? '');
    if (trim($deviceId) === '') {
        salir(['ok' => false, 'error' => 'Falta device_id'], 400);
    }
    $usuarioReg = isset($body['usuario']) ? trim((string)$body['usuario']) : null;
    $plataforma = (string)($body['plataforma'] ?? 'web');
    $ok = notif_registrar_dispositivo(
        $pdo,
        $deviceId,
        $usuarioReg !== '' ? $usuarioReg : null,
        $plataforma,
        isset($body['modelo'])  ? (string)$body['modelo']  : null,
        isset($body['version']) ? (string)$body['version'] : null,
        !isset($body['permitido']) || (bool)$body['permitido'],
        !empty($body['soltar'])
    );
    $resp = $ok ? ['ok' => true] : ['ok' => false, 'error' => 'No se pudo registrar'];

    /* Promo "descarga la app". El widget decide CUÁNDO mostrarla; acá solo se
       dice "a este le corresponde":
         - desde la app (android) nunca: ya la tiene;
         - solo si su tiene_app es 0 (el que ya la instaló no tiene nada que
           descargar y las fichas ya las cobró);
         - Y SOLO SI YA CARGÓ AL MENOS UNA VEZ.

       Esa última condición es del 14/09/2026 y cambió el criterio anterior,
       que era "a todo el que entre desde el navegador, anónimo incluido".
       Nahuel: "me creo usuario y cuando entro ya me sale eso, no lo quiero ahí
       porque bloquea la primera carga".

       Tenía razón, y el motivo es de plata: lo que sigue a crear la cuenta es
       la PRIMERA CARGA, que es la acción más valiosa que ese jugador va a
       hacer. Ponerle un modal encima para regalarle fichas por instalar una
       app cambia una carga real por un regalo, y encima al que todavía no
       demostró que paga. El anónimo queda afuera por lo mismo: todavía no es
       cliente.

       Al que ya cargó se le sigue ofreciendo, y el mejor momento lo resuelve
       el widget: cuando se le están acabando las fichas jugando.
       Best-effort: sin config_crm no hay promo y el registro sigue igual. */
    if ($ok && $plataforma !== 'android') {
        try {
            require_once __DIR__ . '/config_crm.php';
            if (cfg_crm_activo($pdo, 'app_promo_activa')) {
                $fichas = max(0, (int)(cfg_crm($pdo, 'app_bono_fichas') ?? 0));
                /* Sin sesión no hay a quién mirarle nada, y un anónimo no
                   cargó nunca: no le corresponde. */
                $corresponde = ($fichas > 0 && $usuarioReg !== null && $usuarioReg !== '');
                if ($corresponde) {
                    $st = $pdo->prepare("SELECT tiene_app FROM usuarios WHERE username = ?");
                    $st->execute([$usuarioReg]);
                    $fila = $st->fetch();
                    $yaLaTiene = $fila && (int)$fila['tiene_app'];
                    $corresponde = $fila && !$yaLaTiene;

                    /* ¿YA CARGÓ ALGUNA VEZ? Lo contesta rl_es_primera_carga(),
                       que es LA definición única de "una carga" en todo el CRM
                       (ver CLAUDE.md). Acá había una copia escrita a mano --
                       `recargas` acreditada o `movimientos` origen 'peticion'--
                       y ya se había separado sin que nadie lo notara: le faltaba
                       origen 'crm', o sea la carga que hace un agente a mano.
                       Con eso, a quien le cargaron desde el CRM este cartel no
                       se le ofrecía nunca, mientras notif_app_instalada() SÍ le
                       pagaba el bono al instalar. Dos respuestas distintas a la
                       misma pregunta, que es exactamente el error contra el que
                       CLAUDE.md advierte.

                       Ante la duda NO se ofrece: el costo de no mostrar el
                       cartel es cero, y el de mostrarlo encima de la primera
                       carga es una carga perdida. Por eso null (la función no
                       pudo contestar) cuenta como "no corresponde". */
                    if ($corresponde) {
                        if (!function_exists('rl_es_primera_carga')
                            && is_file(__DIR__ . '/recargas_lib.php')) {
                            require_once __DIR__ . '/recargas_lib.php';
                        }
                        $corresponde = function_exists('rl_es_primera_carga')
                                    && rl_es_primera_carga($pdo, $usuarioReg) === 0;
                    }
                    /* Se dice EXPLICITAMENTE que ya la tiene, en vez de dejar
                       que el widget lo deduzca de la ausencia de `app_promo`.
                       No es lo mismo: la promo tambien falta cuando esta
                       apagada o con 0 fichas, y si el widget confundiera los
                       dos casos, prender la promo de nuevo no le llegaria
                       nunca mas a ese navegador. Ademas le sirve para callarse
                       tambien cuando ese mismo navegador navega ANONIMO, donde
                       el server no tiene a quien mirarle el tiene_app. */
                    if ($yaLaTiene) { $resp['app_instalada'] = true; }
                }
                if ($corresponde) {
                    $resp['app_promo'] = [
                        'fichas' => $fichas,
                        'url'    => trim((string)(cfg_crm($pdo, 'app_url') ?? '')),
                        /* Umbral para el cartel "se te estan acabando las
                           fichas". Viaja al widget porque el saldo lo mira EL
                           -- lo lee del store del juego, en vivo. Desde acá no
                           se puede: `usuarios.balance` es un espejo que escribe
                           sync_usuarios.py cada tanto, y con un numero viejo el
                           cartel saldria tarde o, peor, le saldria a alguien
                           que acaba de cargar. 0 = apagado. */
                        'saldo_bajo' => max(0, (int)(cfg_crm($pdo, 'app_promo_saldo_bajo') ?? 0)),
                    ];
                }
            }
        } catch (Throwable $e) { /* sin promo, el registro ya salió bien */ }
    }
    salir($resp, $ok ? 200 : 500);
}

// ---- la tocó ----
/* EL CELULAR DICE A DONDE HAY QUE GOLPEARLE.
   Lo manda el APK (Notificaciones.sincronizarToken): en cada arranque y cada
   vez que Google le rota el token. Es idempotente a proposito -- se lo llama
   mucho mas de lo necesario porque un token muerto NO da error al usarlo, y el
   sintoma de no reintentar seria "a este jugador dejaron de llegarle las
   notificaciones" sin nada roto a la vista.

   NO ES UN SECRETO NI UNA CREDENCIAL: es una direccion que da Google, solo
   sirve para mandarle un push a ESE aparato desde NUESTRO proyecto de Firebase,
   y por eso este endpoint no pide autenticacion -- igual que 'registrar', que
   ya funcionaba asi. Lo peor que podria hacer alguien mandando un token ajeno
   es que los avisos de un jugador suenen en su propio telefono.

   La respuesta incluye el TOPICO del cliente, y eso si es tenant-sensible: es
   el canal por el que llegan los avisos masivos, y sale de la base que resolvio
   db.php por el dominio. El APK se suscribe a lo que le diga el server y no
   decide nada por su cuenta. */
if ($accion === 'token') {
    $deviceId = trim((string)($body['device_id'] ?? ''));
    $token    = trim((string)($body['token'] ?? ''));
    if ($deviceId === '' || $token === '') {
        salir(['ok' => false, 'error' => 'Falta device_id o token'], 400);
    }
    require_once __DIR__ . '/fcm_lib.php';
    $guardado = fcm_guardar_token($pdo, $deviceId, $token);
    salir([
        'ok'     => $guardado,
        'topico' => $guardado ? fcm_topico() : null,
    ], $guardado ? 200 : 500);
}

if ($accion === 'leida') {
    $deviceId = (string)($body['device_id'] ?? '');
    $id = (int)($body['id'] ?? 0);
    if (trim($deviceId) === '' || !$id) {
        salir(['ok' => false, 'error' => 'Falta device_id o id'], 400);
    }
    notif_marcar_leida($pdo, $deviceId, $id);
    salir(['ok' => true]);
}

// ---- envio desde un script (colector, cron): con clave ----
if ($accion === 'enviar') {
    exigir_api_key();

    $todos   = !empty($body['todos']);
    $usuario = $todos ? null : trim((string)($body['usuario'] ?? ''));
    $titulo  = (string)($body['titulo'] ?? '');
    $cuerpo  = (string)($body['cuerpo'] ?? '');
    if (!$todos && $usuario === '') {
        salir(['ok' => false, 'error' => 'Falta usuario (o mandá todos:true)'], 400);
    }
    if (trim($titulo) === '' || trim($cuerpo) === '') {
        salir(['ok' => false, 'error' => 'Falta titulo o cuerpo'], 400);
    }

    $id = notif_crear(
        $pdo, $usuario, $titulo, $cuerpo,
        (string)($body['tipo'] ?? 'promo'),
        isset($body['url']) ? (string)$body['url'] : null,
        'api'
    );
    if (!$id) { salir(['ok' => false, 'error' => 'No se pudo encolar'], 500); }
    salir(['ok' => true, 'id' => $id, 'alcance' => notif_alcance($pdo, $usuario)]);
}

salir(['ok' => false, 'error' => 'accion desconocida'], 400);
