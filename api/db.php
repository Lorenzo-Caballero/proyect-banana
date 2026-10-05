<?php
/**
 * Conexión a la base — TENANT-AWARE (una base por cliente).
 *
 * El dominio por el que entró el request decide en qué base se trabaja. El
 * aislamiento entre clientes lo garantiza MySQL: son bases distintas, así que
 * NINGUNA query de un cliente puede tocar los datos de otro, aunque nos
 * olvidáramos de filtrar. Ese es el punto de haber elegido base-por-cliente.
 *
 * El tenant se saca SIEMPRE del Host (+ slug de path para clientes sin
 * dominio propio, ver abajo), NUNCA de un parámetro que mande el cliente. Un
 * Host no registrado no conecta a nada.
 *
 * Clientes sin dominio propio (path_tenant=1 en `clientes`) entran por
 * https://ganamoscrm.online/<ruta-publica>/gp-api/algo.php. Nginx extrae esa
 * ruta del path y la manda como X-Tenant-Slug; la tabla clientes_rutas_path
 * la resuelve a un id interno estable y a su base. Las rutas antiguas quedan
 * como alias. Si no llega el header, la resolución por dominio sigue igual.
 *
 * Credenciales en config.local.php (no van al repo):
 *   DB_HOST, DB_USER, DB_PASS   -> usuario de la app (con grant en todas las bases)
 *   CONTROL_DB_NAME             -> base maestra (default: goldpaw_control)
 *
 * Estilo PHP viejo a propósito (sin strict_types ni tipos): este archivo carga
 * temprano y un fatal de sintaxis acá no deja mensaje. No le agregues tipos.
 */

require_once __DIR__ . '/config.php';

// 1) Qué dominio pidió. Sin puerto, en minúsculas.
$__host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST']
        : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');
$__host = strtolower(preg_replace('/:\d+$/', '', $__host));

// Cliente sin dominio propio: nginx lo manda como X-Tenant-Slug (ver arriba).
// "" si no vino el header (caso normal, dominio propio).
$__slug = isset($_SERVER['HTTP_X_TENANT_SLUG']) ? trim($_SERVER['HTTP_X_TENANT_SLUG']) : '';
$__routeSlug = $__slug;
$__clientSlug = '';
$__publicSlug = '';
$__altasPropias = 0;

$__dbHost  = cfg('DB_HOST', 'localhost');
$__dbUser  = cfg('DB_USER');
$__dbPass  = cfg('DB_PASS');
$__control = cfg('CONTROL_DB_NAME', 'goldpaw_control');

// 2) Resolver dominio (+ slug si es cliente por path) -> base del cliente.
try {
    $__ctl = new PDO(
        'mysql:host=' . $__dbHost . ';dbname=' . $__control . ';charset=utf8mb4',
        $__dbUser, $__dbPass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    if ($__slug !== '') {
        try {
            $__q = $__ctl->prepare(
                "SELECT c.db_nombre,c.slug,c.ruta_slug,c.altas_propias FROM clientes_rutas_path r
                   JOIN clientes c ON c.id=r.cliente_id
                  WHERE r.dominio=? AND r.ruta_slug=? AND c.path_tenant=1 AND c.estado='activo' LIMIT 1"
            );
            $__q->execute(array($__host, $__slug));
            $__tenant = $__q->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Compatibilidad de despliegue: antes de correr la migración 12,
            // conserva el ruteo anterior por slug interno.
            $__q = $__ctl->prepare(
                "SELECT db_nombre,slug,slug AS ruta_slug,altas_propias FROM clientes
                 WHERE dominio=? AND slug=? AND path_tenant=1 AND estado='activo' LIMIT 1"
            );
            $__q->execute(array($__host, $__slug));
            $__tenant = $__q->fetch(PDO::FETCH_ASSOC);
        }
        $__db = $__tenant['db_nombre'] ?? false;
        $__clientSlug = (string)($__tenant['slug'] ?? '');
        $__publicSlug = (string)($__tenant['ruta_slug'] ?? $__slug);
        $__altasPropias = (int)($__tenant['altas_propias'] ?? 0) === 1;
    } else {
        $__q = $__ctl->prepare(
            "SELECT db_nombre FROM clientes
             WHERE dominio = ? AND path_tenant = 0 AND estado = 'activo' LIMIT 1"
        );
        $__q->execute(array($__host));
        $__db = $__q->fetchColumn();
    }
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(array('ok' => false, 'error' => 'No se pudo resolver el cliente')));
}

if (!$__db) {
    http_response_code(404);
    die(json_encode(array('ok' => false, 'error' => 'Dominio no registrado: ' . $__host . ($__slug !== '' ? '/' . $__slug : ''))));
}

// 3) Conectar a la base de ESE cliente. De acá sale $pdo, igual que antes:
//    todos los endpoints siguen usando $pdo sin enterarse de nada.
try {
    $pdo = new PDO(
        'mysql:host=' . $__dbHost . ';dbname=' . $__db . ';charset=utf8mb4',
        $__dbUser, $__dbPass,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(array('ok' => false, 'error' => 'Base del cliente no disponible')));
}

// Para quien lo necesite: qué cliente resolvimos.
$GLOBALS['TENANT_DB']   = $__db;
$GLOBALS['TENANT_HOST'] = $__host;
$GLOBALS['TENANT_SLUG'] = $__clientSlug; // identidad estable; vacía en dominio propio
$GLOBALS['TENANT_ROUTE_SLUG'] = $__routeSlug; // ruta solicitada, incluso si es alias
$GLOBALS['TENANT_PUBLIC_SLUG'] = $__publicSlug; // ruta actual para generar enlaces nuevos
$GLOBALS['TENANT_ALTAS_PROPIAS'] = $__altasPropias;
