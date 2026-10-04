<?php
/** Contexto mínimo del chatbot para explicar los candados de bonos por persona. */
declare(strict_types=1);

/**
 * El bot recibe el tipo de bono y la clase de señal, nunca el usuario de la
 * otra cuenta. Aun el mismo comprobante acredita uso duplicado, no titularidad
 * de la cuenta; un agente debe verificar identidad antes de compartir acceso.
 */
function chatbot_bloque_bonos_duplicados(PDO $pdo, string $usuario): string
{
    $usuario = trim($usuario);
    if ($usuario === '' || !function_exists('vin_relacionados')) { return ''; }
    try {
        $rel = vin_relacionados($pdo, $usuario);
        if (!$rel) { return ''; }

        $anteriores = [];
        $st = $pdo->prepare(
            "SELECT DISTINCT origen FROM movimientos
              WHERE usuario = ? AND origen IN ('bono_bienvenida','bono_app') AND monto > 0"
        );
        foreach ($rel as $v) {
            $otro = trim((string)($v['usuario'] ?? ''));
            if ($otro === '') { continue; }
            $st->execute([$otro]);
            $tipos = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$tipos) { continue; }
            $anteriores[] = ['tipos' => $tipos, 'senales' => $v['senales'] ?? []];
        }

        // La promo de app también frena pagos repetidos desde el mismo aparato.
        $stDev = $pdo->prepare(
            "SELECT DISTINCT o.usuario
               FROM dispositivos_usuarios d
               JOIN dispositivos_usuarios o
                 ON o.device_id = d.device_id AND o.usuario <> d.usuario
               JOIN movimientos m
                 ON m.usuario = o.usuario AND m.origen = 'bono_app' AND m.monto > 0
              WHERE d.usuario = ? LIMIT 20"
        );
        $stDev->execute([$usuario]);
        $devPrevios = $stDev->fetchAll(PDO::FETCH_COLUMN);

        $tiposConComprobante = [];
        $tiposSinComprobante = [];
        foreach ($anteriores as $a) {
            foreach ($a['tipos'] as $tipo) {
                // Bienvenida se valida por señales de pago; app también por aparato.
                if ($tipo === 'bono_bienvenida'
                    && !array_intersect($a['senales'], ['pago', 'comprobante'])) { continue; }
                $etiqueta = $tipo === 'bono_app' ? 'bono por instalar la app' : 'bono de bienvenida';
                if (in_array('comprobante', $a['senales'], true)) {
                    $tiposConComprobante[$etiqueta] = true;
                } else {
                    $tiposSinComprobante[$etiqueta] = true;
                }
            }
        }
        if ($devPrevios) { $tiposSinComprobante['bono por instalar la app'] = true; }
        if (!$tiposConComprobante && !$tiposSinComprobante) { return ''; }

        $p = "\n- BONOS YA COBRADOS EN OTRA CUENTA: la base confirmó que no se debe volver a acreditar el bono indicado. "
           . "Si pregunta, explicá que es un beneficio por persona y que el registro muestra que ya se otorgó; no prometas un segundo pago. No compartas ni insinúes nombres de otras cuentas. ";
        foreach (array_keys($tiposConComprobante) as $tipo) {
            $p .= 'Para ' . $tipo . ', se declaró el mismo comprobante en otra cuenta y el bono figura pagado. Esto confirma el uso duplicado del comprobante, pero no confirma quién es titular de esa otra cuenta. No reveles el usuario; ofrecé que un agente revise la identidad y ayude a recuperar el acceso si corresponde. ';
        }
        foreach (array_keys($tiposSinComprobante) as $tipo) {
            $p .= 'Para ' . $tipo . ', el control encontró un vínculo de pago o de dispositivo y el bono ya figura pagado en una cuenta vinculada, pero no confirma titularidad. No reveles ni inventes nombres; si cree que es un error, ofrecé que un agente revise el caso. ';
        }
        $p .= 'Nunca afirmes que conocemos o podemos recuperar la contraseña de otra cuenta: las contraseñas no son legibles por el sistema. Indicá una recuperación solo si el sistema confirmó que existe; si no, derivá a un agente.';
        return $p;
    } catch (Throwable $e) {
        error_log('chatbot_bloque_bonos_duplicados: ' . $e->getMessage());
        return '';
    }
}
