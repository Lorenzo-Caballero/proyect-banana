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

/* El bloqueo propio vive en vinculos_lib (migracion 69). Se requiere aca --y
   con is_file-- porque fichas_lib es el cuello por donde pasan TODAS las
   cargas y retiros: chat, CRM y camino A. Si el archivo no estuviera
   desplegado todavia, las funciones no existen y todo sigue como antes. */
if (is_file(__DIR__ . '/vinculos_lib.php')) { require_once __DIR__ . '/vinculos_lib.php'; }

/** RESPALDO de los limites, no la fuente. Los de verdad los pone cada cliente
 *  desde Configuracion (config_crm: lim_carga_min, lim_carga_max,
 *  lim_retiro_min, lim_retiro_max_dia) y se leen con fichas_limite(). Estas
 *  constantes solo aparecen si config_crm no responde -- y son los valores que
 *  regian antes de que los limites fueran configurables, asi que un fallo de
 *  lectura deja el sistema como estaba, nunca sin freno. */
/* CUANTO PUEDE TENER UNA LECTURA DEL SALDO PARA QUE SE LA AFIRME COMO UN HECHO.
   `usuarios.balance` es un ESPEJO del saldo de ganamos, no la verdad: lo
   refresca el colector. Pasado este tiempo, el numero sigue sirviendo para
   orientarse pero NO para desmentir a un jugador.
   Dos minutos y no treinta segundos: los que estan hablando se refrescan en
   cada pasada del cron, que es cada minuto, asi que en una conversacion en
   curso la lectura casi siempre tiene menos de 60 segundos. Dos minutos deja
   pasar lo normal y agarra lo que de verdad quedo viejo. */
const FICHAS_SALDO_FRESCO_SEG = 120;

const FICHAS_MIN_CARGA = 100;
const FICHAS_MAX_CARGA = 500000;

if (!function_exists('fichas_limite')) {
    /**
     * Un limite del negocio, para ESTE cliente.
     *
     * Los limites viven en un solo lugar y se usan en dos: los aplica el
     * codigo (que es lo que manda) y se los cuenta al modelo del chatbot
     * (para que no le ofrezca al jugador algo que despues se le va a
     * rechazar). Ver chatbot_bloque_limites() en chatbot_contexto.php.
     *
     * Devuelve $porDefecto si config_crm no esta disponible o el valor
     * guardado no es un numero -- un limite ilegible no puede convertirse en
     * "sin limite".
     */
    function fichas_limite(PDO $pdo, string $clave, int $porDefecto): int
    {
        if (!function_exists('cfg_crm')) {
            return $porDefecto;
        }
        $v = cfg_crm($pdo, $clave);
        if ($v === null || trim((string)$v) === '' || !is_numeric($v)) {
            return $porDefecto;
        }
        $n = (int)$v;
        return $n >= 0 ? $n : $porDefecto;
    }
}

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
 * $bono: fichas de REGALO que van en el mismo deposito, debitadas de
 * usuarios.bonus (no de coins). Lo usa el auto-canje de una recarga cuando el
 * jugador cobro un bono de bienvenida: transferencia de 3000 con bono 50% =
 * UN deposito de 4500. Se debita del contador para que el bono no se pueda
 * jugar dos veces, y se registra en acciones_saldo.bono_debitado para que un
 * fallo del panel lo devuelva a bonus (ver fichas_devolver). Si el jugador
 * tiene menos bonus que $bono (carrera, ajuste a mano), se deposita lo que
 * haya: mejor quedarse corto que inventar plata.
 *
 * Devuelve ['ok'=>bool, ...]. Nunca lanza por saldo insuficiente: eso es una
 * respuesta normal que el chatbot le tiene que explicar al jugador.
 */
