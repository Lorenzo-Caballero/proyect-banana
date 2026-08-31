<?php
/**
 * config_crm.php — Ajustes del sitio que el agente edita desde el CRM.
 *
 * Clave/valor sobre la tabla `config_crm` (migración 38). No es un endpoint:
 * son funciones que usan ruleta.php, chatbot.php, crear_cuenta.php y la vista
 * de Configuración del CRM.
 *
 * REGLA: cada ajuste tiene su default acá, en DEFAULTS. Una fila que no existe
 * -o una migración sin correr- devuelve el default y el sitio sigue andando
 * como antes. Nada de lo que se configure desde acá puede tirar abajo el sitio
 * si la tabla no está.
 *
 * Requiere la migración sql/38_config_crm.sql.
 *
 * BLINDADA CONTRA DOBLE INCLUSION (el return de abajo): a este archivo lo
 * cargan endpoints con `require` plano Y libs con require_once (meta_lib
 * via recargas_lib). El dia que un endpoint carga una lib de esas ANTES de
 * su propio require, PHP redeclara cfg_crm_todo() y tira un fatal -- fue
 * exactamente el 500 de chatbot.php en produccion. Un `const` repetido
 * tambien es fatal, asi que el guard va antes de TODO, no por funcion.
 */

declare(strict_types=1);

/* Doble inclusion: el `return` corta la re-ejecucion (el const de abajo
   seria un warning), y cada funcion va ademas en function_exists porque a
   las funciones PHP las registra AL COMPILAR el include, antes de ejecutar
   este return -- un guard solo no las salva. Mismo patron que crm_lib. */
if (defined('CFG_CRM_CARGADO')) { return; }
define('CFG_CRM_CARGADO', 1);

/**
 * Valor por defecto de cada ajuste. Lo que no esté acá no existe: cfg_crm()
 * devuelve null y cfg_crm_guardar() lo rechaza, para que un typo en el
 * frontend no llene la tabla de claves fantasma.
 */
const CFG_CRM_DEFAULTS = [
    // Ruleta de bonos: si se apaga, el widget no la muestra y ruleta.php
    // rechaza los giros. Las dos cosas hacen falta -- esconder el botón no
    // alcanza, el endpoint es público.
    'ruleta_activa'   => '1',
    // Qué se le dice al jugador cuando está apagada. Vacío = mensaje genérico.
    'ruleta_mensaje'  => '',

    // ----- Juegos propios, prendibles por separado desde el CRM -----
    // Arrancan APAGADOS: un juego recien desplegado no puede aparecersele al
    // jugador por un default, tiene que ser una decision del agente.
    // *_tope_dia = maximo de bonos que la casa regala por dia en ese juego.
    // '0' = sin tope. Es el unico freno que un agente puede accionar solo,
    // sin esperar a que alguien mire un reporte.
    'raspa_activo'   => '0',
    'raspa_mensaje'  => '',
    'raspa_tope_dia' => '0',
    'slot_activo'    => '0',
    'slot_mensaje'   => '',
    'slot_tope_dia'  => '0',

    // El chatbot atiende. Apagado, el jugador puede escribir igual y le
    // contesta un agente (el mismo camino que config_chatbot.activo).
    'chat_activo'     => '1',

    // Alta de cuentas nuevas desde la landing y el chat.
    'registro_activo' => '1',

    // Link para bajar la app de Android. VACIO = el bot explica que se baja
    // desde la pagina, sin tipear ninguna URL.
    //
    // Va acá y no en las reglas fijas del chatbot a proposito: esas reglas las
    // comparten TODOS los clientes, asi que una URL escrita ahi seria la de un
    // casino repetida por el bot de otro. Cada agencia pone la suya.
    //
    // El bot NO puede inventarla: sin este valor tiene prohibido dar un link
    // (ver CB_REGLAS_FIJAS). Paso en produccion que mandaba a buscar la app a
    // Play Store, donde no esta.
    'app_url'         => '',

    // ----- Limites de carga y retiro, por cliente -----
    // Cada agencia tiene los suyos ("no cargo menos de 500", "no pago mas de
    // 100.000 por dia"). Antes eran constantes en fichas_lib.php, iguales
    // para todos.
    //
    // Los defaults reproducen EXACTAMENTE la conducta anterior, asi que
    // desplegar esto no le cambia el comportamiento a ningun cliente que ya
    // este andando.
    //
    // Se aplican en el AUTOSERVICIO del jugador (chat y widget), no cuando un
    // agente carga a mano desde el CRM: si alguien necesita hacer una
    // excepcion, tiene que poder.
    'lim_carga_min'      => '100',
    'lim_carga_max'      => '500000',
    // El minimo de retiro es OTRO numero que el de carga: antes se reusaba el
    // de carga y son negocios distintos (se suele dejar cargar poco y exigir
    // mas para pagar).
    'lim_retiro_min'     => '100',
    // Tope de lo que un jugador puede pedir por dia. '0' = sin tope, que es
    // como venia funcionando (no existia este limite).
    'lim_retiro_max_dia' => '0',
    // Bono que se le suma a una carga pedida desde el boton Depositos de la
    // plataforma, en % del monto. '0' = sin bono, la carga entra por el importe
    // exacto que transfirio el jugador.
    //
    // Lo aplica colector/aprobar_cargas.py con el campo `bonus_percent` que la
    // plataforma ya trae, y hay que fijarlo ANTES de aprobar: el bono se
    // calcula en el momento de la aprobacion y despues no se puede cargar.
    'lim_bono_carga_pct' => '0',

    // ----- Meta Ads (Pixel + Conversions API) -----
    // Apagado por defecto: sin pixel cargado no hay nada que mandar, y un
    // pixel a medio configurar ensucia las metricas de la campaña.
    'meta_activo'      => '0',
    'meta_pixel_id'    => '',
    // Token de CAPI: solo de servidor. meta_config_publica() NO lo devuelve --
    // en el HTML lo leeria cualquiera y podria mandar eventos falsos al pixel.
    'meta_capi_token'  => '',
    // Con esto puesto los eventos van a "Eventos de prueba" y NO cuentan para
    // la campaña. Vaciarlo al terminar de probar.
    'meta_test_code'   => '',
    // Donde se dispara PageView: registro | panel | ambos | off
    'meta_pageview_en' => 'registro',
    // Cada evento se prende/apaga por separado.
    'meta_ev_contact'  => '1',
    'meta_ev_lead'     => '1',
    'meta_ev_registro' => '1',
    'meta_ev_checkout' => '1',
    'meta_ev_purchase' => '1',

    // Cuando se leyeron por ultima vez los datos bancarios del panel de
    // ganamos (lo escribe bancos_sync.php). No lo edita nadie a mano: sirve
    // para distinguir "el panel no tiene billeteras cargadas" de "hace tres
    // dias que no lo podemos leer" -- las dos dejan el espejo vacio, pero una
    // es un problema del cliente y la otra es nuestro.
    'bancos_sync_en' => '',
];

