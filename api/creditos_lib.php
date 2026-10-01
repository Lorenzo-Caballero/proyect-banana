<?php
/**
 * creditos_lib.php — Créditos prepagos: el cajero paga un % de cada carga.
 *
 * EL MODELO (decisión del dueño, 29/09/2026, para poder vender el servicio):
 * el cliente carga créditos en pesos transfiriendo USDT, y de ese saldo se le
 * descuenta un 2% de cada carga que procesa. Cuando se queda sin créditos se
 * le bloquea el CRM — el mismo mecanismo de la suscripción (migración 04), que
 * deja andando el bot, el chatbot y la plataforma de sus jugadores: el que
 * tiene que resolver es él, y sus jugadores no se enteran.
 *
 * ============================================================================
 * EL COBRO NO SE ENGANCHA EN LOS CAMINOS DE ACREDITACIÓN, Y ESA ES LA DECISIÓN
 * DE DISEÑO QUE SOSTIENE TODO LO DEMÁS.
 *
 * La plata entra por dos caminos que no comparten nada (transferencia del
 * chatbot y botón «Depósitos» de la plataforma), más la carga a mano del CRM.
 * Cobrar en cada uno serían tres puntos que hay que mantener sincronizados, y
 * CLAUDE.md ya cuenta lo que pasa cuando algo mide la plata por su cuenta:
 * Publicidad mostró cero conversiones y Finanzas se comió un 10% del negocio,
 * las dos veces por contar solo `recargas`.
 *
 * Acá se cobra en DIFERIDO leyendo `publicidad_sql_cargas()`, que es la
 * definición única de «una carga» y ya la usan Publicidad, Finanzas y el
 * indicador de salud. Eso da cuatro cosas de arriba:
 *
 *   - una sola definición, la que ya está probada en producción;
 *   - no se toca el camino por el que el jugador recibe su plata: cobrarle al
 *     cajero NUNCA puede hacer fallar una acreditación;
 *   - si mañana aparece un tercer camino de carga, queda cubierto solo;
 *   - y es idempotente: la identidad de una carga es (via, referencia), con
 *     UNIQUE en `consumos_plataforma`, así que el cron puede reenviar la
 *     ventana entera sin cobrar dos veces.
 *
 * El precio es que el saldo baja con unos minutos de atraso. No importa: no
 * hay nada que cortar al segundo.
 * ============================================================================
 */

declare(strict_types=1);

/** Cuánto se cobra por cada carga, si el cliente no tiene un valor propio. */
const CRED_COMISION_PCT_DEFAULT = 2.00;

/** Ventana que el cron reenvía en cada pasada. Una pasada perdida se recupera
 *  sola, sin estado que mantener: mismo criterio que el libro de operaciones.
 *  El UNIQUE es lo que hace que reenviar sea gratis. */
const CRED_VENTANA_HORAS = 72;

/**
 * La fila del cliente en el plano de control, o null.
 *
 * SIN `LIMIT 1`, por lo mismo que `panel_credenciales` en acciones_cola.php:
 * si dos clientes activos comparten `db_nombre` —que pasó dos veces, el 24 y
 * el 29/09— un LIMIT 1 elegiría uno al azar y le cobraría a un cliente las
 * cargas de otro. Con ambigüedad no se cobra: deber una comisión se arregla
 * con un ajuste; cobrársela al que no era, no.
 */
function cred_cliente(PDO $ctl, string $dbNombre): ?array
{
    if ($dbNombre === '') { return null; }
    $st = $ctl->prepare(
        "SELECT id, slug, nombre, cobro_modelo, creditos_ars, comision_pct,
                aviso_umbral_ars, creditos_desde, aviso_saldo_en, suscripcion_estado
           FROM clientes
          WHERE db_nombre = ? AND estado = 'activo'"
    );
    $st->execute([$dbNombre]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) !== 1) {
        if (count($filas) > 1) {
            error_log('creditos: ' . count($filas) . " clientes activos comparten $dbNombre: no se cobra");
        }
        return null;
    }
    return $filas[0];
}

/** El % que se le cobra a este cliente. */
function cred_pct(array $cliente): float
{
    $p = (float)($cliente['comision_pct'] ?? 0);
    return $p > 0 ? $p : CRED_COMISION_PCT_DEFAULT;
}

