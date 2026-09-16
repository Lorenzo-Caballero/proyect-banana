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
 *   CUIT/CBU   `huellas_pagador`. La plata salió de la MISMA cuenta bancaria.
 *              Es la más fuerte de lejos: abrir una cuenta de banco a nombre
 *              de otro no es algo que se haga para esquivar un bloqueo.
 *
 *   Dispositivo  `dispositivos_usuarios`. El mismo celular. Fuerte, pero un
 *              teléfono se presta: la pareja, el hermano, el locutorio.
 *
 *   IP del alta  `altas.ip`. La más débil, y por MUCHO. Un barrio entero
 *              detrás del NAT de la telefónica comparte IP; una familia con el
 *              mismo WiFi también. Sola no prueba nada.
 *
 * Por eso cada vínculo sale etiquetado con su fuerza y NADA se bloquea solo.
 * La diferencia importa de verdad: bloquear por IP a dos hermanos que juegan
 * de la misma casa es perder dos clientes reales para atajar a uno falso.
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

/** Fuerza de un vínculo. Ordena de más a menos confiable. */
const VIN_FUERZA = ['pago' => 3, 'dispositivo' => 2, 'ip' => 1];

/** Sólo estas frenan un alta nueva. La IP nunca: ver el encabezado. */
const VIN_SENALES_DURAS = ['pago', 'dispositivo'];

/**
 * Pasadas cuántas cuentas una IP deja de decir algo sobre una persona.
 *
 * MEDIDO EL 16/09/2026, y por eso existe esta constante: la primera corrida en
 * producción vinculó a @holasofito763 con QUINCE cuentas por IP, entre ellas
 * varias de prueba evidentes (holaTesttet262, holaTeeettttgf695) y tres altas
 * del chat de esa misma madrugada. No es una persona con quince cuentas: es una
 * IP por la que pasan todos -- un proxy, una CDN, o simplemente la conexión
 * desde la que se venía probando.
 *
 * La regla vale igual sin saber la causa, que es lo bueno de ponerla acá: si
 * una IP tiene muchas cuentas, lo que describe es una CONEXIÓN COMPARTIDA, no
 * un jugador. Cuatro deja lugar a una familia; de ahí para arriba es
 * infraestructura.
 *
 * Y se corrige sola: el día que las IP se capturen bien, las de una persona van
 * a tener dos o tres cuentas y volverán a contar, sin tocar nada.
 */
const VIN_IP_MAX_CUENTAS = 4;


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
 * Las cuentas que parecen ser la misma persona que `$usuario`.
 *
 * Devuelve una lista ordenada de más a menos confiable:
 *   ['usuario', 'senales' => ['pago','ip'], 'fuerza' => 3, 'detalle' => '...',
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

    /* ---- 3. Misma IP al registrarse (la débil) ----
       Se acota a 7 días: una IP dinámica cambia de dueño, y cruzar altas de
       hace tres meses por IP junta a desconocidos.

       Y SOBRE TODO: se descartan las IP con muchas cuentas. La primera corrida
       en producción (16/09/2026) ató a un jugador con QUINCE cuentas --varias
       de prueba, y tres altas del chat de esa madrugada-- porque todas
       comparten una misma IP de salida. Una IP así no describe a una persona
       sino a una conexión compartida, y contarla convierte el aviso en ruido:
       si todos están vinculados con todos, el aviso no dice nada y encima
       invita a bloquear a inocentes. Ver VIN_IP_MAX_CUENTAS.

       Las de loopback tampoco: un alta creada desde el CRM o por un script
       lleva la IP nuestra. */
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT o.usuario
               FROM altas a
               JOIN altas o
                 ON o.ip = a.ip AND o.usuario <> a.usuario
                AND ABS(TIMESTAMPDIFF(DAY, o.pedido_en, a.pedido_en)) <= 7
               JOIN (SELECT ip FROM altas
                      WHERE ip IS NOT NULL AND ip <> ''
                      GROUP BY ip
                     HAVING COUNT(DISTINCT usuario) <= " . VIN_IP_MAX_CUENTAS . ") propia
                 ON propia.ip = a.ip
              WHERE a.usuario = ?
                AND a.ip IS NOT NULL AND a.ip <> ''
                AND a.ip NOT IN ('127.0.0.1', '::1', 'localhost')
              LIMIT 50"
        );
        $st->execute([$usuario]);
        foreach ($st as $f) {
            $sumar((string)$f['usuario'], 'ip', 'se registró desde la misma IP (indicio débil)');
        }
    } catch (Throwable $e) { error_log('vin_relacionados/ip: ' . $e->getMessage()); }

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
