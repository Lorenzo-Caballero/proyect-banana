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

// =====================  EDITA ESTO  =======================================
const RL_COINS_POR_PESO = 1;        // 1 coin = 1 peso  (5000 coins => $5000)
const RL_MIN_COINS      = 100;      // minimo por recarga
const RL_MAX_COINS      = 1000000;  // maximo por recarga
const RL_VENCIMIENTO_MIN = 45;      // minutos que vive una recarga sin pagar
const RL_VENTANA_MIN     = 120;     // ventana para el match de respaldo (entero)
const RL_MAX_PENDIENTES_USUARIO = 5;// recargas pendientes simultaneas por usuario

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
function rl_cuenta_cobro(): array
{
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
 * Cual cuenta usar para ESTA recarga, respetando cobro_modo:
 *
 *   'azar' (default)  -> una al azar entre las activas, como siempre.
 *   'fija'             -> siempre la marcada en cobro_fija_id (0/NULL = la
 *                         principal). Si esa cuenta ya no esta activa (se
 *                         pauso o se borro), cae a azar SOLA -- fail-safe:
 *                         una configuracion vieja nunca deja a un jugador
 *                         sin poder cargar.
 */
function rl_cuenta_elegida(): array
{
    $cuentas = rl_cuentas_cobro();
    $c = rl_cliente_actual();
    $modo = (string)($c['cobro_modo'] ?? 'azar');

    if ($modo === 'fija') {
        $fijaId = $c['cobro_fija_id'] ?? null;
        $fijaId = ($fijaId === null || $fijaId === '') ? 0 : (int)$fijaId;
        foreach ($cuentas as $cta) {
            if ((int)$cta['id'] === $fijaId) {
                return $cta;
            }
        }
        // La fija no esta entre las activas (pausada/borrada): sigue de largo
        // al azar de abajo, a proposito -- nunca frenar una recarga por esto.
    }

    return $cuentas[array_rand($cuentas)];
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
function rl_crear_recarga(PDO $pdo, string $usuario, int $coins): array
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
        $ins = $pdo->prepare(
            "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido, centavos,
                                   estado, creada_en, vence_en)
             VALUES (?,?,?,?,?,?, 'pendiente', NOW(), DATE_ADD(NOW(), INTERVAL " . RL_VENCIMIENTO_MIN . " MINUTE))"
        );
        $ref = '';
        for ($intento = 0; $intento < 5; $intento++) {
            $ref = rl_referencia();
            try {
                $ins->execute([$ref, $usuario, $coins, $montoBase, $montoPedido, $cent]);
                break;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) == 1062 && $intento < 4) {
                    continue;   // referencia repetida, probamos otra
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
        // Un alias vacio no viaja: si viajara, el modelo del chatbot lo
        // repetiria tal cual ("alias: ") o, peor, inventaria uno.
        if (($r['alias'] ?? '') === '') { unset($r['alias']); }
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
function rl_acreditar(PDO $pdo, array &$recarga, string $idUnico, string $conf, ?string $operador = null): void
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

    $pdo->prepare(
        "UPDATE recargas SET estado='acreditada', pago_id=?, acreditada_en=NOW(), mensaje=?, es_primera=?
          WHERE id=?"
    )->execute([$idUnico, $conf, $esPrimera, $recarga['id']]);

    $pdo->prepare(
        "UPDATE usuarios SET coins = coins + ? WHERE username = ?"
    )->execute([(int)$recarga['coins'], $recarga['usuario']]);

    $pdo->prepare(
        "UPDATE pagos
            SET estado='usado', recarga_id=?, asignado_por=?,
                asignado_en=IF(? IS NOT NULL, NOW(), NULL)
          WHERE id_unico=?"
    )->execute([$recarga['id'], $operador, $operador, $idUnico]);

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

/** Casa el pago con una recarga pendiente y acredita, todo atomico. */
function rl_matchear_y_acreditar(PDO $pdo, string $idUnico, float $monto): array
{
    $pdo->beginTransaction();
    try {
        rl_vencer($pdo);
        $centTarget = (int)round($monto * 100);

        // Capa 1: monto EXACTO (centavos unicos). Es el camino normal.
        $q = $pdo->prepare(
            "SELECT * FROM recargas
              WHERE estado='pendiente' AND ROUND(monto_pedido*100)=?
              ORDER BY creada_en LIMIT 2 FOR UPDATE"
        );
        $q->execute([$centTarget]);
        $cands = $q->fetchAll();

        $recarga = null;
        $conf = '';
        if (count($cands) === 1) {
            $recarga = $cands[0];
            $conf = 'monto exacto';
        } elseif (count($cands) === 0) {
            // Capa 2 (respaldo): la billetera trunco decimales. Match por parte
            // entera dentro de la ventana, SOLO si hay una sola candidata.
            $q2 = $pdo->prepare(
                "SELECT * FROM recargas
                  WHERE estado='pendiente' AND FLOOR(monto_pedido)=?
                    AND creada_en >= DATE_SUB(NOW(), INTERVAL " . RL_VENTANA_MIN . " MINUTE)
                  ORDER BY creada_en LIMIT 2 FOR UPDATE"
            );
            $q2->execute([(int)floor($monto)]);
            $c2 = $q2->fetchAll();
            if (count($c2) === 1) {
                $recarga = $c2[0];
                $conf = 'monto entero unico';
            }
        }

        if (!$recarga) {
            // Ninguna o ambiguas: a revision manual, nunca adivina.
            $pdo->prepare("UPDATE pagos SET estado='revision' WHERE id_unico=?")->execute([$idUnico]);
            $pdo->commit();
            return ['resultado' => 'revision', 'mensaje' => 'sin recarga unica que coincida'];
        }

        rl_acreditar($pdo, $recarga, $idUnico, $conf);
        $pdo->commit();

        // Recien despues del commit: si el aviso saliera adentro de la
        // transaccion y esta hiciera rollback, quedaria anunciada una recarga
        // que nunca se acredito. Mismo motivo para el Purchase de Meta.
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

        rl_acreditar($pdo, $recarga, $idUnico, 'manual: ' . $operador, $operador);
        $pdo->commit();

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
