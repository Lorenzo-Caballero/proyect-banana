<?php
/**
 * t_bono_no_retirable.php — El bono entra al juego pero no sale por caja.
 *
 * EL CASO (Nahuel, 19/09/2026): *"muchos usuarios hacen la jugada de cargar 16
 * mil, que se le acredite un bono de 8 mil, de ese modo tienen 24, y luego de
 * haber jugado una o dos tiradas ya quieren retirar"*.
 *
 * NO ERA HIPOTÉTICO. Medido ese día en producción:
 *     holalujanomero706   bono 1.000  ->  retiró 4.000 a los  9 minutos
 *     nicolasadi          bono 1.000  ->  retiró 1.000 a los  3 minutos
 *
 * POR QUÉ LA REGLA VIEJA NO SERVÍA. El bot decía "los bonos no se retiran" y
 * `fichas_pedir_retiro()` sólo miraba `usuarios.balance`, nunca
 * `usuarios.bonus`. Las dos cosas eran ciertas y ninguna alcanzaba: al
 * acreditarse, el bono se DEPOSITA EN EL JUEGO junto con las fichas y desde ahí
 * es saldo común. La regla era verdadera sobre el contador y falsa sobre la
 * plata, que es lo único que importa.
 *
 * Lo que se prueba acá es que ahora el límite es real, y --sobre todo-- que es
 * JUSTO: el que juega y pierde no queda con una deuda fantasma bloqueándole
 * plata propia.
 *
 *     T_PORT=3399 php t_bono_no_retirable.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/fichas_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

const U = 't_bnr_jugador';
$limpiar = function () use ($pdo): void {
    foreach (['acciones_saldo' => 'usuario', 'movimientos' => 'usuario',
              'usuarios' => 'username'] as $t => $c) {
        try { $pdo->prepare("DELETE FROM $t WHERE $c = ?")->execute([U]); } catch (Throwable $e) {}
    }
};
/** Deja al jugador con un saldo y un bono en juego concretos. */
$poner = function (float $saldo, float $bonoEnJuego) use ($pdo, $limpiar): void {
    $limpiar();
    $pdo->prepare(
        "INSERT INTO usuarios (id, username, balance, coins, bonus, bono_en_juego, saldo_visto_en)
         VALUES (991500, ?, ?, 0, 0, ?, NOW())"
    )->execute([U, $saldo, $bonoEnJuego]);
};

/* Sin horario ni topes: acá se prueba el bono, no los límites -- y un test que
   depende del reloj empieza a fallar a las 3 de la mañana por otra razón. */
cfg_crm_guardar($pdo, ['lim_retiro_hora_desde' => '', 'lim_retiro_hora_hasta' => '',
                       'lim_retiro_min' => '100', 'lim_retiro_max' => '0',
                       'lim_retiro_max_dia' => '0', 'lim_retiro_cant_dia' => '0'], 'test');

/* =========================================================================
   1. EL CASO DE NAHUEL, TAL CUAL
   ========================================================================= */
echo "\n=== 1. Cargó 16.000, le dieron 8.000 de bono, quiere sacar 24.000 ===\n";
$poner(24000, 8000);

$lim = fichas_retirable($pdo, U);
chequear('el saldo sigue siendo 24.000 (el jugador lo ve en el juego)',
         (int)$lim['saldo'] === 24000, json_encode($lim));
chequear('pero retirable son 16.000: lo suyo', (int)$lim['retirable'] === 16000, json_encode($lim));

$r = fichas_pedir_retiro($pdo, U, 24000);
chequear('pedir los 24.000 se rechaza', empty($r['ok']), json_encode($r));
chequear('y el motivo es saldo insuficiente', ($r['codigo'] ?? '') === 'sin_saldo', json_encode($r));
/* EL MENSAJE TIENE QUE EXPLICAR LA DIFERENCIA. El jugador ve 24.000 en la
   pantalla del juego: decirle "tu saldo es de 16.000" y nada mas es pedirle que
   crea que le mentimos. */
chequear('y le explica de donde sale la diferencia',
         str_contains($r['error'] ?? '', '24.000') && str_contains($r['error'] ?? '', '8.000')
         && str_contains($r['error'] ?? '', 'no se retiran'),
         $r['error'] ?? '');

$r = fichas_pedir_retiro($pdo, U, 16000);
chequear('pedir los 16.000 SÍ sale', !empty($r['ok']), json_encode($r));

/* =========================================================================
   2. "RETIRAR TODO" NO PUEDE LLEVARSE EL BONO
   ========================================================================= */
echo "\n=== 2. El atajo de \"sacá todo\" ===\n";
$poner(24000, 8000);
$r = fichas_pedir_retiro($pdo, U, 0, 'chatbot', true);   // todo=true
chequear('"todo" son 16.000, no 24.000',
         !empty($r['ok']) && (int)($r['monto'] ?? 0) === 16000, json_encode($r));

/* =========================================================================
   3. LO QUE LO HACE JUSTO: si jugó y perdió, el bono se perdió con él
   ========================================================================= */
echo "\n=== 3. Jugó y perdió: no queda una deuda fantasma ===\n";
$poner(5000, 8000);          // tenía 24.000 con 8.000 de bono; jugó y quedó en 5.000
$lim = fichas_retirable($pdo, U);
chequear('el bono en juego se recorta al saldo (8.000 -> 5.000)',
         (int)$lim['bono_en_juego'] === 5000, json_encode($lim));
chequear('pero eso deja retirable en 0, que es correcto: lo que queda ES el bono',
         (int)$lim['retirable'] === 0, json_encode($lim));

/* Y el caso que importa de verdad: perdió TODO el bono y parte de lo suyo. */
$poner(3000, 8000);
$pdo->prepare("UPDATE usuarios SET bono_en_juego = 0 WHERE username = ?")->execute([U]);
$lim = fichas_retirable($pdo, U);
chequear('sin bono pendiente, todo el saldo es retirable',
         (int)$lim['retirable'] === 3000, json_encode($lim));

/* =========================================================================
   4. GANÓ: lo que gana POR ENCIMA del bono sí es suyo
   ========================================================================= */
echo "\n=== 4. Jugó y ganó ===\n";
$poner(30000, 8000);
$lim = fichas_retirable($pdo, U);
chequear('con 30.000 y 8.000 de bono puede sacar 22.000',
         (int)$lim['retirable'] === 22000, json_encode($lim));

/* =========================================================================
   5. SIN BONO NADA CAMBIA (que es la mitad de un cambio bien hecho)
   ========================================================================= */
echo "\n=== 5. El jugador sin bono no nota nada ===\n";
$poner(10000, 0);
$lim = fichas_retirable($pdo, U);
chequear('retirable = saldo', (int)$lim['retirable'] === 10000, json_encode($lim));
$r = fichas_pedir_retiro($pdo, U, 10000);
chequear('y puede sacar todo', !empty($r['ok']), json_encode($r));

/* =========================================================================
   6. LO QUE SE LE DICE AL JUGADOR
   ========================================================================= */
echo "\n=== 6. Consultar saldo informa las dos cosas ===\n";
$poner(24000, 8000);
$c = fichas_consultar($pdo, U);
chequear('dice el saldo que ve en el juego', (int)($c['saldo'] ?? 0) === 24000, json_encode($c));
chequear('y cuánto puede sacar', (int)($c['retirable'] ?? -1) === 16000, json_encode($c));
chequear('y cuánto es bono sin jugar', (int)($c['bono_en_juego'] ?? -1) === 8000, json_encode($c));

$limpiar();
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
