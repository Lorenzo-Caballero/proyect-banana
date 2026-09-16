<?php
/**
 * waf.php — ¿Cuánto nos está costando el challenge de Cloudflare, y va peor?
 *
 * DE DÓNDE SALE (16/09/2026). En la misma madrugada el challenge rompió tres
 * altas del chat (Javierso, Bejarano, Fabianol) y un depósito de 750 fichas
 * que agotó los 5 reintentos. Hasta ahora eso se discutía por impresiones:
 * *"parece que hay más"*. Esto lo cuenta.
 *
 * CÓMO SE RECONOCE, y por qué se puede contar desde la base: cuando
 * ServicePipe/Cloudflare desafía una request, la plataforma NO devuelve un
 * error — devuelve **HTTP 200 con el HTML del challenge**, que trae un
 * `<noscript>` con un refresh a `/exhk…`. El worker guarda la respuesta cruda
 * en el `mensaje` de la fila, así que el rastro queda en `acciones_saldo` y en
 * `altas` sin necesidad de tocar un log.
 *
 * ESO ES JUSTAMENTE LO QUE LO HACÍA PELIGROSO: mirar solo el código HTTP daba
 * "200 = salió bien", y esos depósitos se contaban como hechos costándole las
 * fichas al jugador. Hoy el worker decide por el CUERPO y por eso aparecen acá
 * como errores en vez de desaparecer.
 *
 * SOLO LEE.
 *
 *   php /opt/goldpaw/scripts/waf.php
 *   php /opt/goldpaw/scripts/waf.php ganamoscrm.online 7
 */

$dominio = $argv[1] ?? 'ganamoscrm.online';
$dias    = max(1, (int)($argv[2] ?? 3));

$_SERVER['HTTP_HOST'] = $dominio;
$API = is_dir('/var/www/api') ? '/var/www/api' : __DIR__ . '/../api';
require_once $API . '/db.php';

function titulo($t) { echo "\n\033[1m" . $t . "\033[0m\n" . str_repeat('-', 74) . "\n"; }

/* Las dos marcas del challenge. `/exhk` es la ruta a la que redirige
   ServicePipe; el DOCTYPE cubre el caso de que cambie esa ruta pero siga
   llegando HTML donde tendría que venir JSON. */
const MARCAS = ['%exhk%', '%<!DOCTYPE html>%', '%http-equiv="refresh"%'];

echo "\nChallenge del WAF — " . $dominio . "  (últimos " . $dias . " días)\n";

// ===========================================================================
titulo('1. Depósitos que lo agarraron');
/* `acciones_saldo` es nuestra cola de saldo real: el mensaje guarda la
   respuesta cruda del panel. */
$like = implode(' OR ', array_map(fn($m) => "mensaje LIKE " . "'" . $m . "'", MARCAS));
$st = $pdo->prepare(
    "SELECT id, usuario, monto, estado, intentos, creada_en, ejecutada_en
       FROM acciones_saldo
      WHERE creada_en >= NOW() - INTERVAL ? DAY AND ($like)
      ORDER BY creada_en DESC LIMIT 40"
);
$st->execute([$dias]);
$dep = $st->fetchAll();

/* "revisar" NO SIGNIFICA QUE NO ENTRO, Y ESTA VERSION DECIA QUE SI.
   Medido el 16/09/2026: holaDiego858 tenia DOS acciones de 750 en 'revisar' y
   este script dijo "1.500 fichas que NO entraron". El libro del panel mostraba
   un deposito de 750 ejecutado un minuto despues. O sea que el consejo era
   cargar 1.500 a mano sobre algo que ya estaba: exactamente el doble credito
   que le costo 35.000 a otro jugador esa misma madrugada.

   Por que pasa: con un challenge el worker recibe 200 con HTML, no puede
   confirmar nada y marca 'revisar' para el lado seguro. Pero "no pude
   confirmar" no es "no paso" -- la request pudo haber llegado igual.

   La regla ya estaba escrita en CLAUDE.md y este script no la usaba: estar en
   el libro es la prueba de que la operacion se ejecuto, y no estar es la
   prueba de que no. `operaciones_panel` es el registro de la PLATAFORMA, no el
   nuestro. Nuestras tablas dicen lo que quisimos hacer; el libro, lo que paso.

   Se busca por jugador, monto y una ventana de 30 minutos alrededor: el panel
   da la hora al minuto y el worker reintenta, asi que pedir coincidencia
   exacta no encontraria nada. */
