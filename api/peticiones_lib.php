<?php
/**
 * peticiones_lib.php — La decision del "camino A": que transferencia respalda
 *                      una carga pedida desde el boton Depositos.
 *
 * Va aparte de peticiones_cola.php para poder probarla: el endpoint autentica
 * apenas se incluye, asi que nada de lo que viva ahi adentro se puede llamar
 * desde un test. Ver t_peticiones.php.
 *
 * No decide nada por su cuenta: reusa el matcher de recargas_lib
 * (rl_usuarios_por_huella, rl_similitud_nombres) para no tener un segundo
 * criterio que se vaya separando del primero -- que es exactamente lo que paso
 * con colector/matcher.py.
 */

declare(strict_types=1);
require_once __DIR__ . '/recargas_lib.php';

if (!defined('PC_GRACIA_ANTES_MIN')) {
    /* Margen para el jugador que transfiere ANTES de pedir la carga.
       La regla que esto protege: una transferencia solo respalda una solicitud
       si entro DESPUES de que la solicitud aparecio. Sin ese limite, una
       solicitud nueva se lleva una transferencia vieja que era de otra
       operacion -- y como ahora los montos son redondos (sin centavos unicos),
       "otra operacion por $1000" es algo comun, no una rareza. */
    define('PC_GRACIA_ANTES_MIN', 10);
}

if (!defined('PC_TIPO_DEPOSITO')) {
    /* type == 0 es deposito. El listado del panel mezcla depositos y retiros;
       un retiro aprobado por error saca plata. Se filtra en el worker Y aca. */
    define('PC_TIPO_DEPOSITO', 0);
}

if (!function_exists('pc_elegir_pago')) {
    /**
     * Elige que transferencia respalda una solicitud de carga.
     *
     * El camino A es MAS facil de resolver que el B: la solicitud ya dice de
     * que jugador es, asi que la huella CUIT/CBU se verifica contra ese usuario
     * en concreto en lugar de tener que deducir quien pago.
     *
     * @param array  $cands    pagos candidatos, ya filtrados por monto y ventana
     * @param string $username el jugador que pidio la carga
     * @param string $titular  el titular que declaro al pedirla (item.name)
     * @param int    $abiertas cuantas solicitudes esperan por ese mismo monto
     * @return array [pago|null, confianza('alta'|'media'|''), motivo]
     */
    function pc_elegir_pago(PDO $pdo, array $cands, string $username,
                            string $titular, int $abiertas): array
    {
        if (!$cands) {
            return [null, '', 'todavia no entro ninguna transferencia por ese monto'];
        }

        // --- Capa 1: huella. Este CUIT/CBU ya cargo antes con ESTE usuario. ---
        // Es la señal mas fuerte que hay y no depende de como se escriba el
        // nombre: sale de una carga anterior que ya se confirmo buena.
        $porHuella = [];
        foreach ($cands as $c) {
            $conocidos = rl_usuarios_por_huella($pdo, $c);
            if ($conocidos && in_array($username, $conocidos, true)) {
                $porHuella[] = $c;
            }
        }
        if (count($porHuella) === 1) {
            return [$porHuella[0], 'alta', 'ya habia cargado desde esa misma cuenta'];
        }
        if (count($porHuella) > 1) {
            // Varias transferencias suyas por el mismo monto: sigue siendo el,
            // asi que no es ambiguo. Se acota y decide abajo por fecha.
            $cands = $porHuella;
        }

        // --- Capa 2: el titular declarado contra el remitente del banco. ---
        $puntajes = [];
        foreach ($cands as $c) {
            $puntajes[] = [rl_similitud_nombres($titular, (string)($c['remitente'] ?? '')), $c];
        }
        usort($puntajes, static fn($a, $b) => $b[0] <=> $a[0]);

        if ($titular !== '' && $puntajes[0][0] >= RL_UMBRAL_NOMBRE) {
            $segundo = isset($puntajes[1]) ? $puntajes[1][0] : 0.0;
            if (($puntajes[0][0] - $segundo) >= RL_MARGEN_NOMBRE) {
                return [$puntajes[0][1], 'alta', sprintf(
                    'el titular coincide: "%s" ~ "%s" (%.2f)',
                    $titular, (string)($puntajes[0][1]['remitente'] ?? ''), $puntajes[0][0]
                )];
            }

            /* Empate de nombre. Antes de mandarlo a revision: ¿son la misma
               persona? Dos transferencias del mismo CUIT no son ambiguas -- es
               el mismo pagador dos veces, y ahi se toma la mas antigua (FIFO).
               Ambiguo de verdad es el empate entre personas DISTINTAS. */
            $empatados = [];
            foreach ($puntajes as [$s, $c]) {
                if (($puntajes[0][0] - $s) < RL_MARGEN_NOMBRE) { $empatados[] = $c; }
            }
            $cuits = [];
            foreach ($empatados as $c) {
                $cu = trim((string)($c['cuit'] ?? ''));
                if ($cu !== '') { $cuits[$cu] = true; }
            }
            if (count($cuits) <= 1) {
                usort($empatados, static fn($a, $b) =>
                    strcmp((string)($a['capturado_en'] ?? ''), (string)($b['capturado_en'] ?? '')));
                return [$empatados[0], 'alta', sprintf(
                    'el titular coincide (%.2f) y las %d transferencias son del mismo pagador: tomo la mas vieja',
                    $puntajes[0][0], count($empatados)
                )];
            }
            return [null, '', sprintf(
                'dos titulares parecidos ("%s" y "%s"): lo resuelve un operador',
                (string)($puntajes[0][1]['remitente'] ?? ''),
                (string)($puntajes[1][1]['remitente'] ?? '')
            )];
        }

        /* --- Capa 3: una sola transferencia y una sola solicitud por ese monto.
           El nombre no verifica (pago un familiar, o el banco informa la razon
           social), pero el resto encaja: monto exacto, entro despues de que el
           jugador pidio la carga, y no hay otra solicitud abierta por ese
           importe con la que se pueda confundir.

           Esa ultima condicion es la que el camino B no tiene: alla una sola
           recarga candidata alcanza para acreditar (capa 3 de
           rl_elegir_recarga). Aca se exige ademas que nadie mas la dispute,
           porque al sacar los centavos unicos dos jugadores transfiriendo
           $1000 a la vez dejo de ser raro. */
        if (count($cands) === 1 && $abiertas <= 1) {
            return [$cands[0], 'media', sprintf(
                'unica transferencia y unica solicitud por ese monto (el titular no verifica: declaro "%s", transfirio "%s")',
                $titular !== '' ? $titular : '(nada)',
                (string)($cands[0]['remitente'] ?? '')
            )];
        }

        if (count($cands) === 1) {
            return [null, '', sprintf(
                'hay %d solicitudes abiertas por ese monto y el titular no verifica: lo resuelve un operador',
                $abiertas
            )];
        }
        return [null, '', sprintf(
            '%d transferencias por ese monto, ninguna con el titular "%s"',
            count($cands), $titular !== '' ? $titular : '(nada)'
        )];
    }
}

if (!function_exists('pc_es_ambiguo')) {
    /**
     * Distingue "necesita una persona" de "todavia no llego la plata".
     *
     * No es lo mismo y no se tratan igual: lo ambiguo se congela en 'revision'
     * porque que aparezca otra transferencia no despeja un empate, mientras que
     * lo que sigue esperando se vuelve a evaluar en la proxima vuelta -- si el
     * mail del banco se demoro, la carga entra sola igual.
     */
    function pc_es_ambiguo(string $motivo): bool
    {
        return str_contains($motivo, 'operador');
    }
}