function fichas_pedir_carga(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot',
                           bool $confiable = false, int $bono = 0): array
{
    $usuario = trim($usuario);
    if (function_exists('gp_trace')) { gp_trace("carga: pedido usuario='$usuario' monto=$monto origen=$origen"); }  // TRACE TEMPORAL

    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_usuario',
                'error' => 'No sé a qué usuario cargarle. Primero hay que iniciar sesión.'];
    }
    /* BLOQUEADO DE NUESTRO LADO (migracion 69): no se le mueve plata.
       El texto es para el JUGADOR y a proposito NO dice "estas bloqueado":
       quien abre tres cuentas aprende de cada mensaje que recibe, y decirle
       exactamente que lo detectamos le enseña que probar la proxima vez. Que
       hable con una persona, que es ademas lo correcto si el bloqueo estuvo
       mal puesto. */
    if (function_exists('vin_bloqueado') && vin_bloqueado($pdo, $usuario)) {
        return ['ok' => false, 'codigo' => 'bloqueado',
                'error' => 'No puedo hacer esa operación en esta cuenta. '
                         . 'Decile que lo tiene que ver un agente.'];
    }

    /* Deposito SOLO-BONO (monto=0, bono>0): el CRM mandando al juego los
       bonos del jugador. Es un regalo de la casa, no una compra del jugador:
       los limites de carga (minimo/maximo del AUTOSERVICIO) no aplican, igual
       que no aplican cuando un agente carga a mano. Un monto negativo sigue
       siendo invalido. */
    $soloBono = ($monto === 0 && $bono > 0);
    if ($monto < 0) {
        return ['ok' => false, 'codigo' => 'monto_bajo',
                'error' => 'El monto no puede ser negativo.'];
    }
    $minCarga = fichas_limite($pdo, 'lim_carga_min', FICHAS_MIN_CARGA);
    $maxCarga = fichas_limite($pdo, 'lim_carga_max', FICHAS_MAX_CARGA);
    if (!$soloBono && $monto < $minCarga) {
        return ['ok' => false, 'codigo' => 'monto_bajo', 'minimo' => $minCarga,
                'error' => 'El mínimo para cargar es ' . number_format($minCarga, 0, ',', '.') . ' fichas.'];
    }
    if (!$soloBono && $maxCarga > 0 && $monto > $maxCarga) {
        return ['ok' => false, 'codigo' => 'monto_alto', 'maximo' => $maxCarga,
                'error' => 'Ese monto es muy alto para cargar solo. Te lo hace un agente por chat.'];
    }

    $cobrar = fichas_cobra();

    try {
        $pdo->beginTransaction();

        // FOR UPDATE: sin esto, dos pedidos a la vez leen los mismos coins y
        // los gastan dos veces. Es el caso clasico del doble click.
        $st = $pdo->prepare(
            "SELECT COALESCE(coins,0) AS coins, COALESCE(bonus,0) AS bonus
               FROM usuarios WHERE username = ? FOR UPDATE");
        $st->execute([$usuario]);
        $fila = $st->fetch();

        // El nombre tiene que existir para que el bot lo encuentre en el panel.
        // PERO: si el que llama garantiza que es real ($confiable) -- viene de
        // una recarga YA PAGADA (rl_cargar_al_juego_auto) -- se encola igual
        // aunque no este en el espejo `usuarios`. Ese espejo lo pobla
        // sync_usuarios y puede estar atrasado o CAIDO; sin este OR, un jugador
        // que transfirio y todavia no espejo se quedaba sin sus fichas: la
        // plata entraba, la recarga figuraba acreditada, pero el deposito al
        // juego nunca se encolaba. Pasó el 7/9/2026 con el sync caido. El
        // deposito lo hace ejecutar_cargas.py contra el PANEL (la fuente real),
        // buscando por username -- no necesita el espejo.
        // Se recuerda si el espejo TENIA la fila: mas abajo, el bono de una
        // recarga ya acreditada no se puede capar contra un contador que no
        // existe (ver el bloque del bono).
        $habiaFila = (bool)$fila;
        if (!$fila) {
            if (!$confiable) {
                $pdo->rollBack();
                return ['ok' => false, 'codigo' => 'sin_usuario',
                        'error' => 'Ese usuario no existe.'];
            }
            // Sin fila en el espejo no hay coins que debitar: se encola el
            // deposito y listo (la plata ya entro por la transferencia).
            $cobrar = false;
            $fila   = ['coins' => 0, 'bonus' => 0];
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

        // Con monto=0 (solo-bono) no hay coins que cobrar ni movimiento de
        // fichas que anotar: un "-0" en el historial solo confunde.
        if ($cobrar && $monto > 0) {
            $pdo->prepare("UPDATE usuarios SET coins = coins - ? WHERE username = ?")
                ->execute([$monto, $usuario]);

            // El movimiento va con el descuento, en la misma transaccion: si
            // queda afuera, un corte deja los coins bajados sin ningun rastro.
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'ficha', ?, 'Carga al juego', ?)"
            )->execute([$usuario, -$monto, $origen]);
        }

        /* El bono va en el MISMO deposito, debitado de usuarios.bonus.
           Normalmente se capa a lo que el jugador TENGA en el contador: nadie
           puede jugar bonos que no existen.

           EXCEPCION, y es la que arregla el bono perdido del 12/9/2026: con
           $confiable y SIN fila en el espejo (`usuarios` atrasado o caido,
           tipico en una cuenta recien creada), el contador no se puede leer
           -- $fila quedo en ['coins'=>0,'bonus'=>0] mas arriba. Capar contra
           ese 0 borraba un bono YA PROMETIDO Y CALCULADO: el jugador cargaba
           7000 con 50% y recibia 7000 pelado, sin un solo error en el log.
           El bono de una recarga acreditada no depende del espejo, igual que
           no depende el deposito de las fichas (mismo motivo, ver arriba). */
        $sinEspejo = $confiable && !$habiaFila;
        $bono = $sinEspejo
            ? max(0, $bono)
            : max(0, min($bono, (int)($fila['bonus'] ?? 0)));
        // Solo-bono sin bonos disponibles: no hay NADA que depositar. Sin
        // este corte se encolaba una accion de monto 0 (el bot depositaria $0).
        if ($soloBono && $bono <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'codigo' => 'sin_bonos', 'bonos' => (int)($fila['bonus'] ?? 0),
                    'error' => 'El jugador no tiene bonos para mandar al juego.'];
        }
        if ($bono > 0) {
            $pdo->prepare("UPDATE usuarios SET bonus = bonus - ? WHERE username = ?")
                ->execute([$bono, $usuario]);
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'bono', ?, 'Bono jugado en la carga', ?)"
            )->execute([$usuario, -$bono, $origen]);
        }

        // coins_debitados es lo que se devuelve si el panel falla. En modo
        // 'libre' va 0: no se cobro nada, asi que no hay nada que devolver, y
        // un fallo NO le tiene que regalar fichas propias al jugador.
        // bono_debitado, igual pero contra usuarios.bonus (migracion 56).
        $motivoAcc = $soloBono
            ? 'Bonos al juego'
            : ($cobrar ? 'Canje de fichas' : 'Carga de prueba (sin cobro)')
                . ($bono > 0 ? ' + bono ' . $bono : '');
        try {
            $pdo->prepare(
                "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados, bono_debitado)
                 VALUES (?, 'cargar', ?, ?, ?, ?, ?)"
            )->execute([
                $usuario,
                $monto + $bono,
                $motivoAcc,
                $origen,
                ($cobrar && $monto > 0) ? $monto : 0,
                $bono,
            ]);
        } catch (PDOException $e) {
            /* Sin la migracion 56 no existe bono_debitado. El deposito sale
               igual por el total -- lo que se pierde es SOLO la devolucion
               automatica del bono si el panel fallara, y eso se loguea. */
            if ($bono > 0) {
                error_log('fichas_pedir_carga: sin acciones_saldo.bono_debitado (migracion 56); '
                    . 'si esta carga falla, devolver ' . $bono . ' a bonus de ' . $usuario . ' a mano');
            }
            $pdo->prepare(
                "INSERT INTO acciones_saldo (usuario, tipo, monto, motivo, origen, coins_debitados)
                 VALUES (?, 'cargar', ?, ?, ?, ?)"
            )->execute([
                $usuario,
                $monto + $bono,
                $motivoAcc,
                $origen,
                ($cobrar && $monto > 0) ? $monto : 0,
            ]);
        }

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
        /* PERO NO cuando la carga viene de una recarga por transferencia
           (origen='recarga'). Ahi el jugador YA transfirio y la plata ya entro:
           esta accion es el segundo tramo interno, pasar las fichas al juego.
           Reportar un "inicio de compra" en ese momento le mostraba a Meta un
           embudo al reves -- Purchase primero, InitiateCheckout despues -- que
           es imposible y ensucia el modelo. El inicio real de esa compra fue
           cuando el jugador pidio la recarga por el chat. */
        // Tampoco con un deposito solo-bono: es un REGALO de la casa, no una
        // intencion de compra del jugador -- reportarlo optimizaria la
        // campaña hacia gente que recibe regalos, no que paga.
        if ($origen !== 'recarga' && !$soloBono) {
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
                    // Del jugador, no de quien dispara este evento.
                    'ip'      => $atrib['ip'] ?? '',
                    'ua'      => $atrib['ua'] ?? '',
                    'url'     => $atrib['url'] ?? '',
                    'pixel'   => publicidad_pixel_propio($atrib['publicista']),
                ]);
            } catch (Throwable $e) {
                // Que la campaña pierda un evento es molesto; que el jugador no
                // pueda cargar porque Facebook esta caido, no.
                error_log('meta InitiateCheckout: ' . $e->getMessage());
            }
        }
        if (function_exists('gp_trace')) { gp_trace("carga: ENCOLADA id=$id usuario='$usuario' monto=$monto (espera al bot)"); }  // TRACE TEMPORAL

        return ['ok' => true, 'id' => $id, 'monto' => $monto, 'bono' => $bono,
                'total' => $monto + $bono,
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
    /* EL BLOQUEO SE DICE ACA, AL PRINCIPIO, Y NO AL FINAL DEL FLUJO.
       EL CASO (16/09/2026): a un jugador bloqueado el bot le contesto "Tenés
       1.400 fichas disponibles para retirar. ¿Querés sacar todo o una parte?",
       le pidio el CBU, le confirmo el monto -- y recien al aceptar aparecio
       "hay un bloqueo en tu cuenta". Lo llevo por todo el camino para chocarlo
       contra la pared al final.

       Eso es malo para los dos lados: el jugador se enoja mas cuanto mas
       avanzo, y el operador hereda una discusion que no hacia falta. El freno
       de fichas_pedir_retiro sigue estando --es el que protege la plata-- pero
       el modelo tiene que saberlo ANTES de ofrecer nada.

       El saldo se sigue diciendo: preguntar cuanto tengo es inofensivo y
       negarselo solo confirma que pasa algo raro. Lo que cambia es que el
       modelo deja de OFRECER retirar.

       El texto es para el MODELO, no para el jugador, y a proposito no le dice
       que lo detectamos por multicuenta: quien abre tres cuentas aprende de
       cada mensaje que recibe. */
    $bloqueado = function_exists('vin_bloqueado') && vin_bloqueado($pdo, $usuario);

    return ['ok' => true, 'usuario' => $usuario,
            'saldo' => (float)$r['balance'],
            'bonos' => (int)$r['bonos'],
            'bloqueado' => $bloqueado,
            'aviso' => $bloqueado
                ? 'OJO: esta cuenta tiene un bloqueo. Podés decirle el saldo, pero NO le '
                . 'ofrezcas retirar ni cargar ni le preguntes cuánto quiere sacar: no se '
                . 'va a poder. Decile que un agente tiene que revisar su cuenta y que ya '
                . 'está avisado. No le expliques el motivo del bloqueo.'
                : ''];
}


