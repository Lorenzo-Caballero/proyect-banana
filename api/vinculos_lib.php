<?php
/**
 * vinculos_lib.php — Cuándo dos cuentas son la misma persona, y cómo cortarle
 * el paso a la siguiente.
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026): *"descubrí que holasofito763,
 * holajuan969 y holaleiva89 son la misma persona"*. Lo descubrió a mano. El
 * sistema tenía los datos para verlo desde hacía semanas y no los cruzaba.
 *
 * ============================================================================
 * LAS TRES SEÑALES NO VALEN LO MISMO, Y ESO ES TODO EL DISEÑO
 * ============================================================================
 *
 *   Comprobante  `recargas.trx_declarada`. DOS cuentas declararon LA MISMA
 *              transferencia. Es la más fuerte de todas y además no es un
 *              indicio de identidad sino de intención: nadie declara por error
 *              el mismo número de operación desde dos cuentas distintas.
 *
 *              Así lo descubrió Nahuel el 16/09/2026 --*"mandó un comprobante
 *              con los mismos datos, el mismo desde varias cuentas"*-- y por
 *              eso esta señal existe. `pagos.id_unico` es UNIQUE, así que el
 *              banco acredita UNA sola vez; el riesgo real es que un operador
 *              vea el comprobante en el CRM y lo asigne a mano sin saber que
 *              ya se usó.
 *
 *   CUIT/CBU   `huellas_pagador`. La plata salió de la MISMA cuenta bancaria.
 *              Muy fuerte: abrir una cuenta de banco a nombre de otro no es
 *              algo que se haga para esquivar un bloqueo.
 *
 *   Dispositivo  `dispositivos_usuarios`. El mismo celular. Fuerte, pero un
 *              teléfono se presta: la pareja, el hermano, el locutorio.
 *
 * HUBO UNA CUARTA, LA IP DEL ALTA, Y SE SACÓ EL 16/09/2026. No por débil
 * --que lo era-- sino porque `altas.ip` no tenía IPs de jugadores: el sitio
 * quedó detrás de Cloudflare y estábamos guardando el edge de la CDN. 124
 * cuentas "compartían" una IP. El CRM acusaba a cuentas legítimas de ser la
 * misma persona, y eso es lo que hace que el aviso entero se deje de leer.
 *
 * El criterio que quedó, en palabras de Nahuel: *"si no es seguro que es una
 * cuenta falsa, preferiría que ahí no aparezca nada... que sean datos
 * corroborables"*. Las tres que quedan lo son: un número de operación, un
 * CUIT/CBU que sale del mail del banco, y un device_id que es un UUID por
 * instalación.
 *
 * Cada vínculo sale igual etiquetado con su fuerza, y NADA se bloquea solo.
 *
 * ============================================================================
 * QUÉ BLOQUEA UN BLOQUEO (y qué no)
 * ============================================================================
 * **No le saca el juego.** El juego corre en ganamos; lo que le impide jugar
 * es `is_banned`, que se pone en el PANEL y nosotros solo espejamos (ver la
 * migración 69: escribirla de este lado no sirve, el sync la pisa cada 5 min).
 *
 * Lo nuestro hace otra cosa, y es la que faltaba: **le impide abrir la cuenta
 * siguiente**. Banear en el panel no sirve de nada si la persona se crea otra
 * en dos minutos — que es exactamente lo que ya pasó tres veces. Además le
 * corta el chat, las recargas y los bonos.
 *
 * O sea: el panel corta la cuenta de hoy, esto corta la cadena.
 */

declare(strict_types=1);

/**
 * Fuerza de un vínculo. Ordena de más a menos confiable.
 *
 * LAS TRES SON CORROBORABLES, y eso es el criterio de entrada desde el
 * 16/09/2026: el operador tiene que poder ponerle el dato enfrente al jugador.
 * Había una cuarta, `'ip' => 1`, y se sacó junto con VIN_IP_MAX_CUENTAS -- no
 * por débil sino porque lo que guardábamos no eran IPs de jugadores sino edges
 * de Cloudflare. Ver el bloque 3 de vin_relacionados().
 */
