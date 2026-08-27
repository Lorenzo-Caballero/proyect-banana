<?php
/**
 * publicidad_lib.php — Publicistas, su gasto diario, y el embudo de Meta Ads
 * por debajo del pixel unico de agencia (meta_lib.php / config_crm).
 *
 * Un cliente/agencia tiene UN pixel general (config_crm.meta_pixel_id). Este
 * archivo agrega una capa opcional por-debajo: varios publicistas, cada uno
 * con su propia landing (registro.html?pub=<slug>) y, si quiere, su propio
 * pixel/token. Un publicista sin pixel propio no deja de reportar -- cae al
 * pixel general, ver publicidad_pixel_para().
 *
 * Todo lo de aca es best-effort: si la migracion 44 no corrio, las funciones
 * devuelven vacio/null y quien las llama sigue andando (mismo criterio que
 * config_crm.php y meta_lib.php).
 *
 * Requiere sql/44_publicidad.sql.
 */

declare(strict_types=1);

/**
 * El publicista activo por su slug, o null si no existe / esta apagado /
 * falta la migracion. Lo usan crear_cuenta.php (para asociar el alta) y
 * meta_config.php (para decirle al browser que pixel cargar).
 */
function publicidad_por_slug(PDO $pdo, string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            "SELECT id, nombre, slug, pixel_id, capi_token, activo
               FROM publicistas
              WHERE slug = ? AND activo = 1
              LIMIT 1"
        );
        $st->execute([$slug]);
        $fila = $st->fetch();
        return $fila ?: null;
    } catch (Throwable $e) {
        error_log('publicidad: no pude leer publicistas (¿falta la migración 44?): ' . $e->getMessage());
        return null;
    }
}

/** Un publicista por id. Igual de tolerante que publicidad_por_slug(). */
function publicidad_por_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            "SELECT id, nombre, slug, pixel_id, capi_token, activo
               FROM publicistas WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $fila = $st->fetch();
        return $fila ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Un publicista CON sus credenciales de Insights incluidas. Separada de
 * publicidad_por_id() a propósito: esa la usan altas_cola.php/recargas_lib.php
 * (mandan eventos) y no necesitan leer un token que no van a usar; esta la
 * usa crm_publicidad.php (backend, nunca se expone al frontend) para poder
 * llamar meta_insights_pageviews().
 */
function publicidad_con_insights(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            "SELECT id, nombre, slug, pixel_id, insights_token, insights_ad_account, activo
               FROM publicistas WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $fila = $st->fetch();
        return $fila ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Que pixel_id/capi_token usar para un publicista dado: el propio si lo
 * configuro, o null si tiene que caer al pixel general de config_crm (eso lo
 * decide el caller, esta funcion no conoce config_crm a proposito -- separa
 * "de que publicista es esto" de "con que pixel se manda").
 *
 * Devuelve ['pixel_id' => string, 'capi_token' => string] o null si el
 * publicista no tiene los dos cargados (pixel sin token, o al reves, no
 * sirve para mandar nada).
 */
function publicidad_pixel_propio(?array $publicista): ?array
{
    if (!$publicista) {
        return null;
    }
    $pixel = trim((string)($publicista['pixel_id']   ?? ''));
    $token = trim((string)($publicista['capi_token']  ?? ''));
    if ($pixel === '' || $token === '') {
        return null;
    }
    return ['pixel_id' => $pixel, 'capi_token' => $token];
}

/**
 * Todos los publicistas, mas recientes primero. Para el CRM (tabs + admin).
 */
function publicidad_listar(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT id, nombre, slug, pixel_id,
                    (capi_token IS NOT NULL AND capi_token <> '') AS tiene_token,
                    (insights_token IS NOT NULL AND insights_token <> '') AS tiene_insights_token,
                    insights_ad_account,
                    activo, creado_en
               FROM publicistas
              ORDER BY activo DESC, nombre ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('publicidad: no pude listar publicistas (¿falta la migración 44?): ' . $e->getMessage());
        return [];
    }
}

/**
 * Alta o edicion de un publicista. $id null = nuevo. Devuelve el id, o 0 si
 * fallo (nombre vacio).
 *
 * El slug del link (?pub=<slug>) SIEMPRE se genera acá como un numero
 * aleatorio, nunca a partir del nombre: si el link llevara el nombre del
 * publicista (ej. ?pub=juan-perez), cualquiera que vea un anuncio sabe quien
 * lo maneja. Tampoco es el id autoincremental de la tabla -- eso revelaria
 * cuantos publicistas tiene la cuenta. Un alta nueva siempre saca slug
 * nuevo; una edicion NUNCA lo toca (cambiar el slug rompería un link que ya
 * esta circulando en anuncios activos).
 */
