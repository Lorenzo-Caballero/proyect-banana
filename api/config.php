<?php
/**
 * Resuelve la configuracion desde varias fuentes, porque en hosting compartido
 * (Hostinger, entre otros) las variables de entorno no siempre llegan a PHP.
 *
 * Orden de busqueda:
 *   1. getenv()                        -> VPS, Docker, PHP-FPM con env
 *   2. $_SERVER                        -> SetEnv en .htaccess con mod_php
 *   3. config.local.php                -> archivo suelto, el que casi seguro
 *                                         vas a usar en Hostinger
 *
 * config.local.php NO se sube al repo, y ya no hay un .example que copiar (se
 * saco: un archivo de ejemplo con nombres de claves reales al lado del que
 * lleva los valores es una invitacion a subir el equivocado). Las claves que
 * espera son estas, y arranca sin ninguna:
 *
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS     la base del cliente
 *   BOT_API_KEY                            identica al API_KEY del .env del bot
 *   ADMIN_PASS                             entrada a admin_usuarios.php
 *   JWT_SECRET                             firma del login propio (auth.php)
 *   COHERE_API_KEY                         el chatbot (hoy lee tambien la de Qwen)
 *   ANTHROPIC_API_KEY                      opcional: leer comprobantes con vision
 *   FINANZAS_COSTO_POR_FICHA               opcionales, umbrales del panel de
 *   FINANZAS_UMBRAL_RETIRO_GRANDE          finanzas; sin ellas se usan los
 *   FINANZAS_UMBRAL_RETIRO_MUY_GRANDE      defaults del codigo
 *   FINANZAS_UMBRAL_ALERTA_JUGADOR_GANADOR
 *
 * OJO: este archivo se escribe a proposito en PHP viejo, sin declare(strict_types),
 * sin tipos en los parametros y sin ": void". Es el primer archivo que carga todo
 * lo demas, asi que si la version de PHP del hosting no soporta alguna sintaxis
 * moderna, el server tira un fatal sin mensaje y no hay forma de diagnosticarlo.
 * No le agregues tipos.
 */

if (!function_exists('cfg')) {
    /**
     * @param string $clave
     * @param string $porDefecto
     * @return string
     */
    function cfg($clave, $porDefecto = '')
    {
        static $local = null;

        $v = getenv($clave);
        if (is_string($v) && $v !== '') {
            return $v;
        }

        if (!empty($_SERVER[$clave])) {
            return (string)$_SERVER[$clave];
        }

        if ($local === null) {
            $archivo = __DIR__ . '/config.local.php';
            $local = array();
            if (is_file($archivo)) {
                $cargado = require $archivo;
                if (is_array($cargado)) {
                    $local = $cargado;
                }
            }
        }

        if (isset($local[$clave]) && $local[$clave] !== '') {
            return (string)$local[$clave];
        }

        return $porDefecto;
    }
}

if (!function_exists('exigir_api_key')) {
    /**
     * Corta la ejecucion si la clave no esta configurada o si la que mandaron
     * no coincide. No hay valor por defecto a proposito: uno escrito en el
     * codigo seria publico, porque el codigo esta en el server.
     */
    function exigir_api_key()
    {
        $esperada = cfg('BOT_API_KEY');

        if ($esperada === '' || strlen($esperada) < 16) {
            http_response_code(500);
            error_log('API: falta BOT_API_KEY (minimo 16 caracteres). '
                    . 'Configurala por entorno o en api/config.local.php');
            echo json_encode(array('ok' => false, 'error' => 'API sin configurar'));
            exit;
        }

        $enviada = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        if (!hash_equals($esperada, $enviada)) {
            http_response_code(401);
            echo json_encode(array('ok' => false, 'error' => 'No autorizado'));
            exit;
        }
    }
}
