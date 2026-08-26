<?php
/**
 * juegos_lib.php — Lo comun a los juegos propios (raspa, slot 777).
 *
 * No es un endpoint: son funciones. Existe para que las reglas delicadas
 * -identidad, limites, acreditacion- esten escritas UNA vez y no copiadas por
 * juego. Copiar ruleta.php para cada juego nuevo es exactamente como se
 * multiplican los agujeros.
 *
 * LAS REGLAS QUE NO SE NEGOCIAN
 *
 *  1. El premio lo elige el SERVER. El cliente no manda ni puede influir en
 *     cuanto gana: el body no tiene un solo campo que entre en el sorteo.
 *  2. El monto que se acredita sale de la FILA PERSISTIDA, no de la variable
 *     que se calculo antes de escribirla.
 *  3. Los limites se garantizan con indices UNIQUE, capturando el 1062 --
 *     nunca con un SELECT previo, que con dos pestanas abiertas cobra dos
 *     veces.
 *  4. Se marca consumido ANTES de acreditar. Si algo revienta en el medio, se
 *     pierde el premio; nunca se duplica.
 *  5. Todo lo que mueve plata exige JWT verificado. El session_id lo elige el
 *     navegador: sirve para conversar, no para cobrar.
 *
 * Requiere sql/40_juegos.sql y sql/38_config_crm.sql.
 */

declare(strict_types=1);

// config.php primero: jug_identidad() usa cfg('JWT_SECRET'). Los endpoints ya
// lo cargan antes, pero la lib no puede depender de que el caller se acuerde --
// sin esto, incluirla sola tira "Call to undefined function cfg()".
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_crm.php';

// auth_lib y crm_lib son opcionales en algunos despliegues (igual que en
// chatbot.php): se cargan si estan, y quien los necesite chequea con
// function_exists antes de usarlos.
foreach (['/auth_lib.php', '/crm_lib.php'] as $opt) {
    if (is_file(__DIR__ . $opt)) { require_once __DIR__ . $opt; }
}

/** Todo lo que regalan estos juegos son BONOS, nunca saldo real. */
const JUG_TIPO = 'bono';

