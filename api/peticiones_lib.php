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
    /* Margen para el jugador que transfiere ANTES de pedir la carga, CUANDO NO
       SABEMOS QUIEN PAGO. Protege contra que una solicitud nueva se lleve una
       transferencia vieja que era de otra operacion -- y con montos redondos
       (sin centavos unicos) "otra operacion por $1000" es comun, no raro.

       Sigue en 10 minutos y sigue siendo corto A PROPOSITO: es el unico freno
       que le queda a la capa 3, que acredita sin identificar al pagador. */
    define('PC_GRACIA_ANTES_MIN', 10);
}

if (!defined('PC_VENTANA_IDENTIFICADO_MIN')) {
    /* CUANDO SI SABEMOS QUIEN PAGO, la ventana no tiene por que ser corta.
       24 horas.

       EL PROBLEMA QUE ESTO ARREGLA (medido el 20/09/2026 sobre las 24
       solicitudes que hubo): en ONCE, la transferencia habia entrado ANTES del
       pedido -- y el nombre del remitente coincidia EXACTO con el titular que
       la plataforma declara (similitud 1.00). Estaban a -83, -79, -267, -618 y
       hasta -3.086 minutos. Con la ventana de 10 ni siquiera entraban como
       candidatas, asi que el matcher contestaba "todavia no entro ninguna
       transferencia por ese monto" teniendo la plata en la mano.

       El orden real es ese: el jugador TRANSFIERE PRIMERO y despues entra al
       juego a pedir la carga. La ventana de 10 minutos daba por sentado el
       orden inverso.

       POR QUE ES SEGURO AMPLIARLA. La ventana era un sustituto de "esta plata
       es de esta solicitud". Cuando la huella o el nombre identifican al
       pagador, esa prueba ya la tenemos y el reloj no tiene que cargarla. Y el
       candidato sale de una consulta que solo mira pagos QUE NADIE USO
       (`estado` pendiente/revision, sin recarga y sin otra solicitud
       reclamandolos): una transferencia sin usar de hace dos horas, a nombre
       de quien la solicitud dice, no puede ser de otra operacion -- si lo
       fuera, ya estaria tomada.

       La capa 3 NO usa esta ventana: sin saber quien pago, el reloj vuelve a
       ser la unica prueba que queda. */
    define('PC_VENTANA_IDENTIFICADO_MIN', 1440);
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
     * @param string $pedidaEn cuando aparecio la solicitud (para la capa 3)
     * @return array [pago|null, confianza('alta'|'media'|''), motivo]
     */
    function pc_elegir_pago(PDO $pdo, array $cands, string $username,
                            string $titular, int $abiertas,
                            string $pedidaEn = ''): array
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
        /* LA CAPA 3 SE QUEDA CON LA VENTANA CORTA, y esto es lo que hace que
           ampliarla para las otras dos no afloje nada. Acá no sabemos quién
           pagó: el único argumento es "entró justo después de que la pidió".
           Un pago sin usar de hace seis horas no sostiene ese argumento por
           más que sea el único de ese monto. */
        if ($pedidaEn !== '') {
            $corte = strtotime($pedidaEn) - PC_GRACIA_ANTES_MIN * 60;
            $cands = array_values(array_filter($cands, static fn($c) =>
                strtotime((string)($c['capturado_en'] ?? '')) >= $corte));
            if (!$cands) {
                return [null, '', 'no hay ninguna transferencia sin usar que el titular '
                    . 'verifique, y las que hay entraron demasiado antes del pedido'];
            }
        }

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

if (!defined('PC_ESPERA_MIN')) {
    /* Cuanto tiene que llevar esperando una solicitud antes de que el sistema
       la rechace sola por duplicada. Es el mismo numero que `CRMP_ESPERA_MIN`
       del CRM (los 15 minutos tras los cuales una solicitud cuenta como
       demorada), y esta aparte porque el worker no puede incluir ese archivo:
       crm_peticiones.php autentica apenas se lo incluye. */
    define('PC_ESPERA_MIN', 15);
}

if (!function_exists('pc_ya_acreditada')) {
    /**
     * ¿A este jugador ya se le acredito ESTE monto por el chat?
     *
     * EL PROBLEMA (medido el 20/09/2026 sobre las 24 solicitudes que hubo):
     * NUEVE eran duplicados. El jugador pidio la carga por el chat, se le
     * acredito ahi, y ADEMAS apreto el boton de Depositos adentro del juego. Esa
     * solicitud queda esperando para siempre -- y con razon, porque no hay
     * ninguna transferencia sin usar que la respalde: esa plata ya se la dimos.
     * Nahuel las tenia que revisar una por una en el panel.
     *
     * SE CRUZA POR JUGADOR, NO POR NOMBRE DEL REMITENTE. Probar por nombre daba
     * CUATRO falsos positivos de 13 -- tres "Fernandez" distintos donde la plata
     * era de OTRO jugador. `recargas.usuario` no tiene esa ambiguedad.
     *
     * `$usadas` ENTRA POR REFERENCIA Y NO ES UN DETALLE: cada recarga explica
     * COMO MUCHO UNA solicitud. Sin eso, dos pedidos de holagustavo861 por
     * $3.000 apuntaban los dos a la misma recarga, y uno de los dos era
     * legitimo. Quien llama recorre las solicitudes y va pasando el mismo array.
     *
     * @param array $usadas ids de recarga ya asignados (se modifica)
     * @return array|null   ['recarga_id','referencia','cuando'] o null
     */
    function pc_ya_acreditada(PDO $pdo, string $usuario, float $monto,
                              string $pedidaEn, array &$usadas): ?array
    {
        if ($usuario === '' || $monto <= 0 || $pedidaEn === '') { return null; }
        try {
            /* Se traen VARIAS y se elige en PHP: con LIMIT 1 en SQL la misma
               recarga volveria una y otra vez y no se podria descartar. */
            $st = $pdo->prepare(
                "SELECT id, referencia, acreditada_en,
                        ABS(TIMESTAMPDIFF(MINUTE, acreditada_en, ?)) AS cerca
                   FROM recargas
                  WHERE usuario = ? COLLATE utf8mb4_unicode_ci
                    AND estado = 'acreditada'
                    AND ROUND(monto_pedido * 100) = ?
                    AND acreditada_en BETWEEN ? - INTERVAL 24 HOUR AND ? + INTERVAL 6 HOUR
                  ORDER BY cerca LIMIT 5"
            );
            $st->execute([$pedidaEn, $usuario, (int)round($monto * 100), $pedidaEn, $pedidaEn]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                if (isset($usadas[$id])) { continue; }
                $usadas[$id] = true;
                return ['recarga_id' => $id,
                        'referencia' => (string)$r['referencia'],
                        'cuando'     => (string)$r['acreditada_en']];
            }
        } catch (Throwable $e) {
            error_log('pc_ya_acreditada: ' . $e->getMessage());
        }
        return null;
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
