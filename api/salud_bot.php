<?php
/**
 * salud_bot.php — ¿El bot de altas está VIVO? Público y de solo lectura.
 *
 * Responde la pregunta que alargó los incidentes del 7/9 y 10/9/2026 y que
 * hasta ahora solo se contestaba entrando al VPS por SSH: si las altas no
 * salen, ¿el contenedor está muerto, o vive y algo más falla?
 *
 *   - bot_visto_hace_seg  segundos desde el último sondeo del bot a su cola
 *                         (altas_cola.php anota el latido en cada
 *                         accion=pendientes; el bot sondea cada ~3s).
 *                         null = nunca se lo vio (o falta desplegar el
 *                         latido). <10 = vivo. Cientos/miles = caído o
 *                         crash-loopeando.
 *   - altas_en_cola       pendientes + procesando ahora mismo.
 *   - mas_vieja_min       minutos de la más vieja sin resolver. Con el bot
 *                         vivo esto tiene que ser ~0; si crece con latido
 *                         fresco, el bot vive pero el PANEL le rechaza el
 *                         trabajo (credenciales, sesión, panel caído).
 *
 * NO es secreto: dice si la automatización anda, lo mismo que cualquiera
 * deduce registrándose y mirando el reloj. No expone nombres ni claves.
 * Mismo criterio de publicación que datos_cobro.php.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$hace = null;
try {
    $visto = trim((string)cfg_crm($pdo, 'bot_altas_visto_en'));
    if ($visto !== '') {
        $t = strtotime($visto);
        if ($t !== false) {
            $hace = max(0, time() - $t);
        }
    }
} catch (Throwable $e) {
    error_log('salud_bot: ' . $e->getMessage());
}

$enCola = null;
$viejaMin = null;
try {
    $r = $pdo->query(
        "SELECT COUNT(*) AS c,
                TIMESTAMPDIFF(MINUTE, MIN(pedido_en), NOW()) AS m
           FROM altas
          WHERE estado IN ('pendiente', 'procesando')"
    )->fetch();
    if ($r) {
        $enCola  = (int)$r['c'];
        $viejaMin = ($enCola > 0 && $r['m'] !== null) ? (int)$r['m'] : null;
    }
} catch (Throwable $e) {
    error_log('salud_bot: ' . $e->getMessage());
}

echo json_encode([
    'ok'                 => true,
    'bot_visto_hace_seg' => $hace,
    'altas_en_cola'      => $enCola,
    'mas_vieja_min'      => $viejaMin,
], JSON_UNESCAPED_UNICODE);
