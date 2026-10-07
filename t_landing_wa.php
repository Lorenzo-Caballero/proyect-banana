<?php
/**
 * t_landing_wa.php — La landing que crea la cuenta y deriva al WhatsApp.
 *
 * PARA QUÉ EXISTE (pedido del dueño, 07/10/2026): una landing por cajero. El
 * jugador se registra con NUESTRAS credenciales de agente y, al terminar, en
 * vez de entrar al casino se lo manda al WhatsApp de SU cajero — con el
 * usuario ya escrito en el mensaje, para que del otro lado sepan a quién
 * cargarle sin preguntar.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN:
 *
 *  1. QUE EL NÚMERO SE ACEPTE COMO LO ESCRIBE UNA PERSONA. El operador lo va a
 *     copiar de su agenda, con +, espacios y guiones. Pedirle que lo limpie a
 *     mano es garantizar un link roto que nadie prueba hasta que un jugador se
 *     cae del embudo.
 *  2. QUE NO SE ADIVINE EL PAÍS. Un wa.me con el código equivocado abre un
 *     chat con un desconocido, y eso no falla a la vista.
 *  3. QUE SIN NÚMERO LA LANDING SIGA ANDANDO como siempre.
 *
 *     php t_landing_wa.php
 */
declare(strict_types=1);

if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/landings_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

// ===========================================================================
echo "=== 1. El número, como lo escribe una persona ===\n";
/* Nadie guarda los teléfonos en formato E.164. Si el campo solo aceptara
   dígitos pelados, el operador pegaría lo de su agenda y el link saldría roto
   -- y nadie lo nota hasta que un jugador no llega. */
foreach ([
    ['+54 9 11 2345-6789', '5491123456789'],
    ['5491123456789',      '5491123456789'],
    [' +1 (305) 555-0199 ','13055550199'],
    ['+54-9-11-2345-6789', '5491123456789'],
] as [$crudo, $esperado]) {
    chequear("'$crudo' -> $esperado",
             landings_wa_numero($crudo) === $esperado,
             'dio: ' . (landings_wa_numero($crudo) ?: '(rechazado)'));
}

echo "\n=== 2. Sin país, se rechaza (no se adivina) ===\n";
/* EL CASO QUE IMPORTA es el primero, y el piso de 10 dígitos lo dejaba pasar:
   '11 2345-6789' es como tiene su propio número cualquiera en Argentina, y son
   EXACTAMENTE 10 dígitos. Publicaba un wa.me apuntando a otro país — sin
   error, sin aviso, y perdiendo al jugador que terminó la landing. */
foreach ([
    '11 2345-6789',     // celular argentino sin país: 10 dígitos justos
    '011 2345-6789',    // el mismo, con el 0 de tronco
    '3055550199',       // uno de EEUU sin el 1: también le falta el país
    '2345-6789',
    '',
    'no soy un numero',
    '123',
] as $malo) {
    chequear("'$malo' no produce link", landings_wa_numero($malo) === '',
             'dio: ' . landings_wa_numero($malo));
}

/* Con el "+" el operador DECLARÓ el país, y ahí se le cree: hay países con
   números cortos, y rechazarlos sería inventar un problema que no existe. */
chequear("'+47 12345678' se acepta porque trae el +",
         landings_wa_numero('+47 12345678') === '4712345678');
chequear("'0054 9 11 2345-6789' (prefijo internacional) se acepta",
         landings_wa_numero('0054 9 11 2345-6789') === '5491123456789');
chequear('un número absurdamente largo se rechaza',
         landings_wa_numero('+5491123456789000000') === '');

// ===========================================================================
echo "\n=== 3. El link lleva el usuario, que es lo que lo hace útil ===\n";
$cfg = landings_config_completa('wa', json_encode([
    'whatsapp' => ['numero' => '+54 9 11 2345-6789', 'texto' => 'Hola Leandro'],
]));
$link = landings_wa_link($cfg, 'holaMartina847');
chequear('apunta a wa.me con el número limpio',
         str_starts_with($link, 'https://wa.me/5491123456789?text='), $link);
chequear('el mensaje del operador viaja',
         str_contains(rawurldecode($link), 'Hola Leandro'));
chequear('y el usuario va adentro',
         str_contains(rawurldecode($link), 'holaMartina847'),
         'sin esto el cajero recibe un "hola" y no sabe a quién cargarle');
chequear('el texto va escapado (un & no puede partir la URL)',
         !str_contains(explode('?text=', $link)[1] ?? '', ' '));

/* Sin usuario también tiene que armar un link válido: el jugador puede llegar
   al WhatsApp desde la landing sin haberse registrado. */
chequear('sin usuario sigue siendo un link válido',
         str_starts_with(landings_wa_link($cfg), 'https://wa.me/5491123456789?text='));

// ===========================================================================
echo "\n=== 4. Sin número configurado, la landing es la de siempre ===\n";
/* La derivación es opcional: una landing sin número tiene que seguir mandando
   al casino, no a un link roto. */
