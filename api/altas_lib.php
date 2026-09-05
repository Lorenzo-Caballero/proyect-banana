<?php
/**
 * Logica compartida de la cola de altas (tabla `altas`).
 *
 * La usan dos endpoints con permisos MUY distintos:
 *
 *   altas_cola.php   privado, con X-API-Key. Lo consume el bot del VPS y el CRM.
 *   crear_cuenta.php publico, sin clave. Lo consume la landing.
 *
 * Esta separado justamente por eso: la validacion tiene que ser identica en los
 * dos lados, pero el endpoint publico NO puede tener acceso a lo que devuelve
 * el privado (las contrasenas en claro de toda la cola).
 *
 * Requiere las migraciones sql/13_cola_altas.sql y sql/14_altas_landing.sql.
 */

declare(strict_types=1);

// Freno por IP. Cada alta encolada hace que un bot abra Chromium y opere el
// panel de agentes de verdad, asi que en un endpoint publico no es una
// formalidad: sin freno, alguien encola mil altas en un minuto y te quema la
// cuenta de agente.
//
// APAGADO por defecto (0 = sin limite), en TODOS los endpoints.
//
// Frenar un alta es perder un cliente: el que quiere abrir una cuenta la
// quiere ahora, y un "espera una hora" lo manda a la competencia. El abuso,
// si algun dia aparece, se ataja mirando la cola y prendiendo esto — no al
// reves, castigando de entrada a todos los que si son clientes.
//
// Para prenderlo, en api/config.local.php:
//     'ALTAS_POR_IP_HORA' => 20,
//     'ALTAS_POR_IP_DIA'  => 100,
const ALTAS_POR_IP_HORA = 0;
const ALTAS_POR_IP_DIA  = 0;

// Clave con la que se crean TODAS las cuentas nuevas, por chat y por la
// landing. Decision del producto: que el jugador no tenga que copiar ni
// guardar nada para entrar la primera vez.
//
// El precio hay que tenerlo claro: es la misma para todos, y los nombres de
// usuario se ven en el chat, en el CRM y en el panel, asi que cualquiera que
// sepa un nombre puede entrar a esa cuenta mientras el jugador no la cambie.
// Para volver a una clave por cuenta alcanza con que esto devuelva
// alta_clave_random(): los dos endpoints la piden por aca.
const ALTA_CLAVE_FIJA = '12345678';

function alta_clave_nueva(): string
{
    return ALTA_CLAVE_FIJA;
}

/**
 * Cuantas altas por hora/dia tolera una IP. Lee la config si esta, y si no
 * cae a las constantes de arriba. 0 = sin limite.
 */
function alta_limite_hora(): int
{
    $v = function_exists('cfg') ? cfg('ALTAS_POR_IP_HORA', '') : '';
    return ($v === '' || $v === null) ? ALTAS_POR_IP_HORA : max(0, (int)$v);
}

function alta_limite_dia(): int
{
    $v = function_exists('cfg') ? cfg('ALTAS_POR_IP_DIA', '') : '';
    return ($v === '' || $v === null) ? ALTAS_POR_IP_DIA : max(0, (int)$v);
}

/**
 * REMOTE_ADDR y nada mas. Las cabeceras tipo X-Forwarded-For las manda el
 * cliente: confiar en ellas es dejar el limite sin efecto con un header.
 * Si algun dia el sitio queda detras de Cloudflare, ACA hay que cambiarlo.
 */
function alta_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Devuelve el mensaje de error, o null si los datos sirven.
 *
 * Se valida antes de encolar y no en el bot: si un usuario invalido entra a la
 * cola, el bot lo descubre recien despues de abrir Chromium, loguearse y
 * llenar el formulario.
 */
function alta_validar(string $usuario, string $password, string $email): ?string
{
    if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $usuario)) {
        return 'Usuario invalido: 3 a 64 caracteres, letras, numeros, punto, guion o guion bajo';
    }
    $largo = mb_strlen($password);
    if ($largo < 6 || $largo > 128) {
        return 'La password tiene que tener entre 6 y 128 caracteres';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email invalido';
    }
    return null;
}

