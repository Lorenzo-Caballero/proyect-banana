<?php
/**
 * Pasar FICHAS al SALDO real de ganamos (depósito en el panel).
 *
 * "Cargar fichas" = depositar saldo jugable en la cuenta del jugador EN ganamos.
 * No lo puede hacer PHP: encola una accion en `acciones_saldo` y el bot del VPS
 * (bot_cargar_fichas.py) la ejecuta en el panel de agentes. Para que el deposito
 * ocurra de verdad, el bot tiene que correr con FICHAS_MODE=LIVE.
 *
 * QUIEN PAGA depende de fichas_cobra() (ver abajo). Hoy NO se cobra: el jugador
 * logueado pide y se le carga, porque el pago todavia no esta integrado y esto
 * se esta probando. Con FICHAS_COBRAR se le descuentan los coins que compro.
 *
 * Lo que NO cambia en ningun modo: hay que estar LOGUEADO, y el usuario sale
 * de la sesion verificada, nunca de lo que el jugador le escriba al chatbot.
 *
 * Requiere la migracion sql/15_fichas_al_panel.sql.
 */

declare(strict_types=1);

/** Minimo y maximo por operacion. El techo es un freno de daño, no una regla
 *  de negocio: si algo se descontrola (bug, cuenta robada), acota la perdida. */
const FICHAS_MIN_CARGA = 100;
const FICHAS_MAX_CARGA = 500000;

/**
 * SI SE LE COBRA O NO AL JUGADOR. Es EL interruptor de este archivo.
 *
 *   true (DEFAULT) -> exige `usuarios.coins` suficientes y se los descuenta.
 *          Las fichas solo se consiguen PAGANDO: transferencia verificada por
 *          el colector (centavos unicos) o checkout de HG Cash confirmado por
 *          webhook. Sin pago verificado no hay coins, y sin coins no hay
 *          carga: la cadena completa es
 *              pedir fichas -> datos de pago (alias/CBU o link HG)
 *              -> el jugador transfiere -> colector/HG lo VERIFICA
 *              -> coins -> recien ahi cargar_al_juego descuenta y encola.
 *   false -> el jugador logueado pide y se le carga GRATIS. Era el default de
 *          la fase de prueba, cuando no habia pago integrado. HOY ES UN MODO
 *          DE PRUEBA y hay que pedirlo EXPLICITAMENTE: un default que regala
 *          saldo real es una perdida silenciosa desde el primer jugador que
 *          lo descubre.
 *
 * Se apaga SIN tocar codigo, agregando esto a api/config.local.php:
 *     'FICHAS_COBRAR' => false,
 *
 * OJO, no confundir con FICHAS_MODE del .env del bot (DRY_RUN/LIVE): aquel
 * decide si el bot APRIETA el boton en el panel; este, si se cobra.
 */
/**
 * SI HACE FALTA EL LOGIN PROPIO (JWT) para cargar, o alcanza con el usuario que
 * dice el navegador.
 *
 *   false (default hoy) -> alcanza con el usuario que manda el widget, que lo
 *          lee del header de la plataforma. Es lo unico que funciona hoy: el
 *          jugador esta logueado EN GANAMOS, no en el login propio, y desde
 *          afuera no hay forma de verificar esa sesion. Como es un string que
 *          manda el cliente, se puede falsificar: sirve para probar, no para
 *          producción con plata.
 *   true -> exige el JWT de auth.php. Es verificable de verdad, pero solo lo
 *          tiene el que se registro en el sitio propio.
 *
 * Se prende agregando a api/config.local.php:
 *     'FICHAS_EXIGIR_TOKEN' => true,
 */
function fichas_exige_token(): bool
{
    $v = cfg('FICHAS_EXIGIR_TOKEN', '');
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower(trim((string)$v)), ['1', 'si', 'true', 'yes'], true);
}

function fichas_cobra(): bool
{
    $v = cfg('FICHAS_COBRAR', '');
    if (is_bool($v)) {
        return $v;
    }
    // Por variable de entorno siempre llega como texto. Solo un "no" EXPLICITO
    // apaga el cobro: vacio, basura o clave ausente cobran igual. El lado
    // seguro del default es el que no regala plata.
    return !in_array(strtolower(trim((string)$v)), ['0', 'no', 'false', 'off'], true);
}