$enLibro = function (string $usuario, float $monto, string $cuando) use ($pdo): bool {
    try {
        $q = $pdo->prepare(
            "SELECT 1 FROM operaciones_panel
              WHERE tipo = 0
                AND username = ?
                AND ROUND(monto * 100) = ?
                AND cuando BETWEEN (? - INTERVAL 30 MINUTE) AND (? + INTERVAL 30 MINUTE)
              LIMIT 1"
        );
        $q->execute([$usuario, (int)round($monto * 100), $cuando, $cuando]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        /* Sin libro (migracion 67 sin correr) NO se afirma que entro: se deja
           la duda, que es el lado seguro para el jugador. */
        return false;
    }
};

$plataParada = 0.0;
$sinEntrar = [];
foreach ($dep as $f) {
    $marcadaMal = in_array((string)$f['estado'], ['error', 'revisar'], true);
    $entro = $marcadaMal
           ? $enLibro((string)$f['usuario'], (float)$f['monto'], (string)$f['creada_en'])
           : true;
    if ($marcadaMal && !$entro) {
        $plataParada += (float)$f['monto'];
        $sinEntrar[] = $f;
    }
    printf("  %s  %-22s %8s fichas  %-9s intentos:%d%s\n",
           substr((string)$f['creada_en'], 5, 14), $f['usuario'],
           number_format((float)$f['monto'], 0, ',', '.'), $f['estado'],
           (int)($f['intentos'] ?? 0),
           !$marcadaMal ? ''
             : ($entro ? "   \033[32mpero SÍ entró (está en el libro)\033[0m"
                       : "   \033[1m<< SIN ENTRAR\033[0m"));
}
if (!$dep) { echo "  Ninguno. Los depósitos no se toparon con el challenge.\n"; }
else {
    printf("\n  %d depósito(s) tocados por el challenge.\n", count($dep));
    printf("  De esos, %d no entraron de verdad (contra el libro del panel): %s fichas\n",
           count($sinEntrar), number_format($plataParada, 0, ',', '.'));
    if (count($dep) > count($sinEntrar)) {
        echo "  El resto figura mal en NUESTRA tabla pero el panel lo tiene: no se toca.\n";
    }

    /* ANTES DE QUE ALGUIEN CARGUE ESE TOTAL A MANO: puede estar duplicado.
       Medido el 16/09/2026 en la primera corrida -- dos pedidos de 750 del
       MISMO jugador con 4 minutos de diferencia, distinguidos solo por una
       mayúscula (holaDiego858 / holadiego858). El total decía 1.500 y lo que
       se le debe es 750.

       El nombre se compara en minúsculas justamente por eso: MySQL compara sin
       distinguir mayúsculas, pero acá se agrupa en PHP sobre el texto tal cual
       quedó guardado, y dos formas del mismo nombre parecerían dos personas.

       No se decide nada: se avisa y se manda a mirar. Un jugador PUEDE haber
       pedido dos cargas iguales seguidas -- lo que no puede es que se le
       carguen las dos sin que nadie lo haya mirado. Es exactamente el error
       que le costó 35.000 de más a rodrigoalejandro1234 esa misma madrugada. */
    $porJugador = [];
    foreach ($sinEntrar as $f) {
        $k = mb_strtolower(trim((string)$f['usuario'])) . '|' . (float)$f['monto'];
        $porJugador[$k][] = $f;
    }
    $sospechosos = array_filter($porJugador, fn($g) => count($g) > 1);
    if ($sospechosos) {
        echo "\n  \033[1m⚠ OJO ANTES DE CARGAR A MANO: hay pedidos repetidos.\033[0m\n";
        foreach ($sospechosos as $k => $g) {
            [$u, $m] = explode('|', $k);
            printf("    %s pidió %s fichas %d veces:\n",
                   $u, number_format((float)$m, 0, ',', '.'), count($g));
            foreach ($g as $f) {
                printf("      %s  (como \"%s\", acción %d)\n",
                       substr((string)$f['creada_en'], 5, 14), $f['usuario'], (int)$f['id']);
            }
            printf("      Si fue UNA sola carga, se le deben %s y no %s.\n",
                   number_format((float)$m, 0, ',', '.'),
                   number_format((float)$m * count($g), 0, ',', '.'));
        }
        echo "\n    Miralo con:  php scripts/jugador-plata.php <usuario>\n";
        echo "    Ahí se ve lo que TRANSFIRIÓ contra lo que se le acreditó.\n";
    }

    if ($plataParada > 0) {
        echo "\n  Lo que falte cargar va A MANO en el panel: el jugador pagó y no lo tiene.\n";
    }
}

// ===========================================================================
titulo('2. Altas que lo agarraron');
/* El alta no guarda el HTML: Playwright se queda esperando un formulario que
   nunca carga y lo que queda es esa excepción. Es la misma causa con otra
   cara, y por eso se cuentan juntas. */
$st = $pdo->prepare(
    "SELECT usuario, estado, intentos, pedido_en, origen
       FROM altas
      WHERE pedido_en >= NOW() - INTERVAL ? DAY
        AND (mensaje LIKE '%No aparecio el formulario%'
          OR mensaje LIKE '%exhk%' OR mensaje LIKE '%challenge%')
      ORDER BY pedido_en DESC LIMIT 40"
);
$st->execute([$dias]);
$alt = $st->fetchAll();
foreach ($alt as $f) {
    printf("  %s  %-22s %-11s intentos:%d  (%s)\n",
           substr((string)$f['pedido_en'], 5, 14), $f['usuario'],
           $f['estado'], (int)$f['intentos'], $f['origen']);
}
if (!$alt) { echo "  Ninguna.\n"; }
/* CUENTA DE MENOS Y CONVIENE SABERLO: `altas.mensaje` guarda SOLO el último
   intento. Un alta que hoy pegó contra el challenge y después salió bien
   --porque se renombró y entró por la API-- queda con "creado por API" y
   desaparece de esta lista. O sea que acá se ven las que TERMINARON mal, no
   todas las que lo tocaron. Para las de hoy, mirá scripts/altas-estado.php. */

// ===========================================================================
titulo('3. ¿Va peor? (por día)');
/* LA PREGUNTA QUE IMPORTA. Un challenge suelto es el costo de operar detrás de
   Cloudflare desde un datacenter; una curva que sube es otra cosa y cambia la
   decisión. Por eso se cuenta por día y no en total. */
$st = $pdo->prepare(
    "SELECT f, SUM(dep) dep, SUM(alt) alt FROM (
        SELECT DATE(creada_en) f, 1 dep, 0 alt FROM acciones_saldo
         WHERE creada_en >= NOW() - INTERVAL ? DAY AND ($like)
        UNION ALL
        SELECT DATE(pedido_en) f, 0, 1 FROM altas
         WHERE pedido_en >= NOW() - INTERVAL ? DAY
           AND (mensaje LIKE '%No aparecio el formulario%' OR mensaje LIKE '%exhk%')
     ) x GROUP BY f ORDER BY f"
);
$st->execute([$dias, $dias]);
$hay = 0;
foreach ($st as $f) {
    $hay++;
    $n = (int)$f['dep'] + (int)$f['alt'];
    printf("  %s   depósitos:%-3d altas:%-3d  %s\n",
           $f['f'], (int)$f['dep'], (int)$f['alt'], str_repeat('#', min(40, $n)));
}
if (!$hay) { echo "  Sin challenges en el período.\n"; }

echo "\n";
echo "QUÉ SIGNIFICA Y QUÉ NO\n";
echo str_repeat('-', 74) . "\n";
echo "  El sistema está haciendo lo correcto: reconoce el challenge, NO da el\n";
echo "  depósito por hecho y escala a una persona. Antes tomaba el 200 por\n";
echo "  bueno y le costaba las fichas al jugador -- eso ya no pasa.\n";
echo "\n";
echo "  Y AL REVÉS TAMBIÉN: que figure 'revisar' no prueba que no haya entrado.\n";
echo "  El worker marca eso cuando no PUDO confirmar, y la request igual pudo\n";
echo "  haber llegado. Por eso cada línea se cruza contra el libro del panel\n";
echo "  antes de decir que falta cargar algo. Cargar a mano sobre algo que ya\n";
echo "  entró es cómo se acreditan 35.000 dos veces.\n";
echo "\n";
echo "  Reiniciar el bot NO lo arregla: no está caído, le están contestando\n";
echo "  con un challenge. Lo que cambia la situación es reducir el patrón que\n";
echo "  Cloudflare puntúa como bot (menos concurrencia) o dejar de pegarle al\n";
echo "  dominio que está detrás de Cloudflare. Ver CLAUDE.md, «Dominios».\n\n";
