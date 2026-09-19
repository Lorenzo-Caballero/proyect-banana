<?php
/**
 * retencion_lib.php — ¿Los jugadores que traemos se quedan?
 *
 * EL PEDIDO (Nahuel, 19/09/2026): *"nuestro negocio se basa en la acumulación
 * de clientes. Cuando empezamos a usar publicidad hay un aumento de registros y
 * de cargas. Esos clientes que vamos ganando es muy importante mantenerlos.
 * Intertemporalmente también vamos perdiendo clientes, así que tenemos que
 * hacer que esa pérdida sea de una tasa lo más baja posible"*.
 *
 * Eso son DOS preguntas distintas y acá se contestan por separado:
 *
 *   COHORTES  de los que llegaron en marzo, ¿cuántos seguían en abril, en mayo,
 *             en junio? Mide la CALIDAD de lo que trae la publicidad: si la
 *             curva de enero cae más rápido que la de diciembre, el problema no
 *             es cuánta gente entra sino qué gente entra.
 *
 *   MES A MES ¿cuántos activos tengo, cuántos perdí y cuántos recuperé? Mide la
 *             SALUD de ahora. Ya existía algo así en Finanzas (fn_retencion),
 *             que compara un período contra el anterior; acá se abre en las
 *             tres piezas que se pueden accionar por separado.
 *
 * ---------------------------------------------------------------------------
 * QUÉ CUENTA COMO "ESTUVO ACTIVO", Y POR QUÉ NO ES `publicidad_sql_cargas()`
 * ---------------------------------------------------------------------------
 * CLAUDE.md es terminante: `publicidad_sql_cargas()` es LA definición de "una
 * carga" y no se escribe otra. Eso se respeta — para la PLATA sigue siendo la
 * única, y acá no se suma un peso.
 *
 * Pero la retención no pregunta cuánta plata entró: pregunta si la persona
 * seguía ahí. Y con la definición de plata, la historia no existe: al
 * 19/09/2026 cubría 70 cargas y 3 semanas, porque `recargas` y `movimientos`
 * empezaron a llenarse recién este año. El libro del panel, en cambio, tiene
 * 11 meses.
 *
 * Así que "estuvo activo en el mes M" es la UNIÓN de dos cosas, sin sumar
 * montos (por eso no hay riesgo de contar dos veces):
 *
 *   1. una carga de `publicidad_sql_cargas()`;
 *   2. un depósito del libro NACIDO DE UN PEDIDO DEL JUGADOR.
 *
 * LO SEGUNDO NECESITA EL FILTRO POR `comentario` Y ES LA PARTE DELICADA. El
 * libro trae dos clases de depósito y se distinguen por ahí:
 *
 *   comentario = ''                el jugador pidió cargar y se ejecutó
 *   comentario = 'direct deposit'  lo hizo el agente directo -- y eso incluye
 *                                  A NUESTRO PROPIO WORKER metiendo al juego
 *                                  fichas que el jugador YA había pagado
 *
 * Sin ese filtro, cada carga por transferencia aparece dos veces (una como
 * `recargas` y otra como el depósito que hace `rl_cargar_al_juego_auto`).
 * Medido en septiembre de 2026: el libro entero daba $624.399 contra $361.380
 * de la definición canónica, casi el doble, por exactamente ese motivo.
 *
 * ---------------------------------------------------------------------------
 * LÍMITES QUE CONVIENE SABER ANTES DE LEER UN NÚMERO
 * ---------------------------------------------------------------------------
 * · El libro se llena con un backfill. Un mes anterior al backfill figura en
 *   CERO, y cero no es un dato: es una ausencia. `ret_cubre_desde()` contesta
 *   desde cuándo hay libro y las pantallas tienen que decirlo.
 * · Un cliente nuevo no tiene `operaciones_panel` (migración 67). Todo degrada
 *   a la definición canónica sola: menos historia, ningún error.
 * · Las cohortes de los últimos meses están INCOMPLETAS por construcción: la
 *   de este mes todavía no puede tener un "+1 mes". Se devuelven con
 *   `completa=false` para que la pantalla no las dibuje como una caída.
 */