chequear('sin número no hay link', landings_wa_link(landings_config_completa('wa', null)) === '');
chequear('ni con un número inválido',
         landings_wa_link(landings_config_completa('wa',
             json_encode(['whatsapp' => ['numero' => '123']]))) === '');

// ===========================================================================
echo "\n=== 5. La plantilla está disponible y no rompe las viejas ===\n";
$p = landings_plantillas();
chequear('existe la plantilla "wa"', isset($p['wa']));
chequear('y se llama por lo que hace', ($p['wa']['nombre'] ?? '') === 'Registro + WhatsApp');
chequear('las plantillas de siempre siguen ahí',
         isset($p['oro'], $p['neon'], $p['fuego'], $p['registro']));
/* El merge por sección: si no reconociera 'whatsapp', el número guardado por
   el operador se perdería al leer la config. */
chequear('el número guardado sobrevive al merge',
         landings_config_completa('oro', json_encode([
             'whatsapp' => ['numero' => '5491123456789'],
         ]))['whatsapp']['numero'] === '5491123456789',
         'sin esto el operador lo carga, se guarda, y la página no lo ve');
/* Una landing vieja (config sin la sección) no puede explotar ni quedar sin
   defaults. */
chequear('una landing vieja sigue funcionando',
         landings_config_completa('oro', json_encode(['colores' => ['fondo' => '#000']]))
             ['whatsapp']['numero'] === '');

// ===========================================================================
echo "\n=== 6. Lo que el operador guarda LLEGA a la página ===\n";
/* EL RIESGO REAL DE ESTE MÓDULO. lp_config_sanear() es una LISTA BLANCA:
   descarta en silencio toda sección que no reconozca. Si 'whatsapp' no está
   adentro, el operador carga el número, el CRM dice «Guardada», y la landing
   publicada no deriva a nadie. Nada tira error — solo se pierden jugadores.

   Por eso acá se corre la función REAL de crm_landings.php, extraída del
   archivo: una copia seguiría pasando después de que alguien toque la lista. */
$srcCrm = file_get_contents(__DIR__ . '/api/crm_landings.php');
if (!preg_match('/function lp_config_sanear\(array \$cruda\): array\s*\{.*?\n\}/s', $srcCrm, $m)) {
    chequear('se encontró lp_config_sanear en crm_landings.php', false);
} else {
    eval($m[0]);
    $guardado = lp_config_sanear([
        'whatsapp' => ['numero' => '+54 9 11 2345-6789', 'texto' => 'Hola, quiero cargar'],
        'colores'  => ['fondo' => '#06281d'],
    ]);
    chequear('el número sobrevive al guardado',
             ($guardado['whatsapp']['numero'] ?? '') === '+54 9 11 2345-6789',
             'la lista blanca lo descartó: el CRM diría «Guardada» y no derivaría a nadie');
    chequear('y el mensaje también',
             ($guardado['whatsapp']['texto'] ?? '') === 'Hola, quiero cargar');

    /* SE GUARDA COMO LO ESCRIBIÓ, no normalizado: si se guardara normalizado,
       uno mal escrito desaparecería del editor y el operador no tendría qué
       corregir. */
    chequear('se guarda tal cual lo tipeó (con + y espacios)',
             str_contains($guardado['whatsapp']['numero'], '+')
             && str_contains($guardado['whatsapp']['numero'], ' '));

    // Y el viaje completo: lo guardado tiene que producir el link.
    $cfgFinal = landings_config_completa('wa', json_encode($guardado));
    chequear('guardar -> leer -> link, de punta a punta',
             landings_wa_link($cfgFinal, 'holaMartina847')
               === 'https://wa.me/5491123456789?text='
                   . rawurlencode('Hola, quiero cargar (usuario: holaMartina847)'),
             landings_wa_link($cfgFinal, 'holaMartina847'));

    /* Lo que NO puede entrar: este campo termina dentro de una URL pública. */
    $sucio = lp_config_sanear(['whatsapp' => [
        'numero' => '549112345<script>', 'texto' => str_repeat('x', 400),
    ]]);
    chequear('se filtra lo que no puede ser un teléfono',
             !str_contains($sucio['whatsapp']['numero'], '<'),
             $sucio['whatsapp']['numero']);
    chequear('el mensaje se recorta (va en una URL)',
             mb_strlen($sucio['whatsapp']['texto']) <= 120);

    /* Y lo inverso, que es lo que pasa cuando se da de baja a un cajero:
       vaciar el campo tiene que dejarlo vacío de verdad. Si el merge
       conservara el anterior, ese número seguiría recibiendo jugadores. */
    chequear('borrar el número lo deja vacío de verdad',
             landings_config_completa('wa',
                 json_encode(lp_config_sanear(['whatsapp' => ['numero' => '']])))
               ['whatsapp']['numero'] === '');
}

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
