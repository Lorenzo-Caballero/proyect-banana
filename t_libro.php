<?php
/**
 * t_libro.php — el libro de operaciones ejecutadas del panel.
 *
 * Cubre las dos cosas que pueden salir mal en `api/operaciones_panel.php`, que
 * son las dos caras de la misma moneda: plata mal contada.
 *
 *  1. LA FECHA. El panel manda "2026-09-14 01:33" y por `cuando` filtran
 *     Finanzas y Auditoría. Una fecha mal parseada mete un retiro en el mes
 *     equivocado; una fecha inventada (NOW() ante la duda) hace lo mismo pero
 *     sin dejar rastro. Por eso ante algo ilegible se guarda NULL: una fila sin
 *     fecha no aparece en ningún rango y se nota, que es lo que se quiere.
 *
 *  2. LA IDEMPOTENCIA. El worker reenvía la ventana ENTERA cada 15 minutos, no
 *     solo lo nuevo — a propósito, para que una pasada perdida se recupere sola
 *     sin estado que mantener. El precio es que las mismas filas llegan una y
 *     otra vez, y acá se suma plata: una fila duplicada es un retiro contado
 *     dos veces en Finanzas.
 *
 *     php t_libro.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* `operaciones_panel.php` es un endpoint: al requerirlo exige la API key y
   contesta HTTP. Se le saca la función de fechas, que es autocontenida y es
   donde está el riesgo. El resto —el upsert— se prueba con la misma SQL. */
$src = file_get_contents(__DIR__ . '/api/operaciones_panel.php');
if (!preg_match('/function op_fecha\(\?string \$crudo\): \?string\s*\{.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "No pude extraer op_fecha() de operaciones_panel.php\n");
    exit(1);
}
eval($m[0]);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$limpiar = fn() => $pdo->exec(
    "DELETE FROM operaciones_panel WHERE payment_id BETWEEN 960000 AND 960999");
$limpiar();

// ===========================================================================
echo "\n=== 1. La fecha que manda el panel ===\n";
chequear('el formato real del panel',
         op_fecha('2026-09-14 01:33') === '2026-09-14 01:33:00',
         (string)op_fecha('2026-09-14 01:33'));
chequear('con segundos tambien',
         op_fecha('2026-09-14 01:33:07') === '2026-09-14 01:33:07');
chequear('formato ISO con T',
         op_fecha('2026-09-14T01:33:07') === '2026-09-14 01:33:07');

echo "\n=== 2. Lo que NO se entiende da NULL, no una fecha inventada ===\n";
/* Es la decisión que evita el peor error posible acá: meterle a un retiro la
   fecha de hoy porque no se pudo leer la suya. Eso le cambia el número a dos
   meses —al que pierde la operación y al que la recibe— y no deja rastro. */
chequear('vacio',        op_fecha('') === null);
chequear('null',         op_fecha(null) === null);
chequear('basura',       op_fecha('ayer a la tarde') === null);
chequear('solo la fecha', op_fecha('2026-09-14') === null);
chequear('mes 13',       op_fecha('2026-13-01 10:00') === null, (string)op_fecha('2026-13-01 10:00'));
chequear('dia 32',       op_fecha('2026-09-32 10:00') === null, (string)op_fecha('2026-09-32 10:00'));
chequear('hora 25',      op_fecha('2026-09-14 25:00') === null, (string)op_fecha('2026-09-14 25:00'));

// ===========================================================================
echo "\n=== 3. La misma operacion diez veces sigue siendo una ===\n";
/* El worker reenvía la ventana entera cada vez. Sin la PK y el upsert, cada
   pasada sumaría el retiro de nuevo: en 15 minutos Finanzas mostraría el
   cuádruple de plata saliendo. */
$guardar = function (int $id, int $tipo, string $user, float $monto, ?string $cuando) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO operaciones_panel
                (payment_id, tipo, username, monto, titular, destino,
                 comentario, creada_api, cuando)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
                username = VALUES(username), monto = VALUES(monto),
                titular = VALUES(titular), destino = VALUES(destino),
                comentario = VALUES(comentario), creada_api = VALUES(creada_api),
                cuando = VALUES(cuando)"
    )->execute([$id, $tipo, $user, $monto, null, null, 'direct withdrawal',
                (string)$cuando, $cuando]);
};

for ($i = 0; $i < 10; $i++) {
    $guardar(960001, 1, 't_lib_uno', 5000.0, '2026-09-14 01:33:00');
}
$n = (int)$pdo->query("SELECT COUNT(*) FROM operaciones_panel WHERE payment_id=960001")->fetchColumn();
chequear('una sola fila despues de 10 pasadas', $n === 1, "filas=$n");
$suma = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM operaciones_panel WHERE payment_id=960001")->fetchColumn();
chequear('y la plata no se multiplico', abs($suma - 5000.0) < 0.01, "suma=$suma");

echo "\n=== 4. Si el panel corrige un monto, gana el ultimo ===\n";
/* No acumula: la operación es la misma, el dato nuevo reemplaza al viejo. */
$guardar(960001, 1, 't_lib_uno', 5500.0, '2026-09-14 01:33:00');
$suma = (float)$pdo->query("SELECT monto FROM operaciones_panel WHERE payment_id=960001")->fetchColumn();
chequear('queda el valor corregido', abs($suma - 5500.0) < 0.01, "monto=$suma");

echo "\n=== 5. Una fila sin fecha no ensucia ningun periodo ===\n";
/* Es la contracara del punto 2: se guarda para no perder la operación, pero
   con `cuando` NULL no la levanta ninguna consulta por rango. */
$guardar(960002, 1, 't_lib_sinfecha', 99999.0, null);
$enRango = (float)$pdo->query(
    "SELECT COALESCE(SUM(monto),0) FROM operaciones_panel
      WHERE tipo=1 AND cuando >= '2026-01-01' AND cuando < '2027-01-01'
        AND payment_id BETWEEN 960000 AND 960999")->fetchColumn();
chequear('no entra en el rango', abs($enRango - 5500.0) < 0.01, "suma=$enRango");
$existe = (int)$pdo->query("SELECT COUNT(*) FROM operaciones_panel WHERE payment_id=960002")->fetchColumn();
chequear('pero la fila esta, no se perdio', $existe === 1);

echo "\n=== 6. Depositos y retiros no se mezclan ===\n";
$guardar(960003, 0, 't_lib_dep', 7000.0, '2026-09-14 02:00:00');
$ret = (float)$pdo->query(
    "SELECT COALESCE(SUM(monto),0) FROM operaciones_panel
      WHERE tipo=1 AND payment_id BETWEEN 960000 AND 960999")->fetchColumn();
$dep = (float)$pdo->query(
    "SELECT COALESCE(SUM(monto),0) FROM operaciones_panel
      WHERE tipo=0 AND payment_id BETWEEN 960000 AND 960999")->fetchColumn();
chequear('los retiros suman lo suyo', abs($ret - 105499.0) < 0.01, "ret=$ret");
chequear('los depositos lo suyo',     abs($dep - 7000.0) < 0.01, "dep=$dep");

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
