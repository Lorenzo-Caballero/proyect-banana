<?php
/**
 * t_migraciones.php — Que toda migración se pueda correr dos veces.
 *
 * NO ES UNA PREFERENCIA DE ESTILO: es cómo funciona el runner. `provisionar.php`
 * NO lleva la cuenta de qué migración aplicó en cada cliente. Guarda una HUELLA
 * del contenido de `api/sql/` y, cuando esa huella cambia —o sea, cada vez que
 * se agrega una migración nueva— vuelve a pasar TODOS los archivos, de nuevo,
 * por cada cliente.
 *
 *     $huellaHoy = migraciones_huella();
 *     if (huella_leer($db) === $huellaHoy) { continue; }   // ya esta al dia
 *     $msg = aplicar_migraciones($db);
 *     if (strpos($msg, 'error') === false) { huella_guardar($db, $huellaHoy); }
 *
 * Esa última línea es la trampa. Un `ALTER TABLE ... ADD COLUMN` sin
 * `IF NOT EXISTS` falla con "Duplicate column name" en cuanto la columna ya
 * está — o sea, en la segunda pasada. Eso mete la palabra "error" en el
 * mensaje, la huella NO se guarda, y a partir de ahí **cada cliente vuelve a
 * correr las setenta y pico de migraciones cada minuto, para siempre**.
 *
 * Y NO SE ROMPE NADA VISIBLE, que es lo peor. Las columnas ya existen, el CRM
 * abre, las cargas se aprueban. Lo único que pasa es que el cron quema CPU y el
 * log se llena de errores que nadie lee. Es exactamente el modo de fallar que
 * el proyecto viene tratando de sacarse de encima: no preguntar si algo está
 * vivo, sino si funcionó.
 *
 * PASÓ EL 20/09/2026. Las migraciones 75 y 76 —escritas esa misma semana— se
 * saltearon el `IF NOT EXISTS` que usan las otras 43. Estaban dormidas: como no
 * se había agregado ninguna migración después, la huella no cambiaba y nadie
 * las re-corría. Se despertaron al escribir la 77 (Firebase), que es lo que hizo
 * mirar el runner.
 *
 *     php t_migraciones.php
 */
declare(strict_types=1);

$dir = __DIR__ . '/api/sql';

/* Se lee de provisionar.php en vez de repetir la lista: si alguien suma un
   archivo a MIGRACIONES_LEGACY, el test tiene que enterarse solo. */
$legacy = [];
$srcProv = (string)@file_get_contents(__DIR__ . '/panel/provisionar.php');
if (preg_match('/MIGRACIONES_LEGACY\s*=\s*\[(.*?)\]/s', $srcProv, $m)) {
    preg_match_all("/'([^']+)'/", $m[1], $mm);
    $legacy = $mm[1];
}

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s\n        %s\n", $q, $d); }
}

echo "\n=== Las migraciones se re-corren enteras: tienen que ser idempotentes ===\n";

chequear('se pudo leer MIGRACIONES_LEGACY de provisionar.php', $legacy !== [],
    'sin eso el test no sabe cuales estan excluidas del runner y daria falsos positivos');

$archivos = glob($dir . '/*.sql') ?: [];
natsort($archivos);
chequear('hay migraciones que revisar', count($archivos) > 0, $dir);

/* Cada patrón es una forma real de romper el runner. `MODIFY` no está: volver
   a aplicar el mismo MODIFY es inofensivo. */
$prohibidos = [
    '/\bADD\s+COLUMN\s+(?!IF\s+NOT\s+EXISTS)/i'        => 'ADD COLUMN sin IF NOT EXISTS',
    '/\bADD\s+(UNIQUE\s+)?KEY\s+(?!IF\s+NOT\s+EXISTS)/i' => 'ADD KEY sin IF NOT EXISTS',
    '/\bADD\s+INDEX\s+(?!IF\s+NOT\s+EXISTS)/i'         => 'ADD INDEX sin IF NOT EXISTS',
    '/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?!IF\s+NOT\s+EXISTS)/im' => 'CREATE INDEX sin IF NOT EXISTS',
    '/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/im'   => 'CREATE TABLE sin IF NOT EXISTS',
];

$rotas = [];
$revisados = 0;
foreach ($archivos as $f) {
    $nombre = basename($f);
    if (in_array($nombre, $legacy, true)) { continue; }   // el runner las saltea
    $revisados++;

    /* Se sacan los comentarios ANTES de buscar: estos archivos explican en
       prosa lo que hacen, y una línea que dice "se agrega la columna X" no es
       una sentencia. Sin esto, el test se vuelve ruido y se deja de mirar. */
    $sql = (string)file_get_contents($f);
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);   // línea de comentario
    $sql = (string)preg_replace('/--.*$/m', '', $sql);       // comentario al final

    foreach ($prohibidos as $re => $porque) {
        if (preg_match($re, $sql, $m, PREG_OFFSET_CAPTURE)) {
            $linea = substr_count(substr($sql, 0, (int)$m[0][1]), "\n") + 1;
            $rotas[] = "$nombre (linea ~$linea): $porque";
        }
    }
}

chequear('se revisaron las migraciones vivas', $revisados > 0, (string)$revisados);
chequear(
    'ninguna migracion falla al correrse dos veces',
    $rotas === [],
    $rotas ? implode("\n        ", $rotas)
        . "\n\n        Cada una de estas deja la huella SIN guardar, y entonces"
        . "\n        TODOS los clientes re-corren TODAS las migraciones cada minuto."
        : ''
);

printf("\n---------------------------------------\n%d OK, %d fallas (%d migraciones revisadas)\n",
    $ok, $fail, $revisados);
exit($fail > 0 ? 1 : 0);
