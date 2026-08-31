<?php
/**
 * t_bloque_pago.php — Que el jugador SIEMPRE reciba los datos de pago.
 *
 * El bug que motiva esto: el modelo recibia el CBU en el resultado de
 * crear_recarga y tenia que copiarlo en su respuesta. A veces no lo hacia
 * ("te paso el monto" y ningun CBU). Peor: un CBU de 22 digitos transcripto
 * por un modelo se puede truncar o cambiar un digito, y ahi la plata se va a
 * la cuenta equivocada.
 *
 * Estos chequeos cubren las dos direcciones: que el bloque APAREZCA cuando el
 * modelo se lo olvido, y que NO se duplique cuando el modelo si lo puso.
 *
 *     php t_bloque_pago.php
 */
declare(strict_types=1);
require __DIR__ . '/api/chatbot_contexto.php';

$ok = 0; $fail = 0;
function chequear(string $que, bool $cond, string $detalle = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++;  printf("  OK    %s\n", $que); }
    else       { $fail++; printf("  FALLA %s   %s\n", $que, $detalle); }
}

$pago = [
    'monto'     => '1000.47',
    'alias'     => 'mi.alias.mp',
    'cbu'       => '0000184305000041593023',
    'titular'   => 'Herrera Facundo Nahuel',
    'vence_min' => 45,
];

echo "\n=== El modelo se olvido los datos (el bug reportado) ===\n";
$b = chatbot_bloque_pago($pago, 'Listo, transferí y te cargo las fichas.');
chequear('agrega el bloque',            $b !== '');
chequear('lleva el CBU completo',       strpos($b, '0000184305000041593023') !== false, $b);
chequear('lleva el alias',              strpos($b, 'mi.alias.mp') !== false);
chequear('lleva el titular',            strpos($b, 'Herrera Facundo Nahuel') !== false);
chequear('el monto va CON centavos',    strpos($b, '1.000,47') !== false, $b);
chequear('avisa el vencimiento',        strpos($b, '45 minutos') !== false);

echo "\n=== El modelo SI los puso: no duplicar ===\n";
chequear('detecta el CBU tal cual',
    chatbot_bloque_pago($pago, 'Transferí $1000,47 al CBU 0000184305000041593023') === '');
chequear('detecta el CBU con espacios',
    chatbot_bloque_pago($pago, 'CBU: 0000 1843 0500 0041 5930 23') === '');
chequear('detecta el CBU con puntos',
    chatbot_bloque_pago($pago, 'CBU 0000.1843.0500.0041.5930.23') === '');
chequear('detecta el alias solo',
    chatbot_bloque_pago($pago, 'Mandalo al alias mi.alias.mp') === '');
chequear('detecta el alias en mayusculas',
    chatbot_bloque_pago($pago, 'Alias: MI.ALIAS.MP') === '');

echo "\n=== Un CBU PARECIDO no cuenta como puesto ===\n";
// Si el modelo transcribio mal el CBU, el bloque correcto TIENE que salir
// igual: es exactamente el caso en que la plata se iria a otra cuenta.
chequear('CBU con un digito cambiado -> agrega el bloque bueno',
    chatbot_bloque_pago($pago, 'CBU: 0000184305000041593024') !== '');
chequear('CBU truncado -> agrega el bloque bueno',
    chatbot_bloque_pago($pago, 'CBU: 00001843050000') !== '');

echo "\n=== Sin datos de cuenta no inventa nada ===\n";
chequear('sin alias ni CBU devuelve vacio',
    chatbot_bloque_pago(['monto' => '500', 'alias' => '', 'cbu' => ''], 'hola') === '');

echo "\n=== Solo CBU, sin alias (la config real de hoy) ===\n";
$soloCbu = chatbot_bloque_pago(
    ['monto' => '500.12', 'alias' => '', 'cbu' => '0000184305000041593023',
     'titular' => 'Herrera Facundo Nahuel', 'vence_min' => 45], 'Dale');
chequear('agrega el bloque',         $soloCbu !== '');
chequear('NO escribe "Alias:" vacio', strpos($soloCbu, 'Alias:') === false, $soloCbu);

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
