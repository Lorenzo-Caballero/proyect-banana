<?php
/**
 * _bootstrap_operador.php — Crea el PRIMER operador del CRM por HTTP.
 *
 * Existe SOLO porque todavía no hay SSH activo en el hosting:
 * scripts/crear_operador.php (la vía normal, ver api/README.md) necesita
 * una terminal interactiva que hoy no hay cómo abrir. Este archivo es el
 * puente de una sola vez, para el primer operador nada más.
 *
 * Doble candado, no uno solo:
 *   1) Token largo por query/POST, comparado con BOOTSTRAP_TOKEN de
 *      config.local.php (NUNCA hardcodeado acá — mismo criterio que
 *      BOT_API_KEY. Generalo con:
 *        python -c "import secrets; print(secrets.token_urlsafe(32))"
 *      y agregalo a config.local.php como 'BOOTSTRAP_TOKEN' => '...').
 *   2) Se niega a crear un operador si YA existe alguno en `operadores`,
 *      pase lo que pase con el token. Esto es lo que lo hace seguro dejar
 *      olvidado un rato — aunque igual NO hay que dejarlo olvidado.
 *
 * Sin token válido responde 404 liso, igual que una URL que no existe: no
 * hay ninguna pista de qué es este archivo para quien no tiene la clave.
 *
 * BORRÁ ESTE ARCHIVO DEL SERVER (y sacá BOOTSTRAP_TOKEN de
 * config.local.php) apenas crees el primer operador. Para el segundo en
 * adelante, usá scripts/crear_operador.php (por SSH, cuando lo actives) o
 * repetí este archivo con un token nuevo — no es para altas regulares.
 *
 * GET  ?token=...                            -> formulario
 * POST ?token=... {usuario,password,password2} -> crea y responde
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

/** Sin pistas: responde exactamente como una URL que no existe. */
function boot_negar(): void
{
    http_response_code(404);
    exit;
}

$tokenEsperado = cfg('BOOTSTRAP_TOKEN');
if ($tokenEsperado === '' || strlen($tokenEsperado) < 24) {
    boot_negar();
}

$tokenRecibido = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals($tokenEsperado, $tokenRecibido)) {
    boot_negar();
}

// Segundo candado: si YA hay un operador, este archivo no hace nada más,
// pase lo que pase con el token. Protege incluso si alguien encuentra la
// URL después de usada, o si te olvidás de borrarla.
$yaHayOperador = (bool)$pdo->query('SELECT 1 FROM operadores LIMIT 1')->fetchColumn();
if ($yaHayOperador) {
    boot_negar();
}

function boot_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function boot_validar_usuario(string $u): ?string
{
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $u)) {
        return 'Usuario inválido: 3 a 60 caracteres, letras, números, punto, guion o guion bajo.';
    }
    return null;
}

$error  = '';
$exito  = false;
$usuario = '';

// La contraseña viaja SOLO por POST (nunca por query string), para que no
// termine grabada en el log de accesos del server.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario   = trim((string)($_POST['usuario'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    $errUsuario = boot_validar_usuario($usuario);
    if ($errUsuario !== null) {
        $error = $errUsuario;
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña tiene que tener al menos 8 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'Las dos contraseñas no coinciden.';
    } else {
        $pdo->prepare('INSERT INTO operadores (username, password_hash) VALUES (?, ?)')
            ->execute([$usuario, password_hash($password, PASSWORD_DEFAULT)]);
        $exito = true;
    }
}

header('Content-Type: text/html; charset=utf-8');

if ($exito) {
    echo '<!doctype html><meta charset="utf-8"><title>Bootstrap operador</title>'
       . '<body style="font:15px system-ui;max-width:460px;margin:60px auto;padding:0 16px">'
       . '<h2>Listo</h2>'
       . '<p>Se creó el operador <b>' . boot_esc($usuario) . '</b>.</p>'
       . '<p><b>Borrá este archivo del server ahora</b> y sacá <code>BOOTSTRAP_TOKEN</code> '
       . 'de <code>config.local.php</code>. Ya cumplió su función: aunque queda bloqueado '
       . 'solo a partir de acá (ya existe un operador), no hace falta dejarlo colgado.</p>'
       . '</body>';
    exit;
}

echo '<!doctype html><meta charset="utf-8"><title>Bootstrap operador</title>'
   . '<body style="font:15px system-ui;max-width:460px;margin:60px auto;padding:0 16px">'
   . '<h2>Crear el primer operador del CRM</h2>'
   . ($error !== '' ? '<p style="color:#b00020">' . boot_esc($error) . '</p>' : '')
   . '<form method="post">'
   . '<input type="hidden" name="token" value="' . boot_esc($tokenRecibido) . '">'
   . '<p><label>Usuario<br><input name="usuario" value="' . boot_esc($usuario) . '" required autofocus></label></p>'
   . '<p><label>Contraseña (mínimo 8 caracteres)<br><input type="password" name="password" required></label></p>'
   . '<p><label>Repetir contraseña<br><input type="password" name="password2" required></label></p>'
   . '<button type="submit">Crear operador</button>'
   . '</form>'
   . '</body>';
