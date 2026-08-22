<?php
/**
 * difusiones_chat_procesar.php — Aplica las difusiones de chat PROGRAMADAS
 * cuya fecha ya llegó (tabla difusiones_chat, migración 32).
 *
 * A diferencia del push (pasivo: el celular la descubre solo al sondear), un
 * mensaje de chat necesita algo que lo inserte de verdad en el momento
 * elegido -- si no, aparecería antes de tiempo en el historial de quien abra
 * el chat. Este cron es ese "algo".
 *
 * NO es de cara al jugador: lo dispara un cron por HTTP (curl con X-Api-Key),
 * así db.php resuelve el tenant por el Host igual que todo lo demás y esto
 * sirve multi-cliente sin lógica de tenant propia.
 *
 *   POST /gp-api/difusiones_chat_procesar.php   (header X-Api-Key: BOT_API_KEY)
 *   -> { ok, procesadas:N, mensajes_insertados:N }
 *
 * Cron sugerido (cada 5-10 min; el mensaje sale con ese margen de demora
 * respecto de la hora elegida, igual que cualquier cola por sondeo):
 *   (cada 10 min) curl -s -X POST https://ganamoscrm.online/gp-api/difusiones_chat_procesar.php \
 *                   -H "X-Api-Key: LA_MISMA_BOT_API_KEY" >> /var/log/gp-difusiones-chat.log 2>&1
 *   (crontab real: minuto "star-slash-10" en vez de un asterisco fijo)
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

exigir_api_key();

try {
    $st = $pdo->prepare(
        "SELECT id, usuario, texto FROM difusiones_chat
          WHERE procesada_en IS NULL AND programada_en <= UTC_TIMESTAMP()
          ORDER BY id ASC LIMIT 50"
    );
    $st->execute();
    $pendientes = $st->fetchAll(PDO::FETCH_ASSOC);

    $marcar = $pdo->prepare(
        "UPDATE difusiones_chat SET procesada_en = NOW(), alcance = ? WHERE id = ? AND procesada_en IS NULL"
    );

    $procesadas = 0;
    $totalMensajes = 0;
    foreach ($pendientes as $d) {
        $alcance = crm_difusion_chat_aplicar($pdo, $d['usuario'], $d['texto']);
        // Marcar procesada SIEMPRE, incluso si $alcance es 0 (usuario sin
        // conversación todavía): reintentarla de nuevo no cambiaría nada, y
        // dejarla "pendiente para siempre" ensuciaría el listado del CRM.
        $marcar->execute([$alcance, (int)$d['id']]);
        if ($marcar->rowCount() === 1) {
            $procesadas++;
            $totalMensajes += $alcance;
        }
    }

    echo json_encode(['ok' => true, 'procesadas' => $procesadas, 'mensajes_insertados' => $totalMensajes]);
} catch (Throwable $e) {
    error_log('difusiones_chat_procesar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar']);
}