const VIN_FUERZA = ['comprobante' => 4, 'pago' => 3, 'dispositivo' => 2];

/**
 * Las señales que alcanzan para frenar un alta nueva y para arrastrar un
 * bloqueo al resto del grupo.
 *
 * Hoy son TODAS las que existen, y la lista se queda igual a propósito: si
 * mañana vuelve una señal blanda, tiene que entrar en VIN_FUERZA sin entrar
 * acá, y este const es el lugar donde se decide eso.
 */
const VIN_SENALES_DURAS = ['comprobante', 'pago', 'dispositivo'];


/**
 * ¿Está bloqueado de nuestro lado?
 *
 * Ante un error de base devuelve **false**, y es a propósito: este chequeo
 * corre en cada turno del chat y en cada recarga. Si la consulta falla, dejar
 * pasar a un bloqueado un rato es molesto; cortarle el servicio a todos los
 * jugadores porque una columna no existe todavía es mucho peor.
 */
function vin_bloqueado(PDO $pdo, string $usuario): bool
{
    $usuario = trim($usuario);
    if ($usuario === '') { return false; }
    try {
        $st = $pdo->prepare("SELECT bloqueado FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Sin la migración 69 la columna no existe: el sistema sigue igual que antes.
        return false;
    }
}

/**
 * Bloquear o desbloquear. `$motivo` es para el operador que lo lea en tres
 * semanas, no para el jugador: nunca se le muestra.
 */
function vin_bloquear(PDO $pdo, string $usuario, bool $bloquear,
                      string $operador = '', string $motivo = ''): array
{
    $usuario = trim($usuario);
    if ($usuario === '') { return ['ok' => false, 'error' => 'Falta el usuario']; }

    try {
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'Ese usuario no existe']; }

        /* Al desbloquear se limpian motivo y operador en vez de dejarlos: si
           quedaran, la ficha seguiría mostrando "bloqueado por nahuel: hace
           trampa" sobre alguien que ya no lo está, y eso se lee como que sí. */
        $pdo->prepare(
            "UPDATE usuarios
                SET bloqueado = ?,
                    bloqueado_en     = " . ($bloquear ? 'NOW()' : 'NULL') . ",
                    bloqueado_por    = ?,
                    bloqueado_motivo = ?
              WHERE username = ?"
        )->execute([
            $bloquear ? 1 : 0,
            $bloquear ? mb_substr($operador, 0, 60) : null,
            $bloquear && $motivo !== '' ? mb_substr($motivo, 0, 300) : null,
            $usuario,
        ]);
    } catch (Throwable $e) {
        error_log('vin_bloquear: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar (¿falta la migración 69?)'];
    }
    return ['ok' => true, 'bloqueado' => $bloquear];
}

/**
 * Bloquear (o desbloquear) a la persona, no a una cuenta.
 *
 * POR QUE EXISTE (16/09/2026). Nahuel: *"es la misma persona. ¿Cómo se puede
 * hacer efectivo un bloqueo?"*. Bloquear una de tres cuentas no hace nada: la
 * persona sigue operando con las otras dos y en diez minutos abre una cuarta.
 * Un bloqueo que deja puertas abiertas no es un bloqueo, es una molestia.
 *
 * SOLO ARRASTRA LAS SEÑALES FUERTES (comprobante, cuenta bancaria, celular) y
 * NUNCA la IP. Es la misma línea que en todo este archivo, y acá es donde más
 * importa: arrastrar por IP bloquearía de una sola vez a todos los que
 * comparten una conexión -- que en esta base son quince cuentas, la mayoría
 * ajenas.
 *
 * NO ES RECURSIVO a propósito: se bloquea a los vinculados DIRECTOS del que
 * elegiste, no a los vinculados de los vinculados. Encadenar saltos convierte
 * dos coincidencias flojas en un grupo enorme, y nadie revisa una lista de
 * treinta nombres antes de apretar el botón.
 *
 * Devuelve ['ok', 'usuarios' => [los que cambiaron], 'error'?].
 */