function publicidad_slug_nuevo(PDO $pdo): string
{
    // 6 digitos: 900.000 combinaciones, de sobra para que un choque sea
    // improbable, y el UNIQUE de la tabla lo garantiza igual si pasara.
    for ($intento = 0; $intento < 20; $intento++) {
        $slug = (string)random_int(100000, 999999);
        $st = $pdo->prepare("SELECT 1 FROM publicistas WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
    }
    // Extremadamente improbable (20 intentos fallando todos): timestamp
    // como ultimo recurso, unico por definicion.
    return (string)time();
}

function publicidad_guardar(PDO $pdo, ?int $id, string $nombre,
                             string $pixelId, string $capiToken, bool $activo,
                             string $insightsToken = '', string $insightsAdAccount = ''): int
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return 0;
    }

    $pixelId   = trim($pixelId)   !== '' ? mb_substr(trim($pixelId), 0, 40) : null;
    $capiToken = trim($capiToken) !== '' ? trim($capiToken) : null;
    // Access token de la Marketing API para leer "Visitas de página" (ver
    // meta_insights_pageviews()). Se acepta con o sin el prefijo "act_" en
    // la cuenta -- es facil que falte si se copia del selector de Meta.
    $insightsToken = trim($insightsToken) !== '' ? trim($insightsToken) : null;
    $insightsAdAccount = trim($insightsAdAccount);
    if ($insightsAdAccount !== '' && strpos($insightsAdAccount, 'act_') !== 0) {
        $insightsAdAccount = 'act_' . $insightsAdAccount;
    }
    $insightsAdAccount = $insightsAdAccount !== '' ? mb_substr($insightsAdAccount, 0, 32) : null;

    try {
        if ($id) {
            // Al EDITAR, un campo vacio significa "no tocar" -- se arma el
            // SET dinamicamente para no pisar con NULL lo que ya estaba
            // cargado. Necesario porque pixel_id/capi_token/insights_* son
            // obligatorios solo al CREAR (los exige crm_publicidad.php); en
            // una edicion el operador puede dejar cualquiera vacio sin
            // querer borrarlo -- capi_token/insights_token en particular
            // NUNCA vuelven al frontend por seguridad, asi que vacio ahi es
            // siempre "no lo cambies", nunca "borralo".
            //
            // Sin slug en el SET tampoco: editar un publicista NUNCA cambia
            // su link.
            $campos = ['nombre = ?'];
            $valores = [$nombre];
            foreach ([
                'pixel_id' => $pixelId, 'capi_token' => $capiToken,
                'insights_token' => $insightsToken, 'insights_ad_account' => $insightsAdAccount,
            ] as $col => $val) {
                if ($val !== null) {
                    $campos[] = "$col = ?";
                    $valores[] = $val;
                }
            }
            $campos[] = 'activo = ?';
            $valores[] = $activo ? 1 : 0;
            $valores[] = $id;

            $st = $pdo->prepare("UPDATE publicistas SET " . implode(', ', $campos) . " WHERE id = ?");
            $st->execute($valores);
            return $id;
        }
        $slug = publicidad_slug_nuevo($pdo);
        $st = $pdo->prepare(
            "INSERT INTO publicistas (nombre, slug, pixel_id, capi_token, activo,
                                       insights_token, insights_ad_account)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([$nombre, $slug, $pixelId, $capiToken, $activo ? 1 : 0,
                       $insightsToken, $insightsAdAccount]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 0;   // choque improbable de slug (ver publicidad_slug_nuevo)
        }
        error_log('publicidad_guardar: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Pausa o reactiva un publicista SIN tocar nada mas (nombre, pixel, tokens).
 * Pausado: su link sigue existiendo y sigue funcionando -- solo deja de
 * asociarsele el tracking a los registros nuevos (ver publicidad_por_slug(),
 * que filtra activo=1). Nunca se borra la fila: el historial de ese
 * publicista (altas, recargas, gasto ya cargado) queda intacto siempre.
 *
 * Devuelve el nuevo estado (true=activo) o null si el publicista no existe.
 */
function publicidad_activo_toggle(PDO $pdo, int $id): ?bool
{
    if ($id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT activo FROM publicistas WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $actual = $st->fetchColumn();
        if ($actual === false) {
            return null;
        }
        $nuevo = ((int)$actual === 1) ? 0 : 1;
        $pdo->prepare("UPDATE publicistas SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
        return $nuevo === 1;
    } catch (Throwable $e) {
        error_log('publicidad_activo_toggle: ' . $e->getMessage());
        return null;
    }
}

/**
 * Carga/edita el gasto de UN dia de UN publicista. Upsert: cargar de nuevo el
 * mismo dia corrige el monto, no lo suma (el operador puede haberse
 * equivocado y quiere corregir, no acumular).
 */
function publicidad_gasto_guardar(PDO $pdo, int $publicistaId, string $fecha,
                                   float $monto, string $operador = ''): bool
{
    if ($publicistaId <= 0 || $fecha === '') {
        return false;
    }
    try {
        $pdo->prepare(
            "INSERT INTO gasto_diario (publicista_id, fecha, monto, operador)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE monto = VALUES(monto), operador = VALUES(operador)"
        )->execute([$publicistaId, $fecha, $monto, $operador !== '' ? $operador : null]);
        return true;
    } catch (Throwable $e) {
        error_log('publicidad_gasto_guardar: ' . $e->getMessage());
        return false;
    }
}

/** Gasto total de un publicista en [desde, hasta] (fechas 'Y-m-d', inclusive). */
function publicidad_gasto_periodo(PDO $pdo, int $publicistaId, string $desde, string $hasta): float
{
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(monto), 0) FROM gasto_diario
              WHERE publicista_id = ? AND fecha BETWEEN ? AND ?"
        );
        $st->execute([$publicistaId, $desde, $hasta]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** El gasto dia por dia de un publicista en el rango, para que el operador lo edite. */
function publicidad_gasto_dias(PDO $pdo, int $publicistaId, string $desde, string $hasta): array
{
    try {
        $st = $pdo->prepare(
            "SELECT fecha, monto FROM gasto_diario
              WHERE publicista_id = ? AND fecha BETWEEN ? AND ?
              ORDER BY fecha ASC"
        );
        $st->execute([$publicistaId, $desde, $hasta]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * El embudo de un publicista en [desde, hasta]: registros (altas creadas en
 * el rango que vinieron de este publicista), primeras cargas, cargas totales,
 * depositado real. Todo en una sola consulta por metrica, sin joins pesados.
 *
 * "En el rango" se aplica sobre la FECHA DEL EVENTO correspondiente
 * (pedido_en para registros, acreditada_en para cargas) -- no sobre cuando se
 * creo la cuenta. Un jugador que se registro el mes pasado y carga hoy cuenta
 * como carga de HOY, no arrastra el registro viejo al reporte de hoy.
 */
function publicidad_metricas(PDO $pdo, int $publicistaId, string $desde, string $hasta): array
{
    $hastaFin = $hasta . ' 23:59:59';
    $desdeIni = $desde . ' 00:00:00';

    $registros = 0;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM altas
              WHERE publicista_id = ? AND pedido_en BETWEEN ? AND ?"
        );
        $st->execute([$publicistaId, $desdeIni, $hastaFin]);
        $registros = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('publicidad_metricas (registros): ' . $e->getMessage());
    }

    // Cargas de jugadores que pertenecen a este publicista (por su alta),
    // acreditadas en el rango -- sin importar cuando se registraron.
    $primeras = 0; $totalCargas = 0; $depositado = 0.0;
    try {
        // Sin COLLATE: altas.usuario y recargas.usuario nacieron sin uno
        // explicito, asi que las dos caen al default del server (el mismo con
        // el que nacio usuarios.username, ver comentario en 13_cola_altas.sql)
        // y se comparan directo. Forzar utf8mb4_unicode_ci aca las desalinea.
        $st = $pdo->prepare(
            "SELECT
                SUM(r.es_primera = 1)               AS primeras,
                COUNT(*)                             AS total_cargas,
                COALESCE(SUM(r.monto_base), 0)       AS depositado
               FROM recargas r
               JOIN altas a ON a.usuario = r.usuario
              WHERE a.publicista_id = ?
                AND r.estado = 'acreditada'
                AND r.acreditada_en BETWEEN ? AND ?"
        );
        $st->execute([$publicistaId, $desdeIni, $hastaFin]);
        $fila = $st->fetch();
        $primeras    = (int)($fila['primeras'] ?? 0);
        $totalCargas = (int)($fila['total_cargas'] ?? 0);
        $depositado  = (float)($fila['depositado'] ?? 0);
    } catch (Throwable $e) {
        error_log('publicidad_metricas (cargas): ' . $e->getMessage());
    }

    // "Volvio a cargar": de los jugadores de este publicista que cargaron en
    // el rango, cuantos tienen mas de una recarga acreditada EN TOTAL (no
    // solo en el rango -- volver a cargar es un hecho del jugador, no del
    // periodo que este mirando el operador).
    $jugadoresConCarga = 0; $jugadoresQueVolvieron = 0;
    try {
        $st = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT r.usuario) AS con_carga,
                COUNT(DISTINCT CASE WHEN rep.total > 1 THEN r.usuario END) AS volvieron
               FROM recargas r
               JOIN altas a ON a.usuario = r.usuario
               JOIN (
                   SELECT usuario, COUNT(*) AS total
                     FROM recargas
                    WHERE estado = 'acreditada'
                    GROUP BY usuario
               ) rep ON rep.usuario = r.usuario
              WHERE a.publicista_id = ?
                AND r.estado = 'acreditada'
                AND r.acreditada_en BETWEEN ? AND ?"
        );
        $st->execute([$publicistaId, $desdeIni, $hastaFin]);
        $fila = $st->fetch();
        $jugadoresConCarga    = (int)($fila['con_carga'] ?? 0);
        $jugadoresQueVolvieron = (int)($fila['volvieron'] ?? 0);
    } catch (Throwable $e) {
        error_log('publicidad_metricas (retencion): ' . $e->getMessage());
    }

    $gasto = publicidad_gasto_periodo($pdo, $publicistaId, $desde, $hasta);

    return [
        'registros'          => $registros,
        'primeras_cargas'    => $primeras,
        'cargas_totales'     => $totalCargas,
        'depositado'         => round($depositado, 2),
        'gasto'              => round($gasto, 2),
        'jugadores_con_carga'    => $jugadoresConCarga,
        'jugadores_volvieron'    => $jugadoresQueVolvieron,
    ];
}

/**
 * Registros y primeras cargas de un publicista, día por día en [desde,
 * hasta], para la tabla del CRM. Dos consultas (registros por fecha de
 * pedido, cargas por fecha de acreditación) unidas en PHP por fecha -- más
 * simple y más claro que un UNION/JOIN de dos granularidades distintas en
 * SQL, y el rango de un reporte es corto (semanas, no años).
 */
function publicidad_por_dia(PDO $pdo, int $publicistaId, string $desde, string $hasta): array
{
    $hastaFin = $hasta . ' 23:59:59';
    $desdeIni = $desde . ' 00:00:00';

    $porDia = [];
    for ($d = strtotime($desde); $d <= strtotime($hasta); $d += 86400) {
        $f = date('Y-m-d', $d);
        $porDia[$f] = ['fecha' => $f, 'registros' => 0, 'primeras_cargas' => 0, 'depositado' => 0.0, 'gasto' => 0.0];
    }

    try {
        $st = $pdo->prepare(
            "SELECT DATE(pedido_en) AS f, COUNT(*) AS n
               FROM altas
              WHERE publicista_id = ? AND pedido_en BETWEEN ? AND ?
              GROUP BY DATE(pedido_en)"
        );
        $st->execute([$publicistaId, $desdeIni, $hastaFin]);
        foreach ($st->fetchAll() as $fila) {
            $f = (string)$fila['f'];
            if (isset($porDia[$f])) { $porDia[$f]['registros'] = (int)$fila['n']; }
        }
    } catch (Throwable $e) {
        error_log('publicidad_por_dia (registros): ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare(
            "SELECT DATE(r.acreditada_en) AS f,
                    SUM(r.es_primera = 1)         AS primeras,
                    COALESCE(SUM(r.monto_base), 0) AS depositado
               FROM recargas r
               JOIN altas a ON a.usuario = r.usuario
              WHERE a.publicista_id = ?
                AND r.estado = 'acreditada'
                AND r.acreditada_en BETWEEN ? AND ?
              GROUP BY DATE(r.acreditada_en)"
        );
        $st->execute([$publicistaId, $desdeIni, $hastaFin]);
        foreach ($st->fetchAll() as $fila) {
            $f = (string)$fila['f'];
            if (isset($porDia[$f])) {
                $porDia[$f]['primeras_cargas'] = (int)$fila['primeras'];
                $porDia[$f]['depositado']      = round((float)$fila['depositado'], 2);
            }
        }
    } catch (Throwable $e) {
        error_log('publicidad_por_dia (cargas): ' . $e->getMessage());
    }

    foreach (publicidad_gasto_dias($pdo, $publicistaId, $desde, $hasta) as $g) {
        $f = (string)$g['fecha'];
        if (isset($porDia[$f])) { $porDia[$f]['gasto'] = round((float)$g['monto'], 2); }
    }

    // Mas reciente primero, como en el mockup.
    return array_values(array_reverse($porDia));
}
