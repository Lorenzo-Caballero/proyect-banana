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
chequear('el colector pasa un destino explícito al guardar',
         str_contains($col, 'A.guardar_pago(payload, url=destino)'),
         'si vuelve a A.guardar_pago(payload), los pagos de los clientes caen en NUESTRA base');
/* TRES destinos y el orden importa: reenvío del cliente > casilla IMAP del
   cliente > la nuestra. */
chequear('el destino arranca en la casilla de la cuenta',
         str_contains($col, 'destino = (cuenta or {}).get("api_url", "")'));
chequear('y un reenvío lo pisa con la base de SU dueño',
         str_contains($col, 'destino = info["api_url"]'));
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


// ===========================================================================
echo "
=== 8. El camino SIN contraseñas: reenvío ===
";
/* Pedido del dueño (22/09/2026): "sin pedirle tantas contraseñas al cliente,
   así es más intuitivo y amigable todo". El cliente reenvía los avisos de su
   banco a una dirección nuestra con su slug adentro; nosotros leemos NUESTRA
   casilla de siempre y sabemos de quién es cada mail por la dirección. */
require_once __DIR__ . '/api/mail_reenvio.php';

$firma = mail_reenvio_firma('casinotest');
chequear('la dirección se FIRMA con la llave del servidor',
         $firma !== '' && strlen($firma) === 8,
         'sin firma, cualquiera que sepa el slug manda un aviso falso');
chequear('la firma cambia con el cliente',
         mail_reenvio_firma('casinotest') !== mail_reenvio_firma('otrocliente'));
chequear('y es estable (misma entrada, misma dirección)',
         mail_reenvio_firma('casinotest') === $firma);

$rev = file_get_contents(__DIR__ . '/api/mail_reenvio.php');
$cas2 = file_get_contents(__DIR__ . '/api/mail_casillas.php');
chequear('la firma se VERIFICA al leer, no solo el formato',
         str_contains($rev, 'hash_equals($esperada, $m[2])'),
         'sin verificarla, la firma es decorativa');
chequear('la dirección NO se guarda en la base (se deriva del slug)',
         !str_contains($cas2, 'mail_dir') && !str_contains($cas2, 'mail_alias'));

chequear('el colector resuelve el cliente por la dirección de llegada',
         str_contains($col, 'def slug_del_reenvio('));
chequear('mira varios headers (cada proveedor pone el suyo)',
         str_contains($col, '"Delivered-To", "X-Original-To"'));
chequear('el mail reenviado va a la base de SU dueño',
         str_contains($col, 'info = destinos_reenvio().get(slug) or {}'));

/* La validación cambia para un reenvío, y es deliberado: nuestros
   remitentes_ok son NUESTROS bancos, y el DKIM del banco se rompe al
   reenviar. Lo que sostiene el caso es la dirección firmada. */
chequear('un reenvío no se valida contra NUESTROS remitentes ni DKIM',
         str_contains($col, 'if c.get("reenvio_slug"):')
         && str_contains($col, 'return True, "ok (reenvio de cliente)"'));

$cobro2 = file_get_contents(__DIR__ . '/api/crm_cobro.php');
chequear('el modo reenvío NO exige servidor ni usuario',
         str_contains($cobro2, "modo === 'imap' && (\$host === '' || \$usr === '')"));
$crm2 = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('la pantalla ofrece el reenvío PRIMERO y por default',
         str_contains($crm2, 'value="reenvio" checked'));
chequear('con la dirección y un botón de copiar',
         str_contains($crm2, 'id="mailDirCopiar"'));
chequear('y avisa cuando llega el primer mail (no hay nada que "probar")',
         str_contains($crm2, 'Esperando el primer aviso'));

@unlink($llaveTmp);


// ===========================================================================
echo "
=== 9. Configurar la casilla sin saber qué es un servidor IMAP ===
";
/* Pedido del dueño (22/09/2026): "completá solo y automáticamente el servidor
   de imap y el puerto, abstraé al usuario lo máximo posible, y dejá los links
   directos para crear su app password". Pedirle «servidor IMAP» y «puerto» a
   quien no es técnico es pedirle dos datos que no sabe de dónde sacar, y
   termina copiando mal algo de un tutorial. */
require_once __DIR__ . '/api/mail_proveedores.php';

$g = mail_proveedor('nahuel.cobros@gmail.com');
chequear('Gmail se detecta por el dominio', ($g['id'] ?? '') === 'gmail');
chequear('con su servidor y puerto', ($g['host'] ?? '') === 'imap.gmail.com' && (int)($g['puerto'] ?? 0) === 993);
chequear('y el link DIRECTO a crear la contraseña (no a la ayuda general)',
         str_contains((string)($g['app_url'] ?? ''), 'myaccount.google.com/apppasswords'),
         'el «entrá a Seguridad y buscá…» es donde se pierde la mitad de la gente');
chequear('Outlook también', (mail_proveedor('x@hotmail.com')['id'] ?? '') === 'outlook');
chequear('Yahoo también', (mail_proveedor('x@yahoo.com.ar')['id'] ?? '') === 'yahoo');
chequear('un dominio desconocido no inventa proveedor',
         mail_proveedor('x@miempresa.com.ar') === null);

/* Para un dominio propio se prueba imap.<dominio>: puede fallar y por eso
   queda editable, pero un valor probable que el cliente corrige es mejor que
   un campo vacío que no sabe llenar. */
chequear('dominio propio: se adivina imap.<dominio>',
         mail_host_probable('pagos@miempresa.com.ar') === 'imap.miempresa.com.ar');
chequear('y para Gmail sale el host real, no el adivinado',
         mail_host_probable('x@gmail.com') === 'imap.gmail.com');
chequear('sin arroba no devuelve nada', mail_host_probable('noesunmail') === '');

$cobro3 = file_get_contents(__DIR__ . '/api/crm_cobro.php');
chequear('el server deduce el host si el navegador no lo mandó',
         str_contains($cobro3, "if (\$host === '' && \$usr !== '') { \$host = mail_host_probable(\$usr); }"),
         'si solo estuviera en el JS, un POST directo dejaría una casilla sin servidor');

$crm3 = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('la pantalla guía en pasos numerados', str_contains($crm3, 'class="mail-paso"'));
chequear('el servidor y el puerto quedan en «avanzado»',
         str_contains($crm3, 'class="mail-avanzado"'));
chequear('el link a la contraseña es un botón, no texto',
         str_contains($crm3, 'class="mail-btn-link"'));
chequear('y no se pisa lo que el cliente escribió a mano',
         str_contains($crm3, 'dataset.tocado'));

// ===========================================================================
echo "
=== 10. Recaudar: el detalle técnico solo si algo falló ===
";
/* "Eliminá los logs, eso era solo para probarlo, ahora ya funciona bien". Una
   corrida que salió entera no necesita explicarse. Pero no se borra del todo:
   el día que vuelva a fallar algo, el motivo tiene que estar donde se mira la
   corrida -- fue justamente lo que encontró el bug del redondeo. */
chequear('una corrida sin errores no muestra log',
         str_contains($crm3, 'if(!hayError) return "";'));
chequear('y con errores sí, con el texto que corresponde',
         str_contains($crm3, "' por qué fallaron</button>'"));


printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
