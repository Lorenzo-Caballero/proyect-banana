<?php
/**
 * t_auditoria.php — la vista unificada, con los retiros pedidos DENTRO del juego.
 *
 * POR QUÉ EXISTE (13/09/2026). Auditoría unía recargas + acciones_saldo +
 * movimientos. Los retiros que aparecían ahí eran solo los que el jugador pide
 * por el chat y ejecuta nuestro worker; el botón Retirar de adentro de la
 * plataforma no toca `acciones_saldo` —la solicitud vive del lado de ganamos—
 * así que el canal con MÁS volumen era el único que no figuraba en la pantalla
 * hecha justamente para no tener que creerle a nadie.
 *
 * La query es frágil por dos motivos y los dos se prueban acá:
 *
 *  1. Las tablas no comparten collation (ver CLAUDE.md). Un UNION ALL sin
 *     COLLATE explícito en cada columna de texto tira "Illegal mix of
 *     collations" recién al EJECUTARSE — `php -l` no lo ve.
 *  2. El espejo `retiros_panel` se refresca cada minuto y pisa
 *     `actualizada_en` con NOW(). Anclar la fecha ahí haría que un retiro
 *     abierto salte al tope de la auditoría una vez por minuto.
 *
 *     php t_auditoria.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* Se recorta el archivo antes del despacho HTTP y se evalúa: `crm_auditoria.php`
   es un endpoint, no una librería —al requerirlo exige sesión y contesta— pero
   arriba tiene las funciones y nada que mande headers.
   ANTES esto sacaba la SQL con un regex sobre `return "..."`. Dejó de servir el
   14/09/2026, cuando au_query_base() pasó a tener código antes del return (la
   consulta al libro de operaciones). Llamar a la función de verdad es más
   robusto: no puede quedar desincronizado con el archivo, y prueba también el
   armado, no solo el string. */
$src = file_get_contents(__DIR__ . '/api/crm_auditoria.php');
$corte = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] !== 'GET')");
if ($corte === false) { fwrite(STDERR, "No encontré el corte en crm_auditoria.php\n"); exit(1); }
$src = substr($src, 0, $corte);
$src = preg_replace('/^\s*<\?php/', '', $src, 1);
$src = preg_replace('/^\s*declare\(strict_types=1\);/m', '', $src, 1);
$src = preg_replace('/^\s*require(_once)?\s+__DIR__[^;]+;/m', '', $src);
$src = preg_replace('/^\s*\$operador\s*=\s*exigir_operador\(\);/m', '', $src);
$src = preg_replace('/^\s*header\([^;]+\);/m', '', $src);
eval($src);

$SQL = au_query_base();

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$limpiar = fn() => $pdo->exec("DELETE FROM retiros_panel WHERE request_id BETWEEN 990000 AND 990099");
$limpiar();

$poner = function (int $id, string $user, float $monto, string $estado,
                   string $primeraVez, ?string $actualizada, ?string $titular = null,
                   ?string $destino = null) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO retiros_panel (request_id, username, titular, monto, destino,
                                    estado, primera_vez, actualizada_en)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$id, $user, $titular, $monto, $destino, $estado, $primeraVez, $actualizada]);
};

/* La SQL se rearma en cada llamada a propósito: au_query_base() mira si el
   libro tiene filas para decidir si puede afirmar "rechazado", y este test
   llena el libro a mitad de camino justamente para probar las dos ramas. */
$filas = function (?string $fuente = null) use ($pdo) {
    $sql = au_query_base();
    $w = $fuente ? " WHERE fuente = " . $pdo->quote($fuente) : '';
    return $pdo->query("SELECT * FROM ($sql) x$w ORDER BY fecha_orden")->fetchAll();
};

$libroPoner = function (int $paymentId, string $user, float $monto,
                        string $cuando, int $tipo = 1) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO operaciones_panel (payment_id, tipo, username, monto, cuando)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE monto = VALUES(monto)"
    )->execute([$paymentId, $tipo, $user, $monto, $cuando]);
};
$libroLimpiar = fn() => $pdo->exec(
    "DELETE FROM operaciones_panel WHERE payment_id BETWEEN 990000 AND 990099");
$libroLimpiar();

/* LAS TRES FUENTES VIEJAS, SEMBRADAS POR ESTE TEST.
   Antes no se sembraban: la asercion "las tres viejas siguen ahi" se apoyaba
   en filas que dejaban OTROS tests de la suite. O sea que pasaba o fallaba
   segun el orden en que se corriera, y en una base limpia fallaba siempre --
   justo lo contrario de lo que se quiere de un test que cuida que un refactor
   no se lleve puesta una rama del UNION.
   Fechas viejas (2019) y usuarios con prefijo t_au_ para no pisar nada ni
   aparecer en ninguna ventana real. */
