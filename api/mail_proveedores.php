<?php
/**
 * mail_proveedores.php — De una dirección de mail a su servidor IMAP.
 *
 * POR QUÉ EXISTE (Nahuel, 22/09/2026): *"completá solo y automáticamente el
 * servidor de imap y el puerto, abstraé al usuario lo máximo posible"*.
 *
 * Pedirle a alguien que no es técnico "servidor IMAP" y "puerto" es pedirle
 * dos datos que no tiene idea de dónde sacar, para que termine copiando mal
 * algo de un tutorial. Y no hacen falta: el 95% de las casillas son Gmail,
 * Outlook o Yahoo, y el dominio de la dirección ya dice cuál es.
 *
 * Esta tabla vive del lado del SERVIDOR además del navegador a propósito: el
 * front la usa para completar los campos mientras el cliente escribe, y el
 * back para poder guardar aunque el host llegue vacío -- si solo estuviera en
 * el JS, un navegador viejo o un fetch directo dejarían una casilla sin
 * servidor, que es una casilla que no lee y nadie sabe por qué.
 */

declare(strict_types=1);

if (!function_exists('mail_proveedor')) {

    /**
     * Qué proveedor es esta dirección.
     *
     * Devuelve ['id','nombre','host','puerto','app_url','app_nombre','ayuda']
     * o null si el dominio no está en la lista (ahí el cliente completa a
     * mano, que es el caso raro).
     */
    function mail_proveedor(string $email): ?array
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) { return null; }
        $dom = substr($email, $at + 1);
        if ($dom === '') { return null; }

        $tabla = mail_proveedores();
        foreach ($tabla as $p) {
            if (in_array($dom, $p['dominios'], true)) { return $p; }
        }
        return null;
    }

    /**
     * El host IMAP para esta dirección, siempre con una respuesta razonable.
     *
     * Para un dominio propio (empresa) se prueba `imap.<dominio>`, que es la
     * convención en la abrumadora mayoría de los hostings. Puede fallar, y por
     * eso la pantalla deja editarlo -- pero un valor probable que el cliente
     * corrige es mucho mejor que un campo vacío que no sabe llenar.
     */
    function mail_host_probable(string $email): string
    {
        $p = mail_proveedor($email);
        if ($p) { return $p['host']; }
        $at = strrpos(strtolower(trim($email)), '@');
        if ($at === false) { return ''; }
        $dom = substr(strtolower(trim($email)), $at + 1);
        return $dom !== '' ? 'imap.' . $dom : '';
    }

    /** Los proveedores conocidos. El orden no importa: se busca por dominio. */
    function mail_proveedores(): array
    {
        return [
            [
                'id' => 'gmail',
                'nombre' => 'Gmail',
                'dominios' => ['gmail.com', 'googlemail.com'],
                'host' => 'imap.gmail.com',
                'puerto' => 993,
                /* Link DIRECTO a la pantalla donde se crea, no a la ayuda
                   general: "entrá a Seguridad y buscá contraseñas de
                   aplicaciones" es donde se pierde la mitad de la gente. */
                'app_url' => 'https://myaccount.google.com/apppasswords',
                'app_nombre' => 'contraseña de aplicación',
                'ayuda' => 'Te pide la verificación en dos pasos activada. '
                         . 'Si no la tenés, Google te la ofrece ahí mismo.',
            ],
            [
                'id' => 'outlook',
                'nombre' => 'Outlook / Hotmail',
                'dominios' => ['outlook.com', 'hotmail.com', 'hotmail.com.ar', 'live.com',
                               'live.com.ar', 'msn.com', 'outlook.com.ar', 'outlook.es'],
                'host' => 'outlook.office365.com',
                'puerto' => 993,
                'app_url' => 'https://account.live.com/proofs/AppPassword',
                'app_nombre' => 'contraseña de aplicación',
                'ayuda' => 'Necesita la verificación en dos pasos activada.',
            ],
            [
                'id' => 'yahoo',
                'nombre' => 'Yahoo',
                'dominios' => ['yahoo.com', 'yahoo.com.ar', 'yahoo.es', 'ymail.com', 'rocketmail.com'],
                'host' => 'imap.mail.yahoo.com',
                'puerto' => 993,
                'app_url' => 'https://login.yahoo.com/myaccount/security/app-password',
                'app_nombre' => 'contraseña de aplicación',
                'ayuda' => 'Yahoo exige contraseña de aplicación para IMAP: la de tu cuenta no entra.',
            ],
            [
                'id' => 'icloud',
                'nombre' => 'iCloud',
                'dominios' => ['icloud.com', 'me.com', 'mac.com'],
                'host' => 'imap.mail.me.com',
                'puerto' => 993,
                'app_url' => 'https://account.apple.com/account/manage',
                'app_nombre' => 'contraseña específica para apps',
                'ayuda' => 'En «Iniciar sesión y seguridad» → «Contraseñas específicas para apps».',
            ],
            [
                'id' => 'zoho',
                'nombre' => 'Zoho Mail',
                'dominios' => ['zoho.com', 'zohomail.com'],
                'host' => 'imap.zoho.com',
                'puerto' => 993,
                'app_url' => 'https://accounts.zoho.com/home#security/apppasswords',
                'app_nombre' => 'contraseña de aplicación',
                'ayuda' => '',
            ],
            [
                'id' => 'proton',
                'nombre' => 'Proton Mail',
                'dominios' => ['proton.me', 'protonmail.com', 'pm.me'],
                'host' => '127.0.0.1',
                'puerto' => 1143,
                'app_url' => 'https://proton.me/mail/bridge',
                'app_nombre' => 'Proton Mail Bridge',
                /* Proton no da IMAP directo: hace falta su Bridge corriendo en
                   una máquina. Se dice en vez de dejar que configure algo que
                   nunca va a conectar. */
                'ayuda' => 'Proton no permite IMAP directo: necesita su Bridge instalado. '
                         . 'Para esto conviene el reenvío, que no depende de eso.',
            ],
        ];
    }
}
