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

/* Se saca la SQL del archivo en vez de incluirlo: `crm_auditoria.php` es un
   endpoint y al requerirlo exige sesión y responde HTTP. Lo que importa probar
   es la consulta, y así se prueba la que realmente corre en producción. */
$src = file_get_contents(__DIR__ . '/api/crm_auditoria.php');
if (!preg_match('/function au_query_base\(\): string\s*\{\s*return "(.*?)";\s*\}/s', $src, $m)) {
    fwrite(STDERR, "No pude extraer au_query_base() de crm_auditoria.php\n");
    exit(1);
}
// Deshacer los escapes del string PHP (\$ y \\) para recuperar la SQL literal.
$SQL = str_replace(['\\$', '\\\\'], ['$', '\\'], $m[1]);

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

$filas = function (?string $fuente = null) use ($pdo, $SQL) {
    $w = $fuente ? " WHERE fuente = " . $pdo->quote($fuente) : '';
    return $pdo->query("SELECT * FROM ($SQL) x$w ORDER BY fecha_orden")->fetchAll();
};

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
chequear('las tres viejas siguen ahi',
         isset($porFuente['recargas'], $porFuente['acciones_saldo'], $porFuente['movimientos']),
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
echo "\n=== 4. No se afirma que la plata salio ===\n";
/* El panel deja de listar el pedido tanto si se pagó como si se rechazó, y no
   tenemos capturado el endpoint que los distingue. */
chequear('el detalle dice que no sabemos',
         $ce && str_contains((string)$ce['detalle'], 'no sabemos si se pagó o se rechazó'),
         (string)($ce['detalle'] ?? ''));
chequear('el subtipo no dice "pagado"', $ce && $ce['subtipo'] === 'resuelto_panel',
         (string)($ce['subtipo'] ?? ''));
chequear('el abierto dice que falta resolverlo',
         $ab && str_contains((string)$ab['detalle'], 'todavía sin resolver'));

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
chequear('el filtro tipo=retiro las agarra', $n === 3, "n=$n");

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