function vin_bloquear_grupo(PDO $pdo, string $usuario, bool $bloquear,
                            string $operador = '', string $motivo = ''): array
{
    $r = vin_bloquear($pdo, $usuario, $bloquear, $operador, $motivo);
    if (empty($r['ok'])) { return $r; }

    $hechos = [$usuario];
    foreach (vin_relacionados($pdo, $usuario) as $v) {
        if (!vin_senal_fuerte($v['senales'])) { continue; }
        $sub = vin_bloquear($pdo, (string)$v['usuario'], $bloquear, $operador,
                            $motivo !== '' ? $motivo . ' (vinculada a ' . $usuario . ')'
                                           : 'vinculada a ' . $usuario);
        if (!empty($sub['ok'])) { $hechos[] = (string)$v['usuario']; }
    }
    return ['ok' => true, 'usuarios' => $hechos];
}

/** ¿Alguna de estas señales alcanza para arrastrar un bloqueo? */
function vin_senal_fuerte(array $senales): bool
{
    foreach ($senales as $s) {
        if (in_array($s, VIN_SENALES_DURAS, true)) { return true; }
    }
    return false;
}

/**
 * Las cuentas que parecen ser la misma persona que `$usuario`.
 *
 * Devuelve una lista ordenada de más a menos confiable:
 *   ['usuario', 'senales' => ['pago'], 'fuerza' => 3, 'detalle' => '...',
 *    'bloqueado' => bool]
 *
 * Es una sola pasada por señal (tres consultas), no una por cuenta: la ficha
 * del CRM lo pide en cada apertura y no puede costar N+1.
 *
 * NUNCA lanza: un vínculo que no se pudo calcular no puede impedir que se abra
 * la ficha de un jugador.
 */