if (!function_exists('gp_trace')) {
    // ===== TRACE TEMPORAL (carga de fichas) — BORRAR cuando termines de mirar.
    // /var/log/goldpaw NO lo afecta el PrivateTmp de php-fpm (que sí esconde
    // /tmp): root lo lee y www-data lo escribe. Fallback a /tmp por si el dir
    // no existe. Requiere una vez:
    //   mkdir -p /var/log/goldpaw && chown www-data:www-data /var/log/goldpaw
    function gp_trace(string $msg): void
    {
        $linea = date('H:i:s') . ' ' . $msg . "\n";
        $dst = is_dir('/var/log/goldpaw') ? '/var/log/goldpaw/gp_carga.log' : '/tmp/gp_carga.log';
        @file_put_contents($dst, $linea, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Descuenta los coins (si se cobra) y ENCOLA la carga para el bot. Todo o nada.
 * El bot del VPS deposita el saldo real en el panel de ganamos; hasta que la
 * ejecute, la carga queda 'pendiente'. Requiere el bot en FICHAS_MODE=LIVE.
 *
 * Devuelve ['ok'=>bool, ...]. Nunca lanza por saldo insuficiente: eso es una
 * respuesta normal que el chatbot le tiene que explicar al jugador.
 */
function fichas_pedir_carga(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot'): array
{
    $usuario = trim($usuario);
    if (function_exists('gp_trace')) { gp_trace("carga: pedido usuario='$usuario' monto=$monto origen=$origen"); }  // TRACE TEMPORAL

    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_usuario',
                'error' => 'No sé a qué usuario cargarle. Primero hay que iniciar sesión.'];
    }
    if ($monto < FICHAS_MIN_CARGA) {
        return ['ok' => false, 'codigo' => 'monto_bajo',
                'error' => 'El mínimo para cargar es ' . FICHAS_MIN_CARGA . ' fichas.'];
    }
    if ($monto > FICHAS_MAX_CARGA) {
        return ['ok' => false, 'codigo' => 'monto_alto',
                'error' => 'Ese monto es muy alto para cargar solo. Te lo hace un agente por chat.'];
    }

    $cobrar = fichas_cobra();

    try {
        $pdo->beginTransaction();

        // FOR UPDATE: sin esto, dos pedidos a la vez leen los mismos coins y
        // los gastan dos veces. Es el caso clasico del doble click.
        $st = $pdo->prepare("SELECT COALESCE(coins,0) AS coins FROM usuarios WHERE username = ? FOR UPDATE");
        $st->execute([$usuario]);
        $fila = $st->fetch();

        // Esto se valida SIEMPRE, aunque no se cobre: el bot va a buscar este
        // nombre en el panel, y si no existe se queda dando vueltas al pedo.
        if (!$fila) {
            $pdo->rollBack();
            return ['ok' => false, 'codigo' => 'sin_usuario',
                    'error' => 'Ese usuario no existe.'];
        }

        $coins = (int)$fila['coins'];
        if ($cobrar && $coins < $monto) {
            $pdo->rollBack();
            return ['ok' => false, 'codigo' => 'sin_fichas', 'fichas' => $coins,
                    'error' => 'No te alcanzan las fichas: tenés ' . $coins . ' y querés cargar ' . $monto . '.'];
        }

        // Ya hay una carga en curso para este usuario: encolar otra hace que el
        // bot entre dos veces al panel por el mismo jugador y es la receta para
        // depositar de mas. Que espere a que termine la primera.
        $enCurso = $pdo->prepare(
            "SELECT id FROM acciones_saldo
              WHERE usuario = ? AND tipo = 'cargar' AND estado IN ('pendiente','procesando')
              LIMIT 1"
        );
        $enCurso->execute([$usuario]);
        if ($idPrevio = $enCurso->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'codigo' => 'en_curso', 'id' => (int)$idPrevio,
                    'error' => 'Ya tenés una carga en camino. Esperá a que se acredite.'];
        }

        if ($cobrar) {
            $pdo->prepare("UPDATE usuarios SET coins = coins - ? WHERE username = ?")
                ->execute([$monto, $usuario]);

            // El movimiento va con el descuento, en la misma transaccion: si
            // queda afuera, un corte deja los coins bajados sin ningun rastro.
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'ficha', ?, 'Carga al juego', ?)"
            )->execute([$usuario, -$monto, $origen]);
        }

        // coins_debitados es lo que se devuelve si el panel falla. En modo
        // 'libre' va 0: no se cobro nada, asi que no hay nada que devolver, y
        // un fallo NO le tiene que regalar fichas propias al jugador.
        $pdo->prepare(
            "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados)
             VALUES (?, 'cargar', ?, ?, ?, ?)"
        )->execute([
            $usuario,
            $monto,
            $cobrar ? 'Canje de fichas' : 'Carga de prueba (sin cobro)',
            $origen,
            $cobrar ? $monto : 0,
        ]);

        $id = (int)$pdo->lastInsertId();
        $pdo->commit();

        // Pedir una carga es actividad del jugador: hasta ahora un jugador
        // que cargaba fichas al juego todas las semanas pero no recargaba
        // plata figuraba como inactivo en las tres pantallas del CRM.
        if (is_file(__DIR__ . '/actividad_lib.php')) {
            require_once __DIR__ . '/actividad_lib.php';
            actividad_marcar($pdo, $usuario);
        }

        /* InitiateCheckout: el jugador PIDIO la carga. Todavia no es una
           compra -- el bot la deposita despues en el panel y puede fallar. El
           Purchase se dispara cuando la accion pasa a 'hecha' (ver
           acciones_cola.php), que es el unico momento en que la plata se movio
           de verdad. Reportar la compra aca optimizaria la campaña contra
           intenciones en vez de contra ingresos.

           Va DESPUES del commit: si se disparara adentro de la transaccion y
           el commit fallara, habriamos reportado algo que no existe.

           `ref` hace el event_id reproducible: un reintento o un doble click
           generan el mismo id y Meta lo cuenta UNA vez. */
        try {
            require_once __DIR__ . '/meta_lib.php';
            require_once __DIR__ . '/publicidad_lib.php';
            $atrib = publicidad_atribucion_por_usuario($pdo, $usuario);
            meta_evento($pdo, 'InitiateCheckout', [
                'usuario' => $usuario,
                'valor'   => $monto,
                'ref'     => 'carga:' . $id,
                'fbp'     => $atrib['fbp'],
                'fbc'     => $atrib['fbc'],
                'pixel'   => publicidad_pixel_propio($atrib['publicista']),
            ]);
        } catch (Throwable $e) {
            // Que la campaña pierda un evento es molesto; que el jugador no
            // pueda cargar porque Facebook esta caido, no.
            error_log('meta InitiateCheckout: ' . $e->getMessage());
        }
        if (function_exists('gp_trace')) { gp_trace("carga: ENCOLADA id=$id usuario='$usuario' monto=$monto (espera al bot)"); }  // TRACE TEMPORAL

        return ['ok' => true, 'id' => $id, 'monto' => $monto,
                'cobrado' => $cobrar,
                'fichas_restantes' => $cobrar ? $coins - $monto : $coins,
                'mensaje' => 'Listo, la carga está en camino. En un ratito la ves en tu saldo.'];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Cuanto tiene el jugador. SOLO LEE: no mueve un peso.
 *
 * Existe para que "¿cuánto saldo tengo?" tenga adonde ir. Sin una herramienta
 * de consulta, el modelo agarraba la mas parecida -cargar_al_juego- y una
 * pregunta terminaba en una carga real.
 */
function fichas_consultar(PDO $pdo, string $usuario): array
{
    $usuario = trim($usuario);
    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_sesion',
                'error' => 'Para ver tu saldo tenés que iniciar sesión.'];
    }

    $st = $pdo->prepare(
        "SELECT COALESCE(balance,0) AS balance,
                COALESCE(coins,0)   AS coins,
                COALESCE(bonus,0)   AS bonos
           FROM usuarios WHERE username = ?"
    );
    $st->execute([$usuario]);
    $r = $st->fetch();

    if (!$r) {
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' => 'Ese usuario no existe.'];
    }

    // UNA SOLA MONEDA: `saldo` (usuarios.balance), que es lo que el jugador ve
    // en la plataforma y lo unico con lo que puede jugar. "Fichas" y "saldo"
    // son la misma cosa dicha de dos formas.
    //
    // `usuarios.coins` NO se devuelve. Era un contador paralelo de la casa que
    // en este flujo nadie escribe, y tenerlo al lado hacia que el chatbot
    // hablara de dos monedas distintas: contestaba "0 fichas" a alguien que
    // tenia 1000 de saldo.
    return ['ok' => true, 'usuario' => $usuario,
            'saldo' => (float)$r['balance'],
            'bonos' => (int)$r['bonos']];
}


