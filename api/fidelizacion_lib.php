<?php
/**
 * fidelizacion_lib.php — El motor de la campaña de fidelización.
 *
 * A QUIEN LE HABLA (y por qué importa más que todo lo demás). La campaña es un
 * EMPUJÓN, y un empujón que no llega no es un empujón. Medido el 18/09/2026
 * sobre la única pasada que corrió: 600 bonos del 50% prometidos, 600 push
 * creadas, **0 entregadas** —ninguno de los 600 tenía la app—, 600 mensajes de
 * chat sin leer, y **0 jugadores volvieron**. 300.000 fichas comprometidas con
 * gente que no tenía forma de enterarse.
 *
 * Desde entonces el público se elige con `fid_publico` y el default es `app`:
 * solo quien tiene la app CON las notificaciones prendidas. Ver
 * fid_sql_publico().
 *
 * Jugadores que dejaron de jugar reciben, al CRUZAR cada escalón de
 * inactividad (2 días -> 20%, 3 -> 25%... configurable en el CRM), un empujón
 * automático para volver:
 *
 *   - un bono PORCENTUAL sobre su próxima carga (bonos_pendientes tipo 'pct',
 *     que crmnotif_bono_aplicar_en_recarga ya aplica solo al acreditarse la
 *     recarga, y desde ese mismo cambio el monto entra AL JUEGO junto con
 *     las fichas);
 *   - la push en el celular (notif_crear) y el mensaje de Camila en el chat
 *     (crm_avisar_jugador);
 *   - en los escalones marcados, un giro de cortesía de la ruleta (se otorga
 *     en el acto, lo consume ruleta.php?accion=girar_cortesia).
 *
 * La inactividad es la REAL: usuarios.ultima_actividad (migración 46, cinco
 * señales — jugar, cargar, chatear, loguearse). NULL = no sabemos, no se toca.
 *
 * Reglas que sostienen la campaña:
 *   - UN aviso por (jugador, escalón, racha). La racha es el ultima_actividad
 *     vigente: si vuelve a jugar, cambia, y el ciclo arranca de cero. El
 *     candado es el UNIQUE de `fidelizacion_avisos`, reservado ANTES de
 *     avisar (patrón ruleta_recordatorios): dos crons pisándose no duplican.
 *   - Los bonos NO se apilan: un jugador tiene A LO SUMO un bono de
 *     fidelización pendiente; al cruzar el siguiente escalón se le MEJORA el
 *     porcentaje en la misma fila. (Un bono manual del CRM es aparte y no se
 *     toca.)
 *   - El giro de cortesía no se duplica: solo se regala si no tiene uno
 *     pendiente.
 *
 * Requiere sql/65_fidelizacion.sql y config_crm (fid_activa, fid_tramos).
 */

declare(strict_types=1);

