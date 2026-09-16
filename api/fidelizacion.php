<?php
/**
 * fidelizacion.php — Dispara UNA pasada de la campaña de fidelización.
 *
 * NO es de cara al jugador: lo dispara un cron por HTTP (curl con X-Api-Key),
 * igual que ruleta_recordatorio.php — por HTTP y no por CLI para que db.php
 * resuelva el tenant por Host, multi-cliente sin lógica propia.
 *
 *   POST /gp-api/fidelizacion.php   (header X-Api-Key: BOT_API_KEY)
 *   -> { ok, avisados, por_tramo }
 *
 * Cron cada hora (correr de más no duplica, el candado por racha está en
 * fidelizacion_lib). NO alcanza con dejarlo escrito acá: hay que INSTALARLO en
 * el crontab del VPS, o el CRM muestra "el motor nunca corrió — falta el cron"
 * (pasó: quedó solo como comentario). El instalador idempotente es
 *   scripts/instalar-cron-fidelizacion.sh
 * y pone exactamente:
 *   0 * * * *  curl -s -X POST https://ganamoscrm.online/gp-api/fidelizacion.php \
 *                -H "X-Api-Key: LA_MISMA_BOT_API_KEY" >> /var/log/gp-fidelizacion.log 2>&1
 *
 * Con la campaña apagada (fid_activa='0') responde ok con avisados:0 — el
 * cron puede quedar puesto siempre; el interruptor vive en el CRM.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config_crm.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_notificaciones.php';
require __DIR__ . '/notificaciones_lib.php';
require __DIR__ . '/fidelizacion_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

exigir_api_key();

try {
    echo json_encode(fid_correr($pdo), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('fidelizacion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'error']);
}
