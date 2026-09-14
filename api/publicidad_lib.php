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
 * Toda la atribucion de Meta Ads de UN usuario, en una sola consulta a
 * `altas` (UNIQUE por usuario -- sql/13_cola_altas.sql -- como mucho una
 * fila). Junta lo que varios call sites de meta_evento() necesitan por
 * separado: el publicista que trajo a este jugador y las cookies fbp/fbc
 * que la landing capturo en el momento del alta (crear_cuenta.php es el
 * unico punto donde esas cookies llegan frescas del navegador; de ahi en
 * adelante, para cualquier evento posterior del mismo usuario -- carga,
 * compra, chat -- se reusan desde aca).
 *
 * Sin fila en `altas` (usuario que no vino de la landing -- alta hecha
 * directo en el panel de agentes y despues espejada, por ejemplo -- o alta
 * anterior a la migracion 44), o si la migracion 44 no corrio (columnas
 * ausentes): devuelve el array vacio. El caller sigue con el pixel general
 * y sin fbp/fbc, nunca con un fatal.
 *
 * Devuelve ['publicista' => ?array, 'fbp' => string, 'fbc' => string].
 */
function publicidad_atribucion_por_usuario(PDO $pdo, string $usuario): array
{
    $vacio = ['publicista' => null, 'fbp' => '', 'fbc' => '',
              'ip' => '', 'ua' => '', 'url' => ''];
    $usuario = trim($usuario);
    if ($usuario === '') {
        return $vacio;
    }
    $fila = null;
    try {
        /* ip/ua/url_landing son de la migracion 51: son los del JUGADOR,
           guardados cuando se registro desde su telefono. Hacen falta porque
           los eventos que mas valen (Purchase, CompleteRegistration) los
           dispara el bot del VPS o un operador del CRM, y ahi el REMOTE_ADDR
           del server no tiene nada que ver con la persona. */
        $st = $pdo->prepare(
            "SELECT publicista_id, fbp, fbc, fbclid, ip, ua, url_landing, pedido_en
               FROM altas WHERE usuario = ? LIMIT 1"
        );
        $st->execute([$usuario]);
        $fila = $st->fetch();
    } catch (Throwable $e) {
        // Sin la migracion 51 esas columnas no existen todavia: se reintenta
        // con las de siempre en vez de perder la atribucion entera.
        try {
            $st = $pdo->prepare(
                "SELECT publicista_id, fbp, fbc FROM altas WHERE usuario = ? LIMIT 1"
            );
            $st->execute([$usuario]);
            $fila = $st->fetch();
        } catch (Throwable $e2) {
            // Migracion 44 tampoco: se sigue sin atribucion, nunca rompe.
            return $vacio;
        }
    }
    if (!$fila) {
        return $vacio;
    }
    $publicista = !empty($fila['publicista_id'])
        ? publicidad_por_id($pdo, (int)$fila['publicista_id'])
        : null;

    /* Si no hay cookie `fbc` pero SI quedo el fbclid, se reconstruye.
       Meta define ese valor como `fb.1.<timestamp>.<fbclid>`, asi que se puede
       armar sin la cookie -- y sin esto la conversion viaja sin nada que Meta
       pueda atar al click del anuncio.
       Pasa cuando fbevents.js no llego a cargar (bloqueador, red lenta) o
       cuando el navegador ya borro la cookie: la landing guarda el fbclid
       igual, porque viene en la URL. Hasta ahora esa columna se escribia y no
       la leia nadie. */
    $fbc = (string)($fila['fbc'] ?? '');
    $fbclid = trim((string)($fila['fbclid'] ?? ''));
    if ($fbc === '' && $fbclid !== '') {
        $ts  = strtotime((string)($fila['pedido_en'] ?? '')) ?: time();
        $fbc = 'fb.1.' . ($ts * 1000) . '.' . $fbclid;
    }

    return [
        'publicista' => $publicista,
        'fbp'        => (string)($fila['fbp'] ?? ''),
        'fbc'        => $fbc,
        'ip'         => (string)($fila['ip'] ?? ''),
        'ua'         => (string)($fila['ua'] ?? ''),
        'url'        => (string)($fila['url_landing'] ?? ''),
    ];
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
            // cargado. pixel_id/capi_token/insights_* son opcionales tanto
            // al crear como al editar (sin pixel propio, el publicista usa
            // el pixel general -- ver publicidad_pixel_propio()), pero en
            // una edicion el operador puede dejar cualquiera vacio SIN
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
                                   float $monto, string $operador = '',
                                   string $landing = ''): bool
{
    /* EL GASTO ES DE UN PUBLICISTA O DE UNA LANDING, nunca de los dos.
       Empezo siendo solo por publicista, pensando en medir la campaña de otra
       persona con su propio pixel. Pero el uso real es el contrario: comparar
       LANDINGS para ver cual convierte mejor y cortar la que no rinde. Sin
       gasto no hay CPA ni ROAS, asi que las dos metricas que deciden eso
       estaban siempre vacias para quien no usa publicistas -- que es el caso
       normal (migracion 65). */
    $landing = trim($landing);
    if ($fecha === '' || ($publicistaId <= 0 && $landing === '')) {
        return false;
    }
    try {
        if ($landing !== '') {
            $pdo->prepare(
                "INSERT INTO gasto_diario (landing_slug, fecha, monto, operador)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE monto = VALUES(monto), operador = VALUES(operador)"
            )->execute([mb_substr($landing, 0, 80), $fecha, $monto,
                        $operador !== '' ? $operador : null]);
        } else {
            $pdo->prepare(
                "INSERT INTO gasto_diario (publicista_id, fecha, monto, operador)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE monto = VALUES(monto), operador = VALUES(operador)"
            )->execute([$publicistaId, $fecha, $monto, $operador !== '' ? $operador : null]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('publicidad_gasto_guardar: ' . $e->getMessage());
        return false;
    }
}

/**
 * LA PAUTA DE TODO EL NEGOCIO en [desde, hasta]: publicistas Y landings juntos.
 *
 * Las otras funciones de gasto contestan "cuanto puso ESTA campaña", que es la
 * pregunta de Publicidad. Esta contesta "cuanto puse en total", que es la
 * pregunta de Finanzas, y por eso NO discrimina de donde salio la fila: para
 * saber si el negocio se banca su propia publicidad da igual si la plata se
 * cargo contra una landing o contra un publicista -- salio del mismo bolsillo.
 *
 * `dias` cuenta fechas DISTINTAS con gasto, no filas: dos landings cargadas el
 * mismo dia son un dia de pauta, no dos. Es lo que hace que el promedio diario
 * signifique algo.
 */
function publicidad_gasto_total(PDO $pdo, string $desde, string $hasta): array
{
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(monto),0) AS total,
                    COUNT(DISTINCT fecha)  AS dias
               FROM gasto_diario
              WHERE fecha BETWEEN ? AND ?"
        );
        $st->execute([$desde, $hasta]);
        $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (float)($f['total'] ?? 0), 'dias' => (int)($f['dias'] ?? 0)];
    } catch (Throwable $e) {
        error_log('publicidad_gasto_total: ' . $e->getMessage());
        return ['total' => 0.0, 'dias' => 0];
    }
}