if (!function_exists('fid_tramos')) {

    /**
     * Los escalones configurados, saneados y ordenados por días ascendente.
     * Config rota o vacía => el default de CFG_CRM_DEFAULTS (una config
     * ilegible no puede apagar la campaña a la mitad).
     */
    function fid_tramos(PDO $pdo): array
    {
        $crudo = function_exists('cfg_crm') ? cfg_crm($pdo, 'fid_tramos') : null;
        $out = fid_parsear_tramos((string)($crudo ?? ''));
        if ($out === null) {
            $out = fid_parsear_tramos(CFG_CRM_DEFAULTS['fid_tramos'] ?? '') ?? [];
        }
        return $out;
    }

    /**
     * Valida un JSON de escalones. Devuelve la lista limpia u null si no
     * sirve. Es la MISMA validación que usa el guardado del CRM: lo que no
     * pasa por acá no se guarda, y lo guardado siempre parsea.
     */
    function fid_parsear_tramos(string $json): ?array
    {
        $arr = json_decode($json, true);
        if (!is_array($arr) || !$arr || count($arr) > 10) { return null; }
        $out = [];
        $vistos = [];
        foreach ($arr as $t) {
            if (!is_array($t)) { return null; }
            $dias = (int)($t['dias'] ?? 0);
            $pct  = (int)($t['pct'] ?? 0);
            if ($dias < 1 || $dias > 365 || $pct < 1 || $pct > 200) { return null; }
            if (isset($vistos[$dias])) { return null; }   // dos escalones con los mismos dias
            $vistos[$dias] = true;
            $out[] = ['dias' => $dias, 'pct' => $pct, 'ruleta' => !empty($t['ruleta']) ? 1 : 0];
        }
        usort($out, fn($a, $b) => $a['dias'] <=> $b['dias']);
        return $out;
    }

    /**
     * ¿ES HORA DE HABLARLE A ALGUIEN? Devuelve [abierta, desde, hasta].
     *
     * MEDIDO EL 19/09/2026 A LAS 02:37: la pasada de la 01:30 creo 14 avisos y
     * entrego CERO. No era un bug, era la madrugada -- la push se entrega
     * cuando el celular sondea y a esa hora no sondea nadie. Y le pega mas a
     * esta campaña que a ninguna otra, porque apunta justo a los que hace dias
     * que no abren la app.
     *
     * Se copia el patron de fichas_ventana_retiro(), incluido lo de evaluar en
     * hora ARGENTINA y no en la del server, que corre en UTC: con date('G') una
     * franja de 10 a 22 se aplicaria de 07 a 19 hora local.
     *
     * Config rota o incompleta = sin restriccion. Una campaña frenada por un
     * typo del operador es peor que un aviso de mas a las once de la noche.
     */
    function fid_ventana(PDO $pdo): array
    {
        $libre = ['abierta' => true, 'desde' => '', 'hasta' => ''];
        if (!function_exists('cfg_crm')) { return $libre; }
        $desde = trim((string)(cfg_crm($pdo, 'fid_hora_desde') ?? ''));
        $hasta = trim((string)(cfg_crm($pdo, 'fid_hora_hasta') ?? ''));
        if ($desde === '' || $hasta === '') { return $libre; }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $desde, $d)
            || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hasta, $h)) {
            error_log('fid_ventana: horario invalido (' . $desde . ' - ' . $hasta . ')');
            return $libre;
        }
        try {
            $ahoraAr = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        } catch (Throwable $e) { return $libre; }
        $ahora = (int)$ahoraAr->format('G') * 60 + (int)$ahoraAr->format('i');
        $ini   = (int)$d[1] * 60 + (int)$d[2];
        $fin   = (int)$h[1] * 60 + (int)$h[2];
        $dentro = $ini <= $fin
            ? ($ahora >= $ini && $ahora < $fin)          // franja normal
            : ($ahora >= $ini || $ahora < $fin);         // cruza la medianoche
        return ['abierta' => $dentro, 'desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * EL FILTRO DE PUBLICO, en SQL, segun `fid_publico`.
     *
     * POR QUE EXISTE (medido el 18/09/2026 sobre la unica pasada que corrio):
     *
     *     600 bonos del 50% prometidos
     *     600 notificaciones push creadas  ->  0 ENTREGADAS
     *     600 mensajes de chat escritos    ->  0 leidos
     *       0 jugadores volvieron
     *
     * Ninguno de los 600 tenia la app. Un bono que el jugador no sabe que
     * tiene no incentiva nada: es una deuda de 300.000 fichas y nada mas.
     *
     * La campaña es un EMPUJON, y un empujon que no llega no es un empujon.
     * Por eso el default apunta a quien puede recibirlo de verdad.
     *
     * `notificaciones = 1` no es un detalle: el SondeoWorker del APK chequea el
     * permiso ANTES de pedir la lista, asi que sin permiso la push no se ve
     * (y encima se consumiria el aviso). Tener la app con las notificaciones
     * apagadas es, para esto, igual que no tenerla.
     */
    function fid_sql_publico(PDO $pdo): string
    {
        $pub = function_exists('cfg_crm') ? trim((string)(cfg_crm($pdo, 'fid_publico') ?? '')) : '';
        if ($pub === 'todos') {
            return '';                         // como corria antes
        }
        if ($pub === 'contacto') {
            /* Los de la app MAS los que tienen el chat abierto: a estos el
               mensaje les queda esperando -- lo ven si entran, no antes. */
            return " AND (u.tiene_app = 1
                          OR EXISTS (SELECT 1 FROM conversaciones c
                                      WHERE c.clave = u.username COLLATE utf8mb4_unicode_ci))";
        }
        /* 'app' y cualquier valor raro: el lado seguro es el mas chico.

           SE EXIGE UN CELULAR DE VERDAD, no solo la marca `tiene_app`. Medido
           el 18/09/2026: 29 jugadores tienen el flag pero solo 21 tienen un
           dispositivo android con el permiso puesto. Los otros 8 desinstalaron
           o revocaron, y la push se les encolaria sin que la vea nadie -- que
           es exactamente el problema que esto viene a arreglar, en chico.

           `permitido = 1` es la verdad del terreno: la entrega es POR
           DISPOSITIVO (notificaciones_entregas), no por el flag del jugador. */
        return " AND u.tiene_app = 1 AND u.notificaciones = 1
                 AND EXISTS (SELECT 1 FROM dispositivos d
                              WHERE d.usuario COLLATE utf8mb4_unicode_ci
                                    = u.username COLLATE utf8mb4_unicode_ci
                                AND d.plataforma = 'android' AND d.permitido = 1)";
    }

    /**
     * UNA pasada de la campaña. La dispara el cron (fidelizacion.php) cada
     * hora; correrla dos veces seguidas no duplica nada (candado por racha).
     *
     * $tope: máximo de avisos por pasada — el primer día de campaña puede
     * haber cientos de inactivos viejos, y avisarles a todos junto saturaría
     * el chat del CRM y el sondeo de push. Los que no entren hoy entran en
     * las próximas pasadas (el candado no se reservó, siguen elegibles).
     */
    function fid_correr(PDO $pdo, int $tope = 300): array
    {
        // Latido ANTES del gate: aun con la campaña apagada, la pasada del
        // cron queda registrada -- asi la vista del CRM puede distinguir
        // "el cron no corre" de "la campaña esta apagada". Best-effort.
        try {
            if (function_exists('cfg_crm_guardar')) {
                cfg_crm_guardar($pdo, ['fid_visto_en' => date('Y-m-d H:i:s')], 'fidelizacion');
            }
        } catch (Throwable $e) { /* el latido nunca frena la campaña */ }

        if (!function_exists('cfg_crm_activo') || !cfg_crm_activo($pdo, 'fid_activa')) {
            return ['ok' => true, 'avisados' => 0, 'motivo' => 'campaña apagada'];
        }
        /* FUERA DE HORA NO SE LE HABLA A NADIE. Va DESPUES del latido a
           proposito: la pasada corrio, y la vigilancia de salud_colector.php
           tiene que verla viva -- que no sea hora de avisar no es que el cron
           se murio. */
        $ventana = fid_ventana($pdo);
        if (!$ventana['abierta']) {
            return ['ok' => true, 'avisados' => 0,
                    'motivo' => 'fuera de horario (' . $ventana['desde'] . ' a ' . $ventana['hasta'] . ')'];
        }
        $tramos = fid_tramos($pdo);
        if (!$tramos) {
            return ['ok' => true, 'avisados' => 0, 'motivo' => 'sin escalones'];
        }
        $minDias = $tramos[0]['dias'];

        /* HASTA CUANDO INSISTIR. Alguien que hace tres meses que no aparece no
           es un jugador enfriado: es uno que se fue. Y sin tope, todo el
           backlog viejo entra directo al escalon MAS CARO -- que es lo que
           paso en la unica pasada que corrio: 600 personas, todas al 50%,
           porque el motor le da a cada uno el escalon mas alto que ya cumplio.
           0 = sin tope (el comportamiento viejo). */
        /* Se lee con cfg_crm y no con fichas_limite(): este archivo no carga
           fichas_lib, y con `function_exists` en false el tope caia al default
           en silencio -- o sea que ponerlo en 0 no hacia nada. Lo agarro el
           test, que es para lo que esta. */
        $diasMaxRaw = function_exists('cfg_crm') ? cfg_crm($pdo, 'fid_dias_max') : null;
        $diasMax = ($diasMaxRaw === null || trim((string)$diasMaxRaw) === '')
                 ? 30                      // sin configurar: el default sano
                 : max(0, (int)$diasMaxRaw);   // 0 = sin tope, a proposito

        /* Elegibles: con actividad conocida, dentro de la ventana, y del
           publico configurado. Baneados afuera. Los de MAS dias primero: si el
           tope corta, mejor avisarle antes al que hace mas que no vuelve. */
        $st = $pdo->prepare(
            "SELECT u.username, u.ultima_actividad,
                    TIMESTAMPDIFF(DAY, u.ultima_actividad, NOW()) AS dias_inactivo
               FROM usuarios u
              WHERE u.ultima_actividad IS NOT NULL
                AND u.is_banned = 0
                /* Y EL BLOQUEO NUESTRO, que es otro. is_banned es el flag de
                   ganamos; bloqueado es el que pone el operador desde el CRM
                   --por comprobantes truchos, multicuenta, lo que sea-- y es
                   el que se usa de verdad.
                   Medido el 19/09/2026 en la primera pasada automatica: 4 de
                   los 14 avisados estaban bloqueados aca, con motivos como
                   cuenta trucha o comprobantes truchos escritos a mano por
                   Nahuel. Les estabamos ofreciendo un bono para que vuelvan. */
                AND u.bloqueado = 0
                AND u.ultima_actividad <= DATE_SUB(NOW(), INTERVAL ? DAY)"
            . ($diasMax > 0 ? " AND u.ultima_actividad >= DATE_SUB(NOW(), INTERVAL "
                              . $diasMax . " DAY)" : "")
            . fid_sql_publico($pdo) . "
              ORDER BY u.ultima_actividad ASC
              LIMIT 2000"
        );
        $st->execute([$minDias]);
        $candidatos = $st->fetchAll(PDO::FETCH_ASSOC);

        $avisados = 0;
        $porTramo = [];
        foreach ($candidatos as $c) {
            if ($avisados >= $tope) { break; }
            $dias = (int)$c['dias_inactivo'];

            // El escalón MAS ALTO que ya cumplió.
            $tramo = null;
            foreach ($tramos as $t) {
                if ($dias >= $t['dias']) { $tramo = $t; }
            }
            if ($tramo === null) { continue; }

            if (fid_avisar_uno($pdo, (string)$c['username'], (string)$c['ultima_actividad'],
                               $dias, $tramo)) {
                $avisados++;
                $porTramo[$tramo['dias']] = ($porTramo[$tramo['dias']] ?? 0) + 1;
            }
        }

        return ['ok' => true, 'avisados' => $avisados, 'por_tramo' => $porTramo,
                'candidatos' => count($candidatos)];
    }

    /**
     * El empujón a UN jugador para UN escalón. True si avisó (y armó el bono).
     * Todo best-effort menos el candado: si el candado no se pudo reservar,
     * no se hace NADA (ya se avisó este escalón en esta racha, o lo está
     * haciendo otra corrida ahora mismo).
     */
    function fid_avisar_uno(PDO $pdo, string $usuario, string $actividadRef,
                            int $diasInactivo, array $tramo): bool
    {
        // 1) EL CANDADO, antes que todo lo demás.
        try {
            $g = $pdo->prepare(
                "INSERT IGNORE INTO fidelizacion_avisos (usuario, dias, pct, ruleta, actividad_ref)
                 VALUES (?,?,?,?,?)"
            );
            $g->execute([$usuario, $tramo['dias'], $tramo['pct'], $tramo['ruleta'], $actividadRef]);
            if ($g->rowCount() !== 1) { return false; }
            $avisoId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('fid_avisar_uno (candado): ' . $e->getMessage());
            return false;
        }

        /* 2) El bono. SIEMPRE se crea uno nuevo, y crearlo da de baja el
              anterior (crmnotif_bono_crear).

              ANTES SE "MEJORABA EN EL LUGAR": se buscaba el pendiente de la
              campaña y se le subía el valor, sin bajarlo nunca. Daba el mismo
              resultado mientras la escalera subiera, pero tenía dos problemas:
              no dejaba rastro de que un 20% había sido reemplazado por un 25%
              --en la ficha y en Auditoría aparecía uno solo, como si siempre
              hubiera sido 25--, y no tocaba los bonos de OTRO origen, así que
              un bono manual viejo seguía compitiendo con el de la campaña.

              Consecuencia que conviene conocer: si se reconfiguran los
              escalones hacia abajo, el bono pendiente de alguien PUEDE bajar.
              Es lo pedido: manda el último que se le prometió, que es el que
              el jugador acaba de leer en el aviso. */
        $bonoId = null;
        try {
            if (function_exists('crmnotif_bono_crear')) {
                $r = crmnotif_bono_crear($pdo, $usuario, 'pct', $tramo['pct'], 'fidelizacion');
                if (!empty($r['ok'])) { $bonoId = (int)$r['id']; }
            }
            if ($bonoId !== null) {
                $pdo->prepare("UPDATE fidelizacion_avisos SET bono_id = ? WHERE id = ?")
                    ->execute([$bonoId, $avisoId]);
            }
        } catch (Throwable $e) {
            error_log('fid_avisar_uno (bono): ' . $e->getMessage());
        }

        /* 3) El giro de cortesía, si el escalón lo trae y no tiene uno esperando.

           CON LA RULETA APAGADA NO SE PROMETE NADA, y faltaba ese chequeo.
           Nahuel (16/09/2026): "les llega una notificación de que giren la
           ruleta pero creo que no está activa". Estaba en lo cierto:
           `ruleta_activa` figuraba en 0 y esta función igual mandaba "te
           regalé un giro de la ruleta, entrá y giralo cuando quieras" -- y
           `ruleta.php` lo rechaza justamente por ese flag. El jugador entra,
           no puede girar, y lo que se gana es desconfianza.

           El recordatorio diario (`ruleta_recordatorio.php`) ya lo chequeaba, y
           el APK también filtra sus textos de ruleta con el flag que baja en el
           sondeo. Este era el único camino que prometía sin mirar. */
        $conGiro = false;
        $giroId   = null;   // para atarlo al aviso, igual que el bono de %
        $ruletaOn = !function_exists('cfg_crm_activo') || cfg_crm_activo($pdo, 'ruleta_activa');
        if (!empty($tramo['ruleta']) && $ruletaOn) {
            try {
                if (function_exists('crmnotif_cortesia_disponible')
                    && !crmnotif_cortesia_disponible($pdo, $usuario)
                    && function_exists('crmnotif_bono_crear')) {
                    $g2 = crmnotif_bono_crear($pdo, $usuario, 'giro', 0, 'fidelizacion');
                    $conGiro = !empty($g2['ok']);
                    if ($conGiro) { $giroId = (int)$g2['id']; }
                } elseif (function_exists('crmnotif_cortesia_disponible')
                          && crmnotif_cortesia_disponible($pdo, $usuario)) {
                    $conGiro = true;   // ya lo tiene: el mensaje se lo recuerda igual
                }
            } catch (Throwable $e) {
                error_log('fid_avisar_uno (giro): ' . $e->getMessage());
            }
        }

        // 4) Los avisos. La push siempre; el chat solo si alguna vez chateó
        //    (crm_avisar_jugador devuelve false y no hace nada si no).
        $pct = (int)$tramo['pct'];
        try {
            if (function_exists('notif_crear')) {
                $cuerpo = 'Hace ' . $diasInactivo . ' días que no te vemos. Tu próxima carga '
                        . 'viene con un ' . $pct . '% extra de regalo, cargues lo que cargues.';
                if ($conGiro) { $cuerpo .= ' Y tenés un giro gratis de la ruleta esperándote.'; }
                $notifId = notif_crear($pdo, $usuario, '🎁 Un ' . $pct . '% extra te espera',
                                       $cuerpo, 'promo', null, 'fidelizacion');

                /* EL BONO QUEDA ATADO AL AVISO QUE LO PROMETIO. Sin esto no hay
                   forma de contestar la única pregunta que prueba algo:
                   *"quiero verificar que vengan efectivamente de la
                   notificación que se les envió... quizás una manera de medirlo
                   es si efectivamente reclamaron el bono que se les envió"*
                   (Nahuel, 19/09/2026).

                   Que alguien cargue DESPUES de un aviso es correlación --
                   pudo cargar igual. Que use el bono que ese aviso le prometió
                   es del aviso y de nada más. Medido el 19/09/2026 contra
                   producción: `notificacion_id` estaba en NULL en los 620 bonos
                   de la campaña, así que la medida daba 0 no porque nadie
                   cobrara sino porque nadie había guardado el vínculo.

                   Va acá y no en crmnotif_bono_crear porque el aviso se arma
                   DESPUES del bono (el texto necesita saber si hubo giro). */
                if ($notifId > 0) {
                    $ids = array_filter([$bonoId, $giroId]);
                    if ($ids) {
                        $pdo->prepare(
                            "UPDATE bonos_pendientes SET notificacion_id = ?
                              WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
                        )->execute(array_merge([$notifId], $ids));
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('fid_avisar_uno (push): ' . $e->getMessage());
        }
        try {
            if (function_exists('crm_avisar_jugador')) {
                $msg = '¡Hola! Hace ' . $diasInactivo . ' días que no te vemos por acá 😢 '
                     . 'Te dejé un ' . $pct . '% extra para tu próxima carga: cargás lo que '
                     . 'quieras y te sumo el bono al toque, solo.';
                if ($conGiro) {
                    $msg .= ' Y de paso te regalé un giro de la ruleta, entrá y giralo cuando quieras 🎰';
                }
                crm_avisar_jugador($pdo, $usuario, $msg);
            }
        } catch (Throwable $e) {
            error_log('fid_avisar_uno (chat): ' . $e->getMessage());
        }

        return true;
    }
}

if (!function_exists('fid_analitica')) {

    /**
     * SEGUIMIENTO DE LA CAMPAÑA: quién volvió, cuántos, y a los cuántos días.
     *
     * Pedido del dueño (18/09/2026): *"un analytic o algún gráfico que vaya
     * siguiendo qué usuario respondió a esa campaña, la tasa de conversión y
     * un promedio de a qué día vuelven a activarse"*.
     *
     * ========================================================================
     * LA UNIDAD ES LA RACHA, NO EL AVISO. Es la decisión que hace que el
     * número signifique algo: un mismo jugador recibe VARIOS avisos en la
     * misma racha de inactividad (2d, 3d, 4d, 7d...). Contando por aviso, el
     * que vuelve después del cuarto suma UNA vuelta contra CUATRO envíos y la
     * conversión sale cuatro veces más baja de lo que es. Peor: empeoraría
     * sola al agregar escalones, que es justo al revés de lo que el operador
     * está evaluando cuando los agrega.
     *
     * Una racha es (usuario, actividad_ref) — el UNIQUE de la migración 65.
     *
     * ========================================================================
     * "VOLVIÓ" ES QUE CARGÓ PLATA, no que abrió la app. Y la carga se mide con
     * publicidad_sql_cargas(), la definición única del CRM (ver CLAUDE.md): si
     * esta pantalla contara distinto que Finanzas, dos pantallas mostrarían
     * plata distinta el mismo día. Incluye las dos vías —transferencia y el
     * botón «Depósitos» del juego— porque la campaña no elige por dónde vuelve.
     *
     * ========================================================================
     * LA VENTANA DE ATRIBUCIÓN ES UNA ELECCIÓN, Y SE MUESTRA. Solo cuenta como
     * respuesta la carga que entra DENTRO de $ventana días del primer aviso de
     * esa racha. Sin ventana, el que vuelve tres meses después contaría como
     * converso y la campaña se atribuiría todo lo que pasa en el casino. 14
     * días es el default y el CRM deja cambiarlo para ver qué tan sensible es
     * el número — que es la forma honesta de mostrar una atribución.
     *
     * NO PRUEBA CAUSALIDAD y no puede: no hay grupo de control. Mide "de los
     * que avisamos, cuántos volvieron y cuándo".
     *
     * $dias    = ventana de ANÁLISIS: rachas cuyo primer aviso cae en N días.
     * $ventana = ventana de ATRIBUCIÓN: cuánto crédito se le da al aviso.
     *
     * Nunca lanza: una pantalla de métricas no puede tumbar el CRM.
     */
    function fid_analitica(PDO $pdo, int $dias = 30, int $ventana = 14): array
    {
        $dias    = max(1, min(365, $dias));
        $ventana = max(1, min(90, $ventana));

        $vacio = ['ok' => false, 'dias' => $dias, 'ventana' => $ventana,
                  'resumen' => null, 'por_escalon' => [], 'dist_dias' => [],
                  'serie' => [], 'quienes' => []];

        if (!function_exists('publicidad_sql_cargas')) {
            $lib = __DIR__ . '/publicidad_lib.php';
            if (is_file($lib)) { require_once $lib; }
        }
        if (!function_exists('publicidad_sql_cargas')) { return $vacio; }
        $CARGAS = publicidad_sql_cargas();

        try {
            /* Una fila por RACHA. `escalon_ultimo` es el escalón más alto que
               llegó a recibir: es el que se lleva el crédito (atribución al
               último toque, lo único defendible cuando hubo varios avisos).
               El PRIMER aviso ancla la ventana: el reloj arranca cuando se lo
               empezó a buscar, no en el último empujón. */
            $sqlRachas =
                "SELECT a.usuario, a.actividad_ref,
                        MIN(a.enviado_en)                  AS primer_aviso,
                        MAX(a.dias)                        AS escalon_ultimo,
                        MAX(a.pct)                         AS pct_max,
                        MAX(a.ruleta)                      AS con_giro,
                        COUNT(*)                           AS avisos
                   FROM fidelizacion_avisos a
                  WHERE a.enviado_en >= DATE_SUB(NOW(), INTERVAL $dias DAY)
                  GROUP BY a.usuario, a.actividad_ref";

            /* A cada racha se le cuelga su primera carga posterior dentro de
               la ventana. Subconsulta correlacionada y no un JOIN con GROUP
               BY: la tabla de avisos es chica y así la ventana se aplica por
               fila, sin arrastrar toda la tabla de cargas. */
            $sql =
                "SELECT r.*,
                        (SELECT MIN(c.cuando) FROM ($CARGAS) c
                          WHERE c.usuario = r.usuario COLLATE utf8mb4_unicode_ci
                            AND c.cuando > r.primer_aviso
                            AND c.cuando <= DATE_ADD(r.primer_aviso, INTERVAL $ventana DAY)
                        ) AS volvio_en,
                        (SELECT COALESCE(SUM(c.monto),0) FROM ($CARGAS) c
                          WHERE c.usuario = r.usuario COLLATE utf8mb4_unicode_ci
                            AND c.cuando > r.primer_aviso
                            AND c.cuando <= DATE_ADD(r.primer_aviso, INTERVAL $ventana DAY)
                        ) AS monto
                   FROM ($sqlRachas) r
                  ORDER BY r.primer_aviso DESC";

            $rachas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Sin la migración 65: la pantalla muestra "todavía no hay datos",
            // no un error.
            error_log('fid_analitica: ' . $e->getMessage());
            return $vacio;
        }

        $totRachas = count($rachas);
        $volvieron = 0;
        $monto     = 0.0;
        $horas     = [];      // horas hasta volver: promedio y mediana
        $porEsc    = [];      // escalon => [avisadas, volvieron, monto]
        $dist      = [];      // dia (0 = mismo dia) => cuantos volvieron ese dia
        $serie     = [];      // dia => [avisadas, volvieron]
        $quienes   = [];

        foreach ($rachas as $r) {
            $esc = (int)$r['escalon_ultimo'];
            $dia = substr((string)$r['primer_aviso'], 0, 10);
            if (!isset($porEsc[$esc])) { $porEsc[$esc] = ['avisadas' => 0, 'volvieron' => 0, 'monto' => 0.0]; }
            if (!isset($serie[$dia]))  { $serie[$dia]  = ['avisadas' => 0, 'volvieron' => 0]; }
            $porEsc[$esc]['avisadas']++;
            $serie[$dia]['avisadas']++;

            $vol = $r['volvio_en'] ?? null;
            $h = null;
            if ($vol !== null && $vol !== '') {
                $volvieron++;
                $porEsc[$esc]['volvieron']++;
                $serie[$dia]['volvieron']++;
                $m = (float)$r['monto'];
                $monto += $m;
                $porEsc[$esc]['monto'] += $m;
                $h = (strtotime((string)$vol) - strtotime((string)$r['primer_aviso'])) / 3600;
                if ($h >= 0) {
                    $horas[] = $h;
                    /* A QUE DIA VOLVIO, para el histograma. El dia 0 es "antes
                       de las 24 h" y se rotula «mismo dia»: es la barra que
                       contesta de un vistazo si el empujon funciona en el acto
                       o tarda. floor y no round, porque a las 20 h todavia no
                       paso un dia. */
                    $dd = (int)floor($h / 24);
                    $dist[$dd] = ($dist[$dd] ?? 0) + 1;
                }
            }

            /* QUIÉN respondió — y quién no. Las dos, porque "a quién no le
               funcionó" es la mitad útil: el que recibió el 50% y no volvió no
               es un jugador enfriado, es uno que se fue. */
            $quienes[] = [
                'usuario'   => (string)$r['usuario'],
                'escalon'   => $esc,
                'pct'       => (int)$r['pct_max'],
                'giro'      => (int)$r['con_giro'] === 1,
                'avisos'    => (int)$r['avisos'],
                'aviso_en'  => (string)$r['primer_aviso'],
                'volvio_en' => ($vol !== null && $vol !== '') ? (string)$vol : null,
                'horas'     => $h !== null ? round($h, 1) : null,
                'monto'     => ($vol !== null && $vol !== '') ? (float)$r['monto'] : 0.0,
            ];
        }

        /* MEDIANA ADEMÁS DEL PROMEDIO, y no es rebuscado: con pocos casos, uno
           que vuelve al día 13 mueve el promedio de 1,5 a 4 y el operador lee
           "vuelven a la semana" cuando la mayoría vuelve al otro día. La
           mediana describe al jugador típico. */
        sort($horas);
        $n = count($horas);
        $prom = $n ? array_sum($horas) / $n : null;
        $med  = $n ? ($n % 2 ? $horas[intdiv($n, 2)]
                             : ($horas[$n / 2 - 1] + $horas[$n / 2]) / 2) : null;
        $enDias = static function (?float $h): ?float {
            return $h === null ? null : round($h / 24, 1);
        };

        ksort($porEsc);
        $escSalida = [];
        foreach ($porEsc as $d => $v) {
            $escSalida[] = [
                'dias'      => $d,
                'avisadas'  => $v['avisadas'],
                'volvieron' => $v['volvieron'],
                'tasa'      => $v['avisadas'] ? round($v['volvieron'] * 100 / $v['avisadas'], 1) : 0,
                'monto'     => round($v['monto'], 2),
            ];
        }
        /* RELLENAR LOS DIAS VACIOS hasta el ultimo con gente. Un dia sin
           vueltas es un cero, no un hueco: sin esto el histograma saltea el
           dia 2 y el dia 3 queda pegado al 1, mostrando una forma que no es. */
        ksort($dist);
        $distSalida = [];
        if ($dist) {
            $maxDia = max(array_keys($dist));
            for ($d = 0; $d <= $maxDia; $d++) {
                $distSalida[] = ['dia' => $d, 'n' => (int)($dist[$d] ?? 0)];
            }
        }

        ksort($serie);
        $serieSalida = [];
        foreach ($serie as $d => $v) {
            $serieSalida[] = ['dia' => $d, 'avisadas' => $v['avisadas'], 'volvieron' => $v['volvieron']];
        }

        /* Cuántas rachas siguen DENTRO de su ventana de atribución. Sin esto,
           la tasa de los últimos días parece desplomarse —a esa gente todavía
           no le dimos tiempo de volver—, y verlo es la diferencia entre "la
           campaña dejó de funcionar" y "esperá tres días". */
        $enCurso = 0;
        $corte = time() - $ventana * 86400;
        foreach ($rachas as $r) {
            if (empty($r['volvio_en']) && strtotime((string)$r['primer_aviso']) > $corte) { $enCurso++; }
        }

        return [
            'ok'      => true,
            'dias'    => $dias,
            'ventana' => $ventana,
            'resumen' => [
                'rachas'    => $totRachas,
                'volvieron' => $volvieron,
                'tasa'      => $totRachas ? round($volvieron * 100 / $totRachas, 1) : 0,
                'en_curso'  => $enCurso,
                'monto'     => round($monto, 2),
                'dias_prom' => $enDias($prom),
                'dias_med'  => $enDias($med),
                'ticket'    => $volvieron ? round($monto / $volvieron, 2) : 0,
            ],
            'por_escalon' => $escSalida,
            'dist_dias'   => $distSalida,
            'serie'       => $serieSalida,
            'quienes'     => array_slice($quienes, 0, 200),
        ];
    }
}
