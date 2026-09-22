<?php
/**
 * mail_imap.php — Probar la casilla de mail de un cliente, desde el CRM.
 *
 * POR QUÉ EXISTE. La pantalla «Cómo cobro» le promete al cliente que "el
 * sistema lee tu casilla de mail y acredita solo", y para cumplirlo hay que
 * pedirle servidor, usuario y una contraseña de aplicación. Pedir cuatro datos
 * técnicos sin decir en el momento si funcionan garantiza que la mitad queden
 * mal configuradas -- y el modo de fallar de esto es SILENCIOSO: nadie se
 * entera hasta que un jugador reclama que transfirió y no le acreditaron.
 *
 * Lo que hace el botón «Probar»: conecta, abre la carpeta en SOLO LECTURA,
 * busca los últimos mails del remitente que el cliente eligió y cuenta cuántos
 * encontró. Eso contesta las dos preguntas de una: ¿entra la contraseña?, y
 * ¿el filtro por remitente está bien escrito? La segunda es la que más se
 * equivoca y la que no da ningún error -- una casilla que conecta pero filtra
 * por una dirección que no existe lee cero mails para siempre.
 *
 * NUNCA ESCRIBE EN LA CASILLA. `imap_open` con OP_READONLY: no marca leído, no
 * mueve, no borra. Es la casilla personal de otra persona.
 *
 * SIN LA EXTENSIÓN IMAP DE PHP no rompe nada: devuelve un error entendible que
 * dice qué instalar. El lector de verdad es colector_mail.py (Python, con
 * imapclient); esto es solo el probador del CRM.
 */

declare(strict_types=1);