/**
 * Encola un RETIRO: sacar saldo del juego.
 *
 * El jugador PIDE; el retiro NO se ejecuta hasta que un AGENTE lo aprueba desde
 * el CRM (crm_retiros.php?accion=aprobar). Recién ahí el bot del VPS lo ejecuta
 * en el panel de agentes. Desde el chat se PIDE; no se ejecuta solo. Los BONOS
 * (usuarios.bonus) NO se retiran y no se miran acá.
 */
function fichas_pedir_retiro(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot',
                             bool $todo = false, string $destino = ''): array
{
    $usuario = trim($usuario);
    $destino = trim($destino);

    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_usuario',
                'error' => 'No sé de qué cuenta retirar. Primero hay que iniciar sesión.'];
    }

    // El saldo se lee PRIMERO: hace falta para validar y, si pidió "todo", para
    // saber cuánto es. `balance` es el espejo que actualiza sync_usuarios.py cada
    // 5 minutos, así que puede estar viejo. Sirve para frenar un pedido absurdo,
    // no como verdad final: el agente ve el saldo real en el panel antes de pagar.
    //
    // Solo SALDO: los BONOS (usuarios.bonus) NO se retiran y no se miran acá.
    $st = $pdo->prepare("SELECT COALESCE(balance,0) AS balance FROM usuarios WHERE username = ?");
    $st->execute([$usuario]);
    $fila = $st->fetch();
    if (!$fila) {
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' => 'Ese usuario no existe.'];
    }
    $saldo = (float)$fila['balance'];

    // "Retirar todo" = todo el saldo (sin decimales). El monto lo pone el server,
    // no el jugador: así no depende de que el modelo copie bien la cifra.
    if ($todo) {
        $monto = (int)floor($saldo);
    }

    // Coherencia monto vs saldo.
    if ($saldo < FICHAS_MIN_CARGA) {
        return ['ok' => false, 'codigo' => 'saldo_bajo', 'saldo' => $saldo,
                'error' => 'Tu saldo es de ' . number_format($saldo, 0, ',', '.') .
                           ', menos del mínimo para retirar (' . FICHAS_MIN_CARGA . ').'];
    }
    if ($monto < FICHAS_MIN_CARGA) {
        return ['ok' => false, 'codigo' => 'monto_bajo', 'saldo' => $saldo,
                'error' => 'El mínimo para retirar es ' . FICHAS_MIN_CARGA . ' fichas.'];
    }
    if ($saldo + 0.01 < $monto) {
        return ['ok' => false, 'codigo' => 'sin_saldo', 'saldo' => $saldo,
                'error' => 'Tu saldo es de ' . number_format($saldo, 0, ',', '.') .
                           ' y querés retirar ' . number_format($monto, 0, ',', '.') . '.'];
    }

    $enCurso = $pdo->prepare(
        "SELECT id FROM acciones_saldo
          WHERE usuario = ? AND tipo = 'retirar' AND estado IN ('pendiente','procesando','revisar')
          LIMIT 1"
    );
    $enCurso->execute([$usuario]);
    if ($idPrevio = $enCurso->fetchColumn()) {
        return ['ok' => false, 'codigo' => 'en_curso', 'id' => (int)$idPrevio,
                'error' => 'Ya tenés un retiro pedido. Un agente lo está viendo.'];
    }

    /* El destino del pago (CBU/CVU/alias). Prioridad: lo que dijo AHORA >
       lo que tiene guardado. Si dio uno nuevo se guarda para la proxima --
       nadie quiere dictar 22 digitos dos veces. Sin destino el retiro entra
       igual: el agente lo puede completar, y sin HG ni hace falta. */
    $guardado = '';
    try {
        $q = $pdo->prepare("SELECT COALESCE(cobro_destino,'') FROM usuarios WHERE username = ?");
        $q->execute([$usuario]);
        $guardado = (string)$q->fetchColumn();
    } catch (Throwable $e) { /* sin migracion 43: se sigue sin destino */ }

    if ($destino !== '' && !preg_match('/^\d{22}$/', $destino)
        && !preg_match('/^[a-zA-Z0-9._-]{6,20}$/', $destino)) {
        return ['ok' => false, 'codigo' => 'destino',
                'error' => 'Ese CBU/alias no parece válido. Un CBU/CVU tiene 22 dígitos; un alias, entre 6 y 20 letras/números/puntos.'];
    }
    if ($destino !== '' && $destino !== $guardado) {
        try {
            $pdo->prepare("UPDATE usuarios SET cobro_destino = ? WHERE username = ?")
                ->execute([$destino, $usuario]);
        } catch (Throwable $e) { /* best-effort */ }
    }
    $destinoFinal = $destino !== '' ? $destino : $guardado;

    try {
        $pdo->prepare(
            "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados, destino)
             VALUES (?, 'retirar', ?, 'Retiro pedido por el jugador', ?, 0, ?)"
        )->execute([$usuario, $monto, $origen, $destinoFinal !== '' ? $destinoFinal : null]);
    } catch (Throwable $e) {
        // Sin la migracion 43 no existe `destino`: el retiro entra igual.
        $pdo->prepare(
            "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados)
             VALUES (?, 'retirar', ?, 'Retiro pedido por el jugador', ?, 0)"
        )->execute([$usuario, $monto, $origen]);
    }

    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'monto' => $monto,
            'destino' => $destinoFinal,
            'falta_destino' => $destinoFinal === '',
            'saldo' => $saldo, 'retiro_todo' => $todo,
            'mensaje' => 'Listo, tu pedido de retiro por ' . number_format($monto, 0, ',', '.') .
                         ' quedó registrado. Lo aprueba un agente y te avisamos por el chat. ' .
                         'No es automático como la carga.'];
}