/** Responde JSON y corta. */
function jug_salir(array $datos, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Error con codigo legible para el front. */
function jug_error(string $codigo, string $mensaje, int $http = 400): void
{
    jug_salir(['ok' => false, 'codigo' => $codigo, 'error' => $mensaje], $http);
}

/** Body JSON del POST. */
function jug_body(): array
{
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

/**
 * Quien es el jugador, VERIFICADO.
 *
 * Solo el JWT propio cuenta. El `usuario` suelto que manda el navegador sirve
 * para conversar, no para cobrar: si alcanzara, cualquiera escribe el nombre
 * de otro y le juega -- y le gasta -- su carton del dia.
 *
 * Devuelve el username o '' si no hay sesion verificable.
 */
function jug_identidad(array $body): string
{
    $tok = trim((string)($body['token'] ?? ''));
    if ($tok === '' || !function_exists('jwt_verificar')) {
        return '';
    }
    $claims = jwt_verificar($tok, cfg('JWT_SECRET'));
    if (!$claims || empty($claims['username'])) {
        return '';
    }
    return mb_substr((string)$claims['username'], 0, 50);
}

/**
 * Corta si el juego esta apagado desde el CRM.
 *
 * Se chequea en el SERVER y no solo escondiendo el boton: estos endpoints son
 * publicos y no mostrar el juego no impide que alguien les postee.
 */
function jug_gate(PDO $pdo, string $juego): void
{
    if (!cfg_crm_activo($pdo, $juego . '_activo')) {
        $msg = trim((string)cfg_crm($pdo, $juego . '_mensaje'));
        jug_error('juego_apagado',
            $msg !== '' ? $msg : 'Este juego no está disponible en este momento.', 403);
    }
}

/**
 * Sortea una fila por peso. UNICA fuente de azar de los juegos.
 *
 * random_int y no rand/mt_rand: esto reparte plata, y el generador rapido es
 * predecible con unas pocas observaciones.
 *
 * Devuelve el INDICE de la fila elegida.
 */
function jug_sortear(array $filas): int
{
    $total = 0;
    foreach ($filas as $f) { $total += max(0, (int)($f['peso'] ?? 0)); }
    if ($total <= 0) { return 0; }

    $r = random_int(1, $total);
    $acum = 0;
    foreach ($filas as $i => $f) {
        $acum += max(0, (int)($f['peso'] ?? 0));
        if ($r <= $acum) { return (int)$i; }
    }
    return count($filas) - 1;
}

/**
 * La tabla de premios sin los pesos.
 *
 * El peso es la probabilidad: publicarlo le dice al jugador exactamente cuanto
 * paga la casa, y a cualquiera que mire el JSON como esta armado el juego.
 */
function jug_premios_publicos(array $filas): array
{
    $out = [];
    foreach ($filas as $f) {
        $out[] = ['label' => (string)($f['label'] ?? ''), 'bonus' => (int)($f['bonus'] ?? 0)];
    }
    return $out;
}

/**
 * Toma el cupo del dia de un juego de uno-por-dia. true si lo consiguio.
 *
 * El INSERT contra la PK (usuario, juego, dia) ES el limite. Si ya jugo, choca
 * con 1062 y devuelve false, sin ninguna carrera posible.
 *
 * Si la tabla no existe todavia (migracion sin correr) devuelve true: una
 * migracion pendiente no puede dejar sin jugar a nadie -- mismo criterio que
 * cfg_crm_todo() con config_crm.
 */
function jug_dia_tomar(PDO $pdo, string $usuario, string $juego, int $premio = 0): bool
{
    try {
        $pdo->prepare(
            "INSERT INTO juego_reclamos_dia (usuario, juego, dia, premio)
             VALUES (?, ?, CURDATE(), ?)"
        )->execute([$usuario, $juego, $premio]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false;               // ya jugo hoy
        }
        error_log('juegos: no pude tomar el dia (¿falta la migración 40?): ' . $e->getMessage());
        return true;                    // no bloquear por infra
    }
}

/**
 * Suma al libro del dia sin imponer limite (juegos de N tiradas).
 * Best-effort: es contabilidad para el tope, no puede frenar una tirada.
 */
function jug_dia_sumar(PDO $pdo, string $usuario, string $juego, int $premio): void
{
    if ($premio <= 0) { return; }
    try {
        $pdo->prepare(
            "INSERT INTO juego_reclamos_dia (usuario, juego, dia, premio)
             VALUES (?, ?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE premio = premio + VALUES(premio)"
        )->execute([$usuario, $juego, $premio]);
    } catch (Throwable $e) {
        error_log('juegos: no pude sumar al libro del dia: ' . $e->getMessage());
    }
}

/**
 * ¿La casa ya regalo demasiado hoy en este juego?
 *
 * Se consulta ANTES de sortear: mejor decir "volvé mañana" que sortear un
 * premio y no poder pagarlo. Tope en 0 = sin limite.
 *
 * Ante cualquier error devuelve true (deja jugar): un tope es una proteccion
 * opcional, no puede tumbar el juego si la tabla no esta.
 */
function jug_tope_dia_ok(PDO $pdo, string $juego): bool
{
    $tope = (int)cfg_crm($pdo, $juego . '_tope_dia');
    if ($tope <= 0) { return true; }
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(premio),0) FROM juego_reclamos_dia
              WHERE juego = ? AND dia = CURDATE()"
        );
        $st->execute([$juego]);
        return (int)$st->fetchColumn() < $tope;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Acredita el premio en BONOS, siempre por crm_cargar().
 *
 * Sin fallback crudo a propósito: un premio escrito directo en usuarios.bonus
 * no deja fila en `movimientos`, y entonces es invisible para el reporte de
 * costos del CRM. Un regalo que no se puede contar es peor que uno que no se
 * entrega.
 *
 * Devuelve false si no se pudo (el caller decide qué contarle al jugador).
 */
function jug_acreditar(PDO $pdo, string $usuario, int $bonus, string $motivo, string $origen): bool
{
    if ($bonus <= 0) { return true; }        // "no ganaste" no es un fallo
    if (!function_exists('crm_cargar')) {
        error_log('juegos: crm_lib.php no está: no puedo acreditar ' . $bonus . ' a ' . $usuario);
        return false;
    }
    try {
        crm_cargar($pdo, $usuario, JUG_TIPO, $bonus, $motivo, $origen);
        return true;
    } catch (Throwable $e) {
        error_log('juegos: crm_cargar fallo: ' . $e->getMessage());
        return false;
    }
}

/**
 * ¿Los juegos pueden acreditar en este despliegue?
 *
 * Si crm_lib.php no esta, se reportan APAGADOS en vez de dejar jugar y no
 * pagar. Prometer un premio que no se puede acreditar es peor que no ofrecerlo.
 */
function jug_puede_acreditar(): bool
{
    return function_exists('crm_cargar');
}

/** Token opaco para atar un carton a su cobro. */
function jug_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Freno por IP. Estos endpoints son publicos: sin esto, alguien los martilla
 * y llena las tablas aunque nunca llegue a cobrar.
 */
function jug_rate(string $clave, int $max, int $ventanaSeg): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $f  = sys_get_temp_dir() . '/gpjuego_' . $clave . '_' . md5($ip);
    $ahora = time();
    $hits = [];
    if (is_file($f)) {
        foreach (explode(',', (string)@file_get_contents($f)) as $t) {
            if ($t !== '' && (int)$t > $ahora - $ventanaSeg) { $hits[] = (int)$t; }
        }
    }
    if (count($hits) >= $max) { return false; }
    $hits[] = $ahora;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return true;
}