function vin_relacionados(PDO $pdo, string $usuario, int $limite = 20): array
{
    $usuario = trim($usuario);
    if ($usuario === '') { return []; }

    $enc = [];   // usuario => ['senales'=>[], 'detalle'=>[]]
    $sumar = static function (string $otro, string $senal, string $detalle) use (&$enc, $usuario): void {
        $otro = trim($otro);
        if ($otro === '' || $otro === $usuario) { return; }
        if (!isset($enc[$otro])) { $enc[$otro] = ['senales' => [], 'detalle' => []]; }
        if (!in_array($senal, $enc[$otro]['senales'], true)) {
            $enc[$otro]['senales'][] = $senal;
            $enc[$otro]['detalle'][] = $detalle;
        }
    };

    /* ---- 1. Misma cuenta bancaria (la señal fuerte) ----
       Se cruza por CUIT y por CBU por separado porque un comprobante puede
       traer uno, el otro o los dos -- es la misma razón por la que
       huellas_pagador los guarda en columnas distintas. Los vacíos se
       excluyen: '' = '' haría match entre TODOS los que no informaron nada,
       que es la forma más rápida de acusar a media base de multicuenta. */
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT o.usuario, o.nombre,
                    IF(o.cuit <> '' AND o.cuit = h.cuit, o.cuit, o.cbu) AS ident
               FROM huellas_pagador h
               JOIN huellas_pagador o
                 ON o.usuario <> h.usuario
                AND ( (h.cuit <> '' AND o.cuit = h.cuit)
                   OR (h.cbu  <> '' AND o.cbu  = h.cbu) )
              WHERE h.usuario = ?
              LIMIT 50"
        );
        $st->execute([$usuario]);
        foreach ($st as $f) {
            $quien = trim((string)($f['nombre'] ?? ''));
            $sumar((string)$f['usuario'], 'pago',
                   'paga desde la misma cuenta bancaria'
                   . ($quien !== '' ? ' (' . $quien . ')' : ''));
        }
    } catch (Throwable $e) { error_log('vin_relacionados/pago: ' . $e->getMessage()); }

    /* ---- 1b. EL MISMO COMPROBANTE DECLARADO DESDE DOS CUENTAS ----
       No es solo "son la misma persona": es que alguien reclamó la misma
       transferencia dos veces. Por eso pesa más que la cuenta bancaria --
       compartir banco puede ser una familia; declarar el mismo número de
       operación no tiene lectura inocente.

       Se exigen 6 caracteres: los números de operación cortos ("1", "123") los
       tipea cualquiera y atarían a desconocidos. Y los vacíos quedan afuera
       por lo mismo que en las huellas. */
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT o.usuario, o.trx_declarada
               FROM recargas r
               JOIN recargas o
                 ON o.trx_declarada = r.trx_declarada
                AND o.usuario <> r.usuario
              WHERE r.usuario = ?
                AND r.trx_declarada IS NOT NULL
                AND CHAR_LENGTH(TRIM(r.trx_declarada)) >= 6
              LIMIT 50"
        );
        $st->execute([$usuario]);
        foreach ($st as $f) {
            $sumar((string)$f['usuario'], 'comprobante',
                   'declaró LA MISMA transferencia (operación '
                   . trim((string)$f['trx_declarada']) . ')');
        }
    } catch (Throwable $e) {
        // trx_declarada es de la migracion 45: sin ella, esta señal no existe.
    }

    /* ---- 2. Mismo celular ----
       Sale de dispositivos_usuarios (migración 69), que guarda el HISTORIAL.
       `dispositivos` no sirve acá: su UNIQUE por device_id pisa el usuario
       cuando entra otra cuenta, o sea que borra justo lo que se busca. */
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT o.usuario, o.usos
               FROM dispositivos_usuarios d
               JOIN dispositivos_usuarios o
                 ON o.device_id = d.device_id AND o.usuario <> d.usuario
              WHERE d.usuario = ?
              LIMIT 50"
        );
        $st->execute([$usuario]);
        foreach ($st as $f) {
            $sumar((string)$f['usuario'], 'dispositivo', 'entró desde el mismo celular');
        }
    } catch (Throwable $e) {
        // Sin la migración 69 esta señal simplemente no existe todavía.
    }

    /* ---- 3. Misma IP al registrarse: NO SE USA, Y NO ES POR SER DÉBIL ----
       Acá había una tercera señal que cruzaba `altas.ip`. Se sacó el
       16/09/2026, y el motivo no es el que decía el comentario que estaba acá
       ("indicio débil, un barrio entero comparte el NAT"): eso era cierto pero
       era lo de menos.

       LO QUE PASABA ES QUE NO HABÍA NI UNA IP DE JUGADOR EN LA BASE. El sitio
       quedó detrás de Cloudflare y `alta_ip()` guardaba `REMOTE_ADDR`, o sea
       el edge de la CDN:

           162.158.195.184   124 cuentas
           172.69.255.142     45 cuentas
           198.41.230.150     16 cuentas

       21 "IPs compartidas" tocando 237 cuentas, todas rangos de Cloudflare.
       VIN_IP_MAX_CUENTAS tapaba las peores, pero las de 2, 3 y 4 cuentas
       pasaban el filtro y salían al CRM como "parecen ser la misma persona".
       Cuentas legítimas, acusadas por compartir un servidor de la CDN.

       EL DAÑO NO ERA EL FALSO POSITIVO SUELTO, ERA QUE EL AVISO DEJA DE
       LEERSE. Nahuel lo dijo así el 16/09: *"si no es seguro que es una cuenta
       falsa, preferiría que ahí no aparezca nada... que sean datos
       corroborables"*. Un aviso que miente a veces no es un aviso al 80%: es
       ruido, y termina tapando al comprobante repetido que sí importa.

       `alta_ip()` ya quedó arreglado (lee CF-Connecting-IP cuando la conexión
       viene de Cloudflare, ver api/ip_cliente.php), así que de acá en adelante
       la columna va a tener IPs de verdad. Aun así esta señal NO vuelve sola:
       una IP correcta sigue sin ser corroborable --familia, WiFi compartido,
       NAT de la telefónica-- y el criterio es que el aviso solo diga cosas que
       el operador pueda poner sobre la mesa. La IP queda como dato para
       investigar a mano, no como acusación en la ficha.

       Las tres que quedan son todas verificables contra algo:
         comprobante  el mismo número de operación declarado dos veces
         pago         el mismo CUIT/CBU, que sale del MAIL DEL BANCO
         dispositivo  el mismo device_id, un UUID por instalación de la app
                      (comprobado: los 4 casos compartidos en producción son
                      instalaciones reales, no modelos de teléfono repetidos) */


    if (!$enc) { return []; }

    /* Estado de bloqueo de los encontrados, en UNA consulta. */
    $bloq = [];
    try {
        $ph = implode(',', array_fill(0, count($enc), '?'));
        $st = $pdo->prepare("SELECT username, bloqueado FROM usuarios WHERE username IN ($ph)");
        $st->execute(array_keys($enc));
        foreach ($st as $f) { $bloq[(string)$f['username']] = (bool)$f['bloqueado']; }
    } catch (Throwable $e) { /* sin migración 69: ninguno figura bloqueado */ }

    $salida = [];
    foreach ($enc as $otro => $d) {
        $fuerza = 0;
        foreach ($d['senales'] as $s) { $fuerza = max($fuerza, VIN_FUERZA[$s] ?? 0); }
        $salida[] = [
            'usuario'   => $otro,
            'senales'   => $d['senales'],
            'fuerza'    => $fuerza,
            'detalle'   => implode(' · ', $d['detalle']),
            'bloqueado' => $bloq[$otro] ?? false,
        ];
    }
    /* Más fuerte primero, y a igual fuerza el que tiene MÁS señales: dos
       coincidencias distintas sobre la misma cuenta valen más que una. */
    usort($salida, static function ($a, $b) {
        return [$b['fuerza'], count($b['senales'])] <=> [$a['fuerza'], count($a['senales'])];
    });

    return array_slice($salida, 0, $limite);
}