$viejasLimpiar = function () use ($pdo) {
    $pdo->exec("DELETE FROM recargas       WHERE usuario  LIKE 't_au_%'");
    $pdo->exec("DELETE FROM acciones_saldo WHERE usuario  LIKE 't_au_%'");
    $pdo->exec("DELETE FROM movimientos    WHERE usuario  LIKE 't_au_%'");
};
$viejasLimpiar();
$viejasPoner = function () use ($pdo) {
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                               estado, creada_en, vence_en)
         VALUES ('t_au_r1', 't_au_rec', 1000, 1000, 1000, 'acreditada',
                 '2019-05-03 10:00:00', '2019-05-04 10:00:00')"
    )->execute();
    $pdo->prepare(
        "INSERT INTO acciones_saldo (usuario, tipo, monto, estado, creada_en)
         VALUES ('t_au_ret', 'retirar', 5000, 'pendiente', '2019-05-03 11:00:00')"
    )->execute();
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES ('t_au_mov', 'ficha', 250, 'test auditoria', 'crm', '2019-05-03 12:00:00')"
    )->execute();
};
$viejasPoner();

// ===========================================================================
echo "\n=== 1. La query corre: ninguna collation choca ===\n";
/* Un choque acá tumba TODA la pantalla, no solo la rama nueva. */
$poner(990001, 't_au_ab', 15000, 'abierto', '2019-05-02 10:00:00', '2019-05-02 10:05:00',
       'Juan Perez', 'juan.mp');
$poner(990002, 't_au_ce',  8000, 'cerrado', '2019-05-01 09:00:00', '2019-05-01 12:00:00',
       'Ana Gomez', 'ana.alias');
$todas = $filas();
chequear('el UNION ALL de las 4 fuentes ejecuta', is_array($todas) && count($todas) > 0);

$porFuente = array_count_values(array_column($todas, 'fuente'));
chequear('la fuente nueva aparece', ($porFuente['retiros_panel'] ?? 0) === 2,
         json_encode($porFuente));
/* Cada una tiene AL MENOS la fila que sembro este test. Se compara con >= y no
   con == porque la base puede traer datos de otros lados; lo que se cuida es
   que ninguna rama del UNION desaparezca en un refactor. */
chequear('las tres viejas siguen ahi',
         ($porFuente['recargas'] ?? 0) >= 1
         && ($porFuente['acciones_saldo'] ?? 0) >= 1
         && ($porFuente['movimientos'] ?? 0) >= 1,
         json_encode($porFuente));

// ===========================================================================
echo "\n=== 2. Un retiro abierto NO salta al tope cada minuto ===\n";
/* `actualizada_en` se pisa con NOW() en cada pasada del espejo. Si la fecha
   saliera de ahí, el mismo pedido encabezaría la auditoría una vez por minuto
   y taparía todo lo demás. Se ancla en `primera_vez`, que es cuando pasó. */
$rp = $filas('retiros_panel');
$ab = null; $ce = null;
foreach ($rp as $f) { if ($f['usuario'] === 't_au_ab') { $ab = $f; } if ($f['usuario'] === 't_au_ce') { $ce = $f; } }
chequear('el abierto se ancla en primera_vez',
         $ab && substr((string)$ab['fecha'], 0, 19) === '2019-05-02 10:00:00', json_encode($ab['fecha'] ?? null));

/* Y se comprueba de verdad: se simula otra pasada del espejo. */
$pdo->exec("UPDATE retiros_panel SET actualizada_en = NOW() WHERE request_id = 990001");
$rp2 = $filas('retiros_panel');
$ab2 = null;
foreach ($rp2 as $f) { if ($f['usuario'] === 't_au_ab') { $ab2 = $f; } }
chequear('y no se mueve cuando el espejo se refresca',
         $ab2 && $ab2['fecha'] === $ab['fecha'], json_encode($ab2['fecha'] ?? null));

chequear('el cerrado si usa la fecha en que se resolvio',
         $ce && substr((string)$ce['fecha'], 0, 19) === '2019-05-01 12:00:00', json_encode($ce['fecha'] ?? null));

// ===========================================================================
echo "\n=== 3. Se dice QUE fue humano sin inventar QUIEN ===\n";
/* Lo resolvió una persona, pero del lado de ganamos: no tenemos su nombre.
   Dejarlo en blanco haría parecer que no lo tocó nadie; poner un nombre sería
   mentir en la única pantalla que existe para no tener que creerle a nadie. */
chequear('el cerrado figura como humano', $ce && $ce['actor_tipo'] === 'humano');
chequear('atribuido al panel, no a un operador nuestro',
         $ce && $ce['operador'] === 'Panel de ganamos', (string)($ce['operador'] ?? ''));
