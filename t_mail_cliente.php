<?php
/**
 * t_mail_cliente.php — El cliente conecta SU casilla y sus pagos van a SU base.
 *
 * EL AGUJERO QUE CIERRA (Nahuel, 22/09/2026): *"si el usuario añade su
 * billetera, ¿qué seguridad hay de que lea los comprobantes?"*. Ninguna: la
 * pantalla «Cómo cobro» le prometía que "el sistema lee tu casilla de mail y
 * acredita solo", y el lector leía UNA casilla — la nuestra, de un archivo del
 * servidor. El cliente cargaba su billetera, sus jugadores transferían ahí, y
 * esas recargas no se acreditaban nunca. Del lado nuestro no se veía nada roto.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es donde esto puede salir MUY caro:
 *
 *  1. QUE CADA PAGO VAYA A LA BASE DE SU DUEÑO. Es lo único que no se puede
 *     equivocar: con una URL global, el aviso del banco de un cliente
 *     acreditaría una recarga en NUESTRA base — plata de otro sumada a
 *     nuestros jugadores, y el que transfirió de verdad esperando para siempre.
 *  2. QUE LA CLAVE NUNCA VUELVA AL CRM, ni cifrada.
 *  3. QUE SIN LLAVE DE CIFRADO SE RECHACE, en vez de guardar en claro la
 *     contraseña de la casilla de otra persona.
 *  4. QUE UNA CASILLA ILEGIBLE SE SALTEE, en vez de entregarse a medias.
 *
 *     php t_mail_cliente.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* La llave sale de un ARCHIVO (/etc/goldpaw/cripto.key). Para el test se
   apunta a uno temporal: la constante es `defined() || define()`, así que
   definirla antes del require gana. */
$llaveTmp = sys_get_temp_dir() . '/gp_test_cripto.key';
file_put_contents($llaveTmp, base64_encode(str_repeat('k', 32)));
define('CRIPTO_LLAVE_ARCHIVO', $llaveTmp);
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/cripto.php';

// ===========================================================================
echo "=== 1. La clave va y vuelve cifrada (y no se puede leer sin la llave) ===\n";
chequear('hay llave de cifrado disponible', cripto_disponible());
$clave = 'abcd efgh ijkl mnop';
$cif = cripto_cifrar($clave);
chequear('cifra', is_string($cif) && $cif !== '' && $cif !== $clave);
chequear('y descifra lo mismo', cripto_descifrar($cif) === $clave);
/* GCM y no CBC: un dato manipulado FALLA al descifrar en vez de devolver
   basura que después se usaría como contraseña contra la casilla del
   cliente. */
$roto = substr($cif, 0, -4) . 'AAAA';
chequear('un cifrado manipulado NO descifra (autenticación GCM)',
         cripto_descifrar($roto) === null);
chequear('basura tampoco', cripto_descifrar('no soy cifrado') === null);
chequear('null no explota', cripto_descifrar(null) === null);

// ===========================================================================
echo "\n=== 2. CADA PAGO A LA BASE DE SU DUEÑO ===\n";
/* Lo único que no se puede equivocar. Se replica mc_api_url() porque
   mail_casillas.php exige API key al incluirse. */
function url_de(array $c): string {
    $dom = trim((string)($c['dominio'] ?? ''));
    $slug = trim((string)($c['slug'] ?? ''));
    if ($dom === '') { return ''; }
    $base = 'https://' . $dom;
    if ((int)($c['path_tenant'] ?? 0) === 1 && $slug !== '') { $base .= '/' . $slug; }
    return $base . '/gp-api/pagos.php';
}
chequear('cliente con dominio propio: va a SU dominio',
         url_de(['dominio' => 'micasino.com', 'slug' => 'mica', 'path_tenant' => 0])
         === 'https://micasino.com/gp-api/pagos.php');
chequear('cliente por path: va a SU carpeta, no a la raíz',
         url_de(['dominio' => 'ganamoscrm.online', 'slug' => 'casinotest', 'path_tenant' => 1])
         === 'https://ganamoscrm.online/casinotest/gp-api/pagos.php',
         url_de(['dominio' => 'ganamoscrm.online', 'slug' => 'casinotest', 'path_tenant' => 1]));
chequear('sin dominio devuelve vacío (y el endpoint la saltea)',
         url_de(['dominio' => '', 'slug' => 'x']) === '');

/* Y el colector tiene que USAR esa URL. Es el punto exacto donde, si alguien
   "simplifica" volviendo a la global, todos los pagos caen en nuestra base
   sin que nada falle a la vista. */
