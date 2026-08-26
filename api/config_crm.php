<?php
/**
 * config_crm.php — Ajustes del sitio que el agente edita desde el CRM.
 *
 * Clave/valor sobre la tabla `config_crm` (migración 38). No es un endpoint:
 * son funciones que usan ruleta.php, chatbot.php, crear_cuenta.php y la vista
 * de Configuración del CRM.
 *
 * REGLA: cada ajuste tiene su default acá, en DEFAULTS. Una fila que no existe
 * -o una migración sin correr- devuelve el default y el sitio sigue andando
 * como antes. Nada de lo que se configure desde acá puede tirar abajo el sitio
 * si la tabla no está.
 *
 * Requiere la migración sql/38_config_crm.sql.
 */

declare(strict_types=1);

/**
 * Valor por defecto de cada ajuste. Lo que no esté acá no existe: cfg_crm()
 * devuelve null y cfg_crm_guardar() lo rechaza, para que un typo en el
 * frontend no llene la tabla de claves fantasma.
 */
const CFG_CRM_DEFAULTS = [
    // Ruleta de bonos: si se apaga, el widget no la muestra y ruleta.php
    // rechaza los giros. Las dos cosas hacen falta -- esconder el botón no
    // alcanza, el endpoint es público.
    'ruleta_activa'   => '1',
    // Qué se le dice al jugador cuando está apagada. Vacío = mensaje genérico.
    'ruleta_mensaje'  => '',

    // El chatbot atiende. Apagado, el jugador puede escribir igual y le
    // contesta un agente (el mismo camino que config_chatbot.activo).
    'chat_activo'     => '1',

    // Alta de cuentas nuevas desde la landing y el chat.
    'registro_activo' => '1',
];

/** Cache por request: estas funciones se llaman varias veces por pedido. */
$GLOBALS['__cfg_crm_cache'] = null;

/**
 * Todos los ajustes, con los defaults ya aplicados.
 *
 * Si la tabla no existe (migración sin correr) devuelve los defaults en vez de
 * lanzar: el sitio tiene que funcionar igual, simplemente sin nada configurado.
 */
function cfg_crm_todo(PDO $pdo): array
{
    if ($GLOBALS['__cfg_crm_cache'] !== null) {
        return $GLOBALS['__cfg_crm_cache'];
    }
    $vals = CFG_CRM_DEFAULTS;
    try {
        $filas = $pdo->query("SELECT clave, valor FROM config_crm")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $f) {
            $k = (string)$f['clave'];
            // Solo las claves conocidas: una fila vieja o de otra versión no
            // se cuela en la respuesta que ve el frontend.
            if (array_key_exists($k, CFG_CRM_DEFAULTS)) {
                $vals[$k] = (string)($f['valor'] ?? '');
            }
        }
    } catch (Throwable $e) {
        error_log('config_crm: no pude leer la tabla (¿falta la migración 38?): ' . $e->getMessage());
    }
    $GLOBALS['__cfg_crm_cache'] = $vals;
    return $vals;
}

/** Un ajuste puntual. Devuelve null si la clave no existe en DEFAULTS. */
function cfg_crm(PDO $pdo, string $clave): ?string
{
    if (!array_key_exists($clave, CFG_CRM_DEFAULTS)) {
        return null;
    }
    return cfg_crm_todo($pdo)[$clave] ?? CFG_CRM_DEFAULTS[$clave];
}

/**
 * ¿Está prendido? Para los ajustes de sí/no.
 *
 * Cualquier cosa que no sea "0"/"" cuenta como prendido: es más seguro que un
 * valor raro deje algo funcionando a que lo apague sin que nadie entienda por
 * qué.
 */
function cfg_crm_activo(PDO $pdo, string $clave): bool
{
    $v = trim((string)cfg_crm($pdo, $clave));
    return $v !== '0' && $v !== '' && strtolower($v) !== 'false';
}

/**
 * Guarda varios ajustes de una. Ignora las claves desconocidas.
 * Devuelve cuántos guardó.
 */
function cfg_crm_guardar(PDO $pdo, array $vals, string $operador = ''): int
{
    // Se filtra ANTES de preparar nada: con un payload vacio o todo invalido
    // esta funcion no tiene por que tocar la base.
    $limpios = array_intersect_key($vals, CFG_CRM_DEFAULTS);
    if (!$limpios) {
        return 0;
    }

    $n = 0;
    $st = $pdo->prepare(
        "INSERT INTO config_crm (clave, valor, operador) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), operador = VALUES(operador)"
    );
    foreach ($limpios as $k => $v) {
        $st->execute([$k, (string)$v, $operador !== '' ? $operador : null]);
        $n++;
    }
    $GLOBALS['__cfg_crm_cache'] = null;   // el próximo lector ve lo nuevo
    return $n;
}