chequear('el abierto todavia no lo toco nadie', $ab && $ab['actor_tipo'] === 'sistema');
chequear('y no tiene operador', $ab && ($ab['operador'] === null || $ab['operador'] === ''));

/* El filtro "manual" de la pantalla es actor_tipo='humano' y el "automático"
   IN ('bot','sistema'): con estos dos valores las filas nuevas caen siempre en
   alguno de los dos y no desaparecen al filtrar. */
chequear('los dos actor_tipo son de los que la pantalla sabe filtrar',
         in_array($ab['actor_tipo'], ['humano','bot','sistema'], true)
         && in_array($ce['actor_tipo'], ['humano','bot','sistema'], true));

// ===========================================================================
echo "\n=== 4. Sin libro NO se afirma nada ===\n";
/* El panel deja de listar el pedido tanto si se pagó como si se rechazó. Sin
   el libro de operaciones, la ausencia de una fila no prueba un rechazo:
   prueba que no estamos mirando. */
chequear('el detalle dice que no sabemos',
         $ce && str_contains((string)$ce['detalle'], 'no sabemos si se pagó o se rechazó'),
         (string)($ce['detalle'] ?? ''));
chequear('el subtipo no dice "pagado"', $ce && $ce['subtipo'] === 'resuelto_panel',
         (string)($ce['subtipo'] ?? ''));
chequear('el abierto dice que falta resolverlo',
         $ab && str_contains((string)$ab['detalle'], 'todavía sin resolver'));

// ===========================================================================
echo "\n=== 4b. Con el libro SI se distingue pagado de rechazado ===\n";
/* `operaciones_panel` solo tiene operaciones EJECUTADAS (migración 67,
   verificado contra un depósito que rechazamos a mano y no figura). Así que
   estar en el libro prueba que la plata salió, y no estar prueba lo contrario.
   El 990002 se paga: se le mete su fila. El 990010 nace cerrado y sin fila. */
$poner(990010, 't_au_rech', 5000, 'cerrado', '2019-05-04 10:00:00', '2019-05-04 11:00:00');
$libroPoner(990002, 't_au_ce', 8000, '2019-05-01 12:00:00');
$libroPoner(990050, 't_au_otro', 1, '2019-05-01 12:00:00');   // para que el libro no esté vacío

$rp4 = $filas('retiros_panel');
$pagado = $rechazado = $abierto = null;
foreach ($rp4 as $f) {
    if ($f['usuario'] === 't_au_ce')   { $pagado = $f; }
    if ($f['usuario'] === 't_au_rech') { $rechazado = $f; }
    if ($f['usuario'] === 't_au_ab')   { $abierto = $f; }
}
chequear('el que figura en el libro sale PAGADO',
         $pagado && $pagado['subtipo'] === 'pagado', (string)($pagado['subtipo'] ?? ''));
chequear('y el detalle lo dice',
         $pagado && str_contains((string)$pagado['detalle'], 'PAGADO'));
chequear('el cerrado que NO figura sale RECHAZADO',
         $rechazado && $rechazado['subtipo'] === 'rechazado', (string)($rechazado['subtipo'] ?? ''));
chequear('y el detalle explica por qué',
         $rechazado && str_contains((string)$rechazado['detalle'], 'no figura en el libro'));
chequear('el que sigue abierto no se toca',
         $abierto && $abierto['subtipo'] === 'abierto', (string)($abierto['subtipo'] ?? ''));

/* El cruce es por request_id Y por tipo: un DEPÓSITO con el mismo id no puede
   hacer pasar por pagado a un retiro. */
$libroLimpiar();
$libroPoner(990002, 't_au_ce', 8000, '2019-05-01 12:00:00', 0);   // tipo 0 = depósito
$libroPoner(990050, 't_au_otro', 1, '2019-05-01 12:00:00');
$rp5 = $filas('retiros_panel');
$pagado2 = null;
foreach ($rp5 as $f) { if ($f['usuario'] === 't_au_ce') { $pagado2 = $f; } }
chequear('un deposito con el mismo id NO lo da por pagado',
         $pagado2 && $pagado2['subtipo'] === 'rechazado', (string)($pagado2['subtipo'] ?? ''));

$libroLimpiar();

// ===========================================================================
echo "\n=== 5. El detalle trae con que pagar sin abrir el chat ===\n";
chequear('el titular', $ce && str_contains((string)$ce['detalle'], 'Ana Gomez'));
chequear('el CBU/alias', $ce && str_contains((string)$ce['detalle'], 'ana.alias'));
chequear('y el monto', $ce && str_contains((string)$ce['detalle'], '8,000'));

