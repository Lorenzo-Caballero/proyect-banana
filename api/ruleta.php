<?php
/**
 * ruleta.php — Ruleta promocional. Solo se usa con giros regalados.
 *
 * No hay giro diario gratuito. GET sirve los premios y muestra si el jugador
 * autenticado tiene una cortesía pendiente. POST solo acepta girar_cortesia;
 * todo premio se registra en bonos_pendientes y se acredita con la próxima carga.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';
// crm_lib es opcional: si esta, registra el movimiento en el historial.
$crmLib = __DIR__ . '/crm_lib.php';
if (is_file($crmLib)) { require_once $crmLib; }
// crm_notificaciones es opcional: si esta, habilita el giro de cortesia
// (bono de ruleta prometido por notificacion, fuera del limite diario normal).
$crmNotif = __DIR__ . '/crm_notificaciones.php';
if (is_file($crmNotif)) { require_once $crmNotif; }
$authLib = __DIR__ . '/auth_lib.php';
if (is_file($authLib)) { require_once $authLib; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// =====================  EDITA LOS PREMIOS  ================================
// El ORDEN importa: el cliente dibuja los sectores en este mismo orden.
// 'bonus' = cuantos bonos se acreditan. 'peso' = probabilidad relativa.
const PREMIOS = [
    ['label' => '400',   'bonus' => 400,  'peso' => 30],
    ['label' => '1.000', 'bonus' => 1000, 'peso' => 30],
    ['label' => 'Nada',  'bonus' => 0,    'peso' => 5],
    ['label' => '500',   'bonus' => 500,  'peso' => 25],
    ['label' => '2.000', 'bonus' => 2000, 'peso' => 10],
];
// ==========================================================================

function premios_publicos(): array
{
    return array_map(fn($p) => ['label' => $p['label'], 'bonus' => $p['bonus']], PREMIOS);
}

/** Elige un indice segun los pesos. random_int es criptografico. */
function elegir_indice(): int
{
    $total = 0;
    foreach (PREMIOS as $p) { $total += $p['peso']; }
    $r = random_int(1, $total);
    $acc = 0;
    foreach (PREMIOS as $i => $p) {
        $acc += $p['peso'];
        if ($r <= $acc) { return $i; }
    }
    return count(PREMIOS) - 1;
}

function existe_usuario(PDO $pdo, string $usuario): bool
{
    $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    return (bool)$st->fetchColumn();
}

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Compatibilidad histórica para revisar giros antiguos. El widget actual no
 * usa ni anuncia este giro diario.
 */
