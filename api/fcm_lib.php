<?php
/**
 * fcm_lib.php — El empujón de Firebase.
 *
 * QUÉ HACE, EN UNA LÍNEA: le golpea la puerta al celular para que vaya a buscar
 * los avisos ahora y no dentro de quince minutos.
 *
 * EL PUSH VA VACÍO, Y ES LA DECISIÓN CENTRAL DE TODO ESTO. El mensaje que sale
 * por Google no lleva título, ni cuerpo, ni el nombre del jugador, ni el monto:
 * lleva `{"gp":"1"}`. El teléfono lo recibe, se despierta, y pide los avisos
 * por `notificaciones.php` igual que siempre. Cuatro cosas salen gratis de eso:
 *
 *   1. La entrega única sigue donde estaba: la PK (notificacion_id, device_id)
 *      de `notificaciones_entregas`. Un push no puede duplicar un aviso porque
 *      no contiene ninguno.
 *   2. `solo_app` se aplica en un solo lugar.
 *   3. El texto de los avisos —que dice cuánta plata se le acreditó a quién—
 *      no viaja por Google.
 *   4. Si Firebase se cae, se saca o vence la cuenta, no se rompe nada: el
 *      sondeo de 15 minutos sigue ahí y el sistema vuelve a portarse como el
 *      19/09/2026. Lento, no roto.
 *
 * NADA DE ACÁ LANZA NUNCA. Se la llama desde `notif_crear()`, que a su vez se
 * llama después de acreditar fichas o una recarga. Que falle el timbre no puede
 * hacer que la carga parezca fallida.
 *
 * LA CLAVE NO ESTÁ EN EL REPO NI EN api/. Vive en /etc/goldpaw/firebase.json,
 * y los permisos son DOS -- olvidarse del segundo cuesta una hora de buscar
 * mal:
 *
 *     chown root:www-data /etc/goldpaw          &&  chmod 750 /etc/goldpaw
 *     chown www-data:www-data .../firebase.json &&  chmod 400 .../firebase.json
 *
 * El del archivo solo NO ALCANZA: sin permiso de ENTRAR a la carpeta, www-data
 * no llega hasta el aunque el archivo sea suyo, y file_exists() contesta false
 * igual que si no existiera. Paso el 20/09/2026, con la carpeta en 700 (solo
 * root) y el archivo perfecto adentro: el diagnostico decia "no existe" y el
 * archivo estaba ahi.
 *
 * chmod 400, dueño www-data. Los .json dentro de api/ se sirven por HTTP —
 * probado: devuelve 200— así que ahí sería descargable por cualquiera, y
 * además se commitearía. Con esa clave se le puede mandar una notificación a
 * todos los jugadores de todos los clientes.
 *
 * ES UNA SOLA PARA TODOS LOS CLIENTES, igual que la clave de IA. El APK de
 * todos los clientes tiene el mismo applicationId (com.goldpaw.app), así que un
 * solo proyecto de Firebase los atiende a todos. Lo que separa a un cliente de
 * otro es a qué tokens se le manda, y eso sale de SU base (cada uno tiene su
 * tabla `dispositivos`).
 */

declare(strict_types=1);

// =====================  EDITA ESTO  =======================================
// Dónde está la clave de cuenta de servicio. Se puede pisar desde
// config.local.php si algún día hay que moverla.
defined('FCM_CREDENCIALES') || define('FCM_CREDENCIALES', '/etc/goldpaw/firebase.json');

// Cuánto se espera a Google. Corto a propósito: esto corre DENTRO del request
// que ya acreditó la plata, y el jugador no tiene por qué esperar al timbre.
defined('FCM_TIMEOUT')      || define('FCM_TIMEOUT', 5);

// Cuántos celulares se despiertan de a uno antes de rendirse y dejar que el
// sondeo haga el resto. Un aviso para UN jugador toca 1-3 aparatos; este tope
// es la red por si alguien tiene veinte sesiones abiertas.
defined('FCM_MAX_DIRECTOS') || define('FCM_MAX_DIRECTOS', 25);

