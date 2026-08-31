<?php
/**
 * ruleta_recordatorio.php — Aviso "tenés un giro nuevo" cuando la ruleta se
 * vuelve a habilitar para un jugador.
 *
 * NO es de cara al jugador: lo dispara un cron por HTTP (curl con X-Api-Key),
 * así db.php resuelve el tenant por el Host igual que todo lo demás y esto
 * sirve multi-cliente sin lógica de tenant propia.
 *
 *   POST /gp-api/ruleta_recordatorio.php   (header X-Api-Key: BOT_API_KEY)
 *   -> { ok, avisados:N }
 *
 * Cron sugerido (una vez al día, a una hora razonable; ajustá el dominio por
 * cada cliente y la hora a tu zona):
 *   0 14 * * *  curl -s -X POST https://ganamoscrm.online/gp-api/ruleta_recordatorio.php \
 *                 -H "X-Api-Key: LA_MISMA_BOT_API_KEY" >> /var/log/gp-ruleta.log 2>&1
 *
 * A quién avisa: jugadores que HOY tienen un giro sin reclamar y que YA habían
 * reclamado algún giro antes (para no molestar a quien nunca la usó, y para que
 * el aviso sea "volvé a girar", no "descubrí la ruleta"). Solo a los que tienen
 * la app y notificaciones activas. Guard por (usuario, dia) para no repetir.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/notificaciones_lib.php';
$crmLib = __DIR__ . '/crm_lib.php';
if (is_file($crmLib)) { require_once $crmLib; }

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

exigir_api_key();

try {
    // Candidatos: jugadores con app + notificaciones activas, que YA usaron la
    // ruleta alguna vez (tienen al menos un reclamo histórico) pero HOY no
    // reclamaron todavía -> les volvió a tocar un giro.
    //
    // NOT EXISTS (reclamo de hoy) = giro disponible.
    // EXISTS (reclamo anterior)   = no es un jugador que nunca la tocó.
    // LEFT JOIN a ruleta_recordatorios = no le avisamos ya hoy.
    // Los tres COLLATE no son adorno: `usuarios` quedo en la collation por
    // defecto del servidor (uca1400) y las tablas del CRM en
    // utf8mb4_unicode_ci, asi que comparar u.username contra
    // ruleta_giros.usuario sin esto tira "Illegal mix of collations" y la
    // query entera falla.
    //
    // Y fallaba: el catch de mas abajo se tragaba el error y devolvia 500,
    // asi que este recordatorio NUNCA se mando desde que existe. El unico
    // rastro quedaba en error_log, que nadie mira. Es el mismo choque que ya
    // esta resuelto en crm.php, admin_usuarios.php y crm_notificaciones.php
    // -- este archivo se quedo afuera.
    $sql =
        "SELECT u.username
           FROM usuarios u
           JOIN ruleta_giros gh
                ON gh.usuario = u.username COLLATE utf8mb4_unicode_ci
               AND gh.reclamado = 1 AND gh.dia < CURDATE()
           LEFT JOIN ruleta_recordatorios rr
                ON rr.usuario = u.username COLLATE utf8mb4_unicode_ci
               AND rr.dia = CURDATE()
          WHERE u.tiene_app = 1
            AND u.notificaciones = 1
            AND rr.usuario IS NULL
            AND NOT EXISTS (
                  SELECT 1 FROM ruleta_giros gt
                   WHERE gt.usuario = u.username COLLATE utf8mb4_unicode_ci
                     AND gt.dia = CURDATE() AND gt.reclamado = 1
                )
          GROUP BY u.username";
    $usuarios = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    $marcar = $pdo->prepare(
        "INSERT IGNORE INTO ruleta_recordatorios (usuario, dia) VALUES (?, CURDATE())"
    );

    $avisados = 0;
    foreach ($usuarios as $usuario) {
        // Reservar primero: si el guard ya existe (otra corrida), no avisar.
        $marcar->execute([$usuario]);
        if ($marcar->rowCount() !== 1) { continue; }

        // 1) Notificación push (barra de Android con la app cerrada; tarjeta si
        //    está abierta). tipo 'ruleta' para que el ícono coincida.
        notif_crear(
            $pdo,
            $usuario,
            '¡Tenés un giro gratis!',
            'Tu giro diario en la ruleta ya está disponible. Girá y ganá bonos.',
            'ruleta',
            null,
            'ruleta'
        );

        // 2) Mensaje del chatbot en su chat, para que lo vea al abrir. Se deja
        //    como turno del bot en la conversación de ese usuario, y se marca
        //    la conversación con preview + no_leidos para que figure como nuevo.
        if (function_exists('crm_conversacion_id') && function_exists('crm_mensaje')) {
            try {
                // session_id vacío: no viene de un dispositivo puntual, es el
                // chat del usuario por su nombre (clave = usuario).
                $conv = crm_conversacion_id($pdo, '', $usuario);
                $texto = '¡Hola! Tu giro diario en la ruleta ya está disponible. '
                       . 'Tocá la ruleta y reclamá tus bonos antes de que termine el día.';
                crm_mensaje($pdo, $conv, 'bot', $texto);   // 'bot' = el rol que usa el proyecto
                $pdo->prepare(
                    "UPDATE conversaciones SET preview = ?, no_leidos = no_leidos + 1 WHERE id = ?"
                )->execute([mb_substr($texto, 0, 280), $conv]);
            } catch (Throwable $e) {
                error_log('ruleta_recordatorio chat: ' . $e->getMessage());
            }
        }

        $avisados++;
    }

    echo json_encode(['ok' => true, 'avisados' => $avisados]);
} catch (Throwable $e) {
    error_log('ruleta_recordatorio: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar']);
}
