<?php
/**
 * ia_key.php — De dónde sale la clave del proveedor de IA del chatbot.
 *
 * ESTO EXISTÍA REPARTIDO Y A MEDIAS. `chatbot.php`, `comprobante_leer.php` y
 * `chatbot_diag.php` tenían cada uno estas dos líneas copiadas:
 *
 *     $key = cfg('QWEN_API_KEY');
 *     if ($key === '') { $key = cfg('COHERE_API_KEY'); }
 *
 * y el panel del dueño pedía una "Cohere API key" POR CLIENTE, la guardaba en
 * `goldpaw_control.clientes.cohere_key`... y nadie la leía nunca. El campo era
 * decorativo: cargaras lo que cargaras, todos los clientes hablaban con la
 * clave global del VPS.
 *
 * ORDEN DE BÚSQUEDA, y el porqué de cada escalón:
 *
 *   1. `clientes.ia_key` — la del CLIENTE (migración 07 del control). Es lo
 *      que hace multi-tenant de verdad al chatbot: cada cliente gasta su
 *      cuota, y el que no paga no le quema la del resto. Qwen da cuota gratis
 *      POR MODELO y POR CUENTA, así que compartir una clave entre clientes es
 *      compartir el techo.
 *   2. `QWEN_API_KEY` — la global del VPS. Es el default sano: un cliente sin
 *      clave propia igual tiene chatbot, pagado por el dueño.
 *   3. `COHERE_API_KEY` — el nombre viejo, que se acepta para no dejar el chat
 *      mudo entre que se despliega algo y alguien edita config.local.php.
 *
 * DEGRADA HACIA ARRIBA, SIEMPRE. Si el plano de control está caído o la
 * migración 07 no corrió, cae a la global y el chat sigue andando. Nunca al
 * revés: quedarse sin chatbot porque una base secundaria no responde sería
 * cambiar un problema chico por uno grande.
 *
 * OJO CON LA TRAMPA QUE DOCUMENTA chatbot_diag.php: pegar una clave de COHERE
 * en cualquiera de los tres escalones la manda igual a Qwen, que la rechaza
 * con 401 — y el diagnóstico dice "clave cargada". Por eso ia_key_origen()
 * existe y por eso el panel avisa qué clave espera.
 */

if (!function_exists('ia_key')) {

    /**
     * La clave que hay que usar para hablar con el proveedor de IA.
     * Cadena vacía si no hay ninguna configurada.
     */
    function ia_key(): string
    {
        return (string)(ia_key_resolver()['key']);
    }

    /**
     * De dónde salió: 'cliente' | 'QWEN_API_KEY' | 'COHERE_API_KEY' | ''.
     * Lo usa el diagnóstico, que es donde importa distinguirlas.
     */
    function ia_key_origen(): string
    {
        return (string)(ia_key_resolver()['origen']);
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
        $qwen = (string)cfg('QWEN_API_KEY');
        if ($qwen !== '') {
            return $r = ['key' => $qwen, 'origen' => 'QWEN_API_KEY'];
        }
        $co = (string)cfg('COHERE_API_KEY');
        if ($co !== '') {
            return $r = ['key' => $co, 'origen' => 'COHERE_API_KEY'];
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
               no, se abre una acá: comprobante_leer.php no carga esa lib y no
               vale la pena arrastrarla entera por una consulta. */
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
