<?php
/**
 * bancos_sync.php — Recibe los datos bancarios del panel de ganamos (los manda
 *                   colector/sync_bancos.py) y los espeja en `bancos_ganamos`.
 *
 * POST  { "bancos": [ {id, titular, details, bank}, ... ] }
 *   header: X-API-Key  (o X-Api-Token) = BOT_API_KEY
 *   -> { "ok":true, "recibidos":N, "guardados":N, "borrados":N }
 *
 * EL PANEL MANDA, ESTO SOLO ANOTA. La billetera a la que transfieren los
 * jugadores se cambia en el panel de agentes de ganamos, que es la unica
 * fuente de verdad; el CRM la lee para poder decirsela por el chat. Ver el
 * porque en sql/47_bancos_ganamos.sql.
 *
 * El orden del array IMPORTA y se guarda: segun las pruebas, la plataforma le
 * muestra al jugador la PRIMERA entrada.
 *
 * Una lista VACIA borra el espejo, y esta bien: significa que el cliente saco
 * todos sus datos bancarios del panel. Lo que NUNCA tiene que pasar es que un
 * error de lectura llegue aca como lista vacia -- por eso sync_bancos.py no
 * postea si la lectura fallo (ver alla), y este endpoint exige que venga la
 * clave `bancos` para distinguir "vacio" de "no vino nada".
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';

header('Content-Type: application/json; charset=utf-8');

$key = cfg('BOT_API_KEY');
$enviada = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $enviada)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!array_key_exists('bancos', $body) || !is_array($body['bancos'])) {
    // Sin la clave no se toca nada: "no vino nada" no puede significar
    // "borra todas las billeteras".
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta bancos']);
    exit;
}
$bancos = $body['bancos'];

try {
    $pdo->beginTransaction();

    $vistos = [];
    $up = $pdo->prepare(
        "INSERT INTO bancos_ganamos (id_ganamos, titular, details, tipo, posicion)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE titular = VALUES(titular), details = VALUES(details),
                                 tipo = VALUES(tipo), posicion = VALUES(posicion)"
    );
    foreach ($bancos as $i => $b) {
        $id      = (int)($b['id'] ?? 0);
        $details = trim((string)($b['details'] ?? ''));
        // Sin id o sin el dato a transferir, la entrada no sirve para nada.
        if ($id <= 0 || $details === '') { continue; }
        $up->execute([
            $id,
            mb_substr(trim((string)($b['titular'] ?? '')), 0, 160),
            mb_substr($details, 0, 190),
            mb_substr(trim((string)($b['bank'] ?? '')), 0, 40),
            (int)$i,
        ]);
        $vistos[] = $id;
    }

    // Lo que ya no esta en el panel se va del espejo: si el cliente borro una
    // billetera, el chat no puede seguir ofreciendola.
    if ($vistos) {
        $marcas = implode(',', array_fill(0, count($vistos), '?'));
        $del = $pdo->prepare("DELETE FROM bancos_ganamos WHERE id_ganamos NOT IN ($marcas)");
        $del->execute($vistos);
    } else {
        $del = $pdo->query("DELETE FROM bancos_ganamos WHERE 1");
    }
    $borrados = $del->rowCount();

    $pdo->commit();

    // Marca de tiempo de la ULTIMA lectura exitosa. Es lo que deja distinguir
    // "el panel no tiene billeteras" de "hace dias que no lo podemos leer".
    cfg_crm_guardar($pdo, ['bancos_sync_en' => date('Y-m-d H:i:s')], 'sync_bancos');

    echo json_encode(['ok' => true, 'recibidos' => count($bancos),
                      'guardados' => count($vistos), 'borrados' => $borrados]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('bancos_sync: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
}
