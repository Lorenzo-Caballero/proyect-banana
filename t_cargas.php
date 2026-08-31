<?php
/**
 * t_cargas.php — Que los archivos de la API CARGUEN de verdad.
 *
 * `php -l` solo mira la sintaxis: no ejecuta nada, asi que NO detecta un
 * "Cannot redeclare function". Ese error tumba el endpoint entero y solo se
 * ve en produccion, con el chat ya muerto.
 *
 * Paso dos veces en chatbot.php: recargas_lib.php carga por su cuenta
 * meta_lib/fichas_lib/publicidad_lib, y un `require` pelado despues de el las
 * cargaba de nuevo. Este chequeo lo agarra antes de desplegar.
 *
 *     php t_cargas.php
 */
declare(strict_types=1);

// Stubs de lo que normalmente traen config.php y db.php.
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
$GLOBALS['pdo'] = null;

$ok = 0; $fail = 0;
// Mismo orden que usa cada endpoint real.
$grupos = [
    'chatbot.php'   => ['recargas_lib.php', 'fichas_lib.php', 'altas_lib.php',
                        'config_crm.php', 'meta_lib.php', 'publicidad_lib.php',
                        'chatbot_contexto.php'],
    'pagos.php'     => ['recargas_lib.php'],
    'crm_comprobantes.php' => ['recargas_lib.php'],
    // Camino A: peticiones_lib trae recargas_lib por su cuenta, asi que el
    // orden importa igual que en chatbot.php.
    'peticiones_cola.php' => ['peticiones_lib.php', 'fichas_lib.php',
                              'actividad_lib.php', 'notificaciones_lib.php'],
];

foreach ($grupos as $endpoint => $libs) {
    foreach ($libs as $lib) {
        $ruta = __DIR__ . '/api/' . $lib;
        if (!is_file($ruta)) { continue; }
        try {
            require_once $ruta;
            $ok++;
        } catch (Throwable $e) {
            $fail++;
            printf("  FALLA %s (via %s): %s\n", $lib, $endpoint, $e->getMessage());
        }
    }
}
printf("  OK    %d librerias cargadas sin redeclare\n", $ok);

// Las funciones que el chat necesita para no quedar a medias.
foreach (['rl_crear_recarga', 'rl_cargar_al_juego_auto', 'fichas_pedir_carga',
          'chatbot_bloque_pago', 'rl_similitud_nombres', 'alta_encolar',
          'pc_elegir_pago', 'pc_es_ambiguo', 'rl_usuarios_por_huella',
          'rl_aprender_huella', 'fichas_limite'] as $f) {
    if (function_exists($f)) { $ok++; }
    else { $fail++; printf("  FALLA falta la funcion %s\n", $f); }
}
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
