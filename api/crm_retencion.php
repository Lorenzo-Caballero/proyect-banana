<?php
/**
 * crm_retencion.php — Backend de «Retención»: ¿los jugadores se quedan?
 *
 * 100% LECTURA. No escribe una sola fila en ninguna tabla.
 *
 * Contesta las dos preguntas del negocio, que son distintas y se accionan
 * distinto (el detalle de cada cálculo está en retencion_lib.php):
 *
 *   mes a mes   cuántos activos hay, cuántos se perdieron y cuántos volvieron.
 *               Es la salud de ahora, y `perdidos` es el número a bajar.
 *
 *   cohortes    de los que llegaron en un mes, qué porcentaje seguía N meses
 *               después. Es la CALIDAD de lo que trae la publicidad, y avisa
 *               antes: en esta base la cohorte de noviembre de 2025 retuvo el
 *               68% al mes siguiente, la de diciembre el 42% y la de enero el
 *               30% -- la caída de calidad se vio meses antes de que cayera el
 *               volumen.
 *
 * MULTI-CLIENTE: no hay nada atado a un casino. `$pdo` ya viene resuelto por
 * dominio (db.php), y la librería detecta sola qué fuentes tiene esta base:
 * sin el libro del panel (migración 67) mide con la definición canónica de
 * carga y devuelve menos historia, sin fallar.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/publicidad_lib.php';
require __DIR__ . '/retencion_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('ret_salir')) {
    function ret_salir($data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* Misma puerta que el resto del CRM. Retención no muestra plata, pero sí el
   comportamiento de jugadores identificados: es información del negocio.
   Sin chequeo de saldo de plataforma (false): esta pantalla solo lee, y
   dejar al operador sin ver sus números porque el saldo del agente está bajo
   no ayuda a nadie. */
exigir_operador(false);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    ret_salir(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$accion = (string)($_GET['accion'] ?? 'resumen');

/* Cuántos meses hacia atrás. El tope de 36 está en la librería; acá se acota
   la entrada para que un número absurdo en la URL no arme una consulta enorme. */
$meses = (int)($_GET['meses'] ?? 12);
$meses = max(2, min(36, $meses));

try {
    if ($accion === 'resumen') {
        $mesAMes = ret_mes_a_mes($pdo, $meses);
        $coh     = ret_cohortes($pdo, $meses);

        /* El titular: la tasa de pérdida del último mes CERRADO. El mes en
           curso no sirve para eso -- todavía le faltan días y siempre se ve
           peor de lo que va a terminar siendo. */
        $ultimoCerrado = null;
        foreach (array_reverse($mesAMes) as $m) {
            if (empty($m['en_curso']) && empty($m['primero'])) { $ultimoCerrado = $m; break; }
        }

        ret_salir([
            'ok'         => true,
            'cobertura'  => ret_cubre_desde($pdo),
            'mes_a_mes'  => $mesAMes,
            'cohortes'   => $coh['cohortes'],
            'max_periodos' => $coh['max_periodos'],
            'titular'    => $ultimoCerrado,
            'generado_en' => date('Y-m-d H:i:s'),
        ]);
    }

    ret_salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
} catch (Throwable $e) {
    error_log('crm_retencion: ' . $e->getMessage());
    ret_salir(['ok' => false, 'error' => 'Error al consultar'], 500);
}
