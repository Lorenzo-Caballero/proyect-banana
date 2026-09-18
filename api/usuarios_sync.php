<?php
/**
 * usuarios_sync.php — Recibe lotes de usuarios del panel (los manda
 *                     sync_usuarios.py) y los upsertea en la tabla `usuarios`.
 *
 * POST  { "usuarios": [ {id, username, balance, bonus, total_deposits,
 *                        role, is_banned, creation_date}, ... ] }
 *   header: X-API-Key  (o X-Api-Token) = BOT_API_KEY
 *   -> { "ok":true, "recibidos":N, "guardados":N }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$key = cfg('BOT_API_KEY');
$enviada = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $enviada)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}
/* ---------------------------------------------------------------------------
   GET ?accion=activos  ->  { "ok":true, "usuarios":[ "juan123", ... ] }

   QUIÉNES NECESITAN EL SALDO FRESCO AHORA. El espejo completo pagina ~3.000
   jugadores y por eso corre cada 5 minutos: en el peor caso el CRM y el bot
   contestan con un saldo de hace 5 minutos.

   EL COSTO (Nahuel, 18/09/2026): *"muchas veces las personas dicen quiero
   retirar 5000 y el bot le dice no tenés 5000, tenés 1000, y eso es porque el
   saldo en el CRM no se está actualizando lo suficientemente rápido"*. Y es
   exacto: `fichas_pedir_retiro()` decide con `usuarios.balance`, que es el
   espejo. El jugador acaba de ganar, pide retirar, y el bot le discute con un
   número viejo.

   La salida no es espejar más seguido a los 3.000 --son 62 páginas y ~50
   segundos, no entra en un minuto-- sino espejar SOLO a los que están
   haciendo algo. El panel deja pedir un jugador puntual
   (`?username=`, verificado el 18/09: devuelve 1 fila), así que refrescar a
   los activos cuesta una llamada por cabeza.

   "Activo" es quien podría estar por preguntar su saldo: escribió en el chat,
   o se le movió plata. Lo normal es un puñado; el tope está para que una noche
   rara no convierta esto en el espejo completo por la puerta de atrás.
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'activos') {
    $min  = max(1, min(120, (int)($_GET['minutos'] ?? 15)));
    $tope = max(1, min(200, (int)($_GET['limite']  ?? 60)));
    $lista = [];
    /* LA CONSULTA ARRANCA POR LO RECIENTE, NO POR LOS 3.000 JUGADORES.
       La primera versión recorría `usuarios` entero preguntando, por cada uno,
       si había hecho algo (dos EXISTS correlacionados). Medido el 18/09/2026
       con 3.076 usuarios y 10.599 mensajes: **1.493 ms**, cada minuto — y
       creciendo con el tráfico, que es justo lo que va a pasar al prender la
       publicidad. El COLLATE del JOIN además impide usar el índice del
       username, así que no había forma de que mejorara sola.

       Dada vuelta --UNION de lo que se movió en los últimos N minutos, y recién
       ahí el JOIN contra `usuarios`-- arranca por dos rangos de fecha que SÍ
       tienen índice (`mensajes.creado_en`, `movimientos.creado_en`) y sobre un
       puñado de filas. Misma respuesta: **7 ms**.

       Es la diferencia entre una consulta que escala con el padrón y una que
       escala con la actividad del último cuarto de hora. */
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT u.username
               FROM (
                     SELECT c.clave AS usuario
                       FROM mensajes m
                       JOIN conversaciones c ON c.id = m.conversacion_id
                      WHERE m.creado_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                     UNION
                     SELECT v.usuario
                       FROM movimientos v
                      WHERE v.creado_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                    ) x
               JOIN usuarios u ON u.username COLLATE utf8mb4_unicode_ci = x.usuario
              WHERE u.username <> ''
              LIMIT $tope"
        );
        $st->execute([$min, $min]);
        $lista = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('usuarios_sync/activos: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'usuarios' => array_values($lista),
                      'minutos' => $min], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$usuarios = (isset($body['usuarios']) && is_array($body['usuarios'])) ? $body['usuarios'] : [];
if (!$usuarios) {
    echo json_encode(['ok' => true, 'recibidos' => 0, 'guardados' => 0]);
    exit;
}

// OJO: `bonus` y `coins` NO se actualizan en el UPDATE: ahora son nuestros
// bonos/fichas internos (ruleta, CRM, recargas), no vienen de ganamos. En un
// alta nueva entran en 0 (VALUES(bonus)=0); en updates se preservan.
/* `saldo_visto_en` = CUANDO leimos este saldo, no cuando cambio (migracion
   68). Se escribe SIEMPRE, aunque el balance venga igual: el dato que hace
   falta del otro lado es la edad de la lectura, y una lectura que confirma el
   mismo numero es igual de fresca que una que lo cambia.
   `actualizado_en` no sirve para eso: es ON UPDATE CURRENT_TIMESTAMP, y MySQL
   no lo dispara cuando la fila queda identica -- un jugador con el saldo
   quieto figuraba visto por ultima vez hace una semana.
   Si la migracion todavia no corrio se cae al INSERT sin la columna: el
   espejo tiene que seguir entrando igual (mismo patron que crm_mensaje). */
$cols = "(id, username, balance, bonus, total_deposits, role, is_banned, creation_date";
$vals = "VALUES (?,?,?,?,?,?,?,?";
$upd  = "username       = VALUES(username),
          balance        = VALUES(balance),
          total_deposits = VALUES(total_deposits),
          role           = VALUES(role),
          is_banned      = VALUES(is_banned),
          creation_date  = VALUES(creation_date)";

$hayVisto = true;
try { $pdo->query("SELECT saldo_visto_en FROM usuarios LIMIT 0"); }
catch (Throwable $e) { $hayVisto = false; }

$sql = "INSERT INTO usuarios " . $cols . ($hayVisto ? ", saldo_visto_en)" : ")")
     . " " . $vals . ($hayVisto ? ", NOW())" : ")")
     . " ON DUPLICATE KEY UPDATE " . $upd
     . ($hayVisto ? ",
          saldo_visto_en = NOW()" : "");

$num = function ($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
};

$guardados = 0;
$pdo->beginTransaction();
try {
    $st = $pdo->prepare($sql);
    foreach ($usuarios as $u) {
        $id = (int)($u['id'] ?? 0);
        $username = trim((string)($u['username'] ?? ''));
        if (!$id || $username === '') {
            continue;   // fila incompleta, la salteamos
        }
        $fecha = $u['creation_date'] ?? null;
        if ($fecha !== null) {
            $fecha = substr(str_replace('T', ' ', (string)$fecha), 0, 19) ?: null;
        }
        $st->execute([
            $id,
            mb_substr($username, 0, 80),
            $num($u['balance'] ?? 0),
            $num($u['bonus'] ?? 0),
            $num($u['total_deposits'] ?? 0),
            isset($u['role']) ? (string)$u['role'] : null,
            !empty($u['is_banned']) ? 1 : 0,
            $fecha,
        ]);
        $guardados++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('usuarios_sync: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Fallo al guardar', 'detalle' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'recibidos' => count($usuarios), 'guardados' => $guardados]);
