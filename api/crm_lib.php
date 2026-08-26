<?php
/**
 * crm_lib.php — Logica del CRM reutilizable.
 *
 *   - crm_registrar_turno(): guarda un turno del chatbot (user + bot) en la
 *     conversacion de esa sesion. Lo llama chatbot.php.
 *   - crm_cargar(): suma fichas o bono a un jugador y deja el movimiento en el
 *     historial. Lo usa crm.php (y podria usarlo la ruleta/recargas).
 *
 * Requiere un $pdo (PDO) ya conectado (lo pasa quien la incluye).
 */

declare(strict_types=1);

if (!function_exists('crm_conversacion_id')) {

    /** Devuelve el id de la conversacion, UNA POR NOMBRE DE USUARIO.
     *
     *  - Con usuario: chat identificado por `clave` = usuario. Si todavia no
     *    existe pero hay un chat anonimo de esta sesion, lo "adopta" (le pone el
     *    usuario). Si el usuario dice OTRO nombre, cae en OTRO chat.
     *  - Sin usuario: chat anonimo por sesion (clave = 'anon:'+session_id). */
    function crm_conversacion_id(PDO $pdo, string $sessionId, ?string $usuario = null): int
    {
        $usuario   = $usuario !== null ? trim($usuario) : '';
        $sessionId = substr($sessionId, 0, 64);
        $anon      = 'anon:' . $sessionId;

        if ($usuario !== '') {
            $u = mb_substr($usuario, 0, 50);
            // 1) ya existe el chat de ese usuario
            $st = $pdo->prepare("SELECT id FROM conversaciones WHERE clave = ? LIMIT 1");
            $st->execute([$u]);
            if ($id = $st->fetchColumn()) {
                // El jugador puede volver desde OTRO navegador/dispositivo. La
                // conversacion es UNA por usuario, pero la ENTREGA de respuestas
                // (mis_mensajes.php) empareja por session_id. Si no lo movemos al
                // sid actual, las respuestas del agente no le llegan al dispositivo
                // nuevo (se quedan pegadas al session_id viejo). Por eso lo
                // actualizamos cada vez que el jugador escribe desde aca.
                $pdo->prepare("UPDATE conversaciones SET session_id = ? WHERE id = ?")
                    ->execute([$sessionId, (int)$id]);
                return (int)$id;
            }
            // 2) adoptar el chat anonimo de esta sesion (asi el saludo queda en el mismo hilo)
            $st = $pdo->prepare("SELECT id FROM conversaciones WHERE clave = ? LIMIT 1");
            $st->execute([$anon]);
            if ($id = $st->fetchColumn()) {
                $pdo->prepare("UPDATE conversaciones SET clave = ?, usuario = ? WHERE id = ?")
                    ->execute([$u, $u, (int)$id]);
                return (int)$id;
            }
            // 3) nuevo chat para ese usuario
            $pdo->prepare("INSERT INTO conversaciones (session_id, usuario, clave) VALUES (?,?,?)")
                ->execute([$sessionId, $u, $u]);
            return (int)$pdo->lastInsertId();
        }

        // anonimo: pero si esta sesion (mismo celular) YA se identifico antes,
        // seguimos en el chat de esa persona en vez de abrir uno anonimo nuevo.
        $st = $pdo->prepare(
            "SELECT usuario FROM conversaciones
             WHERE session_id = ? AND usuario IS NOT NULL AND usuario <> ''
             ORDER BY actualizada_en DESC LIMIT 1"
        );
        $st->execute([$sessionId]);
        if ($prev = $st->fetchColumn()) {
            return crm_conversacion_id($pdo, $sessionId, (string)$prev);
        }

        // primera vez, todavia sin identificar
        $st = $pdo->prepare("SELECT id FROM conversaciones WHERE clave = ? LIMIT 1");
        $st->execute([$anon]);
        if ($id = $st->fetchColumn()) { return (int)$id; }
        $pdo->prepare("INSERT INTO conversaciones (session_id, usuario, clave) VALUES (?,?,?)")
            ->execute([$sessionId, null, $anon]);
        return (int)$pdo->lastInsertId();
    }

    /** Encola una accion de SALDO real (la ejecuta el worker Python en ganamos)
     *  y la deja en el historial. NO toca usuarios.balance (ese lo maneja ganamos
     *  y lo pisa el sync). tipo: 'cargar' | 'retirar'.
     *  $operador: username del operador que la disparo (Fase 0.5), o null si
     *  vino de un flujo sin sesion (no debería pasar desde crm.php despues del
     *  Paso 4, pero el parametro es opcional para no romper otros callers). */
    function crm_saldo(PDO $pdo, string $usuario, string $tipo, float $monto, string $motivo = '', ?string $operador = null): array
    {
        if ($monto <= 0) { return ['ok' => false, 'error' => 'El monto debe ser mayor a 0']; }
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'El usuario no existe']; }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO acciones_saldo (usuario, tipo, monto, motivo) VALUES (?,?,?,?)")
                ->execute([$usuario, $tipo, $monto, $motivo !== '' ? $motivo : null]);
            $signed = ($tipo === 'retirar') ? -$monto : $monto;
            $pdo->prepare("INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, operador) VALUES (?,?,?,?,?,?)")
                ->execute([$usuario, 'saldo', (int)round($signed), $motivo !== '' ? $motivo : null, 'crm', $operador]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('crm_saldo: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo registrar'];
        }
        return ['ok' => true];
    }

    /** Inserta un mensaje en una conversacion. $meta es un array opcional (se guarda JSON). */
    function crm_mensaje(PDO $pdo, int $convId, string $rol, string $texto,
                         ?array $meta = null, ?string $operador = null): void
    {
        // La columna `operador` existe desde la migración 30. Si no está
        // (migración sin correr), se cae al INSERT clásico de 4 columnas para
        // no romper el chat.
        try {
            $pdo->prepare(
                "INSERT INTO mensajes (conversacion_id, rol, operador, texto, meta) VALUES (?,?,?,?,?)"
            )->execute([
                $convId, $rol,
                ($operador !== null && $operador !== '') ? mb_substr($operador, 0, 60) : null,
                $texto,
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            $pdo->prepare(
                "INSERT INTO mensajes (conversacion_id, rol, texto, meta) VALUES (?,?,?,?)"
            )->execute([$convId, $rol, $texto, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
        }
    }

    /**
     * ¿Esta base ya tiene `conversaciones.archivada` (migracion 41)?
     *
     * Se pregunta en vez de darlo por hecho: una migracion sin correr no puede
     * dejar el CRM sin lista de conversaciones ni tragarse un mensaje del
     * jugador. Ya paso cuatro veces en este proyecto. Una sola consulta por
     * request, cacheada en un static.
     */
    function crm_hay_archivada(PDO $pdo): bool
    {
        /* El cache va POR CONEXION, no por proceso. Esto es multi-tenant: un
           script que recorre varias bases en la misma corrida se llevaria la
           respuesta de la primera, y si esa tenia la columna y la siguiente
           no, el UPDATE de crm_registrar_turno falla y se PIERDE el mensaje
           del jugador. Un static suelto es barato hasta el dia que no. */
        static $porConexion = [];
        $k = spl_object_id($pdo);
        if (!array_key_exists($k, $porConexion)) {
            try {
                // Un SELECT que no trae filas y no depende del dialecto:
                // SHOW COLUMNS es solo de MySQL y no se puede probar afuera.
                $pdo->query("SELECT archivada FROM conversaciones LIMIT 0");
                $porConexion[$k] = true;
            } catch (Throwable $e) {
                $porConexion[$k] = false;
            }
        }
        return $porConexion[$k];
    }

    /** Guarda un turno del chatbot. Nunca rompe el chat: si falla, solo loguea. */
    function crm_registrar_turno(PDO $pdo, string $sessionId, string $textoUser,
                                 string $textoBot, ?string $usuario = null): void
    {
        if ($sessionId === '') { return; }
        try {
            $pdo->beginTransaction();
            $convId = crm_conversacion_id($pdo, $sessionId, $usuario);
            if ($textoUser !== '') { crm_mensaje($pdo, $convId, 'user', $textoUser); }
            if ($textoBot  !== '') { crm_mensaje($pdo, $convId, 'bot',  $textoBot); }
            $preview = mb_substr($textoBot !== '' ? $textoBot : $textoUser, 0, 280);

            /* Si el jugador VUELVE A ESCRIBIR, la conversacion vuelve a la
               bandeja. Archivar es "por ahora no me interesa", no "mutear a
               esta persona para siempre": sin esto, alguien archivado que
               escribe con un problema de plata no le aparece a nadie nunca
               mas, y el agente ni se entera de que lo esta ignorando.

               Solo cuando hablo EL: un turno donde solo contesta el bot no
               desarchiva nada. Y las difusiones masivas tampoco tocan esto a
               proposito -- ver crm_difusion_chat_aplicar(). */
            $desarchiva = ($textoUser !== '' && crm_hay_archivada($pdo))
                ? ', archivada = 0, archivada_en = NULL, archivada_por = NULL'
                : '';
            $pdo->prepare(
                "UPDATE conversaciones
                    SET preview = ?, no_leidos = no_leidos + 1$desarchiva
                  WHERE id = ?"
            )->execute([$preview, $convId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('crm_registrar_turno: ' . $e->getMessage());
        }
    }

    /** Suma fichas ('ficha') o bono ('bono') a un jugador y registra el movimiento.
     *  Devuelve ['ok'=>bool, 'saldo'=>int] o ['ok'=>false,'error'=>...].
     *  $operador: username del operador que la disparo (Fase 0.5), o null —
     *  la ruleta (ruleta.php) tambien llama esta funcion y ahi no hay operador,
     *  es el jugador reclamando su propio premio. */
    function crm_cargar(PDO $pdo, string $usuario, string $tipo, int $monto,
                        string $motivo = '', string $origen = 'crm', ?string $operador = null): array
    {
        $col = ($tipo === 'bono') ? 'bonus' : 'coins';   // fichas = coins
        $st = $pdo->prepare("SELECT $col AS saldo FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        $saldo = $st->fetchColumn();
        if ($saldo === false) {
            return ['ok' => false, 'error' => 'El usuario no existe en usuarios'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE usuarios SET $col = $col + ? WHERE username = ?")
                ->execute([$monto, $usuario]);
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, operador) VALUES (?,?,?,?,?,?)"
            )->execute([$usuario, $tipo, $monto, $motivo !== '' ? $motivo : null, $origen, $operador]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('crm_cargar: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo cargar'];
        }
        return ['ok' => true, 'saldo' => (int)$saldo + $monto];
    }

    /** Bitácora de acciones administrativas del CRM sin fila propia donde
     *  anotar quién/cuándo (ej: liberar retiros trabados, que es masivo, sin
     *  id puntual — ver CRM_DESIGN.md Fase 0.5). Requiere sql/22_crm_bitacora.sql. */
    function crm_bitacora(PDO $pdo, string $operador, string $accion, string $detalle = ''): void
    {
        $pdo->prepare(
            "INSERT INTO crm_bitacora (operador, accion, detalle) VALUES (?, ?, ?)"
        )->execute([$operador, mb_substr($accion, 0, 60), mb_substr($detalle, 0, 300)]);
    }

    /**
     * Rate limit generico por CLAVE (archivo temporal, timestamps separados
     * por coma). Nacio en crm_retiros.php como crm_cancelar_limite(), atado
     * solo a "cancelar retiro"; al necesitar lo mismo para "cancelar
     * recarga" (Fase A, Módulo 3) se generaliza aca adentro en vez de
     * triplicar el mismo bloque de diez lineas por tercera vez.
     *
     * Deliberadamente separado de crm_login.php::crm_login_limite() y de
     * api/auth.php::_limite() (login de operador y login de jugadores en
     * produccion, ya probados) -- esos dos no se tocan.
     *
     * $clave: identifica el bucket, ej. "cancelar_retiro_<operador>" o
     * "cancelar_carga_<operador>" -- quien llama arma la clave para que dos
     * acciones distintas del mismo operador no compartan cupo.
     */
    function crm_rate_limite(string $clave, int $max, int $ventanaSeg): bool
    {
        $f = sys_get_temp_dir() . '/crm_rl_' . md5($clave);
        $ahora = time();
        $hits = [];
        if (is_file($f)) {
            foreach (explode(',', (string)@file_get_contents($f)) as $t) {
                if ($t !== '' && (int)$t > $ahora - $ventanaSeg) {
                    $hits[] = (int)$t;
                }
            }
        }
        if (count($hits) >= $max) {
            return false;
        }
        $hits[] = $ahora;
        @file_put_contents($f, implode(',', $hits), LOCK_EX);
        return true;
    }
}

if (!function_exists('crm_difusion_chat_aplicar')) {
    /**
     * Inserta $texto como mensaje del bot en la conversación de UN usuario
     * (si $usuario viene) o en TODAS las conversaciones con usuario vinculado
     * (si $usuario es null — difusión masiva). Solo toca conversaciones que
     * YA EXISTEN: no crea una nueva para jugadores que nunca hablaron con el
     * bot (evitar generar miles de chats vacíos de golpe).
     *
     * Devuelve la cantidad de conversaciones donde se insertó.
     */
    function crm_difusion_chat_aplicar(PDO $pdo, ?string $usuario, string $texto): int
    {
        $texto = trim($texto);
        if ($texto === '') { return 0; }

        if ($usuario !== null && $usuario !== '') {
            $st = $pdo->prepare("SELECT id FROM conversaciones WHERE usuario = ? LIMIT 1");
            $st->execute([mb_substr($usuario, 0, 50)]);
            $id = $st->fetchColumn();
            if (!$id) { return 0; }
            crm_mensaje($pdo, (int)$id, 'bot', $texto);
            $pdo->prepare("UPDATE conversaciones SET preview = ?, no_leidos = no_leidos + 1, actualizada_en = NOW() WHERE id = ?")
                ->execute([mb_substr($texto, 0, 280), $id]);
            return 1;
        }

        // Masiva: todas las conversaciones con usuario vinculado. Se recorre
        // de a una (no un INSERT masivo) porque cada fila necesita su propio
        // conversacion_id en `mensajes` — a la escala de un CRM de agencia
        // (cientos/pocos miles de chats) esto es instantáneo.
        $ids = $pdo->query("SELECT id FROM conversaciones WHERE usuario IS NOT NULL AND usuario <> ''")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $upd = $pdo->prepare("UPDATE conversaciones SET preview = ?, no_leidos = no_leidos + 1, actualizada_en = NOW() WHERE id = ?");
        $previewTxt = mb_substr($texto, 0, 280);
        foreach ($ids as $id) {
            crm_mensaje($pdo, (int)$id, 'bot', $texto);
            $upd->execute([$previewTxt, $id]);
        }
        return count($ids);
    }
}

if (!function_exists('crm_difusiones_chat_listar')) {
    /** Difusiones de chat PROGRAMADAS que todavía no salieron, con la fecha ya
     *  convertida a hora de Argentina para mostrar (igual criterio que
     *  notif_programadas_listar). */
    function crm_difusiones_chat_listar(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT id, usuario, texto, programada_en FROM difusiones_chat
                  WHERE procesada_en IS NULL AND programada_en > UTC_TIMESTAMP()
                  ORDER BY programada_en ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // sin tabla (migración 32 no corrida)
        }
        return array_map(function ($r) {
            $ar = '';
            try {
                $dt = new DateTime($r['programada_en'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
                $ar = $dt->format('Y-m-d H:i');
            } catch (Throwable $e) {}
            return ['id' => (int)$r['id'], 'usuario' => $r['usuario'], 'texto' => $r['texto'],
                    'programada_en_ar' => $ar];
        }, $rows);
    }

    /** Cancela una difusión de chat que todavía no salió. */
    function crm_difusion_chat_cancelar(PDO $pdo, int $id): bool
    {
        try {
            $st = $pdo->prepare(
                "DELETE FROM difusiones_chat WHERE id = ? AND procesada_en IS NULL"
            );
            $st->execute([$id]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('crm_parse_programada')) {
    /**
     * Convierte la fecha/hora que tipeó el agente (hora de Argentina, formato
     * "YYYY-MM-DD HH:MM" o el "YYYY-MM-DDTHH:MM" de un <input datetime-local>)
     * a un datetime en UTC "YYYY-MM-DD HH:MM:SS", que es como se guarda y como
     * se compara al procesar (con UTC_TIMESTAMP()). Así no depende de la zona
     * del server ni de la conexión MySQL. Devuelve null si es inválida o ya
     * pasó. La usan crm.php (difusiones push) y el cron de difusiones de chat.
     */
    function crm_parse_programada(string $raw): ?string
    {
        $raw = trim(str_replace('T', ' ', $raw));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw)) { return null; }
        try {
            $ar  = new DateTime($raw, new DateTimeZone('America/Argentina/Buenos_Aires'));
            $now = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
            // Margen de 1 min: si eligió "ahora mismo", lo tomamos como inmediato válido.
            if ($ar->getTimestamp() < $now->getTimestamp() - 60) { return null; }
            $ar->setTimezone(new DateTimeZone('UTC'));
            return $ar->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
