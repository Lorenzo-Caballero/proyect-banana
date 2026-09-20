<?php
/**
 * t_sesion_crm.php — La sesión del operador dura lo que dice que dura.
 *
 * DE DÓNDE SALE (Nahuel, 18/09/2026): *"la sesión de admin del CRM dura muy
 * poco, quiero que dure al menos 24 hs"*. Era la SEGUNDA vez que se pedía: el
 * 15/09 se subió a 12 horas y se le dio carpeta propia, y el operador siguió
 * teniendo que loguearse.
 *
 * LO QUE FALTABA NO ERA EL NÚMERO DE HORAS, y por eso el arreglo anterior no
 * alcanzó. Eran dos cosas que ningún valor de `lifetime` cubre:
 *
 *   1. LA CARPETA ERA /tmp. php-fpm en Debian/Ubuntu corre con
 *      PrivateTmp=true: ese /tmp es privado del servicio y se BORRA ENTERO
 *      cada vez que php-fpm se reinicia o recarga — o sea en cada deploy. La
 *      sesión no duraba 12 horas: duraba hasta el próximo reinicio de PHP.
 *
 *   2. LA COOKIE NO SE RENOVABA. `lifetime` se manda una sola vez, cuando la
 *      sesión nace, así que moría a las N horas EXACTAS del login aunque el
 *      operador estuviera trabajando sin parar.
 *
 * Los chequeos de abajo son en buena parte POSICIONALES (se mira el código) y
 * eso es a propósito: una sesión de 24 horas no se puede verificar de verdad
 * sin esperar 24 horas, y las partes que fallaron son justamente de las que no
 * dan error — simplemente te devuelven al login.
 *
 *     php t_sesion_crm.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* cfg() mockeable: crm_sesion_horas() la consulta si existe. */
$GLOBALS['T_CFG'] = [];
if (!function_exists('cfg')) {
    function cfg($clave, $default = '') {
        return $GLOBALS['T_CFG'][$clave] ?? $default;
    }
}
require_once __DIR__ . '/api/crm_auth.php';

// ===========================================================================
echo "=== 1. Cuánto dura, y qué se puede configurar ===\n";

chequear('el default son 24 horas (lo que pidió el dueño)', crm_sesion_horas() === 24);

$GLOBALS['T_CFG']['CRM_SESION_HORAS'] = '48';
chequear('config.local.php lo puede subir sin deploy', crm_sesion_horas() === 48);

/* Los clamps existen porque este valor lo edita una persona a mano en un
   archivo, y un cero de más (o de menos) no puede dejar sesiones eternas ni
   de un minuto. */
$GLOBALS['T_CFG']['CRM_SESION_HORAS'] = '0';
chequear('un 0 mal puesto no deja la sesión en nada', crm_sesion_horas() === 1);
$GLOBALS['T_CFG']['CRM_SESION_HORAS'] = '99999';
chequear('ni un número enorme la vuelve eterna', crm_sesion_horas() === 720);
$GLOBALS['T_CFG']['CRM_SESION_HORAS'] = 'ocho';
chequear('basura cae en el mínimo, no en 0', crm_sesion_horas() === 1);
unset($GLOBALS['T_CFG']['CRM_SESION_HORAS']);
chequear('sin config, vuelve a 24', crm_sesion_horas() === 24);

// ===========================================================================
echo "\n=== 2. Las cuatro patas (lo que el número de horas NO arregla) ===\n";

$src = file_get_contents(__DIR__ . '/api/crm_auth.php');
/* Se mira el CÓDIGO sin comentarios: este archivo explica en prosa cada una
   de estas piezas, así que buscar las frases sueltas haría pasar el test con
   las funciones borradas. */
$cod = '';
foreach (token_get_all($src) as $tk) {
    if (is_array($tk)) {
        if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) { continue; }
        $cod .= $tk[1];
    } else { $cod .= $tk; }
}

