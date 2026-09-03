<?php
/**
 * t_landings.php — prueba funcional de api/landings_lib.php contra SQLite en
 * memoria (mismo espíritu que t_cargas.php: sin MySQL, sin red).
 */
declare(strict_types=1);
require __DIR__ . '/api/landings_lib.php';

$ok = 0; $mal = 0;
function chk(bool $cond, string $msg): void {
    global $ok, $mal;
    if ($cond) { $ok++; echo "  OK    $msg\n"; }
    else       { $mal++; echo "  FALLA $msg\n"; }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE landings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug VARCHAR(24) NOT NULL UNIQUE,
  nombre VARCHAR(80) NOT NULL,
  plantilla VARCHAR(30) NOT NULL DEFAULT 'oro',
  bono_pct INTEGER NOT NULL DEFAULT 0,
  activa INTEGER NOT NULL DEFAULT 1,
  config TEXT NULL,
  creada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// --- plantillas ---
$pl = landings_plantillas();
chk(isset($pl['oro'], $pl['neon'], $pl['fuego']), 'hay 3 plantillas por default');
foreach ($pl as $clave => $p) {
    chk(isset($p['colores']['fondo'], $p['colores']['acento'], $p['colores']['destacado'], $p['colores']['texto']),
        "plantilla $clave: 4 colores completos");
    chk(count($p['textos']) === 7, "plantilla $clave: 7 textos");
}

foreach ($pl as $clave => $p) {
    chk(($p['tamanos']['cifra'] ?? '') === '100' && ($p['tamanos']['boton'] ?? '') === '100',
        "plantilla $clave: tamanos por default (100%)");
}

// --- merge de config ---
$c = landings_config_completa('oro', json_encode(['tamanos' => ['cifra' => '150']]));
chk($c['tamanos']['cifra'] === '150' && $c['tamanos']['boton'] === '100',
    'tamanos: el guardado pisa, lo no tocado queda en 100');
$c = landings_config_completa('oro', json_encode(['textos' => ['marca' => 'X']]));
chk(($c['tamanos']['cifra'] ?? '') === '100',
    'config vieja SIN tamanos: caen los defaults (sliders y preview no rompen)');

$c = landings_config_completa('neon', json_encode(['textos' => ['marca' => 'Casino X']]));
chk($c['textos']['marca'] === 'Casino X', 'config propia pisa el default');
chk($c['textos']['cta'] === 'Jugar ahora', 'lo no tocado conserva el default de la plantilla');
chk($c['colores']['acento'] === $pl['neon']['colores']['acento'], 'colores salen de la plantilla elegida');
$c = landings_config_completa('inexistente', null);
chk($c['colores']['acento'] === $pl['oro']['colores']['acento'], 'plantilla desconocida cae en oro');
$c = landings_config_completa('oro', '{basura no json');
chk($c['textos']['marca'] === 'Tu Casino', 'config corrupta no rompe: quedan los defaults');

// --- slug ---
chk(landings_slug_nuevo($pdo, 'Promoción Octubre 40%') === 'promocion-octubre-40', 'slug: minusculas, sin acentos ni simbolos');
chk(strlen(landings_slug_nuevo($pdo, str_repeat('a', 60))) <= 24, 'slug: nunca pasa de 24 (limite de altas.origen)');
// El corte a 20 puede caer despues de un guion: no se emite NUNCA un slug
// con guion final (lp.html normaliza los links y ese guion se perderia).
chk(landings_slug_nuevo($pdo, 'Bono Verano Cordoba 2026') === 'bono-verano-cordoba', 'slug: el corte a 20 no deja guion final');

// --- crear ---
$r1 = landings_guardar($pdo, null, 'Promo Octubre', 'oro', 50, ['textos' => ['marca' => 'Mi Casino']]);
chk($r1 !== null && $r1['slug'] === 'promo-octubre', 'crear: devuelve id y slug del nombre');
$r2 = landings_guardar($pdo, null, 'Promo Octubre', 'neon', 30, []);
chk($r2 !== null && $r2['slug'] === 'promo-octubre-2', 'crear: mismo nombre -> sufijo, sin chocar el UNIQUE');
chk(landings_guardar($pdo, null, '   ', 'oro', 10, []) === null, 'crear: sin nombre no guarda');

// --- clamps ---
$r3 = landings_guardar($pdo, null, 'Bono loco', 'fuego', 999, []);
$f3 = landings_por_slug($pdo, $r3['slug']);
chk((int)$f3['bono_pct'] === 200, 'bono se recorta a 200 como maximo');
$r4 = landings_guardar($pdo, null, 'Bono negativo', 'zzz', -5, []);
$f4 = landings_por_slug($pdo, $r4['slug']);
chk((int)$f4['bono_pct'] === 0 && $f4['plantilla'] === 'oro', 'bono negativo -> 0; plantilla desconocida -> oro');

// --- leer ---
$f = landings_por_slug($pdo, 'promo-octubre');
chk($f !== null && (int)$f['bono_pct'] === 50, 'por_slug: encuentra la activa con su bono');
chk(landings_por_slug($pdo, 'no-existe') === null, 'por_slug: inexistente -> null');
chk(count(landings_listar($pdo)) === 4, 'listar: devuelve todas');

// --- editar: slug inmutable ---
$re = landings_guardar($pdo, (int)$r1['id'], 'Promo Noviembre', 'fuego', 60, []);
chk($re['slug'] === 'promo-octubre', 'editar: el slug NO cambia aunque cambie el nombre');
$f = landings_por_slug($pdo, 'promo-octubre');
chk($f['nombre'] === 'Promo Noviembre' && (int)$f['bono_pct'] === 60, 'editar: nombre y bono si cambian');

// --- pausar: el 404 publico y la promesa que sigue ---
chk(landings_toggle($pdo, (int)$r1['id']) === false, 'toggle: pausa');
chk(landings_por_slug($pdo, 'promo-octubre') === null, 'pausada: la lectura publica (solo activas) da null');
$f = landings_por_slug($pdo, 'promo-octubre', false);
chk($f !== null && (int)$f['bono_pct'] === 60, 'pausada: recargas_lib (soloActiva=false) SIGUE viendo el bono prometido');
chk(landings_toggle($pdo, (int)$r1['id']) === true, 'toggle: reactiva');
chk(landings_toggle($pdo, 99999) === null, 'toggle: id inexistente -> null');

// --- el regex de origen que usa crear_cuenta.php acepta lo que genera el slug ---
foreach (['promo-octubre', 'promo-octubre-2'] as $s) {
    chk((bool)preg_match('/^lp:([a-z0-9-]{1,24})$/', 'lp:' . $s), "regex de crear_cuenta acepta 'lp:$s'");
    chk(strlen('lp:' . $s) <= 32, "'lp:$s' entra en altas.origen VARCHAR(32)");
}

// --- el regex de imagenes de crm_landings.php (copiado literal) acepta la URL que devuelve la subida ---
$reImg = '#^/[a-z0-9/._-]*uploads/landings/[a-z0-9._-]+$#i';
chk((bool)preg_match($reImg, '/api/uploads/landings/20260903_ab12cd34ef56.png'), 'regex imagenes: acepta /api/uploads/landings/...');
chk(!preg_match($reImg, 'https://otro.com/x.png'), 'regex imagenes: rechaza URL externa');
chk(!preg_match($reImg, '/api/uploads/../config.local.php'), 'regex imagenes: rechaza path traversal');

echo "\n---------------------------------------\n$ok OK, $mal fallas\n";
exit($mal ? 1 : 0);
