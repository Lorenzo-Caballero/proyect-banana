<?php
/**
 * datos_cobro.php — Los datos de la cuenta a la que el jugador transfiere:
 *                   alias, CBU y titular. Publico y de solo lectura.
 *
 * Lo consume el boton "DATOS PARA TRANSFERIR" del chat (widget.js): en vez de
 * hacer el ida y vuelta con el modelo, el widget pide estos datos directo y
 * los muestra al instante con botones de copiar. Un CBU son 22 digitos y
 * copiarlos a mano es donde el jugador se equivoca; el boton de copiar evita
 * el error que manda la plata a otra cuenta.
 *
 * NO es secreto: es la cuenta de cobro que el jugador necesita para pagar, la
 * misma que ya aparece cuando se crea una recarga. Por eso no lleva API key ni
 * sesion -- cualquiera que va a transferir tiene que poder verla.
 *
 * OJO: esto SOLO muestra los datos. No crea ninguna recarga ni acredita nada.
 * El camino que acredita solo sigue siendo el mail del banco (pagos.php) y el
 * matcher, que se apoya en el titular declarado y el monto. Un jugador que
 * transfiere sin una recarga creada cae en revision hasta que el matcher o un
 * agente lo resuelva -- igual que siempre.
 *
 * GET -> { ok:true, alias, cbu, titular }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recargas_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Cambia poco, pero cuando el dueño edita su cuenta de cobro el jugador tiene
// que ver la nueva enseguida: un cache largo le daria el CBU viejo y la plata
// iria a una cuenta que ya no se mira. Cache corto, no no-store: no es un dato
// sensible ni que cambie a cada segundo.
header('Cache-Control: public, max-age=60');

try {
    $cta = rl_cuenta_cobro();
} catch (Throwable $e) {
    error_log('datos_cobro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo leer la cuenta de cobro'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

$alias   = trim((string)($cta['alias']   ?? ''));
$cbu     = trim((string)($cta['cbu']     ?? ''));
$titular = trim((string)($cta['titular'] ?? ''));

// Sin CBU no hay a donde transferir: es el unico dato obligatorio. Si falta,
// es un cliente sin cuenta de cobro configurada -- que el widget lo mande a un
// agente en vez de mostrar datos a medias.
if ($cbu === '' && $alias === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No hay una cuenta de cobro configurada'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'alias'   => $alias,
    'cbu'     => $cbu,
    'titular' => $titular,
], JSON_UNESCAPED_UNICODE);