/**
 * Cobra las cargas de UN cliente que todavía no se cobraron.
 *
 * `$pdoCliente` es la base del cliente (de donde salen las cargas) y `$ctl` el
 * plano de control (donde vive el saldo). Devuelve un resumen.
 *
 * LAS CARGAS ANTERIORES A `creditos_desde` NO SE COBRAN. Sin ese corte,
 * activarle el modelo a un cliente con historial le vaciaría el saldo de una
 * con cargas de hace meses que nunca acordó pagar — y la primera impresión del
 * producto sería un robo.
 */
function cred_cobrar_cliente(PDO $ctl, PDO $pdoCliente, array $cliente): array
{
    $res = ['cargas' => 0, 'cobrado' => 0.0, 'saldo' => (float)$cliente['creditos_ars']];
    if (($cliente['cobro_modelo'] ?? '') !== 'transaccion') {
        $res['motivo'] = 'no esta en el modelo por transaccion';
        return $res;
    }

    $desde = (string)($cliente['creditos_desde'] ?? '');
    if ($desde === '') {
        $res['motivo'] = 'sin creditos_desde: no se cobra nada hasta fijarlo';
        return $res;
    }
    // La ventana arranca en el mayor entre "desde cuándo se le cobra" y la
    // ventana móvil: no tiene sentido releer un año de historia cada 5 min.
    $ini = max(strtotime($desde), time() - CRED_VENTANA_HORAS * 3600);

    require_once __DIR__ . '/publicidad_lib.php';
    $sql = publicidad_sql_cargas();
    $st = $pdoCliente->prepare(
        "SELECT usuario, cuando, monto, via, referencia FROM ($sql) c
          WHERE c.cuando >= ? ORDER BY c.cuando ASC"
    );
    $st->execute([date('Y-m-d H:i:s', $ini)]);
    $cargas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$cargas) { return $res; }

    $pct = cred_pct($cliente);
    $ins = $ctl->prepare(
        "INSERT IGNORE INTO consumos_plataforma
           (cliente_id, via, referencia, usuario, monto_carga, comision_pct, comision_ars, cuando)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $cid = (int)$cliente['id'];

    foreach ($cargas as $c) {
        $monto = (float)$c['monto'];
        if ($monto <= 0) { continue; }
        /* La referencia puede venir vacía en una carga vieja. Sin identidad no
           hay idempotencia, así que se arma una estable con lo que hay: dos
           pasadas sobre la misma carga tienen que producir la misma clave. */
        $ref = trim((string)($c['referencia'] ?? ''));
        if ($ref === '') {
            $ref = 'auto:' . substr(sha1($c['via'] . '|' . $c['usuario'] . '|' . $c['cuando'] . '|' . $monto), 0, 24);
        }
        $com = round($monto * $pct / 100, 2);
        $ins->execute([$cid, (string)$c['via'], $ref, (string)$c['usuario'],
                       $monto, $pct, $com, (string)$c['cuando']]);
        // INSERT IGNORE: si ya estaba cobrada, rowCount() es 0 y no se descuenta.
        if ($ins->rowCount() > 0) {
            $res['cargas']++;
            $res['cobrado'] += $com;
        }
    }

    if ($res['cobrado'] > 0) {
        $ctl->prepare('UPDATE clientes SET creditos_ars = creditos_ars - ? WHERE id = ?')
            ->execute([$res['cobrado'], $cid]);
        $q = $ctl->prepare('SELECT creditos_ars FROM clientes WHERE id = ?');
        $q->execute([$cid]);
        $res['saldo'] = (float)$q->fetchColumn();
    }
    return $res;
}

/**
 * Aplica el estado que corresponde al saldo: avisa si está bajo, bloquea si se
 * acabó, y DESBLOQUEA si volvió a cargar.
 *
 * El desbloqueo va acá y no solo en la acreditación a propósito: si viviera
 * únicamente donde se acredita, un ajuste hecho a mano en la base dejaría al
 * cliente bloqueado con saldo, que es la clase de estado que nadie entiende
 * después. Acá se recalcula de lo que hay.
 */
