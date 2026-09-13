<?php
/**
 * t_telegram_retiro.php — que el aviso de retiro alcance para pagarle.
 *
 * EL PEDIDO (Nahuel, 13/09/2026): "que en el mensaje que llega a Telegram ya me
 * lleguen los datos del jugador... así no tengo que leer todo el chat y buscar
 * el alias". El flujo real es: llega el aviso, le saca las fichas en el panel y
 * le transfiere al alias. Si el alias no está en el mensaje, hay que abrir el
 * CRM y leer la conversación entera.
 *
 * Lo que se fija acá: que el CBU salga como bloque de código (en Telegram se
 * copia de un toque), que el escapado siga funcionando —el texto lleva nombres
 * de jugador, y un "<" suelto hace que Telegram rechace el mensaje ENTERO con
 * 400— y que un destino vacío no se muestre como un bloque vacío sino como el
 * aviso de que falta pedirlo.
 *
 *     php t_telegram_retiro.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* COPIA del armado de tg_evento() (telegram_lib.php). No se puede incluir el
   archivo y llamarlo sin mandar un mensaje de verdad al grupo. Si divergen,
   este test deja de proteger nada: mantenerlos iguales. */
function armar(string $titulo, array $lineas): string {
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $txt = '<b>' . $esc($titulo) . '</b>';
    foreach ($lineas as $k => $v) {
        if (is_array($v)) {
            $val = trim((string)($v['code'] ?? ''));
            if ($val === '') { continue; }
            $txt .= "\n" . $esc($k) . ': <code>' . $esc($val) . '</code>';
            continue;
        }
        if ($v === null || $v === '') { continue; }
        $txt .= "\n" . $esc($k) . ': ' . $esc($v);
    }
    return $txt;
}

echo "\n=== 1. El aviso trae todo lo que hace falta para transferir ===\n";
$msg = armar('💸 Pedido de retiro (por el chat)', [
    'Jugador'   => 'holajavier375',
    'Quiere'    => '$5.000',
    'Tiene'     => '$34.590',
    'CBU/alias' => ['code' => '0000003100010000000001'],
    'Qué hacer' => 'Sacale las fichas en el panel y transferile.',
]);
foreach (['holajavier375' => 'el usuario', '5.000' => 'cuánto quiere',
          '34.590' => 'cuánto tiene', '0000003100010000000001' => 'el CBU'] as $dato => $que) {
    chequear("el mensaje trae $que", str_contains($msg, $dato));
}
chequear('el CBU va como bloque copiable',
         str_contains($msg, '<code>0000003100010000000001</code>'), $msg);

echo "\n=== 2. Sin CBU no se manda un bloque vacío: se pide ===\n";
$msg = armar('t', ['CBU/alias' => 'NO LO DEJÓ — pedíselo por el chat antes de pagar']);
chequear('dice que falta y qué hacer', str_contains($msg, 'NO LO DEJÓ'));
chequear('y no deja un <code> vacío', !str_contains($msg, '<code></code>'));
$msg = armar('t', ['CBU/alias' => ['code' => ''], 'Otro' => 'x']);
chequear('un code vacío se omite entero', !str_contains($msg, 'CBU'), $msg);
$msg = armar('t', ['CBU/alias' => ['code' => '   ']]);
chequear('y uno con espacios también', !str_contains($msg, '<code>'), $msg);

echo "\n=== 3. El escapado sigue en pie (un '<' rompe el mensaje entero) ===\n";
/* Telegram rechaza con 400 el mensaje COMPLETO si el HTML no cierra bien, y
   estos textos llevan nombres escritos por gente. Un jugador que se ponga
   "<b>" de nombre no puede dejar sin aviso a todos los retiros. */
$msg = armar('t', ['Jugador' => 'ana<b>hack</b>', 'CBU/alias' => ['code' => 'a<b>&"x']]);
chequear('el nombre se escapa',  str_contains($msg, 'ana&lt;b&gt;hack&lt;/b&gt;'), $msg);
chequear('el CBU también, DENTRO del code',
         str_contains($msg, '<code>a&lt;b&gt;&amp;&quot;x</code>'), $msg);
chequear('no queda ningún tag sin escapar del contenido',
         substr_count($msg, '<b>') === 1 && substr_count($msg, '<code>') === 1, $msg);

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
