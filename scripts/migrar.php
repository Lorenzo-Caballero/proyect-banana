<?php
/**
 * migrar.php — Aplica las migraciones de `api/sql/` a la base de un cliente.
 *
 * POR QUÉ EXISTE (16/09/2026). Hasta hoy no había ninguna forma cómoda de
 * correr una migración en el VPS. La receta que circulaba era
 *
 *     mysql -u USUARIO -p BASE < api/sql/69_....sql
 *
 * con dos huecos que hay que completar a mano y una contraseña para tipear —
 * y `api/config.local.php` ya tiene las tres cosas. Peor: al fallar por un
 * usuario inventado, el error se parece a "la migración falló" cuando en
 * realidad nunca se intentó.
 *
 * Acá la base se resuelve por el MISMO camino que la API (`api/db.php` la saca
 * del dominio contra `goldpaw_control.clientes`), así que no hay forma de
 * aplicarle una migración a una base distinta de la que ve el CRM.
 *
 * SON IDEMPOTENTES. Todas usan `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF
 * NOT EXISTS`, así que correrlas de nuevo no hace nada. Por eso sin argumentos
 * las aplica TODAS en orden: es la forma segura de poner una base al día sin
 * tener que saber cuál falta.
 *
 *   php /opt/goldpaw/scripts/migrar.php                     todas, al dominio de siempre
 *   php /opt/goldpaw/scripts/migrar.php 69                  solo la 69
 *   php /opt/goldpaw/scripts/migrar.php otrocliente.com 69  otra base
 *   php /opt/goldpaw/scripts/migrar.php --ver               qué haría, sin tocar nada
 */

$args = array_slice($argv, 1);
$seco = false;
foreach ($args as $i => $a) {
    if ($a === '--ver' || $a === '--dry-run') { $seco = true; unset($args[$i]); }
}
$args = array_values($args);

/* El primer argumento es el dominio SOLO si parece uno. Así `migrar.php 69`
   funciona sin tener que escribir el dominio, que es el caso de siempre. */
$dominio = 'ganamoscrm.online';
$soloN   = '';
if (isset($args[0]) && str_contains($args[0], '.')) {
    $dominio = $args[0];
    $soloN   = (string)($args[1] ?? '');
} elseif (isset($args[0])) {
    $soloN = (string)$args[0];
}

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';

/* Se corren las del REPO, no las de /var/www: si alguien acaba de hacer
   `git pull` y todavía no desplegó, lo que quiere aplicar es lo que bajó. */
$SQL_DIR = is_dir(__DIR__ . '/../api/sql') ? __DIR__ . '/../api/sql' : $API . '/sql';

/* Las dos primeras hablan de la tabla `jugadores`, que la migración 07 borró.
   Correrlas sobre una base actual la ensucia con un esquema muerto. */
const LEGACY = ['01_migracion.sql', '02_recargas.sql'];

$base = $GLOBALS['TENANT_DB'] ?? '(la del config)';
echo "\nMigraciones — " . $dominio . "  →  base " . $base . "\n";
echo "Desde " . realpath($SQL_DIR) . "\n";
if ($seco) { echo "\033[1mMODO VER: no se escribe nada.\033[0m\n"; }

$files = glob(rtrim($SQL_DIR, '/') . '/*.sql');
if (!$files) { fwrite(STDERR, "No encontré ninguna migración en $SQL_DIR\n"); exit(1); }
natsort($files);   // por el prefijo numérico, no alfabético crudo

if ($soloN !== '') {
    $n = str_pad(preg_replace('/\D/', '', $soloN), 2, '0', STR_PAD_LEFT);
    $files = array_filter($files, static fn($f) => str_starts_with(basename($f), $n . '_'));
    if (!$files) {
        fwrite(STDERR, "No hay ninguna migración que empiece con '{$n}_'.\n");
        exit(1);
    }
}

echo str_repeat('-', 72) . "\n";

$okN = 0; $nada = 0; $fallas = [];
foreach ($files as $f) {
    $nombre = basename($f);
    if (in_array($nombre, LEGACY, true)) {
        printf("  %-34s (legacy, se saltea)\n", $nombre);
        continue;
    }

    /* Se parte en sentencias en PHP y no se llama a `mysql`: el cliente de
       línea de comandos puede no estar (en este VPS se llama `mariadb`), y
       además pedía usuario y contraseña que acá ya tenemos resueltos. Los
       comentarios `--` se sacan primero para que un `;` adentro de uno no
       parta una sentencia al medio. */
    $sql = (string)file_get_contents($f);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sentencias = array_values(array_filter(array_map('trim', explode(';', $sql))));

    if ($seco) {
        printf("  %-34s %d sentencia(s)\n", $nombre, count($sentencias));
        continue;
    }

    $hechas = 0; $err = [];
    foreach ($sentencias as $st) {
        try {
            $pdo->exec($st);
            $hechas++;
        } catch (Throwable $e) {
            $err[] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 110);
        }
    }

    if (!$err) {
        $okN++;
        printf("  \033[32mok\033[0m  %-32s %d sentencia(s)\n", $nombre, $hechas);
    } else {
        $fallas[$nombre] = $err;
        printf("  \033[31m!!\033[0m  %-32s %d ok, %d con error\n", $nombre, $hechas, count($err));
        foreach (array_slice($err, 0, 3) as $e) { echo "        └─ " . $e . "\n"; }
    }
}

echo str_repeat('-', 72) . "\n";
if ($seco) { echo "Nada aplicado (modo ver).\n\n"; exit(0); }

printf("%d migración(es) aplicadas sin error.\n", $okN);
if ($fallas) {
    printf("\033[1m%d con errores.\033[0m\n", count($fallas));
    echo "\nUN ERROR NO SIEMPRE ES UN PROBLEMA: una migración vieja puede\n";
    echo "quejarse de algo que ya está hecho (un índice duplicado, una columna\n";
    echo "que existe). Lo que importa es que la que acabás de agregar diga 'ok'.\n";
    exit(1);
}
echo "\n";
