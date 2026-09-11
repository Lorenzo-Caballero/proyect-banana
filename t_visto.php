<?php
/**
 * t_visto.php — El "visto" de los mensajes y el borrado de avisos efímeros.
 *
 * Ejercita la mecánica que usan mis_mensajes.php y crm.php (accion
 * conversacion) sobre la migración 57:
 *   - estampar visto_en en los mensajes del agente cuando el chat está abierto
 *   - borrar el aviso efímero (bono) vencido: visto_en + meta.efimero <= ahora
 *   - estampar visto_en en los mensajes del jugador al abrir el CRM
 *
 *     T_PORT=3399 php t_visto.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// Conversacion de laboratorio
$pdo->exec("DELETE FROM mensajes WHERE conversacion_id IN (SELECT id FROM conversaciones WHERE clave='t_visto')");
$pdo->exec("DELETE FROM conversaciones WHERE clave='t_visto'");
$pdo->prepare("INSERT INTO conversaciones (session_id, usuario, clave) VALUES ('sid-tv','t_visto_user','t_visto')")->execute();
$conv = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO mensajes (conversacion_id, rol, texto, meta, creado_en, visto_en) VALUES (?,?,?,?,?,?)");

echo "=== 1. Visto del jugador: se estampa lo entregado y lo anterior ===\n";
$ins->execute([$conv, 'agente', 'viejo entregado antes', null, date('Y-m-d H:i:s', time()-600), null]);
$idViejo = (int)$pdo->lastInsertId();
$ins->execute([$conv, 'agente', 'recien entregado', null, date('Y-m-d H:i:s'), null]);
$idNuevo = (int)$pdo->lastInsertId();
// La sentencia de mis_mensajes.php con visto=1: desde = idViejo, entregado = [idNuevo]
$pdo->exec("UPDATE mensajes SET visto_en = NOW()
             WHERE conversacion_id IN ($conv) AND rol = 'agente'
               AND visto_en IS NULL AND (id <= $idViejo OR id IN ($idNuevo))");
$n = (int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE conversacion_id=$conv AND visto_en IS NOT NULL")->fetchColumn();
chequear('los dos mensajes quedaron vistos', $n === 2, (string)$n);

echo "\n=== 2. Efimero vencido se borra; el no vencido y el normal quedan ===\n";
$ins->execute([$conv, 'agente', 'bono viejo (vencido)', '{"efimero":300}', date('Y-m-d H:i:s', time()-900), date('Y-m-d H:i:s', time()-600)]);
$idVencido = (int)$pdo->lastInsertId();
$ins->execute([$conv, 'agente', 'bono recien visto', '{"efimero":300}', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$idVivo = (int)$pdo->lastInsertId();
// El barrido de mis_mensajes.php (PHP decide con visto_en + efimero):
$ef = $pdo->query("SELECT id, meta, visto_en FROM mensajes
                    WHERE conversacion_id IN ($conv) AND visto_en IS NOT NULL AND meta LIKE '%efimero%'")->fetchAll();
$borrar = [];
foreach ($ef as $e2) {
    $m2 = json_decode((string)$e2['meta'], true);
    $vida = is_array($m2) ? (int)($m2['efimero'] ?? 0) : 0;
    if ($vida > 0 && strtotime((string)$e2['visto_en']) + $vida <= time()) { $borrar[] = (int)$e2['id']; }
}
if ($borrar) { $pdo->exec("DELETE FROM mensajes WHERE id IN (" . implode(',', $borrar) . ")"); }
chequear('el vencido se fue', !(bool)$pdo->query("SELECT 1 FROM mensajes WHERE id=$idVencido")->fetchColumn());
chequear('el recien visto sigue (le quedan 5 min)', (bool)$pdo->query("SELECT 1 FROM mensajes WHERE id=$idVivo")->fetchColumn());
chequear('los mensajes normales vistos NO se borran', (bool)$pdo->query("SELECT 1 FROM mensajes WHERE id=$idViejo")->fetchColumn());

echo "\n=== 3. El agente abre el CRM: visto en los mensajes del jugador ===\n";
$ins->execute([$conv, 'user', 'hola quiero cargar', null, date('Y-m-d H:i:s'), null]);
$pdo->prepare("UPDATE mensajes SET visto_en = NOW() WHERE conversacion_id = ? AND rol = 'user' AND visto_en IS NULL")->execute([$conv]);
$leido = $pdo->query("SELECT MAX(visto_en) FROM mensajes WHERE conversacion_id IN ($conv) AND rol='user' AND visto_en IS NOT NULL")->fetchColumn();
chequear('leido_user_en sale con fecha', !empty($leido), (string)$leido);

echo "\n=== 4. Eliminar un mensaje enviado (migracion 58) ===\n";
$ins->execute([$conv, 'agente', 'esto salio mal, borralo', null, date('Y-m-d H:i:s'), null]);
$idBorrable = (int)$pdo->lastInsertId();
$ins->execute([$conv, 'user', 'mensaje del jugador', null, date('Y-m-d H:i:s'), null]);
$idJugador = (int)$pdo->lastInsertId();
// La sentencia de crm.php mensaje_borrar:
$bo = $pdo->prepare("UPDATE mensajes SET borrado_en = NOW(), borrado_por = ?
                      WHERE id = ? AND rol <> 'user' AND borrado_en IS NULL");
$bo->execute(['test_op', $idBorrable]);
chequear('el saliente se marca borrado', $bo->rowCount() === 1);
$bo->execute(['test_op', $idJugador]);
chequear('el del JUGADOR no se puede borrar', $bo->rowCount() === 0);
$bo->execute(['test_op', $idBorrable]);
chequear('borrar dos veces no hace nada', $bo->rowCount() === 0);
// La entrega de mis_mensajes lo excluye:
$n = (int)$pdo->query("SELECT COUNT(*) FROM mensajes
                        WHERE conversacion_id IN ($conv) AND rol='agente' AND id > 0
                          AND borrado_en IS NULL AND id = $idBorrable")->fetchColumn();
chequear('la entrega ya no lo incluye', $n === 0);
// Y la lapida sale para la retraccion del widget:
$lap = array_map('intval', $pdo->query(
    "SELECT id FROM mensajes WHERE conversacion_id IN ($conv) AND rol='agente'
      AND borrado_en IS NOT NULL AND borrado_en >= NOW() - INTERVAL 1 DAY"
)->fetchAll(PDO::FETCH_COLUMN));
chequear('la lapida sale en borrados', in_array($idBorrable, $lap, true), json_encode($lap));

$pdo->exec("DELETE FROM mensajes WHERE conversacion_id=$conv");
$pdo->exec("DELETE FROM conversaciones WHERE id=$conv");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