if (!function_exists('mail_probar_imap')) {

    /* Cuántos mails se miran como mucho: es una prueba, no una lectura.
       Con define() y no const: const no se puede declarar dentro de un if
       (el mismo motivo que documenta notificaciones_lib.php). */
    defined('MAIL_PRUEBA_MAX') || define('MAIL_PRUEBA_MAX', 25);

    /**
     * Prueba una casilla y devuelve qué encontró.
     *
     * @return array{ok:bool, encontrados?:int, ultimo?:string, buzon?:int, error?:string, ayuda?:string}
     */
    function mail_probar_imap(string $host, int $puerto, string $usuario, string $clave,
                              string $carpeta = 'INBOX', string $remitentes = ''): array
    {
        if (!function_exists('imap_open')) {
            return ['ok' => false,
                'error' => 'Este servidor no tiene la extensión IMAP de PHP instalada.',
                'ayuda' => 'Del lado del soporte: apt install php-imap && systemctl reload php*-fpm'];
        }
        $host = trim($host);
        $usuario = trim($usuario);
        if ($host === '' || $usuario === '' || $clave === '') {
            return ['ok' => false, 'error' => 'Faltan datos de la casilla'];
        }
        $carpeta = trim($carpeta) !== '' ? trim($carpeta) : 'INBOX';

        /* `novalidate-cert` NO se usa: si el certificado del servidor de mail
           no valida, eso es exactamente lo que hay que ver antes de mandarle
           una contraseña. */
        $mbox = '{' . $host . ':' . $puerto . '/imap/ssl}' . $carpeta;

        /* imap_open escupe warnings de PHP además de fallar, y en un endpoint
           JSON un warning impreso rompe la respuesta entera. */
        $errores = [];
        set_error_handler(static function ($n, $str) use (&$errores) {
            $errores[] = $str;
            return true;
        });
        $con = @imap_open($mbox, $usuario, $clave, OP_READONLY, 1);
        restore_error_handler();

        if ($con === false) {
            $crudo = imap_last_error() ?: ($errores[0] ?? 'no se pudo conectar');
            imap_errors();   // vacía la cola, si no se arrastra al próximo intento
            return ['ok' => false, 'error' => mail_error_legible((string)$crudo, $host, $carpeta)];
        }

        try {
            $info = @imap_check($con);
            $enBuzon = $info && isset($info->Nmsgs) ? (int)$info->Nmsgs : 0;

            /* EL FILTRO POR REMITENTE es lo que hace aceptable pedir la
               casilla: no se lee todo, se leen los mails del banco. Varios
               remitentes se buscan por separado -- IMAP no tiene un OR simple
               y portable, y tres búsquedas cortas son más confiables que una
               consulta que algunos servidores interpretan distinto. */
            $lista = array_values(array_filter(array_map(
                'trim', preg_split('/[\s,;]+/', $remitentes) ?: []
            )));

            $ids = [];
            if (!$lista) {
                $r = @imap_search($con, 'ALL', SE_UID);
                if (is_array($r)) { $ids = $r; }
            } else {
                foreach ($lista as $dir) {
                    // Las comillas del criterio se escapan: una dirección con
                    // una comilla rompería la búsqueda entera.
                    $r = @imap_search($con, 'FROM "' . str_replace('"', '', $dir) . '"', SE_UID);
                    if (is_array($r)) { $ids = array_merge($ids, $r); }
                }
                $ids = array_values(array_unique($ids));
            }
            imap_errors();

            $ultimo = '';
            if ($ids) {
                rsort($ids);
                $ids = array_slice($ids, 0, MAIL_PRUEBA_MAX);
                $h = @imap_fetch_overview($con, (string)$ids[0], FT_UID);
                if (is_array($h) && isset($h[0]->date)) {
                    $ultimo = (string)$h[0]->date;
                }
            }
            imap_close($con);

            /* CONECTA PERO NO ENCUENTRA NADA es el caso que más importa
               distinguir: la contraseña está bien y el filtro está mal, y sin
               decirlo el cliente se queda pensando que ya está listo. */
            if (!$ids && $lista) {
                return ['ok' => true, 'encontrados' => 0, 'buzon' => $enBuzon,
                        'aviso' => 'La casilla abre bien, pero no hay ningún mail de '
                                 . implode(' ni de ', $lista) . '. '
                                 . 'Revisá la dirección del remitente: tiene que ser exactamente '
                                 . 'la que figura en los avisos de tu banco.'];
            }
            return ['ok' => true, 'encontrados' => count($ids), 'buzon' => $enBuzon,
                    'ultimo' => $ultimo];
        } catch (Throwable $e) {
            if (is_resource($con) || $con instanceof \IMAP\Connection) { @imap_close($con); }
            imap_errors();
            return ['ok' => false, 'error' => 'No se pudo leer la casilla: ' . $e->getMessage()];
        }
    }

    /**
     * El error de IMAP, en castellano y accionable.
     *
     * Los mensajes crudos ("AUTHENTICATIONFAILED", "Can't open mailbox") no le
     * dicen nada a quien está configurando esto, y el que más aparece --la
     * contraseña normal en vez de la de aplicación-- tiene una solución
     * concreta que conviene escribir donde se lee el error.
     */
    function mail_error_legible(string $crudo, string $host, string $carpeta): string
    {
        $c = mb_strtolower($crudo);
        if (str_contains($c, 'authenticationfailed') || str_contains($c, 'invalid credentials')
            || str_contains($c, 'login failed') || str_contains($c, 'authentication')) {
            return 'El servidor rechazó el usuario o la contraseña. '
                 . (str_contains(mb_strtolower($host), 'gmail')
                    ? 'Con Gmail tiene que ser una CONTRASEÑA DE APLICACIÓN (16 letras, sin espacios), '
                    . 'no la de tu cuenta: se genera en la Seguridad de tu cuenta de Google, '
                    . 'con la verificación en dos pasos activada.'
                    : 'Si tu proveedor pide una contraseña específica para IMAP, usá esa.');
        }
        if (str_contains($c, "can't open mailbox") || str_contains($c, 'no such mailbox')
            || str_contains($c, 'nonexistent')) {
            return 'La carpeta «' . $carpeta . '» no existe en esa casilla. '
                 . 'Probá con INBOX, o copiá el nombre exacto como lo muestra tu correo.';
        }
        if (str_contains($c, 'certificate') || str_contains($c, 'ssl')) {
            return 'Falló la conexión segura con ' . $host . '. Revisá el servidor y el puerto '
                 . '(Gmail: imap.gmail.com, puerto 993).';
        }
        if (str_contains($c, 'connection refused') || str_contains($c, 'timed out')
            || str_contains($c, 'timeout') || str_contains($c, 'resolve')) {
            return 'No se pudo llegar a ' . $host . ':' . ' revisá el servidor y el puerto. '
                 . '(Gmail: imap.gmail.com, puerto 993.)';
        }
        return 'El servidor de mail respondió: ' . mb_substr($crudo, 0, 200);
    }
}
