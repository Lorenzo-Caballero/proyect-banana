<?php
/**
 * referidos_lib.php — Plan de referidos (estilo Temu).
 *
 * El circuito completo, para leerlo una vez y no reconstruirlo con grep:
 *
 *   1. El CRM manda la difusion del plan (crm.php ?accion=referidos_difundir):
 *      a cada cliente le llega por chat SU link, con SU codigo adentro
 *      (bono.html?ref=<codigo>). El codigo se genera aca, una vez, y no
 *      cambia mas: el link ya compartido tiene que seguir valiendo.
 *   2. El amigo abre el link. bono.html arrastra el ?ref= al POST de
 *      crear_cuenta.php, que lo valida (codigo existente, plan activo) y lo
 *      guarda en altas.ref_codigo. El alta sigue su vida normal.
 *   3. Cuando al amigo se le acredita su PRIMERA carga, los tres caminos que
 *      acreditan (rl_acreditar, rl_acreditar_directo, hg_webhook) llaman a
 *      ref_pagar_por_primera_carga(): resuelve quien lo trajo y le suma el
 *      bono configurado en el CRM (config_crm.ref_bono_monto) en
 *      usuarios.bonus, con su fila en `movimientos` y un push.
 *
 * EL CANDADO DE PAGO es la UNIQUE de referidos.referido (migracion 53): se
 * inserta la fila ANTES de acreditar nada, igual que el movimiento
 * 'bono_bienvenida'. Dos acreditaciones casi simultaneas del mismo amigo
 * chocan ahi y paga una sola.
 *
 * QUE NO HACE, a proposito:
 *   - No paga al REFERIDO: el amigo ya cobra su bono de bienvenida por
 *     bono.html; esto premia al que lo trajo. Son dos promesas distintas.
 *   - No paga retroactivo: si el plan estaba apagado cuando el amigo se
 *     registro, ese alta no tiene ref_codigo y no genera pago nunca.
 *   - El monto se lee AL MOMENTO DE PAGAR, no al registrarse: si el dueño
 *     cambia el bono, los pagos futuros salen con el valor nuevo. Es lo que
 *     significa "editable desde el CRM".
 *
 * Solo define funciones. Nunca lanza hacia afuera, salvo el deadlock dentro
 * de transaccion (mismo criterio y mismo porque que rl_bono_bienvenida_aplicar).
 */

declare(strict_types=1);

require_once __DIR__ . '/config_crm.php';   // cfg_crm(); solo define, no ejecuta

