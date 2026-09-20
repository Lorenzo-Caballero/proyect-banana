<?php
/**
 * crm_auth.php — Sesión de operador para el CRM (Fase 0.5, ver CRM_DESIGN.md).
 *
 *   operador_login(PDO $pdo, string $usuario, string $password): bool
 *   operador_actual(): ?string
 *   exigir_operador(): string          -- corta con 401/403 si no corresponde
 *   operador_logout(): void
 *   csrf_token(): ?string
 *
 * Requiere sql/18_operadores.sql. Requiere un $pdo (PDO) ya conectado
 * cuando se llama operador_login() — lo pasa quien la incluye, igual que
 * crm_lib.php / auth_lib.php.
 *
 * No es JWT ni ninguna librería: sesión de PHP nativa, con los mismos
 * cuidados que ya tiene el resto de la API (hash_equals para comparar
 * secretos, password_hash/password_verify para las claves).
 */

declare(strict_types=1);

if (!function_exists('operador_login')) {

    /**
     * Arranca (o retoma) la sesión con las cookies endurecidas. Idempotente:
     * llamarla más de una vez en el mismo request no hace nada la segunda vez.
     *
     * `secure => true` a propósito: el CRM se sirve por HTTPS en Hostinger.
     * Si algún día se prueba contra HTTP plano (localhost sin túnel), la
     * cookie de sesión no se va a setear — hay que probar Fase 0.5 contra el
     * dominio real o un túnel HTTPS, nunca contra HTTP sin cifrar.
     */
    /**
     * Cuántas horas dura la sesión del operador. 24 por pedido del dueño
     * (18/09/2026, "la sesión del CRM dura muy poco, quiero que dure al menos
     * 24 hs"). Configurable sin deploy: 'CRM_SESION_HORAS' en
     * api/config.local.php. Se acota a [1, 720] para que un valor mal tipeado
     * no deje sesiones eternas ni de un minuto.
     */
    function crm_sesion_horas(): int
    {
        $v = function_exists('cfg') ? cfg('CRM_SESION_HORAS', '') : '';
        $h = ($v === '' || $v === null) ? 24 : (int)$v;
        return max(1, min(720, $h));
    }

    function _crm_sesion_iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        /* LA SESIÓN DURA 24 HORAS Y ES DESLIZANTE. Cuatro patas, y las cuatro
           hacen falta — el 15/09/2026 se arreglaron dos y el operador SIGUIÓ
           teniendo que loguearse ("dura muy poco", 18/09/2026):

           1. CARPETA DE SESIONES PROPIA. En Ubuntu las sesiones de PHP viven
              en /var/lib/php/sessions y un cron del SISTEMA (sessionclean)
              las borra según el gc_maxlifetime del php.ini — 24 MINUTOS por
              default. Un ini_set() acá no lo frena: el cron ni mira lo que
              esta app configura en runtime.

           2. QUE ESA CARPETA SEA PERSISTENTE, que es lo que faltaba. Estaba
              en sys_get_temp_dir(), o sea /tmp, y php-fpm en Debian/Ubuntu
              corre con PrivateTmp=true: ese /tmp es privado del servicio y
              SE BORRA ENTERO cada vez que php-fpm se reinicia o recarga —
              cosa que pasa en cada deploy y en cada logrotate. O sea que la
              sesión no duraba 12 horas: duraba hasta el próximo reinicio de
              PHP. Ese era el "me pide la clave a cada rato" que quedaba.
              Ahora se prueban carpetas persistentes primero y /tmp queda solo
              como último recurso (mejor sesiones frágiles que ninguna).

           3. COOKIE QUE SE RENUEVA CON EL USO. `lifetime` se manda una sola
              vez, cuando la sesión nace: la cookie moría a las 12 horas EXACTAS
              del login aunque el operador estuviera trabajando. Ahora se
              reenvía en cada request (ventana deslizante), así una jornada
              larga no corta a la mitad.

           4. QUE EL ARCHIVO NO SE ENFRÍE. Con session.lazy_write (default en
              PHP 7+) una sesión que no cambia no se reescribe, su mtime queda
              viejo y el gc la borra por "vencida" aunque se esté usando. Por
              eso se estampa una marca cada 10 minutos: mantiene vivo el
              archivo de una sesión activa.

           Ojo al desplegar: cambiar la carpeta invalida las sesiones que
           estaban en la anterior. Se pide la clave UNA vez más y listo. */
        $vida = crm_sesion_horas() * 3600;

        /* Candidatas, de más a menos persistente. La primera escribible gana.
           NINGUNA puede estar bajo el docroot: un archivo de sesión servido
           por nginx es la sesión de un operador descargable por cualquiera. */
        $dir = '';
        foreach ([
            '/var/lib/goldpaw/crm_sesiones',
            '/var/tmp/goldpaw_crm_sesiones',     // /var/tmp sobrevive al reinicio del servicio
            sys_get_temp_dir() . '/goldpaw_crm_sesiones',
        ] as $cand) {
            if (!is_dir($cand)) { @mkdir($cand, 0700, true); }
            if (is_dir($cand) && is_writable($cand)) { $dir = $cand; break; }
        }
        if ($dir !== '') {
            /* UNA SUBCARPETA POR CLIENTE. El server es multi-tenant y hasta
               ahora todos compartían la misma carpeta: un id de sesión válido
               en un cliente resolvía a un archivo con `operador` seteado
               también desde el dominio de OTRO cliente. La cookie no viaja
               sola entre dominios, pero copiarla a mano alcanzaba. Separadas,
               el archivo directamente no existe del otro lado. */
            $tenant = preg_replace('/[^A-Za-z0-9_.-]/', '',
                                   (string)($GLOBALS['TENANT_DB'] ?? 'default')) ?: 'default';
            $porTenant = $dir . '/' . $tenant;
            if (!is_dir($porTenant)) { @mkdir($porTenant, 0700, true); }
            if (is_dir($porTenant) && is_writable($porTenant)) { $dir = $porTenant; }

            session_save_path($dir);
            // El cron del sistema ya no limpia por nosotros: gc propio, ~1 de
            // cada 200 requests.
            @ini_set('session.gc_probability', '1');
            @ini_set('session.gc_divisor', '200');
        }
        /* El gc va con MÁS margen que la cookie a propósito: si borrara justo
           a las 24 h, una sesión en el límite moriría del lado del server con
           la cookie todavía viva — el operador vuelve al login sin entender
           por qué. */
        @ini_set('session.gc_maxlifetime', (string)($vida + 12 * 3600));

        $cookie = [
            'lifetime' => $vida,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        session_set_cookie_params($cookie);
        // Nombre propio (no el PHPSESSID de default) para no compartir
        // cookie con otra cosa que corra en el mismo dominio/hosting.
        session_name('goldpaw_crm');
        session_start();

        /* VENTANA DESLIZANTE (pata 3). Se reenvía la cookie con la vida
           entera desde AHORA, así el reloj arranca de nuevo en cada request.
           Solo si ya hay sesión de operador: a un visitante sin login no hay
           nada que renovarle. Y antes de cualquier salida — todos los
           endpoints del CRM llaman a esto arriba de todo. */
        if (!empty($_SESSION['operador']) && !headers_sent()) {
            @setcookie(session_name(), session_id(), [
                'expires'  => time() + $vida,
                'path'     => $cookie['path'],
                'domain'   => $cookie['domain'],
                'secure'   => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'],
            ]);
            // Pata 4: que el archivo no se enfríe. Cada 10 minutos alcanza
            // para mantenerlo fresco sin escribir en cada request.
            if ((int)($_SESSION['tocada'] ?? 0) < time() - 600) {
                $_SESSION['tocada'] = time();
            }
        }
    }

    /**
     * Valida usuario+password contra `operadores` y, si entra, abre la
     * sesión. Devuelve false sin dar pistas de si fue el usuario o la clave
     * (mismo criterio que auth.php: no filtrar cuál de los dos falló).
     */
    function operador_login(PDO $pdo, string $usuario, string $password): bool
    {
        _crm_sesion_iniciar();

        $usuario = trim($usuario);
        if ($usuario === '' || $password === '') {
            return false;
        }

        // SELECT * y no columnas puntuales: `rol` (migración 31) puede no
        // existir todavía en algún cliente, y así no hace falta un try/catch
        // aparte para el caso "columna faltante" — simplemente no viene en la fila.
        $st = $pdo->prepare(
            "SELECT * FROM operadores WHERE username = ? AND activo = 1 LIMIT 1"
        );
        $st->execute([$usuario]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);

        if (!$fila || !password_verify($password, (string)$fila['password_hash'])) {
            return false;
        }

        // Session fixation: la sesión de verdad nace RECIÉN acá, después de
        // validar la clave. Si alguien plantó un session id antes del login
        // (ej. por un link con ?PHPSESSID=... en otro sitio), regenerarlo
        // corta esa sesión vieja y arranca una limpia.
        session_regenerate_id(true);
        // Casing canonico de la fila, NO lo que se tipeo: WHERE username=?
        // matchea sin importar mayusculas/minusculas (collation
        // utf8mb4_unicode_ci de `operadores`), asi que sin esto la misma
        // persona podia quedar auditada con distinto casing segun como
        // haya tipeado su usuario al loguearse (bug real, ver
        // TODO_FASE_A.md -- Modulo 4).
        $_SESSION['operador'] = (string)$fila['username'];
        // Sin columna `rol` (migración 31 no corrida) -> 'admin', igual que
        // hoy: todos los operadores existentes pueden todo hasta que se corra.
        $_SESSION['rol']      = in_array($fila['rol'] ?? 'admin', ['admin', 'agente'], true)
                               ? $fila['rol'] : 'admin';
        $_SESSION['csrf']     = bin2hex(random_bytes(32));

        $pdo->prepare("UPDATE operadores SET ultimo_login = NOW() WHERE username = ?")
            ->execute([(string)$fila['username']]);

        return true;
    }

    /** Username del operador logueado, o null si no hay sesión válida. */
    function operador_actual(): ?string
    {
        _crm_sesion_iniciar();
        $op = $_SESSION['operador'] ?? null;
        return (is_string($op) && $op !== '') ? $op : null;
    }

    /** 'admin' | 'agente' del operador logueado, o null si no hay sesión. */
    function operador_rol(): ?string
    {
        _crm_sesion_iniciar();
        if (operador_actual() === null) { return null; }
        $r = $_SESSION['rol'] ?? 'admin';
        return in_array($r, ['admin', 'agente'], true) ? $r : 'admin';
    }

    /** El token CSRF de la sesión actual, o null si no hay sesión. */
    function csrf_token(): ?string
    {
        _crm_sesion_iniciar();
        $t = $_SESSION['csrf'] ?? null;
        return (is_string($t) && $t !== '') ? $t : null;
    }

    /**
     * Punto de entrada único para proteger un endpoint del CRM: exige sesión
     * válida y, si el método es POST, exige además el header X-CSRF-Token
     * correcto. Corta con 401/403 y JSON si algo falla. Devuelve el username
     * del operador para que el caller lo use directo (ej. para completar la
     * columna `operador` de `movimientos`).
     *
     * Se valida CSRF acá adentro y no en un chequeo aparte a propósito: un
     * segundo paso que el endpoint tiene que acordarse de llamar es un paso
     * que tarde o temprano alguien se olvida.
     *
     * $chequearSaldo=false lo usa ÚNICAMENTE api/suscripcion.php: un cliente
     * sin saldo tiene que poder seguir entrando ahí para pagar y destrabarse,
     * si no quedaría sin salida posible.
     */
    function exigir_operador(bool $chequearSaldo = true): string
    {
        _crm_sesion_iniciar();

        $operador = operador_actual();
        if ($operador === null) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sesión requerida']);
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $enviado  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $esperado = $_SESSION['csrf'] ?? '';
            if ($esperado === '' || !hash_equals((string)$esperado, (string)$enviado)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
                exit;
            }
        }

        if ($chequearSaldo) {
            exigir_saldo_plataforma();
        }

        return $operador;
    }

    /**
     * Corta con 402 si la SUSCRIPCIÓN DE LA PLATAFORMA del cliente actual está
     * sin saldo (no confundir con saldo de jugadores). Cachea el resultado en
     * sesión 5 minutos para no pegarle a goldpaw_control en cada request
     * autenticado del CRM -- el costo de tardar hasta 5 min en reflejar un
     * bloqueo o una recarga es aceptable, esto es un corte administrativo, no
     * un control de seguridad en tiempo real.
     *
     * Si goldpaw_control no responde, FAIL-OPEN (no bloquear el CRM de un
     * cliente por un problema de infraestructura ajeno a él) y loguea.
     */
    function exigir_saldo_plataforma(): void
    {
        _crm_sesion_iniciar();

        $cache = $_SESSION['saldo_plataforma_cache'] ?? null;
        if (is_array($cache) && ($cache['exp'] ?? 0) > time()) {
            if ($cache['bloqueado']) { _crm_cortar_sin_saldo($cache['mensaje'] ?? ''); }
            return;
        }

        $bloqueado = false;
        $mensaje   = '';
        try {
            $ctl = new PDO(
                'mysql:host=' . cfg('DB_HOST', 'localhost') . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
                cfg('DB_USER'), cfg('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $st = $ctl->prepare('SELECT suscripcion_estado FROM clientes WHERE db_nombre = ? LIMIT 1');
            $st->execute([$GLOBALS['TENANT_DB'] ?? '']);
            $estado = $st->fetchColumn();
            if ($estado === 'sin_saldo') {
                $bloqueado = true;
                $mensaje   = 'La suscripción de este CRM está sin saldo. Recargá desde "Mi suscripción" para reactivar el acceso.';
            }
        } catch (Throwable $e) {
            error_log('exigir_saldo_plataforma: no se pudo consultar goldpaw_control: ' . $e->getMessage());
            $bloqueado = false;
        }

        $_SESSION['saldo_plataforma_cache'] = ['bloqueado' => $bloqueado, 'mensaje' => $mensaje, 'exp' => time() + 300];
        if ($bloqueado) { _crm_cortar_sin_saldo($mensaje); }
    }

    function _crm_cortar_sin_saldo(string $mensaje): void
    {
        http_response_code(402);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'sin_saldo_plataforma', 'mensaje' => $mensaje]);
        exit;
    }

    /**
     * Como exigir_operador(), pero además exige rol='admin'. Corta con 403
     * si el operador es un agente. Devuelve el username, igual que la otra.
     */
    function exigir_admin(): string
    {
        $operador = exigir_operador();
        if (operador_rol() !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Necesitás ser admin para esto']);
            exit;
        }
        return $operador;
    }

    /** Cierra la sesión del operador. No lanza si ya estaba cerrada. */
    function operador_logout(): void
    {
        _crm_sesion_iniciar();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
    }
}