/**
 * ¿Hay alguna cuenta BLOQUEADA detrás de estas señales? Es el chequeo del alta
 * nueva, y es la pieza que de verdad corta la cadena.
 *
 * `$senales`: ['cuit' => ?, 'cbu' => ?, 'device_id' => ?]. La IP NO se acepta a
 * propósito — ver el encabezado: frenar altas por IP deja afuera al hermano, al
 * vecino y a medio barrio detrás del NAT de la telefónica. Para eso está el
 * aviso, que lo mira una persona.
 *
 * Devuelve el usuario bloqueado que coincidió, o null.
 */
function vin_bloqueado_por_senal(PDO $pdo, array $senales): ?string
{
    $cuit   = trim((string)($senales['cuit'] ?? ''));
    $cbu    = trim((string)($senales['cbu'] ?? ''));
    $device = trim((string)($senales['device_id'] ?? ''));

    if ($cuit !== '' || $cbu !== '') {
        try {
            $st = $pdo->prepare(
                /* COLLATE EXPLICITO, o esto no corre: `usuarios` quedo en
                   uca1400 y las tablas del CRM en utf8mb4_unicode_ci (ver
                   CLAUDE.md). Sin el, MySQL tira "Illegal mix of collations"
                   AL EJECUTAR -- php -l no lo ve, y el catch de abajo lo
                   convertia en "no hay nadie bloqueado", o sea que el freno
                   dejaba pasar a todos en silencio. */
                "SELECT h.usuario
                   FROM huellas_pagador h
                   JOIN usuarios u
                     ON u.username COLLATE utf8mb4_unicode_ci = h.usuario
                    AND u.bloqueado = 1
                  WHERE (? <> '' AND h.cuit = ?) OR (? <> '' AND h.cbu = ?)
                  LIMIT 1"
            );
            $st->execute([$cuit, $cuit, $cbu, $cbu]);
            $u = $st->fetchColumn();
            if ($u) { return (string)$u; }
        } catch (Throwable $e) { error_log('vin_bloqueado_por_senal/pago: ' . $e->getMessage()); }
    }

    if ($device !== '') {
        try {
            $st = $pdo->prepare(
                "SELECT d.usuario
                   FROM dispositivos_usuarios d
                   JOIN usuarios u
                     ON u.username COLLATE utf8mb4_unicode_ci = d.usuario
                    AND u.bloqueado = 1
                  WHERE d.device_id = ?
                  LIMIT 1"
            );
            $st->execute([$device]);
            $u = $st->fetchColumn();
            if ($u) { return (string)$u; }
        } catch (Throwable $e) { /* sin migración 69 */ }
    }

    return null;
}

