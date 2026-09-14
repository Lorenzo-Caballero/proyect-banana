<?php
/**
 * fidelizacion_lib.php — El motor de la campaña de fidelización.
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
        if (!function_exists('cfg_crm_activo') || !cfg_crm_activo($pdo, 'fid_activa')) {
            return ['ok' => true, 'avisados' => 0, 'motivo' => 'campaña apagada'];
        }
        $tramos = fid_tramos($pdo);
        if (!$tramos) {
            return ['ok' => true, 'avisados' => 0, 'motivo' => 'sin escalones'];
        }
        $minDias = $tramos[0]['dias'];

        /* Elegibles: con actividad conocida y al menos el primer escalón de
           inactividad. Baneados afuera. Los de MAS dias primero: si el tope
           corta, mejor avisarle antes al que hace mas que no vuelve. */
        $st = $pdo->prepare(
            "SELECT username, ultima_actividad,
                    TIMESTAMPDIFF(DAY, ultima_actividad, NOW()) AS dias_inactivo
               FROM usuarios
              WHERE ultima_actividad IS NOT NULL
                AND is_banned = 0
                AND ultima_actividad <= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY ultima_actividad ASC
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

        // 2) El bono: mejorar el de fidelización pendiente, o crear uno.
        //    NUNCA se baja un % ya prometido: si por config quedó un 50%
        //    pendiente y este escalón dice 30%, se respeta el 50 prometido.
        $bonoId = null;
        try {
            $st = $pdo->prepare(
                "SELECT id, valor FROM bonos_pendientes
                  WHERE usuario = ? AND estado = 'pendiente' AND tipo = 'pct'
                    AND prometido_por = 'fidelizacion'
                  ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$usuario]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if ($b) {
                $bonoId = (int)$b['id'];
                if ((int)$b['valor'] < $tramo['pct']) {
                    $pdo->prepare("UPDATE bonos_pendientes SET valor = ? WHERE id = ? AND estado = 'pendiente'")
                        ->execute([$tramo['pct'], $bonoId]);
                }
            } elseif (function_exists('crmnotif_bono_crear')) {
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

        // 3) El giro de cortesía, si el escalón lo trae y no tiene uno esperando.
        $conGiro = false;
        if (!empty($tramo['ruleta'])) {
            try {
                if (function_exists('crmnotif_cortesia_disponible')
                    && !crmnotif_cortesia_disponible($pdo, $usuario)
                    && function_exists('crmnotif_bono_crear')) {
                    $g2 = crmnotif_bono_crear($pdo, $usuario, 'giro', 0, 'fidelizacion');
                    $conGiro = !empty($g2['ok']);
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
                notif_crear($pdo, $usuario, '🎁 Un ' . $pct . '% extra te espera',
                            $cuerpo, 'promo', null, 'fidelizacion');
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