/**
 * TODAS las filas de gasto del periodo, de TODAS las campañas, para editarlas.
 *
 * POR QUE EXISTE (Nahuel, 14/09/2026): "desde el CRM publicista dia uno hay un
 * gasto de sesenta y seis mil pesos, eso fue una campaña que hicimos mal... los
 * datos estan un poco sucios, me gustaria ver si hay alguna forma de editarlo".
 *
 * Hasta hoy el dia por dia mostraba SOLO la campaña seleccionada, asi que para
 * corregir el gasto de una vieja habia que acordarse de que existia, encontrar
 * su solapa y recien ahi editar dia por dia. Una campaña que ya no se usa es
 * justamente la que uno no va a ir a buscar -- y es la que ensucia el total.
 *
 * Devuelve el destino (landing_slug / publicista_id) en cada fila porque es lo
 * que hace falta para poder editarla o borrarla: el gasto pertenece a UNA
 * campaña, y sin saber a cual no se puede tocar la fila correcta.
 */
function publicidad_gasto_todo(PDO $pdo, string $desde, string $hasta): array
{
    try {
        $st = $pdo->prepare(
            "SELECT g.fecha,
                    g.monto,
                    g.operador,
                    g.landing_slug,
                    g.publicista_id,
                    COALESCE(l.nombre, p.nombre, g.landing_slug,
                             CONCAT('Publicista #', g.publicista_id), '(sin campaña)')
                      AS campana,
                    IF(g.landing_slug IS NOT NULL, 'landing', 'publicista') AS clase
               FROM gasto_diario g
               LEFT JOIN landings    l ON l.slug = g.landing_slug
               LEFT JOIN publicistas p ON p.id   = g.publicista_id
              WHERE g.fecha BETWEEN ? AND ?
              ORDER BY g.fecha DESC, g.monto DESC"
        );
        $st->execute([$desde, $hasta]);
        $filas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $filas[] = [
                'fecha'         => (string)$f['fecha'],
                'monto'         => round((float)$f['monto'], 2),
                'operador'      => $f['operador'] !== null ? (string)$f['operador'] : null,
                'campana'       => (string)$f['campana'],
                'clase'         => (string)$f['clase'],
                'landing'       => $f['landing_slug'] !== null ? (string)$f['landing_slug'] : null,
                'publicista_id' => $f['publicista_id'] !== null ? (int)$f['publicista_id'] : null,
            ];
        }
        return $filas;
    } catch (Throwable $e) {
        error_log('publicidad_gasto_todo: ' . $e->getMessage());
        return [];
    }
}