if (!function_exists('fichas_ahora_ar')) {
    /**
     * El momento actual en hora ARGENTINA.
     *
     * El PHP de este server corre en UTC (no hay date_default_timezone_set en
     * ningun lado, y DEPLOY.md lo dice). Asi que date('H') devuelve la hora de
     * Greenwich: una ventana "de 3 a 8 AM" configurada por el cliente se le
     * aplicaria al jugador de 00 a 05, tres horas corridas antes.
     *
     * Mismo patron que chatbot_fecha_ar() en chatbot.php, que es el unico lugar
     * del proyecto que ya lo hacia bien.
     */
    function fichas_ahora_ar(): DateTime
    {
        try {
            return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        } catch (Throwable $e) {
            // Sin la base de datos de husos horarios, mejor la hora del server
            // que un fatal. Se avisa, porque los limites van a correrse.
            error_log('fichas_ahora_ar: no pude usar la zona horaria AR: ' . $e->getMessage());
            return new DateTime('now');
        }
    }
}

if (!function_exists('fichas_rango_dia_ar')) {
    /**
     * El dia de HOY en Argentina, expresado en el reloj de la BASE.
     *
     * Devuelve ['desde','hasta') listos para comparar contra una columna
     * DATETIME, en el mismo huso en que la base las escribe.
     *
     * POR QUE NO ALCANZA CON CURDATE()
     * `DATE(creada_en) = CURDATE()` parece obvio y es el bug: en produccion la
     * base corre en UTC, asi que el "dia" terminaba a las 21:00 hora argentina.
     * Un jugador que llegaba al tope de retiro a las 22:00 tenia el cupo entero
     * otra vez, tres horas antes de que le tocara.
     *
     * POR QUE NO SE ASUME QUE LA BASE ESTA EN UTC
     * Ese fue el segundo intento y tambien estaba mal: en produccion la base va
     * en UTC, pero en el MySQL local va en hora argentina, y una conversion fija
     * desplazaba tres horas al reves. En vez de creerle a una suposicion, se le
     * PREGUNTA a la base cuanto se corre de UTC (TIMESTAMPDIFF contra
     * UTC_TIMESTAMP) y se ajusta con eso. Asi da igual como este configurada.
     *
     * Se devuelve un rango y no una funcion sobre la columna a proposito: asi
     * el indice de `creada_en` sigue sirviendo.
     */
    function fichas_rango_dia_ar(PDO $pdo): array
    {
        // Cuanto se corre el reloj de la base respecto de UTC, en segundos.
        // 0 si la base va en UTC, -10800 si va en hora argentina.
        try {
            $off = (int)$pdo->query(
                "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())"
            )->fetchColumn();
        } catch (Throwable $e) {
            error_log('fichas_rango_dia_ar: no pude leer el huso de la base: ' . $e->getMessage());
            $off = 0;
        }

        $ar    = fichas_ahora_ar();
        $desde = (clone $ar)->setTime(0, 0, 0);
        $hasta = (clone $desde)->modify('+1 day');
        $utc   = new DateTimeZone('UTC');

        // Medianoche argentina -> UTC -> y de ahi al reloj de la base.
        $aBase = static function (DateTime $d) use ($utc, $off): string {
            return (clone $d)->setTimezone($utc)
                             ->modify(($off >= 0 ? '+' : '-') . abs($off) . ' seconds')
                             ->format('Y-m-d H:i:s');
        };
        return ['desde' => $aBase($desde), 'hasta' => $aBase($hasta)];
    }
}

