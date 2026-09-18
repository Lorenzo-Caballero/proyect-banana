<?php
/**
 * notificaciones_lib.php — Logica de las notificaciones push.
 *
 * No es un endpoint: son funciones que usan notificaciones.php (el dispositivo),
 * crm.php (el agente manda a mano) y recargas_lib.php (aviso automatico al
 * acreditar una transferencia).
 *
 * No hay Firebase. El modelo es "cola + sondeo":
 *
 *   crm.php / recargas_lib  --notif_crear()-->  tabla notificaciones
 *   APK (WorkManager, 15')  --notif_pendientes()-->  notificacion en la barra
 *   widget (25 s, app abierta) --notif_pendientes()-->  tarjeta en pantalla
 *
 * La entrega UNICA la garantiza notificaciones_entregas: se inserta primero y
 * solo se devuelve lo que se logro insertar. Por eso el worker del APK y el
 * widget pueden sondear a la vez sin que el jugador vea el aviso dos veces.
 *
 * Requiere un $pdo ya conectado (lo pasa quien la incluye).
 */

declare(strict_types=1);

// =====================  EDITA ESTO  =======================================
// Van con define() y no con const a proposito: const no se puede declarar
// adentro del if de mas abajo, y este archivo lo incluyen varios endpoints.
//
// NOTIF_VENTANA_DIAS: cuanto sigue viva una notificacion sin entregar. Si el
// jugador no abrio la app en ese plazo, el aviso ya no le llega: mejor eso que
// recibir de golpe la promo de hace un mes.
defined('NOTIF_VENTANA_DIAS')            || define('NOTIF_VENTANA_DIAS', 7);
// Cuantas se entregan por sondeo. Evita 20 notificaciones juntas.
defined('NOTIF_MAX_POR_SONDEO')          || define('NOTIF_MAX_POR_SONDEO', 5);
// Un dispositivo cuenta como activo (para el alcance) si dio señales de vida
// en este plazo.
defined('NOTIF_DISPOSITIVO_ACTIVO_DIAS') || define('NOTIF_DISPOSITIVO_ACTIVO_DIAS', 30);
// ==========================================================================

