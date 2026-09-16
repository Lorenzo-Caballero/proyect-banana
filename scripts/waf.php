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
$plataParada = 0.0;
foreach ($dep as $f) {
    $trabada = in_array((string)$f['estado'], ['error', 'revisar'], true);
    if ($trabada) { $plataParada += (float)$f['monto']; }
    printf("  %s  %-22s %8s fichas  %-9s intentos:%d%s\n",
           substr((string)$f['creada_en'], 5, 14), $f['usuario'],
           number_format((float)$f['monto'], 0, ',', '.'), $f['estado'],
           (int)($f['intentos'] ?? 0),
           $trabada ? "   \033[1m<< SIN ENTRAR\033[0m" : "");
}
if (!$dep) { echo "  Ninguno. Los depósitos no se toparon con el challenge.\n"; }
else {
    printf("\n  %d depósito(s) tocados.  Fichas que NO entraron: %s\n",
           count($dep), number_format($plataParada, 0, ',', '.'));
    if ($plataParada > 0) {
        echo "  Esas hay que cargarlas A MANO en el panel: el jugador pagó y no las tiene.\n";
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
echo "  Reiniciar el bot NO lo arregla: no está caído, le están contestando\n";
echo "  con un challenge. Lo que cambia la situación es reducir el patrón que\n";
echo "  Cloudflare puntúa como bot (menos concurrencia) o dejar de pegarle al\n";
echo "  dominio que está detrás de Cloudflare. Ver CLAUDE.md, «Dominios».\n\n";
