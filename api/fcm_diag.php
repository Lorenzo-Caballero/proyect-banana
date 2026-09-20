<?php
/**
 * Diagnostico de Firebase. Abrilo en el NAVEGADOR (pasa el WAF/Cloudflare):
 *   https://ganamoscrm.online/gp-api/fcm_diag.php?clave=ver-fcm
 *
 * Y para mandarle un empujon de verdad a un jugador:
 *   https://ganamoscrm.online/gp-api/fcm_diag.php?clave=ver-fcm&usuario=holajuan123
 *
 * POR QUE HACE FALTA. Cuando un push no llega, del lado nuestro no se ve nada:
 * la notificacion queda encolada igual, el CRM dice "enviada", el jugador la
 * recibe igual por el sondeo (hasta 15 minutos despues) y nadie puede
 * distinguir "Firebase no esta configurado" de "el telefono no registro el
 * token" de "Google rechazo el mensaje". Los tres se ven identicos: un aviso
 * que tarda.
 *
 * Este archivo recorre la cadena entera y dice EN QUE ESLABON se corta.
 *
 * USA LA MISMA LIBRERIA QUE EL ENVIO REAL, no una copia. Una copia es
 * exactamente lo que hace que el diagnostico diga una cosa y el sistema haga
 * otra -- la trampa que ya nos mordio con las claves de IA.
 *
 * BORRALO del server cuando termines de diagnosticar.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/fcm_lib.php';

if (!isset($_GET['clave']) || $_GET['clave'] !== 'ver-fcm') { http_response_code(404); exit; }

header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors', '1');
error_reporting(E_ALL);

function linea(string $etiqueta, string $valor, string $nota = ''): void
{
    printf("%-22s %s%s\n", $etiqueta . ':', $valor, $nota !== '' ? '   <- ' . $nota : '');
}

echo "=== ENTORNO ===\n";
linea('PHP', PHP_VERSION);
linea('curl', function_exists('curl_init') ? 'si' : 'NO', function_exists('curl_init') ? '' : 'sin esto no se puede mandar nada');
linea('openssl_sign', function_exists('openssl_sign') ? 'si' : 'NO', function_exists('openssl_sign') ? '' : 'sin esto no se puede firmar el JWT');
linea('cliente (base)', (string)($GLOBALS['TENANT_DB'] ?? '?'));
linea('topico', fcm_topico(), 'a donde van los avisos masivos de ESTE cliente');

echo "\n=== 1. LA CLAVE DE CUENTA DE SERVICIO ===\n";
linea('ruta', FCM_CREDENCIALES);
linea('existe', file_exists(FCM_CREDENCIALES) ? 'si' : 'NO');
linea('la puede leer PHP', is_readable(FCM_CREDENCIALES) ? 'si' : 'NO',
    is_readable(FCM_CREDENCIALES) ? '' : 'revisa que sea chmod 400 y dueño www-data');

$cred = fcm_credenciales();
if ($cred === null) {
    echo "\nFIREBASE NO ESTA CONFIGURADO EN ESTE SERVER.\n";
    echo "No es un error fatal: todo sigue funcionando por el sondeo de 15 minutos,\n";
    echo "que es exactamente como se portaba el sistema antes de la version 1.7.\n";
    echo "Pero ningun aviso va a llegar al instante hasta que esto se resuelva.\n";
    exit;
}
linea('proyecto', (string)$cred['project_id']);
linea('cuenta', (string)$cred['client_email']);
linea('clave privada', strlen((string)$cred['private_key']) . ' chars');

echo "\n=== 2. GOOGLE NOS DA PERMISO? ===\n";
echo "(se firma un JWT con la clave y se lo canjea por un token de acceso)\n";
$t0 = microtime(true);
$acceso = fcm_access_token();
$ms = (int)round((microtime(true) - $t0) * 1000);
if ($acceso === null) {
    echo "\nGOOGLE RECHAZO LA CREDENCIAL (o no se pudo llegar). Tardo {$ms} ms.\n";
    echo "El motivo exacto quedo en el error_log del server. Las causas tipicas:\n";
    echo "  - la clave se corto al copiarla (private_key incompleta)\n";
    echo "  - se borro la cuenta de servicio desde la consola de Firebase\n";
    echo "  - el server no tiene salida a oauth2.googleapis.com\n";
    exit;
}
linea('token de acceso', 'OK (' . strlen($acceso) . ' chars, en ' . $ms . ' ms)');

echo "\n=== 3. QUE TELEFONOS PODEMOS DESPERTAR ===\n";
try {
    $r = $pdo->query(
        "SELECT COUNT(*) AS aparatos,
                SUM(fcm_token IS NOT NULL AND fcm_token <> '') AS con_token,
                SUM(plataforma = 'android') AS android,
                SUM(permitido = 1) AS con_permiso
           FROM dispositivos"
    )->fetch(PDO::FETCH_ASSOC);
    linea('aparatos', (string)(int)$r['aparatos']);
    linea('de esos, Android', (string)(int)$r['android']);
    linea('con permiso', (string)(int)$r['con_permiso']);
    linea('CON TOKEN FCM', (string)(int)$r['con_token'],
        ((int)$r['con_token'] === 0)
            ? 'NINGUNO. Ver abajo por que.'
            : 'estos se despiertan al instante');

    if ((int)$r['con_token'] === 0) {
        echo "\nNINGUN TELEFONO REGISTRO SU TOKEN TODAVIA. Eso es normal si:\n";
        echo "  - todavia nadie actualizo a la version 1.7 (las anteriores no lo mandan)\n";
        echo "  - o el que actualizo no volvio a ABRIR la app desde entonces\n";
        echo "\nEl token se manda al abrir la app, no al instalarla.\n";
    } else {
        echo "\nUltimos que registraron:\n";
        $st = $pdo->query(
            "SELECT usuario, plataforma, LEFT(fcm_token, 16) AS token, fcm_en
               FROM dispositivos
              WHERE fcm_token IS NOT NULL AND fcm_token <> ''
              ORDER BY fcm_en DESC LIMIT 10"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            printf("  %-22s %-8s %s...  %s\n",
                (string)($d['usuario'] ?? '(sin usuario)'), (string)$d['plataforma'],
                (string)$d['token'], (string)$d['fcm_en']);
        }
    }
} catch (Throwable $e) {
    echo "NO SE PUDO CONSULTAR: " . $e->getMessage() . "\n";
    echo "Si dice que no existe la columna fcm_token, falta correr la migracion 77.\n";
    exit;
}

echo "\n=== 4. EMPUJON DE VERDAD ===\n";
$usuario = trim((string)($_GET['usuario'] ?? ''));
if ($usuario === '') {
    echo "No se mando nada. Para probar de verdad, agregale a esta URL:\n";
    echo "   &usuario=EL_NOMBRE_DEL_JUGADOR\n";
    echo "\nEso le manda un empujon a sus telefonos y te dice cuantos salieron.\n";
    echo "NO le muestra ninguna notificacion al jugador: el empujon va vacio y la\n";
    echo "app solo va a mirar si hay avisos nuevos. Si no hay, no pasa nada.\n";
    exit;
}

$t0 = microtime(true);
$n  = fcm_despertar($pdo, $usuario);
$ms = (int)round((microtime(true) - $t0) * 1000);
linea('jugador', $usuario);
linea('empujones enviados', (string)$n, $n > 0 ? 'Google los acepto' : 'ver abajo');
linea('tardo', $ms . ' ms');

if ($n === 0) {
    echo "\nNO SALIO NINGUNO. Las causas, en orden de probabilidad:\n";
    echo "  1. ese jugador no tiene ningun aparato con token (mira la lista de arriba)\n";
    echo "  2. sus aparatos tienen permitido = 0 (rechazo las notificaciones)\n";
    echo "  3. Google rechazo el token por viejo -- en ese caso ya se borro solo\n";
    echo "     y el telefono va a mandar uno nuevo la proxima vez que abra la app\n";
} else {
    echo "\nEl empujon salio. Si el telefono NO reacciona en unos segundos:\n";
    echo "  - fijate que la app este instalada y que se haya abierto al menos una vez\n";
    echo "  - que no este 'forzada a detenerse' desde los ajustes de Android\n";
    echo "  - que el telefono tenga Google Play Services (algunos Huawei no)\n";
    echo "  En esos casos igual le llega por el sondeo, hasta 15 minutos despues.\n";
}