/**
 * Anotar que esta cuenta usó este celular. Best-effort y en el camino del
 * registro de dispositivos, que ya corre siempre.
 *
 * `usos` sube en cada pasada: sirve para pesar el indicio después. Tres
 * cuentas que entraron una vez cada una el mismo día no es lo mismo que tres
 * que se usan todas las semanas.
 */
function vin_anotar_dispositivo(PDO $pdo, string $deviceId, string $usuario): void
{
    $deviceId = trim($deviceId);
    $usuario  = trim($usuario);
    if ($deviceId === '' || $usuario === '') { return; }
    try {
        $pdo->prepare(
            "INSERT INTO dispositivos_usuarios (device_id, usuario)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE usos = usos + 1, ultima_vez = NOW()"
        )->execute([mb_substr($deviceId, 0, 64), mb_substr($usuario, 0, 50)]);
    } catch (Throwable $e) {
        // Sin la migración 69 no se anota nada y el push sigue funcionando
        // igual: esta tabla solo alimenta un aviso.
    }
}

/**
 * Las cuentas que son LA MISMA PERSONA que $usuario a nivel BANCARIO: solo
 * las señales 'pago' (misma cuenta bancaria) y 'comprobante' (declaró la
 * misma transferencia).
 *
 * El dispositivo queda afuera A PROPÓSITO, aunque sea señal dura para
 * arrastrar bloqueos: un celular se presta (la pareja, el hermano, el
 * locutorio), y dos hermanos que pagan cada uno de su banco son dos clientes
 * reales. Negarle un bono o acusar de multicuenta por compartir teléfono es
 * castigar a inocentes; la cuenta bancaria y el número de operación no
 * tienen esa lectura. La IP, menos todavía.
 */
function vin_misma_persona(PDO $pdo, string $usuario): array
{
    $out = [];
    foreach (vin_relacionados($pdo, $usuario) as $v) {
        if (array_intersect($v['senales'], ['pago', 'comprobante'])) {
            $out[] = (string)$v['usuario'];
        }
    }
    return $out;
}

/**
 * ¿Alguna OTRA cuenta de la misma persona (vin_misma_persona) ya cobró este
 * bono? `$origen` es el de `movimientos`: 'bono_bienvenida' o 'bono_app'.
 *
 * Es el candado ANTI-MULTICUENTA de los bonos (pedido de Nahuel,
 * 16/09/2026): el candado por usuario que ya tienen los dos bonos sigue
 * igual; este agrega «por persona». Devuelve el usuario que ya lo cobró, o
 * null.
 *
 * Ante un error devuelve null (= se paga): cortar TODOS los bonos porque
 * una tabla de vínculos falta sería cambiar un abuso puntual por un problema
 * general — el mismo criterio que vin_bloqueado().
 */
function vin_bono_cobrado_por_grupo(PDO $pdo, string $usuario, string $origen): ?string
{
    try {
        $grupo = vin_misma_persona($pdo, $usuario);
        if (!$grupo) { return null; }
        $ph = implode(',', array_fill(0, count($grupo), '?'));
        $st = $pdo->prepare(
            "SELECT usuario FROM movimientos
              WHERE origen = ? AND monto > 0 AND usuario IN ($ph)
              LIMIT 1"
        );
        $st->execute(array_merge([$origen], $grupo));
        $u = $st->fetchColumn();
        return $u !== false && $u !== null ? (string)$u : null;
    } catch (Throwable $e) {
        error_log('vin_bono_cobrado_por_grupo: ' . $e->getMessage());
        return null;
    }
}

