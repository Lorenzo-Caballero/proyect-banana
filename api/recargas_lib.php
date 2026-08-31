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
 *                             EXACTO (con centavos unicos) a transferir.
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

// =====================  EDITA ESTO  =======================================
const RL_COINS_POR_PESO = 1;        // 1 coin = 1 peso  (5000 coins => $5000)
const RL_MIN_COINS      = 100;      // minimo por recarga
const RL_MAX_COINS      = 1000000;  // maximo por recarga
const RL_VENCIMIENTO_MIN = 45;      // minutos que vive una recarga sin pagar
const RL_VENTANA_MIN     = 120;     // ventana para el match de respaldo (entero)
const RL_MAX_PENDIENTES_USUARIO = 5;// recargas pendientes simultaneas por usuario

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
// DKIM) -- ver colector/config.json. Sin alias por ahora: vacio significa
// "no compartir alias", nunca inventar uno.
const RL_ALIAS   = '';
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
            'SELECT id, metodo_cobro, cobro_alias, cobro_cbu, cobro_titular,
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


/** Centavos libres (1..99) dado el set de ocupados. Devuelve int o null. */
function rl_elegir_centavo(array $ocupados): ?int
{
    $libres = array_values(array_diff(range(1, 99), array_map('intval', $ocupados)));
    if (!$libres) {
        return null;
    }
    return $libres[random_int(0, count($libres) - 1)];
}

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
function rl_crear_recarga(PDO $pdo, string $usuario, int $coins, string $titular = ''): array
{
    $usuario = trim($usuario);
    if ($usuario === '') {
        return ['ok' => false, 'error' => 'Falta el nombre de usuario del juego.'];
    }
    if ($coins < RL_MIN_COINS || $coins > RL_MAX_COINS) {
        return ['ok' => false, 'error' =>
            'La cantidad debe estar entre ' . RL_MIN_COINS . ' y ' . RL_MAX_COINS . ' coins.'];
    }

    // El usuario tiene que existir en el panel de ganamos (tabla usuarios).
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' =>
            "El usuario '$usuario' no existe todavia. Primero hay que registrarse en el juego."];
    }

    $montoBase = (int)round($coins / RL_COINS_POR_PESO);   // pesos enteros

    $pdo->beginTransaction();
    try {
        rl_vencer($pdo);

        // Tope de pendientes por usuario, para que uno no acapare centavos.
        $c = $pdo->prepare("SELECT COUNT(*) FROM recargas WHERE usuario=? AND estado='pendiente'");
        $c->execute([$usuario]);
        if ((int)$c->fetchColumn() >= RL_MAX_PENDIENTES_USUARIO) {
            $pdo->rollBack();
            return ['ok' => false, 'error' =>
                'Ya tenes varias recargas pendientes. Termina o espera que venzan antes de crear otra.'];
        }

        // Centavos ocupados entre las pendientes del MISMO monto base.
        $q = $pdo->prepare(
            "SELECT centavos FROM recargas
              WHERE estado='pendiente' AND FLOOR(monto_base)=? AND centavos IS NOT NULL
              FOR UPDATE"
        );
        $q->execute([$montoBase]);
        $cent = rl_elegir_centavo($q->fetchAll(PDO::FETCH_COLUMN));
        if ($cent === null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' =>
                'Hay demasiadas recargas pendientes de ese monto. Proba con otra cantidad o espera unos minutos.'];
        }

        $montoPedido = $montoBase + $cent / 100;

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
        if (is_file(__DIR__ . '/hgcash_lib.php') && rl_metodo_cobro() === 'hgcash') {
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

    return [
        'ok'           => true,
        'encontrada'   => true,
        'referencia'   => $r['referencia'],
        'usuario'      => $r['usuario'],
        'coins'        => (int)$r['coins'],
        'monto_pedido' => $r['monto_pedido'],
        'estado'       => $r['estado'],   // pendiente|acreditada|vencida|cancelada|revision
        'saldo_actual' => $saldo !== false && $saldo !== null ? (int)$saldo : null,
    ];
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

    return array_merge(['ok' => true, 'nuevo' => true],
                       rl_matchear_y_acreditar($pdo, $idUnico, $monto));
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
function rl_acreditar(PDO $pdo, array &$recarga, string $idUnico, string $conf,
                       ?string $operador = null, ?string $confianza = null): void
{
    // Se resuelve ANTES del UPDATE de abajo: en ese momento la fila de esta
    // recarga todavia esta 'pendiente', asi que contar 'acreditada' previas
    // del mismo usuario da exactamente "cuantas cargas tenia ANTES de esta".
    // Se guarda en la fila (no se recalcula despues con MIN/subquery) para
    // que el modulo de Publicidad haga SUM/COUNT simples sobre es_primera en
    // vez de repetir este calculo cada vez que el operador abre el reporte.
    $esPrimera = null;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM recargas WHERE usuario = ? AND estado = 'acreditada'"
        );
        $st->execute([$recarga['usuario']]);
        $esPrimera = ((int)$st->fetchColumn() === 0) ? 1 : 0;
    } catch (Throwable $e) {
        error_log('rl_acreditar: no pude calcular es_primera: ' . $e->getMessage());
    }

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
    if (function_exists('crmnotif_bono_aplicar_en_recarga')) {
        crmnotif_bono_aplicar_en_recarga($pdo, (string)$recarga['usuario'], (int)$recarga['id'], (int)$recarga['coins']);
    }

    // es_primera calculado arriba viaja al caller a traves de $recarga -- lo
    // necesita rl_reportar_purchase(), que corre DESPUES del commit (ver esa
    // funcion para el porque).
    $recarga['es_primera'] = $esPrimera;
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
        $r = fichas_pedir_carga($pdo, (string)$recarga['usuario'], (int)$recarga['coins'], 'recarga');
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
}

/** Aviso de recarga acreditada. Mismo texto para el camino automatico y el
 *  manual: para el jugador es el mismo evento, no hay por que distinguirlo. */
function rl_notificar_acreditada(PDO $pdo, array $recarga): void
{
    if (!function_exists('notif_crear')) {
        return;
    }
    notif_crear(
        $pdo,
        (string)$recarga['usuario'],
        'Recarga acreditada',
        'Ya tenés tus ' . number_format((int)$recarga['coins'], 0, ',', '.')
            . ' fichas disponibles. ¡A jugar!',
        'recarga',
        null,
        'recargas'
    );
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
        if (count($cands) === 1) {
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

        $rc = $pdo->prepare("SELECT * FROM recargas WHERE id = ? AND estado = 'pendiente' FOR UPDATE");
        $rc->execute([$recargaId]);
        $recarga = $rc->fetch();
        if (!$recarga) {
            $pdo->rollBack();
            return ['resultado' => 'error', 'error' => 'Esa recarga ya no está pendiente.'];
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