/**
 * A partir de lo que el jugador escribió como nombre, devuelve un username
 * LIBRE (sin chocar con `usuarios` ni con un pedido ya en `altas`), sin que
 * tenga que reintentar a mano.
 *
 * Solo la usa la landing pública (crear_cuenta.php): ahí "elegí otro
 * usuario" es fricción pura -- el jugador puso su nombre una vez y espera
 * una cuenta, no una negociación de username. El chatbot y el CRM siguen
 * pidiendo el nombre EXACTO tal cual lo escribió el operador/jugador (no se
 * tocó esa lógica).
 *
 * Estrategia: saneás el texto a lo que el panel acepta, y si ya está
 * ocupado le vas agregando un sufijo numérico corto (nombre2, nombre3, ...)
 * hasta encontrar uno libre. Nunca vuelve null: si el saneo deja un string
 * vacío/muy corto, cae a un prefijo genérico.
 */
/**
 * ¿El mensaje del bot dice que el nombre de usuario estaba ocupado?
 *
 * POR QUE ES UNA HEURISTICA SOBRE TEXTO Y NO UN CODIGO
 * El bot no devuelve codigos: informa lo que vio. Y lo que ve es de varias
 * formas -- el panel a veces responde un error de validacion en pantalla, y
 * otras un HTTP 200 con el jugador ausente del listado (que es como se
 * manifiesta el nombre tomado por OTRO agente, el caso mas comun).
 *
 * Se prefiere pecar de conservador: si no reconoce el motivo, NO renombra y el
 * alta reintenta como siempre. Un renombre de mas le cambia el nombre a un
 * jugador sin necesidad; uno de menos solo deja las cosas como estaban.
 */
function alta_parece_nombre_ocupado(string $mensaje): bool
{
    return alta_nombre_ocupado_seguro($mensaje)
        || alta_nombre_ocupado_sospecha($mensaje);
}

/**
 * LO DIJO LA PLATAFORMA. No hay nada que interpretar: renombrar de una.
 */
function alta_nombre_ocupado_seguro(string $mensaje): bool
{
    $m = mb_strtolower($mensaje);
    /* 'already exist' SIN la s final, a proposito: el mensaje textual de la
       plataforma es "User with username: juan - already exist" (verificado el
       2/9/2026 en la respuesta de la API). Buscar "already exists" no matcheaba
       y el renombre no se disparaba nunca -- el alta reintentaba con el MISMO
       nombre las tres veces y se rendia. Como se busca por substring, esta
       forma cubre tambien el plural. */
    foreach (['already exist', 'ya existe'] as $pista) {
        if (mb_strpos($m, $pista) !== false) { return true; }
    }
    return false;
}

/**
 * LO DEDUJO EL BOT: mando el formulario, no exploto, y despues el jugador no
 * aparecia en el listado. Es un indicio, no una prueba.
 *
 * POR QUE ESTA SEPARADO (4/9/2026): esa misma frase la produce una sesion
 * caida. Si la sesion se murio, el listado que el bot consulta para verificar
 * tampoco funciona, asi que "no figura" no significa que el nombre este
 * tomado -- significa que no pudo mirar. En el log de esa noche se ve entero:
 * cuatro intentos seguidos de la misma persona (holaChela1560, 6877, 2768,
 * 1879) diagnosticados como "nombre tomado", y minutos despues la API crea
 * cuentas al primer intento. El nombre nunca habia estado ocupado.
 *
 * El costo de creerle era alto: cada sospecha renombraba al jugador y quemaba
 * un intento, asi que un problema de sesion de dos minutos se llevaba puestos
 * todos los reintentos y la persona terminaba sin cuenta.
 *
 * No se descarta, porque cuando el camino rapido no esta disponible es la
 * unica señal que hay. Se le pide una confirmacion mas: ver alta_debe_renombrar().
 */
function alta_nombre_ocupado_sospecha(string $mensaje): bool
{
    $m = mb_strtolower($mensaje);
    foreach (['no figura', 'ya este tomado', 'ya está tomado',
              'ocupado', 'no aparece en el listado'] as $pista) {
        if (mb_strpos($m, $pista) !== false) { return true; }
    }
    return false;
}

