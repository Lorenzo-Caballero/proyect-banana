<?php
/**
 * crm_integracion.php — Las credenciales del panel de agentes de ganamos,
 *                       cargadas POR EL CLIENTE desde su propio CRM.
 *
 * POR QUE EXISTE (15/09/2026). Estas credenciales las cargaba el DUEÑO de la
 * plataforma en su panel de administración, lo que estaba mal dos veces:
 *   - de seguridad: el cliente le dictaba su usuario y contraseña del panel
 *     de ganamos a otra persona para que la tipee por él;
 *   - de flujo: si el dueño no las cargaba, el cliente quedaba con altas y
 *     cargas muertas sin saber por qué — el aviso "Integrá tu panel de
 *     ganamos" del CRM ahora se lo dice a quien puede resolverlo.
 *
 * A DONDE ESCRIBE. goldpaw_control.clientes.agente_usuario/agente_password —
 * el MISMO lugar de siempre: provisionar.php (pasada 2, corre cada minuto)
 * las lee de ahí y levanta el bot del cliente solo; si ya había un bot con
 * credenciales viejas, lo recrea con las nuevas. Acá no se toca Docker ni
 * nada: se guarda y el resto se engancha.
 *
 * La contraseña va EN CLARO en la base de control, igual que siempre: el bot
 * la necesita en claro para tipearla en el login del panel de ganamos (es un
 * login de un sitio de terceros, no un hash nuestro). Lo que SÍ: nunca se
 * devuelve por la API — solo el usuario y el booleano "cargada".
 *
 * PERMISOS PARTIDOS A PROPÓSITO (15/09/2026): el ESTADO lo lee cualquier
 * operador — es lo que alimenta el aviso «Integrá tu panel de ganamos» del
 * CRM, y con exigir_admin un agente logueado recibía 403 y el aviso
 * PRIORITARIO desaparecía en silencio (se vio en producción: solo quedaba el
 * de la cuenta de cobro, que sí es legible por operador). GUARDAR sigue
 * siendo solo admin: son las llaves de la caja del cliente.
 *
 * GET  ?accion=estado         -> { ok, usuario, cargada, usuarios }
 * POST { accion:"guardar", usuario, password }        (solo admin)
 *        password vacío con credenciales ya cargadas = "no la cambies"
 *        (mismo contrato que el resto de los secretos del CRM).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');

$operador = exigir_operador();
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $operador = exigir_admin(); }

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Mismo camino al control que crm_cobro.php: la fila del cliente se resuelve
// por TENANT_DB (que ya decidió db.php por dominio+slug), NUNCA por un
// parámetro del request.
try {
    $ctl = new PDO(
        'mysql:host=' . cfg('DB_HOST', 'localhost') . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
        cfg('DB_USER'), cfg('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    error_log('crm_integracion: sin control: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'No se pudo conectar a la base de control'], 500);
}

$st = $ctl->prepare('SELECT id, agente_usuario, agente_password FROM clientes WHERE db_nombre = ? LIMIT 1');
$st->execute([(string)($GLOBALS['TENANT_DB'] ?? '')]);
$cliente = $st->fetch();
if (!$cliente) { salir(['ok' => false, 'error' => 'cliente no resuelto'], 500); }

$cargada = trim((string)($cliente['agente_usuario'] ?? '')) !== ''
        && trim((string)($cliente['agente_password'] ?? '')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'estado') {
    /* Cuántos jugadores ya espejó el sync en la base de ESTE cliente ($pdo,
       la tenant que resolvió db.php). Es lo que le permite al CRM distinguir
       "recién integrado, el bot todavía está trayendo los datos" (cargada +
       0 usuarios -> cartel con spinner) de "andando" — la primera pasada del
       espejo tarda unos minutos y sin esto parecía que no había funcionado. */
    $usuarios = 0;
    try {
        $usuarios = (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    } catch (Throwable $e) { /* tabla aún sin crear: 0, que es la verdad */ }
    salir([
        'ok'       => true,
        'usuario'  => (string)($cliente['agente_usuario'] ?? ''),
        'cargada'  => $cargada,
        'usuarios' => $usuarios,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    if ($accion === 'guardar') {
        $usr  = trim((string)($body['usuario'] ?? ''));
        $pass = (string)($body['password'] ?? '');
        if ($usr === '') {
            salir(['ok' => false, 'error' => 'Falta el usuario del panel de ganamos'], 422);
        }
        if (mb_strlen($usr) > 120 || mb_strlen($pass) > 190) {
            salir(['ok' => false, 'error' => 'Usuario o contraseña demasiado largos'], 422);
        }
        if ($pass === '' && !$cargada) {
            salir(['ok' => false, 'error' => 'Falta la contraseña del panel de ganamos'], 422);
        }
        try {
            if ($pass !== '') {
                $ctl->prepare('UPDATE clientes SET agente_usuario = ?, agente_password = ? WHERE id = ?')
                    ->execute([$usr, $pass, (int)$cliente['id']]);
            } else {
                // Vacía con credenciales ya cargadas: solo el usuario.
                $ctl->prepare('UPDATE clientes SET agente_usuario = ? WHERE id = ?')
                    ->execute([$usr, (int)$cliente['id']]);
            }
        } catch (Throwable $e) {
            error_log('crm_integracion guardar: ' . $e->getMessage());
            salir(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }
        salir(['ok' => true, 'usuario' => $usr,
               'aviso' => 'Listo. El bot se conecta solo en 1-2 minutos; no hay que hacer nada más.']);
    }

    salir(['ok' => false, 'error' => 'acción desconocida'], 400);
}

salir(['ok' => false, 'error' => 'método no soportado'], 405);
