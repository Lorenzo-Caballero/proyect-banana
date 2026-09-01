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
