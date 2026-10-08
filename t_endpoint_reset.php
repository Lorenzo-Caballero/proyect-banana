<?php
/**
 * t_endpoint_reset.php -- Cambiar de dominio tiene que MOVER las altas.
 *
 * EL BUG QUE CUIDA, y que confundio dos dias (18/09/2026): el fast-path
 * aprende el endpoint de alta UNA vez y lo guarda con la URL ABSOLUTA en
 * alta_endpoint.json; despues lo usa tal cual. Cambiar PANEL_URL/LOGIN_URL
 * mueve SOLO el login -- las altas siguen pegandole al dominio horneado, sin
 * un solo error a la vista. Parecio que el bot "se movio a ganamos7" cuando
 * en realidad las altas nunca salieron de ganamosonline.
 *
 * resetear_endpoint_horneado() (panel/provisionar.php) borra ese archivo
 * cuando el dominio cambia, para que el bot lo re-aprenda contra el nuevo.
 * El mismo arreglo, para NUESTRO bot, vive en scripts/arreglar-bot-altas.sh
 * (bloque 2.b).
 *
 *     php t_endpoint_reset.php
 */
$src = file_get_contents('panel/provisionar.php');
preg_match('/function resetear_endpoint_horneado.*?\n\}/s', $src, $m);
eval($m[0]);

$ok = 0; $fail = 0;
function chk($q,$c){global $ok,$fail; if($c){$ok++;echo "  OK    $q\n";}else{$fail++;echo "  FALLA $q\n";}}

$dir = sys_get_temp_dir() . '/gp_test_' . uniqid();
mkdir($dir, 0777, true);

// Caso 1: endpoint en ganamosonline, dominio nuevo ganamos7 -> debe resetear
file_put_contents("$dir/alta_endpoint.json", '{"url":"https://agents.ganamosonline.com/api/agent_admin/user/"}');
file_put_contents("$dir/estado_sesion.json", '{}');
resetear_endpoint_horneado($dir, 'https://agents.ganamos7.com/user/create-player', 'test');
chk('resetea el endpoint de otro dominio', !is_file("$dir/alta_endpoint.json"));
chk('deja un backup del endpoint', count(glob("$dir/alta_endpoint.json.bak.*")) === 1);
chk('resetea tambien la sesion vieja', !is_file("$dir/estado_sesion.json"));

// Caso 2: endpoint YA en ganamos7 -> NO toca nada
file_put_contents("$dir/alta_endpoint.json", '{"url":"https://agents.ganamos7.com/api/agent_admin/user/"}');
resetear_endpoint_horneado($dir, 'https://agents.ganamos7.com/user/create-player', 'test');
chk('NO toca un endpoint que ya esta en el dominio nuevo', is_file("$dir/alta_endpoint.json"));

// Caso 3: sin archivo -> no explota
$dir2 = sys_get_temp_dir() . '/gp_test2_' . uniqid(); mkdir($dir2,0777,true);
resetear_endpoint_horneado($dir2, 'https://agents.ganamos7.com/user/create-player', 'test');
chk('sin endpoint horneado no hace nada (el bot lo aprende solo)', !is_file("$dir2/alta_endpoint.json"));

// Caso 4: JSON corrupto -> no explota, no borra (host viejo vacio)
file_put_contents("$dir/alta_endpoint.json", 'no soy json');
resetear_endpoint_horneado($dir, 'https://agents.ganamos7.com/user/create-player', 'test');
chk('un JSON ilegible no se borra a ciegas', is_file("$dir/alta_endpoint.json"));

echo "\n$ok OK, $fail fallas\n";
exit($fail ? 1 : 0);