function giro_disponible(PDO $pdo, string $usuario): bool
{
    if ($usuario === '') { return false; }
    $st = $pdo->prepare("SELECT 1 FROM ruleta_giros
                         WHERE usuario = ? AND dia = CURDATE() AND reclamado = 1 LIMIT 1");
    $st->execute([$usuario]);
    return !$st->fetchColumn();   // sin reclamo hoy = tiene giro
}

// --------------------------- GET: premios para dibujar ---------------------
// Con ?usuario=NOMBRE agrega `disponible`: true solo si tiene un giro de
// cortesía regalado. No hay tirada diaria automática.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $u      = trim((string)($_GET['usuario'] ?? ''));
    $activa = cfg_crm_activo($pdo, 'ruleta_activa');

    // `activa` viaja SIEMPRE: es lo que mira el widget para dibujar la ruleta
    // o esconderla. Apagada no se devuelve `disponible` -- que el jugador vea
    // "tenés un giro" y despues no pueda girarlo es peor que no ofrecerlo.
    $out = ['ok' => true, 'activa' => $activa, 'premios' => premios_publicos()];
    if (!$activa) {
        $msg = trim((string)cfg_crm($pdo, 'ruleta_mensaje'));
        if ($msg !== '') { $out['mensaje'] = $msg; }
        salir($out);
    }
    $cortesia = $u !== '' && function_exists('crmnotif_cortesia_disponible')
        ? crmnotif_cortesia_disponible($pdo, $u) : false;
    $out['cortesia_disponible'] = $cortesia;
    $out['disponible'] = $cortesia;
    salir($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string)($body['accion'] ?? 'girar');
/* ip_cliente() y no REMOTE_ADDR: acá la IP es solo registro (los límites de
   la ruleta salen de los UNIQUE por sesión y por día), pero guardar el edge de
   Cloudflare en vez del jugador no sirve para nada. Ver api/ip_cliente.php. */
require_once __DIR__ . '/ip_cliente.php';
$ip     = ip_cliente() ?: null;

/* Ruleta apagada desde el CRM (Configuración -> Ruleta de bonos).
   Se corta ACA, antes de cualquier accion: esconder el boton en el widget no
   alcanza, este endpoint es publico y cualquiera puede seguir posteandole.
   El chequeo va despues de leer $accion para poder dejar pasar 'premios', que
   es solo lectura y lo usa el widget para saber que dibujar. */
if ($accion !== 'premios' && !cfg_crm_activo($pdo, 'ruleta_activa')) {
    $msg = trim((string)cfg_crm($pdo, 'ruleta_mensaje'));
    salir([
        'ok'       => false,
        'codigo'   => 'ruleta_apagada',
        'error'    => $msg !== '' ? $msg : 'La ruleta no está disponible en este momento.',
    ], 403);
}

// Los giros diarios anónimos quedaron deshabilitados: el único camino para
// jugar es una cortesía pendiente, creada desde el CRM o Fidelización.
if (!in_array($accion, ['girar_cortesia', 'premios'], true)) {
    salir(['ok' => false, 'codigo' => 'requiere_regalo',
           'error' => 'La ruleta solo está disponible con un giro regalado.'], 403);
}

try {
    // ============================ GIRAR ====================================
    if ($accion === 'girar') {
        $session = substr(trim((string)($body['session_id'] ?? '')), 0, 64);
        if ($session === '') {
            salir(['ok' => false, 'error' => 'Falta session_id'], 400);
        }

        // ¿Ya giró esta sesión hoy? Devolvemos el mismo giro (no re-tira).
        $st = $pdo->prepare("SELECT indice, token, premio_bonus, reclamado
                             FROM ruleta_giros WHERE session_id = ? AND dia = CURDATE() LIMIT 1");
        $st->execute([$session]);
        $g = $st->fetch(PDO::FETCH_ASSOC);

        if ($g) {
            $i = (int)$g['indice'];
            salir([
                'ok'        => true,
                'indice'    => $i,
                'token'     => $g['token'],
                'bonus'     => (int)$g['premio_bonus'],
                'label'     => PREMIOS[$i]['label'],
                'ya_giro'   => true,
                'reclamado' => (bool)$g['reclamado'],
                'mensaje'   => $g['reclamado'] ? 'Ya reclamaste tu premio de hoy. Volvé mañana.' : '',
            ]);
        }

        $indice = elegir_indice();
        $bonus  = (int)PREMIOS[$indice]['bonus'];
        $token  = bin2hex(random_bytes(16));
        try {
            $pdo->prepare(
                "INSERT INTO ruleta_giros (session_id, dia, indice, premio_bonus, token, ip)
                 VALUES (?, CURDATE(), ?, ?, ?, ?)"
            )->execute([$session, $indice, $bonus, $token, $ip]);
        } catch (PDOException $e) {
            // Carrera: otra request creó el giro. Lo leemos.
            if (($e->errorInfo[1] ?? 0) == 1062) {
                $st->execute([$session]);
                $g = $st->fetch(PDO::FETCH_ASSOC);
                $i = (int)$g['indice'];
                salir(['ok' => true, 'indice' => $i, 'token' => $g['token'],
                       'bonus' => (int)$g['premio_bonus'], 'label' => PREMIOS[$i]['label'],
                       'ya_giro' => true, 'reclamado' => (bool)$g['reclamado']]);
            }
            throw $e;
        }

        salir(['ok' => true, 'indice' => $indice, 'token' => $token,
               'bonus' => $bonus, 'label' => PREMIOS[$indice]['label'], 'ya_giro' => false]);
    }

    // ============================ RECLAMAR =================================
    if ($accion === 'reclamar') {
        $token   = substr(trim((string)($body['token'] ?? '')), 0, 64);
        $usuario = trim((string)($body['usuario'] ?? ''));
        if ($token === '' || $usuario === '') {
            salir(['ok' => false, 'error' => 'Falta el token o el usuario'], 400);
        }

        // El giro tiene que existir, ser de hoy y no estar reclamado.
        $st = $pdo->prepare("SELECT id, indice, premio_bonus, reclamado
                             FROM ruleta_giros WHERE token = ? AND dia = CURDATE() LIMIT 1");
        $st->execute([$token]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            salir(['ok' => false, 'error' => 'Ese giro no es válido o venció. Girá de nuevo.'], 400);
        }
        if ((int)$g['reclamado'] === 1) {
            salir(['ok' => false, 'codigo' => 'ya_reclamado', 'error' => 'Ese premio ya fue reclamado.']);
        }

        $bonus = (int)$g['premio_bonus'];
        if ($bonus <= 0) {
            // "Nada": no hay nada que acreditar, pero marcamos el giro.
            $pdo->prepare("UPDATE ruleta_giros SET reclamado = 1, usuario = ?, reclamado_en = NOW()
                           WHERE id = ? AND reclamado = 0")->execute([substr($usuario, 0, 50), (int)$g['id']]);
            salir(['ok' => true, 'bonus' => 0, 'usuario' => $usuario, 'mensaje' => 'Esta vez no tocó premio. ¡Suerte mañana!']);
        }

        if (!existe_usuario($pdo, $usuario)) {
            salir(['ok' => false, 'codigo' => 'sin_usuario',
                   'error' => 'Ese usuario no existe. Registrate primero para reclamar.']);
        }

        // Un reclamo por usuario por dia.
        $st = $pdo->prepare("SELECT 1 FROM ruleta_giros
                             WHERE usuario = ? AND dia = CURDATE() AND reclamado = 1 LIMIT 1");
        $st->execute([$usuario]);
        if ($st->fetchColumn()) {
            salir(['ok' => false, 'codigo' => 'ya_reclamo_hoy',
                   'error' => 'Ese usuario ya reclamó un premio hoy. Volvé mañana.']);
        }

        // Marcamos el giro (guarda anti doble-reclamo por affected rows) y acreditamos.
        $upd = $pdo->prepare("UPDATE ruleta_giros SET reclamado = 1, usuario = ?, reclamado_en = NOW()
                              WHERE id = ? AND reclamado = 0");
        $upd->execute([substr($usuario, 0, 50), (int)$g['id']]);
        if ($upd->rowCount() !== 1) {
            salir(['ok' => false, 'error' => 'Ese premio ya fue reclamado.']);
        }

        /* EL PREMIO QUEDA PENDIENTE Y SE ACREDITA CON LA CARGA (pedido del
           dueño, 18/09/2026: "los bonos ganados en la ruleta también deben
           ser con la carga; si no cargan, el bono queda pendiente"). Antes se
           sumaba a usuarios.bonus en el acto: un premio jugable sin poner un
           peso. Ahora entra a `bonos_pendientes` (migración 33) y lo aplica
           crmnotif_bono_aplicar_en_recarga() junto con la próxima carga
           acreditada — el MISMO mecanismo del bono prometido del CRM, así la
           ficha del jugador lo muestra como pendiente y no hay dos maneras de
           deber un bono. Sin la migración 33 se degrada al comportamiento
           viejo (acreditar ya): perderle el premio al jugador es peor. */
        $pendiente = false;
        if (function_exists('crmnotif_bono_crear')) {
            $rp = crmnotif_bono_crear($pdo, $usuario, 'fichas', $bonus, 'ruleta');
            $pendiente = !empty($rp['ok']);
        }
        if (!$pendiente) {
            if (function_exists('crm_cargar')) {
                $r = crm_cargar($pdo, $usuario, 'bono', $bonus, 'Ruleta diaria', 'ruleta');
                if (!$r['ok']) { salir(['ok' => false, 'error' => $r['error'] ?? 'No se pudo acreditar'], 400); }
            } else {
                $pdo->prepare("UPDATE usuarios SET bonus = bonus + ? WHERE username = ?")
                    ->execute([$bonus, $usuario]);
            }
        }

        salir(['ok' => true, 'bonus' => $bonus, 'usuario' => $usuario,
               'pendiente' => $pendiente]);
    }

    // ====================== GIRAR (CORTESÍA) ================================
    // Giro de cortesía prometido por notificación (tabla ruleta_giros_cortesia,
    // aparte de ruleta_giros): no cuenta contra el límite diario normal, ni lo
    // toca. Un giro y listo, sin token intermedio: se sortea y acredita en la
    // misma llamada porque no hay "girar" y "reclamar" separados como en el
    // camino normal -- el jugador ya demostró ser él al loguearse en la app.
    if ($accion === 'girar_cortesia') {
        $usuario = trim((string)($body['usuario'] ?? ''));
        if ($usuario === '') {
            salir(['ok' => false, 'error' => 'Falta el usuario'], 400);
        }
        // Si el widget tiene el JWT propio, este manda: no aceptar un username
        // diferente ni caer a identidad sin firmar ante un token inválido.
        $jwt = trim((string)($body['token'] ?? ''));
        if ($jwt !== '') {
            $claims = function_exists('jwt_verificar') ? jwt_verificar($jwt, cfg('JWT_SECRET')) : false;
            $identidad = is_array($claims) ? (string)($claims['username'] ?? '') : '';
            if ($identidad === '' || !hash_equals(mb_strtolower($identidad), mb_strtolower($usuario))) {
                salir(['ok' => false, 'codigo' => 'sesion_invalida', 'error' => 'La sesión no corresponde a este jugador. Volvé a iniciar sesión.'], 401);
            }
            $usuario = $identidad;
        }
        if (!function_exists('crmnotif_cortesia_disponible') || !crmnotif_cortesia_disponible($pdo, $usuario)) {
            salir(['ok' => false, 'codigo' => 'sin_cortesia', 'error' => 'No tenés un giro de cortesía disponible.']);
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia
                  WHERE usuario = ? AND estado = 'pendiente'
                  ORDER BY creado_en ASC LIMIT 1 FOR UPDATE"
            );
            $st->execute([$usuario]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if (!$g) {
                $pdo->rollBack();
                salir(['ok' => false, 'codigo' => 'sin_cortesia', 'error' => 'No tenés un giro de cortesía disponible.']);
            }

            $upd = $pdo->prepare(
                "UPDATE ruleta_giros_cortesia SET estado = 'usado', usado_en = NOW()
                  WHERE id = ? AND estado = 'pendiente'"
            );
            $upd->execute([(int)$g['id']]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                salir(['ok' => false, 'error' => 'Ese giro ya fue usado.']);
            }
            /* EL BONO 'giro' QUE PROMETIO ESTE GIRO SE MARCA APLICADO ACA.
               No lo hacia NADIE (encontrado en la auditoria del 18/09/2026):
               el unico UPDATE a 'aplicado' filtra tipo IN ('fichas','pct'),
               asi que cada giro usado seguia contando como "1×giro" pendiente
               en la ficha del jugador PARA SIEMPRE — el "muestra cualquier
               cosa como bono pendiente" que reporto el dueño. El premio del
               giro sigue su propio camino (bono pendiente de fichas, abajo);
               esta fila ya cumplio. La migracion 71 repara las viejas. */
            if (!empty($g['bono_pendiente_id'])) {
                $pdo->prepare(
                    "UPDATE bonos_pendientes SET estado = 'aplicado', aplicado_en = NOW()
                      WHERE id = ? AND tipo = 'giro' AND estado = 'pendiente'"
                )->execute([(int)$g['bono_pendiente_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('ruleta girar_cortesia: ' . $e->getMessage());
            salir(['ok' => false, 'error' => 'No se pudo procesar el giro'], 500);
        }

        $indice = elegir_indice();
        $bonus  = (int)PREMIOS[$indice]['bonus'];
        // El premio queda pendiente y entra con la próxima carga acreditada.
        $pendiente = false;
        if ($bonus > 0) {
            $rp = function_exists('crmnotif_bono_crear')
                ? crmnotif_bono_crear($pdo, $usuario, 'fichas', $bonus, 'ruleta_cortesia')
                : ['ok' => false];
            $pendiente = !empty($rp['ok']);
            if (!$pendiente) {
                // Si falla el ledger, devolver el giro: nunca acreditar este
                // premio de forma inmediata ni dejar que se pierda en silencio.
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE ruleta_giros_cortesia SET estado = 'pendiente', usado_en = NULL WHERE id = ? AND estado = 'usado'")
                        ->execute([(int)$g['id']]);
                    if (!empty($g['bono_pendiente_id'])) {
                        $pdo->prepare("UPDATE bonos_pendientes SET estado = 'pendiente', aplicado_en = NULL WHERE id = ? AND tipo = 'giro'")
                            ->execute([(int)$g['bono_pendiente_id']]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('ruleta: no pude restaurar la cortesía: ' . $e->getMessage());
                }
                salir(['ok' => false, 'codigo' => 'premio_pendiente_error',
                       'error' => 'Salió un premio, pero no pude dejarlo pendiente. El giro sigue disponible; probá de nuevo.'], 503);
            }
        }

        salir(['ok' => true, 'indice' => $indice, 'bonus' => $bonus, 'label' => PREMIOS[$indice]['label'],
               'usuario' => $usuario, 'pendiente' => $pendiente]);
    }

    salir(['ok' => false, 'error' => 'accion desconocida'], 400);
} catch (Throwable $e) {
    error_log('ruleta: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'No se pudo procesar', 'detalle' => $e->getMessage()], 500);
}
