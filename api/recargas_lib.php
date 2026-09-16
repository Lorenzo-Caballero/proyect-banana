<?php
/**
 * recargas_lib.php — Logica de recargas de coins.
 *
 * No es un endpoint: son funciones que usan chatbot.php (para crear/consultar)
 * y pagos.php (para acreditar cuando llega la transferencia). Nadie lo llama por
 * HTTP directamente.
 *
 * Flujo:
 *   1. rl_crear_recarga()  -> el chatbot crea el pedido y devuelve el monto
 *                             a transferir (el monto redondo que pidio).
 *   2. el usuario transfiere ese importe.
 *   3. el colector lee el mail y pagos.php llama rl_registrar_pago().
 *   4. rl_registrar_pago() casa el monto con la recarga y acredita los coins.
 */

declare(strict_types=1);

// Opcional, igual que crm_lib en ruleta.php: si el archivo esta, el jugador
// recibe una notificacion cuando su transferencia se acredita. Si no esta, la
// recarga funciona igual (solo que se entera al abrir la app).
if (is_file(__DIR__ . '/notificaciones_lib.php')) {
    require_once __DIR__ . '/notificaciones_lib.php';
}
// Opcional: si esta, un bono de fichas/porcentaje prometido por notificacion
// se aplica solo en la proxima recarga acreditada de ese usuario. Si no esta,
// la recarga se acredita igual, sin bono.
if (is_file(__DIR__ . '/crm_notificaciones.php')) {
    require_once __DIR__ . '/crm_notificaciones.php';
}
if (is_file(__DIR__ . '/crm_lib.php')) {
    require_once __DIR__ . '/crm_lib.php';
}
// Opcional: si estan, cada recarga acreditada se reporta a Meta Ads (Purchase)
// para el publicista que trajo a ese jugador (si vino de una landing con
// ?pub=). Si no estan, la recarga se acredita igual, sin reportar nada.
if (is_file(__DIR__ . '/meta_lib.php')) {
    require_once __DIR__ . '/meta_lib.php';
}
if (is_file(__DIR__ . '/publicidad_lib.php')) {
    require_once __DIR__ . '/publicidad_lib.php';
}
// "Hace cuanto que este jugador no aparece". Opcional como los de arriba: si
// el archivo no esta, la recarga se acredita igual.
if (is_file(__DIR__ . '/actividad_lib.php')) {
    require_once __DIR__ . '/actividad_lib.php';
}
// Para encolar la carga al juego apenas se acredita la transferencia (ver
// rl_cargar_al_juego_auto). Va con require_once y no al reves: fichas_lib.php
// NO carga este archivo, asi que no hay ciclo. Hace falta porque pagos.php --
// por donde entra el colector -- solo requiere recargas_lib.
if (is_file(__DIR__ . '/fichas_lib.php')) {
    require_once __DIR__ . '/fichas_lib.php';
}
// Aviso al agente cuando una transferencia entra y no se puede casar sola. Va
// aca por el mismo motivo que fichas_lib: pagos.php -- por donde entra el
// colector de mails -- solo requiere recargas_lib, y sin esto el aviso no
// saldria justo en el caso que mas urge (plata que llego y nadie sabe).
if (is_file(__DIR__ . '/telegram_lib.php')) {
    require_once __DIR__ . '/telegram_lib.php';
}

// Landings del CRM (migracion 52): el bono de bienvenida de una landing
// 'lp:<slug>' sale de su fila en `landings`, no de la constante de abajo.
// Best-effort igual que telegram: sin el archivo, ese camino simplemente no
// da bonos y el resto de la acreditacion no se entera.
if (is_file(__DIR__ . '/landings_lib.php')) {
    require_once __DIR__ . '/landings_lib.php';
}

// Plan de referidos (migracion 53): cuando un jugador acredita su PRIMERA
// carga, el que lo trajo cobra su bono. Mismo criterio best-effort que
// landings_lib: sin el archivo, nadie cobra referidos y la acreditacion sigue.
if (is_file(__DIR__ . '/referidos_lib.php')) {
    require_once __DIR__ . '/referidos_lib.php';
}

// =====================  EDITA ESTO  =======================================
const RL_COINS_POR_PESO = 1;        // 1 coin = 1 peso  (5000 coins => $5000)
const RL_MIN_COINS      = 100;      // minimo por recarga
const RL_MAX_COINS      = 1000000;  // maximo por recarga
const RL_VENCIMIENTO_MIN = 45;      // minutos que vive una recarga sin pagar
const RL_VENTANA_MIN     = 120;     // ventana para el match de respaldo (entero)
const RL_MAX_PENDIENTES_USUARIO = 5;// recargas pendientes simultaneas por usuario

/* Bono de bienvenida de la landing bono.html: % de la PRIMERA recarga
   acreditada que se suma en BONOS (usuarios.bonus, el contador propio -- no
   toca el saldo de ganamos).

   Solo lo cobra quien se registro por esa landing (altas.origen = 'bono50',
   ver crear_cuenta.php): el numero gigante de esa pagina es una promesa, y
   esto es lo que la cumple. Si cambias el porcentaje aca, cambia tambien
   BONO_PCT en landing/bono.html -- los dos numeros TIENEN que decir lo mismo,
   y el que manda es este.

   0 lo apaga sin tocar la landing (quedaria prometiendo de mas: bajala). */
const RL_BONO_BIENVENIDA_PCT    = 50;
const RL_BONO_BIENVENIDA_ORIGEN = 'bono50';

// Cruce por nombre del titular (migracion 45). Dos numeros, y conviene
// entender que hace cada uno antes de tocarlos:
//   UMBRAL  -- cuanto se tienen que parecer los nombres para siquiera
//              considerarlo. 0.72 tolera una errata o un apellido faltante,
//              y rechaza dos personas distintas.
//   MARGEN  -- cuanto le tiene que sacar el primero al segundo. Esta es la
//              proteccion que importa: con dos candidatas parecidas (dos
//              hermanos, "JUAN PEREZ" y "JUAN PEREZ GOMEZ"), acertar por
//              poquito es adivinar. Si no hay margen, va a revision.
const RL_UMBRAL_NOMBRE = 0.72;
const RL_MARGEN_NOMBRE = 0.15;

// Datos de la cuenta donde el usuario transfiere (los muestra el chatbot).
// RESPALDO DE ULTIMA INSTANCIA, no la fuente. La cuenta real de cada cliente
// vive en goldpaw_control.clientes (cobro_alias/cobro_cbu/cobro_titular, se
// cargan desde el panel del dueño) y la lee rl_cuenta_cobro(). Estas
// constantes solo aparecen si ese lookup falla Y no hay nada cargado --
// editar este archivo en el VPS no sirve: el deploy lo pisa en cada corrida.
// Cuenta del dueño (Cencosud). El colector escucha los avisos de esa cuenta
// en nahuelherrera1997@gmail.com (carpeta "pagos", remitente de Cencosud con
// DKIM) -- ver colector/config.json. Si algun dia queda sin alias, va vacio:
// vacio significa "no compartir alias", nunca inventar uno.
const RL_ALIAS   = 'ganamos1010';
const RL_CBU     = '0000184305000041593023';
const RL_TITULAR = 'Herrera Facundo Nahuel';
// ==========================================================================

/**
 * Conexion a goldpaw_control (la base maestra), cacheada por proceso.
 * Mismo patron que hg_control() en hgcash_lib.php -- el usuario de la app
 * tiene grant en todas las bases. Inyectable para tests via
 * $GLOBALS['HG_CONTROL_OVERRIDE'] (mismo global que ya usaban los tests de
 * HG Cash, se reutiliza para no sumar un segundo mecanismo de override).
 */