/**
 * DE DONDE sale la pauta del periodo: una fila por campaña, con su nombre.
 *
 * POR QUE EXISTE. publicidad_gasto_total() devuelve un solo numero, y un solo
 * numero sin desglose no se puede auditar: si dice $88.534 y uno se acuerda de
 * haber cargado $22.500, no hay forma de saber si el resto son otras campañas,
 * un dia cargado dos veces con distinto destino, o un error de tipeo. Nahuel lo
 * planteo asi el 14/09/2026: "no entiendo muy bien de donde sale eso".
 *
 * Devuelve el NOMBRE, no el slug ni el id: "bono-50" todavia se entiende, pero
 * "pub:3" no le dice nada a nadie. Los LEFT JOIN son a proposito -- una campaña
 * borrada despues de cargarle gasto deja su fila igual, y esa plata tiene que
 * seguir apareciendo o el desglose no sumaria el total.
 */
function publicidad_gasto_detalle(PDO $pdo, string $desde, string $hasta): array
{
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(l.nombre, p.nombre, g.landing_slug,
                             CONCAT('Publicista #', g.publicista_id), '(sin campaña)')
                      AS campana,
                    IF(g.landing_slug IS NOT NULL, 'landing', 'publicista') AS clase,
                    COALESCE(SUM(g.monto), 0) AS total,
                    COUNT(DISTINCT g.fecha)   AS dias,
                    MIN(g.fecha)              AS primer_dia,
                    MAX(g.fecha)              AS ultimo_dia
               FROM gasto_diario g
               LEFT JOIN landings    l ON l.slug = g.landing_slug
               LEFT JOIN publicistas p ON p.id   = g.publicista_id
              WHERE g.fecha BETWEEN ? AND ?
              GROUP BY campana, clase
              ORDER BY total DESC"
        );
        $st->execute([$desde, $hasta]);
        $filas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $filas[] = [
                'campana'    => (string)$f['campana'],
                'clase'      => (string)$f['clase'],
                'total'      => round((float)$f['total'], 2),
                'dias'       => (int)$f['dias'],
                'primer_dia' => (string)$f['primer_dia'],
                'ultimo_dia' => (string)$f['ultimo_dia'],
            ];
        }
        return $filas;
    } catch (Throwable $e) {
        error_log('publicidad_gasto_detalle: ' . $e->getMessage());
        return [];
    }
}

/**
 * Las cargas del periodo partidas en DOS: las de jugadores que cargaron por
 * primera vez, y las de los que ya habian cargado antes.
 *
 * POR QUE ESTE CORTE Y NO OTRO. Es el modelo de negocio tal como lo describio
 * Nahuel: "puedo no salir con un ROAS positivo en la primera carga, pero si en
 * la segunda... mi modelo esta en ir adquiriendo jugadores hasta que mis
 * gastos publicitarios diarios sean menores a las ganancias obtenidas". O sea
 * que la plata entra en dos tiempos y solo el segundo escala:
 *
 *   - Las PRIMERAS cargas son lo que devuelve la pauta de HOY. Que no cubran
 *     el gasto no es una mala noticia: casi nunca lo cubren, y por eso mirar
 *     solo el ROAS del dia hace apagar campañas que estaban funcionando.
 *   - Las de REPETICION son lo que deja la base ya comprada, sin gastar un
 *     peso mas hoy. Ese es el numero que tiene que superar a la pauta diaria
 *     para que el negocio se financie solo.
 *
 * "Primera" es el MINIMO historico del jugador sobre LAS DOS VIAS (recarga por
 * transferencia y peticion desde el juego), no la primera dentro del rango:
 * alguien que venia cargando hace meses no puede aparecer como nuevo porque el
 * reporte arranque el lunes. Por eso la subconsulta de `primera` no lleva
 * filtro de fechas -- es a proposito, y sacarselo romperia justo lo que mide.
 */
