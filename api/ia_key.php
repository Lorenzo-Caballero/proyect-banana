<?php
/**
 * ia_key.php — De dónde sale cada clave de IA, y cuál va en qué lugar.
 *
 * HAY DOS LUGARES DISTINTOS, y confundirlos deja el chat mudo con la clave
 * "cargada" (la trampa que documenta chatbot_diag.php):
 *
 *   - EL LUGAR DE ANTHROPIC (`ia_key_anthropic`): el proveedor REAL. Lo usan
 *     el chat (CHAT_MODEL=claude-... en chatbot.php) y la visión de
 *     comprobantes (vision_lib.php, Claude Haiku). Acá es donde se enchufa la
 *     clave POR CLIENTE: `clientes.ia_key` primero, `ANTHROPIC_API_KEY` del
 *     server si el cliente no tiene. Es lo que hace multi-tenant al chatbot:
 *     cada cliente gasta su propia cuota de Anthropic, y el que no carga
 *     clave usa la del dueño.
 *
 *   - EL LUGAR DE QWEN (`ia_key_qwen`): el RESPALDO del chat (si Claude
 *     rechaza o no contesta, ia_chat() sigue con Qwen) y el lector de
 *     comprobantes del CRM (comprobante_leer.php, qwen-vl). SIEMPRE las
 *     claves globales del server: QWEN_API_KEY, o COHERE_API_KEY como nombre
 *     viejo del mismo campo. La clave del cliente NO entra acá a propósito —
 *     es una clave de Anthropic, y mandársela a DashScope da 401: exactamente
 *     la trampa de la clave de Cohere, repetida con otro proveedor.
 *
 * HISTORIA, porque este archivo ya cambió de significado una vez: cuando se
 * escribió (15/09/2026 a la mañana), el chat era Qwen y `clientes.ia_key`
 * resolvía al lugar de Qwen. Ese mismo día se confirmó que producción corre
 * el chat sobre CLAUDE (CHAT_MODEL + ANTHROPIC_API_KEY en config.local.php),
 * así que la clave del cliente enchufada en Qwen no se usaba nunca. El campo
 * del panel pide ahora la clave de Anthropic, que es la que de verdad habla.
 *
 * DEGRADA HACIA ARRIBA, SIEMPRE. Si el plano de control está caído o la
 * migración 07 no corrió, se usa la global y el sistema sigue andando. Y si
 * la clave propia de un cliente muere (sin crédito, revocada), el chat cae
 * solo al respaldo Qwen del server: peor modelo, pero nunca mudo.
 */

if (!function_exists('ia_key_anthropic')) {

    /**
     * La clave para hablar con ANTHROPIC (el chat sobre Claude y la visión):
     * la del cliente si cargó una, si no la ANTHROPIC_API_KEY del server.
     * Cadena vacía si no hay ninguna.
     */
    function ia_key_anthropic(): string
    {
        return (string)(ia_key_resolver()['key']);
    }

    /**
     * De dónde salió la clave de Anthropic: 'cliente' | 'ANTHROPIC_API_KEY'
     * | ''. Lo usa el diagnóstico, que es donde importa distinguirlas.
     */
    function ia_key_origen(): string
    {
        return (string)(ia_key_resolver()['origen']);
    }

    /**
     * La clave para el lugar de QWEN (el respaldo del chat y el lector de
     * comprobantes del CRM): QWEN_API_KEY, o COHERE_API_KEY como nombre viejo
     * del mismo campo. NUNCA la del cliente — ver el docblock de arriba.
     */
    function ia_key_qwen(): string
    {
        $q = (string)cfg('QWEN_API_KEY');
        if ($q !== '') { return $q; }
        return (string)cfg('COHERE_API_KEY');
    }

    /** Resuelve una sola vez por proceso y cachea (incluido el "no hay"). */
    function ia_key_resolver(): array
    {
        static $r = null;
        if ($r !== null) { return $r; }

        $delCliente = ia_key_del_cliente();
        if ($delCliente !== '') {
            return $r = ['key' => $delCliente, 'origen' => 'cliente'];
        }
        $global = (string)cfg('ANTHROPIC_API_KEY');
        if ($global !== '') {
            return $r = ['key' => $global, 'origen' => 'ANTHROPIC_API_KEY'];
        }
        return $r = ['key' => '', 'origen' => ''];
    }

    /**
     * La clave propia de ESTE cliente, de goldpaw_control.clientes.
     *
     * El tenant ya está resuelto por dominio en db.php ($GLOBALS['TENANT_DB']),
     * el mismo camino que usan rl_cliente_actual() y hgcash_lib.
     *
     * Best-effort de punta a punta: si no hay control, si no está la columna
     * (migración 07 sin correr) o si la fila no existe, devuelve '' y el
     * llamador cae a la global. Nunca lanza.
     */
    function ia_key_del_cliente(): string
    {
        $db = (string)($GLOBALS['TENANT_DB'] ?? cfg('DB_NAME'));
        if ($db === '') { return ''; }

        try {
            /* Si recargas_lib ya está cargado se reusa SU conexión al control
               (cacheada por proceso, y respeta el override de los tests). Si
               no, se abre una acá: vision_lib/comprobante_leer no cargan esa
               lib y no vale la pena arrastrarla entera por una consulta. */
            if (function_exists('rl_control')) {
                $ctl = rl_control();
            } else {
                static $propia = null, $intentado = false;
                if (!$intentado) {
                    $intentado = true;
                    try {
                        $propia = new PDO(
                            'mysql:host=' . cfg('DB_HOST', 'localhost')
                                . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control')
                                . ';charset=utf8mb4',
                            cfg('DB_USER'), cfg('DB_PASS'),
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
                        );
                    } catch (Throwable $e) {
                        error_log('ia_key_del_cliente: sin control: ' . $e->getMessage());
                        $propia = null;
                    }
                }
                $ctl = $propia;
            }
            if (!$ctl instanceof PDO) { return ''; }

            $st = $ctl->prepare('SELECT ia_key FROM clientes WHERE db_nombre = ? LIMIT 1');
            $st->execute([$db]);
            return trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            // Sin la migración 07 la columna no existe: no es un error, es una
            // base todavía sin migrar. Se cae a la global, que es lo correcto.
            return '';
        }
    }
}
