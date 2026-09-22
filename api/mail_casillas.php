<?php
/**
 * mail_casillas.php — Las casillas de mail que el colector tiene que escuchar.
 *
 * Auth: header X-API-Key = BOT_API_KEY.
 *
 *   GET  ?accion=listar   -> { ok, casillas:[{cliente, slug, host, puerto,
 *                              usuario, clave, carpeta, remitentes[], api_url}] }
 *   POST ?accion=estado   body { slug, ok:bool, error? }   -> { ok }
 *
 * ============================================================================
 * ESTE ENDPOINT DEVUELVE CONTRASEÑAS EN CLARO, y hay que tenerlo presente al
 * tocarlo. Son las de las casillas de mail de los clientes: el colector las
 * necesita para abrir IMAP, y no hay forma de que las use sin verlas. Por eso
 * va detrás de la API key —igual que altas_cola.php, que devuelve las claves
 * de los jugadores— y por eso nunca se expone nada de esto al CRM: ahí solo
 * viaja "tiene_clave" (bool).
 *
 * Lo que acota el daño no está acá sino en el producto: es una contraseña de
 * APLICACIÓN que el cliente revoca cuando quiere, la casilla se abre en solo
 * lectura y filtrada por remitente, y en la base está cifrada (AES-256-GCM).
 *
 * ============================================================================
 * CADA CASILLA VIAJA CON SU `api_url`, y esa es la parte que no puede fallar.
 * El colector postea los pagos a esa URL, que es la del CLIENTE dueño de la
 * casilla. Si todas fueran a la nuestra —que es lo que pasa hoy, con API_URL
 * global en el .env— el mail del banco de un cliente acreditaría una recarga
 * en NUESTRA base: plata de otro sumada a nuestros jugadores, y el jugador que
 * transfirió de verdad esperando para siempre.
 *
 * Requiere panel/sql/09_mail_lectura.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/mail_reenvio.php';

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();

/* La base de control. Se define acá y no se importa porque `control_pdo()`
   no es una función global del proyecto: cada endpoint que la necesita la
   declara igual (crm_cobro.php, mp_webhook.php). */