function cred_aplicar_estado(PDO $ctl, array $cliente, float $saldo): string
{
    $cid    = (int)$cliente['id'];
    $estado = (string)$cliente['suscripcion_estado'];

    if ($saldo <= 0) {
        if ($estado !== 'sin_saldo') {
            $ctl->prepare("UPDATE clientes SET suscripcion_estado = 'sin_saldo' WHERE id = ?")->execute([$cid]);
            cred_avisar($cliente, 'sin_creditos', $saldo);
        }
        return 'sin_saldo';
    }

    if ($estado === 'sin_saldo') {
        $ctl->prepare("UPDATE clientes SET suscripcion_estado = 'activa' WHERE id = ?")->execute([$cid]);
        return 'activa';
    }

    /* AVISO DE SALDO BAJO, una vez por día como mucho. Un aviso que repite en
       cada pasada del cron se aprende a ignorar en dos días, y entonces no
       sirve para el único momento en que importa. */
    $umbral = (float)($cliente['aviso_umbral_ars'] ?? 0);
    $ultimo = (string)($cliente['aviso_saldo_en'] ?? '');
    if ($umbral > 0 && $saldo <= $umbral
        && ($ultimo === '' || strtotime($ultimo) < time() - 86400)) {
        $ctl->prepare('UPDATE clientes SET aviso_saldo_en = NOW() WHERE id = ?')->execute([$cid]);
        cred_avisar($cliente, 'saldo_bajo', $saldo);
    }
    return $estado;
}

/** Aviso al dueño por Telegram. Best-effort: nunca frena el cobro. */
function cred_avisar(array $cliente, string $tipo, float $saldo): void
{
    try {
        if (!is_file(__DIR__ . '/telegram_lib.php')) { return; }
        require_once __DIR__ . '/telegram_lib.php';
        if (!function_exists('tg_evento')) { return; }
        $quien = (string)($cliente['nombre'] ?? $cliente['slug'] ?? ('cliente ' . $cliente['id']));
        $clave = (string)($cliente['slug'] ?? $cliente['id']);
        $plata = '$' . number_format($saldo, 2, ',', '.');
        if ($tipo === 'sin_creditos') {
            tg_evento(null, 'creditos_agotados', '🔴 Un cliente se quedó sin créditos', [
                'Cliente'     => $quien,
                'Saldo'       => $plata,
                'Qué pasa'    => 'Se le bloqueó el CRM. El bot, el chatbot y la plataforma de sus '
                               . 'jugadores siguen andando: sus jugadores no se enteran.',
                'Se destraba' => 'Cuando cargue créditos, o con un ajuste desde tu panel.',
            ], 'creditos_agotados:' . $clave);
        } else {
            tg_evento(null, 'creditos_bajos', '🟡 Un cliente se está quedando sin créditos', [
                'Cliente' => $quien,
                'Saldo'   => $plata,
                'Aviso'   => 'Todavía opera normal. Al llegar a cero se le bloquea el CRM.',
            ], 'creditos_bajos:' . $clave);
        }
    } catch (Throwable $e) { error_log('cred_avisar: ' . $e->getMessage()); }
}

/**
 * Le suma créditos a un cliente y deja el rastro. Es el ÚNICO lugar que suma:
 * lo usan la acreditación de una recarga USDT y el ajuste manual del panel.
 */
function cred_acreditar(PDO $ctl, int $clienteId, float $ars, string $motivo, string $quien = 'sistema'): float
{
    $ctl->prepare('UPDATE clientes SET creditos_ars = creditos_ars + ? WHERE id = ?')
        ->execute([$ars, $clienteId]);
    try {
        /* `delta_usd` y `operador` son los nombres REALES de la migración 04
           (los usa panel.php en saldo_ajustar). Se guarda 0 en delta_usd
           porque esto mueve PESOS y esa columna es de la suscripción en
           dólares: el monto en pesos va en el motivo, que es texto libre.
           Mezclar las dos unidades en la misma columna daría un histórico que
           suma dólares con pesos y no significa nada. */
        $ctl->prepare(
            'INSERT INTO ajustes_saldo_plataforma (cliente_id, delta_usd, motivo, operador)
             VALUES (?,?,?,?)'
        )->execute([$clienteId, 0, mb_substr($motivo, 0, 240), mb_substr($quien, 0, 60)]);
    } catch (Throwable $e) { /* la tabla puede tener otra forma: el saldo ya se sumó */ }

    $q = $ctl->prepare('SELECT creditos_ars, suscripcion_estado FROM clientes WHERE id = ?');
    $q->execute([$clienteId]);
    $f = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $saldo = (float)($f['creditos_ars'] ?? 0);

    /* DESBLOQUEO INMEDIATO al acreditar. El gate de crm_auth.php cachea el
       "sin saldo" unos minutos en la sesión, así que el estado tiene que
       quedar bien en el acto: si no, el cliente paga, ve el CRM igual de
       bloqueado y lo natural es que vuelva a pagar. */
    if ($saldo > 0 && ($f['suscripcion_estado'] ?? '') === 'sin_saldo') {
        $ctl->prepare("UPDATE clientes SET suscripcion_estado = 'activa' WHERE id = ?")->execute([$clienteId]);
    }
    return $saldo;
}
