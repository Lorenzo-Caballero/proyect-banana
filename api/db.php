<?php
/**
 * Conexion PDO compartida.
 *
 * OJO: vos ya tenes un db.php propio (login.php lo requiere). Si el tuyo
 * funciona, quedate con el tuyo y borra este archivo. Lo incluyo solo para
 * que la carpeta api/ ande sola si la subis entera.
 *
 * Las credenciales salen de variables de entorno si existen, asi no quedan
 * hardcodeadas en un archivo que puede terminar en el repo.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$DB_HOST = cfg('DB_HOST', 'localhost');
$DB_NAME = cfg('DB_NAME');
$DB_USER = cfg('DB_USER');
$DB_PASS = cfg('DB_PASS');

// Distinguir "no configurado" de "credenciales incorrectas": si no, MySQL
// contesta "Access denied for user ''@'localhost'" y parece un problema de
// permisos cuando en realidad falta el archivo de config.
if ($DB_NAME === '' || $DB_USER === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('db.php: faltan DB_NAME/DB_USER. Crea api/config.local.php '
            . '(copia config.local.php.example) o usa tu propio db.php.');
    echo json_encode(['ok' => false, 'error' => 'Base de datos sin configurar']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // No filtramos el detalle del error al cliente: iria host/usuario/base.
    echo json_encode(['ok' => false, 'error' => 'Error de conexion']);
    error_log('DB: ' . $e->getMessage());
    exit;
}