/* Sin titular ni destino no puede quedar un " | " colgando ni romperse. */
$poner(990003, 't_au_pelado', 500, 'abierto', '2019-05-03 10:00:00', null);
$rp3 = $filas('retiros_panel');
$pelado = null;
foreach ($rp3 as $f) { if ($f['usuario'] === 't_au_pelado') { $pelado = $f; } }
chequear('un retiro sin titular ni CBU no rompe la fila', $pelado !== null);
chequear('y no deja separadores colgando',
         $pelado && !str_contains((string)$pelado['detalle'], '|  |'),
         (string)($pelado['detalle'] ?? ''));
chequear('sin actualizada_en igual tiene fecha',
         $pelado && $pelado['fecha'] !== null, json_encode($pelado['fecha'] ?? null));

// ===========================================================================
echo "\n=== 6. Cuenta como 'retiro' y entra en los filtros de tipo ===\n";
$tipos = array_unique(array_column($filas('retiros_panel'), 'tipo'));
chequear('todas son tipo retiro', $tipos === ['retiro'], json_encode(array_values($tipos)));

$n = (int)$pdo->query("SELECT COUNT(*) FROM ($SQL) x WHERE tipo='retiro' AND fuente='retiros_panel'")->fetchColumn();
chequear('el filtro tipo=retiro las agarra', $n === 4, "n=$n");

// ===========================================================================
echo "\n=== 7. El signo del ajuste: +35.000 no puede verse como -35.000 ===\n";

/* EL BUG (16/09/2026). Nahuel abrio Auditoria para contestar exactamente
   "¿a este jugador le cargamos dos veces?", y la fila de SU PROPIA carga de
   +35.000 le decia -35.000. Eran dos cosas encadenadas: la SQL devolvia
   ABS(m.monto), tirando el signo que `movimientos` si guarda, y la pantalla lo
   reponia por el TIPO -- y como 'ajuste' no es ni ingreso ni egreso, todos los
   ajustes salian en rojo con un menos.

   Una auditoria donde no se distingue lo que entra de lo que sale no sirve
   para lo unico que se le pide. Estos chequeos cuidan la mitad del server; la
   otra mitad vive en auFilaHtml() de crm.html. */
$pdo->exec("DELETE FROM movimientos WHERE usuario LIKE 't_sg_%'");

$mov = $pdo->prepare(
    "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, operador)
     VALUES (?,?,?,?,?,?)"
);
/* Las dos filas de aquella noche, tal cual las escribio el sistema. */
$mov->execute(['t_sg_uno', 'saldo',  35000, null, 'crm', 'nahuel']);   // carga a mano
$mov->execute(['t_sg_uno', 'ficha', -35000, 'Carga al juego', 'recarga', null]);
$mov->execute(['t_sg_uno', 'bono',    5000, 'Bono de bienvenida', 'recarga', null]);

$sg = $pdo->query(
    "SELECT monto, detalle, tipo FROM (" . au_query_base() . ") x
      WHERE usuario = 't_sg_uno' ORDER BY monto DESC"
)->fetchAll();

chequear('salen las tres filas', count($sg) === 3, (string)count($sg));

$porMonto = [];
foreach ($sg as $f) { $porMonto[(int)$f['monto']] = $f; }

chequear('la carga a mano llega POSITIVA (era el bug)',
         isset($porMonto[35000]), json_encode(array_keys($porMonto)));
chequear('las fichas gastadas llegan NEGATIVAS',
         isset($porMonto[-35000]), json_encode(array_keys($porMonto)));
chequear('y no se confunden entre si: son dos filas distintas',
         isset($porMonto[35000]) && isset($porMonto[-35000]));
chequear('el bono tambien conserva su signo', isset($porMonto[5000]));

/* El detalle de la carga a mano se arma solo (motivo NULL): es el
   "saldo +35000" que se veia en pantalla, y ahi el signo SIEMPRE estuvo bien.
   O sea que la fila se contradecia a si misma -- el monto decia una cosa y el
   detalle la contraria. */
chequear('el detalle autogenerado ya decia el signo correcto',
         isset($porMonto[35000]) && str_contains((string)$porMonto[35000]['detalle'], '+35000'),
         $porMonto[35000]['detalle'] ?? '(no está)');

/* Y los KPIs no pueden haberse movido: suman SOLO 'deposito' y 'retiro', que
   salen de recargas/acciones_saldo como magnitudes. Si un ajuste negativo
   entrara ahi, restaria de los depositos del dia. */
$tiposAjuste = array_unique(array_column($sg, 'tipo'));
sort($tiposAjuste);
chequear('ninguna de estas filas cuenta como deposito ni retiro',
         !in_array('deposito', $tiposAjuste, true) && !in_array('retiro', $tiposAjuste, true),
         json_encode($tiposAjuste));

$pdo->exec("DELETE FROM movimientos WHERE usuario LIKE 't_sg_%'");

$limpiar();
$libroLimpiar();
$viejasLimpiar();   // el test se lleva TODO lo suyo, incluidas las tres viejas
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
