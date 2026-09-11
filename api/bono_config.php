<?php
/**
 * bono_config.php — El % del bono de bienvenida que promete la landing.
 *                   Público y de solo lectura, como datos_cobro.php.
 *
 * Existe para que la landing (bono.html) y el pago digan EL MISMO número.
 * Antes el 50 estaba hardcodeado en dos lados (BONO_PCT en la landing y
 * RL_BONO_BIENVENIDA_PCT en el server) con un comentario rogando que nadie
 * cambiara uno solo. Ahora el número vive en la config del CRM
 * ('bono_bienvenida_pct', editable en Configuración) y los dos lo leen de acá:
 * el server al acreditar (rl_bono_bienvenida_aplicar) y la landing al pintar.
 *
 * GET -> { ok:true, pct:50 }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Cache corto: si el dueño cambia el %, la landing tiene que mostrar el
// nuevo enseguida -- una promesa vieja en pantalla es un reclamo seguro.
header('Cache-Control: public, max-age=60');

$pct = 50;
try {
    $v = cfg_crm($pdo, 'bono_bienvenida_pct');
    if ($v !== null && is_numeric($v) && (int)$v >= 0) {
        $pct = (int)$v;
    }
} catch (Throwable $e) {
    error_log('bono_config: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'pct' => $pct], JSON_UNESCAPED_UNICODE);