if (!function_exists('control_pdo')) {
    function control_pdo(): PDO
    {
        return new PDO(
            'mysql:host=' . cfg('DB_HOST', 'localhost')
                . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
            cfg('DB_USER'), cfg('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}

/** La URL donde ESTE cliente recibe sus pagos. */
function mc_api_url(array $c): string
{
    $dom  = trim((string)($c['dominio'] ?? ''));
    $slug = trim((string)($c['slug'] ?? ''));
    if ($dom === '') { return ''; }
    // path_tenant=1: entra por ganamoscrm.online/<slug>/... (ver sql/01_control).
    $base = 'https://' . $dom;
    if ((int)($c['path_tenant'] ?? 0) === 1 && $slug !== '') {
        $base .= '/' . $slug;
    }
    return $base . '/gp-api/pagos.php';
}

try {
    $ctl = control_pdo();
} catch (Throwable $e) {
    error_log('mail_casillas: sin control_pdo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'sin base de control']);
    exit;
}

$accion = (string)($_GET['accion'] ?? 'listar');

// --------------------------------------------------------------- listar
if ($accion === 'listar') {
    try {
        $filas = $ctl->query(
            "SELECT slug, nombre, dominio, path_tenant,
                    mail_host, mail_puerto, mail_usuario, mail_clave,
                    mail_carpeta, mail_remitentes
               FROM clientes
              WHERE mail_activo = 1
                AND COALESCE(mail_modo, 'imap') = 'imap'
                AND mail_host IS NOT NULL AND mail_host <> ''
                AND mail_usuario IS NOT NULL AND mail_usuario <> ''
              ORDER BY slug"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Sin la migración 09 no hay casillas de clientes: el colector sigue
        // con la suya de config.json, como siempre.
        error_log('mail_casillas: ' . $e->getMessage());
        echo json_encode(['ok' => true, 'casillas' => [], 'sin_migracion' => true]);
        exit;
    }

    $out = [];
    foreach ($filas as $c) {
        /* UNA CASILLA QUE NO SE PUEDE DESCIFRAR SE SALTEA, no se devuelve a
           medias: sin clave el colector intentaría un login vacío contra la
           casilla del cliente y lo único que lograría es que Gmail le cuente
           intentos fallidos. Queda el log para saber cuál es. */
        $clave = cripto_descifrar($c['mail_clave'] ?? null);
        if ($clave === null || $clave === '') {
            error_log('mail_casillas: ' . $c['slug'] . ' sin clave legible (¿llave cambiada?)');
            continue;
        }
        $url = mc_api_url($c);
        if ($url === '') {
            error_log('mail_casillas: ' . $c['slug'] . ' sin dominio: no sé dónde acreditarle');
            continue;
        }
        $rem = array_values(array_filter(array_map(
            'trim', preg_split('/[\s,;]+/', (string)($c['mail_remitentes'] ?? '')) ?: []
        )));
        $out[] = [
            'cliente'    => (string)$c['nombre'],
            'slug'       => (string)$c['slug'],
            'host'       => (string)$c['mail_host'],
            'puerto'     => (int)($c['mail_puerto'] ?: 993),
            'usuario'    => (string)$c['mail_usuario'],
            'clave'      => $clave,
            'carpeta'    => (string)($c['mail_carpeta'] ?: 'INBOX'),
            'remitentes' => $rem,
            'api_url'    => $url,
        ];
    }
    echo json_encode(['ok' => true, 'casillas' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------------------- reenvios
/* LOS CLIENTES QUE ELIGIERON EL CAMINO SIN CONTRASEÑAS. El colector lee
   NUESTRA casilla y necesita saber, para cada mail que entra, de quién es y a
   qué base mandarlo. La dirección se deriva del slug (mail_reenvio.php): no se
   guarda, así que no puede quedar desincronizada.

   Acá NO viaja ninguna credencial: es un mapa de slug -> a dónde acreditar. */
if ($accion === 'reenvios') {
    try {
        $filas = $ctl->query(
            "SELECT slug, nombre, dominio, path_tenant
               FROM clientes
              WHERE mail_activo = 1 AND COALESCE(mail_modo, 'reenvio') = 'reenvio'
              ORDER BY slug"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('mail_casillas reenvios: ' . $e->getMessage());
        echo json_encode(['ok' => true, 'reenvios' => [], 'sin_migracion' => true]);
        exit;
    }
    $out = [];
    foreach ($filas as $c) {
        $url = mc_api_url($c);
        if ($url === '') {
            error_log('mail_casillas: ' . $c['slug'] . ' sin dominio: no sé dónde acreditarle');
            continue;
        }
        $out[] = [
            'slug'    => (string)$c['slug'],
            'cliente' => (string)$c['nombre'],
            'dir'     => mail_reenvio_dir((string)$c['slug']),
            'api_url' => $url,
        ];
    }
    echo json_encode(['ok' => true, 'reenvios' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------------------- estado
/* EL LATIDO POR CASILLA. Es lo que hace visible el fallo silencioso: si la
   contraseña se revoca o el banco cambia de remitente, los jugadores de ese
   cliente dejan de cobrar y NADA se rompe a la vista -- su CRM abre, su chat
   contesta, y las recargas quedan pendientes para siempre. Con esto, su
   pantalla «Cómo cobro» dice desde cuándo no lee y por qué. */
if ($accion === 'estado' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $slug = trim((string)($body['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'falta slug']);
        exit;
    }
    $bien = !empty($body['ok']);
    try {
        if ($bien) {
            /* Se sella la hora de la lectura BUENA y se limpia el error. "La
               última vez que FUNCIONÓ" es el dato, no "la última vez que se
               intentó": un proceso que reintenta cada minuto y falla siempre
               tendría un latido fresco y una casilla muerta. */
            $ctl->prepare(
                "UPDATE clientes SET mail_visto_en = NOW(), mail_error = NULL WHERE slug = ?"
            )->execute([$slug]);
        } else {
            $ctl->prepare(
                "UPDATE clientes SET mail_error = ? WHERE slug = ?"
            )->execute([mb_substr((string)($body['error'] ?? 'error'), 0, 300), $slug]);
        }
    } catch (Throwable $e) {
        error_log('mail_casillas estado: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'accion desconocida']);