declare(strict_types=1);

if (!function_exists('ret_hay_libro')) {

    /** ¿Existe el libro del panel en esta base? (migración 67) */
    function ret_hay_libro(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (isset($cache[$k])) { return $cache[$k]; }
        try {
            $pdo->query("SELECT 1 FROM operaciones_panel LIMIT 0");
            return $cache[$k] = true;
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }

    /**
     * Desde cuándo hay datos para medir. Devuelve ['desde' => 'YYYY-MM', ...].
     *
     * No es cosmético: sin esto, una cohorte anterior al backfill se dibuja como
     * "entraron 200 y se fueron todos", cuando lo que pasó es que no tenemos el
     * dato. La pantalla tiene que poder decir hasta dónde mira.
     */
    function ret_cubre_desde(PDO $pdo): array
    {
        $out = ['libro' => null, 'cargas' => null, 'desde' => null];
        if (ret_hay_libro($pdo)) {
            try {
                $v = $pdo->query("SELECT MIN(cuando) FROM operaciones_panel WHERE tipo = 0")
                         ->fetchColumn();
                if ($v) { $out['libro'] = substr((string)$v, 0, 7); }
            } catch (Throwable $e) { /* se sigue sin el dato */ }
        }
        if (function_exists('publicidad_sql_cargas')) {
            try {
                $c = publicidad_sql_cargas();
                $v = $pdo->query("SELECT MIN(cuando) FROM ($c) x")->fetchColumn();
                if ($v) { $out['cargas'] = substr((string)$v, 0, 7); }
            } catch (Throwable $e) { /* idem */ }
        }
        $ms = array_filter([$out['libro'], $out['cargas']]);
        $out['desde'] = $ms ? min($ms) : null;
        return $out;
    }

    /**
     * LA definición de "estuvo activo", como SQL que devuelve (usuario, mes).
     *
     * Una fila por jugador y por mes en que hubo actividad — ya viene con
     * DISTINCT, así que un jugador que cargó quince veces en marzo aparece una
     * sola vez. Eso es lo que hace que unir las dos fuentes no cuente doble.
     *
     * `usuario` se normaliza a minúsculas: `usuarios` está en uca1400 (que no
     * distingue mayúsculas) y las tablas del CRM en utf8mb4_unicode_ci, así que
     * sin esto el mismo jugador puede salir dos veces con distinta caja y
     * arruinar la cohorte entera.
     */
    function ret_sql_actividad(PDO $pdo): string
    {
        $partes = [];
        if (function_exists('publicidad_sql_cargas')) {
            $c = publicidad_sql_cargas();
            $partes[] = "SELECT LOWER(x.usuario) AS usuario,
                                DATE_FORMAT(x.cuando, '%Y-%m') AS mes
                           FROM ($c) x
                          WHERE x.usuario IS NOT NULL AND x.usuario <> ''";
        }
        if (ret_hay_libro($pdo)) {
            /* SOLO los nacidos de un pedido del jugador: ver el encabezado. Un
               'direct deposit' es el agente (o nuestro worker) cargando
               directo, y contarlo duplicaría cada transferencia. */
            $partes[] = "SELECT LOWER(o.username COLLATE utf8mb4_unicode_ci) AS usuario,
                                DATE_FORMAT(o.cuando, '%Y-%m') AS mes
                           FROM operaciones_panel o
                          WHERE o.tipo = 0
                            AND COALESCE(o.comentario, '') = ''
                            AND o.username IS NOT NULL AND o.username <> ''";
        }
        if (!$partes) {
            // Base sin ninguna de las dos fuentes: una consulta vacía válida,
            // para que quien llama no tenga que preguntar si hay datos.
            return "SELECT NULL AS usuario, NULL AS mes FROM DUAL WHERE 1 = 0";
        }
        return "SELECT DISTINCT usuario, mes FROM (" . implode(' UNION ALL ', $partes) . ") u";
    }

    /**
     * La tabla de cohortes: por mes de llegada, cuántos seguían N meses después.
     *
     * "Mes de llegada" = el mes de su PRIMERA actividad, no el del alta. Un
     * jugador que se registró y nunca cargó no entró al negocio; contarlo
     * hundiría la retención de su cohorte por algo que no es retención.
     *
     * $meses: cuántos meses hacia atrás mirar (12 por defecto).
     */
    function ret_cohortes(PDO $pdo, int $meses = 12): array
    {
        $meses = max(2, min(36, $meses));
        $act = ret_sql_actividad($pdo);
        $hoy = date('Y-m');

        try {
            $st = $pdo->prepare(
                "SELECT p.cohorte, a.mes,
                        COUNT(DISTINCT a.usuario) AS jugadores
                   FROM (SELECT usuario, MIN(mes) AS cohorte FROM ($act) t GROUP BY usuario) p
                   JOIN ($act) a ON a.usuario = p.usuario
                  WHERE p.cohorte >= DATE_FORMAT(DATE_SUB(?, INTERVAL ? MONTH), '%Y-%m')
                  GROUP BY p.cohorte, a.mes
                  ORDER BY p.cohorte, a.mes"
            );
            $st->execute([$hoy . '-01', $meses]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('ret_cohortes: ' . $e->getMessage());
            return ['cohortes' => [], 'max_periodos' => 0];
        }

        /* Se arma en PHP y no con más SQL: la resta de meses en SQL es
           farragosa y esto son unas pocas decenas de filas. */
        $coh = [];
        foreach ($filas as $f) {
            $c = (string)$f['cohorte'];
            $n = ret_meses_entre($c, (string)$f['mes']);
            if ($n < 0) { continue; }                 // no puede pasar, pero no se asume
            $coh[$c][$n] = (int)$f['jugadores'];
        }

        $out = [];
        $maxP = 0;
        foreach ($coh as $c => $periodos) {
            $base = $periodos[0] ?? 0;
            if ($base === 0) { continue; }            // sin mes cero no hay cohorte
            $posibles = ret_meses_entre($c, $hoy);
            $serie = [];
            for ($i = 0; $i <= $posibles; $i++) {
                $v = $periodos[$i] ?? 0;
                $serie[] = ['periodo' => $i, 'jugadores' => $v,
                            'pct' => round($v * 100 / $base, 1)];
            }
            $maxP = max($maxP, $posibles);
            $out[] = [
                'cohorte'   => $c,
                'nuevos'    => $base,
                'serie'     => $serie,
                /* El mes en curso no terminó: su último punto va a subir. Sin
                   esta marca, la pantalla lo dibuja como una caída que no
                   existe -- el error más fácil de cometer leyendo cohortes. */
                'en_curso'  => ($c === $hoy),
                'completa'  => ($posibles >= 1),
            ];
        }
        return ['cohortes' => $out, 'max_periodos' => $maxP];
    }

    /** Meses enteros entre dos 'YYYY-MM'. */
    function ret_meses_entre(string $desde, string $hasta): int
    {
        [$a1, $m1] = array_map('intval', explode('-', $desde));
        [$a2, $m2] = array_map('intval', explode('-', $hasta));
        return ($a2 - $a1) * 12 + ($m2 - $m1);
    }

    /**
     * La foto del mes: activos, nuevos, perdidos y recuperados.
     *
     * Las cuatro piezas se accionan distinto y por eso van separadas:
     *   nuevos       lo trae la publicidad
     *   retenidos    los que ya estaban y siguieron
     *   perdidos     estaban el mes pasado y este no -> es a los que apunta la
     *                campaña de fidelización
     *   recuperados  no estaban el mes pasado pero sí antes, y volvieron
     *
     * `perdidos` es el número que el dueño quiere ver bajar.
     */
    function ret_mes_a_mes(PDO $pdo, int $meses = 12): array
    {
        $meses = max(2, min(36, $meses));
        $act = ret_sql_actividad($pdo);
        try {
            $st = $pdo->prepare(
                "SELECT mes, COUNT(DISTINCT usuario) AS activos
                   FROM ($act) t
                  WHERE mes >= DATE_FORMAT(DATE_SUB(?, INTERVAL ? MONTH), '%Y-%m')
                  GROUP BY mes ORDER BY mes"
            );
            $st->execute([date('Y-m') . '-01', $meses]);
            $activos = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $activos[(string)$f['mes']] = (int)$f['activos'];
            }

            // Quién estuvo en cada mes, para poder cruzar los conjuntos.
            $st2 = $pdo->prepare(
                "SELECT mes, usuario FROM ($act) t
                  WHERE mes >= DATE_FORMAT(DATE_SUB(?, INTERVAL ? MONTH), '%Y-%m')"
            );
            $st2->execute([date('Y-m') . '-01', $meses + 1]);
            $porMes = [];
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $porMes[(string)$f['mes']][(string)$f['usuario']] = true;
            }
        } catch (Throwable $e) {
            error_log('ret_mes_a_mes: ' . $e->getMessage());
            return [];
        }

        $vistos = [];              // todos los que aparecieron alguna vez, acumulado
        $out = [];

        /* SE RECORRE UN RANGO CONTINUO DE MESES, no solo los que tienen filas.
           Un mes sin un solo jugador activo NO es un mes que no existe: es el
           dato más fuerte de todos. Saltearlo hacía dos cosas mal -- lo escondía
           de la serie, y dejaba que el mes siguiente se comparara contra el
           anterior CON datos, de modo que la pérdida aparecía tarde y repartida.
           En esta base pasaba con abril de 2026: cero actividad, y mayo se
           comparaba contra marzo. */
        $mesesConDatos = array_keys($porMes);
        sort($mesesConDatos);
        if (!$mesesConDatos) { return []; }
        $mesesOrden = [];
        $cur = $mesesConDatos[0];
        $fin = date('Y-m');
        while ($cur <= $fin) {
            $mesesOrden[] = $cur;
            $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
        }

        $prev = null;
        foreach ($mesesOrden as $m) {
            $hoyU  = $porMes[$m] ?? [];
            $prevU = $prev !== null ? ($porMes[$prev] ?? []) : [];

            $nuevos = $retenidos = $recuperados = 0;
            foreach ($hoyU as $u => $_) {
                if (isset($prevU[$u]))      { $retenidos++; }
                elseif (isset($vistos[$u])) { $recuperados++; }
                else                        { $nuevos++; }
            }
            $perdidos = 0;
            foreach ($prevU as $u => $_) { if (!isset($hoyU[$u])) { $perdidos++; } }

            $base = count($prevU);
            /* El primer mes de la serie no tiene contra qué compararse: sus
               "nuevos" son todos los que había, y su pérdida es desconocida, no
               cero. Se marca para que la pantalla no dibuje un 0% que parece un
               logro. */
            $out[] = [
                'mes'          => $m,
                'activos'      => count($hoyU),
                'primero'      => ($prev === null),
                'nuevos'       => $nuevos,
                'retenidos'    => $retenidos,
                'recuperados'  => $recuperados,
                'perdidos'     => $perdidos,
                /* La tasa de pérdida es sobre los del mes ANTERIOR, que es la
                   base de la que se podía perder. Sobre los de este mes daría
                   un número que sube cuando entra gente nueva, que es lo
                   contrario de lo que significa. */
                'pct_perdidos' => $base > 0 ? round($perdidos * 100 / $base, 1) : null,
                'en_curso'     => ($m === date('Y-m')),
                /* Con cuatro jugadores, "perdí el 50%" son dos personas y no
                   dice nada del negocio. La pantalla tiene que poder mostrar el
                   crudo en vez del porcentaje. */
                'poca_muestra' => ($base > 0 && $base < 10),
            ];
            $vistos += $hoyU;
            $prev = $m;
        }
        return $out;
    }
}