/**
 * El AVISO al jugador multicuenta (pedido de Nahuel, 16/09/2026): cuando el
 * sistema descubre que sus cuentas son la misma persona —a nivel bancario,
 * ver vin_misma_persona()— se le dice de frente, UNA vez por cuenta: que lo
 * vimos, que los bonos son por persona, y que siga con una sola cuenta para
 * no llegar a la restricción.
 *
 * Se dispara desde donde NACEN las señales (rl_aprender_huella al acreditar
 * un pago, rl_declarar_pago al declarar un comprobante), así el aviso llega
 * en el momento en que el vínculo se vuelve un hecho y no en un cron.
 *
 * La idempotencia es la fila en `notificaciones` con origen 'multicuenta':
 * si ya existe para este usuario, no se repite nada. Best-effort total:
 * nunca lanza, y jamás puede frenar la acreditación desde la que se llamó.
 */
function vin_avisar_multicuenta(PDO $pdo, string $usuario): void
{
    $usuario = trim($usuario);
    if ($usuario === '') { return; }

    try {
        // ¿Ya se le avisó a ESTA cuenta? (La push queda en `notificaciones`
        // aunque el jugador nunca la abra: sirve de marca durable.)
        $st = $pdo->prepare(
            "SELECT 1 FROM notificaciones
              WHERE usuario = ? AND origen = 'multicuenta' LIMIT 1"
        );
        $st->execute([$usuario]);
        if ($st->fetchColumn()) { return; }

        $grupo = vin_misma_persona($pdo, $usuario);
        if (!$grupo) { return; }

        $texto = 'Detectamos que hay más de una cuenta creada por la misma persona, '
               . 'y la tuya es una de ellas. Los bonos se pagan una sola vez por '
               . 'persona: nuestro sistema es anti-multicuenta. Seguí jugando con '
               . 'una sola cuenta, así no tenemos que restringirte el acceso.';

        // La push (y la marca de "ya avisado"). Si notificaciones_lib no está
        // cargada se trae acá: sin la push no queda marca y el aviso se
        // repetiría en cada carga.
        if (!function_exists('notif_crear') && is_file(__DIR__ . '/notificaciones_lib.php')) {
            require_once __DIR__ . '/notificaciones_lib.php';
        }
        $notifId = 0;
        if (function_exists('notif_crear')) {
            $notifId = notif_crear($pdo, $usuario, '⚠️ Varias cuentas detectadas',
                                   $texto, 'aviso', null, 'multicuenta');
        }
        if ($notifId <= 0) {
            // Sin la marca durable no hay idempotencia: mejor no mandar el
            // chat tampoco y reintentar entero en la próxima señal, que
            // repetir la acusación en cada carga.
            return;
        }

        // El mismo texto en el chat, que es donde el jugador de verdad lee.
        if (!function_exists('crm_avisar_jugador') && is_file(__DIR__ . '/crm_lib.php')) {
            require_once __DIR__ . '/crm_lib.php';
        }
        if (function_exists('crm_avisar_jugador')) {
            crm_avisar_jugador($pdo, $usuario, '⚠️ ' . $texto,
                               ['multicuenta_aviso' => true]);
        }

        // Y que el operador se entere de que se avisó (con quién matchea lo
        // ve en la ficha). Dedupe por usuario: una línea por cuenta avisada.
        //
        // NUNCA con una transacción abierta: tg_evento es un curl sincrónico
        // de hasta 8 segundos, y sostener los locks del caller mientras se
        // habla con Telegram es un cuelgue servido. Los callers correctos
        // llaman post-commit; si alguno futuro llega en transacción, pierde
        // solo la línea de Telegram (el push y el chat salen igual).
        if (!function_exists('tg_evento') && is_file(__DIR__ . '/telegram_lib.php')) {
            require_once __DIR__ . '/telegram_lib.php';
        }
        if (function_exists('tg_evento') && !$pdo->inTransaction()) {
            tg_evento($pdo, 'salud', '👥 Multicuenta avisada', [
                'Jugador'   => $usuario,
                'Vinculada' => implode(', ', array_slice($grupo, 0, 5)),
                'Qué pasó'  => 'Se le avisó que los bonos son por persona y que use una sola cuenta.',
            ], 'multicuenta_' . $usuario);
        }
    } catch (Throwable $e) {
        error_log('vin_avisar_multicuenta: ' . $e->getMessage());
    }
}
