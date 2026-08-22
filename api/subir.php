<?php
/**
 * subir.php — Sube un adjunto (imagen o PDF) y lo agrega como mensaje.
 *
 * multipart/form-data:
 *   archivo        (file)  imagen jpg/png/webp/gif o pdf, hasta 8 MB
 *   session_id     (str)   -> mensaje del CLIENTE en el chat de esa sesion
 *   usuario        (str, opcional) para vincular la conversacion
 *   conversacion_id(int)   -> mensaje del AGENTE en esa conversacion (CRM)
 *
 * Devuelve: { ok, adjunto:{tipo,url,nombre} }
 *
 * Guarda en api/uploads/ (con .htaccess que impide ejecutar PHP ahi).
 *
 * Dos ramas con reglas distintas:
 * - AGENTE (conversacion_id > 0): exige sesion del CRM (exigir_operador) y
 *   que la conversacion exista. Antes no lo pedia, y cualquiera podia
 *   escribir en el hilo de cualquier jugador haciendose pasar por agente.
 * - JUGADOR (session_id): anonima por diseño -- manda el comprobante antes
 *   de identificarse. Se le exige session_id (sin eso el archivo quedaba
 *   huerfano en el disco) y va con rate limit por IP.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Usa POST']); exit;
}

const MAX_BYTES = 8 * 1024 * 1024;
$PERMITIDOS = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/gif'  => 'gif', 'application/pdf' => 'pdf',
];

/* ---------------------------------------------------------------------------
 * Quién puede subir, y a dónde. Se resuelve ANTES de tocar el disco: si no,
 * el archivo queda guardado igual aunque después rechacemos el mensaje.
 *
 *   conversacion_id > 0  -> subida del AGENTE: exige sesión del CRM. Sin esto
 *                           cualquiera podía escribir en el hilo de cualquier
 *                           jugador haciéndose pasar por un agente.
 *   session_id           -> subida del JUGADOR: anónima por diseño (manda el
 *                           comprobante antes de identificarse), pero la
 *                           sesión tiene que existir de verdad en el CRM.
 * ------------------------------------------------------------------------- */
$convId    = (int)($_POST['conversacion_id'] ?? 0);
$sessionId = trim((string)($_POST['session_id'] ?? ''));
$esAgente  = $convId > 0;

if ($esAgente) {
    exigir_operador();
    $st = $pdo->prepare('SELECT 1 FROM conversaciones WHERE id = ? LIMIT 1');
    $st->execute([$convId]);
    if (!$st->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Esa conversación no existe']); exit;
    }
} else {
    // Sin session_id no hay dónde guardar el mensaje: antes se aceptaba igual
    // y el archivo quedaba huérfano en el disco para siempre.
    if ($sessionId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta session_id']); exit;
    }
    // Rate limit por IP: sin esto un anónimo podía llenar el disco con
    // archivos de 8 MB. Mismo patrón de archivo temporal que crm_login.php.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $rl = sys_get_temp_dir() . '/gp_subir_rl_' . md5($ip);
    $hits = [];
    if (is_file($rl)) {
        foreach (explode(',', (string)@file_get_contents($rl)) as $t) {
            if ($t !== '' && (int)$t > time() - 600) { $hits[] = (int)$t; }
        }
    }
    if (count($hits) >= 10) {   // 10 archivos cada 10 minutos por IP
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Demasiadas subidas. Esperá unos minutos.']); exit;
    }
    $hits[] = time();
    @file_put_contents($rl, implode(',', $hits), LOCK_EX);
}

if (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'No llegó el archivo']); exit;
}
$f = $_FILES['archivo'];
if ($f['size'] > MAX_BYTES) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'El archivo supera 8 MB']); exit;
}

// Validar el tipo REAL (no confiar en la extension que manda el navegador).
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
if (!isset($PERMITIDOS[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Solo se aceptan imágenes o PDF']); exit;
}
$ext = $PERMITIDOS[$mime];

$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

$nombre = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destino = $dir . '/' . $nombre;
if (!move_uploaded_file($f['tmp_name'], $destino)) {
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']); exit;
}

$url = '/api/uploads/' . $nombre;
$adjunto = [
    'tipo'   => $ext === 'pdf' ? 'pdf' : 'imagen',
    'url'    => $url,
    'nombre' => mb_substr((string)($f['name'] ?? $nombre), 0, 120),
];

// Registrar el mensaje con el adjunto. Quién sube y a dónde ya se validó
// arriba, antes de guardar el archivo.
try {
    if ($esAgente) {
        // Subida del AGENTE (CRM)
        crm_mensaje($pdo, $convId, 'agente', '📎 ' . $adjunto['nombre'], $adjunto);
        $pdo->prepare("UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?")
            ->execute(['📎 ' . $adjunto['nombre'], $convId]);
    } else {
        // Subida del CLIENTE (chat del sitio)
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $convId  = crm_conversacion_id($pdo, $sessionId, $usuario !== '' ? $usuario : null);
        crm_mensaje($pdo, $convId, 'user', '📎 ' . $adjunto['nombre'], $adjunto);
        $etq = $adjunto['tipo'] === 'pdf' ? 'Comprobante (PDF)' : 'Comprobante (imagen)';
        $pdo->prepare("UPDATE conversaciones SET preview = ?, no_leidos = no_leidos + 1, actualizada_en = NOW() WHERE id = ?")
            ->execute([$etq, $convId]);
    }
} catch (Throwable $e) {
    error_log('subir: ' . $e->getMessage());
    // el archivo ya está subido; devolvemos igual el adjunto
}

echo json_encode(['ok' => true, 'adjunto' => $adjunto], JSON_UNESCAPED_UNICODE);
