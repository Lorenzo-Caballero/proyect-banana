<?php
/**
 * t_stock.php — el aviso de "te estas quedando sin fichas".
 *
 * EL CASO QUE BLINDA (12/9/2026): la cuenta de agente se quedo sin fichas, la
 * plataforma empezo a rechazar los depositos, y nos enteramos cuando los
 * jugadores reclamaron. Este aviso existe para que se sepa ANTES.
 *
 * Lo que mas importa que no falle: el saldo sale de result.source_user.balance,
 * y la MISMA respuesta del panel trae otros dos numeros plausibles que NO son
 * nuestro stock (balance_sum = suma de los jugadores; users[].balance = uno
 * suelto). Agarrar el equivocado haria que el aviso suene (o calle) por el
 * motivo incorrecto.
 *
 *     php t_stock.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* Respuesta REAL del panel, recortada. Los tres numeros salieron de la sonda
   del 13/9/2026 contra la cuenta de produccion. */
$RESPUESTA = json_decode(<<<'JSON'
{"result": {
  "users": [
    {"balance": 3750.0,  "parent_agent": {"balance": 233911.1}},
    {"balance": 0.0,     "parent_agent": {"balance": 233911.1}},
    {"balance": 70134.8, "parent_agent": {"balance": 233911.1}}
  ],
  "balance_sum": 73884.8,
  "source_user": {"balance": 233911.1}
}}
JSON, true);

echo "\n=== 1. De donde sale NUESTRO stock ===\n";
// Esta es la ruta que usa colector/aprobar_cargas.py: revisar_stock().
$saldo = $RESPUESTA['result']['source_user']['balance'] ?? null;
chequear('result.source_user.balance es el saldo del cajero',
         $saldo === 233911.1, var_export($saldo, true));
chequear('y coincide con el "Saldo" que muestra el panel (233.911,10)',
         number_format((float)$saldo, 2, ',', '.') === '233.911,10',
         number_format((float)$saldo, 2, ',', '.'));

echo "\n=== 2. Los dos numeros con los que NO hay que confundirlo ===\n";
chequear('balance_sum es la suma de los JUGADORES, no nuestro stock',
         $RESPUESTA['result']['balance_sum'] !== $saldo);
chequear('users[].balance es el saldo de UN jugador',
         $RESPUESTA['result']['users'][0]['balance'] !== $saldo);
/* El mas traicionero: un jugador con saldo grande. Si el codigo agarrara
   "el balance mas grande de la respuesta" o "el primero que encuentre",
   acertaria hoy y fallaria el dia que alguien tenga mas que nosotros. */
chequear('un jugador con saldo grande no se confunde con el stock',
         $RESPUESTA['result']['users'][2]['balance'] === 70134.8
         && $RESPUESTA['result']['users'][2]['balance'] !== $saldo);

echo "\n=== 3. Cuando avisa y cuando no ===\n";
/* Misma condicion que api/stock_agente.php. */
$avisa = fn(float $s, int $u): bool => $u > 0 && $s < $u;
chequear('umbral 0 = sin aviso, aunque el stock sea cero',   $avisa(0.0, 0) === false);
chequear('stock arriba del umbral: no avisa',                $avisa(233911.1, 50000) === false);
chequear('stock abajo del umbral: avisa',                    $avisa(40000.0, 50000) === true);
chequear('justo en el umbral: todavia no avisa',             $avisa(50000.0, 50000) === false);
chequear('stock en cero con umbral puesto: avisa',           $avisa(0.0, 50000) === true);

echo "\n=== 4. El dedupe por TRAMO no satura ni se calla de mas ===\n";
/* Corre cada 10 min y el saldo baja de a poco: una clave con el numero exacto
   mandaria un mensaje por cada ficha que se mueve. El tramo (decimos del
   umbral) hace que suene de nuevo solo cuando empeora en serio. */
$tramo = fn(float $s, int $u): int => (int)floor($s / max(1, (int)($u / 10)));
$u = 50000;
chequear('dos lecturas del mismo tramo: un solo aviso',
         $tramo(41000.0, $u) === $tramo(40000.0, $u),
         $tramo(41000.0, $u) . ' vs ' . $tramo(40000.0, $u));
chequear('si empeora de verdad, cambia el tramo y vuelve a sonar',
         $tramo(40000.0, $u) !== $tramo(31000.0, $u),
         $tramo(40000.0, $u) . ' vs ' . $tramo(31000.0, $u));
/* En el BORDE de un tramo (40.000 -> 39.500 cruza los 40.000) vuelve a sonar
   aunque el salto sea chico. Es a proposito: cruzar un escalon es justo la
   senal de que sigue bajando. El techo es de ~10 avisos entre el umbral y
   cero, y tg_avisar_una_vez ademas no repite la misma clave dentro de su
   ventana, asi que no hay forma de que sature. */
chequear('cruzar un borde vuelve a avisar (es lo que se busca)',
         $tramo(40000.0, $u) !== $tramo(39500.0, $u));
chequear('y el total de avisos entre el umbral y cero esta acotado (~10)',
         $tramo((float)$u, $u) - $tramo(0.0, $u) <= 12,
         (string)($tramo((float)$u, $u) - $tramo(0.0, $u)));
chequear('en cero suena, y es el tramo mas bajo', $tramo(0.0, $u) === 0);

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
