<?php
/**
 * crm_notificaciones.php — Notificaciones avanzadas del CRM: filtro por
 * inactividad, presets de filtro, historial de envíos, y bonos pendientes
 * (fichas/porcentaje/giro de ruleta) prometidos por notificación.
 *
 * No es un endpoint: lo incluye crm.php (acciones del agente) y
 * recargas_lib.php (aplicación automática del bono al acreditar una carga).
 * Requiere sql/33_notif_avanzadas.sql corrida.
 *
 * Requiere un $pdo ya conectado (lo pasa quien la incluye) y, para el envío
 * masivo, notif_crear() de notificaciones_lib.php.
 */

declare(strict_types=1);

defined('CRMNOTIF_BONO_TIPOS') || define('CRMNOTIF_BONO_TIPOS', ['fichas', 'pct', 'giro']);

if (!function_exists('crmnotif_alcance_inactivos')) {

    /**
     * Cuántos jugadores no tienen ninguna recarga ACREDITADA en los últimos
     * $dias. Un jugador sin ninguna recarga acreditada nunca sale de este
     * conteo (se lo trata como inactivo desde siempre) — no hay una fecha
     * real contra la cual medirlo, así que cuenta como el caso más inactivo
     * posible, útil para apuntar promos de primera carga.
     */
    function crmnotif_alcance_inactivos(PDO $pdo, int $dias): int
    {
        $dias = max(0, $dias);
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM usuarios u
              WHERE COALESCE(u.is_banned, 0) = 0
                AND NOT EXISTS (
                SELECT 1 FROM recargas r
                 WHERE r.usuario = u.username COLLATE utf8mb4_unicode_ci
                   AND r.estado = 'acreditada'
                   AND r.acreditada_en > DATE_SUB(NOW(), INTERVAL ? DAY)
              )"
        );
        $st->execute([$dias]);
        return (int)$st->fetchColumn();
    }

    /** Lista de usernames inactivos hace más de $dias. Mismo criterio que
     *  crmnotif_alcance_inactivos(), usada al resolver un envío masivo. */
    /**
     * Los que existen como jugadores pero NUNCA escribieron en el chat.
     *
     * "No interactuo" se mide por MENSAJES SUYOS, no por si tiene conversacion:
     * la fila en `conversaciones` se puede crear sola (este mismo envio la
     * crea), asi que preguntar por la conversacion daria falso desde el segundo
     * envio en adelante. Lo que no miente es si alguna vez hablo el.
     *
     * El COLLATE es obligatorio: `usuarios` toma el default del servidor y las
     * tablas del CRM son utf8mb4_unicode_ci. Sin el, el JOIN tira
     * "Illegal mix of collations" -- la trampa clasica de este esquema.
     *
     * Se saltean los baneados: mandarle una promo a alguien al que le cerramos
     * la cuenta es peor que no mandarle nada.
     */
    function crmnotif_usuarios_sin_chat(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT u.username FROM usuarios u
              WHERE COALESCE(u.is_banned, 0) = 0
                AND NOT EXISTS (
                  SELECT 1
                    FROM conversaciones c
                    JOIN mensajes m ON m.conversacion_id = c.id AND m.rol = 'user'
                   WHERE c.clave = u.username COLLATE utf8mb4_unicode_ci
                )
              ORDER BY u.username"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    function crmnotif_usuarios_inactivos(PDO $pdo, int $dias): array
    {
        $dias = max(0, $dias);
        $st = $pdo->prepare(
            "SELECT u.username FROM usuarios u
              WHERE COALESCE(u.is_banned, 0) = 0
                AND NOT EXISTS (
                SELECT 1 FROM recargas r
                 WHERE r.usuario = u.username COLLATE utf8mb4_unicode_ci
                   AND r.estado = 'acreditada'
                   AND r.acreditada_en > DATE_SUB(NOW(), INTERVAL ? DAY)
              )"
        );
        $st->execute([$dias]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Envía una notificación según el filtro elegido por el agente.
     * $filtro: ['modo'=>'todos'] | ['modo'=>'usuario','usuario'=>'x'] |
     *          ['modo'=>'inactivos','dias'=>N]
     *
     * "todos" sigue siendo UNA fila en `notificaciones` (usuario=NULL), igual
     * que siempre: el sondeo de cada celular la resuelve solo. "inactivos" no
     * puede serlo (el sondeo no puede evaluar esa condición por su cuenta),
     * así que se resuelve la lista ACÁ y se crea una fila POR USUARIO, todas
     * con el mismo lote_id para que el historial las agrupe como un envío.
     *
     * Devuelve ['ok'=>bool, 'alcance'=>int, 'lote_id'=>?string, 'error'?=>...].
     */
    function crmnotif_enviar_masivo(PDO $pdo, array $filtro, string $titulo, string $cuerpo,
                                    string $tipo, string $origen = 'crm', ?string $operador = null,
                                    ?string $programadaEn = null): array
    {
        $modo = (string)($filtro['modo'] ?? 'todos');

        if ($modo === 'usuario') {
            $usuario = trim((string)($filtro['usuario'] ?? ''));
            if ($usuario === '') { return ['ok' => false, 'error' => 'Falta el usuario']; }
            $id = notif_crear($pdo, $usuario, $titulo, $cuerpo, $tipo, null, $origen, null, false, $programadaEn);
            return $id ? ['ok' => true, 'alcance' => 1, 'lote_id' => null, 'id' => $id]
                       : ['ok' => false, 'error' => 'No se pudo encolar el push'];
        }

        if ($modo === 'inactivos') {
            $dias = max(0, (int)($filtro['dias'] ?? 0));
            $usuarios = crmnotif_usuarios_inactivos($pdo, $dias);
            if (!$usuarios) { return ['ok' => true, 'alcance' => 0, 'lote_id' => null]; }

            $loteId = crmnotif_uuid();
            $filtroJson = json_encode($filtro, JSON_UNESCAPED_UNICODE);
            $enviadas = 0;
            foreach ($usuarios as $u) {
                $id = notif_crear($pdo, $u, $titulo, $cuerpo, $tipo, null, $origen, null, false, $programadaEn);
                if ($id) {
                    crmnotif_marcar_lote($pdo, $id, $loteId, $filtroJson);
                    $enviadas++;
                }
            }
            return ['ok' => true, 'alcance' => $enviadas, 'lote_id' => $loteId];
        }

        // modo "todos" (default)
        $id = notif_crear($pdo, null, $titulo, $cuerpo, $tipo, null, $origen, null, false, $programadaEn);
        return $id ? ['ok' => true, 'alcance' => null, 'lote_id' => null, 'id' => $id]
                   : ['ok' => false, 'error' => 'No se pudo encolar el push'];
    }

    function crmnotif_uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** Deja lote_id/filtro_usado en una fila recién creada. Nunca lanza: si
     *  la migración 33 no corrió, el envío ya se hizo, solo se pierde el
     *  agrupamiento en el historial. */
    function crmnotif_marcar_lote(PDO $pdo, int $notifId, string $loteId, string $filtroJson): void
    {
        try {
            $pdo->prepare("UPDATE notificaciones SET lote_id = ?, filtro_usado = ? WHERE id = ?")
                ->execute([$loteId, $filtroJson, $notifId]);
        } catch (Throwable $e) {
            error_log('crmnotif_marcar_lote: ' . $e->getMessage());
        }
    }

    /**
     * Historial de notificaciones YA enviadas (a diferencia de
     * notif_programadas_listar(), que solo mira las futuras), agrupado por
     * lote_id para que un envío masivo a inactivos cuente como una fila.
     *
     * $opts: usuario?, desde?, hasta?, tipo?, pagina (1-based), por_pagina.
     * Devuelve ['items'=>[...], 'total'=>int].
     */
    /**
     * CUANTA DE LA GENTE QUE JUEGA PUEDE RECIBIR UNA OFERTA.
     *
     * EL PEDIDO (Nahuel, 19/09/2026): *"lo que me interesa a mi es que los
     * usuarios ACTIVOS tengan la aplicacion instalada... el calculo va mas
     * sobre los usuarios activos que sobre los usuarios de la otra epoca del
     * negocio"*.
     *
     * Y el numero grande engaña: sobre los 3.081 del padron la app da 1%, y
     * ese 1% incluye cuentas de hace meses que no vuelven. El numero que dice
     * si el canal sirve es **sobre los que estan jugando ahora**, porque son
     * los unicos a los que tiene sentido mandarles una oferta.
     *
     * Las tres filas, de menos a mas exigente:
     *
     *   padron    todos los jugadores que existen. El denominador historico.
     *   activos   los que dieron señales en los ultimos N dias (jugaron,
     *             cargaron, chatearon o entraron -- `ultima_actividad`, que
     *             junta las cinco señales de la migracion 46).
     *   celulares los aparatos que de verdad sondearon hace poco. Es lo unico
     *             que garantiza que una push se vea: la entrega es POR
     *             DISPOSITIVO, no por el flag `tiene_app` del jugador (medido
     *             el 18/09: 29 con el flag, 21 con un android con permiso).
     *
     * `dias` es la ventana de "activo": 30 por default, movible desde el CRM
     * porque "activo" significa cosas distintas mirando una semana o un mes.
     */
    function crmnotif_cobertura(PDO $pdo, int $dias = 7): array
    {
        $dias = max(1, min(365, $dias));
        $out = [
            'dias' => $dias,
            'pico' => null,
            'padron'    => ['total' => 0, 'con_app' => 0, 'pct' => 0.0],
            'activos'   => ['total' => 0, 'con_app' => 0, 'pct' => 0.0],
            'celulares' => ['android' => 0, 'sondearon_24h' => 0, 'sondearon_7d' => 0],
        ];
        try {
            /* CON LA APP = con un celular que puede recibir, no con el flag.
               Es el mismo criterio que usa la campaña de fidelizacion: si esta
               pantalla contara distinto, el numero que se mira para decidir no
               seria el que va a pasar. */
            $conApp = "EXISTS (SELECT 1 FROM dispositivos d
                                WHERE d.usuario COLLATE utf8mb4_unicode_ci
                                      = u.username COLLATE utf8mb4_unicode_ci
                                  AND d.plataforma = 'android' AND d.permitido = 1)";

            $r = $pdo->query("SELECT COUNT(*) t, SUM($conApp) a FROM usuarios u")
                     ->fetch(PDO::FETCH_ASSOC);
            $out['padron']['total']   = (int)($r['t'] ?? 0);
            $out['padron']['con_app'] = (int)($r['a'] ?? 0);

            $st = $pdo->prepare(
                "SELECT COUNT(*) t, SUM($conApp) a FROM usuarios u
                  WHERE u.ultima_actividad IS NOT NULL
                    AND u.ultima_actividad >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $st->execute([$dias]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $out['activos']['total']   = (int)($r['t'] ?? 0);
            $out['activos']['con_app'] = (int)($r['a'] ?? 0);

            foreach (['padron', 'activos'] as $k) {
                $out[$k]['pct'] = $out[$k]['total'] > 0
                    ? round($out[$k]['con_app'] * 100 / $out[$k]['total'], 1) : 0.0;
            }

            $cel = "FROM dispositivos WHERE plataforma = 'android' AND permitido = 1";
            $out['celulares']['android'] = (int)$pdo->query("SELECT COUNT(*) $cel")->fetchColumn();
            $out['celulares']['sondearon_24h'] = (int)$pdo->query(
                "SELECT COUNT(*) $cel AND visto_en > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
            $out['celulares']['sondearon_7d'] = (int)$pdo->query(
                "SELECT COUNT(*) $cel AND visto_en > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

            /* EL BACKFILL QUE ARRUINA EL NUMERO SI NADIE LO DICE.
               `ultima_actividad` se lleno de una para todo el padron cuando
               entro la migracion 46: medido el 19/09/2026, 2.832 de 3.081
               jugadores tienen la MISMA fecha (2026-09-03). O sea que
               cualquier ventana que llegue hasta ahi cuenta como "activo" a
               casi todo el mundo, y el porcentaje con app se desploma de 27%
               a 0,7% sin que haya cambiado nada real.

               Es la trampa clasica de este dato: el numero se ve peor cuanto
               mas grande se hace la ventana, y la conclusion natural --"la app
               no prende"-- es exactamente la contraria a la verdad.

               Se detecta solo: si UN dia concentra mas del 20% del padron, no
               es actividad, es un relleno. Se informa con su fecha para que la
               pantalla avise cuando la ventana lo alcanza. */
            $pico = $pdo->query(
                "SELECT DATE(ultima_actividad) f, COUNT(*) n
                   FROM usuarios WHERE ultima_actividad IS NOT NULL
                  GROUP BY f ORDER BY n DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($pico && $out['padron']['total'] > 0
                && (int)$pico['n'] > $out['padron']['total'] * 0.2) {
                $out['pico'] = [
                    'fecha' => (string)$pico['f'],
                    'n'     => (int)$pico['n'],
                    'dias_atras' => (int)((time() - strtotime((string)$pico['f'])) / 86400),
                ];
            }
        } catch (Throwable $e) {
            error_log('crmnotif_cobertura: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * EL TABLERO DE LA APP: dónde se pierde la gente entre "juega" y "le puedo
     * hablar", y si el canal crece o se achica.
     *
     * POR QUE ESTAS Y NO OTRAS. El negocio se sostiene acumulando jugadores
     * activos, y la herramienta para que no se enfríen es la app: instalarla,
     * recibir la notificación, volver por el bono. Así que las métricas
     * contestan las cuatro preguntas de esa cadena, en orden:
     *
     *   1. ¿A cuántos les puedo hablar?      -> el embudo
     *   2. ¿Estoy ganando o perdiendo?       -> altas y bajas por semana
     *   3. ¿Sirve de algo?                   -> retención con app vs sin app
     *   4. ¿Lo que mando llega?              -> entrega y lectura
     *
     * La 3 es la que justifica todo el resto: si el que tiene la app no vuelve
     * más que el que no la tiene, el canal es un gasto. Y la 2 es la que nadie
     * mira y la que avisa temprano: una app que se desinstala no genera ningún
     * evento, simplemente deja de sondear.
     */
    function crmnotif_metricas(PDO $pdo, int $dias = 7): array
    {
        $dias = max(1, min(365, $dias));
        $out = ['dias' => $dias, 'embudo' => [], 'semanas' => [],
                'retencion' => null, 'entrega' => null, 'fidelizacion' => null];

        /* Con la app = con un celular android que puede recibir. Mismo criterio
           que la campaña de fidelización: si acá contara distinto, el número
           que se mira para decidir no sería el que va a pasar. */
        $conApp = "EXISTS (SELECT 1 FROM dispositivos d
                            WHERE d.usuario COLLATE utf8mb4_unicode_ci
                                  = u.username COLLATE utf8mb4_unicode_ci
                              AND d.plataforma = 'android')";
        $permitida = "EXISTS (SELECT 1 FROM dispositivos d
                               WHERE d.usuario COLLATE utf8mb4_unicode_ci
                                     = u.username COLLATE utf8mb4_unicode_ci
                                 AND d.plataforma = 'android' AND d.permitido = 1)";
        $viva = "EXISTS (SELECT 1 FROM dispositivos d
                          WHERE d.usuario COLLATE utf8mb4_unicode_ci
                                = u.username COLLATE utf8mb4_unicode_ci
                            AND d.plataforma = 'android' AND d.permitido = 1
                            AND d.visto_en > DATE_SUB(NOW(), INTERVAL 7 DAY))";

        /* ---- 1. EL EMBUDO ----------------------------------------------
           Cada escalón que se pierde tiene un arreglo DISTINTO, y por eso van
           separados en vez de un solo porcentaje:
             activo -> instaló    lo arregla el bono por instalar y el cartel
                                  en la primera carga
             instaló -> permitió  lo arregla pedir el permiso en el momento
                                  justo, no al abrir
             permitió -> viva     la app está instalada pero no se abre: ahí
                                  la push tarda o no llega
           Un embudo con un solo número escondería cuál de los tres está mal. */
        try {
            $base = "FROM usuarios u
                      WHERE u.ultima_actividad IS NOT NULL
                        AND u.ultima_actividad >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $q = function (string $extra) use ($pdo, $base, $dias): int {
                $st = $pdo->prepare("SELECT COUNT(*) $base $extra");
                $st->execute([$dias]);
                return (int)$st->fetchColumn();
            };
            $act = $q('');
            $ins = $q(" AND $conApp");
            $per = $q(" AND $permitida");
            $viv = $q(" AND $viva");
            $pc = fn(int $n, int $de) => $de > 0 ? round($n * 100 / $de, 1) : 0.0;
            $out['embudo'] = [
                ['k' => 'activos',    'n' => $act, 'pct' => null,
                 'que' => 'Jugaron, cargaron o escribieron en el período'],
                ['k' => 'instalada',  'n' => $ins, 'pct' => $pc($ins, $act),
                 'que' => 'De esos, los que bajaron la app'],
                ['k' => 'permitida',  'n' => $per, 'pct' => $pc($per, $ins),
                 'que' => 'De los que la tienen, los que dejaron pasar las notificaciones'],
                ['k' => 'alcanzable', 'n' => $viv, 'pct' => $pc($viv, $per),
                 'que' => 'De esos, los que abrieron la app en la última semana'],
            ];
        } catch (Throwable $e) { error_log('crmnotif_metricas/embudo: ' . $e->getMessage()); }

        /* ---- 2. ALTAS Y BAJAS POR SEMANA -------------------------------
           LA BAJA NO GENERA NINGUN EVENTO. Nadie avisa que desinstaló: el
           celular simplemente deja de sondear, y el jugador sigue figurando
           con la app puesta para siempre. Se cuenta como baja el aparato que
           hace más de 14 días que no aparece -- dos semanas es más que el
           sondeo de 15 minutos y más que cualquier fin de semana largo. */
        try {
            for ($s = 3; $s >= 0; $s--) {
                $st = $pdo->prepare(
                    "SELECT COUNT(*) FROM dispositivos
                      WHERE plataforma = 'android'
                        AND creado_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
                        AND creado_en <  DATE_SUB(NOW(), INTERVAL ? DAY)"
                );
                $st->execute([($s + 1) * 7, $s * 7]);
                $altas = (int)$st->fetchColumn();

                $st2 = $pdo->prepare(
                    "SELECT COUNT(*) FROM dispositivos
                      WHERE plataforma = 'android'
                        AND visto_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
                        AND visto_en <  DATE_SUB(NOW(), INTERVAL ? DAY)
                        AND visto_en <  DATE_SUB(NOW(), INTERVAL 14 DAY)"
                );
                $st2->execute([($s + 1) * 7, $s * 7]);
                $bajas = (int)$st2->fetchColumn();

                $out['semanas'][] = [
                    'hace' => $s === 0 ? 'esta semana' : 'hace ' . $s . ($s === 1 ? ' semana' : ' semanas'),
                    'altas' => $altas, 'bajas' => $bajas, 'neto' => $altas - $bajas,
                ];
            }
        } catch (Throwable $e) { error_log('crmnotif_metricas/semanas: ' . $e->getMessage()); }

        /* ---- 3. ¿SIRVE? RETENCION CON APP vs SIN APP --------------------
           LA METRICA QUE JUSTIFICA TODO EL RESTO. Si el que tiene la app no
           vuelve más que el que no la tiene, el canal es un gasto y conviene
           saberlo antes de seguir invirtiendo en él.
           Se mide igual para los dos grupos: de los que cargaron entre hace 30
           y hace 8 días, cuántos volvieron a cargar en los últimos 7. La
           ventana de corte evita contar como "volvió" la misma carga.
           Se usa publicidad_sql_cargas(), que es la definición única de "una
           carga" en todo el CRM -- si esta pantalla armara la suya, mostraría
           un número distinto al de Finanzas el mismo día. */
        try {
            if (function_exists('publicidad_sql_cargas')) {
                $cargas = publicidad_sql_cargas();
                $sql = "SELECT $conApp AS tiene_app,
                               COUNT(DISTINCT u.username) AS base,
                               COUNT(DISTINCT CASE WHEN EXISTS (
                                     SELECT 1 FROM ($cargas) c2
                                      WHERE c2.usuario = u.username COLLATE utf8mb4_unicode_ci
                                        AND c2.cuando > DATE_SUB(NOW(), INTERVAL 7 DAY))
                                   THEN u.username END) AS volvieron
                          FROM usuarios u
                         WHERE EXISTS (SELECT 1 FROM ($cargas) c1
                                        WHERE c1.usuario = u.username COLLATE utf8mb4_unicode_ci
                                          AND c1.cuando <  DATE_SUB(NOW(), INTERVAL 8 DAY)
                                          AND c1.cuando >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                         GROUP BY tiene_app";
                $ret = ['con_app' => ['base' => 0, 'volvieron' => 0, 'pct' => 0.0],
                        'sin_app' => ['base' => 0, 'volvieron' => 0, 'pct' => 0.0]];
                foreach ($pdo->query($sql) as $r) {
                    $k = ((int)$r['tiene_app'] === 1) ? 'con_app' : 'sin_app';
                    $ret[$k]['base']      = (int)$r['base'];
                    $ret[$k]['volvieron'] = (int)$r['volvieron'];
                    $ret[$k]['pct'] = $ret[$k]['base'] > 0
                        ? round($ret[$k]['volvieron'] * 100 / $ret[$k]['base'], 1) : 0.0;
                }
                /* UNA BASE DE UNO NO ES UN PORCENTAJE. Con 1 jugador con app
                   y 6 sin app, mostrar "0% vs 0%" no es un dato: es una
                   conclusión inventada sobre la que alguien podría decidir
                   apagar el canal. Se informa que todavía no alcanza y la
                   pantalla lo dice con esas palabras en vez de pintar números.
                   10 por grupo es poco y ya evita lo peor -- el ruido de una
                   muestra de un dígito. */
                $ret['suficiente'] = ($ret['con_app']['base'] >= 10
                                      && $ret['sin_app']['base'] >= 10);
                $out['retencion'] = $ret;
            }
        } catch (Throwable $e) { error_log('crmnotif_metricas/retencion: ' . $e->getMessage()); }

        /* ---- 4. ¿LO QUE MANDO LLEGA? -----------------------------------
           Crear no es llegar: la fidelización creó 600 avisos y entregó 0. */
        try {
            $r = $pdo->query(
                "SELECT COUNT(DISTINCT n.id) creadas,
                        COUNT(DISTINCT e.notificacion_id) con_entrega,
                        COUNT(e.device_id) entregas,
                        COUNT(e.leida_en) leidas
                   FROM notificaciones n
                   LEFT JOIN notificaciones_entregas e ON e.notificacion_id = n.id
                  WHERE n.creada_en > DATE_SUB(NOW(), INTERVAL 30 DAY)"
            )->fetch(PDO::FETCH_ASSOC);
            $out['entrega'] = [
                'creadas'     => (int)($r['creadas'] ?? 0),
                'con_entrega' => (int)($r['con_entrega'] ?? 0),
                'entregas'    => (int)($r['entregas'] ?? 0),
                'leidas'      => (int)($r['leidas'] ?? 0),
            ];
        } catch (Throwable $e) { error_log('crmnotif_metricas/entrega: ' . $e->getMessage()); }

        /* ---- 5. LA CAMPAÑA: prometido vs cobrado -----------------------
           Un bono prometido es una deuda; uno cobrado es un jugador que
           volvió. La distancia entre los dos es lo que dice si la campaña
           empuja o solo regala. */
        try {
            $r = $pdo->query(
                "SELECT SUM(estado = 'pendiente') pendientes,
                        SUM(estado = 'aplicado')  cobrados
                   FROM bonos_pendientes WHERE prometido_por = 'fidelizacion'"
            )->fetch(PDO::FETCH_ASSOC);
            $pend = (int)($r['pendientes'] ?? 0);
            $cob  = (int)($r['cobrados'] ?? 0);
            $out['fidelizacion'] = [
                'pendientes' => $pend, 'cobrados' => $cob,
                'pct' => ($pend + $cob) > 0 ? round($cob * 100 / ($pend + $cob), 1) : 0.0,
            ];
        } catch (Throwable $e) { error_log('crmnotif_metricas/fid: ' . $e->getMessage()); }

        /* ---- 6. ¿SIRVIO? de los que recibieron, cuantos cargaron ---- */
        try { $out['efectividad'] = crmnotif_efectividad($pdo, 30); }
        catch (Throwable $e) { error_log('crmnotif_metricas/efect: ' . $e->getMessage()); }

        return $out;
    }

    /* AVISOS QUE SIGUEN A UNA OPERACION, no que la provocan: "te acreditamos
       la carga", "te contestamos", "tu retiro salio". Se cuentan aparte porque
       mezclarlos con las promos da vuelta la causalidad.

       MEDIDO EL 19/09/2026 CONTRA PRODUCCION, y es lo que inflaba el numero:
       de 1.067 pares aviso/carga separados por menos de dos horas en el origen
       `chatbot`, 500 tenian el aviso creado DESPUES de la carga. No es que el
       aviso los hizo cargar: cargaron, y por eso les llego el aviso. */
    defined('CRMNOTIF_ORIGEN_TRANSACCIONAL') || define('CRMNOTIF_ORIGEN_TRANSACCIONAL',
        ['chatbot', 'carga', 'recargas', 'retiro', 'multicuenta']);

    /**
     * ¿SIRVEN LOS AVISOS? Tres medidas, de la mas dura a la mas blanda.
     *
     * EL PEDIDO ORIGINAL (Nahuel, 19/09/2026): *"quiero ver qué tanta
     * efectividad están teniendo. Es decir, a qué porcentaje de los que le
     * mandamos notificación realmente cargaron"*. Y despues, mirando el 42,5%
     * que salio de eso: *"ese dato quiero que lo corrobores, porque quiero
     * verificar que vengan efectivamente de la notificación que se les
     * envió... quizás una manera de medirlo es si efectivamente reclamaron el
     * bono que se les envió"*.
     *
     * SE CORROBORO, Y EL 42,5% NO PROBABA NADA. Lo que se encontro el
     * 19/09/2026 midiendo produccion:
     *
     *   · 84 de los 87 "avisados" eran el aviso `chatbot` ("te contestamos"),
     *     que va con solo_app=1 -- o sea que el widget lo consume y NO LO
     *     DIBUJA. Contaba como "recibio un aviso" algo que por diseño nadie ve.
     *   · Y su causalidad corre al reves: de 1.067 pares aviso/carga a menos
     *     de dos horas, 500 tenian el aviso creado DESPUES de la carga.
     *   · La campaña de fidelizacion, que es la unica que existe para hacer
     *     cargar a alguien, no aportaba una sola fila: mando 614 avisos y
     *     entrego 0.
     *   · Y el grupo de control no ayuda: los 25 jugadores con app que no
     *     recibieron ningun aviso cargaron 0%, pero eso no prueba que el aviso
     *     funcione -- es circular. Los avisos transaccionales se disparan al
     *     cargar, asi que "no recibio ningun aviso en 30 dias" es practicamente
     *     la definicion de "no hizo nada en 30 dias".
     *
     * LAS TRES MEDIDAS QUE SI DICEN ALGO:
     *
     * 1. `reclamo` -- RECLAMARON EL BONO DEL AVISO. La unica atribuible: le
     *    prometimos un bono EN un aviso y lo uso en una carga. No se explica
     *    por casualidad. Necesita que el bono guarde `notificacion_id`, que es
     *    lo que faltaba (fid_avisar_uno lo escribe desde el 19/09/2026).
     *
     * 2. `toque` -- ABRIERON el aviso y cargaron, contra los que lo recibieron
     *    y no lo abrieron. Es la comparacion la que informa, no el numero
     *    suelto: el mismo universo, la misma ventana, y la unica diferencia es
     *    si lo tocaron. Sigue sin ser causa (quien toca ya estaba enganchado),
     *    pero es la señal mas fuerte que se puede leer sin un grupo de control.
     *
     * 3. `promo` -- de los que RECIBIERON una promo VISIBLE, cuantos cargaron
     *    dentro de los 7 dias. Es el numero que se pidio al principio, ahora
     *    sin las dos contaminaciones: medido desde la ENTREGA y no desde que
     *    se creo el aviso, y sin los transaccionales.
     *
     * DESDE `entregada_en`, NO DESDE `creada_en`. La push se entrega cuando el
     * celular sondea, y la demora medida en produccion no es chica: 54 minutos
     * de promedio en el chat, 7 horas en los avisos de carga, 20 horas en los
     * de ruleta. Contar desde que se creo el aviso mete como "cargo despues"
     * cargas que pasaron antes de que el jugador viera nada.
     *
     * LO QUE SIGUE SIN PODERSE CONTESTAR es cuanta de esa plata no habria
     * entrado igual. Para eso hace falta dejar a proposito sin aviso a una
     * parte de los elegibles y comparar -- no se puede deducir de lo que ya
     * paso.
     */
    function crmnotif_efectividad(PDO $pdo, int $dias = 30): array
    {
        $dias = max(1, min(365, $dias));
        $vacio = ['avisados' => 0, 'cargaron' => 0, 'pct' => 0.0];
        $out = [
            'dias'    => $dias,
            'reclamo' => ['prometidos' => 0, 'reclamados' => 0, 'pct' => 0.0, 'sin_atar' => 0],
            'toque'   => ['tocaron' => 0, 'tocaron_cargaron' => 0, 'tocaron_pct' => 0.0,
                          'ignoraron' => 0, 'ignoraron_cargaron' => 0, 'ignoraron_pct' => 0.0],
            'promo'   => $vacio,
            'transaccional' => $vacio,
            'por_origen' => [],
        ];
        $pc = fn(int $n, int $de) => $de > 0 ? round($n * 100 / $de, 1) : 0.0;

        try {
            /* ---- 1. LA MEDIDA DURA: reclamaron el bono que el aviso prometio.
                  Se mira `creado_en` del bono y no la ventana de entrega: un
                  bono prometido hace 20 dias y cobrado ayer cuenta como
                  cobrado, que es lo que interesa. */
            $st = $pdo->prepare(
                "SELECT COUNT(*) prometidos,
                        SUM(estado = 'aplicado') reclamados
                   FROM bonos_pendientes
                  WHERE notificacion_id IS NOT NULL
                    AND creado_en >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $st->execute([$dias]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['reclamo']['prometidos'] = (int)($r['prometidos'] ?? 0);
            $out['reclamo']['reclamados'] = (int)($r['reclamados'] ?? 0);
            $out['reclamo']['pct'] = $pc($out['reclamo']['reclamados'], $out['reclamo']['prometidos']);

            /* Los bonos SIN aviso atado no entran al porcentaje, pero se
               informan: si son muchos, el numero de arriba esta midiendo una
               parte chica y hay que decirlo en vez de dar un 0% redondo. */
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM bonos_pendientes
                  WHERE notificacion_id IS NULL
                    AND creado_en >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $st->execute([$dias]);
            $out['reclamo']['sin_atar'] = (int)$st->fetchColumn();

            if (!function_exists('publicidad_sql_cargas')) { return $out; }

            /* ---- 2. Las entregas: quien, cuando, de que tipo, y si la toco.
                  SOLO LO QUE SE DIBUJA (solo_app = 0): un aviso que el widget
                  consume sin mostrar no le puede pedir nada a nadie.
                  La ventana termina hace un dia: a alguien que lo recibio hace
                  dos horas todavia no se le puede reprochar no haber cargado. */
            $st = $pdo->prepare(
                "SELECT d.usuario, o.origen,
                        MIN(e.entregada_en) AS cuando,
                        MAX(e.leida_en IS NOT NULL) AS toco
                   FROM notificaciones o
                   JOIN notificaciones_entregas e ON e.notificacion_id = o.id
                   JOIN dispositivos d ON d.device_id = e.device_id
                  WHERE e.entregada_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND e.entregada_en <  DATE_SUB(NOW(), INTERVAL 1 DAY)
                    AND COALESCE(o.solo_app, 0) = 0
                    AND d.usuario IS NOT NULL AND d.usuario <> ''
                  GROUP BY d.usuario, o.origen"
            );
            $st->execute([$dias]);
            $avisos = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$avisos) { return $out; }

            // ---- 3. Las cargas del periodo, una vez.
            $cargas = publicidad_sql_cargas();
            $sc = $pdo->prepare(
                "SELECT usuario, cuando FROM ($cargas) c
                  WHERE cuando >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $sc->execute([$dias + 7]);
            $porUsuario = [];
            foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $porUsuario[mb_strtolower((string)$c['usuario'])][] = strtotime((string)$c['cuando']);
            }

            // ---- 4. El cruce.
            $orig  = [];
            $grupo = ['promo' => ['a' => [], 'c' => []], 'transaccional' => ['a' => [], 'c' => []]];
            $toque = ['1' => ['a' => [], 'c' => []], '0' => ['a' => [], 'c' => []]];
            foreach ($avisos as $a) {
                $u  = mb_strtolower((string)$a['usuario']);
                $o  = (string)$a['origen'] ?: 'otro';
                $ts = strtotime((string)$a['cuando']);
                $cl = in_array($o, CRMNOTIF_ORIGEN_TRANSACCIONAL, true) ? 'transaccional' : 'promo';
                $tk = !empty($a['toco']) ? '1' : '0';

                $cargo = false;
                foreach ($porUsuario[$u] ?? [] as $tc) {
                    if ($tc > $ts && $tc < $ts + 7 * 86400) { $cargo = true; break; }
                }
                if (!isset($orig[$o])) { $orig[$o] = ['clase' => $cl, 'a' => [], 'c' => []]; }
                $orig[$o]['a'][$u]   = true;
                $grupo[$cl]['a'][$u] = true;
                $toque[$tk]['a'][$u] = true;
                if ($cargo) {
                    $orig[$o]['c'][$u]   = true;
                    $grupo[$cl]['c'][$u] = true;
                    $toque[$tk]['c'][$u] = true;
                }
            }

            foreach (['promo', 'transaccional'] as $cl) {
                $out[$cl] = ['avisados' => count($grupo[$cl]['a']),
                             'cargaron' => count($grupo[$cl]['c']),
                             'pct' => $pc(count($grupo[$cl]['c']), count($grupo[$cl]['a']))];
            }
            /* Un jugador que toco ALGUN aviso cuenta como "toco": si abrio uno
               y otro no, lo que se quiere saber es si abrir mueve la aguja. */
            foreach ($toque['1']['a'] as $u => $_) {
                unset($toque['0']['a'][$u], $toque['0']['c'][$u]);
            }
            $out['toque'] = [
                'tocaron' => count($toque['1']['a']),
                'tocaron_cargaron' => count($toque['1']['c']),
                'tocaron_pct' => $pc(count($toque['1']['c']), count($toque['1']['a'])),
                'ignoraron' => count($toque['0']['a']),
                'ignoraron_cargaron' => count($toque['0']['c']),
                'ignoraron_pct' => $pc(count($toque['0']['c']), count($toque['0']['a'])),
            ];

            foreach ($orig as $o => $v) {
                $out['por_origen'][] = [
                    'origen' => $o, 'clase' => $v['clase'],
                    'avisados' => count($v['a']), 'cargaron' => count($v['c']),
                    'pct' => $pc(count($v['c']), count($v['a'])),
                ];
            }
            // De mayor a menor conversion: arriba lo que funciona.
            usort($out['por_origen'], fn($a, $b) => $b['pct'] <=> $a['pct']);
        } catch (Throwable $e) {
            error_log('crmnotif_efectividad: ' . $e->getMessage());
        }
        return $out;
    }

    function crmnotif_historial(PDO $pdo, array $opts = []): array
    {
        $usuario   = trim((string)($opts['usuario'] ?? ''));
        $desde     = trim((string)($opts['desde'] ?? ''));
        $hasta     = trim((string)($opts['hasta'] ?? ''));
        $tipo      = trim((string)($opts['tipo'] ?? ''));
        $pagina    = max(1, (int)($opts['pagina'] ?? 1));
        $porPagina = max(1, min(100, (int)($opts['por_pagina'] ?? 30)));

        $where  = ['(n.programada_en IS NULL OR n.programada_en <= UTC_TIMESTAMP())'];
        $params = [];

        if ($usuario !== '') {
            $where[] = "(n.usuario = ? COLLATE utf8mb4_unicode_ci
                         OR EXISTS (SELECT 1 FROM notificaciones_entregas e
                                     JOIN dispositivos d ON d.device_id = e.device_id
                                    WHERE e.notificacion_id = n.id
                                      AND d.usuario = ? COLLATE utf8mb4_unicode_ci))";
            $params[] = $usuario;
            $params[] = $usuario;
        }
        if ($desde !== '') { $where[] = 'n.creada_en >= ?'; $params[] = $desde . ' 00:00:00'; }
        if ($hasta !== '') { $where[] = 'n.creada_en <= ?'; $params[] = $hasta . ' 23:59:59'; }
        if ($tipo  !== '') { $where[] = 'n.tipo = ?'; $params[] = $tipo; }
        /* Solo lo MANDADO POR UN OPERADOR desde la vista Difusiones. El
           origen 'crm' NO sirve de discriminador: tambien lo llevan los
           avisos automaticos del CRM ("Un agente te respondio", el push de
           cargar fichas). Lo que si es inequivoco: un lote (masiva por
           filtro), un broadcast (usuario NULL: nadie mas manda a todos), y
           el origen 'difusion' que ahora estampa la accion notificar. */
        if (!empty($opts['solo_difusiones'])) {
            $where[] = "(n.lote_id IS NOT NULL OR n.usuario IS NULL OR n.origen = 'difusion')";
        }

        $whereSql = implode(' AND ', $where);

        // Agrupar por lote (o por id si no tiene lote, o sea, envíos "todos"/puntuales).
        $base = "FROM notificaciones n WHERE $whereSql GROUP BY COALESCE(n.lote_id, n.id)";

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 $base) x");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $offset = ($pagina - 1) * $porPagina;
        $st = $pdo->prepare(
            "SELECT MIN(n.id) AS id, n.usuario, n.titulo, n.cuerpo, n.tipo, n.origen,
                    n.lote_id, n.filtro_usado, MIN(n.creada_en) AS creada_en,
                    COUNT(*) AS alcance_filas
             $base
             ORDER BY MIN(n.creada_en) DESC
             LIMIT $porPagina OFFSET $offset"
        );
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        /* CUANTAS LLEGARON DE VERDAD. Hasta hoy el historial mostraba
           `alcance_filas`: cuantos avisos se CREARON. Nahuel: *"si yo quiero
           mandar una notificacion, quiero estar seguro de que esa notificacion
           llego"*. Crear no es llegar -- la push se entrega por DISPOSITIVO
           cuando el celular sondea, y si nadie sondea no llega a nadie (paso
           con la fidelizacion: 600 creadas, 0 entregadas).

           Va en una consulta aparte y no en el GROUP BY de arriba: un JOIN a
           `notificaciones_entregas` multiplicaria las filas y romperia
           `alcance_filas`, que cuenta otra cosa. */
        $entregas = [];
        $grupos = [];
        foreach ($items as $it) {
            $grupos[] = (int)($it['lote_id'] ?? 0) ?: (int)$it['id'];
        }
        if ($grupos) {
            try {
                $marcas = implode(',', array_fill(0, count($grupos), '?'));
                $qe = $pdo->prepare(
                    "SELECT COALESCE(n.lote_id, n.id) g,
                            COUNT(DISTINCT e.device_id) entregadas,
                            COUNT(DISTINCT CASE WHEN e.leida_en IS NOT NULL
                                                THEN e.device_id END) leidas
                       FROM notificaciones n
                       JOIN notificaciones_entregas e ON e.notificacion_id = n.id
                      WHERE COALESCE(n.lote_id, n.id) IN ($marcas)
                      GROUP BY g"
                );
                $qe->execute($grupos);
                foreach ($qe->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $entregas[(int)$f['g']] = [
                        'entregadas' => (int)$f['entregadas'],
                        'leidas'     => (int)$f['leidas'],
                    ];
                }
            } catch (Throwable $e) { /* sin la tabla: se informa 0 */ }
        }

        foreach ($items as &$it) {
            $it['id']            = (int)$it['id'];
            $it['alcance_filas'] = (int)$it['alcance_filas'];
            $g = (int)($it['lote_id'] ?? 0) ?: (int)$it['id'];
            $it['entregadas'] = $entregas[$g]['entregadas'] ?? 0;
            $it['leidas']     = $entregas[$g]['leidas'] ?? 0;
            $it['masivo']        = $it['lote_id'] !== null;
            $it['filtro'] = $it['filtro_usado'] ? json_decode($it['filtro_usado'], true) : null;
            unset($it['filtro_usado']);
        }
        unset($it);

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    // ------------------------- Presets de filtro ---------------------------

    function crmnotif_preset_guardar(PDO $pdo, string $nombre, array $filtro, ?string $operador = null): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') { return ['ok' => false, 'error' => 'Falta el nombre del preset']; }
        try {
            $pdo->prepare(
                "INSERT INTO notif_presets_filtro (nombre, filtro_json, creado_por)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE filtro_json = VALUES(filtro_json), actualizado_en = NOW()"
            )->execute([mb_substr($nombre, 0, 80), json_encode($filtro, JSON_UNESCAPED_UNICODE), $operador]);
            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('crmnotif_preset_guardar: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo guardar el preset'];
        }
    }

    function crmnotif_presets_listar(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT id, nombre, filtro_json, creado_por, creado_en
                   FROM notif_presets_filtro ORDER BY nombre ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(function ($r) {
            return [
                'id' => (int)$r['id'], 'nombre' => $r['nombre'],
                'filtro' => json_decode($r['filtro_json'], true),
                'creado_por' => $r['creado_por'], 'creado_en' => $r['creado_en'],
            ];
        }, $rows);
    }

    function crmnotif_preset_borrar(PDO $pdo, int $id): bool
    {
        try {
            $st = $pdo->prepare("DELETE FROM notif_presets_filtro WHERE id = ?");
            $st->execute([$id]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ------------------------- Bonos pendientes -----------------------------

    /**
     * Promete un bono a un usuario. tipo='giro' acredita de una el giro de
     * cortesía (no espera recarga); 'fichas'/'pct' quedan pendientes hasta
     * la próxima recarga acreditada de ese usuario (ver
     * crmnotif_bono_aplicar_en_recarga(), enganchada en rl_acreditar()).
     */
    function crmnotif_bono_crear(PDO $pdo, string $usuario, string $tipo, int $valor,
                                 ?string $prometidoPor = null, ?int $notificacionId = null): array
    {
        $usuario = trim($usuario);
        if ($usuario === '') { return ['ok' => false, 'error' => 'Falta el usuario']; }
        if (!in_array($tipo, CRMNOTIF_BONO_TIPOS, true)) {
            return ['ok' => false, 'error' => 'Tipo de bono inválido'];
        }
        if ($tipo !== 'giro' && $valor <= 0) {
            return ['ok' => false, 'error' => 'El valor tiene que ser mayor a 0'];
        }
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        if (!$st->fetchColumn()) {
            return ['ok' => false, 'error' => 'Ese usuario no existe'];
        }

        $pdo->beginTransaction();
        try {
            /* EL BONO NUEVO REEMPLAZA AL ANTERIOR, NO SE SUMA.
               EL PEDIDO (Nahuel, 19/09/2026): *"si a una persona le mandamos un
               bono del 20% y no lo usa, al siguiente día cuando le mandamos uno
               del 25%, ese debe ser el utilizable, no el anterior. El anterior
               debe ser dado de baja antes de enviarle uno, porque si no las
               personas tardarían una semana en volver y sumarían bonos
               superiores al 100%, cosa que no nos sería muy rentable"*.

               Sin esto la plata se apila de dos maneras distintas:
                 · `crmnotif_bono_aplicar_en_recarga` toma UNO por carga y el
                   MAS VIEJO (ORDER BY creado_en ASC LIMIT 1), así que el 20%
                   de ayer le gana al 25% de hoy -- justo al revés de lo que
                   corresponde;
                 · y el que no se aplicó queda pendiente para la carga
                   siguiente, así que al final se pagan todos igual, de a uno.

               SE DA DE BAJA SOLO SU MISMA FAMILIA. `fichas` y `pct` son plata
               sobre la carga y compiten entre sí; un `giro` de la ruleta es
               otra cosa y no tiene por qué perderse porque le llegó un
               porcentaje. Ese es el único motivo de la lista de tipos.

               EL PREMIO DE LA RULETA ENTRA EN LA REGLA, y esto se dio vuelta
               una vez. El 19/09/2026 lo deje afuera por mi cuenta razonando
               que un premio lo gano el jugador y no se lo mandamos nosotros;
               Nahuel lo corrigio ese mismo dia: *"los bonos que no se tienen
               que acumular son esos bonos clasicos, diarios y de ruleta y de
               juegos"*. O sea que la familia es UNA sola: todo lo que sea
               plata sobre la proxima carga compite por el mismo lugar, lo
               haya ganado o se lo hayamos regalado.

               EL BONO DE BIENVENIDA NO ESTA ACA Y SI SE ACUMULA -- *"los
               bonos de bienvenida si pueden acumularse con algun otro"*. No
               hace falta ninguna excepcion para eso: no vive en esta tabla.
               Lo aplica rl_bono_bienvenida_aplicar() contra `usuarios.bonus`
               en el momento de acreditar la primera carga, y rl_acreditar()
               los suma explicitamente (`$bono + $bonoPrometido`), asi que un
               jugador de landing puede cobrar su 50% de bienvenida Y el
               porcentaje que le prometio la campaña en la misma carga. Lo
               cuida t_bono_carga.php.

               Queda como 'cancelado' y no borrado: se ve en la ficha del
               jugador y en Auditoria que se le prometio y que lo reemplazo. */
            $reemplazados = 0;
            if ($tipo !== 'giro') {
                $up = $pdo->prepare(
                    "UPDATE bonos_pendientes
                        SET estado = 'cancelado'
                      WHERE usuario = ? AND estado = 'pendiente' AND tipo IN ('fichas','pct')"
                );
                $up->execute([mb_substr($usuario, 0, 50)]);
                $reemplazados = $up->rowCount();
            }

            $pdo->prepare(
                "INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, notificacion_id)
                 VALUES (?,?,?,?,?)"
            )->execute([mb_substr($usuario, 0, 50), $tipo, $tipo === 'giro' ? 0 : $valor, $prometidoPor, $notificacionId]);
            $bonoId = (int)$pdo->lastInsertId();

            if ($tipo === 'giro') {
                $pdo->prepare(
                    "INSERT INTO ruleta_giros_cortesia (usuario, bono_pendiente_id) VALUES (?,?)"
                )->execute([mb_substr($usuario, 0, 50), $bonoId]);
            }

            $pdo->commit();
            return ['ok' => true, 'id' => $bonoId, 'reemplazados' => $reemplazados];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('crmnotif_bono_crear: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo crear el bono'];
        }
    }

    /** $opts: usuario?, estado?. Sin filtros trae todo, más reciente primero. */
    function crmnotif_bono_listar(PDO $pdo, array $opts = []): array
    {
        $where  = ['1=1'];
        $params = [];
        $usuario = trim((string)($opts['usuario'] ?? ''));
        $estado  = trim((string)($opts['estado'] ?? ''));
        if ($usuario !== '') { $where[] = 'usuario = ?'; $params[] = $usuario; }
        if ($estado  !== '' && in_array($estado, ['pendiente', 'aplicado', 'cancelado'], true)) {
            $where[] = 'estado = ?'; $params[] = $estado;
        }
        $st = $pdo->prepare(
            "SELECT id, usuario, tipo, valor, estado, prometido_por, creado_en,
                    aplicado_en, recarga_id, notificacion_id
               FROM bonos_pendientes WHERE " . implode(' AND ', $where) . "
              ORDER BY creado_en DESC LIMIT 300"
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']    = (int)$r['id'];
            $r['valor'] = (int)$r['valor'];
            $r['recarga_id']      = $r['recarga_id'] !== null ? (int)$r['recarga_id'] : null;
            $r['notificacion_id'] = $r['notificacion_id'] !== null ? (int)$r['notificacion_id'] : null;
        }
        unset($r);
        return $rows;
    }

    function crmnotif_bono_editar(PDO $pdo, int $id, string $tipo, int $valor): array
    {
        if (!in_array($tipo, CRMNOTIF_BONO_TIPOS, true)) {
            return ['ok' => false, 'error' => 'Tipo de bono inválido'];
        }
        if ($tipo !== 'giro' && $valor <= 0) {
            return ['ok' => false, 'error' => 'El valor tiene que ser mayor a 0'];
        }
        // No se edita un giro a otro tipo (o viceversa): la fila de cortesía
        // ya se creó/no se creó al momento del alta, cambiar el tipo acá
        // dejaría esa tabla desincronizada. Solo se ajusta valor si el tipo
        // pedido coincide con el actual.
        $st = $pdo->prepare("SELECT tipo, estado FROM bonos_pendientes WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) { return ['ok' => false, 'error' => 'Ese bono no existe']; }
        if ($b['estado'] !== 'pendiente') {
            return ['ok' => false, 'error' => 'Ese bono ya no está pendiente'];
        }
        if ($b['tipo'] !== $tipo) {
            return ['ok' => false, 'error' => 'No se puede cambiar el tipo de un bono existente'];
        }
        $pdo->prepare("UPDATE bonos_pendientes SET valor = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$tipo === 'giro' ? 0 : $valor, $id]);
        return ['ok' => true];
    }

    /** Solo se puede borrar mientras sigue pendiente. Si era un giro, también
     *  se cancela el giro de cortesía asociado (si no se usó todavía). */
    function crmnotif_bono_borrar(PDO $pdo, int $id): bool
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT tipo FROM bonos_pendientes WHERE id = ? AND estado = 'pendiente' FOR UPDATE");
            $st->execute([$id]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) { $pdo->rollBack(); return false; }

            $pdo->prepare("UPDATE bonos_pendientes SET estado = 'cancelado' WHERE id = ?")->execute([$id]);
            if ($b['tipo'] === 'giro') {
                $pdo->prepare(
                    "DELETE FROM ruleta_giros_cortesia WHERE bono_pendiente_id = ? AND estado = 'pendiente'"
                )->execute([$id]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('crmnotif_bono_borrar: ' . $e->getMessage());
            return false;
        }
    }

    /** true si el usuario tiene un giro de cortesía pendiente de usar. */
    function crmnotif_cortesia_disponible(PDO $pdo, string $usuario): bool
    {
        if (trim($usuario) === '') { return false; }
        try {
            $st = $pdo->prepare(
                "SELECT 1 FROM ruleta_giros_cortesia WHERE usuario = ? AND estado = 'pendiente' LIMIT 1"
            );
            $st->execute([$usuario]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Aplica el bono de fichas/porcentaje pendiente más viejo de $usuario
     * (si tiene alguno) en el momento en que se le acredita una recarga.
     * Nunca lanza: la llama rl_acreditar() y un problema acá no puede hacer
     * que la recarga (ya efectivamente acreditada) parezca fallida.
     */
    function crmnotif_bono_aplicar_en_recarga(PDO $pdo, string $usuario, ?int $recargaId, int $montoRecarga): int
    {
        /* Devuelve el MONTO acreditado (0 si no habia bono): el caller lo suma
           a $recarga['bono'] para que rl_cargar_al_juego_auto lo deposite EN
           EL JUEGO junto con las fichas, igual que el bono de bienvenida.
           Antes devolvia void y el bono quedaba solo en usuarios.bonus -- el
           jugador cobraba un numero en el chat que en la plataforma no
           aparecia nunca. El deposito solo-bono despues DEBITA ese mismo
           monto del contador (bono_debitado), asi que no se juega dos veces. */
        try {
            /* EL MAS NUEVO, no el mas viejo. Decia `creado_en ASC`, asi que
               con dos pendientes se aplicaba el de ayer y no el de hoy. Desde
               que crear uno da de baja el anterior deberia haber siempre uno
               solo, pero el orden importa igual: si por una carrera o por un
               arreglo a mano quedaran dos, el que vale es el ULTIMO que se le
               prometio -- que es el que el jugador acaba de leer en el aviso. */
            $st = $pdo->prepare(
                "SELECT id, tipo, valor FROM bonos_pendientes
                  WHERE usuario = ? AND estado = 'pendiente' AND tipo IN ('fichas','pct')
                  ORDER BY creado_en DESC, id DESC LIMIT 1"
            );
            $st->execute([$usuario]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) { return 0; }

            $monto = $b['tipo'] === 'pct'
                ? (int)round($montoRecarga * ((int)$b['valor']) / 100)
                : (int)$b['valor'];
            if ($monto <= 0) { return 0; }

            if (!function_exists('crm_cargar')) { return 0; }
            $r = crm_cargar($pdo, $usuario, 'bono', $monto, 'Bono prometido', 'crm_bono');
            if (!$r['ok']) { return 0; }

            $pdo->prepare(
                "UPDATE bonos_pendientes SET estado='aplicado', aplicado_en=NOW(), recarga_id=?
                  WHERE id=? AND estado='pendiente'"
            )->execute([$recargaId ?: null, $b['id']]);

            // El festejo: hasta ahora el bono se aplicaba en silencio y el
            // jugador no tenia forma de enterarse de que lo cobro.
            if (function_exists('notif_crear')) {
                try {
                    $det = $b['tipo'] === 'pct'
                        ? 'tu bono del ' . (int)$b['valor'] . '%'
                        : 'tu bono prometido';
                    notif_crear($pdo, $usuario, '🎁 ¡Bono aplicado!',
                        'Se sumó ' . $det . ': +' . number_format($monto, 0, ',', '.')
                        . ' fichas junto con tu carga.', 'bono', null, 'crm_bono');
                } catch (Throwable $e) { /* el aviso nunca frena el bono */ }
            }
            return $monto;
        } catch (Throwable $e) {
            /* "Nunca lanza" tiene UNA excepcion: un deadlock (1213) dentro de
               la transaccion del caller ya la revirtio ENTERA del lado del
               server -- tragarlo aca dejaria a rl_acreditar() siguiendo (y
               despues "commiteando") una acreditacion que ya no existe. Se
               relanza para que el caller aborte limpio; crm_cargar() ya hace
               lo mismo. Cualquier otro fallo sigue siendo best-effort. */
            if ($pdo->inTransaction() && $e instanceof PDOException
                && ((string)($e->errorInfo[0] ?? '') === '40001'
                    || (int)($e->errorInfo[1] ?? 0) === 1213)) {
                throw $e;
            }
            error_log('crmnotif_bono_aplicar_en_recarga: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Aplica el bono pendiente cuando la plata entró SIN una recarga nuestra:
     * el camino A (botón «Depósitos» del juego, peticiones_cola.php) o la
     * carga manual del CRM (crm_saldo). Encontrado en la auditoría del
     * 18/09/2026: los bonos pendientes solo se aplicaban en rl_acreditar()
     * (camino B) — un jugador que ganaba en la ruleta y después cargaba por
     * el botón del juego no cobraba NUNCA. El bono de la app no tenía este
     * agujero porque notif_app_bono_liberar() sí está enganchado en los
     * cuatro caminos; esto empareja.
     *
     * En el camino B el bono viaja DENTRO del mismo depósito. Acá la plata ya
     * está en el juego, así que después de acreditarlo al contador se encola
     * un depósito solo-bono (monto 0, bono N) — el mismo mecanismo de
     * notif_app_bono_entregar(), que debita el contador al depositar: no se
     * paga dos veces. Best-effort entero: post-commit en los dos callers.
     */
    function crmnotif_bono_aplicar_fuera_de_recarga(PDO $pdo, string $usuario, int $montoCarga): int
    {
        $monto = crmnotif_bono_aplicar_en_recarga($pdo, $usuario, null, $montoCarga);
        if ($monto > 0 && function_exists('fichas_pedir_carga')) {
            try {
                fichas_pedir_carga($pdo, $usuario, 0, 'crm_bono', false, $monto);
            } catch (Throwable $e) {
                // El bono ya quedó en el contador: un agente lo manda con
                // «Bonos al juego». Peor sería marcarlo no-aplicado y pagarlo
                // de nuevo.
                error_log('crmnotif_bono_aplicar_fuera_de_recarga (deposito): ' . $e->getMessage());
            }
        }
        return $monto;
    }
}
