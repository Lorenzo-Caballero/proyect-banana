<?php
/**
 * mail_reenvio.php — La dirección única a la que cada cliente reenvía sus avisos.
 *
 * EL CAMINO QUE NO PIDE CONTRASEÑAS (Nahuel, 22/09/2026: *"sin pedirle tantas
 * contraseñas al cliente, así es más intuitivo y amigable todo"*).
 *
 * En vez de entrar a la casilla del cliente, el cliente reenvía los avisos de
 * su banco a una dirección nuestra que lleva su slug adentro:
 *
 *     pagos@goldpaw.com   ->   pagos+casinotest@goldpaw.com
 *
 * Eso se llama subdirección (plus addressing) y lo soportan Gmail, Outlook,
 * Zoho y casi todos: todo lo que va a `algo+loquesea@dominio` cae en la casilla
 * `algo@dominio`, con la dirección completa preservada en el header
 * `Delivered-To`. O sea: UNA casilla nuestra, una dirección distinta por
 * cliente, sin crear cuentas ni alias en el proveedor.
 *
 * LA DIRECCIÓN NO SE GUARDA EN LA BASE, se deriva del slug (que ya es único).
 * Guardarla sería un segundo lugar donde puede quedar desincronizada de lo que
 * el colector busca -- y el día que no coincidan, los mails del cliente entran
 * a nuestra casilla y no se acreditan a nadie, sin ningún error a la vista.
 */

declare(strict_types=1);

// La llave para firmar la dirección (ver mail_reenvio_firma).
require_once __DIR__ . '/cripto.php';

if (!function_exists('mail_reenvio_base')) {

    /**
     * Nuestra casilla, la que recibe los reenvíos. Sale de config.local.php:
     *
     *     'MAIL_REENVIO_BASE' => 'pagos@goldpaw.com',
     *
     * Sin esto configurado no se puede ofrecer el reenvío, y la pantalla lo
     * dice en vez de mostrar una dirección inventada que no recibe nada.
     */
    function mail_reenvio_base(): string
    {
        $v = function_exists('cfg') ? trim((string)cfg('MAIL_REENVIO_BASE', '')) : '';
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /** ¿Se puede ofrecer el reenvío en este servidor? */
    function mail_reenvio_disponible(): bool
    {
        return mail_reenvio_base() !== '';
    }

    /**
     * La firma que hace que la dirección no se pueda adivinar.
     *
     * SIN ESTO, la dirección sería `pagos+casinotest@…` y cualquiera que
     * supiera el slug --que sale en la URL del cliente-- podría mandarle un
     * aviso de transferencia falso a nuestra casilla y hacer que se acrediten
     * fichas sin plata. Con ocho caracteres de HMAC al final hay que conocer
     * la llave del servidor para armar una dirección válida.
     *
     * Se DERIVA, no se guarda: una columna más sería otro lugar donde puede
     * quedar desincronizada de lo que el colector espera.
     */
    function mail_reenvio_firma(string $slug): string
    {
        $secreto = '';
        if (function_exists('cripto_llave')) { $secreto = (string)cripto_llave(); }
        if ($secreto === '' && function_exists('cfg')) { $secreto = (string)cfg('JWT_SECRET', ''); }
        if ($secreto === '') { return ''; }
        return substr(hash_hmac('sha256', 'reenvio:' . $slug, $secreto), 0, 8);
    }

    /**
     * La dirección de ESTE cliente, o '' si no se puede armar.
     *
     * Queda `pagos+<slug>-<firma>@dominio`: el slug adelante para que se
     * entienda de quién es mirando un log, y la firma atrás para que no se
     * pueda falsificar.
     *
     * El slug se sanea aunque ya venga limpio de la base: termina dentro de una
     * dirección de mail que se le muestra al cliente para que la copie, y un
     * caracter raro ahí produce una dirección que su Gmail rechaza sin decir
     * por qué.
     */
    function mail_reenvio_dir(string $slug): string
    {
        $base = mail_reenvio_base();
        $slug = strtolower(preg_replace('/[^A-Za-z0-9._]/', '', trim($slug)) ?? '');
        if ($base === '' || $slug === '') { return ''; }
        $firma = mail_reenvio_firma($slug);
        if ($firma === '') { return ''; }   // sin llave no se ofrece una dirección débil
        [$usr, $dom] = explode('@', $base, 2);
        return $usr . '+' . $slug . '-' . $firma . '@' . $dom;
    }

    /**
     * De una dirección con subdirección al slug: pagos+casinotest@x -> casinotest.
     *
     * Lo usa el colector para saber de qué cliente es cada mail que entra a
     * nuestra casilla. Devuelve '' si la dirección no es nuestra o no trae
     * subdirección -- un mail que cayó ahí por otro motivo no puede resolverse
     * a un cliente al azar.
     */
    function mail_reenvio_slug(string $dir): string
    {
        $base = mail_reenvio_base();
        if ($base === '') { return ''; }
        $dir = strtolower(trim($dir));
        [$usr, $dom] = explode('@', strtolower($base), 2);
        if (!preg_match('/^' . preg_quote($usr, '/') . '\+([a-z0-9._]+)-([a-f0-9]{8})@'
                        . preg_quote($dom, '/') . '$/', $dir, $m)) {
            return '';
        }
        /* LA FIRMA SE VERIFICA, no alcanza con que tenga el formato. Con
           hash_equals para que comparar no filtre por tiempo -- es barato y
           acá el atacante puede probar cuantas veces quiera. */
        $esperada = mail_reenvio_firma($m[1]);
        if ($esperada === '' || !hash_equals($esperada, $m[2])) { return ''; }
        return $m[1];
    }
}