/** Cache por request: estas funciones se llaman varias veces por pedido. */
$GLOBALS['__cfg_crm_cache'] = null;

/**
 * Todos los ajustes, con los defaults ya aplicados.
 *
 * Si la tabla no existe (migración sin correr) devuelve los defaults en vez de
 * lanzar: el sitio tiene que funcionar igual, simplemente sin nada configurado.
 */
if (!function_exists('cfg_crm_todo')) {
function cfg_crm_todo(PDO $pdo): array
{
    if ($GLOBALS['__cfg_crm_cache'] !== null) {
        return $GLOBALS['__cfg_crm_cache'];
    }
    $vals = CFG_CRM_DEFAULTS;
    try {
        $filas = $pdo->query("SELECT clave, valor FROM config_crm")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $f) {
            $k = (string)$f['clave'];
            // Solo las claves conocidas: una fila vieja o de otra versión no
            // se cuela en la respuesta que ve el frontend.
            if (array_key_exists($k, CFG_CRM_DEFAULTS)) {
                $vals[$k] = (string)($f['valor'] ?? '');
            }
        }
    } catch (Throwable $e) {
        error_log('config_crm: no pude leer la tabla (¿falta la migración 38?): ' . $e->getMessage());
    }
    $GLOBALS['__cfg_crm_cache'] = $vals;
    return $vals;
}
}

/** Un ajuste puntual. Devuelve null si la clave no existe en DEFAULTS. */
if (!function_exists('cfg_crm')) {
function cfg_crm(PDO $pdo, string $clave): ?string
{
    if (!array_key_exists($clave, CFG_CRM_DEFAULTS)) {
        return null;
    }
    return cfg_crm_todo($pdo)[$clave] ?? CFG_CRM_DEFAULTS[$clave];
}
}

/**
 * ¿Está prendido? Para los ajustes de sí/no.
 *
 * Cualquier cosa que no sea "0"/"" cuenta como prendido: es más seguro que un
 * valor raro deje algo funcionando a que lo apague sin que nadie entienda por
 * qué.
 */
if (!function_exists('cfg_crm_activo')) {
function cfg_crm_activo(PDO $pdo, string $clave): bool
{
    $v = trim((string)cfg_crm($pdo, $clave));
    return $v !== '0' && $v !== '' && strtolower($v) !== 'false';
}
}

/**
 * Guarda varios ajustes de una. Ignora las claves desconocidas.
 * Devuelve cuántos guardó.
 */
if (!function_exists('cfg_crm_guardar')) {
function cfg_crm_guardar(PDO $pdo, array $vals, string $operador = ''): int
{
    // Se filtra ANTES de preparar nada: con un payload vacio o todo invalido
    // esta funcion no tiene por que tocar la base.
    $limpios = array_intersect_key($vals, CFG_CRM_DEFAULTS);
    if (!$limpios) {
        return 0;
    }

    $n = 0;
    $st = $pdo->prepare(
        "INSERT INTO config_crm (clave, valor, operador) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), operador = VALUES(operador)"
    );
    foreach ($limpios as $k => $v) {
        $st->execute([$k, (string)$v, $operador !== '' ? $operador : null]);
        $n++;
    }
    $GLOBALS['__cfg_crm_cache'] = null;   // el próximo lector ve lo nuevo
    return $n;
}
}
