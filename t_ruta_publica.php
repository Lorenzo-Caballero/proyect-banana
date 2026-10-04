<?php
/** Regresión de la URL editable sin cambiar la identidad interna del cliente. */
$panel = file_get_contents(__DIR__ . '/panel/panel.php');
preg_match('/function ruta_slug_valida\(string \$s\): bool \{.*?\n\}/s', $panel, $m);
if (!$m) { fwrite(STDERR, "No encontré ruta_slug_valida()\n"); exit(1); }
eval($m[0]); // Ejecuta el validador real del panel, no una copia del test.
$checks = [
    'acepta rutas públicas descriptivas' => ruta_slug_valida('leandro-juega'),
    'rechaza la ruta de la plataforma /home' => !ruta_slug_valida('home'),
    'rechaza rutas de páginas internas' => !ruta_slug_valida('crm'),
    'rechaza caracteres fuera del alfabeto de URL' => !ruta_slug_valida('leandro_2'),
    'la migración conserva aliases por dominio y cliente' => str_contains(file_get_contents(__DIR__.'/panel/sql/12_ruta_publica_cliente.sql'), 'PRIMARY KEY (dominio, ruta_slug)')
        && str_contains(file_get_contents(__DIR__.'/panel/sql/12_ruta_publica_cliente.sql'), 'INSERT IGNORE INTO clientes_rutas_path'),
    'la resolución API enlaza ruta pública con cliente interno' => str_contains(file_get_contents(__DIR__.'/api/db.php'), 'JOIN clientes c ON c.id=r.cliente_id')
        && str_contains(file_get_contents(__DIR__.'/api/db.php'), "\$GLOBALS['TENANT_SLUG'] = \$__clientSlug"),
    'el proceso de aprovisionamiento usa la ruta pública sin renombrar sus bots' => str_contains(file_get_contents(__DIR__.'/panel/provisionar.php'), "'bot-' . \$slug")
        && str_contains(file_get_contents(__DIR__.'/panel/provisionar.php'), "\$c['ruta_slug'] ?? \$slug"),
    'la edición toca ruta_slug y no actualiza el slug interno' => str_contains($panel, 'UPDATE clientes SET ruta_slug=? WHERE id=?')
        && !preg_match('/UPDATE clientes SET[^;]*\bslug\s*=/', $panel),
    'los webhooks por ruta resuelven aliases al mismo cliente' => str_contains(file_get_contents(__DIR__.'/api/hg_webhook.php'), 'JOIN clientes c ON c.id=r.cliente_id')
        && str_contains(file_get_contents(__DIR__.'/api/db.php'), 'r.ruta_slug=?'),
    'los links nuevos usan la ruta vigente, no el slug interno' => str_contains(file_get_contents(__DIR__.'/api/hgcash_lib.php'), "TENANT_PUBLIC_SLUG")
        && str_contains(file_get_contents(__DIR__.'/api/referidos_lib.php'), "TENANT_PUBLIC_SLUG")
        && str_contains(file_get_contents(__DIR__.'/api/mail_casillas.php'), "\$c['ruta_slug'] ?? \$c['slug']"),
    'al purgar se liberan las rutas históricas sin borrar la base del cliente' => str_contains($panel, 'DELETE FROM clientes_rutas_path WHERE cliente_id=?')
        && str_contains($panel, "'db_huerfana' => \$cli['db_nombre']"),
];
$fail = 0;
foreach ($checks as $name => $ok) { printf("  %s %s\n", $ok ? 'OK' : 'FALLA', $name); if (!$ok) $fail++; }
printf("%d OK, %d fallas\n", count($checks)-$fail, $fail);
exit($fail ? 1 : 0);