/**
 * Devuelve las fichas de una accion que fallo. Idempotente por diseño: solo
 * devuelve si la fila todavia tiene `coins_debitados > 0`, y lo pone en 0 en la
 * misma sentencia. Si se llama dos veces, la segunda no encuentra nada.
 *
 * NO se llama nunca para estado 'revisar': ahi no se sabe si el deposito entro,
 * y devolver las fichas de una carga que si se acredito es regalar plata.
 */
function fichas_devolver(PDO $pdo, int $accionId): int
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT usuario, coins_debitados FROM acciones_saldo WHERE id = ? FOR UPDATE"
        );
        $st->execute([$accionId]);
        $a = $st->fetch();

        if (!$a || (int)$a['coins_debitados'] <= 0) {
            $pdo->commit();
            return 0;
        }

        $monto = (int)$a['coins_debitados'];

        $pdo->prepare("UPDATE usuarios SET coins = coins + ? WHERE username = ?")
            ->execute([$monto, $a['usuario']]);

        $pdo->prepare(
            "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
             VALUES (?, 'ficha', ?, 'Devolución: la carga no se pudo hacer', 'sistema')"
        )->execute([$a['usuario'], $monto]);

        $pdo->prepare("UPDATE acciones_saldo SET coins_debitados = 0 WHERE id = ?")
            ->execute([$accionId]);

        $pdo->commit();
        return $monto;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
