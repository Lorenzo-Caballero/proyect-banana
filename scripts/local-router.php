<?php
/**
 * Router del servidor embebido de PHP para desarrollo local.
 *
 * En producción nginx sirve landing/ en la raíz y la API bajo /api (o
 * /gp-api). El servidor de `php -S` solo puede apuntar a UNA carpeta, así
 * que este router hace ese ruteo a mano:
 *
 *    /            -> landing/
 *    /api/x.php   -> api/x.php
 *    /gp-api/x.php-> api/x.php   (por si probás con esa ruta)
 *
 * No se usa en producción.
 */

$raiz = dirname(__DIR__);
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// La API: /api/... y /gp-api/... salen los dos de api/
if (preg_match('#^/(?:gp-)?api/(.+)$#', $ruta, $m)) {
    $archivo = $raiz . '/api/' . $m[1];
    if (is_file($archivo)) {
        if (substr($archivo, -4) === '.php') {
            chdir(dirname($archivo));
            require $archivo;
            return true;
        }
        return false;   // uploads y demás: que los sirva el server
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'no existe: ' . $m[1]]);
    return true;
}

// El panel del dueño
if (preg_match('#^/panel/(.+)$#', $ruta, $m)) {
    $archivo = $raiz . '/panel/' . $m[1];
    if (is_file($archivo)) {
        if (substr($archivo, -4) === '.php') { chdir(dirname($archivo)); require $archivo; return true; }
        return false;
    }
}

// Todo lo demás sale de landing/. Se sirve a mano y no con `return false`
// porque el docroot del server es la raíz del repo, no landing/: si lo
// delegáramos, PHP buscaría el archivo en el lugar equivocado.
if ($ruta === '/') { $ruta = '/crm.html'; }
$archivo = $raiz . '/landing' . $ruta;
if (is_file($archivo)) {
    $tipos = [
        'html' => 'text/html; charset=utf-8', 'js'  => 'text/javascript; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',  'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico'  => 'image/x-icon', 'woff2' => 'font/woff2', 'apk' => 'application/vnd.android.package-archive',
    ];
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    if (isset($tipos[$ext])) { header('Content-Type: ' . $tipos[$ext]); }
    header('Cache-Control: no-store');   // en local siempre la última versión
    readfile($archivo);
    return true;
}

http_response_code(404);
echo 'No encontrado: ' . htmlspecialchars($ruta);
return true;
