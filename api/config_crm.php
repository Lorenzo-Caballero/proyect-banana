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

    // ----- Promo "descarga la app y gana fichas" -----
    // Arranca APAGADA por la misma regla que los juegos: una promo que regala
    // fichas no puede aparecersele al jugador por un default de codigo, la
    // prende el agente desde Configuracion. Con esto en '1':
    //   - tras crear una cuenta por el chat aparece el modal de descarga
    //   - el chatbot invita a bajar la app mencionando el regalo
    //   - al PRIMER inicio de sesion desde la app se acreditan las fichas
    //     (una sola vez por jugador, y directo al juego)
    'app_promo_activa' => '0',
    // Cuantas fichas regala. '0' = ni modal ni bono aunque la promo este
    // prendida: un cartel ofreciendo 0 fichas es peor que ninguno.
    'app_bono_fichas'  => '1000',
    /* Umbral de fichas para mostrar el cartel de la app mientras juega.
       0 = apagado (el cartel sale solo en los momentos de siempre).
       El widget lee el saldo real del juego en vivo, asi que esto se evalua
       con el numero que el jugador tiene en pantalla, no con el espejo. */
    'app_promo_saldo_bajo' => '0',

    /* COMISIONES DE LA PASARELA DE PAGO, en porcentaje.
       Quien cobra por transferencia (HG Cash y similares) se lleva un % de
       cada movimiento, y distinto segun la direccion: tipicamente ~4% de lo
       que ENTRA y ~1% de lo que SALE. Eso sale de la ganancia y hasta ahora
       no se contaba en ningun lado.
       VACIO/0 = no se cobra nada, que es el caso de quien opera con
       billeteras virtuales. Es el default a proposito: cobrar una comision
       que no existe le haria ver a alguien una perdida inventada. */
    /* DESDE CUANDO medir todo lo acumulado: resultado del negocio, lo que
       deja un jugador, lo que cuesta traerlo, en cuanto se recupera.

       VACIO = automatico, y es el caso normal: el sistema arranca a medir con
       el primer dato propio, o sea el dia que el cajero lo empezo a usar. Un
       cajero nuevo no configura nada.

       Se pone a mano cuando la base tiene una etapa anterior que no
       corresponde mezclar -- un negocio que corrio, cerro, y se reactivo. Sin
       esto, la pauta de esta semana se reparte entre jugadores de hace un año
       y el costo de adquisicion sale mucho mas barato de lo que es. */
    'fin_medir_desde' => '',

    /* Cuantos dias sin cargar hacen que un jugador deje de contar como ACTIVO.
       El negocio es acumular jugadores que vuelven, asi que "cuantos tengo
       jugando" es mas importante que "cuantos cargaron alguna vez": de los que
       trae la publicidad, algunos vuelven y otros cargan una sola vez y se van.
       30 dias es el default; un casino con jugadores de fin de semana puede
       querer 45, y uno de mucha frecuencia 15. */
    'fin_dias_activo' => '30',

    'fin_comision_entrada' => '0',
    'fin_comision_salida'  => '0',

    // ----- Aviso por Telegram cuando el bot deriva a un agente -----
    // Vacios = sin Telegram, y no pasa nada: la derivacion igual queda marcada
    // en el CRM. Esto es el aviso que suena en el celular cuando nadie tiene
    // la pestaña abierta, que es justo cuando mas falta hace.
    //
    // Van por CLIENTE y no en config.local.php porque cada agencia avisa a su
    // propia gente; si estuvieran en el server, todas compartirian el mismo
    // grupo de Telegram. El config.local.php queda igual como respaldo (ver
    // tg_credenciales en telegram_lib.php).
    'tg_bot_token'    => '',
    // Puede ser una persona o un GRUPO (ahi el id arranca con "-"), que es lo
    // util cuando hay varios agentes: se enteran todos.
    'tg_chat_id'      => '',

    // Cada cuantos MINUTOS se repite un aviso de algo que SIGUE roto. Los
    // detecta un cron que corre cada minuto, asi que sin este freno serian
    // 1.440 mensajes por dia por problema -- y el agente termina silenciando
    // el bot justo antes de que pase algo importante.
    'tg_repetir_min'  => '180',
    // Horas sin NINGUNA actividad tras las cuales se avisa. '0' = no avisar.
    // 6 y no menos: de madrugada no hay nadie jugando y eso es normal.
    'tg_sin_actividad_hs' => '6',

    // Que avisar. Cada uno por separado, porque no todos los clientes quieren
    // las mismas interrupciones: el de retiros le suena a quien paga, el de
    // derivaciones a quien atiende.
    'tg_ev_derivacion' => '1',   // el bot paso una charla a un humano
    'tg_ev_revision'   => '1',   // entro una transferencia que no se pudo casar
    'tg_ev_retiro'     => '1',   // un jugador pidio retirar
    'tg_ev_salud'      => '1',   // algo esta roto / sin actividad
    // Estos dos son informativos, no piden accion: sirven para mirar el
    // negocio de reojo sin entrar al CRM. Arrancan APAGADOS a proposito --
    // con volumen son muchos mensajes por dia, y quien los quiera los prende.
    // Minutos que la IA espera antes de RETOMAR una charla que derivo a un
    // humano, si el agente no la atendio. 0 = nunca reconecta (queda para
    // el agente para siempre, como era antes). El aviso de Telegram al
    // jugador que reescribe derivado dice este numero, asi el operador sabe
    // en cuanto lo va a retomar el bot.
    // 30 -> 10 -> 5 (15/09/2026, dos veces el mismo dia). Nahuel: "debe ser un
    // poco mas rapido... quiero estar seguro que a los pocos minutos vuelva a
    // activarse".
    //
    // CINCO MINUTOS NO ES POCO, aunque lo parezca: el reloj arranca en la
    // ULTIMA respuesta del agente, no en la primera. Cada vez que el agente
    // escribe, crm.php vuelve a poner ia_silencio_en = NOW() -- asi que
    // mientras la conversacion este viva el bot no se mete. Los 5 minutos
    // corren recien cuando el agente deja de contestar.
    //
    // Bajar mas se vuelve riesgoso: un agente que se toma dos minutos para
    // mirar el panel y volver se encontraria al bot hablando encima.
    //
    // OJO, ESTO ES EL DEFAULT: rige en una base que nunca guardo el valor. Si
    // config_crm ya tiene la fila, GANA la fila -- en una instalacion andando
    // hay que cambiarlo en Configuracion del CRM, no aca.
    //
    // Y OJO CON LO QUE MIDE: no es un cron. El bot se vuelve a prender cuando
    // el JUGADOR escribe de nuevo pasados estos minutos (chatbot.php:457). Si
    // no vuelve a escribir, no pasa nada -- no hay a quien contestarle.
    'ia_reconectar_min' => '5',
    'tg_ev_alta'       => '0',   // se registro un jugador (o no se pudo)
    'tg_ev_pago'       => '0',   // entro una transferencia y se acredito sola
    // Prendido por defecto a pedido de Nahuel: es la señal de que la promo de
    // la app convierte, y el volumen es el de instalaciones, no el de pagos.
    'tg_ev_app'        => '1',   // un jugador instalo la app y entro

    // ----- Campaña de fidelizacion (bonos escalonados por inactividad) -----
    // Apagada por defecto, como toda promo que regala plata: la prende el
    // admin desde la vista Fidelizacion del CRM.
    'fid_activa'  => '0',
    // Los escalones: al cumplir `dias` sin jugar, se promete `pct` % sobre la
    // proxima carga; `ruleta` regala ademas un giro de cortesia. JSON editable
    // desde el CRM (config_crm.valor es TEXT, entra sobrado). El default es
    // la campaña que pidio Nahuel. Un JSON roto = se usa este default:
    // una config ilegible no puede apagar la campaña a la mitad.
    'fid_tramos'  => '[{"dias":2,"pct":20},{"dias":3,"pct":25},{"dias":4,"pct":30},{"dias":7,"pct":40},{"dias":8,"pct":50,"ruleta":1}]',
    // Latido del motor: fid_correr() lo sella en CADA pasada (aun apagada).
    // La vista Fidelizacion lo muestra para responder de un vistazo "¿el cron
    // esta corriendo?" -- mismo patron que bot_cargas_visto_en.
    'fid_visto_en' => '',

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
    // Tope de UN retiro. Va aparte del tope diario porque resuelven cosas
    // distintas: muchos casinos pagan hasta 100.000 por dia pero en tandas de
    // 50.000, para no mover todo junto. Con solo el tope diario, el jugador se
    // lleva los 100.000 en un pedido. '0' = sin tope.
    'lim_retiro_max'     => '0',
    // Tope de lo que un jugador puede pedir por dia. '0' = sin tope, que es
    // como venia funcionando (no existia este limite).
    'lim_retiro_max_dia' => '0',
    // Cuantos retiros puede pedir por dia, contados aparte del monto: con
    // lim_retiro_max en 50.000, lim_retiro_max_dia en 100.000 y esto en 2,
    // sale exactamente "dos retiros de 50.000 por dia". '0' = sin limite.
    'lim_retiro_cant_dia' => '0',
    // Franja horaria en la que NO se puede retirar, en hora ARGENTINA y
    // formato HH:MM. Tipicamente la madrugada, cuando no hay nadie para
    // aprobar. Puede cruzar la medianoche ('23:00' a '06:00').
    //
    // VACIAS = sin restriccion, y hacen falta las DOS para que aplique. Es
    // vacio y no '0' porque 0 es una hora legitima (medianoche): la convencion
    // "0 = desactivado" que usan los topes no sirve para un horario.
    'lim_retiro_hora_desde' => '',
    'lim_retiro_hora_hasta' => '',

    // ----- Stock de fichas de la cuenta de agente -----
    // Avisar por Telegram cuando NUESTRO saldo en ganamos baja de este numero.
    // '0' = sin aviso.
    //
    // POR QUE EXISTE: el 12/9/2026 la cuenta se quedo sin fichas y la
    // plataforma empezo a rechazar los depositos. Es una condicion operativa
    // normal -- se acaba el stock y hay que comprarle mas al proveedor -- pero
    // se descubria cuando los jugadores reclamaban. El umbral tiene que dar
    // tiempo a reponer, asi que se pone bastante mas arriba de cero: si el
    // casino mueve 50.000 por dia, avisar en 50.000 es avisar tarde.
    //
    // El default es '0' y no un numero: lo que para un casino es poco, para
    // otro es mucho, y un umbral inventado o no suena nunca o suena siempre.
    'lim_stock_aviso'    => '0',
    // Ultima lectura, para mostrarla en el CRM. Las escribe stock_agente.php,
    // no se editan a mano.
    'stock_fichas'       => '',
    'stock_fichas_en'    => '',

    // ----- Credenciales del panel de agentes (agents.ganamos7.com desde el 16/09/2026) -----
    // Las usa el bot del VPS para loguearse y depositar/crear jugadores.
    // VACIAS = el bot sigue con las PANEL_USER/PANEL_PASS de su .env, que es
    // el comportamiento de siempre -- desplegar esto no cambia nada hasta que
    // el cliente cargue las suyas. Se editan desde Configuracion del CRM y el
    // bot las pide por acciones_cola.php?accion=panel_credenciales (con la
    // API key), asi un cambio de contraseña del panel no exige tocar el .env
    // del contenedor. panel_pass NUNCA va en meta_config_publica ni en ningun
    // endpoint sin auth: solo el CRM (admin) y el bot (API key) la ven.
    'panel_user' => '',
    'panel_pass' => '',

    // Bono de BIENVENIDA de la landing bono.html, en % de la primera carga.
    // El default '50' reproduce la constante historica RL_BONO_BIENVENIDA_PCT:
    // desplegar esto no cambia nada hasta que el cliente lo toque. La landing
    // muestra este mismo numero (lo pide a bono_config.php), asi la promesa y
    // el pago no pueden decir cosas distintas. Las landings del CRM (lp:<slug>)
    // no usan esto: cada una tiene SU bono_pct propio.
    'bono_bienvenida_pct' => '50',

    // Bono que se le suma a una carga pedida desde el boton Depositos de la
    // plataforma, en % del monto. '0' = sin bono, la carga entra por el importe
    // exacto que transfirio el jugador.
    //
    // Lo aplica colector/aprobar_cargas.py con el campo `bonus_percent` que la
    // plataforma ya trae, y hay que fijarlo ANTES de aprobar: el bono se
    // calcula en el momento de la aprobacion y despues no se puede cargar.
    'lim_bono_carga_pct' => '0',

    // ----- Plan de referidos (migracion 53, referidos_lib.php) -----
    // Apagado y en 0 por defecto: prender el plan es una promesa de plata y
    // tiene que ser una decision explicita del dueño, no un default.
    //
    //   ref_activo      habilita aceptar ?ref= en el alta y la difusion.
    //   ref_bono_monto  BONOS que cobra el que trajo al amigo cuando el amigo
    //                   acredita su PRIMERA carga. Monto fijo, no %: "traes
    //                   un amigo, te llevas N" se entiende y se difunde solo.
    //                   Se lee al momento de PAGAR: cambiarlo afecta los
    //                   pagos futuros, no los ya hechos.
    //   ref_mensaje     la plantilla de la difusion. {link} se reemplaza por
    //                   el link UNICO de cada cliente -- por eso la difusion
    //                   del plan tiene su camino propio y no la masiva comun,
    //                   que manda el mismo texto a todos -- y {bono} por el
    //                   monto configurado arriba, para que subirlo no exija
    //                   acordarse de reescribir el mensaje.
    //                   El default va ACA y no vacio: asi el CRM muestra la
    //                   plantilla ya escrita, lista para tocar o mandar tal
    //                   cual, en vez de un campo en blanco que hay que saber
    //                   llenar.
    'ref_activo'     => '0',
    'ref_bono_monto' => '0',
    'ref_mensaje'    => "🎁 ¡Invitá y ganá!\n\n"
        . "Compartí tu link con tus amigos: cuando uno se registre y haga su "
        . "primera carga, te regalamos {bono} en bonos. Se acreditan solos, "
        . "sin pedir nada.\n\n"
        . "👇 Este es TU link, tocalo y reenvialo:\n{link}",

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

    // Ultima vez que el bot de altas sondeo su cola (lo escribe
    // altas_cola.php en cada accion=pendientes; el bot sondea cada ~3s).
    // No lo edita nadie a mano. Lo lee salud_bot.php: distingue "el
    // contenedor esta muerto / crash-loopeando" (latido viejo o ausente) de
    // "el bot vive pero el panel le rechaza el trabajo" (latido fresco y la
    // cola igual no drena) SIN entrar al VPS -- la ambiguedad que alargo los
    // incidentes del 7/9 y 10/9/2026.
    'bot_altas_visto_en' => '',

    // Gemelo del anterior para el loop de DEPOSITOS (acciones_cola.php
    // accion=pendientes). Un bot viejo late en altas pero no aca: es la
    // firma exacta de "las altas andan y las cargas del CRM quedan
    // pendientes para siempre" (10/9/2026).
    'bot_cargas_visto_en' => '',

    /* QUE PUDO LEER EL COLECTOR DEL PANEL, y cuando por ultima vez.
       Lo escribe salud_colector.php con lo que le reporta aprobar_cargas.py en
       cada pasada. Nadie lo edita a mano.

       Existen porque las lecturas fallaban EN SILENCIO: con el WAF tapando el
       espejo, el CRM abre, el chat contesta y las cargas se aprueban -- lo
       unico que pasa es que los saldos envejecen, el bot le discute el saldo a
       gente que si tiene plata, y un jugador recien creado no aparece. Los dos
       watchdogs que habia (monitor-altas.sh, monitor-cargas.sh) dan VERDE en
       ese escenario, porque miran el worker y la cola, no lo que el worker
       pudo leer.

       `_en` guarda la ultima lectura BUENA (solo la mueve un 'ok'); `_estado`
       guarda como salio la ultima pasada, que es distinto: sirve para ver que
       el colector esta vivo y peleando y no muerto. */
    'colector_espejo_en'     => '',
    'colector_espejo_estado' => '',
    'colector_libro_en'      => '',
    'colector_libro_estado'  => '',
    'colector_stock_en'      => '',
    'colector_stock_estado'  => '',
    'colector_challenges'    => '',   // challenges del WAF en el ultimo barrido
    'colector_visto_en'      => '',   // ultima vez que el colector reporto algo

    /* LATIDOS DE LAS TAREAS QUE CORREN SOLAS. Cada una sella el suyo al
       terminar; salud_colector.php avisa si alguno se queda quieto.

       Existen por lo que aparecio el 18/09/2026 mirando a mano: TRES tareas
       apuntando a la nada --el cron de bancos a un contenedor apagado, el de
       fidelizacion que nunca se instalo, y el espejo muriendo en el primer
       challenge-- y ninguna daba error visible. Un proceso que no corre no se
       queja: simplemente no pasa nada, y eso se ve igual que "no habia nada
       que hacer". */
    'difusiones_visto_en'   => '',   // difusiones_chat_procesar.php (cada 10 min)
    'ruleta_aviso_visto_en' => '',   // ruleta_recordatorio.php (una vez por dia)

    /* DESDE CUANDO ESTAMOS MIRANDO. Sin esto, una tarea que no corrio NUNCA
       se queda en null para siempre y no avisa jamas -- que es exactamente el
       caso de la fidelizacion: su cron nunca se instalo, y solo se descubrio
       porque alguien la habia corrido a mano una vez y esa fecha envejecio.
       Con este ancla, "nunca corrio" tambien envejece. */
    'tareas_vigilando_desde' => '',
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