// CUANTO TIEMPO, EN TOTAL, PUEDE GASTAR UN REQUEST TOCANDO TIMBRES.
//
// EL PROBLEMA QUE ACOTA: crm.php arma las difusiones filtradas (inactivos, sin
// chat) con un foreach sobre los destinatarios, llamando a notif_crear() una
// vez por jugador. Como notif_crear() ahora toca el timbre, una campaña a
// trescientos jugadores dispara trescientas consultas y hasta trescientas
// llamadas a Google ADENTRO del request del agente. Con los 42 celulares de
// hoy son unos 8 segundos; con 500 el CRM se cuelga y el operador ve la
// pantalla congelada sin saber por que.
//
// Se corta por TIEMPO y no por cantidad porque lo que hay que proteger es que
// el agente no espere, y eso no depende de cuantos jugadores sean sino de
// cuanto tarde Google. Mismo criterio que USUARIOS_MAX_SEG en el colector.
//
// Cortar es barato: al que no se alcanzo a despertar le llega por el sondeo,
// que es como llegaba todo hasta la version 1.7. Se pierde inmediatez en la
// cola de una campaña masiva, no un aviso.
defined('FCM_PRESUPUESTO_SEG') || define('FCM_PRESUPUESTO_SEG', 8);
// ==========================================================================

