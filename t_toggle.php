<?php
/**
 * t_toggle.php — El apagado/encendido MANUAL del bot desde un chat del CRM.
 *
 * EL BUG QUE BLINDA (visto con holaelianafernandez544, sept 2026):
 * el agente apaga el bot desde el chat y "el bot jamas se apaga". La causa no
 * era el UPDATE del CRM (anda), sino que el chatbot MIRABA OTRA FILA.
 *
 * El widget scrapea el nombre del jugador del header de la plataforma, y a
 * veces postea SIN usuario (recien cargada la pagina, antes de resolverlo). En
 * ese post anonimo:
 *   - crm_registrar_turno (via crm_conversacion_id) igual mete el turno en el
 *     chat CON NOMBRE: sigue la sesion ya identificada.
 *   - pero chatbot_ia_del_chat leia el estado por 'anon:<sid>' -> otra fila (o
 *     ninguna) -> IA activa por defecto -> el bot respondia un chat apagado.
 *
 * El arreglo: chatbot_clave_conv resuelve la clave IGUAL que crm_conversacion_id
 * (si el post viene sin usuario, usa el chat identificado de la sesion). Este
 * test fija esa invariante: el turno y el chequeo de IA tienen que caer en la
 * MISMA fila, la que el CRM apaga.
 *
 *     T_PORT=3399 php t_toggle.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require __DIR__ . '/api/crm_lib.php';   // crm_conversacion_id, la verdad de terreno

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* COPIA EXACTA de chatbot_clave_conv() (chatbot.php). No se puede incluir
   chatbot.php (corre el request al incluirse), asi que se replica aca. Si algun
   dia divergen, este test deja de proteger nada: mantenerlas iguales. */
function clave_como_chatbot(PDO $pdo, string $sessionId, string $usuario): string {
    if ($usuario !== '') { return mb_substr($usuario, 0, 50); }
    $sid = substr($sessionId, 0, 64);
    if ($sid === '') { return ''; }
    try {
        $st = $pdo->prepare(
            "SELECT usuario FROM conversaciones
              WHERE session_id = ? AND usuario IS NOT NULL AND usuario <> ''
              ORDER BY actualizada_en DESC LIMIT 1"
        );
        $st->execute([$sid]);
        $u = $st->fetchColumn();
        if ($u !== false && $u !== null && (string)$u !== '') {
            return mb_substr((string)$u, 0, 50);
        }
    } catch (Throwable $e) {}
    return 'anon:' . $sid;
}
// El estado de IA como lo leeria el chatbot: por la clave resuelta.
function ia_activa_chatbot(PDO $pdo, string $sid, string $usuario): bool {
    $clave = clave_como_chatbot($pdo, $sid, $usuario);
    if ($clave === '') { return true; }
    $st = $pdo->prepare("SELECT ia_activa FROM conversaciones WHERE clave = ? LIMIT 1");
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    if ($v === false) { return true; }
    return (int)$v === 1;
}
// La logica VIEJA (con el bug), para probar que el test detecta la regresion.
function ia_activa_vieja(PDO $pdo, string $sid, string $usuario): bool {
    $clave = $usuario !== '' ? mb_substr($usuario, 0, 50)
                             : ($sid !== '' ? 'anon:' . substr($sid, 0, 64) : '');
    if ($clave === '') { return true; }
    $st = $pdo->prepare("SELECT ia_activa FROM conversaciones WHERE clave = ? LIMIT 1");
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    if ($v === false) { return true; }
    return (int)$v === 1;
}

$SID = 'sid-tgl-001';
$USR = 't_tgl_user';
function limpiar(PDO $pdo, string $sid, string $usr): void {
    $pdo->prepare("DELETE FROM conversaciones WHERE clave IN (?, ?) OR session_id = ?")
        ->execute([$usr, 'anon:' . $sid, $sid]);
}
limpiar($pdo, $SID, $USR);

echo "=== 1. El jugador se identifica: hay UNA fila con nombre ===\n";
$idU = crm_conversacion_id($pdo, $SID, $USR);
chequear('crm_conversacion_id crea/devuelve el chat con nombre', $idU > 0, (string)$idU);
$claveFila = $pdo->query("SELECT clave FROM conversaciones WHERE id=$idU")->fetchColumn();
chequear("la clave es el usuario, no anon:<sid>", $claveFila === $USR, (string)$claveFila);

echo "\n=== 2. El agente apaga el bot desde el CRM (UPDATE por id) ===\n";
// Exactamente lo que hace crm.php accion 'chatbot_ia_chat'.
$pdo->prepare("UPDATE conversaciones SET ia_activa = 0 WHERE id = ?")->execute([$idU]);
$raw = (int)$pdo->query("SELECT ia_activa FROM conversaciones WHERE id=$idU")->fetchColumn();
chequear('la fila quedo en ia_activa = 0', $raw === 0, (string)$raw);

echo "\n=== 3. Post IDENTIFICADO (usuario en el body): el bot se ve apagado ===\n";
chequear('con usuario, el chatbot lee la MISMA fila -> IA apagada',
         ia_activa_chatbot($pdo, $SID, $USR) === false);

echo "\n=== 4. Post ANONIMO (widget sin nombre): el turno y el chequeo coinciden ===\n";
// El turno anonimo cae en el chat con nombre (crm_conversacion_id sigue la sesion).
$idAnon = crm_conversacion_id($pdo, $SID, null);
chequear('el turno anonimo cae en el chat CON NOMBRE (misma fila)', $idAnon === $idU,
         "idAnon=$idAnon idU=$idU");
// Y el chequeo de IA arreglado mira esa misma fila -> apagado. ESTE es el bug.
chequear('el chatbot ARREGLADO ve la IA apagada aunque el post venga anonimo',
         ia_activa_chatbot($pdo, $SID, '') === false);
// Con la logica vieja, el mismo caso daba "IA activa" -> el bot seguia hablando.
chequear('la logica VIEJA daba IA activa (documenta la regresion)',
         ia_activa_vieja($pdo, $SID, '') === true);

echo "\n=== 5. La reconexion NO revive un apagado manual (derivada_en NULL) ===\n";
// Mismo UPDATE condicional que chatbot_reconectar_derivacion, ventana 0 min.
$rec = $pdo->prepare(
    "UPDATE conversaciones SET ia_activa = 1, derivada_en = NULL, derivada_motivo = NULL
      WHERE clave = ? AND COALESCE(ia_activa,1) = 0
        AND derivada_en IS NOT NULL AND derivada_en <= NOW() - INTERVAL 0 MINUTE");
$rec->execute([$USR]);
chequear('un apagado manual (sin derivada_en) no lo reconecta ni con 0 min',
         $rec->rowCount() === 0, 'filas=' . $rec->rowCount());
chequear('y sigue apagado despues del intento de reconexion',
         ia_activa_chatbot($pdo, $SID, $USR) === false);

echo "\n=== 6. El agente vuelve a prender el bot ===\n";
$pdo->prepare("UPDATE conversaciones SET ia_activa = 1 WHERE id = ?")->execute([$idU]);
chequear('con usuario, el bot se ve prendido', ia_activa_chatbot($pdo, $SID, $USR) === true);
chequear('y anonimo tambien (misma fila)', ia_activa_chatbot($pdo, $SID, '') === true);

limpiar($pdo, $SID, $USR);

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