$col = file_get_contents(__DIR__ . '/colector/colector_mail.py');
$api = file_get_contents(__DIR__ . '/colector/api_client.py');
chequear('el colector pasa la url de la casilla al guardar',
         str_contains($col, 'A.guardar_pago(payload, url=(cuenta or {}).get("api_url", ""))'),
         'si vuelve a A.guardar_pago(payload), los pagos de los clientes caen en NUESTRA base');
chequear('y api_client la respeta sobre la global',
         str_contains($api, 'urllib.request.Request((url or API_URL)'));
chequear('las dos llamadas a guardar() pasan la cuenta',
         substr_count($col, 'guardar(con, c, cuenta)') === 2,
         substr_count($col, 'guardar(con, c, cuenta)') . ' de 2');

// ===========================================================================
echo "\n=== 3. La clave NUNCA vuelve al CRM ===\n";
$cobro = file_get_contents(__DIR__ . '/api/crm_cobro.php');
chequear('el estado manda «tiene_clave», no la clave',
         str_contains($cobro, "'tiene_clave'=> trim((string)(\$cliente['mail_clave'] ?? '')) !== ''"));
chequear('no se devuelve mail_clave en ningún lado del CRM',
         !preg_match("/'clave'\s*=>\s*\\\$cliente\['mail_clave'\]/", $cobro));
/* Vacío = "no la cambies", nunca "borrala": el mismo criterio que el token de
   HG Cash y el de Meta. Sin esto, guardar cualquier otro campo de la pantalla
   borraría la contraseña y la casilla dejaría de leer en silencio. */
chequear('un campo vacío no pisa la clave guardada',
         str_contains($cobro, "if (\$clave !== '') {"));

// ===========================================================================
echo "\n=== 4. Sin llave de cifrado se RECHAZA, no se guarda en claro ===\n";
chequear('crm_cobro se niega a guardar sin llave',
         str_contains($cobro, 'if (!cripto_disponible()) {')
         && str_contains($cobro, 'no se puede guardar la contraseña'),
         'guardar en claro la contraseña de la casilla de otra persona no es una opción');

$cas = file_get_contents(__DIR__ . '/api/mail_casillas.php');
chequear('una casilla que no se puede descifrar se SALTEA',
         str_contains($cas, 'if ($clave === null || $clave === \'\') {')
         && str_contains($cas, 'continue;'),
         'entregarla sin clave haría que el colector intente logins vacíos contra su Gmail');
chequear('y una sin dominio también (no sabríamos dónde acreditarle)',
         str_contains($cas, 'no sé dónde acreditarle'));

// ===========================================================================
echo "\n=== 5. El fallo silencioso se hace visible ===\n";
/* Si el cliente revoca la contraseña o el banco cambia de remitente, sus
   jugadores dejan de cobrar y NADA se rompe a la vista: su CRM abre, su chat
   contesta, y las recargas quedan pendientes para siempre. */
chequear('el colector reporta cuando la casilla FUNCIONÓ',
         str_contains($col, 'reportar_casilla(cuenta, True)'));
chequear('y cuando falló, con el motivo',
         str_contains($col, 'reportar_casilla(cuenta, False, str(e))'));
chequear('el endpoint sella la hora de la lectura BUENA, no la del intento',
         str_contains($cas, 'SET mail_visto_en = NOW(), mail_error = NULL'));
chequear('el CRM muestra desde cuándo no lee', str_contains($cobro, "'visto_en'"));
chequear('y el último error', str_contains($cobro, "'error'      => (string)(\$cliente['mail_error'] ?? '')"));

// ===========================================================================
echo "\n=== 6. Nunca escribe en la casilla del cliente ===\n";
$imap = file_get_contents(__DIR__ . '/api/mail_imap.php');
chequear('el probador abre en SOLO LECTURA', str_contains($imap, 'OP_READONLY'));
chequear('el lector usa BODY.PEEK (no marca leído)',
         str_contains($col, 'BODY.PEEK') || str_contains($col, 'PEEK'));

/* El caso que NO da error y es el que más se equivoca: conecta bien pero el
   filtro por remitente no encuentra nada. Sin decirlo, el cliente cree que ya
   está listo y la casilla lee cero mails para siempre. */
chequear('«conecta pero no encuentra nada» se avisa aparte',
         str_contains($imap, 'La casilla abre bien, pero no hay ningún mail de'));

// ===========================================================================
echo "\n=== 7. Si el panel no responde, lo nuestro sigue leyendo ===\n";
/* Un problema de red no puede dejar de acreditarle a nadie: las casillas de
   clientes se SUMAN a la de config.json, no la reemplazan. */
chequear('las del panel se suman a las locales',
         str_contains($col, 'todas = list(cfg["cuentas"]) + casillas_del_panel()'));
chequear('y un fallo al pedirlas devuelve lista vacía, no rompe',
         str_contains($col, 'return []'));

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
