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
              WHERE NOT EXISTS (
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
              WHERE NOT EXISTS (
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

        foreach ($items as &$it) {
            $it['id']            = (int)$it['id'];
            $it['alcance_filas'] = (int)$it['alcance_filas'];
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
            return ['ok' => true, 'id' => $bonoId];
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
    function crmnotif_bono_aplicar_en_recarga(PDO $pdo, string $usuario, int $recargaId, int $montoRecarga): void
    {
        try {
            $st = $pdo->prepare(
                "SELECT id, tipo, valor FROM bonos_pendientes
                  WHERE usuario = ? AND estado = 'pendiente' AND tipo IN ('fichas','pct')
                  ORDER BY creado_en ASC LIMIT 1"
            );
            $st->execute([$usuario]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) { return; }

            $monto = $b['tipo'] === 'pct'
                ? (int)round($montoRecarga * ((int)$b['valor']) / 100)
                : (int)$b['valor'];
            if ($monto <= 0) { return; }

            if (!function_exists('crm_cargar')) { return; }
            $r = crm_cargar($pdo, $usuario, 'bono', $monto, 'Bono prometido', 'crm_bono');
            if (!$r['ok']) { return; }

            $pdo->prepare(
                "UPDATE bonos_pendientes SET estado='aplicado', aplicado_en=NOW(), recarga_id=?
                  WHERE id=? AND estado='pendiente'"
            )->execute([$recargaId, $b['id']]);
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
    }
}
