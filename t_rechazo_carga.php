<?php
/**
 * t_rechazo_carga.php — Rechazar una carga pedida en el juego la RECHAZA.
 *
 * EL BUG (reportado por Nahuel el 15/09/2026): "revisá que cuando se rechace
 * ahí no quede eternamente en «rechazo en camino»".
 *
 * El cartel era el síntoma visible. Debajo había algo peor: `peticiones_cola.php`
 * aceptaba el estado `'cerrada'` en la validación pero **no tenía una rama que
 * lo manejara**. Caía derecho en el bloque de `aprobada`, así que una carga que
 * el operador RECHAZÓ terminaba:
 *
 *   - con `estado = 'aprobada'`;
 *   - con su línea en `movimientos` (origen='peticion', tipo='saldo', monto>0);
 *   - y con `rechazo_pedido_en` todavía puesto, o sea el cartel para siempre.
 *
 * Esa línea de `movimientos` es exactamente lo que `publicidad_sql_cargas()`
 * cuenta como una carga. O sea que **rechazar una carga la sumaba a los
 * ingresos de Finanzas**. Un rechazo que factura es el peor final posible.
 *
 * Se ejecuta el ARCHIVO de verdad, no una copia: lo que hay que saber es si
 * ESE endpoint, tal como está desplegado, cierra bien. Los `exit;` pasan a
 * `return;` dentro de una función para no matar el proceso del test.
 *
 *     php t_rechazo_carga.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;

$ok = 0; $fallas = 0;
function ok(bool $c, string $q, string $d = ''): void {
    global $ok, $fallas;
    if ($c) { $ok++; echo "  OK    $q\n"; }
    else { $fallas++; echo "  FALLA $q" . ($d !== '' ? " -- $d" : '') . "\n"; }
}

const CLAVE = 'clave-de-prueba-larguisima-1234567890';
function cfg($c, $d = '') { return $c === 'BOT_API_KEY' ? CLAVE : $d; }

/** Corre el endpoint de verdad y devuelve lo que habría contestado. */
function confirmar(array $cuerpo): array {
    global $pdo;
    $src = file_get_contents(__DIR__ . '/api/peticiones_cola.php');
    $src = preg_replace('/^\s*<\?php/', '', $src, 1);
    $src = preg_replace('/^\s*declare\(strict_types=1\);/m', '', $src, 1);
    $src = preg_replace('/^\s*require(_once)?\s+__DIR__[^;]+;/m', '', $src);
    $src = preg_replace('/^\s*if \(is_file\(\$\w+Lib\)\).*$/m', '', $src);
    $src = str_replace("file_get_contents('php://input')",
                       'json_encode($GLOBALS["__cuerpo"])', $src);
    $src = preg_replace('/^(\s*)exit;/m', '$1return;', $src);
    /* Las cabeceras HTTP no tienen nada que probar acá, y en CLI el warning de
       header() cae dentro del buffer y rompe el json_decode. La línea ENTERA:
       el Content-Type lleva un ";" adentro del string. */
    $src = preg_replace("/^[ \t]*header\\(.*\\);[ \t\r]*$/m", '', $src);

    $GLOBALS['__cuerpo'] = $cuerpo;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_API_KEY'] = CLAVE;
    $_GET = ['accion' => 'confirmar'];

    $correr = function () use ($src, $pdo) {
        ob_start();
        try { eval($src); }
        catch (Throwable $e) { ob_get_clean(); return ['__fatal' => $e->getMessage()]; }
        $salida = ob_get_clean();
        return json_decode($salida ?: '{}', true) ?: [];
    };
    $r = $correr();
    if ($pdo->inTransaction()) { $pdo->rollBack(); }   // por si el endpoint corto antes del commit
    return $r;
}

$U   = 't_rech_' . substr((string)time(), -6);
$RID = 900000000 + (int)substr((string)time(), -6);

