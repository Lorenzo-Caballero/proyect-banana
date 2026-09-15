<?php
/**
 * t_derivada.php — El cartel «Te necesita» se puede sacar.
 *
 * EL BUG (reportado el 15/09/2026): "queda un 'te necesita' en el chat, no se
 * puede sacar al parecer". Era cierto y era literal. `conversaciones.derivada_en`
 * —la marca que el bot pone al derivar a un humano— se limpiaba en UN SOLO
 * lugar de toda la API: la acción `responder` de crm.php. Atender el chat,
 * cerrarlo o archivarlo no la tocaban.
 *
 * Y eso no era cosmético. La bandeja ordena por
 *
 *     ORDER BY c.fijada DESC, (c.derivada_en IS NOT NULL) DESC, ...
 *
 * y el badge del rail es `SUM(derivada_en IS NOT NULL)`. O sea que una
 * conversación ya resuelta quedaba clavada arriba de todo y sumando a un
 * número que no bajaba nunca — que es la forma de que en dos días nadie le
 * dé bola a ese número.
 *
 * Esto ejercita crm_bajar_derivada(), que es la función que ahora comparten
 * los cuatro caminos, y la MECÁNICA de cada uno tal como la hace crm.php.
 *
 *     T_PORT=3399 php t_derivada.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/crm_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$limpiar = fn() => $pdo->exec("DELETE FROM conversaciones WHERE clave LIKE 't_der%'");
$limpiar();

/** Una conversación YA DERIVADA por el bot, como la deja chatbot.php. */
function derivada(PDO $pdo, string $clave): int {
    $pdo->prepare(
        "INSERT INTO conversaciones (session_id, usuario, clave, estado, derivada_en, derivada_motivo)
         VALUES (?, ?, ?, 'pendiente', NOW(), 'el jugador pidió un agente')"
    )->execute(['sid-' . $clave, 'u_' . $clave, $clave]);
    return (int)$pdo->lastInsertId();
}
/** ¿Sigue puesta la marca? Es lo que el front dibuja como «Te necesita». */
function marcada(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("SELECT derivada_en FROM conversaciones WHERE id = ?");
    $st->execute([$id]);
    return $st->fetchColumn() !== null;
}
/** El número del badge del rail, con el mismo SQL que crm.php. */
function badge(PDO $pdo): int {
    return (int)$pdo->query(
        "SELECT COALESCE(SUM(derivada_en IS NOT NULL),0) FROM conversaciones
          WHERE clave LIKE 't_der%' AND archivada = 0"
    )->fetchColumn();
}

// ===========================================================================
echo "=== 1. Punto de partida: derivada = marcada y contando ===\n";
$c = derivada($pdo, 't_der1');
chequear('queda marcada al derivar', marcada($pdo, $c));
chequear('y suma al badge del rail', badge($pdo) === 1, 'badge=' . badge($pdo));

// ===========================================================================
echo "\n=== 2. ATENDER la baja (antes no hacía nada) ===\n";
/* Atender ES la respuesta al pedido del bot: pidió una persona y la persona
   apareció. Este era el caso más reportado: el agente tomaba el chat y el
   cartel seguía ahí. */
chequear('crm_bajar_derivada devuelve 1', crm_bajar_derivada($pdo, [$c]) === 1);
chequear('la marca se fue', !marcada($pdo, $c));
chequear('el badge bajó a 0', badge($pdo) === 0, 'badge=' . badge($pdo));

// ===========================================================================
echo "\n=== 3. Es idempotente: bajarla dos veces no rompe ni miente ===\n";
/* Importante porque `responder` la llama en CADA respuesta del agente. El
   rowCount tiene que decir "ninguna ESTABA derivada", no "toqué una fila". */
chequear('la segunda vez devuelve 0', crm_bajar_derivada($pdo, [$c]) === 0);

// ===========================================================================
echo "\n=== 4. CERRAR el ticket la baja ===\n";
$c2 = derivada($pdo, 't_der2');
$pdo->prepare("UPDATE conversaciones SET estado = 'cerrada' WHERE id = ?")->execute([$c2]);
crm_bajar_derivada($pdo, [$c2]);     // lo que hace crm.php cuando estado === 'cerrada'
chequear('un ticket cerrado no puede seguir pidiendo ayuda', !marcada($pdo, $c2));

// ===========================================================================
echo "\n=== 5. ARCHIVAR la baja, y en LOTE ===\n";
/* Archivar es "sacame esto de la bandeja". Si la marca queda puesta, reaparece
   en la vista «Todo» y en cualquier búsqueda. Va en lote porque el CRM archiva
   con selección múltiple. */
$c3 = derivada($pdo, 't_der3');
$c4 = derivada($pdo, 't_der4');
chequear('dos derivadas más en el badge', badge($pdo) === 2, 'badge=' . badge($pdo));
$n = crm_bajar_derivada($pdo, [$c3, $c4]);
chequear('el lote baja las dos de una', $n === 2, "n=$n");
chequear('badge en 0', badge($pdo) === 0, 'badge=' . badge($pdo));

// ===========================================================================
echo "\n=== 6. No toca lo que no le corresponde ===\n";
$c5 = derivada($pdo, 't_der5');
$c6 = derivada($pdo, 't_der6');
crm_bajar_derivada($pdo, [$c5]);
chequear('la otra conversación sigue marcada', marcada($pdo, $c6));
chequear('un id que no existe no explota', crm_bajar_derivada($pdo, [99999999]) === 0);
chequear('una lista vacía tampoco', crm_bajar_derivada($pdo, []) === 0);
chequear('basura en la lista tampoco', crm_bajar_derivada($pdo, [0, -3]) === 0);

// ===========================================================================
echo "\n=== 7. NO se lleva puesta la reconexión automática del bot ===\n";
/* La trampa que ya costó una vez: si la reconexión se anclara en `derivada_en`,
   bajar la marca dejaría al bot mudo para siempre. El ancla es
   `ia_silencio_en` (migración 60) justamente por esto, y tiene que sobrevivir. */
$c7 = derivada($pdo, 't_der7');
$pdo->prepare("UPDATE conversaciones SET ia_activa = 0, ia_silencio_en = NOW() WHERE id = ?")
    ->execute([$c7]);
crm_bajar_derivada($pdo, [$c7]);
$f = $pdo->query("SELECT ia_activa, ia_silencio_en FROM conversaciones WHERE id = $c7")->fetch();
chequear('ia_silencio_en sobrevive (el bot puede despertar)', $f['ia_silencio_en'] !== null);
chequear('y no se prende la IA de prepo', (int)$f['ia_activa'] === 0);

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