if (!function_exists('fichas_ventana_retiro')) {
    /**
     * ¿Se puede retirar en este momento?
     *
     * El cliente configura una franja en la que NO se paga -- tipicamente la de
     * madrugada, cuando no hay nadie para aprobar. Dos claves de config_crm,
     * formato HH:MM:
     *
     *     lim_retiro_hora_desde / lim_retiro_hora_hasta
     *
     * VACIO = sin restriccion, y por eso NO se usa fichas_limite() aca: esa
     * funcion acepta 0 como valor valido (y 0 es una hora legitima, medianoche),
     * asi que la convencion "0 = desactivado" de los topes no sirve.
     *
     * La franja puede cruzar la medianoche (23:00 a 06:00) y ese es justamente
     * el caso comun, asi que se contempla.
     *
     * @return array ['abierta'=>bool, 'desde'=>string, 'hasta'=>string]
     */
    function fichas_ventana_retiro(PDO $pdo): array
    {
        $abierta = ['abierta' => true, 'desde' => '', 'hasta' => ''];
        if (!function_exists('cfg_crm')) { return $abierta; }

        $desde = trim((string)(cfg_crm($pdo, 'lim_retiro_hora_desde') ?? ''));
        $hasta = trim((string)(cfg_crm($pdo, 'lim_retiro_hora_hasta') ?? ''));
        // Hacen falta las DOS: una sola no define ninguna franja.
        if ($desde === '' || $hasta === '') { return $abierta; }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $desde, $d)
            || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hasta, $h)) {
            // Mal cargado: se ignora en vez de bloquear. Un retiro frenado por
            // un typo del operador es peor que uno de mas en horario raro.
            error_log('fichas_ventana_retiro: horario invalido (' . $desde . ' - ' . $hasta . ')');
            return $abierta;
        }

        $ahora = (int)fichas_ahora_ar()->format('G') * 60 + (int)fichas_ahora_ar()->format('i');
        $ini   = (int)$d[1] * 60 + (int)$d[2];
        $fin   = (int)$h[1] * 60 + (int)$h[2];

        // Franja que cruza la medianoche (23:00 -> 06:00): esta bloqueado si
        // esta DESPUES del inicio o ANTES del fin. Sin cruzar: entre los dos.
        $bloqueado = ($ini <= $fin)
            ? ($ahora >= $ini && $ahora < $fin)
            : ($ahora >= $ini || $ahora < $fin);

        return ['abierta' => !$bloqueado, 'desde' => $desde, 'hasta' => $hasta];
    }
}

