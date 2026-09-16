<?php
/**
 * conciliar_lib.php — Cerrar solas las acciones que el LIBRO dice que sí pasaron.
 *
 * EL PROBLEMA (16/09/2026). Cuando el WAF corta un depósito, el worker recibe
 * HTTP 200 con el HTML del challenge: no puede confirmar y marca `revisar`, que
 * es el lado seguro. Pero *"no pude confirmar"* no es *"no pasó"* — la request
 * pudo llegar igual, y de hecho llega. Dos acciones de holaDiego858 quedaron en
 * `revisar` con el depósito ya ejecutado en el panel.
 *
 * Esas filas no se cierran nunca. Ensucian la bandeja de lo que falta resolver
 * con cosas resueltas, y el costo real no es el ruido: es que el operador deja
 * de creerle a la bandeja y empieza a cargar a mano lo que ya entró — que es
 * exactamente cómo se acreditan 35.000 dos veces.
 *
 * ============================================================================
 * LA REGLA
 * ============================================================================
 * `operaciones_panel` es el registro de la PLATAFORMA: estar ahí es la prueba
 * de que la operación se ejecutó (CLAUDE.md). Si una acción en `revisar`/`error`
 * tiene su operación en el libro, se cierra como `hecha` y se anota cuál.
 *
 * **Solo cierra, nunca falla nada.** Que una operación NO esté en el libro no
 * se usa para marcar error: el libro tiene una ventana móvil y un backfill, y
 * una ausencia puede ser "todavía no se sincronizó". Cerrar de más se nota;
 * marcar un fracaso falso le saca la plata a alguien que la tiene.
 *
 * **Una operación del panel cierra UNA acción.** Lo garantiza el UNIQUE de
 * `acciones_saldo.payment_id` (migración 70), no este código: dos acciones del
 * mismo monto compiten por el mismo depósito, y cerrar las dos diría que entró
 * el doble. La segunda se queda abierta a propósito, con una nota que lo dice.
 *
 * **No toca `pendiente` ni `procesando`.** Esas están vivas: el worker puede
 * estar ejecutándolas en este instante.
 */

declare(strict_types=1);

/** Cuánto hacia atrás se concilia. Más que eso ya es historia, no bandeja. */
const CONC_DIAS = 7;

/**
 * La operación del panel tiene que caer DESPUÉS del pedido (con unos minutos de
 * gracia por el reloj) y no mucho después. La cola de abajo es ancha porque el
 * worker reintenta hasta 5 veces con espera en el medio, y el panel da la hora
 * al minuto.
 */
const CONC_GRACIA_ANTES_MIN = 10;
const CONC_VENTANA_DESPUES_MIN = 180;

/**
 * Concilia las acciones trabadas contra el libro.
 *
 * Devuelve ['ok', 'cerradas' => N, 'detalle' => [...]]. Nunca lanza: esto corre
 * en el loop del colector y un fallo acá no puede frenar los depósitos.
 */