// Pata 1 y 2: carpeta propia, y que sea PERSISTENTE.
chequear('la carpeta de sesiones la decide este archivo (no el php.ini)',
         str_contains($cod, 'session_save_path('));
chequear('se prueban carpetas persistentes ANTES que /tmp',
         strpos($cod, '/var/lib/goldpaw') !== false
         && strpos($cod, '/var/lib/goldpaw') < strpos($cod, 'sys_get_temp_dir'),
         'si /tmp queda primero, un reload de php-fpm borra todas las sesiones');
chequear('/tmp sigue como último recurso (mejor frágil que ninguna sesión)',
         str_contains($cod, 'sys_get_temp_dir'));

// Pata 3: la cookie se renueva con el uso.
chequear('la cookie se REENVÍA en cada request (ventana deslizante)',
         str_contains($cod, 'setcookie(session_name(), session_id()'),
         'sin esto muere a las N horas exactas del login, trabajando o no');
chequear('y solo para quien ya tiene sesión de operador',
         str_contains($cod, "!empty(\$_SESSION['operador']) && !headers_sent()"));

// Pata 4: que el archivo no se enfríe (session.lazy_write).
chequear('se estampa una marca periódica para que el gc no borre una sesión viva',
         str_contains($cod, "\$_SESSION['tocada']"));

/* El gc tiene que ir con MÁS margen que la cookie: si borrara justo a las 24
   horas, una sesión en el límite moriría del lado del server con la cookie
   todavía viva — y el operador vuelve al login sin entender por qué. */
chequear('el gc del server vence DESPUÉS que la cookie, no antes',
         str_contains($cod, "session.gc_maxlifetime', (string)(\$vida + 12 * 3600)"));

// ===========================================================================
echo "\n=== 3. Lo que no se puede aflojar al alargar la sesión ===\n";

/* Una sesión más larga es una cookie robada que sirve más tiempo. Estas tres
   son las que la contienen, y ninguna puede caerse por error al tocar acá. */
chequear('la cookie sigue siendo secure (solo HTTPS)',  str_contains($cod, "'secure'   => true"));
chequear('y httponly (el JS no la puede leer)',          str_contains($cod, "'httponly' => true"));
chequear('y SameSite=Strict (no viaja desde otro sitio)', str_contains($cod, "'samesite' => 'Strict'"));
chequear('el login sigue regenerando el id (session fixation)',
         str_contains($cod, 'session_regenerate_id(true)'));
chequear('y el POST sigue exigiendo CSRF', str_contains($cod, 'HTTP_X_CSRF_TOKEN'));

/* Y la renovación va en el MISMO setcookie con todos los flags: reenviarla
   sin `secure`/`httponly` sería degradar la cookie en cada request, que es
   peor que no renovarla. */
$pos = strpos($cod, 'setcookie(session_name(), session_id()');
$trozo = $pos !== false ? substr($cod, $pos, 400) : '';
chequear('la cookie renovada conserva secure/httponly/samesite',
         str_contains($trozo, "'secure'") && str_contains($trozo, "'httponly'")
         && str_contains($trozo, "'samesite'"), $trozo);

// ===========================================================================
echo "\n=== 4. Un cliente no puede usar la sesión de otro ===\n";

/* El server es multi-tenant y todos los clientes compartían la carpeta de
   sesiones: un id válido en un cliente resolvía a un archivo con `operador`
   seteado también desde el dominio de OTRO. La cookie no viaja sola entre
   dominios, pero copiarla a mano alcanzaba. Con una subcarpeta por cliente el
   archivo directamente no existe del otro lado. */
chequear('cada cliente tiene su propia carpeta de sesiones',
         str_contains($cod, "TENANT_DB") && str_contains($cod, '$porTenant'));
chequear('y el nombre de la carpeta se sanea (viene de una config)',
         str_contains($cod, "preg_replace('/[^A-Za-z0-9_.-]/'"));

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