/**
 * Encola un RETIRO: sacar saldo del juego.
 *
 * El jugador PIDE; el retiro NO se ejecuta hasta que un AGENTE lo aprueba desde
 * el CRM (crm_retiros.php?accion=aprobar). Recién ahí el bot del VPS lo ejecuta
 * en el panel de agentes. Desde el chat se PIDE; no se ejecuta solo. Los BONOS
 * (usuarios.bonus) NO se retiran y no se miran acá.
 */
/**
 * El aviso de Telegram de UN retiro, leyendo la fila -- no los parametros.
 *
 * Se llama desde DOS momentos y por eso lee de la base: cuando el retiro se
 * crea CON destino, y cuando el destino se completa en un segundo mensaje del
 * jugador. En los dos casos tiene que salir el mismo aviso, con el CBU adentro.
 *
 * EL AVISO NO SALE SIN DESTINO, y esa es la regla: el mensaje existe para poder
 * pagarle, y sin CBU no se puede pagar. Nahuel lo reporto asi -- "el mensaje me
 * llega antes de que el cliente complete ese dato, con lo cual siempre viene
 * vacio". El pedido SI queda registrado igual (se ve en Retiros pendientes
 * marcado en rojo "Sin CBU/alias"): lo que se posterga es el aviso, no el
 * registro.
 */
function fichas_avisar_retiro(PDO $pdo, string $usuario, int $idRetiro): bool
{
    if (!function_exists('tg_evento')) { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT monto, COALESCE(destino,'') destino FROM acciones_saldo
              WHERE id = ? AND tipo = 'retirar' LIMIT 1"
        );
        $st->execute([$idRetiro]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return false; }
        $destino = trim((string)$r['destino']);
        if ($destino === '') { return false; }   // sin CBU no hay nada que avisar

        $saldo = null;
        try {
            $b = $pdo->prepare("SELECT balance FROM usuarios WHERE username = ?");
            $b->execute([$usuario]);
            $v = $b->fetchColumn();
            if ($v !== false && $v !== null) { $saldo = (float)$v; }
        } catch (Throwable $e) { /* el saldo es contexto, no bloquea el aviso */ }

        return tg_evento($pdo, 'retiro', '💸 Pedido de retiro (por el chat)', [
            'Jugador'   => $usuario,
            'Quiere'    => '$' . number_format((float)$r['monto'], 0, ',', '.'),
            'Tiene'     => $saldo !== null ? '$' . number_format($saldo, 0, ',', '.') : null,
            'CBU/alias' => ['code' => $destino],
            'Qué hacer' => 'Aprobalo en CRM → Retiros (le saca las fichas) y transferile.',
        ]);
    } catch (Throwable $e) {
        error_log('fichas_avisar_retiro: ' . $e->getMessage());
        return false;
    }
}