/**
 * Si corresponde cambiarle el nombre al jugador tras este fallo.
 *
 * Con certeza, siempre. Con sospecha, recien a partir del SEGUNDO intento: si
 * fue una sesion caida, el reintento con el MISMO nombre suele salir bien --
 * y salir bien con el nombre que la persona eligio es mejor que salir bien con
 * uno inventado. Si el nombre estaba de verdad ocupado, el segundo intento
 * vuelve a fallar y ahi si se renombra, con un solo intento de costo.
 *
 * $intentos es el contador de la fila, ya incrementado por 'pendientes'.
 */
function alta_debe_renombrar(string $mensaje, int $intentos): bool
{
    if (alta_nombre_ocupado_seguro($mensaje)) { return true; }
    return alta_nombre_ocupado_sospecha($mensaje) && $intentos >= 2;
}

/** Prefijo de los usuarios que genera la landing.
 *
 *  "holaJuan" en vez de "Juan427". Dos motivos, y el segundo es el importante:
 *
 *  1. Se dicta y se recuerda. Un jugador que llama por telefono puede decir su
 *     usuario; "Juan427" se olvida apenas cierra la pantalla.
 *  2. NO CHOCA. En ganamos el nombre es unico en TODA la plataforma y esta
 *     saturada: el 2/9/2026 fallaron Juan676, Juan565, Juan557 y Martin109,
 *     cuatro de cuatro. El patron "Nombre + numeros" lo usa todo el mundo hace
 *     años; "hola + Nombre" no lo usa casi nadie.
 */
const ALTA_PREFIJO = 'hola';

function alta_usuario_disponible(PDO $pdo, string $nombreCrudo, int $ronda = 0): string
{
    // Mismo alfabeto que exige alta_validar(): letras, números, punto,
    // guion, guion bajo. Se sanea ACA (no se confía en que el frontend ya
    // lo haya hecho) porque esta función también decide el username final.
    //
    // Tildes/eñes ANTES del preg_replace ASCII: "María" sin esto perdía la
    // "í" entera (preg_replace sin flag Unicode corta bytes multibyte a lo
    // bruto) -- iconv translitera a lo más parecido en ASCII ("Maria") y
    // recién ahí se filtra lo que no entra en el alfabeto del panel.
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $nombreCrudo);
    $base = preg_replace('/[^a-zA-Z0-9._-]/', '', $translit !== false ? $translit : $nombreCrudo);
    $base = mb_substr((string)$base, 0, 40); // deja lugar al sufijo sin pasar de 64
    // 4 y no 3: alta_validar() acepta desde 3, pero el PANEL rechaza los muy
    // cortos y ahi el alta muere recien cuando el bot llena el formulario --
    // con el jugador ya esperando. Se alarga aca, antes de encolar nada.
    if (mb_strlen($base) < 4) {
        $base = 'jugador' . $base;
    }

    /* El prefijo va SIEMPRE, y los numeros SOLO si hacen falta.

       `$ronda` es cuantas veces ya nos rechazaron este alta, y sube la entropia
       de a poco: se empieza por el nombre mas lindo posible y solo se ensucia
       cuando la plataforma obliga. Asi el caso normal -- que es el 99% -- se
       lleva "holaJuan" y no "holaJuan8471".

           ronda 0     holaJuan
           ronda 1-2   holaJuan + 2 digitos
           ronda 3+    holaJuan + 4 digitos

       Idempotente con el prefijo: si el nombre YA empieza con el, no se vuelve
       a agregar. Sin esto, cada renombre lo apilaba ("holaholaJuan"), porque el
       que renombra parte del nombre anterior. */
    $pre = ALTA_PREFIJO;
    if (stripos($base, $pre) !== 0) {
        $base = $pre . ucfirst($base);
    }

    for ($intento = 0; $intento < 50; $intento++) {
        $vuelta = $ronda + $intento;
        if ($vuelta === 0) {
            $candidato = $base;                          // holaJuan
        } elseif ($vuelta <= 2) {
            $candidato = $base . random_int(10, 99);      // holaJuan47
        } else {
            $candidato = $base . random_int(1000, 9999);  // holaJuan8471
        }

        // Un solo viaje a la base por candidato: existe en `usuarios` O hay
        // un pedido no fallido en `altas` con ese nombre. 'error' no cuenta
        // como ocupado -- ver alta_encolar(), ese estado se puede reintentar
        // con el mismo usuario.
        $st = $pdo->prepare(
            "SELECT
               (SELECT 1 FROM usuarios WHERE username = ? LIMIT 1) AS en_usuarios,
               (SELECT 1 FROM altas WHERE usuario = ? AND estado <> 'error' LIMIT 1) AS en_altas"
        );
        $st->execute([$candidato, $candidato]);
        $r = $st->fetch();
        if (!$r['en_usuarios'] && !$r['en_altas']) {
            return $candidato;
        }
    }

    // Extremadamente improbable (50 intentos con sufijo random fallando
    // todos): último recurso, con timestamp para garantizar unicidad.
    return $base . substr((string)time(), -6);
}

