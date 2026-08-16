#!/usr/bin/env php
<?php
/**
 * crear_operador.php — Bootstrap de operadores del CRM. CLI, no HTTP.
 *
 * Existe porque hace falta un operador para poder loguearse al CRM, y no
 * hay sesión sin un operador ya creado (huevo y gallina) — por eso es un
 * script de línea de comandos y no un endpoint web.
 *
 *   php scripts/crear_operador.php <username>
 *       Pide el password por stdin dos veces (confirmación), SIN eco en
 *       pantalla donde el sistema lo permite. Inserta en `operadores`.
 *       Falla si el username ya existe (usar --reset-password para eso).
 *
 *   php scripts/crear_operador.php --reset-password <username>
 *       Igual, pero UPDATE en vez de INSERT. Falla si el username NO existe.
 *
 * Corre con el mismo config.php/db.php que el resto de la API: necesita
 * api/config.local.php (o las variables de entorno equivalentes) ya
 * configurado. Requiere sql/18_operadores.sql corrida antes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script es solo para línea de comandos (CLI), no para el navegador.\n");
    exit(1);
}

require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

/**
 * Lee una línea de stdin sin mostrarla en pantalla. En Windows no hay una
 * forma simple y portable de ocultar el tipeo sin una extensión que no
 * viene por defecto, así que ahí se avisa y se cae a fgets() normal (se ve
 * lo que se tipea) — es preferible a bloquear el script en ese sistema.
 */
function leer_password_oculto(string $prompt): string
{
    echo $prompt;
    $esWindows = stripos(PHP_OS, 'WIN') === 0;

    if ($esWindows) {
        echo "\n(en Windows no se puede ocultar el tipeo con PHP puro — se va a ver en pantalla)\n> ";
        $linea = fgets(STDIN);
        return $linea === false ? '' : rtrim($linea, "\r\n");
    }

    system('stty -echo');
    $linea = fgets(STDIN);
    system('stty echo');
    echo "\n";
    return $linea === false ? '' : rtrim($linea, "\r\n");
}

function pedir_password_confirmado(): string
{
    while (true) {
        $p1 = leer_password_oculto('Password (mínimo 8 caracteres): ');
        if (strlen($p1) < 8) {
            echo "Muy corta, mínimo 8 caracteres.\n\n";
            continue;
        }
        $p2 = leer_password_oculto('Repetí el password: ');
        if ($p1 !== $p2) {
            echo "No coinciden, probá de nuevo.\n\n";
            continue;
        }
        return $p1;
    }
}

function validar_username(string $u): ?string
{
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $u)) {
        return "Usuario inválido: 3 a 60 caracteres, letras, números, punto, guion o guion bajo.";
    }
    return null;
}

$args = array_slice($argv, 1);

$resetPassword = false;
if (($args[0] ?? '') === '--reset-password') {
    $resetPassword = true;
    array_shift($args);
}

$username = trim((string)($args[0] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/crear_operador.php <username>\n");
    fwrite(STDERR, "  php scripts/crear_operador.php --reset-password <username>\n");
    exit(1);
}

if ($error = validar_username($username)) {
    fwrite(STDERR, "$error\n");
    exit(1);
}

$existe = $pdo->prepare('SELECT id FROM operadores WHERE username = ?');
$existe->execute([$username]);
$fila = $existe->fetch();

if ($resetPassword) {
    if (!$fila) {
        fwrite(STDERR, "No existe el operador '$username'. Sacá --reset-password para crearlo.\n");
        exit(1);
    }
    echo "Vas a cambiar el password de '$username'.\n";
} else {
    if ($fila) {
        fwrite(STDERR, "Ya existe el operador '$username'. Usá --reset-password si querés cambiarle el password.\n");
        exit(1);
    }
    echo "Vas a crear el operador '$username'.\n";
}

$password = pedir_password_confirmado();
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($resetPassword) {
    $pdo->prepare('UPDATE operadores SET password_hash = ? WHERE username = ?')
        ->execute([$hash, $username]);
    echo "Listo: password actualizado para '$username'.\n";
} else {
    $pdo->prepare('INSERT INTO operadores (username, password_hash) VALUES (?, ?)')
        ->execute([$username, $hash]);
    echo "Listo: operador '$username' creado.\n";
}
