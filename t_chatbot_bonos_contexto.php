<?php
/** Pruebas aisladas de identidad revelable y explicación de bonos duplicados. */
declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Falta pdo_sqlite; no se ejecutaron las pruebas.\n");
    exit(2);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->sqliteCreateFunction('CHAR_LENGTH', 'strlen', 1);
$pdo->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no, 3);
$pdo->exec('CREATE TABLE huellas_pagador (usuario TEXT, cuit TEXT, cbu TEXT, nombre TEXT)');
$pdo->exec('CREATE TABLE recargas (usuario TEXT, trx_declarada TEXT)');
$pdo->exec('CREATE TABLE dispositivos_usuarios (device_id TEXT, usuario TEXT, usos INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE usuarios (username TEXT, bloqueado INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE movimientos (usuario TEXT, origen TEXT, monto INTEGER)');
require_once __DIR__ . '/api/vinculos_lib.php';
require_once __DIR__ . '/api/chatbot_bonos_lib.php';

$checks = 0;
$fails = 0;
$check = static function (string $label, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    if (!$ok) { $fails++; }
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
};
$paid = static function (PDO $pdo, string $user, string $origin): void {
    $st = $pdo->prepare('INSERT INTO movimientos (usuario, origen, monto) VALUES (?, ?, 100)');
    $st->execute([$user, $origin]);
};

// El mismo comprobante declarado desde las dos cuentas permite revelar solo el username.
$pdo->exec("INSERT INTO recargas VALUES ('actual_trx', 'OPERACION987654'), ('otra_trx', 'OPERACION987654')");
$paid($pdo, 'otra_trx', 'bono_bienvenida');
$ctx = chatbot_bloque_bonos_duplicados($pdo, 'actual_trx');
$check('mismo comprobante explica bienvenida sin divulgar la otra cuenta',
    !str_contains($ctx, 'otra_trx') && str_contains($ctx, 'bono de bienvenida')
    && str_contains($ctx, 'no confirma quién es titular'));
$check('nunca incluye ni promete una contraseña',
    !str_contains($ctx, 'password:') && str_contains($ctx, 'no son legibles'));

// Un teléfono compartido bloquea el bono de app, pero el bot no identifica al otro usuario.
$pdo->exec("INSERT INTO dispositivos_usuarios VALUES ('device-shared', 'actual_device', 1), ('device-shared', 'secreto_device', 1)");
$paid($pdo, 'secreto_device', 'bono_app');
$ctx = chatbot_bloque_bonos_duplicados($pdo, 'actual_device');
$check('bono de app duplicado por dispositivo se explica sin revelar cuenta',
    str_contains($ctx, 'bono por instalar la app') && !str_contains($ctx, 'secreto_device'));

// Compartir banco es suficiente para el candado de pago, no para exponer usuario.
$pdo->exec("INSERT INTO huellas_pagador VALUES ('actual_banco', '20123456789', '', 'PERSONA'), ('secreto_banco', '20123456789', '', 'PERSONA')");
$paid($pdo, 'secreto_banco', 'bono_bienvenida');
$ctx = chatbot_bloque_bonos_duplicados($pdo, 'actual_banco');
$check('cuenta bancaria compartida explica rechazo sin revelar cuenta',
    str_contains($ctx, 'bono de bienvenida') && !str_contains($ctx, 'secreto_banco'));

// El teléfono no es señal para el bono de bienvenida.
$pdo->exec("INSERT INTO dispositivos_usuarios VALUES ('device-welcome', 'actual_welcome', 1), ('device-welcome', 'secreto_welcome', 1)");
$paid($pdo, 'secreto_welcome', 'bono_bienvenida');
$check('compartir dispositivo no atribuye bienvenida duplicada',
    chatbot_bloque_bonos_duplicados($pdo, 'actual_welcome') === '');

$check('sin bono anterior vinculado no se agrega contexto',
    chatbot_bloque_bonos_duplicados($pdo, 'sin_vinculos') === '');

echo "\n$checks comprobaciones, $fails fallidas\n";
exit($fails === 0 ? 0 : 1);