function rl_control(): ?PDO
{
    if (isset($GLOBALS['HG_CONTROL_OVERRIDE']) && $GLOBALS['HG_CONTROL_OVERRIDE'] instanceof PDO) {
        return $GLOBALS['HG_CONTROL_OVERRIDE'];
    }
    static $ctl = null, $intentado = false;
    if ($intentado) { return $ctl; }
    $intentado = true;
    try {
        $ctl = new PDO(
            'mysql:host=' . cfg('DB_HOST', 'localhost')
                . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
            cfg('DB_USER'), cfg('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
    } catch (Throwable $e) {
        error_log('rl_control: no pude conectar a goldpaw_control: ' . $e->getMessage());
        $ctl = null;
    }
    return $ctl;
}

/**
 * La fila de ESTE cliente en goldpaw_control.clientes (id, metodo_cobro,
 * cobro_*, hg_propio_*), cacheada por proceso. null si no se pudo resolver
 * (control caido, tenant sin fila) -- todo lo que la consume ya sabe
 * degradar a un default seguro.
 */
function rl_cliente_actual(): ?array
{
    static $c = null, $intentado = false;
    if ($intentado) { return $c; }
    $intentado = true;
    $ctl = rl_control();
    $db  = (string)($GLOBALS['TENANT_DB'] ?? cfg('DB_NAME'));
    if (!$ctl || $db === '') { return $c = null; }
    try {
        $st = $ctl->prepare(
            'SELECT id, metodo_cobro, coins_por_peso, cobro_alias, cobro_cbu, cobro_titular,
                    cobro_modo, cobro_fija_id,
                    hg_propio_activo, hg_propio_token, hg_propio_account_id,
                    hg_propio_webhook_secret, hg_propio_modo
               FROM clientes WHERE db_nombre = ? LIMIT 1'
        );
        $st->execute([$db]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('rl_cliente_actual: ' . $e->getMessage());
        $c = null;
    }
    return $c;
}

/**
 * CUANTOS COINS VALE UN PESO PARA ESTE CLIENTE.
 *
 * El panel del dueño pedia este numero desde siempre (campo «Coins por peso»)
 * y lo guardaba en goldpaw_control.clientes.coins_por_peso... y nadie lo leia:
 * el calculo usaba la constante RL_COINS_POR_PESO, la misma para todos. O sea
 * que un cliente que ponia 2 seguia cobrando 1 a 1, sin un solo error.
 *
 * Mismo patron que la cuenta de cobro: el cliente manda, la constante es el
 * respaldo de ultima instancia si el control no responde o no hay nada
 * cargado. Una base maestra caida no puede frenar una recarga.
 *
 * GUARDA CONTRA EL 0: es un DECIMAL con default 1.0000, pero un 0 o un
 * negativo cargado a mano en la base haria una division por cero justo en el
 * calculo del monto a cobrar. Cualquier valor no positivo cae al respaldo.
 */
function rl_coins_por_peso(): float
{
    $c = rl_cliente_actual();
    $v = $c !== null ? (float)($c['coins_por_peso'] ?? 0) : 0.0;
    return $v > 0 ? $v : (float)RL_COINS_POR_PESO;
}

/**
 * La cuenta de cobro PRINCIPAL de ESTE cliente, desde goldpaw_control.clientes.
 *
 * Multi-tenant de verdad: cada cliente cobra en SU cuenta, no en una
 * constante compartida. Fallback a las constantes si el control no responde
 * o no hay nada cargado: una base maestra caida no puede frenar la creacion
 * de recargas. Para TODAS las cuentas activas del cliente (si cargo mas de
 * una), ver rl_cuentas_cobro().
 */
/**
 * La billetera que tiene cargada el cliente en el PANEL DE GANAMOS, espejada
 * por colector/sync_bancos.py (tabla bancos_ganamos, migracion 47).
 *
 * ES LA FUENTE DE VERDAD. El jugador que pide un deposito DENTRO de la
 * plataforma ve esto mismo, asi que el chat tiene que decir lo mismo o la
 * plata entra en dos cuentas y el colector solo escucha los mails de una.
 *
 * Se toma la de `posicion` 0: segun las pruebas contra el panel, cuando hay
 * varias cargadas la plataforma le muestra al jugador la primera. Lo sano es
 * tener UNA sola (sync_bancos.py avisa si hay mas).
 *
 * Devuelve null si no hay espejo todavia (el sync nunca corrio, o el cliente
 * no cargo ninguna) -- ahi manda lo configurado a mano, como antes.
 */
function rl_banco_panel(): ?array
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $st = $pdo->query(
            "SELECT titular, details, tipo FROM bancos_ganamos ORDER BY posicion, id_ganamos LIMIT 1"
        );
        $fila = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;   // falta la migracion 47: se sigue con lo de antes
    }
    if (!$fila || trim((string)$fila['details']) === '') {
        return null;
    }
    // El panel guarda UN campo (`details`) y aparte el tipo. Se reparte en
    // alias/cbu segun lo que sea, que es como lo espera el resto del sistema.
    $esAlias = stripos((string)$fila['tipo'], 'alias') !== false;
    $dato    = trim((string)$fila['details']);
    return [
        'id'      => 0,
        'alias'   => $esAlias ? $dato : '',
        'cbu'     => $esAlias ? ''    : $dato,
        'titular' => trim((string)($fila['titular'] ?? '')),
    ];
}

function rl_cuenta_cobro(): array
{
    // Primero el panel de ganamos: si el cliente cargo su billetera ahi, esa
    // es la que ve el jugador cuando pide deposito en la plataforma, y el
    // chat tiene que coincidir. Lo de goldpaw_control queda de respaldo para
    // cuando el espejo todavia no corrio.
    $delPanel = rl_banco_panel();
    if ($delPanel) {
        return $delPanel;
    }

    // id=0 es el sentinel de "la principal" (nunca choca con un id real de
    // cobro_cuentas, que es AUTO_INCREMENT desde 1) -- lo usa rl_cuenta_elegida()
    // para saber si cobro_fija_id=NULL se refiere a esta cuenta.
    $cta = ['id' => 0, 'alias' => RL_ALIAS, 'cbu' => RL_CBU, 'titular' => RL_TITULAR];
    $c = rl_cliente_actual();
    if ($c) {
        if (trim((string)($c['cobro_alias'] ?? ''))   !== '') { $cta['alias']   = trim((string)$c['cobro_alias']); }
        if (trim((string)($c['cobro_cbu'] ?? ''))     !== '') { $cta['cbu']     = trim((string)$c['cobro_cbu']); }
        if (trim((string)($c['cobro_titular'] ?? '')) !== '') { $cta['titular'] = trim((string)$c['cobro_titular']); }
    }
    return $cta;
}

/**
 * TODAS las cuentas de cobro activas de este cliente: la principal (arriba)
 * mas las que haya cargado en `cobro_cuentas` (migracion 06, control) --
 * pensada para clientes con varias billeteras virtuales. rl_crear_recarga()
 * usa rl_cuenta_elegida() para decidir CUAL de estas usar en cada recarga
 * (rotar al azar, o siempre la misma, segun cobro_modo -- nunca se le
 * muestran varias opciones a un mismo jugador a la vez).
 *
 * Siempre devuelve al menos la principal (con fallback a las constantes si
 * ni eso hay), asi el caller nunca tiene que manejar el caso "sin cuentas".
 */
function rl_cuentas_cobro(): array
{
    $lista = [rl_cuenta_cobro()];
    $c = rl_cliente_actual();
    $ctl = rl_control();
    if (!$c || !$ctl) { return $lista; }
    try {
        $st = $ctl->prepare(
            'SELECT id, alias, cbu, titular FROM cobro_cuentas WHERE cliente_id = ? AND activa = 1 ORDER BY id'
        );
        $st->execute([(int)$c['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if (trim((string)($fila['cbu'] ?? '')) === '') { continue; }   // CBU es obligatorio
            $lista[] = [
                'id'      => (int)$fila['id'],
                'alias'   => trim((string)($fila['alias'] ?? '')),
                'cbu'     => trim((string)$fila['cbu']),
                'titular' => trim((string)($fila['titular'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        error_log('rl_cuentas_cobro: ' . $e->getMessage());
    }
    return $lista;
}

/**
 * Cual cuenta usar para ESTA recarga: SIEMPRE la billetera en uso
 * (cobro_fija_id; 0/NULL = la principal).
 *
 * Es deterministico a proposito. Si la elegida ya no esta activa (se pauso o
 * se borro) cae a la principal, no a una cualquiera -- el porque esta
 * explicado adentro de la funcion.
 */
function rl_cuenta_elegida(): array
{
    $cuentas = rl_cuentas_cobro();
    $c = rl_cliente_actual();

    // SIEMPRE la billetera EN USO, nunca al azar.
    //
    // Habia un modo "rotar al azar" (clientes.cobro_modo) y se saco a
    // proposito -- no lo re-agregues sin leer esto:
    //
    // El jugador puede pedir los datos de dos formas: por el chat (donde
    // contesta este codigo) o pidiendo un deposito DENTRO de la plataforma,
    // donde ve el CBU que esta cargado en el panel de agentes de ganamos.
    // Son dos fuentes distintas para el mismo dato. Si no coinciden, la plata
    // entra en dos cuentas y el colector escucha los mails de UNA sola: lo
    // que caiga en la otra no se acredita jamas y el jugador reclama.
    //
    // Con una billetera fija, mantener las dos en sincronia es posible. Con
    // rotacion al azar habria que actualizar el panel de ganamos en CADA
    // recarga, y cualquier fallo desincroniza el sistema en silencio.
    //
    // cobro_modo sigue en la base (no se borro para no romper migraciones)
    // pero ya no se lee.
    $fijaId = $c['cobro_fija_id'] ?? null;
    $fijaId = ($fijaId === null || $fijaId === '') ? 0 : (int)$fijaId;
    foreach ($cuentas as $cta) {
        if ((int)$cta['id'] === $fijaId) {
            return $cta;
        }
    }

    // La elegida ya no esta (pausada o borrada): cae a la principal, que
    // siempre existe. Deterministico a proposito -- si esto devolviera algo
    // distinto en cada llamada, el CBU del chat dejaria de coincidir con el
    // del panel de ganamos, que es justo lo que se quiere evitar.
    return $cuentas[0];
}

/** 'transferencia' (default) o 'hgcash' -- ver metodo_cobro en goldpaw_control.clientes. */
function rl_metodo_cobro(): string
{
    $c = rl_cliente_actual();
    $m = (string)($c['metodo_cobro'] ?? 'transferencia');
    return $m === 'hgcash' ? 'hgcash' : 'transferencia';
}


/* Aca vivia rl_elegir_centavo(), que repartia centavos unicos (1..99) para
   que el importe identificara la recarga. Se saco junto con esa logica: le
   pedia al jugador un numero raro, mucha gente redondeaba igual (y entonces
   no servia), y hoy el pago se reconoce por titular declarado y huella de
   CUIT/CBU -- que funcionan aunque transfiera de mas o de menos.
   La columna `recargas.centavos` sigue existiendo, con NULL en las nuevas,
   para no romper las viejas que si los tienen. */

/** Codigo de referencia corto, legible (sin caracteres ambiguos). */
function rl_referencia(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) {
        $s .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $s;
}

/** Marca vencidas las recargas pendientes que pasaron su vencimiento. */
function rl_vencer(PDO $pdo): int
{
    return (int)$pdo->exec(
        "UPDATE recargas SET estado='vencida'
          WHERE estado='pendiente' AND vence_en < NOW()"
    );
}

/**
 * Estado "efectivo" de una recarga para MOSTRAR: 'vencida' si sigue en
 * 'pendiente' pero ya paso su vence_en (rl_vencer() es perezoso, solo corre
 * en los puntos de entrada de arriba -- una fila puede estar objetivamente
 * vencida sin que la columna `estado` todavia lo diga). En cualquier otro
 * caso, el estado real de la fila.
 *
 * $venceEn sin tipar a proposito: PDO_MYSQL siempre devuelve las columnas
 * DATETIME como string con el fetch mode que usa este proyecto, pero la
 * funcion no confia en eso -- acepta tambien un DateTime (por si algun
 * caller cambia de fetch mode el dia de mañana) y, si el valor no es
 * parseable (string vacio/formato raro), NO asume vencida: devuelve el
 * estado real tal cual. strtotime() devuelve false ante algo invalido, y
 * false < time() da true en PHP -- sin este chequeo explicito, un dato
 * inesperado marcaria la fila como vencida en silencio.
 *
 * OJO: crm_recargas.php necesita la MISMA condicion en SQL (para poder
 * filtrar/contar por tab usando el indice ix_estado_vence sin traer todo a
 * PHP) -- esta funcion es la version canonica en PHP, usada para el
 * ?accion=detalle de una fila puntual. Si esta logica cambia, cambiar
 * tambien la condicion equivalente en crm_recargas.php (documentada ahi).
 */
function rl_estado_efectivo(string $estado, $venceEn): string
{
    if ($estado !== 'pendiente') {
        return $estado;
    }
    if ($venceEn instanceof DateTime) {
        $ts = $venceEn->getTimestamp();
    } else {
        $ts = strtotime((string)$venceEn);
        if ($ts === false) {
            return $estado;
        }
    }
    return $ts < time() ? 'vencida' : $estado;
}


/**
 * Crea una recarga pendiente y devuelve el monto exacto a transferir.
 * Lo llama el chatbot (herramienta crear_recarga).
 */
/**
 * Crea el pedido de recarga y devuelve el monto exacto a transferir.
 *
 * $titular: a nombre de quien esta la cuenta desde la que el jugador va a
 * transferir. Lo declara el mismo al pedir la carga, y es lo que despues
 * permite identificar su comprobante aunque el monto choque con el de otro
 * (ver rl_elegir_recarga). Puede NO ser el jugador -- le puede transferir un
 * familiar. Opcional para no romper a ningun llamador viejo: sin el, esa
 * recarga simplemente no participa del desempate por nombre.
 */
function rl_crear_recarga(PDO $pdo, string $usuario, int $coins, string $titular = '',
                         bool $usuarioConfiable = false): array
{
    $usuario = trim($usuario);
    if ($usuario === '') {
        return ['ok' => false, 'error' => 'Falta el nombre de usuario del juego.'];
    }

    /* BLOQUEADO: se corta ANTES de darle el alias. Es el orden que importa --
       si se cortara despues, el jugador ya transfirio y la plata entro: ahi el
       problema deja de ser un bloqueo y pasa a ser devolverle el dinero a
       alguien a quien no le queremos vender. */
    if (function_exists('vin_bloqueado') && vin_bloqueado($pdo, $usuario)) {
        return ['ok' => false, 'codigo' => 'bloqueado',
                'error' => 'No puedo generar una carga para esta cuenta. '
                         . 'Decile que lo tiene que ver un agente.'];
    }
    // Los MISMOS limites que fichas_pedir_carga(), leidos del mismo lugar: si
    // el chat aceptara un monto que despues la carga rechaza, el jugador
    // transferiria plata por una recarga que no se puede completar.
    $minCarga = function_exists('fichas_limite')
        ? fichas_limite($pdo, 'lim_carga_min', RL_MIN_COINS) : RL_MIN_COINS;
    $maxCarga = function_exists('fichas_limite')
        ? fichas_limite($pdo, 'lim_carga_max', RL_MAX_COINS) : RL_MAX_COINS;
    if ($maxCarga <= 0) { $maxCarga = RL_MAX_COINS; }
    if ($coins < $minCarga || $coins > $maxCarga) {
        return ['ok' => false, 'codigo' => 'monto_fuera_de_rango',
                'minimo' => $minCarga, 'maximo' => $maxCarga,
                'error' => 'La cantidad tiene que estar entre '
                    . number_format($minCarga, 0, ',', '.') . ' y '
                    . number_format($maxCarga, 0, ',', '.') . ' fichas.'];
    }

    // El usuario tiene que ser real. Se da por real si:
    //   - $usuarioConfiable: vino de una SESION verificada (el jugador esta
    //     logueado -> existe, aunque el espejo `usuarios` no lo tenga aun); o
    //   - figura en el espejo `usuarios`; o
    //   - tiene un alta CREADA con ese nombre (`altas.estado='ok'`).
    //
    // El OR con la sesion y con `altas` cubre al jugador RECIEN registrado o
    // logueado cuando el espejo esta ATRASADO O CAIDO (lo pobla sync_usuarios).
    // Sin esto, un jugador logueado no podia recargar hasta la proxima pasada
    // del sync -- y con el sync caido, NUNCA: pedia cargar, el bot fallaba con
    // 'sin_usuario' y (por la regla del prompt) terminaba diciendo "te paso los
    // datos" sin pasar nada, o inventando un CBU. Pasó el 7/9/2026.
    $existe = $usuarioConfiable;
    if (!$existe) {
        $st = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        $existe = (bool)$st->fetchColumn();
    }
    if (!$existe) {
        try {
            $sa = $pdo->prepare("SELECT 1 FROM altas WHERE usuario = ? AND estado = 'ok' LIMIT 1");
            $sa->execute([$usuario]);
            $existe = (bool)$sa->fetchColumn();
        } catch (Throwable $e) {
            // Sin tabla `altas` (setup viejo): queda el chequeo de `usuarios`.
        }
    }
    if (!$existe) {
        // OJO: este `error` el MODELO lo suele mostrar TAL CUAL al jugador (Haiku
        // filtro la version vieja, que estaba escrita como instruccion interna y
        // hasta nombraba la herramienta). Por eso ahora es un mensaje NATURAL,
        // para el jugador, seguro de copiar. La regla de "no inventes el nombre"
        // ya vive en el prompt, no hace falta repetirsela al modelo aca.
        // El mensaje NO nombra al usuario a proposito: el nombre que llego suele
        // ser uno que el modelo invento, y repetirlo ("'jugador123' no existe")
        // solo confunde al jugador.
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' =>
            'Para cargarte las fichas primero tenes que iniciar sesion en la '
            . 'pagina, con el boton de acceso. Si todavia no tenes cuenta, '
            . 'decime y te la creo en el momento.'];
    }

    $montoBase = (int)round($coins / rl_coins_por_peso());   // pesos enteros, a la tasa del cliente

    $pdo->beginTransaction();
    try {
        rl_vencer($pdo);

        /* MISMO JUGADOR, MISMO MONTO: se reusa la pendiente que ya tiene.
           En el chat de holaJorge443 (12/9) el jugador pidio $2000 cinco veces
           seguidas -- no sabia si habia entrado, porque el bot no le leia el
           comprobante -- y cada pedido abria una recarga nueva. Con cinco filas
           de $2000 abiertas llego al tope de pendientes y no pudo pedir mas, y
           encima cualquier OTRO jugador que quisiera cargar $2000 se comia el
           pedido de titular por un choque que era falso.
           Pedir dos veces lo mismo no es pedir dos cargas: es la misma persona
           preguntando de nuevo. Se le devuelve la que ya tenia, con el
           vencimiento renovado, y sigue siendo UNA sola transferencia. */
        $reuso = null;
        try {
            $q = $pdo->prepare(
                "SELECT * FROM recargas
                  WHERE usuario = ? AND estado = 'pendiente' AND monto_base = ?
                  ORDER BY id DESC LIMIT 1"
            );
            $q->execute([$usuario, $montoBase]);
            $reuso = $q->fetch() ?: null;
        } catch (Throwable $e) { $reuso = null; }
        if ($reuso !== null) {
            $pdo->prepare(
                "UPDATE recargas SET vence_en = DATE_ADD(NOW(), INTERVAL "
                    . RL_VENCIMIENTO_MIN . " MINUTE) WHERE referencia = ?"
            )->execute([$reuso['referencia']]);
            /* Si recien ahora dice de quien es la cuenta, se guarda: es el dato
               que desempata (migracion 45; si no corrio, se sigue sin el). */
            if (trim($titular) !== '' && trim((string)($reuso['titular_declarado'] ?? '')) === '') {
                try {
                    $pdo->prepare("UPDATE recargas SET titular_declarado = ? WHERE referencia = ?")
                        ->execute([mb_substr(trim($titular), 0, 120), $reuso['referencia']]);
                } catch (Throwable $e) { /* sin migracion 45: se pierde el dato, no la recarga */ }
            }
        }

        // Tope de pendientes por usuario: un jugador con veinte recargas
        // abiertas del mismo monto hace imposible saber cual pago.
        $c = $pdo->prepare("SELECT COUNT(*) FROM recargas WHERE usuario=? AND estado='pendiente'");
        $c->execute([$usuario]);
        if ($reuso === null && (int)$c->fetchColumn() >= RL_MAX_PENDIENTES_USUARIO) {
            $pdo->rollBack();
            return ['ok' => false, 'error' =>
                'Ya tenes varias recargas pendientes. Termina o espera que venzan antes de crear otra.'];
        }

        /* SE PIDE EL MONTO REDONDO, el que el jugador dijo. Nada de centavos.
           Antes se le sumaban centavos unicos (1000 -> 1000.47) para poder
           reconocer el pago por el importe. Se saco por dos motivos:
             1. Le pedia al jugador un numero raro. Mucha gente redondea igual,
                y entonces el identificador no servia y encima sembraba dudas
                ("¿por que 47 centavos de mas?").
             2. Ya hay con que reconocer el pago sin eso: el titular declarado
                y la huella de CUIT/CBU (ver rl_elegir_recarga), que ademas
                funcionan aunque el jugador transfiera de mas o de menos.
           `centavos` queda en NULL: la columna sigue existiendo para no
           romper las recargas viejas que si los tienen. */
        $cent = null;
        $montoPedido = $montoBase;

        /* PEDIR EL TITULAR SOLO CUANDO HACE FALTA.
           Sin centavos, el importe alcanza para reconocer el pago mientras
           sea el unico de ese monto esperando. Si OTRO jugador ya tiene una
           pendiente por lo mismo, van a entrar dos transferencias iguales y
           el importe deja de distinguirlas: ahi si hace falta el titular.

           Se pregunta solo en ese caso, y no siempre, porque en el flujo real
           el jugador dice "me cargas?" y espera el alias -- meterle una
           pregunta antes es friccion que el empleado humano no hace. El
           sistema sabe de antemano cuando va a haber ambiguedad, asi que
           pregunta unicamente entonces.

           Se compara contra recargas de OTROS usuarios: dos pedidos del mismo
           jugador por el mismo monto no son ambiguos para lo que importa
           (a quien acreditarle). */
        if ($reuso === null && trim($titular) === '') {
            $choque = $pdo->prepare(
                "SELECT COUNT(*) FROM recargas
                  WHERE estado = 'pendiente' AND monto_base = ? AND usuario <> ?"
            );
            $choque->execute([$montoBase, $usuario]);
            if ((int)$choque->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'codigo' => 'falta_titular',
                        'error' => 'Justo hay otra carga por el mismo monto esperando. '
                            . 'Preguntale a nombre de quien esta la cuenta desde la que va a '
                            . 'transferir y volve a intentarlo con ese dato.'];
            }
        }

        if ($reuso !== null) {
            // Ya existe: no se inserta nada, se sigue con su referencia.
            $ref = (string)$reuso['referencia'];
            /* Y con SUS coins: son los que se le van a acreditar cuando entre el
               pago. Si algun dia cambia la tasa (rl_coins_por_peso) entre el primer pedido
               y el segundo, devolver los recalculados le prometeria al jugador
               una cifra distinta de la que va a recibir. */
            $coins = (int)($reuso['coins'] ?? $coins);
        } else {
            // Insertar, reintentando si la referencia aleatoria choca (muy raro).
            // titular_declarado es de la migracion 45: si todavia no corrio, se
            // inserta sin esa columna y la recarga funciona igual (solo pierde el
            // desempate por nombre). Mismo criterio de degradado que alta_encolar().
            $titular = mb_substr(trim($titular), 0, 120);
            $conTitular = true;
            $ins = $pdo->prepare(
                "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, centavos,
                                       titular_declarado, estado, creada_en, vence_en)
                 VALUES (?,?,?,?,?,?,?, 'pendiente', NOW(), DATE_ADD(NOW(), INTERVAL " . RL_VENCIMIENTO_MIN . " MINUTE))"
            );
            $insViejo = null;
            $ref = '';
            for ($intento = 0; $intento < 5; $intento++) {
                $ref = rl_referencia();
                try {
                    if ($conTitular) {
                        $ins->execute([$ref, $usuario, $coins, $montoBase, $montoPedido, $cent,
                                       $titular !== '' ? $titular : null]);
                    } else {
                        $insViejo->execute([$ref, $usuario, $coins, $montoBase, $montoPedido, $cent]);
                    }
                    break;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) == 1062 && $intento < 4) {
                        continue;   // referencia repetida, probamos otra
                    }
                    if ($conTitular && ($e->errorInfo[1] ?? 0) == 1054) {
                        // "Unknown column": falta la migracion 45.
                        $conTitular = false;
                        $insViejo = $pdo->prepare(
                            "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                                                   centavos, estado, creada_en, vence_en)
                             VALUES (?,?,?,?,?,?, 'pendiente', NOW(), DATE_ADD(NOW(), INTERVAL " . RL_VENCIMIENTO_MIN . " MINUTE))"
                        );
                        $intento--;   // este intento no cuenta: se reintenta con la query vieja
                        continue;
                    }
                    throw $e;
                }
            }
        }

        $pdo->commit();

        /* ---- HG Cash: el pago pasa por la pasarela ----
           Con HG prendido, en vez de "transferi a NUESTRO alias y que el
           colector de mails lo matchee", se crea un checkout de HG: el
           jugador paga en una pagina hosteada (o transfiere al CVU de HG) y
           HG matchea solo por monto+DNI. La acreditacion llega por webhook.

           FAIL-OPEN a proposito, en dos niveles: si el cliente eligio HG pero
           no tiene credenciales propias validas, cae al modo "casa" (viejo,
           inactivo salvo que alguien lo reactive a mano en panel.html); si
           ninguno de los dos anda, cae al flujo legacy de transferencia con
           centavos unicos. Una caida de la pasarela no puede dejar a los
           jugadores sin poder cargar. */
        $rHG = null;
        /* Si se reuso una recarga que ya tenia checkout de HG, el link sigue
           siendo el mismo: crear otro seria una segunda intencion de pago. */
        if ($reuso !== null && trim((string)($reuso['hg_url'] ?? '')) !== '') {
            $rHG = ['id'  => (string)($reuso['hg_checkout_id'] ?? ''),
                    'url' => (string)$reuso['hg_url'],
                    'alias' => '', 'cvu' => '', 'titular' => ''];
        }
        if (!$rHG && is_file(__DIR__ . '/hgcash_lib.php') && rl_metodo_cobro() === 'hgcash') {
            require_once __DIR__ . '/hgcash_lib.php';

            // Intento 1: HG Cash con las credenciales PROPIAS de este cliente
            // -- el modo nuevo, el que corresponde cuando eligio 'hgcash'.
            if (function_exists('hg_propio_activo') && hg_propio_activo()) {
                $chk = hg_propio_checkout_crear($montoPedido, $ref,
                    ['usuario' => $usuario, 'referencia' => $ref]);
                if (!empty($chk['ok'])) {
                    $rHG = $chk;
                }
            }

            // Intento 2 (compatibilidad, modo "casa"): solo si el cliente NO
            // tiene credenciales propias configuradas. Nadie deberia caer
            // aca hoy -- el selector del CRM ya no ofrece este modo -- pero
            // si algun dia se reactiva a mano, sigue funcionando igual que
            // siempre (con su libro mayor de comision).
            if (!$rHG && function_exists('hg_activo') && hg_activo()) {
                $chk = hg_checkout_crear($montoPedido, $ref,
                    ['usuario' => $usuario, 'referencia' => $ref]);
                if (!empty($chk['ok'])) {
                    $cli = hg_cliente_actual();
                    if ($cli) {
                        hg_ledger_alta('deposito', $cli, $usuario, $ref,
                            $chk['id'], (float)$montoPedido, $chk['url']);
                    }
                    $rHG = $chk;
                }
            }

            if ($rHG) {
                $pdo->prepare(
                    "UPDATE recargas SET metodo='hgcash', hg_checkout_id=?, hg_url=?
                      WHERE referencia = ?"
                )->execute([$rHG['id'], $rHG['url'], $ref]);
            }
        }

        $r = [
            'ok'           => true,
            'referencia'   => $ref,
            'usuario'      => $usuario,
            'coins'        => $coins,
            'monto_pedido' => number_format($montoPedido, 2, '.', ''),
            'vence_min'    => RL_VENCIMIENTO_MIN,
        ];
        if ($rHG) {
            // Con HG, el link ES la instruccion de pago. El CVU/alias que se
            // muestra es el de HG (por si prefiere transferir a mano), nunca
            // el de transferencia directa: la plata tiene que entrar por la
            // pasarela.
            $r['metodo']    = 'hgcash';
            $r['link_pago'] = $rHG['url'];
            $cta = rl_cuenta_cobro();
            $r['alias']     = $rHG['alias'] !== '' ? $rHG['alias'] : $cta['alias'];
            $r['cbu']       = $rHG['cvu'] !== '' ? $rHG['cvu'] : $cta['cbu'];
            $r['titular']   = $rHG['titular'] !== '' ? $rHG['titular'] : $cta['titular'];
        } else {
            // Transferencia directa: si el cliente cargo mas de una cuenta,
            // rl_cuenta_elegida() respeta su cobro_modo (rotar al azar, o
            // usar siempre la misma) -- nunca se le muestra mas de una
            // opcion a la vez al jugador, elija lo que elija el cliente.
            $cta = rl_cuenta_elegida();
            $r['metodo']  = 'transferencia';
            $r['alias']   = $cta['alias'];
            $r['cbu']     = $cta['cbu'];
            $r['titular'] = $cta['titular'];
        }
        // Un dato vacio no viaja: si viajara, el modelo del chatbot lo
        // repetiria tal cual ("alias: ") o, peor, inventaria uno. Aplica a
        // los dos, porque la billetera del panel de ganamos puede ser SOLO
        // alias (lo normal en Mercado Pago) o SOLO CBU.
        if (($r['alias'] ?? '') === '') { unset($r['alias']); }
        if (($r['cbu']   ?? '') === '') { unset($r['cbu']); }
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rl_crear_recarga: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo crear la recarga, intenta de nuevo.'];
    }
}


/**
 * Consulta el estado de una recarga por referencia o por usuario.
 * Lo llama el chatbot (herramienta consultar_recarga).
 */
function rl_consultar(PDO $pdo, string $refOUsuario): array
{
    rl_vencer($pdo);
    $key = trim($refOUsuario);
    if ($key === '') {
        return ['ok' => false, 'error' => 'Decime la referencia o tu nombre de usuario.'];
    }

    $st = $pdo->prepare("SELECT * FROM recargas WHERE referencia = ? LIMIT 1");
    $st->execute([strtoupper($key)]);
    $r = $st->fetch();
    if (!$r) {
        $st = $pdo->prepare("SELECT * FROM recargas WHERE usuario = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$key]);
        $r = $st->fetch();
    }
    if (!$r) {
        return ['ok' => true, 'encontrada' => false,
                'mensaje' => 'No encontre ninguna recarga con ese dato.'];
    }

    $saldo = null;
    if ($r['estado'] === 'acreditada') {
        $b = $pdo->prepare("SELECT coins FROM usuarios WHERE username = ?");
        $b->execute([$r['usuario']]);
        $saldo = $b->fetchColumn();
    }

    $out = [
        'ok'           => true,
        'encontrada'   => true,
        'referencia'   => $r['referencia'],
        'usuario'      => $r['usuario'],
        'coins'        => (int)$r['coins'],
        'monto_pedido' => $r['monto_pedido'],
        'estado'       => $r['estado'],   // pendiente|acreditada|vencida|cancelada|revision
        'saldo_actual' => $saldo !== false && $saldo !== null ? (int)$saldo : null,
    ];

    /* Si sigue pendiente, mirar si la plata YA ENTRO y quedo trabada en
       revision. Va aca y no en una herramienta nueva a proposito: es
       exactamente el momento en que el bot pregunta "¿llego lo mio?", asi que
       el dato le llega solo, sin que tenga que acordarse de ir a buscarlo. */
    if ($r['estado'] === 'pendiente') {
        $trab = rl_pago_trabado($pdo, (string)$r['usuario'], (float)$r['monto_pedido']);
        if (!empty($trab['hay'])) {
            $out['pago_trabado'] = $trab;
        }
    }

    return $out;
}


/**
 * ¿HAY PLATA DE ESTE JUGADOR TRABADA EN REVISION?
 *
 * EL PROBLEMA QUE RESUELVE: cuando el matcher no puede decidir a quien
 * acreditarle una transferencia, la deja en `pagos` con estado 'revision'
 * esperando a una persona. La plata ESTA. Pero ninguna herramienta del chatbot
 * miraba esa tabla -- consultar_recarga lee `recargas`, o sea lo que el jugador
 * PIDIO, nunca lo que LLEGO -- asi que el bot le decia "todavia no me figura" a
 * alguien cuyo pago ya habia entrado. No mentia: estaba ciego.
 *
 * Para el jugador son dos situaciones opuestas:
 *   - no llego     -> hay que esperar al banco, no hay nada que hacer;
 *   - llego trabado -> la plata ya esta adentro y un agente la libera en
 *                      segundos, pero alguien tiene que enterarse.
 * Distinguirlas es lo que convierte "ya le avise a un agente" (repetido diez
 * veces) en una respuesta que sirve.
 *
 * QUE NO HACE: no acredita ni decide nada. Solo MIRA y reporta. El unico que
 * casa un pago sigue siendo rl_matchear_y_acreditar, con sus reglas, que
 * rl_declarar_pago ya reintenta cuando el jugador declara el titular. Si el
 * matcher no pudo, esto no lo va a "convencer" -- lo hace visible, que es otra
 * cosa. La regla de oro no se toca: nunca adivina.
 *
 * Devuelve ['hay' => bool, 'cantidad' => int, 'monto' => float|null,
 *           'id_unico' => string, 'titular' => string, 'desde_min' => int|null]
 */
function rl_pago_trabado(PDO $pdo, string $usuario, ?float $monto = null): array
{
    $vacio = ['hay' => false, 'cantidad' => 0, 'monto' => null,
              'id_unico' => '', 'titular' => '', 'desde_min' => null];
    $usuario = trim($usuario);
    if ($usuario === '') { return $vacio; }

    try {
        /* El monto de referencia: el que pidio y todavia espera. Sin recarga
           pendiente no hay con que cruzar -- un pago en revision suelto puede
           ser de cualquiera, y adjudicarselo al que pregunta seria justo lo que
           el matcher se niega a hacer. */
        if ($monto === null) {
            $st = $pdo->prepare(
                "SELECT monto_pedido FROM recargas
                  WHERE usuario = ? AND estado = 'pendiente'
                  ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$usuario]);
            $v = $st->fetchColumn();
            if ($v === false || $v === null) { return $vacio; }
            $monto = (float)$v;
        }

        $pg = $pdo->prepare(
            "SELECT id_unico, monto, remitente, capturado_en,
                    TIMESTAMPDIFF(MINUTE, capturado_en, NOW()) AS desde_min
               FROM pagos
              WHERE estado = 'revision'
                AND (ROUND(monto*100) = ROUND(? * 100) OR FLOOR(monto) = FLOOR(?))
              ORDER BY capturado_en DESC LIMIT 5"
        );
        $pg->execute([$monto, $monto]);
        $filas = $pg->fetchAll();
        if (!$filas) { return $vacio; }

        $p = $filas[0];
        return [
            'hay'       => true,
            'cantidad'  => count($filas),
            'monto'     => (float)$p['monto'],
            'id_unico'  => (string)$p['id_unico'],
            'titular'   => trim((string)($p['remitente'] ?? '')),
            'desde_min' => $p['desde_min'] !== null ? (int)$p['desde_min'] : null,
        ];
    } catch (Throwable $e) {
        error_log('rl_pago_trabado: ' . $e->getMessage());
        return $vacio;
    }
}


/**
 * Registra un pago capturado del mail y trata de acreditarlo.
 * Lo llama pagos.php (que a su vez lo alimenta el colector).
 */
function rl_registrar_pago(PDO $pdo, array $p): array
{
    $idUnico = trim((string)($p['id_unico'] ?? $p['nro_transaccion'] ?? ''));
    if ($idUnico === '') {
        return ['ok' => false, 'error' => 'pago sin id_unico'];
    }
    $monto = isset($p['monto']) && $p['monto'] !== null && $p['monto'] !== ''
        ? (float)$p['monto'] : null;

    // Insertar el pago. UNIQUE(id_unico) => si ya estaba, no se procesa de nuevo.
    try {
        $ins = $pdo->prepare(
            "INSERT INTO pagos (id_unico, monto, remitente, cuit, cbu_origen, nro_transaccion,
                                entidad, fecha_operacion, dkim_pass, mail_de, estado, capturado_en)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'pendiente', NOW())"
        );
        $ins->execute([
            $idUnico, $monto, $p['remitente'] ?? null, $p['cuit'] ?? null,
            $p['cbu_origen'] ?? null, $p['nro_transaccion'] ?? null, $p['entidad'] ?? null,
            $p['fecha_operacion'] ?? null, !empty($p['dkim_pass']) ? 1 : 0, $p['mail_de'] ?? null,
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) {
            return ['ok' => true, 'nuevo' => false, 'resultado' => 'ya_visto'];
        }
        error_log('rl_registrar_pago insert: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'no se pudo guardar el pago'];
    }

    if ($monto === null) {
        return ['ok' => true, 'nuevo' => true, 'resultado' => 'sin_monto'];
    }

    $res = rl_matchear_y_acreditar($pdo, $idUnico, $monto);

    /* Aviso por Telegram de la transferencia que acaba de entrar.

       UN SOLO MENSAJE POR PAGO. Cual de los dos interruptores lo gobierna
       depende de COMO TERMINO, no de que haya entrado plata:

         - quedo sin resolver -> 'revision'. Es urgente y pide accion: el
           jugador ya transfirio y espera, y nadie se entera hasta que alguien
           abra el CRM.
         - se acredito sola   -> 'pago'. Es informativo, para mirar el negocio
           de reojo sin entrar al CRM.

       Con los dos prendidos igual llega uno solo: si se prendieran por separado
       sobre el mismo hecho, cada transferencia problematica avisaria dos veces
       -- y el aviso que importa se perderia entre los repetidos.

       Va DESPUES del commit (el comprobante ya esta guardado cuando suena) y
       con clave, para que un reintento del colector no vuelva a avisar. */
    if (function_exists('tg_evento')) {
        $tit  = trim((string)($p['remitente'] ?? ''));
        $quien = $tit !== '' ? $tit : '(el banco no informó el titular)';
        $plata = '$' . number_format((float)$monto, 2, ',', '.');

        /* EL AVISO DE 'revision' YA NO SALE ACA. Lo manda el barrido de
           rl_avisar_revision_vieja(), unos minutos despues.

           Por que (3/9/2026): 'revision' significa "el matcher del camino B no
           encontro a quien acreditarsela", y eso es CIERTO para toda carga
           pedida con el boton "Depositos" -- ese camino no crea fila en
           `recargas`, asi que nunca puede casar de este lado. Un minuto mas
           tarde `aprobar_cargas.py` la cruza contra la solicitud del panel, la
           aprueba y marca el pago 'usado'.

           O sea que el aviso era correcto en el instante en que salia y falso
           sesenta segundos despues: una falsa alarma por cada carga del camino
           A. Y eso no es ruido inofensivo -- entrena a ignorar el aviso, que
           es lo que lo rompe el dia que sea de verdad.

           La condicion que importa no es "no casó", es "SIGUE sin resolverse
           despues de un rato". Eso solo se puede saber mas tarde. */
        if (($res['resultado'] ?? '') === 'acreditada') {
            /* SIN clave: este aviso sale SIEMPRE, sin tiempo de espera. Es un
               hecho puntual y cada transferencia es una distinta -- el freno
               esta pensado para los avisos de "algo sigue roto", que un cron
               vuelve a detectar cada minuto.
               No hay riesgo de repetido: si el colector reintenta el POST del
               mismo comprobante, rl_registrar_pago corta antes por 'ya_visto'
               (el UNIQUE de id_unico) y nunca llega hasta aca. */
            tg_evento($pdo, 'pago', '💰 Entró una transferencia', [
                'Monto'    => $plata,
                'De'       => $quien,
                'Jugador'  => (string)($res['usuario'] ?? ''),
                'Fichas'   => isset($res['coins'])
                              ? number_format((int)$res['coins'], 0, ',', '.') . ' acreditadas solas'
                              : '',
            ]);
        }
    }

    return array_merge(['ok' => true, 'nuevo' => true], $res);
}


/**
 * Avisa por Telegram las transferencias que SIGUEN sin resolverse.
 *
 * Es la otra mitad del cambio explicado arriba, en rl_registrar_pago(): el
 * aviso no puede salir cuando entra el pago, porque en ese momento todavia no
 * se sabe si lo va a levantar el camino A. Recien despues de unos minutos
 * "sin resolver" significa algo.
 *
 * QUIEN LO LLAMA: aprobar_cargas.py, al terminar cada pasada. No es un cron
 * nuevo -- es el mismo worker que, si esa transferencia era de una carga
 * pedida desde el panel, acaba de aprobarla y marcarla 'usado'. Llamarlo
 * justo despues es lo mas ajustado que se puede: para cuando corre, el camino
 * A ya tomo lo suyo.
 *
 * LA ESPERA SE MIDE EN LA BASE, no en PHP. `capturado_en` y NOW() salen del
 * mismo reloj; mezclar timestamps de la base con time() de PHP ya causo dos
 * bugs distintos en este proyecto.
 *
 * El aviso va con clave por pago, asi que tg_avisar_una_vez() no lo repite en
 * cada pasada. Que vuelva a sonar cuando pase la ventana es deseable: sigue
 * habiendo plata de alguien sin acreditar.
 *
 * Devuelve cuantos aviso.
 */
function rl_avisar_revision_vieja(PDO $pdo, int $minutos = 3): int
{
    if (!function_exists('tg_evento')) { return 0; }
    if ($minutos < 1) { $minutos = 1; }

    try {
        $st = $pdo->prepare(
            "SELECT id_unico, monto, remitente
               FROM pagos
              WHERE estado = 'revision'
                AND capturado_en <= NOW() - INTERVAL ? MINUTE
              ORDER BY capturado_en ASC
              LIMIT 20"
        );
        $st->execute([$minutos]);
        $filas = $st->fetchAll();
    } catch (PDOException $e) {
        error_log('rl_avisar_revision_vieja: ' . $e->getMessage());
        return 0;
    }

    $n = 0;
    foreach ($filas as $f) {
        $tit = trim((string)($f['remitente'] ?? ''));
        tg_evento($pdo, 'revision', '⚠️ Hay una transferencia sin resolver', [
            'Monto'     => '$' . number_format((float)$f['monto'], 2, ',', '.'),
            'De'        => $tit !== '' ? $tit : '(el banco no informó el titular)',
            'Hace'      => 'más de ' . $minutos . ' min sin poder asignarla',
            'Qué hacer' => 'CRM → Comprobantes, para asignarla al jugador.',
        ], 'pago_revision:' . (string)$f['id_unico']);
        $n++;
    }
    return $n;
}


/**
 * Cierra la recarga + suma coins + marca el pago usado. NO abre ni cierra
 * transaccion (el caller ya tiene una abierta) y NO notifica (el caller lo
 * hace despues del commit, por la misma razon que ya explicaba
 * rl_matchear_y_acreditar: si notificara antes y el commit fallara, quedaria
 * avisada una recarga que nunca se acredito).
 *
 * $operador: username de quien la disparo a mano (Fase A, asignacion manual
 * de comprobantes), o null si la caso el matcher automatico solo. Se guarda
 * en pagos.asignado_por/asignado_en (Fase 0.5) — con null quedan en NULL,
 * que es su valor por default, asi que el camino automatico no cambia nada
 * observable.
 */
/**
 * ¿ESTA ES LA PRIMERA CARGA DEL JUGADOR? 1 si, 0 no, null si no se pudo saber.
 *
 * EL BUG QUE ARREGLA (15/09/2026, caso real). Los tres caminos de acreditacion
 * contaban lo mismo:
 *
 *     SELECT COUNT(*) FROM recargas WHERE usuario = ? AND estado='acreditada'
 *
 * o sea SOLO el camino B (la transferencia que toma el chatbot). Un jugador
 * que ya habia cargado por el boton «Depositos» de adentro del juego, o al que
 * un agente le cargo saldo a mano desde el CRM, seguia teniendo cero filas en
 * `recargas`: su SEGUNDA carga se contaba como la primera y se llevaba el bono
 * de bienvenida otra vez. Paso el 15/09: cargo 1.280 y cobro 640 de bono que
 * no le tocaban, y hubo que sacarselos a mano.
 *
 * Es exactamente el error que CLAUDE.md documenta para Publicidad y Finanzas
 * -- «hay UNA definicion de una carga» -- y que Publicidad ya habia corregido
 * por su lado (publicidad_lib.php calcula la primera con las dos vias sobre la
 * mesa y dejo de leer `recargas.es_primera`). Faltaba el bono, que es el unico
 * de los tres que PAGA por esa respuesta.
 *
 * LAS DOS PREGUNTAS SON:
 *   1. ¿tiene alguna recarga acreditada? (camino B: chatbot + HG Cash)
 *   2. ¿tiene algun movimiento de SALDO positivo? Ese tipo solo lo escriben
 *      dos lugares y los dos son plata de verdad entrando al juego:
 *      `origen='peticion'` (camino A, el boton Depositos) y `origen='crm'`
 *      (un agente cargando a mano, que en la practica es como se resuelve una
 *      transferencia que el matcher no pudo casar). Los regalos y los bonos
 *      NO son tipo 'saldo' -- van como 'ficha' o 'bono' -- asi que regalarle
 *      fichas a alguien no le quema el bono de bienvenida.
 *
 * ANTE LA DUDA, NO ES LA PRIMERA. Si la consulta falla se devuelve null y el
 * caller no paga: deberle un bono se arregla cargandolo desde el CRM, pagarlo
 * dos veces no se arregla con nada.
 *
 * SE LLAMA ANTES de marcar acreditada la recarga en curso -- si no, se contaria
 * a si misma. Los tres callers ya lo hacen asi.
 */
function rl_es_primera_carga(PDO $pdo, string $usuario): ?int
{
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM recargas WHERE usuario = ? AND estado = 'acreditada' LIMIT 1"
        );
        $st->execute([$usuario]);
        if ($st->fetchColumn()) { return 0; }

        /* Segunda consulta y no un UNION: `recargas.usuario` y
           `movimientos.usuario` tienen collations distintas (ver CLAUDE.md), y
           comparar cada una contra un parametro de PHP no depende de ninguna. */
        $st = $pdo->prepare(
            "SELECT 1 FROM movimientos
              WHERE usuario = ? AND tipo = 'saldo' AND monto > 0 LIMIT 1"
        );
        $st->execute([$usuario]);
        if ($st->fetchColumn()) { return 0; }

        return 1;
    } catch (Throwable $e) {
        error_log('rl_es_primera_carga (' . $usuario . '): ' . $e->getMessage());
        return null;
    }
}

function rl_acreditar(PDO $pdo, array &$recarga, string $idUnico, string $conf,
                       ?string $operador = null, ?string $confianza = null): void
{
    // Se resuelve ANTES del UPDATE de abajo: en ese momento la fila de esta
    // recarga todavia esta 'pendiente', asi que no se cuenta a si misma.
    // Se guarda en la fila (no se recalcula despues con MIN/subquery) para
    // que el modulo de Publicidad haga SUM/COUNT simples sobre es_primera en
    // vez de repetir este calculo cada vez que el operador abre el reporte.
    //
    // ACA SE CONTABAN SOLO LAS `recargas`, y por eso un jugador que habia
    // empezado por el boton «Depositos» cobraba el bono de bienvenida en su
    // segunda carga. El conteo vive ahora en rl_es_primera_carga(), que mira
    // los dos caminos -- ver su docblock.
    $esPrimera = rl_es_primera_carga($pdo, (string)$recarga['usuario']);

    // $confianza viene explicito del que llama, no deducido de $conf. Se
    // intento parsear el prefijo del texto y quedaba NULL justo en el camino
    // mas comun ("monto exacto", que no lleva prefijo): deducir semantica de
    // un mensaje pensado para leer es fragil, y esto despues se filtra.
    $confianza = in_array($confianza, ['alta', 'media', 'manual'], true) ? $confianza : null;
    // Migracion 45: si todavia no corrio, se acredita igual sin la
    // trazabilidad -- una recarga no puede quedar sin acreditar por una
    // columna que falta.
    try {
        $pdo->prepare(
            "UPDATE recargas SET estado='acreditada', pago_id=?, acreditada_en=NOW(), mensaje=?,
                    es_primera=?, match_confianza=?, match_motivo=?
              WHERE id=?"
        )->execute([$idUnico, $conf, $esPrimera, $confianza,
                    mb_substr($conf, 0, 200), $recarga['id']]);
    } catch (Throwable $e) {
        $pdo->prepare(
            "UPDATE recargas SET estado='acreditada', pago_id=?, acreditada_en=NOW(), mensaje=?, es_primera=?
              WHERE id=?"
        )->execute([$idUnico, $conf, $esPrimera, $recarga['id']]);
    }

    $pdo->prepare(
        "UPDATE usuarios SET coins = coins + ? WHERE username = ?"
    )->execute([(int)$recarga['coins'], $recarga['usuario']]);

    /* Fila en `movimientos`: sin esto, la ficha del jugador en el CRM MIENTE.
       El agente abre un chat y ve el historial vacio de alguien que viene
       depositando hace meses, porque `movimientos` solo tenia las cargas
       hechas a mano desde el CRM, la ruleta y el debito al pasar fichas al
       juego -- todo menos el evento mas importante, que es este.
       Va con el mismo signo y unidad que el resto (fichas acreditadas). */
    try {
        $pdo->prepare(
            "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
             VALUES (?, 'ficha', ?, ?, 'recarga')"
        )->execute([
            mb_substr((string)$recarga['usuario'], 0, 50),
            (int)$recarga['coins'],
            'Recarga ' . (string)$recarga['referencia'] . ' acreditada',
        ]);
    } catch (Throwable $e) {
        // La recarga YA se acredito arriba. Que falte la linea del historial
        // es molesto; que se caiga la acreditacion por eso, inaceptable.
        error_log('rl_acreditar: no pude registrar el movimiento: ' . $e->getMessage());
    }

    // El jugador acaba de aparecer: cuenta como actividad (ver actividad_lib).
    if (function_exists('actividad_marcar')) {
        actividad_marcar($pdo, (string)$recarga['usuario']);
    }

    $pdo->prepare(
        "UPDATE pagos
            SET estado='usado', recarga_id=?, asignado_por=?,
                asignado_en=IF(? IS NOT NULL, NOW(), NULL)
          WHERE id_unico=?"
    )->execute([$recarga['id'], $operador, $operador, $idUnico]);

    // Aprender desde que cuenta paga este jugador. Va DESPUES de acreditar y
    // en el mismo lugar para los dos caminos (automatico y manual desde el
    // CRM). El manual es el que mas rinde: cada comprobante que el operador
    // resuelve a mano hoy es uno que se resuelve solo la proxima vez.
    try {
        $pg = $pdo->prepare("SELECT cuit, cbu_origen, remitente FROM pagos WHERE id_unico = ? LIMIT 1");
        $pg->execute([$idUnico]);
        if ($datosPago = $pg->fetch()) {
            rl_aprender_huella($pdo, (string)$recarga['usuario'], $datosPago);
        }
    } catch (Throwable $e) {
        error_log('rl_acreditar: no pude aprender la huella: ' . $e->getMessage());
    }

    // Si el jugador tiene un bono de fichas/porcentaje pendiente (prometido
    // por notificacion), se aplica ahora. crm_cargar() adentro abre y comitea
    // su propia transaccion -- mismo patron ya usado en ruleta.php al
    // reclamar premio, no rompe nada nuevo. Nunca lanza: un problema ahi no
    // puede hacer que esta recarga (ya efectivamente acreditada arriba)
    // parezca fallida.
    $bonoPrometido = 0;
    if (function_exists('crmnotif_bono_aplicar_en_recarga')) {
        // Devuelve el monto acreditado: se suma abajo a $recarga['bono'] para
        // que rl_cargar_al_juego_auto lo deposite EN EL JUEGO junto con las
        // fichas, igual que el de bienvenida. (El deposito debita el contador,
        // asi que no se cobra doble.)
        $bonoPrometido = crmnotif_bono_aplicar_en_recarga($pdo, (string)$recarga['usuario'], (int)$recarga['id'], (int)$recarga['coins']);
    }

    /* Bono de bienvenida (landing bono.html y las landings del CRM): en la
       PRIMERA recarga acreditada de un jugador que entro por una landing con
       bono, se le suma el % prometido en BONOS. La logica vive en
       rl_bono_bienvenida_aplicar() porque este YA NO es el unico lugar que
       acredita plata del camino B: rl_acreditar_directo() (Comprobantes a
       mano) y hg_webhook.php (HG Cash) acreditan sin pasar por aca, y se
       salteaban el bono -- la primera carga de un jugador de landing resuelta
       por esos caminos no daba bono nunca, y una recarga posterior lo cobraba
       de mas o sobre el monto equivocado.

       Descansa en $esPrimera, que ya se calculo ARRIBA, antes de marcar esta
       recarga como acreditada -- por eso vale "cero acreditadas previas". Si
       ese calculo fallo ($esPrimera === null) NO se acredita: mejor deberle un
       bono a alguien (se carga a mano desde el CRM) que regalarlo dos veces.
       El candado de una-sola-vez-por-jugador esta adentro del helper, y es lo
       que frena el doble pago cuando dos acreditaciones del mismo usuario
       calculan "primera" casi a la vez, o cuando la primera entro por otro
       camino. Nunca lanza: la recarga YA quedo acreditada arriba.

       Limite conocido: si la primera carga entrara por el camino A (el boton
       "Depositos" DENTRO del juego), esto no corre -- ese camino no crea filas
       en `recargas` ni pasa por aca; el bono sale recien con la primera
       carga del camino B (y el candado evita que salga dos veces). */
    $bono = 0;
    if ($esPrimera === 1) {
        $bono = rl_bono_bienvenida_aplicar($pdo, (string)$recarga['usuario'], (int)$recarga['coins']);
    }

    /* Referidos: FUERA del gate es_primera a proposito. Si la primera carga
       del amigo entro por el camino A (boton Depositos del juego, que no
       pasa por aca), con el gate el referidor no cobraba NUNCA; asi cobra en
       la primera carga por transferencia. Es seguro sin el gate: el candado
       UNIQUE de `referidos` garantiza UN pago por amigo, y sin ref_codigo en
       el alta la funcion devuelve 0 en un SELECT. Si pago, el bono del
       REFERIDOR viaja en $recarga para que rl_cargar_al_juego_auto lo
       deposite AL JUEGO despues del commit -- antes moria en usuarios.bonus
       y en la plataforma no aparecia nunca. */
    if (function_exists('ref_pagar_por_primera_carga')) {
        $refQuien = null;
        $refMonto = ref_pagar_por_primera_carga($pdo, (string)$recarga['usuario'], $refQuien);
        if ($refMonto > 0 && $refQuien !== null && $refQuien !== '') {
            $recarga['ref_pago'] = ['usuario' => $refQuien, 'monto' => $refMonto];
        }
    }

    // es_primera calculado arriba viaja al caller a traves de $recarga -- lo
    // necesita rl_reportar_purchase(), que corre DESPUES del commit (ver esa
    // funcion para el porque). El bono viaja igual: rl_cargar_al_juego_auto
    // lo suma al deposito para que llegue AL JUEGO y no quede solo como un
    // numero en el chat (el bug del "transferi 3000 con bono 50% y en la
    // plataforma me aparecieron 3000").
    $recarga['es_primera'] = $esPrimera;
    // Bienvenida + prometido (fidelizacion / promesa manual del CRM): los dos
    // viajan juntos al deposito. En la practica no se pisan: bienvenida es
    // solo primera carga, el prometido suele ser para inactivos que ya
    // cargaron antes.
    $recarga['bono']       = $bono + max(0, $bonoPrometido);
}

/**
 * Cumple la promesa del bono de bienvenida sobre la primera carga: mira por
 * que landing entro el jugador (altas.origen), resuelve el %
 * ('bono50' -> RL_BONO_BIENVENIDA_PCT; 'lp:<slug>' -> landings.bono_pct de
 * ESA landing, migracion 52) y lo suma en BONOS (usuarios.bonus, el contador
 * propio -- no toca el saldo de ganamos).
 *
 * QUIEN DECIDE "PRIMERA" ES EL CALLER (es_primera en rl_acreditar; cero
 * recargas acreditadas en rl_acreditar_directo y hg_webhook). Aca vive el
 * candado de UNA SOLA VEZ POR JUGADOR: el movimiento con origen
 * 'bono_bienvenida' es la marca, y se consulta con FOR UPDATE a proposito --
 * una lectura bloqueante ve lo ultimo COMMITEADO, no el snapshot de la
 * transaccion, asi que cuando dos acreditaciones del mismo usuario calculan
 * "primera" casi a la vez (las dos contaron cero previas), la que llega
 * segunda espera el lock de la fila de `usuarios` que la primera ya tiene,
 * y al seguir VE el movimiento recien commiteado y se frena. Usa el indice
 * ix_usuario de movimientos: no toca rangos de otros jugadores.
 *
 * El movimiento se inserta ANTES de sumar el bonus, a proposito: si algo se
 * rompe entre los dos, queda el candado puesto y un log con el usuario.
 * Deberle un bono (se carga a mano desde el CRM) es recuperable; pagarlo dos
 * veces no.
 *
 * Dos decisiones sobre el alta:
 *  - Solo cuenta un alta CONCRETADA (estado='ok'): una fila zombie en
 *    'error' de un intento que nunca se completo no puede regalarle el bono
 *    a un tocayo creado despues por el panel de agentes.
 *  - La landing 'lp:' se busca AUNQUE este pausada: pausar corta las altas
 *    nuevas, no las promesas ya hechas a quien se registro antes.
 *
 * Nunca lanza. Devuelve el bono acreditado (0 si no correspondia).
 */
function rl_bono_bienvenida_aplicar(PDO $pdo, string $usuario, int $coins): int
{
    try {
        // Sin JOIN a proposito: `altas` comparte collation con `usuarios`,
        // pero comparar contra un parametro PHP no depende de ninguna.
        $sa = $pdo->prepare(
            "SELECT origen FROM altas WHERE usuario = ? AND estado = 'ok'
              ORDER BY id DESC LIMIT 1"
        );
        $sa->execute([$usuario]);
        $origenAlta = (string)$sa->fetchColumn();

        if ($origenAlta === '') {
            /* Sin alta confirmada. Si igual hay una fila con promo en otro
               estado, NO se paga (podria ser el zombie de un intento ajeno),
               pero tampoco se calla: es el caso real de un alta de landing
               que fallo y el agente creo la cuenta a mano en el panel -- el
               bono se le debe y alguien lo tiene que ver. */
            $sz = $pdo->prepare(
                "SELECT origen, estado FROM altas WHERE usuario = ? ORDER BY id DESC LIMIT 1"
            );
            $sz->execute([$usuario]);
            if ($z = $sz->fetch()) {
                $oz = (string)$z['origen'];
                if ($oz === RL_BONO_BIENVENIDA_ORIGEN || strncmp($oz, 'lp:', 3) === 0) {
                    error_log('rl_bono_bienvenida: ' . $usuario . ' tiene alta con promo '
                        . $oz . " en estado '" . (string)$z['estado'] . "' (no 'ok'):"
                        . ' bono NO pagado solo. Si la cuenta se creo a mano por esa'
                        . ' promo, cargarlo desde el CRM.');
                }
            }
            return 0;
        }

        $pctBono = 0;
        if (strncmp($origenAlta, 'lp:', 3) === 0) {
            if (!function_exists('landings_por_slug')) {
                // Deploy parcial (recargas_lib nuevo sin landings_lib): que
                // no sea silencioso -- este log es la unica señal de que se
                // le esta debiendo un bono prometido a alguien.
                error_log('rl_bono_bienvenida: falta landings_lib.php y '
                    . $usuario . ' entro por ' . $origenAlta
                    . ' -- bono NO acreditado, cargarlo a mano desde el CRM');
            } else {
                $lpFila = landings_por_slug($pdo, substr($origenAlta, 3), false);
                if ($lpFila) {
                    $pctBono = (int)$lpFila['bono_pct'];
                }
            }
        } elseif ($origenAlta === RL_BONO_BIENVENIDA_ORIGEN || $origenAlta === 'chatbot') {
            /* bono50 = landing de promo (siempre lo cobro). 'chatbot' = cuenta
               creada POR EL CHAT: el bot le PROMETE el bono al crear la cuenta
               ("con tu primera carga te doy el bono"), pero antes no lo cobraba
               -- caia en el else y quedaba en 0. Ahora si, con el bono general
               del casino (config_crm 'bono_bienvenida_pct', default 50%). Es la
               primera carga (es_primera, garantizado por el caller) y el candado
               de movimientos evita pagarlo dos veces. Para apagarlo, poner
               bono_bienvenida_pct en 0 desde el CRM.
               A proposito NO se incluye 'landing'/'panel'/manual: esos no
               prometen bono, y darselo seria regalar fichas sin quererlo. */
            $pctBono = fichas_limite($pdo, 'bono_bienvenida_pct', RL_BONO_BIENVENIDA_PCT);
        }
        if ($pctBono <= 0) {
            return 0;
        }
        $bono = (int)floor($coins * $pctBono / 100);
        if ($bono <= 0) {
            return 0;
        }

        /* EL BONO ES POR PERSONA, NO POR CUENTA (16/09/2026). El candado de
           abajo es por usuario, así que una cuenta nueva era un bono nuevo:
           registrarse, cargar el mínimo, cobrar el 50%, repetir. Si OTRA
           cuenta de la misma persona —misma cuenta bancaria o mismo
           comprobante declarado, ver vin_misma_persona()— ya cobró la
           bienvenida, esta no la cobra. Funciona en la PRIMERA carga de la
           cuenta nueva porque la huella se aprende antes que este bono en
           los tres caminos de acreditación. Ante error, el helper devuelve
           null y se paga: no se le corta el bono a todos por una tabla que
           falte. */
        if (!function_exists('vin_bono_cobrado_por_grupo') && is_file(__DIR__ . '/vinculos_lib.php')) {
            require_once __DIR__ . '/vinculos_lib.php';
        }
        if (function_exists('vin_bono_cobrado_por_grupo')) {
            $otra = vin_bono_cobrado_por_grupo($pdo, $usuario, 'bono_bienvenida');
            if ($otra !== null) {
                error_log('rl_bono_bienvenida: ' . $usuario . ' NO cobra: la misma persona ya lo cobró en ' . $otra);
                return 0;
            }
        }

        // El candado (ver docblock).
        $ya = $pdo->prepare(
            "SELECT id FROM movimientos
              WHERE usuario = ? AND origen = 'bono_bienvenida' LIMIT 1 FOR UPDATE"
        );
        $ya->execute([$usuario]);
        if ($ya->fetchColumn()) {
            return 0;
        }

        // El movimiento con origen propio: en la ficha del CRM se distingue
        // "bono de la promo" de un bono cargado a mano (si no, las cuentas
        // de Publicidad no cierran) -- y ademas ES el candado de arriba.
        $pdo->prepare(
            "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
             VALUES (?, 'bono', ?, ?, 'bono_bienvenida')"
        )->execute([
            mb_substr($usuario, 0, 50),
            $bono,
            'Bono de bienvenida ' . $pctBono . '% por su primera carga',
        ]);

        $pdo->prepare("UPDATE usuarios SET bonus = bonus + ? WHERE username = ?")
            ->execute([$bono, $usuario]);

        if (function_exists('notif_crear')) {
            notif_crear(
                $pdo,
                $usuario,
                '🎁 ¡Bono de bienvenida!',
                'Te acreditamos ' . number_format($bono, 0, ',', '.')
                    . ' en bonos (' . $pctBono
                    . '% de tu primera carga). ¡A jugarlos!',
                'bono',
                null,
                'recargas'
            );
        }
        return $bono;
    } catch (Throwable $e) {
        /* Un deadlock (1213 / SQLSTATE 40001) NO es "fallo el bono": InnoDB
           ya revirtio la transaccion ENTERA del caller del lado del server.
           Tragarlo aca dejaria a rl_acreditar creyendo que acredito (y sus
           efectos post-commit -- carga al juego, aviso -- correrian sobre
           una acreditacion deshecha, que el matcher volveria a pagar en la
           proxima pasada). Se RELANZA para que el caller haga su rollback y
           devuelva error, como con cualquier otro fallo de acreditacion.
           Solo dentro de una transaccion: en autocommit el deadlock revierte
           nada mas que su statement y el best-effort de siempre esta bien. */
        if ($pdo->inTransaction() && $e instanceof PDOException
            && ((string)($e->errorInfo[0] ?? '') === '40001'
                || (int)($e->errorInfo[1] ?? 0) === 1213)) {
            throw $e;
        }
        // CON usuario y monto, y sin afirmar de mas: si fallo DESPUES del
        // INSERT del movimiento, el candado quedo puesto con el bonus a
        // medias -- revisar `movimientos` origen='bono_bienvenida' del
        // usuario ANTES de acreditar nada a mano. Y OJO al reponerlo: una
        // carga manual del CRM deja origen='crm', que NO planta el candado
        // -- si el jugador acredita despues otra "primera" carga, el sistema
        // puede volver a pagarlo solo. Verificar antes de duplicar.
        error_log('rl_bono_bienvenida: ' . $usuario . ' coins=' . $coins
            . ': ' . $e->getMessage()
            . ' -- revisar movimientos bono_bienvenida del usuario antes de acreditar a mano');
        return 0;
    }
}

/**
 * Purchase de Meta: EL evento que le importa a la campaña, plata real
 * acreditada. Separado de rl_acreditar() a proposito -- meta_evento() hace
 * un curl HTTP a Meta (timeout hasta 5s) y rl_acreditar() corre DENTRO de la
 * transaccion del caller con filas tomadas por FOR UPDATE; llamar a Meta ahi
 * retendria esos locks de mas. Se llama despues del commit, mismo momento y
 * misma razon que rl_notificar_acreditada().
 *
 * `ref` con el id de la recarga hace el event_id reproducible (un reintento
 * del matcher no duplica el evento en Meta). Si el jugador vino de una
 * landing con ?pub=, se reporta con el pixel DE ESE publicista -- sin eso,
 * cae al pixel general del cliente (meta_evento() ya resuelve ese fallback).
 */
function rl_reportar_purchase(PDO $pdo, array $recarga): void
{
    if (!function_exists('meta_evento')) {
        return;
    }
    try {
        $atrib = function_exists('publicidad_atribucion_por_usuario')
            ? publicidad_atribucion_por_usuario($pdo, (string)$recarga['usuario'])
            : ['publicista' => null, 'fbp' => '', 'fbc' => ''];
        meta_evento($pdo, 'Purchase', [
            'usuario' => (string)$recarga['usuario'],
            'valor'   => (float)$recarga['monto_base'],
            'ref'     => 'recarga:' . $recarga['id'],
            'fbp'     => $atrib['fbp'],
            'fbc'     => $atrib['fbc'],
            // Del jugador, no de quien dispara este evento.
            'ip'      => $atrib['ip'] ?? '',
            'ua'      => $atrib['ua'] ?? '',
            'url'     => $atrib['url'] ?? '',
            'pixel'   => function_exists('publicidad_pixel_propio')
                ? publicidad_pixel_propio($atrib['publicista']) : null,
        ]);
    } catch (Throwable $e) {
        error_log('meta Purchase (recarga): ' . $e->getMessage());
    }
}

/**
 * Encola la carga al juego apenas se acredita la transferencia.
 *
 * POR QUE ESTO EXISTE
 * El negocio tiene UN solo paso: el jugador transfiere y le aparecen las
 * fichas en la plataforma. No existe un "saldo comprado esperando a que lo
 * carguen" -- eso era un invento del codigo, y traia dos problemas:
 *
 *   1. El jugador transferia y no le pasaba nada hasta que ADEMAS pedia
 *      "cargame las fichas". Nadie hace eso: ya pago, espera sus fichas.
 *   2. Al chatbot le dabamos dos herramientas parecidas (cargar_al_juego y
 *      crear_recarga) y elegia mal. Un modelo con dos caminos casi iguales se
 *      equivoca; con uno solo, no puede.
 *
 * fichas_pedir_carga() ya hace todo lo delicado y se reusa tal cual: valida
 * que el jugador exista en el panel, corta si ya hay una carga en curso (que
 * es lo que evita depositar dos veces), descuenta y encola en acciones_saldo.
 * Despues el bot entra al panel de agentes, busca al jugador y aprieta
 * DEPOSITAR -- lo mismo que haria un empleado a mano.
 *
 * Best-effort y DESPUES del commit: la recarga ya esta acreditada. Si esto
 * falla, los coins le quedan al jugador y se pueden cargar a mano desde el
 * CRM; lo que no puede pasar es que un problema para encolar deshaga un pago
 * que ya entro.
 */
function rl_cargar_al_juego_auto(PDO $pdo, array $recarga): void
{
    if (!function_exists('fichas_pedir_carga')) {
        return;
    }
    try {
        // $confiable=true: la recarga YA se pago y se acredito, el jugador es
        // real. Asi el deposito al juego se encola aunque el espejo `usuarios`
        // no lo tenga todavia (sync atrasado o caido) -- sin esto la plata
        // entraba pero las fichas no llegaban al juego.
        // El bono de bienvenida (si esta recarga lo gano) va en el MISMO
        // deposito: transferencia de 3000 con bono 50% = una carga de 4500.
        $r = fichas_pedir_carga($pdo, (string)$recarga['usuario'], (int)$recarga['coins'], 'recarga', true,
                                max(0, (int)($recarga['bono'] ?? 0)));
        if (empty($r['ok'])) {
            // 'en_curso' no es un problema: ya hay una carga en camino para
            // ese jugador y el bot la esta por hacer. El resto si conviene
            // mirarlo -- queda saldo sin cargar.
            error_log('rl_cargar_al_juego_auto: recarga ' . ($recarga['referencia'] ?? '?')
                . ' acreditada pero no se encolo la carga: ' . ($r['codigo'] ?? '?')
                . ' ' . ($r['error'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('rl_cargar_al_juego_auto: ' . $e->getMessage());
    }

    /* El bono del REFERIDOR (si esta primera carga se lo gano, ver
       rl_acreditar): deposito solo-bono AL JUEGO de quien lo trajo, por el
       mismo camino que todo bono. Corre aca porque este punto es post-commit
       (fichas_pedir_carga abre su propia transaccion). Si justo el referidor
       tiene una carga en curso, el bono queda en su contador y lo manda
       «Bonos al juego» -- misma degradacion que el bono del CRM. */
    if (!empty($recarga['ref_pago']['monto'])) {
        try {
            $rp = $recarga['ref_pago'];
            $r2 = fichas_pedir_carga($pdo, (string)$rp['usuario'], 0, 'referidos', false,
                                     (int)$rp['monto']);
            if (empty($r2['ok'])) {
                error_log('rl_cargar_al_juego_auto: bono de referido para '
                    . $rp['usuario'] . ' quedo en el contador: ' . ($r2['codigo'] ?? '?'));
            }
        } catch (Throwable $e) {
            error_log('rl_cargar_al_juego_auto (referido): ' . $e->getMessage());
        }
    }
}

/** Aviso de recarga acreditada. Mismo texto para el camino automatico y el
 *  manual: para el jugador es el mismo evento, no hay por que distinguirlo. */
/**
 * ¿Ya le mandamos hoy la invitacion a instalar la app?
 *
 * El freno de "una por dia" del mensaje de chat, espejo del que ya tiene el
 * cartel del widget (PROMO_APP_MIN). Sin esto, quien carga cinco veces en un
 * dia se lleva cinco invitaciones identicas.
 *
 * SE PREGUNTA POR LA MARCA `app_invite` DE `mensajes.meta`, no por el texto.
 * Buscar por texto ata el freno a una frase, y la primera vez que alguien
 * mejore la redaccion el freno deja de encontrar nada y vuelve el spam --
 * ademas en silencio, que es lo peor.
 *
 * Ante un error devuelve TRUE (= "ya se mando"): el costo de saltear una
 * invitacion es cero, y el de repetirla es el spam que se vino sacando toda la
 * semana. Sin la conversacion tampoco hay nada que mirar, y ahi no hay mensaje
 * posible de todos modos.
 */
function rl_invito_app_hoy(PDO $pdo, string $usuario): bool
{
    try {
        $st = $pdo->prepare(
            "SELECT 1
               FROM mensajes m
               JOIN conversaciones c ON c.id = m.conversacion_id
              WHERE (c.clave = ? OR c.usuario = ?)
                AND m.meta LIKE '%\"app_invite\"%'
                AND m.creado_en >= NOW() - INTERVAL 1 DAY
              LIMIT 1"
        );
        $st->execute([$usuario, $usuario]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('rl_invito_app_hoy (' . $usuario . '): ' . $e->getMessage());
        return true;
    }
}

function rl_notificar_acreditada(PDO $pdo, array $recarga): void
{
    $usuario = (string)$recarga['usuario'];
    $coins   = (int)$recarga['coins'];
    $bono    = max(0, (int)($recarga['bono'] ?? 0));

    if (function_exists('notif_crear')) {
        notif_crear(
            $pdo,
            $usuario,
            'Recarga acreditada',
            'Ya tenés tus ' . number_format($coins, 0, ',', '.')
                . ' fichas disponibles. ¡A jugar!',
            'recarga',
            null,
            'recargas'
        );
    }

    /* El bono de la app que quedo esperando la primera carga (instalo antes
       de cargar): esto ES una carga, asi que aca se libera. Corre post-commit
       en todos los callers; best-effort adentro, no puede tumbar el aviso. */
    if (function_exists('notif_app_bono_liberar')) {
        notif_app_bono_liberar($pdo, $usuario);
    }

    /* Ademas del push, un aviso EN EL CHAT: la carga entra minutos despues de
       que el jugador transfiere (asincronico), y hasta ahora preguntaba "ya me
       cargaste?" sin recibir una confirmacion clara -- solo la push, que se
       pierde facil. Ahora Camila se lo confirma en la conversacion cuando de
       verdad entro (lo pidio Nahuel). Best-effort: si no chateo, queda la push. */
    $crmLib = __DIR__ . '/crm_lib.php';
    if (is_file($crmLib)) { require_once $crmLib; }
    if (function_exists('crm_avisar_jugador')) {
        /* ESTA CONFIRMACION YA NO REPITE EL BONO, y es a proposito.
           Al acreditarse una carga con bono salian TRES avisos en el mismo
           minuto: este (fichas + bono), la invitacion a la app, y --cuando el
           bot confirma que deposito en ganamos-- el "🎁 Bono acreditado: ya
           tenes N en tu saldo del juego". Nahuel lo leyo como "se le dio dos
           veces el bono"; en la base habia UNA sola acreditacion (verificado),
           lo que estaba duplicado era el anuncio.

           De los dos que hablaban del bono, el que sobrevive es el OTRO: llega
           cuando las fichas estan de verdad EN EL JUEGO y dice el total
           jugable, que es lo unico que el jugador puede usar. Este llega antes
           de que el deposito ocurra, asi que prometer el bono aca es prometer
           algo que todavia no esta.

           Y hay una razon de negocio, que la pidio Nahuel: "lo mas importante
           de todo es que descarguen la app". Sacarle una linea a este mensaje
           deja que la invitacion a la app --el aviso siguiente-- no compita
           con un parrafo sobre bonos. */
        $msg = '¡Listo! Ya te acredité tus ' . number_format($coins, 0, ',', '.') . ' fichas 🎉'
             . ' ¡Gracias por jugar con nosotros, mucha suerte! 🍀';
        crm_avisar_jugador($pdo, $usuario, $msg);

        /* LA INVITACION A LA APP, DE VUELTA (15/09/2026, segunda vez).
           Se habia sacado esta manana razonando que el CARTEL del widget dice
           lo mismo en el mismo segundo y quedaban dos avisos pegados. Eso es
           cierto SOLO SI EL JUGADOR ESTA MIRANDO.

           Y casi nunca lo esta: la carga acredita minutos despues de que
           transfirio (el mail del banco es asincronico), y el cartel necesita
           la pagina abierta y a la vista -- el widget sondea cada 25 s y no
           dibuja nada si la pestaña se cerro. O sea que en el caso normal el
           cartel se pierde y no queda NADA. El mensaje de chat sobrevive: esta
           ahi cuando el jugador vuelve.

           Los dos canales, entonces, y cada uno para lo suyo: el cartel para
           el que esta presente (tiene el boton de descarga ahi mismo), el
           mensaje para el que no.

           EN TODAS LAS CARGAS, NO SOLO LA PRIMERA. Antes iba con `es_primera`;
           Nahuel pidio explicitamente que salga siempre. Pero "siempre" no es
           "en cada carga": quien carga cinco veces en un dia no necesita cinco
           invitaciones. El freno es UNA POR DIA, el mismo criterio que el
           cartel (PROMO_APP_MIN).

           Las condiciones de siempre: la promo prendida y con monto, `app_url`
           configurada (sin URL no se inventa un link -- paso en produccion que
           mandaba a Play Store, donde no esta) y el jugador sin la app
           (tiene_app=0; si ya la tiene, el regalo ya lo cobro y prometerselo
           seria mentirle). Si instala, el circuito existente acredita solo.
           Best-effort total: esto no puede tumbar una acreditacion. */
        try {
            if (function_exists('cfg_crm_activo') && cfg_crm_activo($pdo, 'app_promo_activa')) {
                $fichasApp = max(0, (int)(cfg_crm($pdo, 'app_bono_fichas') ?? 0));
                $urlApp    = trim((string)(cfg_crm($pdo, 'app_url') ?? ''));
                if ($fichasApp > 0 && $urlApp !== '') {
                    if (!preg_match('~^https?://~i', $urlApp)) { $urlApp = 'https://' . $urlApp; }
                    $st = $pdo->prepare("SELECT tiene_app FROM usuarios WHERE username = ?");
                    $st->execute([$usuario]);
                    $fila = $st->fetch(PDO::FETCH_ASSOC);
                    if ($fila && !(int)$fila['tiene_app'] && !rl_invito_app_hoy($pdo, $usuario)) {
                        crm_avisar_jugador($pdo, $usuario,
                            '🎁 Ah, y tenés un regalo más esperándote: instalá nuestra app y '
                            . 'te acredito otras ' . number_format($fichasApp, 0, ',', '.')
                            . ' fichas, solas, apenas entres con tu cuenta. Bajala de acá: '
                            . $urlApp,
                            ['app_invite' => true]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('rl_notificar_acreditada (invitacion app): ' . $e->getMessage());
        }
    }
}

/**
 * Nombre listo para comparar: sin tildes, en mayusculas, solo letras.
 * "José  Ñuñez-Pérez" -> "JOSE NUNEZ PEREZ"
 */
function rl_normalizar_nombre(string $s): string
{
    $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z\s]/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Palabras utiles de un nombre. Descarta particulas y letras sueltas. */
function rl_tokens_nombre(string $s): array
{
    $basura = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'DA', 'DOS'];
    $out = [];
    foreach (explode(' ', rl_normalizar_nombre($s)) as $t) {
        if ($t !== '' && mb_strlen($t) > 1 && !in_array($t, $basura, true)) {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * Cuanto se parecen dos palabras de un nombre (0..1).
 *
 * Los tres casos reales, en orden de confianza:
 *   iguales                          -> 1.00
 *   una es prefijo de la otra        -> 0.92   CABALLER / CABALLERO
 *   distancia de edicion chica       -> 1-d/n  NAHUER / NAHUEL
 *
 * El tramo de distancia de edicion es un agregado sobre el matcher.py
 * original, que solo tenia los dos primeros. Con eso, "NAHUER HERRRA",
 * "NAHUL" o "EHERRERA" daban CERO contra "NAHUEL HERRERA" -- justo las
 * erratas mas comunes, porque el prefijo solo agarra cuando el error esta al
 * final. Se exige d<=2 y que no pase de un tercio del largo: sin ese tope,
 * palabras cortas y distintas ("ANA"/"ANO") empezarian a puntuar.
 */
function rl_similitud_palabra(string $x, string $y): float
{
    if ($x === $y) {
        return 1.0;
    }
    $lx = strlen($x);
    $ly = strlen($y);
    $corto = min($lx, $ly);
    if ($corto >= 4 && (strpos($x, $y) === 0 || strpos($y, $x) === 0)) {
        return 0.92;
    }
    $largo = max($lx, $ly);
    if ($largo < 4) {
        return 0.0;
    }
    $d = levenshtein($x, $y);
    if ($d <= 2 && $d / $largo <= 1 / 3) {
        return round(1 - $d / $largo, 3);
    }
    return 0.0;
}

/**
 * Cuanto se parecen dos nombres completos (0..1).
 *
 * Compara el titular que el jugador DECLARO al pedir la carga contra el que
 * informa el banco en el comprobante. Los dos son nombres reales, asi que
 * tolera lo que pasa de verdad:
 *   · orden distinto      HERRERA FACUNDO  vs  FACUNDO HERRERA
 *   · apellidos faltantes NAHUEL HERRERA   vs  FACUNDO NAHUEL HERRERA
 *   · truncamiento        CABALLER         vs  CABALLERO
 *   · erratas             NAHUER HERRRA    vs  NAHUEL HERRERA
 *
 * Cada palabra se aparea con la que MEJOR le pegue del otro lado, y una vez
 * usada no se reutiliza (si no, "JUAN JUAN" contra "JUAN PEREZ" daria 1.0).
 * Se divide por la lista mas corta a proposito: que a uno le falte un
 * apellido no tiene que penalizar, es lo normal cuando alguien escribe su
 * nombre a las apuradas en un chat.
 */
function rl_similitud_nombres(string $a, string $b): float
{
    $ta = rl_tokens_nombre($a);
    $tb = rl_tokens_nombre($b);
    if (!$ta || !$tb) {
        return 0.0;
    }
    $usados  = [];
    $aciertos = 0.0;
    foreach ($ta as $x) {
        $mejor = 0.0;
        $idx   = null;
        foreach ($tb as $i => $y) {
            if (isset($usados[$i])) {
                continue;
            }
            $p = rl_similitud_palabra($x, $y);
            if ($p > $mejor) {
                $mejor = $p;
                $idx   = $i;
            }
        }
        if ($idx !== null) {
            $usados[$idx] = true;
            $aciertos += $mejor;
        }
    }
    return round($aciertos / min(count($ta), count($tb)), 3);
}

/**
 * Que usuarios ya pagaron alguna vez desde esta cuenta (CUIT o CBU).
 *
 * Devuelve [] si el comprobante no trae ninguno de los dos, si nadie pago
 * nunca desde ahi, o si la migracion 45 no corrio. Ese [] significa "no se",
 * NUNCA "ninguno": el que llama tiene que seguir de largo sin descartar
 * candidatas. Es la diferencia entre una pista y un requisito.
 */
function rl_usuarios_por_huella(PDO $pdo, array $pago): array
{
    $cuit = trim((string)($pago['cuit'] ?? ''));
    $cbu  = trim((string)($pago['cbu_origen'] ?? ''));
    if ($cuit === '' && $cbu === '') {
        return [];
    }
    try {
        $st = $pdo->prepare(
            "SELECT usuario FROM huellas_pagador
              WHERE (cuit <> '' AND cuit = ?) OR (cbu <> '' AND cbu = ?)
              GROUP BY usuario"
        );
        $st->execute([$cuit, $cbu]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Aprende que este usuario paga desde esta cuenta. Se llama en CADA
 * acreditacion, automatica o manual -- y la manual es la que mas vale: un
 * pago que el operador resolvio a mano hoy es uno que se resuelve solo
 * manana.
 *
 * Best-effort a proposito: si esto falla, la recarga YA se acredito. Nunca
 * puede tumbar una acreditacion por no poder guardar una pista.
 */
function rl_aprender_huella(PDO $pdo, string $usuario, array $pago): void
{
    $cuit = trim((string)($pago['cuit'] ?? ''));
    $cbu  = trim((string)($pago['cbu_origen'] ?? ''));
    if ($usuario === '' || ($cuit === '' && $cbu === '')) {
        return;
    }
    try {
        $pdo->prepare(
            "INSERT INTO huellas_pagador (usuario, cuit, cbu, nombre)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE usos = usos + 1, nombre = VALUES(nombre)"
        )->execute([$usuario, $cuit, $cbu, mb_substr((string)($pago['remitente'] ?? ''), 0, 160)]);
    } catch (Throwable $e) {
        error_log('rl_aprender_huella: ' . $e->getMessage());
    }

    /* La huella recién aprendida puede ser justo la que une dos cuentas: si
       ahora este usuario comparte cuenta bancaria con otro, se le avisa (una
       sola vez — la idempotencia vive adentro). Best-effort: el aviso jamás
       puede afectar la acreditación desde la que se llamó. */
    try {
        if (!function_exists('vin_avisar_multicuenta') && is_file(__DIR__ . '/vinculos_lib.php')) {
            require_once __DIR__ . '/vinculos_lib.php';
        }
        if (function_exists('vin_avisar_multicuenta')) {
            vin_avisar_multicuenta($pdo, $usuario);
        }
    } catch (Throwable $e) {
        error_log('rl_aprender_huella (aviso multicuenta): ' . $e->getMessage());
    }
}

/**
 * Elige a QUE recarga corresponde un pago, entre las que ya coincidieron en
 * monto y ventana de tiempo. Devuelve [recarga|null, confianza, motivo].
 *
 * Las capas van de mas fuerte a mas debil, y ninguna adivina:
 *
 *   1. HUELLA (CUIT/CBU)  exacta. Si este pagador ya cargo antes para UNA de
 *      las candidatas, es esa. Con varias, solo acota y sigue. Sin huella no
 *      descarta nada -- en la primera transferencia de cualquiera no existe.
 *   2. TITULAR DECLARADO  el nombre que el jugador dijo al pedir la carga
 *      contra el que informa el banco. Exige umbral Y margen sobre la
 *      segunda: acertar por poquito entre dos parecidas es adivinar.
 *   3. UNICA CANDIDATA    si quedo una sola, es esa aunque no se pueda
 *      verificar el nombre (comportamiento historico, se conserva).
 *   4. Nada de lo anterior -> revision manual, con el motivo escrito.
 */
function rl_elegir_recarga(PDO $pdo, array $cands, array $pago): array
{
    if (!$cands) {
        return [null, '', 'no hay recarga pendiente que coincida en monto'];
    }

    // ---- Capa 1: huella del pagador ----
    $conocidos = rl_usuarios_por_huella($pdo, $pago);
    if ($conocidos) {
        $porHuella = array_values(array_filter(
            $cands,
            static fn($c) => in_array((string)$c['usuario'], $conocidos, true)
        ));
        if (count($porHuella) === 1) {
            $ident = trim((string)($pago['cuit'] ?? '')) ?: trim((string)($pago['cbu_origen'] ?? ''));
            return [$porHuella[0], 'alta',
                    'ya habia cargado desde esta cuenta (' . $ident . ')'];
        }
        if (count($porHuella) > 1) {
            $cands = $porHuella;   // no decide, pero descarta al resto
        }
    }

    // ---- Capa 2: titular declarado vs titular del comprobante ----
    $remitente = (string)($pago['remitente'] ?? '');
    if ($remitente !== '') {
        $puntajes = [];
        foreach ($cands as $c) {
            $decl = (string)($c['titular_declarado'] ?? '');
            $puntajes[] = [$decl !== '' ? rl_similitud_nombres($remitente, $decl) : 0.0, $c];
        }
        usort($puntajes, static fn($a, $b) => $b[0] <=> $a[0]);

        if ($puntajes[0][0] >= RL_UMBRAL_NOMBRE) {
            $segundo = isset($puntajes[1]) ? $puntajes[1][0] : 0.0;
            if (($puntajes[0][0] - $segundo) >= RL_MARGEN_NOMBRE) {
                return [$puntajes[0][1], 'alta', sprintf(
                    'titular coincide: "%s" ~ "%s" (%.2f)',
                    $remitente, $puntajes[0][1]['titular_declarado'], $puntajes[0][0]
                )];
            }
            return [null, '', sprintf(
                'dos titulares parecidos (%s %.2f vs %s %.2f): lo resuelve un operador',
                $puntajes[0][1]['usuario'], $puntajes[0][0],
                $puntajes[1][1]['usuario'], $segundo
            )];
        }
    }

    // ---- Capa 3: quedo una sola ----
    if (count($cands) === 1) {
        return [$cands[0], 'media', 'unica recarga en monto y ventana (el titular no verifica)'];
    }

    return [null, '', count($cands) . ' recargas posibles, ninguna distinguible'];
}

/** Casa el pago con una recarga pendiente y acredita, todo atomico. */
function rl_matchear_y_acreditar(PDO $pdo, string $idUnico, float $monto): array
{
    $pdo->beginTransaction();
    try {
        rl_vencer($pdo);
        $centTarget = (int)round($monto * 100);

        // El comprobante entero: hace falta el titular, el CUIT y el CBU para
        // desempatar, no solo el monto (ver rl_elegir_recarga).
        $pg = $pdo->prepare("SELECT * FROM pagos WHERE id_unico = ? LIMIT 1");
        $pg->execute([$idUnico]);
        $pago = $pg->fetch() ?: [];

        /* ---- CAPA 0: EL NUMERO DE OPERACION, QUE NO ADMITE EMPATE ----
           El jugador declara el numero de operacion de su transferencia (por
           texto con `informar_transferencia`, o leido de la foto por vision) y
           se guarda en `recargas.trx_declarada`. Estaba guardado desde la
           migracion 45 y NO se usaba para casar: lo que desempataba era el
           titular, con tolerancia a erratas.

           Ahora se usa, y va PRIMERO, porque es la unica señal que no admite
           interpretacion: dos personas pueden llamarse igual y transferir el
           mismo monto el mismo minuto, pero no comparten el numero de
           operacion del banco.

           POR QUE RECIEN AHORA: hacia falta saber que el dato existe de los dos
           lados. Medido el 16/09/2026 sobre los 81 pagos de 30 dias, el 100%
           trae `nro_transaccion`. Antes esto era una idea; ahora es un cruce.

           Se exigen 6 caracteres: los numeros cortos los tipea cualquiera y
           casarian recargas de desconocidos. Y se compara contra el monto
           tambien -- si el numero coincide pero el importe no, algo esta mal y
           es mejor caer a las capas de abajo que acreditar a ciegas. */
        $trx = trim((string)($pago['nro_transaccion'] ?? ''));
        $porTrx = null;
        if (mb_strlen($trx) >= 6) {
            try {
                $qt = $pdo->prepare(
                    "SELECT * FROM recargas
                      WHERE estado = 'pendiente'
                        AND trx_declarada IS NOT NULL
                        AND TRIM(trx_declarada) = ?
                        AND ROUND(monto_pedido*100) = ?
                      ORDER BY creada_en
                      LIMIT 2 FOR UPDATE"
                );
                $qt->execute([$trx, $centTarget]);
                $cT = $qt->fetchAll();
                /* Si dos recargas declararon el MISMO numero, no se elige: son
                   dos personas reclamando la misma transferencia, y eso lo mira
                   un operador. Acreditar a la primera seria premiar al que
                   copio el comprobante de otro. */
                if (count($cT) === 1) { $porTrx = $cT[0]; }
            } catch (Throwable $e) {
                // Sin la migracion 45 no hay trx_declarada: sigue el camino viejo.
            }
        }

        // Capa 1: monto EXACTO (centavos unicos). Es el camino normal.
        $q = $pdo->prepare(
            "SELECT * FROM recargas
              WHERE estado='pendiente' AND ROUND(monto_pedido*100)=?
              ORDER BY creada_en FOR UPDATE"
        );
        $q->execute([$centTarget]);
        $cands = $q->fetchAll();

        $recarga = null;
        $conf = '';
        $confianza = null;

        /* La Capa 0 gana sobre todo lo de abajo: el numero de operacion es del
           banco, no una coincidencia de monto. */
        if ($porTrx) {
            $recarga   = $porTrx;
            $confianza = 'alta';
            $conf = 'numero de operacion declarado: ' . $trx;
        }
        elseif (count($cands) === 1) {
            $recarga = $cands[0];
            $conf = 'monto exacto';
            $confianza = 'alta';
        } elseif (count($cands) > 1) {
            // Mismo importe exacto pedido por dos jugadores a la vez. Antes
            // esto iba derecho a revision; ahora se desempata por huella o
            // por titular declarado.
            [$recarga, $confCapa, $motivo] = rl_elegir_recarga($pdo, $cands, $pago);
            $conf = $recarga ? $confCapa . ': ' . $motivo : '';
            $confianza = $recarga ? $confCapa : null;
            if (!$recarga) {
                $pdo->prepare("UPDATE pagos SET estado='revision' WHERE id_unico=?")->execute([$idUnico]);
                $pdo->commit();
                return ['resultado' => 'revision', 'mensaje' => $motivo];
            }
        } elseif (count($cands) === 0) {
            // Capa 2 (respaldo): el jugador transfirio redondo, o la billetera
            // trunco los decimales. Se busca por parte entera dentro de la
            // ventana. Antes esto exigia UNA sola candidata y si no, a
            // revision -- que es lo que pasaba seguido, porque muchos piden el
            // mismo monto "lindo" ($1000) al mismo tiempo. Ahora las varias
            // candidatas se desempatan por huella o titular.
            $q2 = $pdo->prepare(
                "SELECT * FROM recargas
                  WHERE estado='pendiente' AND FLOOR(monto_pedido)=?
                    AND creada_en >= DATE_SUB(NOW(), INTERVAL " . RL_VENTANA_MIN . " MINUTE)
                  ORDER BY creada_en FOR UPDATE"
            );
            $q2->execute([(int)floor($monto)]);
            $c2 = $q2->fetchAll();
            if ($c2) {
                [$recarga, $confCapa, $motivo] = rl_elegir_recarga($pdo, $c2, $pago);
                $conf = $recarga ? $confCapa . ' (monto entero): ' . $motivo : '';
                $confianza = $recarga ? $confCapa : null;
                if (!$recarga) {
                    $pdo->prepare("UPDATE pagos SET estado='revision' WHERE id_unico=?")->execute([$idUnico]);
                    $pdo->commit();
                    return ['resultado' => 'revision', 'mensaje' => $motivo];
                }
            }
        }

        if (!$recarga) {
            // Ninguna o ambiguas: a revision manual, nunca adivina.
            $pdo->prepare("UPDATE pagos SET estado='revision' WHERE id_unico=?")->execute([$idUnico]);
            $pdo->commit();
            return ['resultado' => 'revision', 'mensaje' => 'sin recarga unica que coincida'];
        }

        rl_acreditar($pdo, $recarga, $idUnico, $conf, null, $confianza);
        $pdo->commit();

        // Recien despues del commit: si el aviso saliera adentro de la
        // transaccion y esta hiciera rollback, quedaria anunciada una recarga
        // que nunca se acredito. Mismo motivo para el Purchase de Meta.
        // Primero encolar la carga, despues avisar: si el aviso saliera
        // antes y el encolado fallara, le habriamos dicho "ya tenes tus
        // fichas" a alguien que no las va a recibir.
        rl_cargar_al_juego_auto($pdo, $recarga);
        rl_notificar_acreditada($pdo, $recarga);
        rl_reportar_purchase($pdo, $recarga);

        return [
            'resultado'  => 'acreditada',
            'usuario'    => $recarga['usuario'],
            'coins'      => (int)$recarga['coins'],
            'referencia' => $recarga['referencia'],
            'recarga_id' => (int)$recarga['id'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rl_matchear_y_acreditar: ' . $e->getMessage());
        return ['resultado' => 'error', 'error' => 'fallo al acreditar'];
    }
}

/**
 * DECLARAR el pago: el jugador ya transfirio y dice a nombre de QUIEN esta la
 * cuenta (por texto o leido de la foto del comprobante). Lo llaman las
 * herramientas informar_transferencia y verificar_comprobante del chatbot.
 *
 * QUE HACE, y que NO:
 * - Guarda el titular en la recarga PENDIENTE del jugador (recargas.titular_
 *   declarado). Eso es lo unico que desempata dos recargas del mismo monto
 *   cuando llega el pago -- ver rl_elegir_recarga.
 * - Re-intenta casar los pagos que quedaron en 'revision' de ese monto: si el
 *   pago ya habia entrado y estaba esperando justo este dato, ahora se
 *   acredita. Reusa rl_matchear_y_acreditar, que NUNCA adivina: solo acredita
 *   con match claro.
 * - NO acredita por si mismo ni por lo que diga el jugador. El unico que
 *   confirma que entro la plata es el aviso del banco. Declarar solo AYUDA a
 *   casar; si el pago no entro, el dato queda guardado y espera.
 *
 * El numero de operacion (trx) se guarda si viene, pero NO hace falta para
 * casar: el matcher no lo usa. No se le pide al jugador (ver el chatbot).
 *
 * Devuelve estado: 'acreditada' | 'pendiente' | 'sin_pendiente'.
 */
function rl_declarar_pago(PDO $pdo, string $usuario, string $titular = '',
                          string $nroTrx = '', ?float $monto = null,
                          string $origen = 'chat'): array
{
    $usuario = trim($usuario);
    if ($usuario === '') {
        return ['ok' => false, 'error' => 'Falta el usuario.'];
    }
    rl_vencer($pdo);

    // La recarga PENDIENTE mas reciente: la que esta esperando la plata.
    $st = $pdo->prepare(
        "SELECT * FROM recargas WHERE usuario = ? AND estado = 'pendiente' ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$usuario]);
    $rec = $st->fetch();

    if (!$rec) {
        // Sin pendiente: quizas ya se acredito (se lo confirmamos) o no pidio.
        $cons = rl_consultar($pdo, $usuario);
        if (!empty($cons['encontrada']) && ($cons['estado'] ?? '') === 'acreditada') {
            return ['ok' => true, 'estado' => 'acreditada', 'usuario' => $usuario,
                    'coins' => $cons['coins'] ?? null];
        }
        return ['ok' => true, 'estado' => 'sin_pendiente'];
    }

    // Guardar el titular (y el nro si vino). COALESCE + NULLIF: un dato vacio no
    // pisa lo que ya habia (el jugador pudo declararlo al crear la recarga).
    $titular = trim($titular);
    $nroTrx  = trim($nroTrx);
    if ($titular !== '' || $nroTrx !== '') {
        try {
            $pdo->prepare(
                "UPDATE recargas
                    SET titular_declarado = COALESCE(NULLIF(?, ''), titular_declarado),
                        trx_declarada     = COALESCE(NULLIF(?, ''), trx_declarada)
                  WHERE id = ?"
            )->execute([$titular, $nroTrx, $rec['id']]);
        } catch (Throwable $e) {
            // trx_declarada es de 45_recarga_exacta, que puede faltar aunque
            // exista titular_declarado (45_match_titular). Guardar el titular
            // es lo que DESEMPATA -- no puede caerse porque falte la columna
            // del numero de operacion, que ni siquiera se usa para casar.
            try {
                $pdo->prepare(
                    "UPDATE recargas
                        SET titular_declarado = COALESCE(NULLIF(?, ''), titular_declarado)
                      WHERE id = ?"
                )->execute([$titular, $rec['id']]);
            } catch (Throwable $e2) {
                error_log('rl_declarar_pago: no pude guardar el titular: ' . $e2->getMessage());
            }
        }

        /* Un número de operación recién declarado puede ser el MISMO que ya
           declaró otra cuenta (la señal 'comprobante' de vinculos_lib, la
           más fuerte de todas): si eso acaba de pasar, el aviso multicuenta
           sale ahora. Best-effort, una sola vez por cuenta. */
        if ($nroTrx !== '') {
            try {
                if (!function_exists('vin_avisar_multicuenta') && is_file(__DIR__ . '/vinculos_lib.php')) {
                    require_once __DIR__ . '/vinculos_lib.php';
                }
                if (function_exists('vin_avisar_multicuenta')) {
                    vin_avisar_multicuenta($pdo, $usuario);
                }
            } catch (Throwable $e) {
                error_log('rl_declarar_pago (aviso multicuenta): ' . $e->getMessage());
            }
        }
    }

    // Re-intentar los pagos en 'revision' de este monto: con el titular recien
    // declarado, uno que estaba trabado por ambiguedad puede desempatar ahora.
    $montoRec = (float)$rec['monto_pedido'];
    try {
        $pg = $pdo->prepare(
            "SELECT id_unico, monto FROM pagos
              WHERE estado = 'revision'
                AND (ROUND(monto*100) = ROUND(? * 100) OR FLOOR(monto) = FLOOR(?))
              ORDER BY capturado_en DESC LIMIT 10"
        );
        $pg->execute([$montoRec, $montoRec]);
        foreach ($pg->fetchAll() as $pago) {
            $res = rl_matchear_y_acreditar($pdo, (string)$pago['id_unico'], (float)$pago['monto']);
            // Solo cuenta si acredito LA recarga de ESTE usuario. Si el match
            // acredito la de otro (era su pago), se sigue buscando el nuestro.
            if (($res['resultado'] ?? '') === 'acreditada'
                && (string)($res['usuario'] ?? '') === $usuario) {
                return ['ok' => true, 'estado' => 'acreditada', 'usuario' => $usuario,
                        'coins' => (int)($res['coins'] ?? $rec['coins'])];
            }
        }
    } catch (Throwable $e) {
        error_log('rl_declarar_pago: rematch: ' . $e->getMessage());
    }

    // El pago todavia no entro (o no casa aun): el dato quedo guardado y espera
    // al aviso del banco. El chatbot dice "quedo anotada", nunca "ya esta".
    return ['ok' => true, 'estado' => 'pendiente', 'usuario' => $usuario,
            'coins' => (int)$rec['coins'], 'monto_pedido' => $montoRec];
}

/**
 * Asignacion MANUAL de un pago en revision a una recarga pendiente puntual
 * (Fase A, modulo Comprobantes sin resolver). Revalida los dos con
 * FOR UPDATE dentro de la transaccion: si el matcher automatico o otro
 * operador se adelantaron un instante antes, no hace nada y avisa.
 */
function rl_asignar_manual(PDO $pdo, string $idUnico, int $recargaId, string $operador): array
{
    $pdo->beginTransaction();
    try {
        $pg = $pdo->prepare("SELECT * FROM pagos WHERE id_unico = ? AND estado = 'revision' FOR UPDATE");
        $pg->execute([$idUnico]);
        if (!$pg->fetch()) {
            $pdo->rollBack();
            return ['resultado' => 'error', 'error' => 'Ese pago ya no está en revisión (puede que ya se haya asignado).'];
        }

        /* Esa transferencia puede estar reservada para una carga pedida desde
           el boton Depositos de la plataforma (camino A, migracion 48). Si se
           asigna igual, el jugador cobra DOS veces: una por esta recarga y otra
           cuando aprobar_cargas.py apruebe la solicitud en el panel.

           Va adentro de la transaccion y con FOR UPDATE por el mismo motivo que
           los dos chequeos de arriba: el worker corre cada minuto y la ventana
           entre mirar y acreditar tiene que ser cero.

           Si la migracion 48 no corrio, la tabla no existe y esto no aplica --
           no hay camino A que pueda pisar nada. */
        try {
            $pt = $pdo->prepare(
                "SELECT request_id FROM peticiones_carga
                  WHERE pago_id_unico = ? AND estado IN ('esperando','revision') FOR UPDATE"
            );
            $pt->execute([$idUnico]);
            if ($req = $pt->fetchColumn()) {
                $pdo->rollBack();
                return ['resultado' => 'error', 'error' =>
                    'Esa transferencia está reservada para la carga #' . $req .
                    ', que el jugador pidió desde la plataforma. Resolvela en «Cargas del panel».'];
            }
        } catch (PDOException $e) {
            // Sin migracion 48 no hay camino A. Se sigue.
        }

        /* Se aceptan las VENCIDAS, no solo las pendientes.

           Una recarga vence a los RL_VENCIMIENTO_MIN (45) minutos, y eso esta
           bien para el matcher automatico: pasado ese rato ya no puede decidir
           solo. Pero exigirlo tambien aca dejaba comprobantes IMPOSIBLES de
           resolver: si el aviso del banco se demoraba mas de 45 minutos --
           cosa comun-- el pago quedaba en 'revision' sin ninguna candidata que
           ofrecerle al operador, para siempre. Se acumularon 25 asi.

           Que este vencida no dice nada sobre si el pago es de ella; dice que
           el jugador tardo en transferir. Quien decide es la persona, y el CRM
           le muestra el estado y hace cuanto se creo. Las canceladas SI quedan
           afuera: ahi alguien decidio expresamente anularla. */
        $rc = $pdo->prepare(
            "SELECT * FROM recargas WHERE id = ? AND estado IN ('pendiente','vencida') FOR UPDATE"
        );
        $rc->execute([$recargaId]);
        $recarga = $rc->fetch();
        if (!$recarga) {
            $pdo->rollBack();
            return ['resultado' => 'error',
                    'error' => 'Esa recarga ya no se puede usar (ya está acreditada o fue cancelada).'];
        }

        rl_acreditar($pdo, $recarga, $idUnico, 'manual: ' . $operador, $operador, 'manual');
        $pdo->commit();

        // Primero encolar la carga, despues avisar: si el aviso saliera
        // antes y el encolado fallara, le habriamos dicho "ya tenes tus
        // fichas" a alguien que no las va a recibir.
        rl_cargar_al_juego_auto($pdo, $recarga);
        rl_notificar_acreditada($pdo, $recarga);
        rl_reportar_purchase($pdo, $recarga);

        return [
            'resultado'  => 'acreditada',
            'usuario'    => $recarga['usuario'],
            'coins'      => (int)$recarga['coins'],
            'referencia' => $recarga['referencia'],
            'recarga_id' => (int)$recarga['id'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rl_asignar_manual: ' . $e->getMessage());
        return ['resultado' => 'error', 'error' => 'No se pudo asignar.'];
    }
}

/**
 * Acredita un comprobante A UN JUGADOR, sin recarga de por medio.
 *
 * POR QUE HACE FALTA
 * rl_asignar_manual() necesita una recarga a la que colgar el pago. Pero hay
 * transferencias que NO tienen ninguna y nunca la van a tener:
 *
 *   - Las del camino A (boton "Depositos" de la plataforma): la solicitud vive
 *     del lado de ganamos y no crea fila en `recargas`. Por construccion caen
 *     siempre en 'revision', y son la mayoria de las que se acumularon.
 *   - El jugador que transfiere sin pedir nada por el chat.
 *
 * Para esas, el operador solo podia cargar fichas a mano desde la ficha del
 * jugador. Eso acredita, pero deja el pago en 'revision' PARA SIEMPRE (sigue
 * inflando el badge, y se puede volver a acreditar por error) y no aprende la
 * huella del pagador. Esta funcion hace las dos cosas que faltaban.
 *
 * Es la version sin recarga de rl_acreditar(): mismos efectos, misma
 * transaccion, sin `recargas`. No abre transaccion propia -- la abre el caller,
 * igual que en el resto de la libreria.
 *
 * @return array ['resultado'=>'acreditada'|'error', ...]
 */
function rl_acreditar_directo(PDO $pdo, string $idUnico, string $usuario,
                              int $coins, string $operador): array
{
    $usuario = trim($usuario);
    if ($usuario === '' || $coins <= 0) {
        return ['resultado' => 'error', 'error' => 'Faltan el jugador o el monto.'];
    }

    $pdo->beginTransaction();
    try {
        // El pago tiene que seguir sin usar. FOR UPDATE: dos operadores
        // resolviendo el mismo comprobante a la vez lo acreditarian dos veces.
        $pg = $pdo->prepare(
            "SELECT * FROM pagos
              WHERE id_unico = ? AND estado IN ('pendiente','revision') FOR UPDATE"
        );
        $pg->execute([$idUnico]);
        $pago = $pg->fetch();
        if (!$pago) {
            $pdo->rollBack();
            return ['resultado' => 'error',
                    'error' => 'Ese comprobante ya no está sin resolver (puede que ya se haya acreditado).'];
        }

        // Misma proteccion que en rl_asignar_manual: si una carga pedida desde
        // la plataforma ya reservo esta transferencia, acreditarla aca la
        // pagaria dos veces cuando el worker apruebe la solicitud.
        try {
            $pt = $pdo->prepare(
                "SELECT request_id FROM peticiones_carga
                  WHERE pago_id_unico = ? AND estado IN ('esperando','revision') FOR UPDATE"
            );
            $pt->execute([$idUnico]);
            if ($req = $pt->fetchColumn()) {
                $pdo->rollBack();
                return ['resultado' => 'error', 'error' =>
                    'Esa transferencia está reservada para la carga #' . $req .
                    ', que el jugador pidió desde la plataforma. Resolvela en «Cargas del panel».'];
            }
        } catch (PDOException $e) {
            // Sin migracion 48 no hay camino A. Se sigue.
        }

        $us = $pdo->prepare("SELECT username FROM usuarios WHERE username = ? LIMIT 1");
        $us->execute([$usuario]);
        if (!$us->fetchColumn()) {
            $pdo->rollBack();
            return ['resultado' => 'error', 'error' => 'No existe el jugador «' . $usuario . '».'];
        }

        $pdo->prepare("UPDATE usuarios SET coins = coins + ? WHERE username = ?")
            ->execute([$coins, $usuario]);

        // Lo que faltaba cuando esto se hacia con "cargar fichas": el pago
        // queda consumido y no puede volver a acreditarse.
        $pdo->prepare(
            "UPDATE pagos SET estado='usado', asignado_por=?, asignado_en=NOW()
              WHERE id_unico = ?"
        )->execute([$operador, $idUnico]);

        try {
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'ficha', ?, ?, 'recarga')"
            )->execute([
                mb_substr($usuario, 0, 50), $coins,
                'Comprobante ' . mb_substr($idUnico, 0, 40) . ' acreditado a mano',
            ]);
        } catch (Throwable $e) {
            error_log('rl_acreditar_directo: no pude registrar el movimiento: ' . $e->getMessage());
        }

        // Y lo otro que faltaba: aprender de que cuenta paga. Cada comprobante
        // que el operador resuelve hoy es uno que se resuelve solo la proxima.
        rl_aprender_huella($pdo, $usuario, $pago);

        if (function_exists('actividad_marcar')) { actividad_marcar($pdo, $usuario); }

        /* Bono de bienvenida: esto ES una acreditacion del camino B (plata
           real por transferencia), solo que resuelta a mano y sin fila en
           `recargas` -- y por eso se lo salteaba: la primera carga de un
           jugador de landing resuelta por aca no daba bono nunca, y la
           SEGUNDA (por recarga normal) lo cobraba sobre el monto equivocado.
           Mismo criterio de "primera" que rl_acreditar -- la misma funcion,
           de hecho (este camino no crea filas en `recargas`, asi que el
           candado de una-sola-vez del helper es el que evita el doble pago
           entre caminos y entre dos directos seguidos). */
        $esPrimera = rl_es_primera_carga($pdo, $usuario);
        $bono = 0;
        $refPago = null;
        if ($esPrimera === 1) {
            $bono = rl_bono_bienvenida_aplicar($pdo, $usuario, $coins);
        }
        /* Referidos: FUERA del gate es_primera, igual que en rl_acreditar()
           (si la primera carga fue por el camino A, con el gate no cobraba
           nunca). El candado UNIQUE garantiza un solo pago; el deposito al
           juego del referidor sale post-commit via rl_cargar_al_juego_auto. */
        if (function_exists('ref_pagar_por_primera_carga')) {
            $refQuien = null;
            $refMonto = ref_pagar_por_primera_carga($pdo, $usuario, $refQuien);
            if ($refMonto > 0 && $refQuien !== null && $refQuien !== '') {
                $refPago = ['usuario' => $refQuien, 'monto' => $refMonto];
            }
        }

        /* El bono PROMETIDO (fidelizacion o promesa manual): este camino se
           lo salteaba -- un comprobante resuelto a mano no aplicaba el bono
           pendiente nunca, y quedaba esperando una recarga automatica que
           quizas no llegaba mas. Mismo enganche que rl_acreditar; recarga_id
           0 porque aca no hay fila de `recargas`. */
        if (function_exists('crmnotif_bono_aplicar_en_recarga')) {
            $bono += max(0, crmnotif_bono_aplicar_en_recarga($pdo, $usuario, 0, $coins));
        }

        $pdo->commit();

        /* Despues del commit, igual que en los otros caminos: encolar la carga
           al juego primero y avisar despues. Si el aviso saliera antes y el
           encolado fallara, le habriamos dicho "ya tenes tus fichas" a alguien
           que no las va a recibir. El bono viaja para que el deposito lo
           incluya, igual que en rl_acreditar(). */
        $comoRecarga = ['usuario' => $usuario, 'coins' => $coins,
                        'referencia' => 'manual', 'id' => 0, 'bono' => $bono,
                        // Para la invitacion a la app del aviso (solo primera carga).
                        'es_primera' => $esPrimera];
        // El bono del referidor (si esta primera carga lo gano): al juego
        // post-commit, igual que en el camino automatico.
        if (!empty($refPago)) { $comoRecarga['ref_pago'] = $refPago; }
        rl_cargar_al_juego_auto($pdo, $comoRecarga);
        rl_notificar_acreditada($pdo, $comoRecarga);

        return ['resultado' => 'acreditada', 'usuario' => $usuario, 'coins' => $coins];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('rl_acreditar_directo: ' . $e->getMessage());
        return ['resultado' => 'error', 'error' => 'No se pudo acreditar.'];
    }
}
