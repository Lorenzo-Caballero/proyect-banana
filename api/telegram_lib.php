<?php
/**
 * telegram_lib.php — Avisos al agente por Telegram.
 *
 * Existe por un motivo concreto: el CRM hay que estar mirandolo. Cuando el bot
 * deriva una conversacion, el jugador ya escucho "ya se lo paso a un agente" y
 * se queda esperando; si el agente no tiene la pestaña abierta, esa espera
 * puede durar horas. Telegram le suena en el celular.
 *
 * POR QUE TELEGRAM Y NO OTRA COSA: no hace falta registrar una app, ni
 * verificar un numero, ni pagar por mensaje. Se crea un bot con @BotFather en
 * un minuto y listo. Es la misma decision que la de no usar Firebase para el
 * push de los jugadores (ver CLAUDE.md): cero dependencias con cuenta ajena.
 *
 * CONFIGURACION (por cliente, en la config del CRM; si no esta ahi, cae al
 * config.local.php del server):
 *   tg_bot_token   el token que da @BotFather
 *   tg_chat_id     a quien avisarle. Puede ser una persona o un GRUPO (ahi el
 *                  id arranca con "-"), que es lo util cuando hay varios
 *                  agentes: se enteran todos.
 *
 * REGLA DE ORO: esto NUNCA puede romper el chat. Un token vencido, Telegram
 * caido o el server sin salida a internet tienen que terminar en un false y
 * una linea en el log, jamas en una excepcion que le corte la respuesta al
 * jugador. Por eso todo esta envuelto en try/catch y el timeout es corto.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_crm.php';

if (!function_exists('tg_credenciales')) {
    /**
     * Token y destino, del cliente o del server. Array vacio = sin configurar.
     */
    function tg_credenciales(?PDO $pdo = null): array
    {
        $token = '';
        $chat  = '';
        if ($pdo instanceof PDO) {
            try {
                $token = trim((string)(cfg_crm($pdo, 'tg_bot_token') ?? ''));
                $chat  = trim((string)(cfg_crm($pdo, 'tg_chat_id') ?? ''));
            } catch (Throwable $e) {
                // La tabla de config puede no existir todavia: no es un error.
            }
        }
        if ($token === '') { $token = trim((string)cfg('TELEGRAM_BOT_TOKEN')); }
        if ($chat === '')  { $chat  = trim((string)cfg('TELEGRAM_CHAT_ID')); }

        return ($token !== '' && $chat !== '') ? ['token' => $token, 'chat' => $chat] : [];
    }
}

if (!function_exists('tg_configurado')) {
    /** ¿Hay a quién avisarle? El CRM lo usa para mostrar el estado. */
    function tg_configurado(?PDO $pdo = null): bool
    {
        return tg_credenciales($pdo) !== [];
    }
}