function publicidad_cargas_split(PDO $pdo, string $desde, string $hasta): array
{
    $vacio = [
        'jugadores'        => 0, 'jugadores_nuevos' => 0, 'jugadores_repiten' => 0,
        'depositado'       => 0.0, 'dep_primeras' => 0.0, 'dep_repeticion' => 0.0,
        'cargas'           => 0,
    ];
    try {
        $sqlCargas = publicidad_sql_cargas();
        $st = $pdo->prepare(
            "SELECT COUNT(*)                                                   AS cargas,
                    COUNT(DISTINCT c.usuario)                                  AS jugadores,
                    COUNT(DISTINCT IF(c.cuando = pr.primera, c.usuario, NULL)) AS nuevos,
                    COUNT(DISTINCT IF(c.cuando > pr.primera, c.usuario, NULL)) AS repiten,
                    COALESCE(SUM(c.monto),0)                                   AS total,
                    COALESCE(SUM(IF(c.cuando = pr.primera, c.monto, 0)),0)     AS dep_primeras
               FROM ($sqlCargas) c
               JOIN (SELECT usuario, MIN(cuando) AS primera
                       FROM ($sqlCargas) z GROUP BY usuario) pr
                 ON pr.usuario = c.usuario
              WHERE c.cuando BETWEEN ? AND ?"
        );
        $st->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
        $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (float)($f['total'] ?? 0);
        $dep1  = (float)($f['dep_primeras'] ?? 0);
        return [
            'jugadores'         => (int)($f['jugadores'] ?? 0),
            'jugadores_nuevos'  => (int)($f['nuevos'] ?? 0),
            'jugadores_repiten' => (int)($f['repiten'] ?? 0),
            'cargas'            => (int)($f['cargas'] ?? 0),
            'depositado'        => round($total, 2),
            'dep_primeras'      => round($dep1, 2),
            'dep_repeticion'    => round($total - $dep1, 2),
        ];
    } catch (Throwable $e) {
        error_log('publicidad_cargas_split: ' . $e->getMessage());
        return $vacio;
    }
}

/**
 * Borra el gasto de UN dia de una campaña.
 *
 * NO ALCANZA CON GUARDAR 0, y por eso existe. Un 0 deja la fila viva, y la
 * fila viva cuenta como "dia con pauta" en publicidad_gasto_total() -- que es
 * lo que divide el promedio diario del indicador de salud. Un dia que nunca
 * tuvo pauta metido ahi baja el promedio y hace parecer que la publicidad se
 * paga sola antes de tiempo. Cargar un gasto en la campaña equivocada es facil
 * (las landings y los publicistas comparten las mismas solapas), asi que tiene
 * que haber forma de deshacerlo del todo, no de taparlo con un cero.
 */
function publicidad_gasto_borrar(PDO $pdo, int $publicistaId, string $fecha,
                                 string $landing = ''): bool
{
    $landing = trim($landing);
    if ($fecha === '' || ($publicistaId <= 0 && $landing === '')) {
        return false;
    }
    try {
        /* El `IS NULL` de la otra columna no es decorativo: sin el, borrar el
           gasto de la landing "promo" un dia se llevaria puesto tambien el del
           publicista que cargo ese mismo dia. */
        if ($landing !== '') {
            $st = $pdo->prepare(
                "DELETE FROM gasto_diario
                  WHERE landing_slug = ? AND publicista_id IS NULL AND fecha = ?"
            );
            $st->execute([mb_substr($landing, 0, 80), $fecha]);
        } else {
            $st = $pdo->prepare(
                "DELETE FROM gasto_diario
                  WHERE publicista_id = ? AND landing_slug IS NULL AND fecha = ?"
            );
            $st->execute([$publicistaId, $fecha]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('publicidad_gasto_borrar: ' . $e->getMessage());
        return false;
    }
}

/** Gasto total de un publicista en [desde, hasta] (fechas 'Y-m-d', inclusive). */
function publicidad_gasto_periodo(PDO $pdo, int $publicistaId, string $desde, string $hasta,
                                   string $landing = ''): float
{
    $landing = trim($landing);
    try {
        if ($landing !== '') {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(monto), 0) FROM gasto_diario
                  WHERE landing_slug = ? AND fecha BETWEEN ? AND ?"
            );
            $st->execute([$landing, $desde, $hasta]);
        } else {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(monto), 0) FROM gasto_diario
                  WHERE publicista_id = ? AND fecha BETWEEN ? AND ?"
            );
            $st->execute([$publicistaId, $desde, $hasta]);
        }
        return (float)$st->fetchColumn();
    } catch (Throwable $e) {
        // Sin la migracion 65 no existe landing_slug: se sigue sin gasto.
        return 0.0;
    }
}

