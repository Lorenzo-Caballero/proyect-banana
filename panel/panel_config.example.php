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

    // Carpeta de migraciones (api/sql/*.sql) que provisionar.php corre sobre
    // cada base como red de seguridad, además de la plantilla. Default:
    // /var/www/api/sql. Descomentar solo si tu layout es distinto.
    // 'SQL_DIR'   => '/var/www/api/sql',

    // ---------------------------------------------------------------------
    // CREAR LOS JUGADORES DE TODOS LOS CLIENTES CON NUESTRA CUENTA DE AGENTE
    // (decision del dueno, 03/10/2026). Vacias o ausentes = cada cliente usa
    // las suyas, que es el comportamiento de siempre.
    //
    // Afecta SOLO al bot de altas. El de sync sigue con las del cliente:
    // espeja SUS jugadores contra SU base.
    //
    // ANTES DE PRENDERLO, LO QUE CAMBIA DE VERDAD: el nombre de usuario es
    // unico en toda la plataforma, pero la PERTENENCIA es por agente -- el
    // jugador queda colgando de la cuenta que lo creo. Con esto activo, los
    // jugadores de un cliente quedan en NUESTRA estructura: no aparecen en su
    // panel, el no les puede cargar fichas (el panel contesta "User ID X is
    // not in user ID Y structure", el incidente del 29/09/2026) y las fichas
    // que se les carguen salen de NUESTRO saldo de agente.
    //
    // O sea: resuelve el alta y mueve el problema al deposito. Si un cliente
    // reclama que no puede cargarle a un jugador suyo, mirar esto primero.
    // 'ALTAS_PANEL_USER' => '',
    // 'ALTAS_PANEL_PASS' => '',
];

// El token de MercadoPago (cobro de suscripción de los clientes a la
// plataforma) NO va acá: se guarda en goldpaw_control.config_plataforma
// (tabla), se carga desde panel.html → botón "Cobro (MercadoPago)". Es un
// solo token para TODOS los clientes, por eso vive en la base y no en un
// archivo de config por-servidor -- panel.php, api/suscripcion.php y
// api/mp_webhook.php lo leen todos de ahí. Ver panel/sql/04_facturacion.sql.