if (!function_exists('tg_llamar')) {
    /**
     * Una llamada cruda a la API de Telegram. Devuelve el JSON decodificado, o
     * ['ok'=>false,'error'=>...] si ni siquiera se pudo llegar.
     *
     * Existe aparte de tg_avisar() porque el asistente de configuracion del CRM
     * necesita getMe y getUpdates, no solo sendMessage.
     */
    function tg_llamar(string $token, string $metodo, array $params = []): array
    {
        $token = trim($token);
        if ($token === '') { return ['ok' => false, 'error' => 'Falta el token.']; }
        try {
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metodo);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                return ['ok' => false, 'error' => 'No se pudo contactar a Telegram: ' . $err];
            }
            $j = json_decode((string)$resp, true);
            if (!is_array($j)) {
                return ['ok' => false, 'error' => 'Telegram respondio algo inesperado.'];
            }
            return $j;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('tg_detectar_chat')) {
    /**
     * Busca solo el chat_id, mirando quien le hablo al bot ultimamente.
     *
     * Es LA parte que traba a cualquiera que configura esto por primera vez: el
     * chat_id no se ve en ningun lado de la app de Telegram, y la unica forma
     * documentada es abrir a mano una URL con el token adentro y buscar un
     * numero en un JSON. Aca se resuelve solo: la persona le escribe algo al
     * bot (o lo agrega a un grupo) y el CRM lo encuentra.
     *
     * Se queda con el MAS RECIENTE. Si hay varios, el ultimo que escribio es
     * casi siempre el que esta configurandolo en ese momento.
     */
    function tg_detectar_chat(string $token): array
    {
        $r = tg_llamar($token, 'getUpdates', ['limit' => 50, 'timeout' => 0]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string)($r['description'] ?? $r['error'] ?? 'No se pudo consultar.')];
        }
        $encontrados = [];
        foreach ((array)($r['result'] ?? []) as $up) {
            // Un update puede venir como message, channel_post, my_chat_member
            // (cuando lo AGREGAN a un grupo, que es el caso mas util y el unico
            // que no genera un "message"), etc.
            foreach (['message', 'channel_post', 'edited_message', 'my_chat_member'] as $k) {
                $chat = $up[$k]['chat'] ?? null;
                if (!is_array($chat) || !isset($chat['id'])) { continue; }
                $id = (string)$chat['id'];
                $encontrados[$id] = [
                    'id'     => $id,
                    'tipo'   => (string)($chat['type'] ?? ''),
                    'nombre' => trim((string)($chat['title'] ?? ''))
                              ?: trim(((string)($chat['first_name'] ?? '')) . ' ' . ((string)($chat['last_name'] ?? '')))
                              ?: (string)($chat['username'] ?? ''),
                ];
            }
        }
        if (!$encontrados) {
            return ['ok' => false, 'codigo' => 'sin_mensajes', 'error' =>
                'Todavia nadie le escribio al bot. Abri Telegram, buscalo por su '
                . 'nombre de usuario y mandale /start (o agregalo al grupo). Despues '
                . 'volve a tocar este boton.'];
        }
        $lista = array_values($encontrados);
        return ['ok' => true, 'chats' => $lista, 'sugerido' => end($lista)];
    }
}

if (!function_exists('tg_avisar')) {
    /**
     * Manda un mensaje. Devuelve true si Telegram lo acepto.
     *
     * $texto va en HTML (parse_mode=HTML), asi que lo que venga de afuera --el
     * motivo que escribio el modelo, el nombre del jugador-- TIENE que pasar
     * por htmlspecialchars antes de llegar aca. Un "<" suelto hace que
     * Telegram rechace el mensaje entero con 400.
     *
     * Sin configurar devuelve false sin loguear nada: no tener Telegram es una
     * opcion valida, no una falla que haya que reportar en cada derivacion.
     */
    function tg_avisar(string $texto, ?PDO $pdo = null): bool
    {
        $cred = tg_credenciales($pdo);
        if (!$cred) { return false; }

        $texto = trim($texto);
        if ($texto === '') { return false; }
        // Telegram corta en 4096; se recorta antes para que no rechace el envio.
        if (mb_strlen($texto) > 3900) { $texto = mb_substr($texto, 0, 3900) . '…'; }

        try {
            $ch = curl_init('https://api.telegram.org/bot' . $cred['token'] . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'chat_id'                  => $cred['chat'],
                    'text'                     => $texto,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => 'true',
                ]),
                CURLOPT_RETURNTRANSFER => true,
                // Cortos a proposito: esto corre DENTRO del pedido del chat.
                // Un Telegram lento no puede hacer esperar al jugador.
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($code === 200) { return true; }
            // Se loguea el motivo: los dos errores tipicos (token mal copiado ->
            // 401, y "el bot nunca hablo con ese chat" -> 400) se distinguen
            // solo por la respuesta, y sin esto no hay como diagnosticarlos.
            error_log('telegram: HTTP ' . $code . ' ' . ($err !== '' ? $err : substr((string)$resp, 0, 200)));
            return false;
        } catch (Throwable $e) {
            error_log('telegram: ' . $e->getMessage());
            return false;
        }
    }
}