/**
 * Devuelve el mensaje de rechazo si la IP se paso del limite, o null.
 * Solo cuenta pedidos que llegaron a entrar: los rechazados no suman.
 */
function alta_limite_superado(PDO $pdo, string $ip): ?string
{
    $porHora = alta_limite_hora();
    $porDia  = alta_limite_dia();

    // Los dos en 0 = freno apagado: ni siquiera consultamos la base.
    if ($ip === '' || ($porHora === 0 && $porDia === 0)) {
        return null;
    }

    $q = $pdo->prepare(
        "SELECT SUM(pedido_en > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ultima_hora,
                COUNT(*)                                          AS ultimo_dia
           FROM altas
          WHERE ip = ?
            AND pedido_en > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $q->execute([$ip]);
    $r = $q->fetch();

    if ($porHora > 0 && (int)($r['ultima_hora'] ?? 0) >= $porHora) {
        return 'Ya pediste varias cuentas hace un rato. Esperá una hora e intentá de nuevo.';
    }
    if ($porDia > 0 && (int)($r['ultimo_dia'] ?? 0) >= $porDia) {
        return 'Alcanzaste el máximo de cuentas por día. Escribinos por chat si necesitás otra.';
    }
    return null;
}

/**
 * Mete un pedido en la cola.
 *
 * Devuelve ['http' => codigo, 'cuerpo' => array] para que cada endpoint lo
 * emita como quiera. No imprime ni corta la ejecucion.
 */
function alta_encolar(PDO $pdo, array $d): array
{
    $usuario  = trim((string)($d['usuario'] ?? ''));
    $password = (string)($d['password'] ?? '');
    $email    = trim((string)($d['email'] ?? ''));
    $nombre   = trim((string)($d['nombre'] ?? ''));
    $apellido = trim((string)($d['apellido'] ?? ''));
    $origen   = mb_substr(trim((string)($d['origen'] ?? 'crm')), 0, 32);
    $ip       = (string)($d['ip'] ?? '');
    // Solo el chatbot los manda: la clave queda guardada aparte para
    // entregarsela al jugador RECIEN cuando el bot confirme el alta.
    $entSid   = mb_substr(trim((string)($d['entrega_sid'] ?? '')), 0, 64);
    $entCla   = (string)($d['entrega_clave'] ?? '');
    // De que publicista vino (landing con ?pub=<slug>) y los identificadores
    // de Meta que agarro en el camino. Solo la landing publica los manda.
    $pubId    = isset($d['publicista_id']) ? (int)$d['publicista_id'] : 0;
    $fbclid   = mb_substr(trim((string)($d['fbclid'] ?? '')), 0, 255);
    $fbp      = mb_substr(trim((string)($d['fbp']    ?? '')), 0, 80);
    $fbc      = mb_substr(trim((string)($d['fbc']    ?? '')), 0, 120);
    // Navegador y URL del jugador (migracion 51). Se guardan para poder mandarle
    // a Meta los datos de LA PERSONA en los eventos que dispara despues el bot,
    // donde $_SERVER es del servidor. Ver el docblock de la migracion.
    $uaJug    = mb_substr(trim((string)($d['ua']          ?? '')), 0, 400);
    $urlJug   = mb_substr(trim((string)($d['url_landing'] ?? '')), 0, 255);

    $error = alta_validar($usuario, $password, $email);
    if ($error !== null) {
        return ['http' => 400, 'cuerpo' => ['ok' => false, 'error' => $error]];
    }

    // Ya es jugador: no tiene sentido mandarlo al panel, el alta va a fallar
    // igual por usuario repetido. `usuarios` y `altas` comparten collation
    // (ver migracion 13), asi que esta comparacion no necesita COLLATE.
    $ya = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
    $ya->execute([$usuario]);
    if ($ya->fetchColumn()) {
        return ['http' => 409, 'cuerpo' => ['ok' => false, 'error' => 'Ese usuario ya existe']];
    }

    $base = [
        $usuario,
        $password,
        $email    !== '' ? $email    : null,
        $nombre   !== '' ? $nombre   : null,
        $apellido !== '' ? $apellido : null,
        $origen,
        $ip !== '' ? $ip : null,
    ];

    try {
        try {
            // Nivel 0: todo, incluidos el navegador y la URL del jugador
            // (migracion 51). Si esas dos columnas no existen todavia, cae al
            // nivel 1 y el alta entra igual -- solo pierde calidad de match en
            // los eventos de Meta, no la atribucion.
            $ins = $pdo->prepare(
                "INSERT INTO altas (usuario, password, email, nombre, apellido, origen, ip,
                                    entrega_clave, entrega_sid, publicista_id, fbclid, fbp, fbc,
                                    ua, url_landing)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute(array_merge($base, [
                $entCla !== '' ? $entCla : null,
                $entSid !== '' ? $entSid : null,
                $pubId  > 0    ? $pubId  : null,
                $fbclid !== '' ? $fbclid : null,
                $fbp    !== '' ? $fbp    : null,
                $fbc    !== '' ? $fbc    : null,
                $uaJug  !== '' ? $uaJug  : null,
                $urlJug !== '' ? $urlJug : null,
            ]));
        } catch (PDOException $e0) {
            if ($e0->getCode() === '23000') { throw $e0; }
        try {
            // Nivel 1: todas las columnas, incluidas las de publicidad
            // (migracion 44).
            $ins = $pdo->prepare(
                "INSERT INTO altas (usuario, password, email, nombre, apellido, origen, ip,
                                    entrega_clave, entrega_sid, publicista_id, fbclid, fbp, fbc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute(array_merge($base, [
                $entCla !== '' ? $entCla : null,
                $entSid !== '' ? $entSid : null,
                $pubId  > 0    ? $pubId  : null,
                $fbclid !== '' ? $fbclid : null,
                $fbp    !== '' ? $fbp    : null,
                $fbc    !== '' ? $fbc    : null,
            ]));
        } catch (PDOException $e2) {
            // El duplicado (23000) lo maneja el catch de afuera: es el flujo
            // normal, no un problema de esquema.
            if ($e2->getCode() === '23000') {
                throw $e2;
            }
            // Cualquier otra cosa es, casi siempre, que faltan columnas de una
            // migracion (35 o 44) sin correr. Se reintenta en capas, cada vez
            // con menos columnas nuevas, hasta el INSERT base de siempre.
            //
            // No se filtra por codigo de error a proposito: MySQL dice 42S22 y
            // SQLite HY000 para lo mismo, y atarse a uno hace que el fallback
            // no dispare justo donde hace falta. Si TODOS los reintentos
            // fallan, se propaga el error ORIGINAL (nivel 1), que es el que
            // mejor explica que paso.
            //
            // Degradar es mejor que romper: sin esto, una migracion sin correr
            // se lleva puesto TODO el alta con un 502. Asi la cuenta se crea
            // igual; lo unico que se pierde son los datos de la migracion que
            // falte (entrega automatica de clave, o el tracking de campaña).
            error_log('altas: INSERT nivel 1 fallo (' . $e2->getMessage()
                    . '). Reintento sin publicista_id/fbclid/fbp/fbc: '
                    . 'revisa que este corrida la migracion 44.');
            try {
                // Nivel 2: sin las columnas de publicidad, con entrega_clave/sid.
                $ins = $pdo->prepare(
                    "INSERT INTO altas (usuario, password, email, nombre, apellido, origen, ip,
                                        entrega_clave, entrega_sid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->execute(array_merge($base, [
                    $entCla !== '' ? $entCla : null,
                    $entSid !== '' ? $entSid : null,
                ]));
            } catch (PDOException $e3) {
                if ($e3->getCode() === '23000') {
                    throw $e3;      // duplicado: lo resuelve el catch de afuera
                }
                error_log('altas: INSERT nivel 2 fallo (' . $e3->getMessage()
                        . '). Reintento sin entrega_clave/entrega_sid: '
                        . 'revisa que este corrida la migracion 35.');
                try {
                    // Nivel 3: el INSERT base de siempre.
                    $ins = $pdo->prepare(
                        "INSERT INTO altas (usuario, password, email, nombre, apellido, origen, ip)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $ins->execute($base);
                } catch (PDOException $e4) {
                    if ($e4->getCode() === '23000') {
                        throw $e4;  // duplicado: lo resuelve el catch de afuera
                    }
                    throw $e2;      // el original (nivel 1) explica mejor el problema
                }
            }
        }
        }   // cierra el nivel 0 (migracion 51: ua / url_landing)

        return ['http' => 200, 'cuerpo' => [
            'ok'     => true,
            'id'     => (int)$pdo->lastInsertId(),
            'estado' => 'pendiente',
        ]];

    } catch (PDOException $e) {
        // 23000 = choque con uq_alta_usuario. Es el caso normal (doble click,
        // reintento del chatbot), no un error del server: que el duplicado lo
        // frene la base y no un "si no existe, insertar" en PHP, que se pisa
        // solo cuando entran dos pedidos a la vez.
        if ($e->getCode() !== '23000') {
            throw $e;
        }
    }

    $prev = $pdo->prepare("SELECT id, estado FROM altas WHERE usuario = ?");
    $prev->execute([$usuario]);
    $fila = $prev->fetch();

    if (!$fila) {                       // se borro entre el INSERT y este SELECT
        return ['http' => 409, 'cuerpo' => ['ok' => false, 'error' => 'Pedido duplicado']];
    }

    if ($fila['estado'] === 'error') {
        // Reintento explicito: vuelve a la cola con la clave nueva y el
        // contador en cero, si no arranca ya agotado y no lo toma nadie.
        //
        // `origen` se pisa con el del pedido NUEVO, a proposito. La fila en
        // 'error' puede ser de otra persona y de hace semanas: como
        // alta_usuario_disponible() trata las filas 'error' como nombre libre,
        // cualquier re-registro con el mismo nombre saneado cae justo aca. Si
        // el origen viejo quedara, el bono de bienvenida (que se decide por
        // altas.origen en rl_bono_bienvenida_aplicar) se heredaria de una
        // landing que este registrante jamas vio -- o se perderia el que si
        // le prometieron. El origen valido es el de quien esta pidiendo AHORA.
        $vals = [
            $password, $email !== '' ? $email : null,
            $origen,
            $entCla !== '' ? $entCla : null,
            $entSid !== '' ? $entSid : null,
            $fila['id'],
        ];
        try {
            $pdo->prepare(
                "UPDATE altas
                    SET password = ?, email = COALESCE(?, email),
                        origen = ?,
                        estado = 'pendiente', intentos = 0,
                        mensaje = NULL, tomado_en = NULL,
                        proximo_intento_en = NULL,
                        entrega_clave = ?, entrega_sid = ?
                  WHERE id = ?"
            )->execute($vals);
        } catch (PDOException $e) {
            // proximo_intento_en es de la migracion 37. Sin ella este UPDATE
            // explota y el alta NO entra a la cola -- por una columna que solo
            // sirve para espaciar los reintentos. Se reintenta sin ella.
            error_log('altas: el UPDATE de reintento fallo (' . $e->getMessage()
                    . '). Voy sin proximo_intento_en: revisa la migracion 37.');
            $pdo->prepare(
                "UPDATE altas
                    SET password = ?, email = COALESCE(?, email),
                        origen = ?,
                        estado = 'pendiente', intentos = 0,
                        mensaje = NULL, tomado_en = NULL,
                        entrega_clave = ?, entrega_sid = ?
                  WHERE id = ?"
            )->execute($vals);
        }

        return ['http' => 200, 'cuerpo' => [
            'ok'        => true,
            'id'        => (int)$fila['id'],
            'estado'    => 'pendiente',
            'reintento' => true,
        ]];
    }

    return ['http' => 409, 'cuerpo' => [
        'ok'     => false,
        'error'  => $fila['estado'] === 'ok' ? 'Ese usuario ya existe' : 'Ese alta ya está en la cola',
        'id'     => (int)$fila['id'],
        'estado' => $fila['estado'],
    ]];
}

/**
 * Estado de un pedido, para que la landing sepa cuando mostrar el cartel.
 *
 * Pide id Y usuario a proposito: con el id solo, cualquiera recorre 1,2,3... y
 * se entera de todos los pedidos que existen. Sabiendo los dos, ya conoce el
 * suyo. Nunca devuelve la password ni el usuario: solo confirma lo que el que
 * pregunta ya sabia.
 */
function alta_estado(PDO $pdo, int $id, string $usuario): array
{
    // creado_en_panel manda igual que en alta_entrega(): `listo` significa "la
    // cuenta EXISTE", y de eso depende que el front muestre las credenciales.
    // Si la columna no esta (migracion 36 sin correr) se cae a la consulta
    // vieja pero NO se da por creada nada -- ver mas abajo.
    $hayFlag = true;
    try {
        $q = $pdo->prepare("SELECT estado, creado_en_panel FROM altas WHERE id = ? AND usuario = ?");
        $q->execute([$id, $usuario]);
        $fila = $q->fetch();
    } catch (PDOException $e) {
        $hayFlag = false;
        $q = $pdo->prepare("SELECT estado FROM altas WHERE id = ? AND usuario = ?");
        $q->execute([$id, $usuario]);
        $fila = $q->fetch();
    }

    if (!$fila) {
        return ['http' => 404, 'cuerpo' => ['ok' => false, 'error' => 'Pedido inexistente']];
    }

    // Sin la bandera no se afirma que la cuenta exista: se responde "todavia
    // no" y se avisa. Es el mismo criterio que alta_entrega().
    $enPanel = $hayFlag
        ? ((int)($fila['creado_en_panel'] ?? 0) === 1)
        : false;
    if (!$hayFlag) {
        error_log('altas: falta la migracion 36 (creado_en_panel). '
                . 'alta_estado() no confirma altas hasta que se corra.');
    }

    // El detalle tecnico que informa el bot (`mensaje`) NO sale de aca: no le
    // sirve de nada al jugador y puede filtrar como esta armado el panel.
    return ['http' => 200, 'cuerpo' => [
        'ok'     => true,
        'estado' => $fila['estado'],
        'listo'  => ($fila['estado'] === 'ok' && $enPanel),
        'fallo'  => $fila['estado'] === 'error',
    ]];
}

/**
 * Clave al azar para un alta pedida por chat.
 *
 * El jugador no elige la contrasena cuando la pide por el chat: se la damos
 * hecha. Motivo: la unica forma de que la elija seria que la escriba en el
 * chat, y eso la deja guardada en `mensajes` para siempre y a la vista de
 * cualquier agente que abra esa conversacion en el CRM.
 *
 * Sin caracteres ambiguos (0/O, 1/l/I) porque mucha gente la copia a mano
 * desde el celular. random_int() y no rand(): esto es una credencial.
 */
function alta_clave_random(int $largo = 10): string
{
    $abc = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $largo; $i++) {
        $s .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $s;
}

/**
 * Estado de un alta pedida por el chat, y la clave SI YA ESTA CREADA.
 *
 * Es lo que sondea el widget mientras el bot trabaja. La clave se entrega una
 * sola vez y recien cuando `estado = 'ok'`: hasta entonces la cuenta no existe
 * en el panel y dar las credenciales seria mentirle al jugador.
 *
 * Pide el session_id ademas del id a proposito. Sin eso, cualquiera que
 * recorra 1,2,3... se lleva las contrasenas de las altas de los demas. El sid
 * lo genera el widget, no viaja en ningun lado publico, y solo lo tiene el
 * navegador que pidio esa alta.
 *
 * La clave se BORRA al devolverla (UPDATE ... WHERE entrega_clave IS NOT NULL,
 * mirando rowCount): si dos sondeos entran a la vez, uno solo se la lleva y el
 * otro ve 'entregada'. Asi no queda dando vueltas en la base despues de que el
 * jugador ya la anoto.
 */
function alta_entrega(PDO $pdo, int $id, string $sid): array
{
    if ($id <= 0 || $sid === '') {
        return ['ok' => false, 'error' => 'Faltan datos'];
    }

    try {
        $q = $pdo->prepare(
            "SELECT usuario, estado, entrega_clave, creado_en_panel
               FROM altas
              WHERE id = ? AND entrega_sid = ?
              LIMIT 1"
        );
        $q->execute([$id, mb_substr($sid, 0, 64)]);
        $fila = $q->fetch();
    } catch (PDOException $e) {
        // Sin la migracion 36 la columna no existe. NO se cae a "entregar
        // igual": el sentido de esta bandera es no dar credenciales de una
        // cuenta que capaz no se creo, y sin la columna no hay forma de
        // saberlo. Se responde "todavia no" y se avisa en el log, que es lo
        // que hay que arreglar.
        error_log('altas: falta la migracion 36 (creado_en_panel). '
                . 'No se entregan credenciales hasta que se corra.');
        return ['ok' => true, 'estado' => 'en_curso', 'listo' => false];
    }

    if (!$fila) {
        return ['ok' => false, 'error' => 'Pedido inexistente'];
    }

    $estado = (string)$fila['estado'];

    // Fallo definitivo. NUNCA se entrega la clave: esa cuenta no existe.
    if ($estado === 'error') {
        return ['ok' => true, 'estado' => 'error', 'listo' => false, 'fallo' => true];
    }

    // DOS condiciones, y las dos tienen que darse:
    //
    //   estado === 'ok'      la cola dice que el alta termino bien
    //   creado_en_panel = 1  el bot confirmo que el PANEL la creo
    //
    // La segunda es la que manda. `estado` lo usa toda la cola para otras cosas
    // y un valor inesperado o un UPDATE mal escrito ya nos hizo entregar
    // credenciales de una cuenta que no existia. `creado_en_panel` no hace otra
    // cosa que esto y solo lo escribe el bot al confirmar.
    //
    // La lista es de lo que SI habilita, nunca de lo que no: cualquier otro
    // caso -- pendiente, procesando, un estado nuevo, la columna en NULL -- es
    // "todavia no".
    $enPanel = (int)($fila['creado_en_panel'] ?? 0) === 1;
    if ($estado !== 'ok' || !$enPanel) {
        return ['ok' => true, 'estado' => 'en_curso', 'listo' => false];
    }

    $clave = (string)($fila['entrega_clave'] ?? '');
    if ($clave === '') {
        // Ya se la llevo (este mismo navegador, o un sondeo anterior).
        return ['ok' => true, 'estado' => 'ok', 'listo' => true, 'entregada' => true];
    }

    $del = $pdo->prepare(
        "UPDATE altas SET entrega_clave = NULL
          WHERE id = ? AND entrega_clave IS NOT NULL"
    );
    $del->execute([$id]);
    if ($del->rowCount() !== 1) {
        // Otro sondeo se la llevo en el medio.
        return ['ok' => true, 'estado' => 'ok', 'listo' => true, 'entregada' => true];
    }

    return ['ok' => true, 'estado' => 'ok', 'listo' => true,
            'usuario' => (string)$fila['usuario'], 'password' => $clave];
}

