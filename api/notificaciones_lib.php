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

            /* Las banderas del CRM son sobre la APP, no sobre el navegador: una
               visita desde la web no puede marcar tiene_app. Estas dos columnas
               existen desde la migracion 07 y hasta ahora no las escribia nadie. */
            if ($usuario !== null && $plataforma === 'android') {
                $pdo->prepare(
                    "UPDATE usuarios SET tiene_app = 1, notificaciones = ? WHERE username = ?"
                )->execute([$permitido ? 1 : 0, $usuario]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('notif_registrar_dispositivo: ' . $e->getMessage());
            return false;
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
