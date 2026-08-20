<?php
/**
 * POST /login.php
 * Body JSON: { "usuario": "...", "password": "..." }
 *
 * - Si el usuario NO existe  -> lo registra (usuario + contraseña hasheada)
 *   y genera automáticamente nombre, apellido y correo.
 * - Si el usuario YA existe  -> valida la contraseña y devuelve sus datos.
 *
 * ---------------------------------------------------------------------------
 * CAMBIOS respecto de tu version (marcados con  [BOT]  abajo):
 *
 * 1. Al registrar se guarda ADEMAS la contraseña en claro en `panel_password`.
 *    El bot la necesita para tipearla en el panel de agentes, y de
 *    `contrasena` no se puede sacar porque password_hash() no se revierte.
 *    `cola_panel.php` la borra apenas el alta sale bien.
 *
 * 2. Cuando un usuario que YA existe entra bien y todavia tiene creado = 0,
 *    se le vuelve a guardar la clave en claro y se lo manda de nuevo a la
 *    cola. Asi los jugadores anteriores a la migracion se van dando de alta
 *    solos a medida que van entrando, sin que tengas que hacer nada.
 * ---------------------------------------------------------------------------
 */

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido. Usa POST.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

$usuario  = trim($data['usuario'] ?? '');
$password = (string)($data['password'] ?? '');

/* ---------- Validación mínima (solo usuario y contraseña) ---------- */
$errores = [];
if ($usuario === '')                       $errores['usuario']  = 'Escribe tu usuario.';
elseif (mb_strlen($usuario) < 3)           $errores['usuario']  = 'Mínimo 3 caracteres.';
elseif (!preg_match('/^[\w.\-]{3,50}$/u', $usuario))
                                           $errores['usuario']  = 'Solo letras, números, punto, guion y guion bajo.';
if ($password === '')                      $errores['password'] = 'Escribe tu contraseña.';
elseif (strlen($password) < 6)             $errores['password'] = 'Mínimo 6 caracteres.';

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => $errores]);
    exit;
}

/* ---------- Generadores automáticos (temática bélica) ---------- */
function generarNombre(): string {
    $n = ['Dante','Kira','Rurik','Vera','Cassian','Nika','Ivar','Sable','Rhea','Kane',
          'Milo','Nadia','Tobias','Yuri','Alaric','Selva','Bruno','Astrid','Emir','Lena'];
    return $n[array_rand($n)];
}
function generarApellido(): string {
    $a = ['Vargas','Kovacs','Ferrer','Duarte','Novak','Salgado','Reyes','Ibarra','Volkov',
          'Marchetti','Bastos','Cortés','Halden','Ocampo','Rivas','Strand','Zúñiga','Peralta'];
    return $a[array_rand($a)];
}
function generarCorreoUnico(PDO $pdo, string $usuario): string {
    $base = preg_replace('/[^a-z0-9]/', '', strtolower($usuario));
    if ($base === '') { $base = 'recluta'; }
    $base = substr($base, 0, 30);
    $chk = $pdo->prepare('SELECT 1 FROM jugadores WHERE correo = ? LIMIT 1');
    do {
        $correo = $base . random_int(100, 99999) . '@goldpaw.game';
        $chk->execute([$correo]);
    } while ($chk->fetchColumn());
    return $correo;
}

/* ---------- ¿Existe el usuario? ---------- */
// [BOT] agregamos `creado` al SELECT para saber si ya esta en el panel
$stmt = $pdo->prepare('SELECT id, nombre_usuario, contrasena, correo, nombre, apellido, creado
                       FROM jugadores WHERE nombre_usuario = ? LIMIT 1');
$stmt->execute([$usuario]);
$jugador = $stmt->fetch();

if ($jugador) {
    // Ya existe -> login normal
    if (!password_verify($password, $jugador['contrasena'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos.']);
        exit;
    }
    $creado = false;

    // [BOT] Si todavia no esta en el panel, aprovechamos que ACA tenemos la
    // clave en claro (es el unico momento en que existe) y lo reencolamos.
    if ((int)$jugador['creado'] === 0) {
        $pdo->prepare(
            "UPDATE jugadores
                SET panel_password  = ?,
                    panel_estado    = 'pendiente',
                    panel_intentos  = 0,
                    panel_mensaje   = NULL,
                    panel_tomado_en = NULL
              WHERE id = ? AND creado = 0 AND panel_estado <> 'procesando'"
        )->execute([$password, $jugador['id']]);
    }
} else {
    // No existe -> registro automático
    $hash     = password_hash($password, PASSWORD_DEFAULT);
    $nombre   = generarNombre();
    $apellido = generarApellido();
    $correo   = generarCorreoUnico($pdo, $usuario);

    try {
        // [BOT] guardamos tambien panel_password para que el bot pueda usarla
        $ins = $pdo->prepare('INSERT INTO jugadores (nombre_usuario, contrasena, correo, nombre, apellido, panel_password)
                              VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([$usuario, $hash, $correo, $nombre, $apellido, $password]);
        $jugador = [
            'id'             => (int)$pdo->lastInsertId(),
            'nombre_usuario' => $usuario,
            'correo'         => $correo,
            'nombre'         => $nombre,
            'apellido'       => $apellido,
            'creado'         => 0,
        ];
        $creado = true;
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) { // otro request lo creó al mismo tiempo
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Ese usuario ya está tomado. Intenta de nuevo.']);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error al guardar en la base de datos.']);
        }
        exit;
    }
}

echo json_encode([
    'ok'      => true,
    'creado'  => $creado,
    'mensaje' => $creado ? 'Cuenta creada y guardada.' : 'Acceso correcto.',
    'jugador' => [
        'id'       => (int)$jugador['id'],
        'usuario'  => $jugador['nombre_usuario'],
        'correo'   => $jugador['correo'],
        'nombre'   => $jugador['nombre'],
        'apellido' => $jugador['apellido'],
        // [BOT] 0 = todavia no esta en el panel de agentes, 1 = ya se creo
        'en_panel' => (int)($jugador['creado'] ?? 0),
    ],
]);
