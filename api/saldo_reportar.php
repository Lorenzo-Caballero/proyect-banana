<?php
/**
 * saldo_reportar.php — El navegador avisa cuanto saldo ve en el header.
 *
 *   POST {"usuario":"feudo1234","saldo":1000.00}  -> {ok, guardado}
 *
 * ENDPOINT PUBLICO, sin API key: lo llama widget.js, que corre en la pagina de
 * la plataforma y no puede llevar una clave (la leeria cualquiera).
 *
 * Justamente por eso escribe en `balance_web` y NO en `balance`. Lo que manda
 * un cliente sirve para MOSTRAR, nunca para autorizar: el retiro sigue mirando
 * `balance`, que solo escribe sync_usuarios.py desde el panel de agentes.
 *
 * Requiere sql/17_saldo_web.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// El widget corre en el dominio de la plataforma, no en el nuestro.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

/** Techo de cordura. Un saldo absurdo es un bug o alguien jugando con el
 *  inspector; guardarlo solo ensucia lo que despues mira el agente. */
const SALDO_MAX = 100000000;

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$usuario = trim((string)($body['usuario'] ?? ''));
$saldo   = $body['saldo'] ?? null;

if ($usuario === '' || !is_numeric($saldo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
    exit;
}

$saldo = (float)$saldo;
if ($saldo < 0 || $saldo > SALDO_MAX) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Saldo fuera de rango']);
    exit;
}

try {
    // Solo usuarios que existen: sin esto, cualquiera llena la tabla de basura
    // con nombres inventados. El UPDATE ya lo garantiza (no crea filas), pero
    // devolver 'desconocido' le sirve al widget para dejar de insistir.
    //
    // SOLO `balance_web`, NUNCA `balance`. Este endpoint es publico y sin
    // credenciales: lo que manda un cliente sirve para MOSTRAR, jamas para
    // autorizar plata. `balance` es lo que mira fichas_pedir_retiro() para
    // aprobar un retiro (ver fichas_lib.php), asi que escribirlo desde aca
    // permitia inflarlo con un POST sin credenciales -- contra la cuenta de
    // cualquier jugador, porque `usuario` es un parametro libre -- y pedir el
    // retiro acto seguido. `balance` lo escribe unicamente sync_usuarios.py
    // desde el panel de agentes.
    $upd = $pdo->prepare(
        "UPDATE usuarios
            SET balance_web = ?, balance_web_en = NOW()
          WHERE username = ?"
    );
    $upd->execute([$saldo, $usuario]);

    echo json_encode(['ok' => true, 'guardado' => $upd->rowCount() > 0]);

} catch (Throwable $e) {
    error_log('saldo_reportar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error']);
}