$limpiar = function () use ($pdo, $U, $RID) {
    $pdo->prepare("DELETE FROM peticiones_carga WHERE request_id = ?")->execute([$RID]);
    $pdo->prepare("DELETE FROM movimientos WHERE usuario = ?")->execute([$U]);
    $pdo->prepare("DELETE FROM pagos WHERE id_unico LIKE ?")->execute(["t-rech-$U-%"]);
};
/** Una solicitud esperando, con el rechazo ya pedido desde el CRM. */
$sembrar = function (string $pagoId = '') use ($pdo, $U, $RID, $limpiar) {
    $limpiar();
    $pdo->prepare(
        "INSERT INTO peticiones_carga
            (request_id, username, monto, estado, primera_vez, rechazo_pedido_en, rechazo_por, pago_id_unico)
         VALUES (?, ?, 5000, 'esperando', NOW(), NOW(), 'test', ?)"
    )->execute([$RID, $U, $pagoId !== '' ? $pagoId : null]);
};
$pet = function () use ($pdo, $RID) {
    $st = $pdo->prepare("SELECT * FROM peticiones_carga WHERE request_id = ?");
    $st->execute([$RID]);
    return $st->fetch() ?: [];
};
$movs = function () use ($pdo, $U) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = ?");
    $st->execute([$U]);
    return (int)$st->fetchColumn();
};

echo "\n=== 1. Rechazada en ganamos: se cierra como RECHAZADA ===\n";
$sembrar();
$r = confirmar(['request_id' => $RID, 'estado' => 'cerrada',
                'mensaje' => 'rechazada en ganamos por API (200)']);
$p = $pet();
ok(empty($r['__fatal']), 'el endpoint no revienta', (string)($r['__fatal'] ?? ''));
ok(($p['estado'] ?? '') === 'cerrada',
   "queda 'cerrada' y NO 'aprobada'", (string)($p['estado'] ?? '(sin fila)'));

/* EL QUE MAS DUELE. Esa linea es la que publicidad_sql_cargas() cuenta como
   una carga: si se escribe, rechazar una carga la suma a los ingresos. */
ok($movs() === 0, 'NO se registra ningun movimiento: no entro un peso',
   (string)$movs());

/* Y el cartel de la pantalla: sin esto queda "Rechazo en camino" para siempre,
   que fue el sintoma reportado. */
ok(array_key_exists('rechazo_pedido_en', $p) && $p['rechazo_pedido_en'] === null,
   'se baja la marca del pedido de rechazo (se va el cartel)',
   var_export($p['rechazo_pedido_en'] ?? 'sin columna', true));

echo "\n=== 2. La transferencia reclamada se suelta ===\n";
/* No deberia haber ninguna --el CRM y el worker se niegan a rechazar una
   solicitud con pago reclamado-- pero entre esas guardas y este punto pudo
   entrar, y dejarla marcada la sacaria de circulacion sin que nadie sepa. */
$idPago = "t-rech-$U-1";
$pdo->prepare("INSERT INTO pagos (id_unico, monto, remitente, estado) VALUES (?, 5000, 'Test', 'pendiente')")
    ->execute([$idPago]);
$sembrar($idPago);
confirmar(['request_id' => $RID, 'estado' => 'cerrada', 'mensaje' => 'rechazada']);
$p = $pet();
ok(($p['pago_id_unico'] ?? '') === null || ($p['pago_id_unico'] ?? '') === '',
   'la solicitud suelta el pago', var_export($p['pago_id_unico'] ?? null, true));
$e = $pdo->prepare("SELECT estado FROM pagos WHERE id_unico = ?");
$e->execute([$idPago]);
ok($e->fetchColumn() !== 'usado', 'y el pago NO queda consumido');
ok($movs() === 0, 'sigue sin registrarse un ingreso');

echo "\n=== 3. Una aprobada de verdad SI registra el ingreso ===\n";
/* La contracara: el arreglo no puede haber roto el camino bueno. */
$sembrar();
$pdo->prepare("UPDATE peticiones_carga SET rechazo_pedido_en = NULL WHERE request_id = ?")
    ->execute([$RID]);
confirmar(['request_id' => $RID, 'estado' => 'aprobada', 'mensaje' => 'ok']);
$p = $pet();
ok(($p['estado'] ?? '') === 'aprobada', 'queda aprobada', (string)($p['estado'] ?? ''));
ok($movs() === 1, 'y SI deja la linea del ingreso', (string)$movs());

echo "\n=== 4. Confirmar dos veces no hace nada raro ===\n";
/* El worker reintenta los POST. */
$sembrar();
confirmar(['request_id' => $RID, 'estado' => 'cerrada', 'mensaje' => 'rechazada']);
$r2 = confirmar(['request_id' => $RID, 'estado' => 'cerrada', 'mensaje' => 'rechazada']);
ok(!empty($r2['ya_cerrada']), 'el segundo confirma que ya estaba cerrada', json_encode($r2));
ok($movs() === 0, 'y sigue sin ingresos', (string)$movs());

$limpiar();
echo "\n---------------------------------------\n";
echo "$ok OK, $fallas fallas\n";
exit($fallas ? 1 : 0);
