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
 * Fase 0.5: NO se agrego exigir_operador() aca.
 * - La rama del agente (conversacion_id > 0) ya va cubierta porque solo la
 *   llama crm.html, que requiere sesion desde Paso 6.
 * - La rama del jugador (session_id, sin conversacion_id) es un flujo
 *   anonimo por diseño: no puede exigir sesion. Queda abierta a subidas
 *   sin autenticacion.
 *
 * Gap conocido, prioridad ALTA para Fase A: sumar validacion de
 * session_id existente en `conversaciones` + rate limit por IP (reusar
 * patron de _limite() en api/auth.php).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';

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

// Registrar el mensaje con el adjunto.
try {
    $convId = (int)($_POST['conversacion_id'] ?? 0);
    if ($convId > 0) {
        // Subida del AGENTE (CRM)
        crm_mensaje($pdo, $convId, 'agente', '📎 ' . $adjunto['nombre'], $adjunto);
        $pdo->prepare("UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?")
            ->execute(['📎 ' . $adjunto['nombre'], $convId]);
    } else {
        // Subida del CLIENTE (chat del sitio)
        $sessionId = trim((string)($_POST['session_id'] ?? ''));
        $usuario   = trim((string)($_POST['usuario'] ?? ''));
        if ($sessionId === '') {
            echo json_encode(['ok' => true, 'adjunto' => $adjunto, 'aviso' => 'sin session_id: no se guardó en el CRM']);
            exit;
        }
        $convId = crm_conversacion_id($pdo, $sessionId, $usuario !== '' ? $usuario : null);
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
