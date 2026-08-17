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
            if ($id = $st->fetchColumn()) { return (int)$id; }
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
    function crm_mensaje(PDO $pdo, int $convId, string $rol, string $texto, ?array $meta = null): void
    {
        $pdo->prepare(
            "INSERT INTO mensajes (conversacion_id, rol, texto, meta) VALUES (?,?,?,?)"
        )->execute([$convId, $rol, $texto, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
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
            $pdo->prepare(
                "UPDATE conversaciones SET preview = ?, no_leidos = no_leidos + 1 WHERE id = ?"
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
}