function fichas_pedir_retiro(PDO $pdo, string $usuario, int $monto, string $origen = 'chatbot',
                             bool $todo = false, string $destino = ''): array
{
    $usuario = trim($usuario);
    $destino = trim($destino);

    if ($usuario === '') {
        return ['ok' => false, 'codigo' => 'sin_usuario',
                'error' => 'No sé de qué cuenta retirar. Primero hay que iniciar sesión.'];
    }
    /* BLOQUEADO DE NUESTRO LADO (migracion 69): no se le paga un retiro.
       El texto es para el JUGADOR y a proposito NO dice "estas bloqueado":
       quien abre tres cuentas aprende de cada mensaje que recibe, y decirle
       exactamente que lo detectamos le enseña que probar la proxima vez. Que
       hable con una persona, que es ademas lo correcto si el bloqueo estuvo
       mal puesto. */
    if (function_exists('vin_bloqueado') && vin_bloqueado($pdo, $usuario)) {
        return ['ok' => false, 'codigo' => 'bloqueado',
                'error' => 'No puedo hacer esa operación en esta cuenta. '
                         . 'Decile que lo tiene que ver un agente.'];
    }


    /* Ventana horaria: si el cliente cerró los retiros a esta hora, se corta acá
       y no se toca la base. Mismo criterio que las validaciones baratas de
       fichas_pedir_carga(): primero lo que no necesita consultar nada.
       Va ANTES de resolver "todo", así un "retirá todo" también queda frenado
       sin haber calculado nada. */
    $ventana = fichas_ventana_retiro($pdo);
    if (!$ventana['abierta']) {
        return ['ok' => false, 'codigo' => 'fuera_de_horario',
                'desde' => $ventana['desde'], 'hasta' => $ventana['hasta'],
                'error' => 'Los retiros están cerrados de ' . $ventana['desde'] .
                           ' a ' . $ventana['hasta'] . '. Podés pedirlo apenas vuelva a abrir.'];
    }

    // El saldo se lee PRIMERO: hace falta para validar y, si pidió "todo", para
    // saber cuánto es. `balance` es el espejo que actualiza sync_usuarios.py cada
    // 5 minutos, así que puede estar viejo. Sirve para frenar un pedido absurdo,
    // no como verdad final: el agente ve el saldo real en el panel antes de pagar.
    //
    // Solo SALDO: los BONOS (usuarios.bonus) NO se retiran y no se miran acá.
    /* `saldo_visto_en` viaja junto al saldo (migracion 68): es CUANDO lo
       leimos. Se pide aparte y con fallback porque una base sin esa migracion
       tiene que seguir andando -- ahi la edad queda desconocida y se trata como
       vieja, que es el lado seguro. */
    $st = $pdo->prepare("SELECT COALESCE(balance,0) AS balance FROM usuarios WHERE username = ?");
    $st->execute([$usuario]);
    $fila = $st->fetch();
    if (!$fila) {
        return ['ok' => false, 'codigo' => 'sin_usuario', 'error' => 'Ese usuario no existe.'];
    }
    $saldo = (float)$fila['balance'];

    $saldoEdad = null;   // segundos desde que leimos ese numero; null = no sabemos
    try {
        $sv = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, saldo_visto_en, NOW()) AS edad
               FROM usuarios WHERE username = ? AND saldo_visto_en IS NOT NULL"
        );
        $sv->execute([$usuario]);
        $e = $sv->fetchColumn();
        if ($e !== false && $e !== null) { $saldoEdad = max(0, (int)$e); }
    } catch (Throwable $e) { /* sin migracion 68 */ }
    $saldoViejo = ($saldoEdad === null || $saldoEdad > FICHAS_SALDO_FRESCO_SEG);

    /* NO DESMENTIR AL JUGADOR CON UN NUMERO VIEJO (decision de Nahuel,
       18/09/2026). Este es el caso que el reportaba: *"muchas veces las
       personas dicen quiero retirar 5000 y el bot le dice no tenes 5000, tenes
       1000"*. Cuando eso pasa con una lectura fresca, el bot tiene razon y hay
       que decirselo. Cuando pasa con una de hace cinco minutos, el bot esta
       discutiendo con un numero que ya no existe -- y el jugador, que acaba de
       ver su saldo en la pantalla del juego, sabe que le estan mintiendo.

       La salida no es creerle ni desmentirlo: es no AFIRMAR. Se devuelve el
       numero igual (sirve para orientarse) pero con un codigo distinto, y la
       regla del prompt hace que el bot lo diga como lo que es -- lo que le
       figura-- y lo pase a una persona, que puede mirar el panel.

       Se calcula aca, una sola vez, y lo usan los dos chequeos de abajo: el del
       minimo y el del saldo insuficiente. Los dos afirmaban igual. */
    $incierto = function (float $pide) use ($saldo, $saldoEdad): array {
        $hace = $saldoEdad === null
              ? 'y no sé de cuándo es'
              : ('pero esa lectura es de hace ' .
                 ($saldoEdad < 120 ? 'un rato'
                                   : (int)round($saldoEdad / 60) . ' minutos'));
        return ['ok' => false, 'codigo' => 'saldo_incierto', 'saldo' => $saldo,
                'saldo_edad_seg' => $saldoEdad, 'pedido' => $pide,
                'error' => 'Me figura un saldo de ' . number_format($saldo, 0, ',', '.') .
                           ', ' . $hace . ', así que puede no estar al día. ' .
                           'Que lo confirme un agente antes de seguir.'];
    };

    // "Retirar todo" = todo el saldo (sin decimales). El monto lo pone el server,
    // no el jugador: así no depende de que el modelo copie bien la cifra.
    if ($todo) {
        $monto = (int)floor($saldo);
    }

    // Coherencia monto vs saldo. El minimo de RETIRO es su propio numero: antes
    // reusaba el de carga, y son negocios distintos -- se suele dejar cargar
    // poco y exigir mas para pagar.
    $minRetiro = fichas_limite($pdo, 'lim_retiro_min', FICHAS_MIN_CARGA);
    if ($saldo < $minRetiro) {
        if ($saldoViejo) { return $incierto((float)$monto); }
        return ['ok' => false, 'codigo' => 'saldo_bajo', 'saldo' => $saldo,
                'error' => 'Tu saldo es de ' . number_format($saldo, 0, ',', '.') .
                           ', menos del mínimo para retirar (' . number_format($minRetiro, 0, ',', '.') . ').'];
    }
    if ($monto < $minRetiro) {
        return ['ok' => false, 'codigo' => 'monto_bajo', 'saldo' => $saldo, 'minimo' => $minRetiro,
                'error' => 'El mínimo para retirar es ' . number_format($minRetiro, 0, ',', '.') . ' fichas.'];
    }
    /* Tope de UN retiro (lim_retiro_max, 0 = sin tope). Va antes del chequeo
       de saldo a proposito: si pide mas del tope Y no le alcanza, lo que hay
       que decirle es el tope -- bajar el monto es lo unico que puede hacer. */
    $topeUno = fichas_limite($pdo, 'lim_retiro_max', 0);
    if ($topeUno > 0 && $monto > $topeUno) {
        return ['ok' => false, 'codigo' => 'monto_alto', 'saldo' => $saldo,
                'maximo' => $topeUno,
                'error' => 'Por retiro podés pedir hasta ' .
                    number_format($topeUno, 0, ',', '.') .
                    '. Si querés sacar más, hacelo en varios pedidos.'];
    }
    if ($saldo + 0.01 < $monto) {
        if ($saldoViejo) { return $incierto((float)$monto); }
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
        /* YA TIENE UNO PEDIDO. Antes se cortaba acá y listo, pero eso dejaba
           un caso muy común sin salida: el bot registra el retiro apenas sabe
           el monto -- para no perderlo si el jugador abandona -- y el CBU llega
           en el mensaje SIGUIENTE. Ese segundo llamado caía acá y el dato se
           perdía: el pedido quedaba sin destino y el aviso de Telegram salía
           vacío, que es justo lo que reportó Nahuel.
           Si el retiro que ya existe NO tiene destino y ahora sí lo trae, se
           completa. Y RECIÉN AHÍ suena el Telegram, con el dato adentro: el
           aviso sirve para pagar, y sin CBU no se puede pagar. */
        $idPrevio = (int)$idPrevio;
        if ($destino !== '') {
            try {
                $upd = $pdo->prepare(
                    "UPDATE acciones_saldo
                        SET destino = ?
                      WHERE id = ? AND tipo = 'retirar'
                        AND estado IN ('pendiente','revisar')
                        AND COALESCE(destino,'') = ''"
                );
                $upd->execute([mb_substr($destino, 0, 64), $idPrevio]);
                if ($upd->rowCount() > 0) {
                    // Se guarda tambien para la proxima vez que retire.
                    try {
                        $pdo->prepare("UPDATE usuarios SET cobro_destino = ? WHERE username = ?")
                            ->execute([mb_substr($destino, 0, 64), $usuario]);
                    } catch (Throwable $e) { /* sin migracion 43 */ }
                    fichas_avisar_retiro($pdo, $usuario, $idPrevio);
                    return ['ok' => true, 'id' => $idPrevio, 'destino' => $destino,
                            'falta_destino' => false, 'completado' => true,
                            'mensaje' => 'Listo, ya quedó registrado con tu alias. '
                                       . 'Lo aprueba un agente y te avisamos por el chat.'];
                }
            } catch (Throwable $e) {
                error_log('fichas_pedir_retiro/completar destino: ' . $e->getMessage());
            }
        }
        return ['ok' => false, 'codigo' => 'en_curso', 'id' => $idPrevio,
                'error' => 'Ya tenés un retiro pedido. Un agente lo está viendo.'];
    }

    /* ¿Y UNO PEDIDO DENTRO DEL JUEGO? Son DOS colas distintas: la de arriba es
       la nuestra (`acciones_saldo`), y el boton de retirar de adentro de la
       plataforma deja el pedido del lado de ganamos, que espejamos en
       `retiros_panel` (migracion 64). Hasta ahora esta funcion solo miraba la
       nuestra, asi que un jugador podia tener los dos abiertos a la vez.

       NO ES TEORICO. El 15/09/2026 uno pidio 4.000 desde el juego y 4.280 por
       el chat: el agente le transfirio 4.280 al banco y despues resolvio en el
       panel el pedido de 4.000 -- le quedaron 280 fichas adentro y dos pedidos
       que decian cosas distintas sobre la misma plata. Con dos pedidos abiertos
       del mismo jugador, la forma normal de equivocarse es pagar los dos.

       Va DESPUES del bloque de arriba a proposito: ese completa el CBU de un
       retiro nuestro que ya existe, y esa ayuda no se pierde por esto.

       Si falta la migracion 64 no hay espejo y se sigue como siempre: una
       tabla que no existe no puede frenar un retiro legitimo. */
    try {
        $enJuego = $pdo->prepare(
            "SELECT request_id, monto FROM retiros_panel
              WHERE username = ? AND estado = 'abierto'
              ORDER BY primera_vez DESC LIMIT 1"
        );
        $enJuego->execute([$usuario]);
        if ($rp = $enJuego->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'codigo' => 'en_curso',
                    'id' => (int)$rp['request_id'], 'en_el_juego' => true,
                    'error' => 'Ya pediste un retiro de '
                             . number_format((float)$rp['monto'], 0, ',', '.')
                             . ' fichas desde el juego y lo está viendo un agente. '
                             . 'Cuando se resuelva podés pedir otro.'];
        }
    } catch (Throwable $e) {
        // Sin migracion 64: se sigue como antes.
        error_log('fichas_pedir_retiro/retiros_panel: ' . $e->getMessage());
    }

    /* Tope de retiro POR DIA (config_crm.lim_retiro_max_dia, 0 = sin tope).
       Se cuentan los pedidos de hoy incluyendo los que todavia no se pagaron:
       si solo se sumaran los ya pagados, alcanzaria con encolar varios juntos
       para saltear el tope. Los rechazados y cancelados no cuentan, que seria
       castigar al jugador por un pedido que no le pagamos. */
    $topeDia = fichas_limite($pdo, 'lim_retiro_max_dia', 0);
    if ($topeDia > 0) {
        try {
            /* El "dia" es el dia ARGENTINO, no el del reloj de la base. Antes
               decia `DATE(creada_en) = CURDATE()` y con la base en UTC el tope
               se reseteaba a las 21:00 hora argentina. Ver fichas_rango_dia_ar(). */
            $dia = fichas_rango_dia_ar($pdo);
            $q = $pdo->prepare(
                "SELECT COALESCE(SUM(monto),0) FROM acciones_saldo
                  WHERE usuario = ? AND tipo = 'retirar'
                    AND estado IN ('pendiente','procesando','revisar','hecha')
                    AND creada_en >= ? AND creada_en < ?"
            );
            $q->execute([$usuario, $dia['desde'], $dia['hasta']]);
            $yaHoy = (float)$q->fetchColumn();
        } catch (Throwable $e) {
            // Sin poder contar, no se bloquea: el tope es una politica
            // comercial, no un control de fraude. Frenar un retiro legitimo
            // por un error de lectura es peor que dejar pasar uno de mas.
            error_log('fichas_pedir_retiro: no pude sumar el tope diario: ' . $e->getMessage());
            $yaHoy = 0.0;
        }
        if ($yaHoy + $monto > $topeDia) {
            $resta = max(0, $topeDia - (int)$yaHoy);
            return ['ok' => false, 'codigo' => 'tope_diario', 'saldo' => $saldo,
                    'tope_dia' => $topeDia, 'ya_hoy' => (int)$yaHoy, 'disponible' => $resta,
                    'error' => $resta > 0
                        ? 'Por hoy podés retirar hasta ' . number_format($resta, 0, ',', '.') .
                          ' (el tope diario es ' . number_format($topeDia, 0, ',', '.') . ').'
                        : 'Ya llegaste al tope de retiro de hoy (' .
                          number_format($topeDia, 0, ',', '.') . '). Mañana podés seguir.'];
        }
    }

    /* Cuantos retiros por dia (lim_retiro_cant_dia, 0 = sin limite). Cuenta
       PEDIDOS, no plata: es el otro lado del tope diario. Un casino que paga
       100.000 por dia en tandas de 50.000 necesita los dos numeros, porque con
       el tope de monto solo el jugador se lleva todo en un pedido.
       Mismo dia ARGENTINO y mismos estados que el tope de monto: los
       rechazados y cancelados no gastan cupo. */
    $cantDia = fichas_limite($pdo, 'lim_retiro_cant_dia', 0);
    if ($cantDia > 0) {
        try {
            $dia = fichas_rango_dia_ar($pdo);
            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM acciones_saldo
                  WHERE usuario = ? AND tipo = 'retirar'
                    AND estado IN ('pendiente','procesando','revisar','hecha')
                    AND creada_en >= ? AND creada_en < ?"
            );
            $q->execute([$usuario, $dia['desde'], $dia['hasta']]);
            $hechosHoy = (int)$q->fetchColumn();
        } catch (Throwable $e) {
            // Mismo criterio que el tope de monto: si no se puede contar, no se
            // bloquea. Es una politica comercial, no un control de fraude.
            error_log('fichas_pedir_retiro: no pude contar los retiros de hoy: ' . $e->getMessage());
            $hechosHoy = 0;
        }
        if ($hechosHoy >= $cantDia) {
            return ['ok' => false, 'codigo' => 'tope_cantidad', 'saldo' => $saldo,
                    'cant_dia' => $cantDia, 'hechos_hoy' => $hechosHoy,
                    'error' => $cantDia === 1
                        ? 'Se puede pedir un retiro por día. Mañana podés pedir otro.'
                        : 'Ya pediste los ' . $cantDia . ' retiros de hoy. Mañana podés seguir.'];
        }
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

    $idRetiro = (int)$pdo->lastInsertId();

    /* Aviso al agente: un retiro NO se paga solo, lo tiene que aprobar una
       persona. Sin esto, el pedido espera a que alguien abra el CRM por su
       cuenta -- y el jugador ya vio "lo aprueba un agente y te avisamos".
       Va DESPUES del INSERT: el pedido ya esta registrado cuando suena, asi que
       el agente que abra el CRM lo va a encontrar.
       Sin clave a proposito: cada pedido de retiro es un evento distinto y hay
       que avisarlos todos, no agruparlos. */
    if (function_exists('tg_evento')) {
        /* Una sola via para el aviso: fichas_avisar_retiro(). Si todavia no
           hay destino NO manda nada -- el pedido ya quedo registrado y se ve en
           Retiros pendientes; el aviso sale cuando el jugador da el CBU. */
        fichas_avisar_retiro($pdo, $usuario, $idRetiro);
    }

    return ['ok' => true, 'id' => $idRetiro, 'monto' => $monto,
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
        // bono_debitado es de la migracion 56: sin ella, consulta vieja y el
        // bono (que tampoco se pudo debitar sin la 56) queda en 0.
        try {
            $st = $pdo->prepare(
                "SELECT usuario, coins_debitados, bono_debitado FROM acciones_saldo WHERE id = ? FOR UPDATE"
            );
            $st->execute([$accionId]);
            $a = $st->fetch();
        } catch (PDOException $e) {
            $st = $pdo->prepare(
                "SELECT usuario, coins_debitados, 0 AS bono_debitado FROM acciones_saldo WHERE id = ? FOR UPDATE"
            );
            $st->execute([$accionId]);
            $a = $st->fetch();
        }

        $monto = $a ? (int)$a['coins_debitados'] : 0;
        $bono  = $a ? (int)$a['bono_debitado']   : 0;
        if ($monto <= 0 && $bono <= 0) {
            $pdo->commit();
            return 0;
        }

        if ($monto > 0) {
            $pdo->prepare("UPDATE usuarios SET coins = coins + ? WHERE username = ?")
                ->execute([$monto, $a['usuario']]);

            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'ficha', ?, 'Devolución: la carga no se pudo hacer', 'sistema')"
            )->execute([$a['usuario'], $monto]);
        }

        // El bono vuelve a SU contador: devolverlo a coins lo convertiria en
        // plata comun (y retirable) que el jugador nunca puso.
        if ($bono > 0) {
            $pdo->prepare("UPDATE usuarios SET bonus = bonus + ? WHERE username = ?")
                ->execute([$bono, $a['usuario']]);

            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'bono', ?, 'Devolución del bono: la carga no se pudo hacer', 'sistema')"
            )->execute([$a['usuario'], $bono]);
        }

        try {
            $pdo->prepare("UPDATE acciones_saldo SET coins_debitados = 0, bono_debitado = 0 WHERE id = ?")
                ->execute([$accionId]);
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE acciones_saldo SET coins_debitados = 0 WHERE id = ?")
                ->execute([$accionId]);
        }

        $pdo->commit();
        return $monto + $bono;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