if (!function_exists('fcm_credenciales')) {

    /**
     * La clave de cuenta de servicio, leída una sola vez por request.
     *
     * Devuelve null —sin ruido— si no está. Es el estado normal en desarrollo y
     * en cualquier cliente que todavía no configuró Firebase: ahí simplemente
     * no hay empujón y queda el sondeo.
     */
    function fcm_credenciales(): ?array
    {
        /* El cache vive en $GLOBALS y no en un static para que se pueda
           olvidar (fcm_olvidar). Sin eso no hay forma de probar los dos
           estados --con clave y sin clave-- en el mismo proceso, y el estado
           "sin clave" es el que tiene que degradar bien. Mismo patron que
           cfg_crm_olvidar(). */
        if (array_key_exists("__fcm_cred", $GLOBALS)) { return $GLOBALS["__fcm_cred"]; }
        $cache = null;
        $GLOBALS["__fcm_cred"] = null;

        $ruta = FCM_CREDENCIALES;
        if (!is_readable($ruta)) { return null; }
        try {
            $j = json_decode((string)file_get_contents($ruta), true);
            if (!is_array($j)) { return null; }
            foreach (['client_email', 'private_key', 'project_id'] as $k) {
                if (empty($j[$k])) {
                    error_log('fcm: la clave no tiene ' . $k);
                    return null;
                }
            }
            $cache = $j;
            $GLOBALS["__fcm_cred"] = $j;
        } catch (Throwable $e) {
            error_log('fcm_credenciales: ' . $e->getMessage());
        }
        return $cache;
    }

    /** Vuelve a leer la clave del disco la proxima vez. Lo usan los tests. */
    function fcm_olvidar(): void
    {
        unset($GLOBALS["__fcm_cred"]);
    }

    /** ¿Hay con qué mandar? Lo consultan los que quieren evitarse el trabajo. */
    function fcm_disponible(): bool
    {
        return fcm_credenciales() !== null && function_exists('curl_init')
            && function_exists('openssl_sign');
    }

    /**
     * El token de acceso de Google (OAuth2), que es lo que autoriza cada envío.
     *
     * CÓMO SE CONSIGUE: se arma un JWT firmado con la clave privada de la cuenta
     * de servicio y se lo canjea en el endpoint de Google por un token que dura
     * una hora. No hay forma más corta; Firebase dejó de aceptar la "server key"
     * vieja, que era una constante y se mandaba tal cual.
     *
     * SE CACHEA EN DISCO Y NO EN LA BASE, por dos razones: la credencial es
     * global (una para todos los clientes) mientras que cada base es de UN
     * cliente, y porque esto se lee en cada notificación — una consulta de más
     * por aviso, multiplicada por una promo masiva, se nota.
     *
     * Se renueva 5 minutos antes de que venza. Un token vencido no avisa: Google
     * contesta 401 y el aviso se pierde, que es justo el modo de fallar que
     * había que evitar.
     */
    function fcm_access_token(): ?string
    {
        $cred = fcm_credenciales();
        if ($cred === null) { return null; }

        $archivo = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'goldpaw_fcm_' . substr(sha1((string)$cred['client_email']), 0, 16) . '.json';

        // ¿Sirve el que ya tenemos?
        try {
            if (is_readable($archivo)) {
                $c = json_decode((string)file_get_contents($archivo), true);
                if (is_array($c) && !empty($c['token']) && (int)($c['expira'] ?? 0) > time() + 300) {
                    return (string)$c['token'];
                }
            }
        } catch (Throwable $e) {
            /* Un cache ilegible no es un error: se pide uno nuevo. */
        }

        $ahora = time();
        $aud   = (string)($cred['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $jwt   = fcm_firmar_jwt([
            'iss'   => $cred['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $aud,
            'iat'   => $ahora,
            'exp'   => $ahora + 3600,
        ], (string)$cred['private_key']);
        if ($jwt === null) { return null; }

        try {
            $ch = curl_init($aud);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => FCM_TIMEOUT,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]),
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $j = is_string($resp) ? json_decode($resp, true) : null;
            if ($code !== 200 || !is_array($j) || empty($j['access_token'])) {
                error_log('fcm: Google no dio token (HTTP ' . $code . '): '
                    . substr((string)$resp, 0, 200));
                return null;
            }

            $token = (string)$j['access_token'];
            $vive  = (int)($j['expires_in'] ?? 3600);
            /* 0600 y no el umask por defecto: es un bearer token; con él se le
               manda una notificación a cualquiera hasta que venza. */
            @file_put_contents($archivo, json_encode([
                'token'  => $token,
                'expira' => time() + $vive,
            ]));
            @chmod($archivo, 0600);
            return $token;
        } catch (Throwable $e) {
            error_log('fcm_access_token: ' . $e->getMessage());
            return null;
        }
    }

    /** Firma el JWT con la clave privada. null si openssl la rechaza. */
    function fcm_firmar_jwt(array $claims, string $clavePrivada): ?string
    {
        try {
            $b64 = static fn(string $s): string =>
                rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

            $cabecera = $b64((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $cuerpo   = $b64((string)json_encode($claims));
            $firma    = '';
            /* @ a proposito: una clave ilegible ya se reporta abajo con un
               mensaje que dice algo ("la clave esta cortada"), y el warning
               crudo de openssl no agrega nada salvo ruido en el log de
               produccion. El fallo NO se traga: se devuelve null. */
            if (!@openssl_sign($cabecera . '.' . $cuerpo, $firma, $clavePrivada, OPENSSL_ALGO_SHA256)) {
                error_log('fcm: openssl_sign fallo (¿la clave está cortada?)');
                return null;
            }
            return $cabecera . '.' . $cuerpo . '.' . $b64($firma);
        } catch (Throwable $e) {
            error_log('fcm_firmar_jwt: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Manda UN mensaje. `$destino` es ['token'=>...] o ['topic'=>...].
     *
     * @return string 'ok' | 'invalido' (ese token ya no existe) | 'error'
     */
    function fcm_enviar(array $destino): string
    {
        $cred = fcm_credenciales();
        if ($cred === null) { return 'error'; }
        $acceso = fcm_access_token();
        if ($acceso === null) { return 'error'; }

        /* `data` y NO `notification`, a propósito y es importante: un mensaje
           con bloque `notification` lo dibuja el sistema solo, con el texto que
           venga adentro, y NUESTRO servicio ni se entera cuando la app está
           cerrada. Con `data` puro siempre pasa por MensajesFCM, que es quien
           decide qué mostrar después de pedir la lista.

           priority HIGH es lo que despierta al teléfono en Doze. Sin esto el
           mensaje puede quedar esperando a la próxima ventana de
           mantenimiento, que es exactamente el problema que vinimos a
           resolver. */
        $mensaje = $destino + [
            'data'    => ['gp' => '1'],
            'android' => ['priority' => 'HIGH'],
        ];

        $url = 'https://fcm.googleapis.com/v1/projects/'
            . rawurlencode((string)$cred['project_id']) . '/messages:send';

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => FCM_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $acceso,
                    'Content-Type: application/json; charset=utf-8',
                ],
                CURLOPT_POSTFIELDS     => (string)json_encode(['message' => $mensaje]),
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) { return 'ok'; }

            /* UN TOKEN MUERTO NO ES UN ERROR NUESTRO, es lo normal: el jugador
               desinstaló, restauró un backup o limpió los datos. Google lo dice
               con 404/UNREGISTERED, o con 400 si el token está mal formado.
               Distinguirlo importa porque el que llama lo BORRA de la base; si
               se confundiera con una caída de Google, una caída de diez minutos
               le borraría el token a todo el parque. */
            $cuerpo = is_string($resp) ? $resp : '';
            if ($code === 404
                || ($code === 400 && str_contains($cuerpo, 'INVALID_ARGUMENT'))
                || str_contains($cuerpo, 'UNREGISTERED')) {
                return 'invalido';
            }

            error_log('fcm: HTTP ' . $code . ' ' . substr($cuerpo, 0, 200));
            return 'error';
        } catch (Throwable $e) {
            error_log('fcm_enviar: ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * El tópico de ESTE cliente: a dónde va un aviso para todos.
     *
     * POR QUÉ UN TÓPICO Y NO LA LISTA DE TOKENS. Un aviso masivo puede tocar
     * cientos de celulares, y la API v1 de Firebase manda de a UN mensaje por
     * llamada HTTP (el envío en lote se discontinuó). Trescientos aparatos
     * serían trescientas llamadas adentro del request del agente, que se
     * quedaría mirando la pantalla un minuto. Un tópico es UNA llamada, la
     * reparte Google, y no importa si mañana son diez mil.
     *
     * SALE DE LA BASE Y NO DEL SLUG: `TENANT_SLUG` viene vacío para los
     * clientes con dominio propio, así que todos compartirían el mismo tópico y
     * la promo de uno le llegaría a los jugadores de otro. `TENANT_DB` es el
     * identificador real del cliente — es justamente lo que db.php resuelve.
     */
    function fcm_topico(): string
    {
        $base = (string)($GLOBALS['TENANT_DB'] ?? '');
        if ($base === '') { $base = (string)($GLOBALS['TENANT_HOST'] ?? 'goldpaw'); }
        // Firebase sólo acepta [a-zA-Z0-9-_.~%] en el nombre de un tópico.
        return 'gp_' . preg_replace('/[^a-zA-Z0-9\-_.~%]/', '_', $base);
    }

    /**
     * Despierta a los celulares de un jugador (o a todos si $usuario es null).
     *
     * @return int cuántos empujones salieron. 0 no es un fallo: puede que nadie
     *             tenga la app, o que Firebase no esté configurado.
     */
    function fcm_despertar(PDO $pdo, ?string $usuario): int
    {
        if (!fcm_disponible()) { return 0; }
        if (fcm_sin_presupuesto()) { return 0; }

        $t0 = microtime(true);
        try {
            // ---- Para todos: un solo mensaje al tópico del cliente.
            if ($usuario === null || trim($usuario) === '') {
                $r = fcm_enviar(['topic' => fcm_topico()]) === 'ok' ? 1 : 0;
                fcm_gastar(microtime(true) - $t0);
                return $r;
            }

            // ---- Para uno: sus aparatos, los que dieron permiso.
            $st = $pdo->prepare(
                "SELECT device_id, fcm_token
                   FROM dispositivos
                  WHERE usuario = ? COLLATE utf8mb4_unicode_ci
                    AND fcm_token IS NOT NULL AND fcm_token <> ''
                    AND permitido = 1
                  ORDER BY visto_en DESC
                  LIMIT " . (int)FCM_MAX_DIRECTOS
            );
            $st->execute([trim($usuario)]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$filas) { fcm_gastar(microtime(true) - $t0); return 0; }

            $enviados = 0;
            $muertos  = [];
            foreach ($filas as $f) {
                /* Tambien adentro del bucle: un solo jugador con varios
                   aparatos y Google lento gastaria el presupuesto entero. */
                if (fcm_sin_presupuesto($t0)) { break; }
                $r = fcm_enviar(['token' => (string)$f['fcm_token']]);
                if ($r === 'ok') { $enviados++; }
                elseif ($r === 'invalido') { $muertos[] = (string)$f['device_id']; }
                /* 'error' no se toca: puede ser un corte de red de treinta
                   segundos, y borrar el token por eso sería cambiar una demora
                   por una pérdida permanente. */
            }

            if ($muertos) {
                /* Se limpia el token pero NO se borra el aparato: sigue siendo
                   el mismo celular, con sus entregas y su vínculo al jugador.
                   Lo único que caducó es la dirección, y la app manda una nueva
                   en el próximo arranque. */
                $marcas = implode(',', array_fill(0, count($muertos), '?'));
                $pdo->prepare(
                    "UPDATE dispositivos SET fcm_token = NULL, fcm_en = NULL
                      WHERE device_id IN ($marcas)"
                )->execute($muertos);
            }
            fcm_gastar(microtime(true) - $t0);
            return $enviados;
        } catch (Throwable $e) {
            error_log('fcm_despertar: ' . $e->getMessage());
            fcm_gastar(microtime(true) - $t0);
            return 0;
        }
    }

    /**
     * ¿Ya se gasto el presupuesto de este request?
     *
     * @param float|null $desde si se pasa, cuenta ADEMAS lo que va corriendo
     *        de la llamada actual (para poder cortar en medio de un bucle).
     */
    function fcm_sin_presupuesto(?float $desde = null): bool
    {
        $gastado = (float)($GLOBALS['__fcm_seg'] ?? 0.0);
        if ($desde !== null) { $gastado += microtime(true) - $desde; }
        if ($gastado < FCM_PRESUPUESTO_SEG) { return false; }

        /* Se avisa UNA sola vez por request. Sin esto, una campaña a 300
           jugadores escribiria 300 lineas identicas y el log dejaria de
           servir justo cuando hay algo que mirar. */
        if (empty($GLOBALS['__fcm_aviso'])) {
            $GLOBALS['__fcm_aviso'] = true;
            error_log(sprintf(
                'fcm: presupuesto agotado (%.1f s); el resto de este envio llega por el sondeo',
                $gastado
            ));
        }
        return true;
    }

    /** Anota lo que costo una llamada. */
    function fcm_gastar(float $seg): void
    {
        $GLOBALS['__fcm_seg'] = (float)($GLOBALS['__fcm_seg'] ?? 0.0) + max(0.0, $seg);
    }

    /** Devuelve el presupuesto al estado inicial. Lo usan los tests. */
    function fcm_presupuesto_reiniciar(): void
    {
        $GLOBALS['__fcm_seg']   = 0.0;
        $GLOBALS['__fcm_aviso'] = false;
    }

    /**
     * Guarda el token que mandó un celular.
     *
     * Devuelve false si la columna no existe (migración 77 sin correr). El APK
     * lee ese false y reintenta en el próximo arranque, así que instalar la
     * migración después de publicar la app se arregla solo.
     */
    function fcm_guardar_token(PDO $pdo, string $deviceId, string $token): bool
    {
        $deviceId = substr(trim($deviceId), 0, 64);
        $token    = substr(trim($token), 0, 255);
        if ($deviceId === '' || $token === '') { return false; }
        try {
            $st = $pdo->prepare(
                "UPDATE dispositivos
                    SET fcm_token = ?, fcm_en = NOW()
                  WHERE device_id = ?"
            );
            $st->execute([$token, $deviceId]);
            /* rowCount 0 puede ser que el aparato no esté registrado todavía
               (el alta la hace el widget) o que el token sea el mismo de antes.
               Los dos casos son normales y ninguno es un error: se contesta que
               sí para que la app no reintente en loop. */
            return true;
        } catch (Throwable $e) {
            error_log('fcm_guardar_token: ' . $e->getMessage());
            return false;
        }
    }
}
