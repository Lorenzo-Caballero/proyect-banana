<?php
/**
 * Pasar FICHAS propias (usuarios.coins) a SALDO real de ganamos.
 *
 * El jugador ya pagó esas fichas (transferencia -> pagos.php -> +coins). Esto
 * las convierte en saldo jugable: descuenta los coins y encola una accion para
 * que el bot del VPS la deposite en el panel de agentes.
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
 *   false (default hoy) -> el jugador logueado pide y se le carga, GRATIS.
 *          Fase de prueba: cualquiera con una cuenta puede pedir saldo real
 *          las veces que quiera. Es lo que se pidio mientras no haya pago.
 *   true -> exige `usuarios.coins` suficientes y se los descuenta.
 *          Es el modo para cuando el pago este integrado.
 *
 * Se prende SIN tocar codigo, agregando esto a api/config.local.php:
 *     'FICHAS_COBRAR' => true,
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
    // Por variable de entorno siempre llega como texto.
    return in_array(strtolower(trim((string)$v)), ['1', 'si', 'true', 'yes'], true);
}

/**
 * Descuenta los coins y encola la carga. Todo o nada.
 *
 * Devuelve ['ok'=>bool, ...]. Nunca lanza por saldo insuficiente: eso es una
 * respuesta normal que el chatbot le tiene que explicar al jugador.
 */
function fichas_pedir_carga(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot'): array
{
    $usuario = trim($usuario);

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
 * OJO, esto no es simetrico con la carga. El bot sabe depositar en el panel
 * pero NO retirar: el panel tiene su propio circuito de retiro y todavia no
 * esta automatizado. Asi que esto deja el pedido en la cola y el bot lo marca
 * 'revisar' para que un agente lo resuelva a mano. Desde el chat se PIDE el
 * retiro; no se ejecuta solo. El texto que devuelve dice exactamente eso, para
 * que el chatbot no le prometa al jugador algo que no va a pasar en 2 minutos.
 */
function fichas_pedir_retiro(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot'): array
{
    $usuario = trim($usuario);

    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_usuario',
                'error' => 'No sé de qué cuenta retirar. Primero hay que iniciar sesión.'];
    }
    if ($monto < FICHAS_MIN_CARGA) {
        return ['ok' => false, 'codigo' => 'monto_bajo',
                'error' => 'El mínimo para retirar es ' . FICHAS_MIN_CARGA . ' fichas.'];
    }

    $st = $pdo->prepare("SELECT COALESCE(balance,0) AS balance FROM usuarios WHERE username = ?");
    $st->execute([$usuario]);
    $fila = $st->fetch();
    if (!$fila) {
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' => 'Ese usuario no existe.'];
    }

    // `balance` es el espejo que actualiza sync_usuarios.py cada 5 minutos, asi
    // que puede estar viejo. Sirve para frenar un pedido absurdo, no como
    // verdad final: el agente ve el saldo real en el panel antes de pagar.
    $saldo = (float)$fila['balance'];
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

    $pdo->prepare(
        "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados)
         VALUES (?, 'retirar', ?, 'Retiro pedido por el jugador', ?, 0)"
    )->execute([$usuario, $monto, $origen]);

    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'monto' => $monto,
            'mensaje' => 'Listo, tu pedido de retiro quedó registrado. Lo revisa un agente y ' .
                         'te avisamos por el chat. No es automático como la carga.'];
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