if (!function_exists('ref_codigo_de')) {
    /**
     * El codigo de referido de un cliente. Lo crea si no existe.
     *
     * 8 caracteres a-z0-9 al azar (~2.8e12 combinaciones): imposible de
     * adivinar recorriendo, corto para un link de WhatsApp. Se reintenta si
     * choca la UNIQUE, que con ese espacio es anecdotico.
     *
     * Devuelve '' si no se pudo (tabla sin migrar): el caller decide si eso
     * es un error o un "todavia no".
     */
    function ref_codigo_de(PDO $pdo, string $usuario): string
    {
        $usuario = mb_substr(trim($usuario), 0, 80);
        if ($usuario === '') { return ''; }

        try {
            $st = $pdo->prepare("SELECT codigo FROM referidos_codigos WHERE usuario = ? LIMIT 1");
            $st->execute([$usuario]);
            $c = (string)$st->fetchColumn();
            if ($c !== '') { return $c; }

            $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
            for ($i = 0; $i < 5; $i++) {
                $c = '';
                for ($j = 0; $j < 8; $j++) { $c .= $abc[random_int(0, 35)]; }
                try {
                    $pdo->prepare("INSERT INTO referidos_codigos (usuario, codigo) VALUES (?, ?)")
                        ->execute([$usuario, $c]);
                    return $c;
                } catch (PDOException $e) {
                    if ((string)$e->getCode() !== '23000') { throw $e; }
                    // Choco una UNIQUE. Si fue la de usuario es que OTRO
                    // pedido nos gano de mano creando el codigo de esta misma
                    // persona (la difusion corre en lote): se relee y listo.
                    $st->execute([$usuario]);
                    $ya = (string)$st->fetchColumn();
                    if ($ya !== '') { return $ya; }
                    // Fue la de codigo: otra vuelta con otro azar.
                }
            }
        } catch (Throwable $e) {
            error_log('ref_codigo_de(' . $usuario . '): ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('ref_usuario_de_codigo')) {
    /** Quien es el dueño de un codigo. '' si el codigo no existe. */
    function ref_usuario_de_codigo(PDO $pdo, string $codigo): string
    {
        $codigo = strtolower(trim($codigo));
        if (!preg_match('/^[a-z0-9]{4,16}$/', $codigo)) { return ''; }
        try {
            $st = $pdo->prepare("SELECT usuario FROM referidos_codigos WHERE codigo = ? LIMIT 1");
            $st->execute([$codigo]);
            return (string)$st->fetchColumn();
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('ref_link')) {
    /**
     * El link que se comparte. Apunta a bono.html A PROPOSITO: el amigo que
     * entra por un referido tambien se lleva el bono de bienvenida -- ese es
     * el gancho que hace que el link valga la pena compartirse.
     *
     * El host sale del request (multi-cliente: cada agencia su dominio),
     * mismo criterio que crm_cobro.php.
     */
    function ref_link(string $codigo): string
    {
        $host = (string)($GLOBALS['TENANT_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'ganamoscrm.online');
        return 'https://' . $host . '/bono.html?ref=' . rawurlencode($codigo);
    }
}

if (!function_exists('ref_pagar_por_primera_carga')) {
    /**
     * Paga el bono al que trajo a este jugador, si corresponde. Se llama en
     * cada acreditacion de PRIMERA carga (el "es primera" lo decide el
     * caller, igual que con el bono de bienvenida).
     *
     * Corresponde cuando: el alta CONFIRMADA del jugador tiene ref_codigo,
     * el codigo resuelve a un cliente real distinto de el, el monto
     * configurado es > 0, y nadie pago por este amigo todavia (UNIQUE).
     *
     * El INSERT en `referidos` va PRIMERO, antes de sumar el bonus: si algo
     * se rompe en el medio queda el candado puesto y un log con nombres.
     * Deberle un bono a alguien es recuperable (se carga a mano desde el
     * CRM); pagarlo dos veces no. Mismo orden que rl_bono_bienvenida_aplicar.
     *
     * Devuelve lo pagado (0 si no correspondia). No lanza, salvo deadlock
     * dentro de transaccion -- ver el porque en rl_bono_bienvenida_aplicar:
     * tragarlo dejaria al caller creyendo que acredito una recarga que
     * InnoDB ya revirtio entera.
     */
    function ref_pagar_por_primera_carga(PDO $pdo, string $usuario): int
    {
        try {
            $usuario = mb_substr(trim($usuario), 0, 80);
            if ($usuario === '') { return 0; }

            // Solo un alta CONCRETADA, igual que el bono de bienvenida: una
            // fila zombie en 'error' no puede pagarle a nadie.
            $sa = $pdo->prepare(
                "SELECT ref_codigo FROM altas WHERE usuario = ? AND estado = 'ok'
                  ORDER BY id DESC LIMIT 1"
            );
            $sa->execute([$usuario]);
            $codigo = (string)$sa->fetchColumn();
            if ($codigo === '') { return 0; }

            $referidor = ref_usuario_de_codigo($pdo, $codigo);
            if ($referidor === '' || $referidor === $usuario) { return 0; }

            /* El monto se lee AHORA, no al registrarse: editable desde el CRM
               significa que manda el valor del momento del pago. Con 0 no se
               paga -- es el interruptor de facto del plan para pagos nuevos. */
            $monto = (int)round((float)cfg_crm($pdo, 'ref_bono_monto'));
            if ($monto <= 0) { return 0; }

            // El referidor tiene que seguir existiendo como jugador: el bono
            // va a usuarios.bonus y una cuenta baneada no cobra premios.
            $su = $pdo->prepare(
                "SELECT 1 FROM usuarios WHERE username = ? AND COALESCE(is_banned,0) = 0 LIMIT 1"
            );
            $su->execute([$referidor]);
            if (!$su->fetchColumn()) {
                error_log('referidos: ' . $referidor . ' refirio a ' . $usuario
                    . ' pero no esta (o esta baneado) en usuarios: bono NO pagado');
                return 0;
            }

            // EL CANDADO. Si ya hay fila para este referido, ya se pago.
            try {
                $pdo->prepare(
                    "INSERT INTO referidos (referido, referidor, bono) VALUES (?, ?, ?)"
                )->execute([$usuario, $referidor, $monto]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') { return 0; }
                throw $e;
            }

            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'bono', ?, ?, 'bono_referido')"
            )->execute([
                mb_substr($referidor, 0, 50),
                $monto,
                'Bono por traer a ' . $usuario . ' (hizo su primera carga)',
            ]);

            $pdo->prepare("UPDATE usuarios SET bonus = bonus + ? WHERE username = ?")
                ->execute([$monto, $referidor]);

            if (function_exists('notif_crear')) {
                notif_crear(
                    $pdo,
                    $referidor,
                    '🎉 ¡Tu amigo ya juega!',
                    $usuario . ' hizo su primera carga y te ganaste '
                        . number_format($monto, 0, ',', '.')
                        . ' en bonos por haberlo invitado. ¡Segui compartiendo tu link!',
                    'bono',
                    null,
                    'referidos'
                );
            }
            return $monto;
        } catch (Throwable $e) {
            if ($pdo->inTransaction() && $e instanceof PDOException
                && ((string)($e->errorInfo[0] ?? '') === '40001'
                    || (int)($e->errorInfo[1] ?? 0) === 1213)) {
                throw $e;   // deadlock: que el caller haga SU rollback
            }
            // Cualquier otra cosa (tabla sin migrar, etc.): la recarga del
            // amigo ya quedo acreditada y eso no se toca. El bono debido
            // queda en el log.
            error_log('ref_pagar_por_primera_carga(' . $usuario . '): ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('ref_resumen')) {
    /**
     * Los numeros del plan para el CRM: cuantos compartieron, cuantos amigos
     * entraron, cuantos ya cargaron y cuanto se pago en bonos.
     */
    function ref_resumen(PDO $pdo): array
    {
        $r = ['codigos' => 0, 'registrados' => 0, 'pagados' => 0, 'bonos_pagados' => 0];
        try {
            $r['codigos'] = (int)$pdo->query("SELECT COUNT(*) FROM referidos_codigos")->fetchColumn();
            $r['registrados'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM altas WHERE ref_codigo IS NOT NULL AND estado = 'ok'"
            )->fetchColumn();
            $p = $pdo->query("SELECT COUNT(*), COALESCE(SUM(bono),0) FROM referidos")->fetch(PDO::FETCH_NUM);
            $r['pagados'] = (int)($p[0] ?? 0);
            $r['bonos_pagados'] = (int)($p[1] ?? 0);
        } catch (Throwable $e) { /* sin migrar: ceros */ }
        return $r;
    }
}