if (!function_exists('notif_crear')) {

    /**
     * Deja una notificacion en la cola. $usuario null o '' = para todos.
     *
     * NUNCA lanza: se la llama despues de acreditar fichas o una recarga, y que
     * falle el aviso no puede hacer que la carga parezca fallida. Devuelve el id
     * o 0 si no se pudo.
     */
    function notif_crear(PDO $pdo, ?string $usuario, string $titulo, string $cuerpo,
                         string $tipo = 'aviso', ?string $url = null,
                         string $origen = 'crm', ?string $expiraEn = null,
                         bool $soloApp = false, ?string $programadaEn = null): int
    {
        $usuario = $usuario !== null ? trim($usuario) : '';
        $titulo  = trim($titulo);
        $cuerpo  = trim($cuerpo);
        if ($titulo === '' || $cuerpo === '') { return 0; }

        $tipos = ['bono', 'fichas', 'recarga', 'ruleta', 'promo', 'aviso'];
        if (!in_array($tipo, $tipos, true)) { $tipo = 'aviso'; }

        // programada_en: NULL = se entrega ya; futura = recién en ese momento
        // (el sondeo la ignora hasta entonces, ver notif_pendientes). La
        // columna existe desde la migración 29; si no está, se cae al INSERT
        // sin ella para no romper (el catch de abajo lo cubre).
        $prog = ($programadaEn !== null && trim($programadaEn) !== '') ? trim($programadaEn) : null;

        $params = [
            $usuario !== '' ? mb_substr($usuario, 0, 50) : null,
            mb_substr($titulo, 0, 120),
            mb_substr($cuerpo, 0, 400),
            $tipo,
            $url !== null && $url !== '' ? mb_substr($url, 0, 300) : null,
            mb_substr($origen, 0, 20),
            $expiraEn,
            $soloApp ? 1 : 0,
            $prog,
        ];
        try {
            $pdo->prepare(
                "INSERT INTO notificaciones
                   (usuario, titulo, cuerpo, tipo, url, origen, expira_en, solo_app, programada_en)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute($params);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            // Sin columna programada_en (migración 29 no corrida): si NO se
            // pidió programar, la difusión normal no tiene por qué fallar por
            // eso. Se reintenta sin esa columna. Si SÍ se pidió programar,
            // no hay forma de cumplirlo sin la columna: se deja fallar (mejor
            // avisar que "se perdió la fecha" en silencio).
            if ($prog === null) {
                try {
                    array_pop($params);   // saca $prog del final
                    $pdo->prepare(
                        "INSERT INTO notificaciones
                           (usuario, titulo, cuerpo, tipo, url, origen, expira_en, solo_app)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute($params);
                    return (int)$pdo->lastInsertId();
                } catch (Throwable $e2) {
                    error_log('notif_crear (fallback): ' . $e2->getMessage());
                    return 0;
                }
            }
            error_log('notif_crear: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Alta o actualizacion de un celular. Lo llama el widget desde el WebView
     * (es un navegador de verdad, asi que el WAF de Hostinger no lo corta).
     *
     * De paso marca en `usuarios` las banderas que el CRM ya mostraba pero que
     * hasta ahora nadie escribia: tiene_app y notificaciones.
     */
    function notif_registrar_dispositivo(PDO $pdo, string $deviceId, ?string $usuario,
                                         string $plataforma = 'web', ?string $modelo = null,
                                         ?string $version = null, bool $permitido = true,
                                         bool $soltar = false): bool
    {
        $deviceId = substr(trim($deviceId), 0, 64);
        if ($deviceId === '') { return false; }
        $usuario = $usuario !== null && trim($usuario) !== '' ? mb_substr(trim($usuario), 0, 50) : null;
        if ($soltar) { $usuario = null; }
        if ($plataforma !== 'android') { $plataforma = 'web'; }

        try {
            /* Tres casos distintos y hay que separarlos bien:
                 - viene usuario            -> se ata el celular a ese jugador
                 - $soltar (cerro sesion)   -> se desata, y deja de recibir lo suyo
                 - ninguno (todavia no sabemos quien es) -> se deja como estaba,
                   porque un registro temprano no puede borrar la vinculacion. */
            $pdo->prepare(
                "INSERT INTO dispositivos (device_id, usuario, plataforma, modelo, version, permitido)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   usuario    = IF(?, NULL, COALESCE(VALUES(usuario), usuario)),
                   plataforma = VALUES(plataforma),
                   modelo     = COALESCE(VALUES(modelo), modelo),
                   version    = COALESCE(VALUES(version), version),
                   permitido  = VALUES(permitido),
                   visto_en   = NOW()"
            )->execute([
                $deviceId, $usuario, $plataforma,
                $modelo !== null && $modelo !== '' ? mb_substr($modelo, 0, 80) : null,
                $version !== null && $version !== '' ? mb_substr($version, 0, 20) : null,
                $permitido ? 1 : 0,
                $soltar ? 1 : 0,
            ]);

            /* EL HISTORIAL de que cuentas pasaron por este celular. La tabla
               `dispositivos` de arriba no sirve para eso y no es un descuido:
               su UNIQUE por device_id PISA el usuario cuando entra otra cuenta,
               que es justo lo que necesita para notificar --avisarle a quien
               esta usando el telefono AHORA-- y justo lo que borra el dato de
               que antes pasaron otras dos.
               Best-effort: esto solo alimenta un aviso en el CRM, no puede
               tumbar el registro del dispositivo ni las notificaciones. */
            if ($usuario !== null && $usuario !== '') {
                if (is_file(__DIR__ . '/vinculos_lib.php')) {
                    require_once __DIR__ . '/vinculos_lib.php';
                }
                if (function_exists('vin_anotar_dispositivo')) {
                    vin_anotar_dispositivo($pdo, $deviceId, $usuario);

                    /* EL CELULAR DE UN BLOQUEADO ENTRO CON OTRA CUENTA. El
                       alta por chat/landing ya la habria frenado, asi que si
                       esta cuenta existe vino por otra via (el panel, un alta
                       anterior al bloqueo). No se bloquea sola —un celular se
                       presta, y el bloqueo es decision de una persona (ver
                       vinculos_lib)— pero el operador se entera YA, con el
                       boton de la ficha a un click. Dedupe por par
                       bloqueado+cuenta: un aviso, no uno por sondeo. */
                    if (function_exists('vin_bloqueado') && function_exists('vin_bloqueado_por_senal')
                        && !vin_bloqueado($pdo, $usuario)) {
                        $duenoBloq = vin_bloqueado_por_senal($pdo, ['device_id' => $deviceId]);
                        if ($duenoBloq !== null) {
                            if (!function_exists('tg_evento') && is_file(__DIR__ . '/telegram_lib.php')) {
                                require_once __DIR__ . '/telegram_lib.php';
                            }
                            if (function_exists('tg_evento')) {
                                tg_evento($pdo, 'salud', '🚫 El celular de un bloqueado entró con otra cuenta', [
                                    'Cuenta nueva' => $usuario,
                                    'Bloqueado'    => $duenoBloq,
                                    'Qué pasó'  => 'El mismo aparato de un jugador bloqueado inició sesión con esta cuenta.',
                                    'Qué hacer' => 'Abrí la ficha de ' . $usuario . ' en el CRM y bloquealo con «también las vinculadas» si corresponde.',
                                ], 'dev_bloq_' . $duenoBloq . '_' . $usuario);
                            }
                        }
                    }
                }
            }

            /* Las banderas del CRM son sobre la APP, no sobre el navegador: una
               visita desde la web no puede marcar tiene_app. Estas dos columnas
               existen desde la migracion 07 y hasta ahora no las escribia nadie. */
            if ($usuario !== null && $plataforma === 'android') {
                /* La transicion 0 -> 1 en dos pasos A PROPOSITO: el primer
                   UPDATE (condicionado a tiene_app = 0) es un candado atomico
                   que gana UNA sola vez en la vida del jugador -- ese rowCount
                   es "recien instalo la app y entro", el momento del bono de
                   la promo y del aviso por Telegram. El segundo mantiene
                   `notificaciones` al dia en cada registro, como siempre.
                   Quien ya tenia la app antes de la promo no pasa por el
                   candado: no hay regalo retroactivo masivo el dia del deploy. */
                $primeraVez = $pdo->prepare(
                    "UPDATE usuarios SET tiene_app = 1 WHERE username = ? AND tiene_app = 0"
                );
                $primeraVez->execute([$usuario]);
                $pdo->prepare(
                    "UPDATE usuarios SET notificaciones = ? WHERE username = ?"
                )->execute([$permitido ? 1 : 0, $usuario]);

                if ($primeraVez->rowCount() === 1) {
                    /* El bono y el aviso solo si la request viene DE la app de
                       verdad: el WebView del APK agrega el sufijo GOLDPAW al
                       User-Agent (MainActivity), y un fetch desde una pagina
                       de navegador NO puede falsificar ese header. Un script
                       con curl si -- esto no es criptografia, corta el abuso
                       facil: el endpoint es publico y `usuario`/`plataforma`
                       los manda el cliente. tiene_app queda marcado igual (es
                       un hecho del espejo, no parte del regalo). */
                    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
                    if (strpos($ua, 'GOLDPAW') !== false) {
                        // Best-effort SIEMPRE: ni el bono ni el Telegram pueden
                        // hacer fallar el registro del dispositivo.
                        try { notif_app_instalada($pdo, $usuario); }
                        catch (Throwable $e) { error_log('notif_app_instalada: ' . $e->getMessage()); }
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log('notif_registrar_dispositivo: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * El jugador acaba de iniciar sesion desde la app POR PRIMERA VEZ (lo
     * garantiza el candado tiene_app 0->1 del que llama). Dos efectos, los dos
     * best-effort:
     *
     *   1. El bono de la promo "descarga la app" (config app_promo_activa +
     *      app_bono_fichas) -- PERO SOLO SI YA HIZO SU PRIMERA CARGA (pedido
     *      del 16/09/2026: el bono es "despues de la primera carga"). El que
     *      instala ANTES de cargar no cobra aca: queda un MARCADOR (fila en
     *      `movimientos` origen 'bono_app' con monto 0) y un aviso que le
     *      explica la condicion -- a proposito recien aca, con la app ya
     *      instalada, y nunca en la promo del navegador: la invitacion va sin
     *      letra chica. Cuando le entre su primera plata (cualquiera de los
     *      caminos), notif_app_bono_liberar() se lo paga.
     *
     *      "Ya cargo" lo contesta rl_es_primera_carga() (recargas_lib), LA
     *      definicion unica de una carga -- las dos vias, ver CLAUDE.md. Ante
     *      la duda (la lib no esta, o devolvio null) NO se paga ya: se
     *      difiere, que es el lado seguro.
     *
     *      La fila de `movimientos` (el pago o el marcador) es ademas el
     *      SEGUNDO candado: si alguien resetea tiene_app a mano, el bono no
     *      se paga ni se promete dos veces.
     *
     *   2. El aviso por Telegram (tg_ev_app), que sale aunque la promo este
     *      apagada: saber quien instala la app es una señal del negocio, no
     *      parte del regalo.
     */
    function notif_app_instalada(PDO $pdo, string $usuario): void
    {
        // Config: sin config_crm (migracion sin correr) no hay promo ni aviso
        // configurable -- y no se rompe nada.
        foreach (['/config_crm.php', '/telegram_lib.php'] as $opc) {
            if (is_file(__DIR__ . $opc)) { require_once __DIR__ . $opc; }
        }

        $fichas = 0;
        if (function_exists('cfg_crm_activo') && cfg_crm_activo($pdo, 'app_promo_activa')) {
            $fichas = max(0, (int)(cfg_crm($pdo, 'app_bono_fichas') ?? 0));
        }

        $acreditado = false;
        $pendiente  = false;
        if ($fichas > 0) {
            // La definicion de "ya cargo" vive en recargas_lib; carga perezosa
            // porque esto corre una sola vez en la vida del jugador y el resto
            // de esta lib no la necesita.
            if (!function_exists('rl_es_primera_carga') && is_file(__DIR__ . '/recargas_lib.php')) {
                require_once __DIR__ . '/recargas_lib.php';
            }
            $yaCargo = function_exists('rl_es_primera_carga')
                    && rl_es_primera_carga($pdo, $usuario) === 0;

            /* EL BONO ES POR PERSONA, NO POR CUENTA (16/09/2026).
               El candado de abajo es por `usuario`, asi que una cuenta nueva =
               un bono nuevo. Nahuel encontro a alguien cobrandolo varias veces:
               registrarse, instalar la app, cobrar, repetir.

               La condicion de la primera carga (de esta misma mañana) ya lo
               encarece bastante, pero no lo cierra: si el minimo de carga es
               1.280 y el bono 1.000, repetir la vuelta sigue conviniendo.

               El celular lo cierra bastante, porque no se multiplica gratis:
               `dispositivos_usuarios` (migracion 69) guarda que cuentas
               pasaron por cada aparato. Y desde el 16/09 a la tarde la misma
               pregunta se hace tambien a nivel BANCARIO (vinculos_lib: misma
               cuenta bancaria o mismo comprobante declarado), que cubre a
               quien usa dos telefonos. Todo vive en
               notif_app_bono_cobro_otro(), compartido con
               notif_app_bono_liberar(): el marcador tampoco es un cheque al
               portador.

               DOS LIMITES, dichos de frente:
                 - las tablas arrancan vacias, asi que esto protege de aca en
                   adelante y no puede revisar lo que ya paso;
                 - reinstalando la app se puede generar un device_id nuevo. Eso
                   ya es bastante mas trabajo que crearse una cuenta, que es
                   todo lo que se le pide a una defensa asi.

               Ante un error de base NO se frena el bono: negarle un regalo a un
               jugador legitimo por una consulta que fallo es peor que pagar uno
               de mas. */
            $otroCobro = notif_app_bono_cobro_otro($pdo, $usuario);
            $yaLoCobroOtro = $otroCobro !== null;
            if ($yaLoCobroOtro) {
                error_log("notif_app_instalada: bono de la app NO pagado a $usuario; "
                        . "ya lo cobro $otroCobro (misma persona)");
            }
            try {
                $pdo->beginTransaction();
                // Segundo candado (ver arriba). FOR UPDATE: dos registros
                // simultaneos del mismo jugador esperan aca y el segundo ve la
                // fila del primero. Matchea el pago Y el marcador: cualquiera
                // de los dos es "esta instalacion ya fue atendida".
                $ya = $pdo->prepare(
                    "SELECT id FROM movimientos
                      WHERE usuario = ? AND origen = 'bono_app' LIMIT 1 FOR UPDATE"
                );
                $ya->execute([$usuario]);
                /* `!$yaLoCobroOtro` corta las DOS ramas, y tiene que ser asi:
                   poner $fichas en 0 mas arriba no alcanzaba --al contrario,
                   era peor-- porque la rama del marcador inserta un movimiento
                   de monto 0 pase lo que pase, y ese marcador es exactamente lo
                   que notif_app_bono_liberar() cobra en la primera carga. O
                   sea: el bono se pagaba igual, un rato despues. */
                if (!$ya->fetch() && !$yaLoCobroOtro) {
                    if ($yaCargo) {
                        $pdo->prepare(
                            "UPDATE usuarios SET bonus = bonus + ? WHERE username = ?"
                        )->execute([$fichas, $usuario]);
                        $pdo->prepare(
                            "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                             VALUES (?, 'bono', ?, 'Bono por instalar la app', 'bono_app')"
                        )->execute([$usuario, $fichas]);
                        $acreditado = true;
                    } else {
                        // El marcador. Monto 0 a proposito: no es plata, no
                        // entra en ningun conteo (todos filtran monto > 0) y
                        // en la ficha del CRM se lee como lo que es.
                        $pdo->prepare(
                            "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                             VALUES (?, 'bono', 0, 'Bono de la app: espera su primera carga', 'bono_app')"
                        )->execute([$usuario]);
                        $pendiente = true;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('notif_app_instalada (bono): ' . $e->getMessage());
            }
        }

        if ($acreditado) {
            notif_app_bono_entregar($pdo, $usuario, $fichas);
        } elseif ($pendiente) {
            // La condicion se cuenta ACA, con la app recien instalada --
            // nunca en la promo del navegador (pedido explicito de Nahuel).
            try {
                notif_crear($pdo, $usuario,
                    '🎁 Tenés ' . number_format($fichas, 0, ',', '.') . ' fichas esperándote',
                    'Se acreditan solas apenas hagas tu primera carga. ¡Hacela y son tuyas!',
                    'bono', null, 'app');
            } catch (Throwable $e) {
                error_log('notif_app_instalada (notif pendiente): ' . $e->getMessage());
            }
        }

        if (function_exists('tg_evento')) {
            $lineas = ['Jugador' => $usuario];
            $lineas['Bono'] = $acreditado
                ? number_format($fichas, 0, ',', '.') . ' fichas acreditadas'
                : ($pendiente
                    ? number_format($fichas, 0, ',', '.') . ' fichas a la espera de su primera carga'
                    : 'sin bono (promo apagada o ya cobrado)');
            tg_evento($pdo, 'app', '📱 Instaló la app', $lineas);
        }
    }

    /**
     * ¿Alguna OTRA cuenta de la misma persona ya cobro el bono de la app?
     * Dos miradas, cada una best-effort:
     *
     *   1. El CELULAR: dispositivos_usuarios (migracion 69) -- que cuentas
     *      pasaron por este aparato. Es la natural para un bono que se cobra
     *      instalando una app.
     *   2. El BANCO: vin_bono_cobrado_por_grupo() (vinculos_lib) -- misma
     *      cuenta bancaria o mismo comprobante declarado. Cubre a quien usa
     *      dos telefonos distintos.
     *
     * Devuelve el usuario que ya lo cobro, o null. Ante error: null (se
     * paga) -- negarle el regalo a un legitimo por una consulta caida es
     * peor que pagar uno de mas.
     */
    function notif_app_bono_cobro_otro(PDO $pdo, string $usuario): ?string
    {
        try {
            $qd = $pdo->prepare(
                "SELECT o.usuario
                   FROM dispositivos_usuarios d
                   JOIN dispositivos_usuarios o
                     ON o.device_id = d.device_id AND o.usuario <> d.usuario
                   JOIN movimientos m
                     ON m.usuario = o.usuario AND m.origen = 'bono_app' AND m.monto > 0
                  WHERE d.usuario = ?
                  LIMIT 1"
            );
            $qd->execute([$usuario]);
            $otro = $qd->fetchColumn();
            if ($otro) { return (string)$otro; }
        } catch (Throwable $e) {
            // Sin la migracion 69 esta mirada no existe todavia.
        }

        try {
            if (!function_exists('vin_bono_cobrado_por_grupo') && is_file(__DIR__ . '/vinculos_lib.php')) {
                require_once __DIR__ . '/vinculos_lib.php';
            }
            if (function_exists('vin_bono_cobrado_por_grupo')) {
                return vin_bono_cobrado_por_grupo($pdo, $usuario, 'bono_app');
            }
        } catch (Throwable $e) {
            error_log('notif_app_bono_cobro_otro: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * El "despues de acreditar" del bono de la app, compartido entre el pago
     * al instalar (ya habia cargado) y el diferido (notif_app_bono_liberar):
     * mandarlo AL JUEGO en el acto por el mismo camino que el bono del CRM
     * (deposito solo-bono via fichas_pedir_carga, con bot y devolucion-si-
     * falla; si justo hay una carga en curso, queda en el contador) y el
     * festejo en el celular. Best-effort las dos cosas.
     */
    function notif_app_bono_entregar(PDO $pdo, string $usuario, int $fichas): void
    {
        try {
            require_once __DIR__ . '/fichas_lib.php';
            fichas_pedir_carga($pdo, $usuario, 0, 'bono_app', false, $fichas);
        } catch (Throwable $e) {
            error_log('notif_app_bono_entregar (al juego): ' . $e->getMessage());
        }
        try {
            notif_crear($pdo, $usuario, '🎁 ¡Fichas de regalo!',
                'Por instalar la app te acreditamos ' . number_format($fichas, 0, ',', '.')
                . ' fichas de bono. ¡Que las disfrutes!', 'bono', null, 'app');
        } catch (Throwable $e) {
            error_log('notif_app_bono_entregar (notif): ' . $e->getMessage());
        }
    }

    /**
     * La otra mitad del bono diferido: la llaman los caminos por donde entra
     * plata de verdad (rl_notificar_acreditada -- camino B y HG Cash --,
     * peticiones_cola -- camino A -- y crm_saldo -- la carga a mano del CRM)
     * DESPUES de su commit. Si este jugador instalo la app antes de cargar
     * (marcador monto 0 en origen 'bono_app', sin pago), le paga el bono que
     * la app le prometio.
     *
     * Idempotente por el mismo candado (fila con monto > 0 = ya se pago) y
     * best-effort: nunca lanza. Si la promo esta apagada en este momento no
     * paga -- la promo manda, el mismo criterio que al instalar.
     */
    function notif_app_bono_liberar(PDO $pdo, string $usuario): void
    {
        $usuario = trim($usuario);
        if ($usuario === '') { return; }

        if (is_file(__DIR__ . '/config_crm.php')) { require_once __DIR__ . '/config_crm.php'; }
        $fichas = 0;
        if (function_exists('cfg_crm_activo') && cfg_crm_activo($pdo, 'app_promo_activa')) {
            $fichas = max(0, (int)(cfg_crm($pdo, 'app_bono_fichas') ?? 0));
        }
        if ($fichas <= 0) { return; }

        $acreditado = false;
        $propia     = false;
        try {
            // Los callers llaman post-commit, pero si alguno llegara con una
            // transaccion abierta no se le pisa: se suma a la suya.
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }
            $st = $pdo->prepare(
                "SELECT monto FROM movimientos
                  WHERE usuario = ? AND origen = 'bono_app' FOR UPDATE"
            );
            $st->execute([$usuario]);
            $marcado = false; $pagado = false;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
                if ((int)$m > 0)   { $pagado  = true; }   // el pago (o su debito no: es negativo)
                if ((int)$m === 0) { $marcado = true; }   // el marcador de la instalacion
            }
            if ($marcado && !$pagado) {
                /* EL MARCADOR NO ES UN CHEQUE AL PORTADOR. Entre la
                   instalacion y la primera carga pudo aparecer la prueba de
                   que es la misma persona que ya cobro -- y justamente ESTA
                   carga es la que aprende la huella bancaria, que corre
                   antes de llegar aca. La misma pregunta que al instalar,
                   el mismo helper; y recien aca adentro para que las cargas
                   sin marcador (el 99%) no paguen las consultas de vinculos. */
                $otro = notif_app_bono_cobro_otro($pdo, $usuario);
                if ($otro !== null) {
                    error_log("notif_app_bono_liberar: bono NO liberado a $usuario; "
                            . "ya lo cobro $otro (misma persona)");
                } else {
                    $pdo->prepare(
                        "UPDATE usuarios SET bonus = bonus + ? WHERE username = ?"
                    )->execute([$fichas, $usuario]);
                    $pdo->prepare(
                        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                         VALUES (?, 'bono', ?, 'Bono por instalar la app', 'bono_app')"
                    )->execute([$usuario, $fichas]);
                    $acreditado = true;
                }
            }
            if ($propia) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('notif_app_bono_liberar: ' . $e->getMessage());
            return;
        }

        if ($acreditado) {
            notif_app_bono_entregar($pdo, $usuario, $fichas);
            /* Telegram SOLO sin transaccion abierta: hg_webhook llama
               rl_notificar_acreditada (que termina aca) ANTES de su commit,
               con la fila de `usuarios` lockeada FOR UPDATE -- y tg_evento es
               un curl sincronico de hasta 8 segundos. Sostener ese lock
               mientras se habla con Telegram es un cuelgue servido (mismo
               criterio que vin_avisar_multicuenta). En los otros caminos
               (matcher, directo, peticiones_cola) esto corre post-commit y
               el aviso sale igual; en HG se pierde solo la linea de TG. */
            if (!$pdo->inTransaction()) {
                if (is_file(__DIR__ . '/telegram_lib.php')) { require_once __DIR__ . '/telegram_lib.php'; }
                if (function_exists('tg_evento')) {
                    tg_evento($pdo, 'app', '🎁 Bono de la app liberado', [
                        'Jugador' => $usuario,
                        'Bono'    => number_format($fichas, 0, ',', '.') . ' fichas (hizo su primera carga)',
                    ]);
                }
            }
        }
    }

    /**
     * Lo que este dispositivo todavia no vio, y lo marca como entregado en la
     * misma pasada.
     *
     * El nombre de usuario NO viene del cliente: se lee de `dispositivos`, que es
     * lo que se registro desde la sesion real. Asi nadie pide las notificaciones
     * de otro jugador pasando un usuario cualquiera por la URL.
     */
    function notif_pendientes(PDO $pdo, string $deviceId, int $limite = NOTIF_MAX_POR_SONDEO): array
    {
        $deviceId = substr(trim($deviceId), 0, 64);
        if ($deviceId === '') { return []; }
        $limite = max(1, min(20, $limite));

        try {
            $st = $pdo->prepare("SELECT usuario, creado_en FROM dispositivos WHERE device_id = ? LIMIT 1");
            $st->execute([$deviceId]);
            $disp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$disp) { return []; }   // sin registrar: que se registre primero

            $pdo->prepare("UPDATE dispositivos SET visto_en = NOW() WHERE device_id = ?")
                ->execute([$deviceId]);

            // creado_en del dispositivo: una instalacion nueva no arranca con
            // toda la historia de promos encima. Para las PROGRAMADAS el corte
            // es contra programada_en, no contra creada_en: si no, una promo
            // creada antes de que el dispositivo existiera pero programada
            // para mas adelante nunca le llegaria a un celular nuevo.
            $st = $pdo->prepare(
                "SELECT n.id, n.titulo, n.cuerpo, n.tipo, n.url, n.solo_app, n.creada_en
                   FROM notificaciones n
                   LEFT JOIN notificaciones_entregas e
                          ON e.notificacion_id = n.id AND e.device_id = ?
                  WHERE e.notificacion_id IS NULL
                    AND (CASE WHEN n.programada_en IS NULL
                              THEN n.creada_en     > ?
                              ELSE n.programada_en > ?
                         END)
                    AND (n.programada_en IS NULL OR n.programada_en <= UTC_TIMESTAMP())
                    AND (CASE WHEN n.programada_en IS NULL
                              THEN n.creada_en    > DATE_SUB(NOW(),            INTERVAL " . NOTIF_VENTANA_DIAS . " DAY)
                              ELSE n.programada_en > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . NOTIF_VENTANA_DIAS . " DAY)
                         END)
                    AND (n.expira_en IS NULL OR n.expira_en > NOW())
                    AND (n.usuario IS NULL OR n.usuario <=> ?)
                  ORDER BY n.id ASC
                  LIMIT $limite"
            );
            // El usuario va como NULL real, no como '': con el cast a string,
            // un dispositivo sin usuario (registrado antes de saber quien es,
            // o despues de cerrar sesion) comparaba `n.usuario = ''`, que no
            // matchea nada -- las notificaciones personales encoladas mientras
            // tanto no le llegaban nunca. El <=> compara NULL sin sorpresas.
            $usuarioDisp = ($disp['usuario'] !== null && $disp['usuario'] !== '')
                ? (string)$disp['usuario'] : null;
            $st->execute([$deviceId, $disp['creado_en'], $disp['creado_en'], $usuarioDisp]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);

            // Reservar antes de devolver: la fila que no se pudo insertar ya la
            // tomo el otro sondeo (worker vs widget) y no se repite.
            $marcar = $pdo->prepare(
                "INSERT IGNORE INTO notificaciones_entregas (notificacion_id, device_id) VALUES (?,?)"
            );
            $salida = [];
            foreach ($filas as $f) {
                $marcar->execute([(int)$f['id'], $deviceId]);
                if ($marcar->rowCount() !== 1) { continue; }
                $salida[] = [
                    'id'        => (int)$f['id'],
                    'titulo'    => $f['titulo'],
                    'cuerpo'    => $f['cuerpo'],
                    'tipo'      => $f['tipo'],
                    'url'       => $f['url'],
                    // El widget se la lleva igual (asi queda consumida) pero no
                    // la dibuja: el jugador ya esta leyendo eso en el chat.
                    'solo_app'  => (bool)$f['solo_app'],
                    'creada_en' => $f['creada_en'],
                ];
            }
            return $salida;
        } catch (Throwable $e) {
            error_log('notif_pendientes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * "Te contestamos". La usan chatbot.php y el CRM cuando responde un agente.
     *
     * Va como solo_app: si el jugador sigue adentro de la app ya esta leyendo la
     * respuesta en el chat, y el widget la consume sin dibujarla. Si cerro, se la
     * lleva el worker y le aparece en la barra de Android.
     *
     * Sin usuario no se encola nada: un chat anonimo no tiene a quien avisarle.
     */
    function notif_chat(PDO $pdo, ?string $usuario, string $respuesta, bool $deAgente = false): int
    {
        if ($usuario === null || trim($usuario) === '') { return 0; }
        $texto = trim(preg_replace('/\s+/u', ' ', $respuesta) ?? '');
        if ($texto === '') { return 0; }
        if (mb_strlen($texto) > 140) { $texto = mb_substr($texto, 0, 139) . '…'; }

        return notif_crear(
            $pdo,
            $usuario,
            $deAgente ? 'Un agente te respondió' : 'GOLDPAW te respondió',
            $texto,
            'aviso',
            null,
            $deAgente ? 'crm' : 'chatbot',
            null,
            true
        );
    }

    /**
     * Difusiones PROGRAMADAS que todavía no salieron (programada_en futura,
     * en UTC). Para el listado del CRM: devuelve la fecha ya convertida a
     * hora de Argentina, lista para mostrar.
     */
    function notif_programadas_listar(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT id, usuario, titulo, cuerpo, tipo, programada_en
                   FROM notificaciones
                  WHERE programada_en IS NOT NULL AND programada_en > UTC_TIMESTAMP()
                  ORDER BY programada_en ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // sin columna (migración 29 no corrida)
        }
        return array_map(function ($r) {
            $ar = '';
            try {
                $dt = new DateTime($r['programada_en'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
                $ar = $dt->format('Y-m-d H:i');
            } catch (Throwable $e) {}
            return [
                'id' => (int)$r['id'], 'usuario' => $r['usuario'], 'titulo' => $r['titulo'],
                'cuerpo' => $r['cuerpo'], 'tipo' => $r['tipo'], 'programada_en_ar' => $ar,
            ];
        }, $rows);
    }

    /** Cancela una difusión que todavía no salió. false si ya salió o no existe. */
    function notif_programada_cancelar(PDO $pdo, int $id): bool
    {
        try {
            $st = $pdo->prepare(
                "DELETE FROM notificaciones WHERE id = ? AND programada_en > UTC_TIMESTAMP()"
            );
            $st->execute([$id]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** El jugador toco la notificacion. Solo para saber que promo funciona. */
    function notif_marcar_leida(PDO $pdo, string $deviceId, int $id): void
    {
        try {
            $pdo->prepare(
                "UPDATE notificaciones_entregas SET leida_en = NOW()
                  WHERE notificacion_id = ? AND device_id = ? AND leida_en IS NULL"
            )->execute([$id, substr(trim($deviceId), 0, 64)]);
        } catch (Throwable $e) {
            error_log('notif_marcar_leida: ' . $e->getMessage());
        }
    }

    /** A cuantos celulares activos le va a llegar. $usuario null = a todos. */
    function notif_alcance(PDO $pdo, ?string $usuario = null): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM dispositivos
                     WHERE permitido = 1
                       AND visto_en > DATE_SUB(NOW(), INTERVAL " . NOTIF_DISPOSITIVO_ACTIVO_DIAS . " DAY)";
            if ($usuario !== null && trim($usuario) !== '') {
                $st = $pdo->prepare($sql . " AND usuario = ?");
                $st->execute([mb_substr(trim($usuario), 0, 50)]);
            } else {
                $st = $pdo->query($sql);
            }
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('notif_alcance: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Texto listo para una carga de fichas o bonos. Devuelve [titulo, cuerpo].
     * Que el aviso lo redacte el server y no el CRM mantiene el tono parejo:
     * el agente carga el monto y el jugador siempre lee lo mismo.
     */
    function notif_texto_carga(string $tipo, int $monto, string $motivo = ''): array
    {
        $n = number_format($monto, 0, ',', '.');
        if ($tipo === 'bono') {
            $titulo = '¡Te regalamos ' . $n . ' bonos!';
            $cuerpo = 'Ya están acreditados en tu cuenta.';
        } else {
            $titulo = '¡Te cargamos ' . $n . ' fichas!';
            $cuerpo = 'Ya las tenés disponibles para jugar.';
        }
        if (trim($motivo) !== '') {
            $cuerpo .= ' ' . mb_substr(trim($motivo), 0, 120);
        }
        return [$titulo, $cuerpo];
    }
}