function conc_conciliar(PDO $pdo, int $dias = CONC_DIAS): array
{
    $dias = max(1, min(90, $dias));
    $cerradas = 0;
    $detalle  = [];

    try {
        /* Solo lo que quedó trabado. 'pendiente' y 'procesando' quedan afuera:
           están vivas. */
        $st = $pdo->prepare(
            "SELECT id, usuario, tipo, monto, estado, creada_en, mensaje
               FROM acciones_saldo
              WHERE estado IN ('revisar', 'error')
                AND payment_id IS NULL
                AND creada_en >= NOW() - INTERVAL ? DAY
              ORDER BY creada_en ASC
              LIMIT 100"
        );
        $st->bindValue(1, $dias, PDO::PARAM_INT);
        $st->execute();
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Sin la migración 70 no hay columna: no se concilia nada y el resto
        // del worker sigue igual.
        return ['ok' => false, 'cerradas' => 0,
                'error' => 'sin columna payment_id (¿falta la migración 70?)'];
    }

    if (!$filas) { return ['ok' => true, 'cerradas' => 0, 'detalle' => []]; }

    /* La operación candidata. `tipo` cruzado: nuestra 'cargar' es un depósito
       (0) del panel y 'retirar' es un retiro (1). Cruzarlos al revés cerraría
       un retiro contra un depósito, que es la peor confusión posible acá.
       Se pide la MÁS VIEJA que sirva: si hubo dos, la primera es la que
       corresponde a este pedido. */
    $qOp = $pdo->prepare(
        "SELECT o.payment_id, o.monto, o.cuando
           FROM operaciones_panel o
          WHERE o.tipo = ?
            AND o.username = ?
            AND ROUND(o.monto * 100) = ?
            AND o.cuando BETWEEN (? - INTERVAL " . CONC_GRACIA_ANTES_MIN . " MINUTE)
                             AND (? + INTERVAL " . CONC_VENTANA_DESPUES_MIN . " MINUTE)
            AND NOT EXISTS (SELECT 1 FROM acciones_saldo a2
                             WHERE a2.payment_id = o.payment_id)
          ORDER BY o.cuando ASC
          LIMIT 1"
    );

    foreach ($filas as $f) {
        $tipoLibro = ((string)$f['tipo'] === 'retirar') ? 1 : 0;
        try {
            $qOp->execute([
                $tipoLibro,
                (string)$f['usuario'],
                (int)round(((float)$f['monto']) * 100),
                $f['creada_en'], $f['creada_en'],
            ]);
            $op = $qOp->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('conc_conciliar (buscar): ' . $e->getMessage());
            continue;
        }
        if (!$op) { continue; }

        /* El UPDATE es la carrera: si otra pasada tomó ese payment_id entre el
           SELECT y ahora, el UNIQUE lo rechaza y esta acción queda abierta --
           que es justo lo que se quiere. Por eso el catch NO es un error: es
           el mecanismo funcionando. */
        $nota = sprintf('conciliado con el libro del panel: %s del %s (pago %d)',
                        (string)$f['tipo'] === 'retirar' ? 'retiro' : 'deposito',
                        substr((string)$op['cuando'], 0, 16), (int)$op['payment_id']);
        $viejo = trim((string)($f['mensaje'] ?? ''));
        $msg = $viejo !== ''
             ? mb_substr($viejo, 0, max(0, 300 - mb_strlen($nota) - 3)) . ' | ' . $nota
             : $nota;

        try {
            $up = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'hecha', payment_id = ?, mensaje = ?,
                        ejecutada_en = COALESCE(ejecutada_en, ?)
                  WHERE id = ? AND estado IN ('revisar','error') AND payment_id IS NULL"
            );
            $up->execute([(int)$op['payment_id'], mb_substr($msg, 0, 300),
                          $op['cuando'], (int)$f['id']]);
            if ($up->rowCount() > 0) {
                $cerradas++;
                $detalle[] = [
                    'id' => (int)$f['id'], 'usuario' => (string)$f['usuario'],
                    'tipo' => (string)$f['tipo'], 'monto' => (float)$f['monto'],
                    'payment_id' => (int)$op['payment_id'],
                ];
            }
        } catch (Throwable $e) {
            /* Choque del UNIQUE: otra acción se quedó con esa operación. Queda
               abierta y con una nota, que es la respuesta correcta -- si dos
               acciones iguales apuntan a un solo depósito, una de las dos
               sobra y eso lo decide una persona. */
            try {
                $aviso = 'hay otra accion cerrada contra el mismo movimiento del panel: revisala';
                if (!str_contains($viejo, $aviso)) {
                    $pdo->prepare("UPDATE acciones_saldo SET mensaje = ? WHERE id = ?")
                        ->execute([mb_substr(($viejo !== '' ? $viejo . ' | ' : '') . $aviso, 0, 300),
                                   (int)$f['id']]);
                }
            } catch (Throwable $e2) { /* la nota es un extra */ }
        }
    }

    return ['ok' => true, 'cerradas' => $cerradas, 'detalle' => $detalle];
}
