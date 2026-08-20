<?php
/**
 * Copiar a panel_config.php (gitignored) y completar. Va junto a panel.php.
 *
 * PANEL_SECRET firma los tokens de sesión del panel: tiene que ser largo y
 * random. Generá uno con:  php -r "echo bin2hex(random_bytes(32));"
 */
return [
    'DB_HOST'      => 'localhost',
    'DB_NAME'      => 'goldpaw_control',
    'DB_USER'      => 'u722310012_cuentas_cuenta',
    'DB_PASS'      => 'PONER-LA-CLAVE',
    'PANEL_SECRET' => 'CAMBIAR-por-un-hex-largo-y-random',

    // Cloudflare: para que el worker cree el subdominio de cada cliente solo.
    // Token = "Edit zone DNS" para la zona; Zone ID = Overview de la zona.
    // Si CF_API_TOKEN queda vacío, el worker igual crea la base, solo saltea el DNS.
    'CF_API_TOKEN' => '',
    'CF_ZONE_ID'   => '',
    'CF_ZONE_NAME' => 'ganamoscrm.online',  // los subdominios cuelgan de acá
    'VPS_IP'       => '168.231.98.136',      // a dónde apunta el A record
];
