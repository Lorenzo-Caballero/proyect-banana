<?php
/**
 * arreglar-bases-compartidas.php — Devuelve a cada cliente su propia base.
 *
 * EL PROBLEMA QUE RESUELVE, y es el peor de un multi-cliente. El 24/09/2026 se
 * descubrió que el cliente `ganamos` tenía `db_nombre = u722310012_fauno888`,
 * que es la base de `ganamoscrm` — LA NUESTRA. Su CRM resolvía a nuestros
 * jugadores, nuestra plata y nuestras conversaciones.
 *
 * Nueve días después seguía igual, porque el aviso que debía gritarlo moría en
 * un TypeError (ver el arreglo de tg_evento del 03/10). Lo único que contenía
 * la fuga era un accidente: su operador no existe en nuestra base, así que el
 * login lo rechazaba. El día que alguien cree ese usuario, se filtra todo.
 *
 * ============================================================================
 * POR QUÉ UN SCRIPT Y NO UN UPDATE A MANO: porque la pregunta difícil no es
 * "cómo lo cambio" sino "a qué base va cada uno, y tiene datos adentro". Eso
 * se contesta mirando, y mirar a mano a las 3 de la mañana es como se rompen
 * las cosas. Acá se mira solo y se muestra ANTES de tocar nada.
 *
 * LA REGLA: `panel.php` calcula `db_nombre = 'gp_' . slug` al dar de alta un
 * cliente, y el `editar` excluye ese campo a propósito. O sea que el valor
 * correcto de un cliente creado por el panel es siempre `gp_<slug>`. Este
 * script reasigna SOLO a los clientes para los que esa base EXISTE: si no
 * existe, no hay a dónde mandarlo y lo dice en vez de inventar.
 *
 * NO TOCA AL DUEÑO DE LA BASE. En un conflicto, el que se queda con la base es
 * el que NO tiene una `gp_<slug>` propia a dónde ir — típicamente nosotros,
 * cuya base es una heredada de Hostinger y no sigue ese patrón. Así el arreglo
 * no puede cortarnos a nosotros mismos, que sería cambiar un problema grave por
 * uno peor.
 *
 *   php scripts/arreglar-bases-compartidas.php           # muestra, no toca
 *   php scripts/arreglar-bases-compartidas.php --aplicar # lo hace
 */

declare(strict_types=1);

$aplicar = in_array('--aplicar', $argv, true);

/* El config se puede apuntar a otro lado con GP_PANEL_CONFIG. Es lo que hace
   que esto se pueda PROBAR: un script que reasigna la base de un cliente no
   puede estrenarse en producción, y sin este gancho la única forma de
   ejercitarlo sería correrlo contra el control de verdad. Ver t_bases.php. */
$cfgPath = getenv('GP_PANEL_CONFIG') ?: (__DIR__ . '/../panel/panel_config.php');
$cfg = require $cfgPath;

function line(string $m = ''): void { echo $m . "\n"; }

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "No pude conectar a la base de control: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------- los conflictos
$dup = $pdo->query(
    "SELECT db_nombre, COUNT(*) AS n, GROUP_CONCAT(slug ORDER BY id) AS slugs
       FROM clientes
      WHERE db_nombre IS NOT NULL AND db_nombre <> '' AND estado = 'activo'
      GROUP BY db_nombre HAVING n > 1"
)->fetchAll();

if (!$dup) {
    line('Ningún cliente activo comparte base. No hay nada que arreglar.');
    exit(0);
}

line($aplicar ? '=== APLICANDO ===' : '=== VISTA PREVIA (no se toca nada) ===');
line('');

/** ¿Existe esa base y cuántas tablas tiene? */
function base_info(PDO $pdo, string $db): ?array
{
    $q = $pdo->prepare(
        "SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema = ?"
    );
    $q->execute([$db]);
    $n = (int) ($q->fetch()['tablas'] ?? 0);
    return $n > 0 ? ['tablas' => $n] : null;
}

$cambios = [];

foreach ($dup as $d) {
    line("La base {$d['db_nombre']} la comparten: {$d['slugs']}");

    $q = $pdo->prepare(
        "SELECT id, slug, nombre FROM clientes
          WHERE db_nombre = ? AND estado = 'activo' ORDER BY id"
    );
    $q->execute([$d['db_nombre']]);

    foreach ($q->fetchAll() as $c) {
        $slug   = (string) $c['slug'];
        $propia = 'gp_' . $slug;
        $info   = base_info($pdo, $propia);

        if ($info === null) {
            line("   · {$slug}: SE QUEDA con {$d['db_nombre']} — no existe {$propia}, "
               . "no hay a dónde moverlo.");
            continue;
        }
        line("   · {$slug}: SE MUEVE a {$propia} ({$info['tablas']} tablas) — "
           . "es la base que le corresponde por su slug.");
        $cambios[] = ['id' => (int) $c['id'], 'slug' => $slug, 'destino' => $propia];
    }
    line('');
}

if (!$cambios) {
    line('No hay ningún cliente que se pueda mover: ninguno tiene una base gp_<slug> propia.');
    line('Hay que crearla y migrarla a mano, o corregir el db_nombre sabiendo a dónde va.');
    exit(1);
}

/* QUE NO QUEDE NADIE SIN BASE. Si TODOS los de un conflicto se mueven, esa base
   se queda sin dueño declarado -- y es la que tiene los datos con los que
   alguien viene trabajando. Es exactamente el error que este script existe
   para no cometer. */
foreach ($dup as $d) {
    $delConflicto = 0;
    $q = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE db_nombre = ? AND estado = 'activo'");
    $q->execute([$d['db_nombre']]);
    $total = (int) $q->fetchColumn();
    foreach ($cambios as $cm) {
        $q2 = $pdo->prepare("SELECT db_nombre FROM clientes WHERE id = ?");
        $q2->execute([$cm['id']]);
        if ($q2->fetchColumn() === $d['db_nombre']) { $delConflicto++; }
    }
    if ($delConflicto >= $total) {
        line("FRENO: se moverían TODOS los clientes de {$d['db_nombre']} y esa base quedaría");
        line("sin dueño. Revisalo a mano: alguno tiene que quedarse con los datos que hay ahí.");
        exit(1);
    }
}

if (!$aplicar) {
    line('Para aplicarlo:  php scripts/arreglar-bases-compartidas.php --aplicar');
    exit(0);
}

$pdo->beginTransaction();
try {
    $up = $pdo->prepare('UPDATE clientes SET db_nombre = ? WHERE id = ?');
    foreach ($cambios as $cm) {
        $up->execute([$cm['destino'], $cm['id']]);
        line("OK  {$cm['slug']} -> {$cm['destino']}");
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'No se aplicó nada: ' . $e->getMessage() . "\n");
    exit(1);
}

line('');
line('Listo. Lo que sigue, en este orden:');
line('  1. provisionar.php (corre solo cada minuto) le recrea el bot al cliente movido,');
line('     porque su env cambió de base.');
line('  2. Probá que el cliente entre a SU CRM y vea SUS jugadores, no los nuestros.');
exit(0);
