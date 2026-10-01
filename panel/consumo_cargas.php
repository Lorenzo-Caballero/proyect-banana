<?php
/**
 * consumo_cargas.php — Le cobra a cada cliente el % de las cargas que procesó.
 *
 * El otro modelo de facturación, al lado de consumo_diario.php (suscripción
 * por día). Un cliente está en UNO de los dos según `clientes.cobro_modelo`;
 * este solo toca los que están en 'transaccion'.
 *
 * Cron sugerido, cada 5 minutos:
 *   *\/5 * * * * php /opt/goldpaw/panel/consumo_cargas.php >> /var/log/goldpaw-creditos.log 2>&1
 *
 * ============================================================================
 * REENVÍA UNA VENTANA, NO SOLO LO NUEVO, y no lleva estado de "hasta dónde
 * cobré". Una pasada perdida se recupera sola en la siguiente. Eso se puede
 * hacer únicamente porque cobrar dos veces la misma carga es imposible: la
 * identidad es (cliente, via, referencia) con UNIQUE en `consumos_plataforma`
 * y el INSERT es IGNORE. Es el mismo criterio del libro de operaciones, y por
 * la misma razón: el estado que hay que mantener al día es el que se pudre.
 *
 * SI FALLA UN CLIENTE, SIGUE CON LOS DEMÁS. Una base caída o un cliente mal
 * configurado no puede frenar la facturación de todos los otros.
 * ============================================================================
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/panel_config.php';
require_once __DIR__ . '/../api/creditos_lib.php';

function log_línea(string $m): void { echo '[' . date('c') . '] ' . $m . "\n"; }

try {
    $ctl = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, '[' . date('c') . "] no pude conectar a la maestra\n");
    exit(1);
}

try {
    $clientes = $ctl->query(
        "SELECT id, slug, nombre, db_nombre, cobro_modelo, creditos_ars, comision_pct,
                aviso_umbral_ars, creditos_desde, aviso_saldo_en, suscripcion_estado,
                trial_hasta
           FROM clientes
          WHERE estado = 'activo' AND cobro_modelo = 'transaccion'
            AND db_nombre IS NOT NULL AND db_nombre <> ''
          ORDER BY id"
    )->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] sin migración 11: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$clientes) { log_línea('no hay clientes en el modelo por transacción'); exit(0); }

/* DOS CLIENTES CON LA MISMA BASE NO SE FACTURAN. Pasó dos veces (24 y
   29/09/2026) y acá el daño sería cobrarle a los dos las cargas de uno.
   cred_cliente() ya se niega ante la ambigüedad; esto lo resuelve de una para
   toda la pasada y deja dicho por qué en el log. */
$vistas = [];
foreach ($clientes as $c) { $vistas[strtolower((string)$c['db_nombre'])][] = $c['slug']; }

$totCargas = 0; $totCobrado = 0.0;