/** El gasto dia por dia de un publicista en el rango, para que el operador lo edite. */
function publicidad_gasto_dias(PDO $pdo, int $publicistaId, string $desde, string $hasta,
                                string $landing = ''): array
{
    $landing = trim($landing);
    try {
        if ($landing !== '') {
            $st = $pdo->prepare(
                "SELECT fecha, monto FROM gasto_diario
                  WHERE landing_slug = ? AND fecha BETWEEN ? AND ?
                  ORDER BY fecha ASC"
            );
            $st->execute([$landing, $desde, $hasta]);
        } else {
            $st = $pdo->prepare(
                "SELECT fecha, monto FROM gasto_diario
                  WHERE publicista_id = ? AND fecha BETWEEN ? AND ?
                  ORDER BY fecha ASC"
            );
            $st->execute([$publicistaId, $desde, $hasta]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * TODAS las cargas de un jugador, venga por donde venga la plata.
 *
 * EL BUG QUE ESTO ARREGLA (3/9/2026): el embudo salia solo de `recargas`, y
 * por ahi pasa un solo camino -- el nuestro, el que arranca en el chatbot. La
 * carga que el jugador pide con el boton "Depositos" DENTRO del juego no crea
 * ninguna fila en `recargas` (lo dice peticiones_cola.php: "no hubo recarga
 * nuestra"), asi que era invisible: no contaba como primera carga, ni como
 * carga total, ni sumaba al depositado.
 *
 * O sea que Publicidad mostraba 0 conversiones mientras la gente cargaba de
 * verdad. Con la pauta corriendo eso no es un numero feo en una pantalla: es
 * un CPA inflado y un ROAS en cero, que llevan a apagar una campaña que
 * estaba funcionando.
 *
 * Del camino A lo unico que queda de este lado es la linea de `movimientos`
 * (origen='peticion'), porque las fichas las acredita la plataforma sobre el
 * saldo real. Alcanza: tiene usuario, monto y fecha, que es lo que mide el
 * embudo.
 *
 * `monto > 0` deja afuera los ajustes negativos, que no son cargas.
 *
 * EL COLLATE ES OBLIGATORIO Y ES NUEVO. `recargas` y `altas` nacieron sin uno
 * explicito -- las dos caen al default del server -- y por eso el JOIN entre
 * ellas nunca lo necesito. `movimientos` en cambio quedo fijada en
 * utf8mb4_unicode_ci (05_crm.sql), asi que al meterla en el UNION las dos
 * ramas traen collations distintas y MariaDB corta con "illegal mix of
 * collations". Se fuerzan las dos ramas a la misma y listo; el JOIN contra
 * `altas` de abajo tambien la fuerza, por el mismo motivo.
 */
function publicidad_sql_cargas(): string
{
    /* ES LA DEFINICION UNICA DE "UNA CARGA" EN TODO EL CRM: la usan Publicidad,
       Finanzas y el indicador de salud. Si cada pantalla armara la suya, dos
       pantallas mostrarian plata distinta el mismo dia y no habria forma de
       saber cual esta bien.

       SUMA monto_pedido Y NO monto_base: `monto_pedido` es lo que el jugador
       efectivamente transfirio; `monto_base` es el numero redondo que pidio.
       Hoy son iguales (los centavos unicos se sacaron), pero en las recargas
       viejas difieren hasta en 99 centavos, y la plata que entro a la caja es
       la primera.

       `via` distingue las dos: 'transferencia' es el camino del chatbot y
       'juego' el boton Depositos de adentro de la plataforma. Se devuelve para
       que cada pantalla pueda abrir el numero sin volver a consultar. */
    return "SELECT r.usuario COLLATE utf8mb4_unicode_ci      AS usuario,
                   r.acreditada_en                           AS cuando,
                   r.monto_pedido                            AS monto,
                   'transferencia' COLLATE utf8mb4_unicode_ci AS via,
                   r.referencia COLLATE utf8mb4_unicode_ci   AS referencia
              FROM recargas r
             WHERE r.estado = 'acreditada' AND r.acreditada_en IS NOT NULL
            UNION ALL
            SELECT m.usuario COLLATE utf8mb4_unicode_ci,
                   m.creado_en,
                   m.monto,
                   'juego' COLLATE utf8mb4_unicode_ci,
                   NULL
              FROM movimientos m
             WHERE m.origen = 'peticion' AND m.tipo = 'saldo' AND m.monto > 0";
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
/**
 * Visitas de UNA landing en [desde, hasta] (fechas 'Y-m-d'), del contador
 * propio (landing_visitas, migración 54). El equivalente de
 * meta_insights_pageviews() para las landings: Meta da las visitas de una
 * cuenta de anuncios, no de una URL, asi que una landing las cuenta aca.
 *
 * `dia` se compara con las fechas del rango directo: se guarda con CURDATE()
 * del server, el mismo reloj con el que se guardan las altas (pedido_en), asi
 * que visitas y registros del embudo cuadran entre si.
 *
 * Devuelve null si falta la migración 54 -- el front muestra "-", igual que un
 * publicista sin Insights, en vez de un 0 que pareceria "nadie entro".
 */
function publicidad_visitas_landing(PDO $pdo, string $slug, string $desde, string $hasta): ?int
{
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM landing_visitas
              WHERE slug = ? AND dia BETWEEN ? AND ?"
        );
        $st->execute([$slug, $desde, $hasta]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * UN SEGMENTO del embudo. El reporte se puede mirar por dos ejes distintos, y
 * son preguntas distintas:
 *
 *   publicista -> QUIEN te trajo al jugador (una persona, con su pixel y su
 *                 link registro.html?pub=<slug>). Se filtra por altas.publicista_id.
 *   landing    -> QUE pagina de promo vio (lp.html?l=<slug>, modulo Landings).
 *                 Se filtra por altas.origen = 'lp:<slug>'.
 *
 * Los dos viven en la misma tabla `altas`, asi que el embudo es el mismo: lo
 * unico que cambia es la columna del WHERE. publicidad_metricas() y
 * publicidad_por_dia() aceptan cualquiera de los dos via estas dos funciones,
 * y siguen aceptando un int pelado (= publicista) por compatibilidad.
 */
function publicidad_seg_norm(int|array $seg): array
{
    if (is_int($seg)) { return ['tipo' => 'publicista', 'id' => $seg]; }
    if (($seg['tipo'] ?? '') === 'landing') {
        /* El `slug` se conserva: es la clave con la que se guarda el gasto de
           esa landing (migracion 65). Antes se descartaba aca -- solo hacia
           falta el `origen` para filtrar altas -- y el gasto quedaba sin
           forma de encontrarse. */
        return ['tipo' => 'landing', 'origen' => (string)($seg['origen'] ?? ''),
                'slug' => (string)($seg['slug'] ?? '')];
    }
    return ['tipo' => 'publicista', 'id' => (int)($seg['id'] ?? 0)];
}

/**
 * La condicion SQL de un segmento y su valor a bindear. $alias es el de la
 * tabla `altas` en esa consulta ('' para las que la nombran sin alias, 'a'
 * para las que hacen JOIN altas a).
 */
function publicidad_seg_where(array $seg, string $alias = ''): array
{
    $p = $alias !== '' ? $alias . '.' : '';
    if (($seg['tipo'] ?? '') === 'landing') {
        return ["{$p}origen = ?", (string)$seg['origen']];
    }
    return ["{$p}publicista_id = ?", (int)($seg['id'] ?? 0)];
}

function publicidad_metricas(PDO $pdo, int|array $seg, string $desde, string $hasta): array
{
    $seg = publicidad_seg_norm($seg);
    [$wReg, $vReg] = publicidad_seg_where($seg, '');   // registros: `altas` sin alias
    [$wA,   $vA]   = publicidad_seg_where($seg, 'a');  // cargas/retencion: JOIN altas a
    $hastaFin = $hasta . ' 23:59:59';
    $desdeIni = $desde . ' 00:00:00';

    $registros = 0;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM altas
              WHERE $wReg AND pedido_en BETWEEN ? AND ?"
        );
        $st->execute([$vReg, $desdeIni, $hastaFin]);
        $registros = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('publicidad_metricas (registros): ' . $e->getMessage());
    }

    // Cargas de jugadores que pertenecen a este publicista (por su alta),
    // acreditadas en el rango -- sin importar cuando se registraron.
    $primeras = 0; $totalCargas = 0; $depositado = 0.0; $depPrimeras = 0.0;
    try {
        // monto_base y NO monto_pedido, a proposito y NO es un bug:
        // monto_pedido = monto_base + los centavos que identifican la
        // transferencia (100.47). Esos centavos son un identificador, no
        // plata que el jugador quiso gastar, asi que para medir una campaña
        // corresponde el valor redondo.
        //
        // crm_finanzas.php suma monto_pedido, y tambien esta bien: ahi
        // interesa la CAJA, la plata exacta que entro al banco. Por eso los
        // dos modulos dan unos centavos de diferencia sobre lo mismo. Si
        // algun dia parecen "descuadrados", es esto -- no unifiques uno con
        // el otro sin entender cual pregunta responde cada pantalla.
        //
        // "PRIMERA CARGA" SE CALCULA ACA, ya no se lee recargas.es_primera.
        // Esa columna la escribe rl_acreditar() contando recargas anteriores,
        // asi que a un jugador que empezo cargando por el boton "Depositos"
        // le marca como primera la que en realidad es su segunda. Con las dos
        // vias sobre la mesa, "la primera" es el MINIMO de las dos y hay que
        // mirarlas juntas. La columna queda: la sigue escribiendo el camino B
        // y no molesta.
        //
        // COUNT(DISTINCT ...) y no SUM(...): si un jugador tuviera dos cargas
        // con el mismo timestamp al minimo, las dos empatarian con MIN() y se
        // contaria dos veces como jugador nuevo.
        $union = publicidad_sql_cargas();
        $st = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT IF(c.cuando = pr.primera, c.usuario, NULL)) AS primeras,
                COUNT(*)                        AS total_cargas,
                COALESCE(SUM(c.monto), 0)       AS depositado,
                /* La plata de las PRIMERAS cargas, separada del total. Es lo
                   que se recupera EN EL ACTO de cada jugador nuevo; el resto
                   llega cuando vuelve. Sin separarlas no hay forma de saber si
                   una campaña recupera enseguida o recien con la segunda carga
                   -- y esa diferencia es la que decide cuanto aguante hace
                   falta para bancarla. */
                COALESCE(SUM(IF(c.cuando = pr.primera, c.monto, 0)), 0) AS dep_primeras
               FROM ($union) c
               JOIN altas a
                 ON a.usuario COLLATE utf8mb4_unicode_ci = c.usuario
               JOIN (SELECT usuario, MIN(cuando) AS primera
                       FROM ($union) t GROUP BY usuario) pr
                 ON pr.usuario = c.usuario
              WHERE $wA
                AND c.cuando BETWEEN ? AND ?"
        );
        $st->execute([$vA, $desdeIni, $hastaFin]);
        $fila = $st->fetch();
        $primeras    = (int)($fila['primeras'] ?? 0);
        $totalCargas = (int)($fila['total_cargas'] ?? 0);
        $depositado  = (float)($fila['depositado'] ?? 0);
        $depPrimeras = (float)($fila['dep_primeras'] ?? 0);
    } catch (Throwable $e) {
        error_log('publicidad_metricas (cargas): ' . $e->getMessage());
    }

    // "Volvio a cargar": de los jugadores de este publicista que cargaron en
    // el rango, cuantos tienen mas de una recarga acreditada EN TOTAL (no
    // solo en el rango -- volver a cargar es un hecho del jugador, no del
    // periodo que este mirando el operador).
    $jugadoresConCarga = 0; $jugadoresQueVolvieron = 0;
    try {
        // Las mismas dos vias. "Volvio a cargar" es la metrica que mas se
        // rompia con una sola fuente: el jugador que carga la primera vez por
        // el chatbot y las siguientes por el boton "Depositos" -- que es el
        // recorrido natural, porque una vez adentro del juego el boton esta
        // mas a mano -- figuraba con una sola carga y como que nunca volvio.
        $union = publicidad_sql_cargas();
        $st = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT c.usuario) AS con_carga,
                COUNT(DISTINCT CASE WHEN rep.total > 1 THEN c.usuario END) AS volvieron
               FROM ($union) c
               JOIN altas a
                 ON a.usuario COLLATE utf8mb4_unicode_ci = c.usuario
               JOIN (SELECT usuario, COUNT(*) AS total
                       FROM ($union) t GROUP BY usuario) rep
                 ON rep.usuario = c.usuario
              WHERE $wA
                AND c.cuando BETWEEN ? AND ?"
        );
        $st->execute([$vA, $desdeIni, $hastaFin]);
        $fila = $st->fetch();
        $jugadoresConCarga    = (int)($fila['con_carga'] ?? 0);
        $jugadoresQueVolvieron = (int)($fila['volvieron'] ?? 0);
    } catch (Throwable $e) {
        error_log('publicidad_metricas (retencion): ' . $e->getMessage());
    }

    // El gasto va por publicista O por landing (migracion 66). Antes solo por
    // publicista -- "una landing es una pagina, no una cuenta de Meta" -- y eso
    // dejaba el CPA y el ROAS en "-" para quien mide por landing, que es el uso
    // real. Si no hay gasto cargado sigue dando 0 y las dos metricas quedan en
    // "-", que es lo correcto: no hay con que calcularlas.
    $gasto = $seg['tipo'] === 'publicista'
        ? publicidad_gasto_periodo($pdo, (int)$seg['id'], $desde, $hasta)
        : publicidad_gasto_periodo($pdo, 0, $desde, $hasta, (string)($seg['slug'] ?? ''));

    return [
        'registros'          => $registros,
        'primeras_cargas'    => $primeras,
        'cargas_totales'     => $totalCargas,
        'depositado'         => round($depositado, 2),
        'depositado_primeras' => round($depPrimeras, 2),
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
function publicidad_por_dia(PDO $pdo, int|array $seg, string $desde, string $hasta): array
{
    $seg = publicidad_seg_norm($seg);
    [$wReg, $vReg] = publicidad_seg_where($seg, '');
    [$wA,   $vA]   = publicidad_seg_where($seg, 'a');
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
              WHERE $wReg AND pedido_en BETWEEN ? AND ?
              GROUP BY DATE(pedido_en)"
        );
        $st->execute([$vReg, $desdeIni, $hastaFin]);
        foreach ($st->fetchAll() as $fila) {
            $f = (string)$fila['f'];
            if (isset($porDia[$f])) { $porDia[$f]['registros'] = (int)$fila['n']; }
        }
    } catch (Throwable $e) {
        error_log('publicidad_por_dia (registros): ' . $e->getMessage());
    }

    try {
        // Las dos vias de carga, igual que en publicidad_metricas(): si el
        // total y el grafico por dia salieran de fuentes distintas, no
        // cerrarian entre si y no habria forma de saber cual esta mal.
        $union = publicidad_sql_cargas();
        $st = $pdo->prepare(
            "SELECT DATE(c.cuando) AS f,
                    COUNT(DISTINCT IF(c.cuando = pr.primera, c.usuario, NULL)) AS primeras,
                    COALESCE(SUM(c.monto), 0) AS depositado
               FROM ($union) c
               JOIN altas a
                 ON a.usuario COLLATE utf8mb4_unicode_ci = c.usuario
               JOIN (SELECT usuario, MIN(cuando) AS primera
                       FROM ($union) t GROUP BY usuario) pr
                 ON pr.usuario = c.usuario
              WHERE $wA
                AND c.cuando BETWEEN ? AND ?
              GROUP BY DATE(c.cuando)"
        );
        $st->execute([$vA, $desdeIni, $hastaFin]);
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

    // El gasto va por publicista O por landing (migracion 65).
    $filas = $seg['tipo'] === 'publicista'
        ? publicidad_gasto_dias($pdo, (int)$seg['id'], $desde, $hasta)
        : publicidad_gasto_dias($pdo, 0, $desde, $hasta, (string)($seg['slug'] ?? ''));
    foreach ($filas as $g) {
        $f = (string)$g['fecha'];
        if (isset($porDia[$f])) { $porDia[$f]['gasto'] = round((float)$g['monto'], 2); }
    }

    // Mas reciente primero, como en el mockup.
    return array_values(array_reverse($porDia));
}
