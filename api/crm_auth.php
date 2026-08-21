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
    function _crm_sesion_iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,          // se cierra con el navegador
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        // Nombre propio (no el PHPSESSID de default) para no compartir
        // cookie con otra cosa que corra en el mismo dominio/hosting.
        session_name('goldpaw_crm');
        session_start();
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
     */
    function exigir_operador(): string
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

        return $operador;
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