foreach ($clientes as $c) {
    $slug = (string)$c['slug'];
    $db   = strtolower((string)$c['db_nombre']);

    if (count($vistas[$db]) > 1) {
        log_línea("$slug: NO se factura, comparte la base $db con " . implode(', ', $vistas[$db]));
        continue;
    }

    try {
        $pc = new PDO(
            "mysql:host={$cfg['DB_HOST']};dbname={$c['db_nombre']};charset=utf8mb4",
            $cfg['DB_USER'], $cfg['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $r = cred_cobrar_cliente($ctl, $pc, $c);

        if ($r['cargas'] > 0) {
            $totCargas  += $r['cargas'];
            $totCobrado += $r['cobrado'];
            log_línea(sprintf('%s: %d carga(s), se cobró $%s, saldo $%s',
                $slug, $r['cargas'], number_format($r['cobrado'], 2, ',', '.'),
                number_format($r['saldo'], 2, ',', '.')));
        } elseif (isset($r['motivo'])) {
            log_línea("$slug: " . $r['motivo']);
        }

        /* El estado se recalcula SIEMPRE, haya cobrado o no: es lo que
           desbloquea al que cargó créditos y lo que avisa al que se está
           quedando corto sin haber operado hoy. */
        cred_aplicar_estado($ctl, $c, $r['saldo'], $c['trial_hasta'] ?? null);

    } catch (Throwable $e) {
        log_línea("$slug: ERROR -> " . $e->getMessage());
        continue;
    }
}

log_línea(sprintf('listo: %d carga(s) cobradas, $%s en total',
    $totCargas, number_format($totCobrado, 2, ',', '.')));

/* ===========================================================================
 * LAS RECARGAS QUE QUEDARON PENDIENTES.
 *
 * SIN ESTO EL CLIENTE PAGA Y NO SE ENTERA NADIE. Una transferencia queda
 * pendiente por dos motivos normales: la red todavía no la confirmó, o recién
 * se envió y la API aún no la indexó. En los dos casos la plata YA SALIÓ de la
 * billetera del cliente. Si nada las retoma, la fila se queda en 'pendiente'
 * para siempre, el saldo nunca sube, y del lado nuestro no se rompe nada: es
 * exactamente la clase de fallo silencioso que CLAUDE.md persigue.
 *
 * Se reintenta consultando la cadena de nuevo. Es una LECTURA: repetirla no
 * puede acreditar dos veces --lo impide el UNIQUE del txid-- así que reintentar
 * es gratis, igual que en las lecturas del colector.
 * =========================================================================== */
const PEND_HORAS_REVISION = 24;   // sin aparecer después de esto, lo mira una persona

try {
    $pend = $ctl->query(
        "SELECT r.id, r.txid, r.cliente_id, r.creado, c.slug
           FROM recargas_usdt r JOIN clientes c ON c.id = r.cliente_id
          WHERE r.estado = 'pendiente' AND r.creado >= NOW() - INTERVAL 7 DAY
          ORDER BY r.id LIMIT 50"
    )->fetchAll();
} catch (Throwable $e) {
    $pend = [];
}

if ($pend) {
    require_once __DIR__ . '/../api/usdt_lib.php';

    $cfgp = function (string $k, string $d = '') use ($ctl) {
        try {
            $q = $ctl->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
            $q->execute([$k]);
            $v = $q->fetchColumn();
            return (is_string($v) && $v !== '') ? $v : $d;
        } catch (Throwable $e) { return $d; }
    };
    $wallet = $cfgp('usdt_wallet');
    $cotiz  = (float)$cfgp('usdt_cotizacion_ars', '0');
    $minCf  = (int)$cfgp('usdt_min_confirmaciones', (string)USDT_CONFIRMACIONES_MIN);

    foreach ($pend as $p) {
        if ($wallet === '' || $cotiz <= 0) {
            log_línea('recargas pendientes: falta billetera o cotización, no se procesan');
            break;
        }
        $v = usdt_verificar((string)$p['txid'], $wallet, $minCf);

        if ($v['estado'] === 'ok') {
            /* LA COTIZACIÓN ES LA DE HOY, NO LA DEL DÍA QUE LA PRESENTÓ, y es
               la decisión correcta aunque parezca al revés: el crédito se
               entrega ahora. Queda guardada en la fila, así que la recarga se
               puede explicar con el número que se usó. */
            $ars = round($v['monto'] * $cotiz, 2);
            $ctl->prepare(
                "UPDATE recargas_usdt
                    SET estado='acreditada', monto_usdt=?, cotizacion=?, monto_ars=?,
                        destino=?, confirmaciones=?, raw=?, acreditado_en=NOW(), motivo=NULL
                  WHERE id = ? AND estado = 'pendiente'"
            )->execute([$v['monto'], $cotiz, $ars, $v['destino'], $v['confirmaciones'], $v['raw'], $p['id']]);

            /* El WHERE estado='pendiente' del UPDATE es la guarda: si dos
               pasadas del cron se pisan, la segunda no encuentra la fila en ese
               estado y no acredita de nuevo. Se relee para saber cuál ganó. */
            $chk = $ctl->prepare("SELECT estado FROM recargas_usdt WHERE id = ?");
            $chk->execute([$p['id']]);
            if ($chk->fetchColumn() === 'acreditada') {
                $saldo = cred_acreditar($ctl, (int)$p['cliente_id'], $ars,
                    'recarga USDT confirmada (tx ' . substr((string)$p['txid'], 0, 12) . '…)', 'cron');
                log_línea(sprintf('%s: recarga confirmada, +$%s -> saldo $%s',
                    $p['slug'], number_format($ars, 2, ',', '.'), number_format($saldo, 2, ',', '.')));
            }

        } elseif ($v['estado'] === 'pendiente') {
            // Sigue esperando. Si ya lleva demasiado, que lo mire una persona.
            if (strtotime((string)$p['creado']) < time() - PEND_HORAS_REVISION * 3600) {
                $ctl->prepare("UPDATE recargas_usdt SET estado='revision', motivo=? WHERE id = ?")
                    ->execute(['sigue sin confirmar después de ' . PEND_HORAS_REVISION . ' h: ' . $v['motivo'], $p['id']]);
                log_línea($p['slug'] . ': recarga a revisión (' . $v['motivo'] . ')');
            }
        } else {
            $ctl->prepare("UPDATE recargas_usdt SET estado=?, motivo=?, raw=? WHERE id = ?")
                ->execute([$v['estado'], mb_substr($v['motivo'], 0, 240), $v['raw'], $p['id']]);
            log_línea($p['slug'] . ': recarga ' . $v['estado'] . ' (' . $v['motivo'] . ')');
        }
    }
}
