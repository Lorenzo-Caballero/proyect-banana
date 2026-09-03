<?php
/**
 * crm_landings.php — Backend del módulo "Landings" del CRM.
 *
 * CRUD de las landings publicables (tabla `landings`, migración 52) y subida
 * de las imágenes que usan (logo / fondo). La página pública que las pinta es
 * landing/lp.html, que lee la config por landing_publica.php (sin auth); acá
 * todo va detrás de la sesión de operador, como el resto del CRM.
 *
 * GET  ?accion=listar                              -> { ok, landings, plantillas, base_url }
 * POST { accion:"guardar", id?, nombre, plantilla,
 *        bono_pct, config:{colores,textos,imagenes} } -> { ok, id, slug }
 *        (el slug lo genera el server al crear y no se edita nunca: vive en
 *         links publicados y en altas.origen de jugadores reales)
 * POST { accion:"toggle", id }                     -> { ok, activa }
 * POST multipart accion=imagen, archivo=<file>     -> { ok, url }
 *
 * Requiere sql/52_landings.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/landings_lib.php';

$operador = exigir_operador();

function lp_salir($data, int $code = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Deja pasar solo lo que este módulo escribe en `config`: colores en hex,
 * textos recortados, e imágenes que apunten a nuestra carpeta de uploads.
 * Es la contraparte de que lp.html confíe en esta config para pintar: nada
 * que venga del navegador del operador entra sin pasar por acá.
 */
function lp_config_sanear(array $cruda): array
{
    $limpia = ['colores' => [], 'textos' => [], 'imagenes' => [], 'tamanos' => []];

    foreach (['fondo', 'acento', 'destacado', 'texto'] as $k) {
        $v = trim((string)($cruda['colores'][$k] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            $limpia['colores'][$k] = strtolower($v);
        }
    }
    $maximos = ['marca' => 40, 'pill' => 60, 'titulo' => 60, 'bajo_cifra' => 60,
                'sub' => 160, 'cta' => 40, 'legal' => 120];
    foreach ($maximos as $k => $max) {
        $v = trim((string)($cruda['textos'][$k] ?? ''));
        if ($v !== '') {
            $limpia['textos'][$k] = mb_substr($v, 0, $max);
        }
    }
    // Escalas en % (sliders del editor). Como string, que es lo que el merge
    // de landings_config_completa() sabe pisar. Fuera de rango se descarta y
    // queda el default de la plantilla.
    foreach (['cifra', 'boton', 'aire'] as $k) {
        $v = (int)($cruda['tamanos'][$k] ?? 0);
        if ($v >= 50 && $v <= 200) {
            $limpia['tamanos'][$k] = (string)$v;
        }
    }
    foreach (['logo', 'fondo'] as $k) {
        $v = trim((string)($cruda['imagenes'][$k] ?? ''));
        // Solo rutas del propio server hacia uploads/landings: nunca una URL
        // externa ni un data: — esto termina en un <img src> público.
        if ($v !== '' && preg_match('#^/[a-z0-9/._-]*uploads/landings/[a-z0-9._-]+$#i', $v)) {
            $limpia['imagenes'][$k] = $v;
        }
    }
    return $limpia;
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================== POST ========================================
if ($metodo === 'POST') {

    // --- subida de imagen (multipart, no JSON) ------------------------------
    if ((string)($_POST['accion'] ?? '') === 'imagen') {
        $PERMITIDOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            lp_salir(['ok' => false, 'error' => 'No llegó el archivo'], 400);
        }
        $f = $_FILES['archivo'];
        if ($f['size'] > 4 * 1024 * 1024) {
            lp_salir(['ok' => false, 'error' => 'La imagen supera 4 MB'], 400);
        }
        // Tipo REAL, no la extensión del navegador. Mismo criterio que subir.php.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        if (!isset($PERMITIDOS[$mime])) {
            lp_salir(['ok' => false, 'error' => 'Solo se aceptan JPG, PNG o WEBP'], 400);
        }
        $dir = __DIR__ . '/uploads/landings';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $nombre = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $PERMITIDOS[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
            lp_salir(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }
        // '/api/uploads/...' SIEMPRE, igual que subir.php: en la réplica lo
        // sirve el location ^~ /api/uploads/ de nginx, que anda en el dominio
        // raíz Y en los clientes por-path. '/gp-api/uploads/...' no: el bloque
        // /<slug>/gp-api/ solo rutea .php, y la imagen caería al proxy de la
        // plataforma.
        lp_salir(['ok' => true, 'url' => '/api/uploads/landings/' . $nombre]);
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        if ($accion === 'guardar') {
            $id       = isset($body['id']) ? (int)$body['id'] : null;
            $nombre   = (string)($body['nombre'] ?? '');
            $plantilla = (string)($body['plantilla'] ?? 'oro');
            $bonoPct  = (int)($body['bono_pct'] ?? 0);
            $config   = lp_config_sanear(is_array($body['config'] ?? null) ? $body['config'] : []);

            if (trim($nombre) === '') {
                lp_salir(['ok' => false, 'error' => 'Ponele un nombre a la landing'], 400);
            }
            $r = landings_guardar($pdo, $id ?: null, $nombre, $plantilla, $bonoPct, $config);
            if (!$r) {
                lp_salir(['ok' => false, 'error' => 'No se pudo guardar (¿corrió la migración 52?)'], 500);
            }
            crm_bitacora($pdo, $operador, $id ? 'landing_editar' : 'landing_crear',
                         "id={$r['id']} slug={$r['slug']} bono={$bonoPct}%");
            lp_salir(['ok' => true, 'id' => $r['id'], 'slug' => $r['slug']]);
        }

        if ($accion === 'toggle') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                lp_salir(['ok' => false, 'error' => 'Falta el id'], 400);
            }
            $nuevo = landings_toggle($pdo, $id);
            if ($nuevo === null) {
                lp_salir(['ok' => false, 'error' => 'Esa landing no existe'], 404);
            }
            crm_bitacora($pdo, $operador, $nuevo ? 'landing_activar' : 'landing_pausar', "id=$id");
            lp_salir(['ok' => true, 'activa' => $nuevo]);
        }

        lp_salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_landings POST: ' . $e->getMessage());
        lp_salir(['ok' => false, 'error' => 'Error al guardar'], 500);
    }
}

// ============================== GET =========================================
if ($metodo === 'GET') {
    $accion = (string)($_GET['accion'] ?? '');

    try {
        if ($accion === 'listar') {
            $landings = [];
            foreach (landings_listar($pdo) as $l) {
                // La config viaja ya COMPLETA (defaults de la plantilla + lo
                // guardado): el editor del CRM siempre arranca con todos los
                // campos llenos, sin repetir el merge en JS.
                $l['config'] = landings_config_completa((string)$l['plantilla'], $l['config']);
                $l['bono_pct'] = (int)$l['bono_pct'];
                $l['activa']   = (int)$l['activa'];
                $landings[] = $l;
            }
            lp_salir([
                'ok'         => true,
                'landings'   => $landings,
                'plantillas' => landings_plantillas(),
            ]);
        }

        lp_salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_landings GET: ' . $e->getMessage());
        lp_salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

lp_salir(['ok' => false, 'error' => 'Método no permitido'], 405);
