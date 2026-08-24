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

// Freno del endpoint publico. Cada alta encolada hace que un bot abra Chromium
// y opere el panel de agentes de verdad, asi que esto no es una formalidad.
const ALTAS_POR_IP_HORA = 3;
const ALTAS_POR_IP_DIA  = 10;

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
 * Devuelve el mensaje de rechazo si la IP se paso del limite, o null.
 * Solo cuenta pedidos que llegaron a entrar: los rechazados no suman.
 */
function alta_limite_superado(PDO $pdo, string $ip): ?string
{
    if ($ip === '') {
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

    if ((int)($r['ultima_hora'] ?? 0) >= ALTAS_POR_IP_HORA) {
        return 'Ya pediste varias cuentas hace un rato. Esperá una hora e intentá de nuevo.';
    }
    if ((int)($r['ultimo_dia'] ?? 0) >= ALTAS_POR_IP_DIA) {
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

    try {
        $ins = $pdo->prepare(
            "INSERT INTO altas (usuario, password, email, nombre, apellido, origen, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $usuario,
            $password,
            $email    !== '' ? $email    : null,
            $nombre   !== '' ? $nombre   : null,
            $apellido !== '' ? $apellido : null,
            $origen,
            $ip !== '' ? $ip : null,
        ]);

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
        $pdo->prepare(
            "UPDATE altas
                SET password = ?, email = COALESCE(?, email),
                    estado = 'pendiente', intentos = 0,
                    mensaje = NULL, tomado_en = NULL
              WHERE id = ?"
        )->execute([$password, $email !== '' ? $email : null, $fila['id']]);

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
    $q = $pdo->prepare("SELECT estado FROM altas WHERE id = ? AND usuario = ?");
    $q->execute([$id, $usuario]);
    $fila = $q->fetch();

    if (!$fila) {
        return ['http' => 404, 'cuerpo' => ['ok' => false, 'error' => 'Pedido inexistente']];
    }

    // El detalle tecnico que informa el bot (`mensaje`) NO sale de aca: no le
    // sirve de nada al jugador y puede filtrar como esta armado el panel.
    return ['http' => 200, 'cuerpo' => [
        'ok'     => true,
        'estado' => $fila['estado'],
        'listo'  => $fila['estado'] === 'ok',
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
