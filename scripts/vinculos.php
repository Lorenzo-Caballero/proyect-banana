<?php
/**
 * vinculos.php — ¿Qué cuentas de la base son la misma persona?
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026): *"descubrí que holasofito763,
 * holajuan969 y holaleiva89 son la misma persona"*. Lo descubrió a mano. Esto
 * hace la misma pregunta sobre toda la base, y sirve para dos cosas: barrer lo
 * que ya pasó, y comprobar que el aviso del CRM detecta el caso que motivó
 * todo esto.
 *
 * LAS SEÑALES NO VALEN LO MISMO (ver api/vinculos_lib.php):
 *   cuenta bancaria  fuerte    la plata salió de la MISMA cuenta
 *   celular          fuerte    pero un teléfono se presta
 *   IP del alta      DÉBIL     medio barrio comparte el NAT de la telefónica
 *
 * Por eso los grupos salen ordenados por la señal más fuerte que los une, y
 * los que solo comparten IP van al final y aparte. **Nada se bloquea solo.**
 *
 * SOLO LEE. Bloquear es una decisión de una persona, y se hace desde el CRM.
 *
 *   php /opt/goldpaw/scripts/vinculos.php
 *   php /opt/goldpaw/scripts/vinculos.php ganamoscrm.online holasofito763
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$uno     = trim((string)($argv[2] ?? ''));

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';
require_once $API . '/vinculos_lib.php';

function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 76) . "\n"; }

/* La migración 69 se chequea ANTES de nada: sin ella el script correría y
   diría "no hay vínculos", que es una respuesta tranquilizadora y falsa. */
try { $pdo->query("SELECT bloqueado FROM usuarios LIMIT 0"); }
catch (Throwable $e) {
    fwrite(STDERR, "\nFalta correr la migración 69 en esta base.\n"
                 . "Sin ella este script diría 'no hay vínculos', que no es lo mismo\n"
                 . "que 'no los busqué'.\n\n");
    exit(1);
}

echo "\nCuentas que parecen la misma persona — " . $dominio . "\n";

// ===========================================================================
if ($uno !== '') {
    titulo('Vínculos de @' . $uno);
    $v = vin_relacionados($pdo, $uno);
    if (!$v) {
        echo "  Ninguno. Ni cuenta bancaria, ni celular, ni IP en común.\n";
    } else {
        foreach ($v as $x) {
            printf("  %s @%-24s %s%s\n",
                   $x['fuerza'] >= 3 ? "\033[1m●\033[0m" : '○',
                   $x['usuario'], $x['detalle'],
                   $x['bloqueado'] ? '   [BLOQUEADO]' : '');
        }
        echo "\n  ● = señal fuerte (misma cuenta bancaria).  ○ = indicio.\n";
    }
    echo "\n";
    exit(0);
}

// ===========================================================================
titulo('1. Misma cuenta bancaria (la señal fuerte)');
/* Se agrupa por el identificador bancario, no por jugador: así el grupo sale
   entero de una y no repetido desde cada miembro. Los vacíos quedan afuera --
   '' = '' ataría entre sí a todos los que nunca informaron CUIT. */
$st = $pdo->query(
    "SELECT ident, GROUP_CONCAT(usuario ORDER BY usuario SEPARATOR ' ') cuentas,
            COUNT(*) n, MAX(nombre) titular
       FROM (
         SELECT IF(cuit <> '', CONCAT('CUIT ', cuit), CONCAT('CBU ', cbu)) ident,
                usuario, nombre
           FROM huellas_pagador
          WHERE cuit <> '' OR cbu <> ''
       ) x
      GROUP BY ident
     HAVING COUNT(DISTINCT usuario) > 1
      ORDER BY n DESC"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  \033[1m%d cuentas\033[0m — %s%s\n", (int)$f['n'], $f['ident'],
           trim((string)$f['titular']) !== '' ? '  (' . $f['titular'] . ')' : '');
    printf("      %s\n", $f['cuentas']);
}
if (!$hay) { echo "  Ninguna. Nadie pagó por dos cuentas desde el mismo banco.\n"; }

// ===========================================================================
titulo('2. Mismo celular');
/* Empieza vacío a propósito: `dispositivos_usuarios` se llena de acá en
   adelante (la tabla vieja pisaba el usuario y no guardaba historial). Un cero
   acá el primer día no significa que no haya: significa que todavía no se
   registró ninguno. */
$st = $pdo->query(
    "SELECT device_id, GROUP_CONCAT(usuario ORDER BY usuario SEPARATOR ' ') cuentas,
            COUNT(*) n
       FROM dispositivos_usuarios
      GROUP BY device_id
     HAVING COUNT(DISTINCT usuario) > 1
      ORDER BY n DESC LIMIT 30"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  \033[1m%d cuentas\033[0m — celular %s\n", (int)$f['n'],
           substr((string)$f['device_id'], 0, 12) . '…');
    printf("      %s\n", $f['cuentas']);
}
if (!$hay) {
    echo "  Ninguno todavía. Esta señal se empieza a juntar desde que se\n";
    echo "  despliega la migración 69: antes no se guardaba el historial.\n";
}

// ===========================================================================
titulo('3. Misma IP al registrarse (INDICIO DÉBIL)');
/* Va último y con la advertencia arriba porque es el que más fácil se
   malinterpreta. Un locutorio, una familia, o directamente el NAT de la
   telefónica juntan a desconocidos. Se acota a altas de la misma semana. */
$st = $pdo->query(
    "SELECT a.ip, GROUP_CONCAT(DISTINCT a.usuario ORDER BY a.usuario SEPARATOR ' ') cuentas,
            COUNT(DISTINCT a.usuario) n
       FROM altas a
      WHERE a.ip IS NOT NULL AND a.ip <> ''
        AND a.ip NOT IN ('127.0.0.1', '::1', 'localhost')
        AND a.pedido_en >= NOW() - INTERVAL 60 DAY
      GROUP BY a.ip
     HAVING COUNT(DISTINCT a.usuario) > 2
      ORDER BY n DESC LIMIT 20"
);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    printf("  %d cuentas — IP %s\n", (int)$f['n'], $f['ip']);
    printf("      %s\n", $f['cuentas']);
}
if (!$hay) {
    echo "  Ninguna IP con más de dos altas en 60 días.\n";
} else {
    echo "\n  \033[1mNo alcanza para bloquear a nadie.\033[0m Sirve para mirar, cruzado con\n";
    echo "  las otras dos señales. Por eso el alta nueva NUNCA se frena por IP.\n";
}

echo "\n";
