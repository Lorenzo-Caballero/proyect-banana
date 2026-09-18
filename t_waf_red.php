<?php
/**
 * t_waf_red.php — la red que sostiene al sistema cuando el WAF gana.
 *
 * EL PEDIDO (Nahuel, 18/09/2026): *"quiero estar seguro de que si eso da algún
 * problema, haya algún método extra o alguna solución para cada uno de los
 * casos. No quiero que nada se rompa por culpa del WAF."*
 *
 * Son DOS problemas distintos y se resuelven al revés uno del otro:
 *
 *   ESCRITURAS (aprobar, rechazar, retirar, depositar). Acá el peligro es
 *   creer que algo pasó cuando no pasó, o al revés. La regla es no afirmar: un
 *   200 ilegible va a 'revisar' y lo mira una persona. Lo cubren t_deposito.py
 *   y t_retiro_api.py, y este archivo chequea que nadie afloje esa regla.
 *
 *   LECTURAS (saldos, libro, stock, solicitudes). Acá el peligro es el
 *   contrario: que NO se rompa nada visible. Con el WAF tapando el espejo el
 *   CRM abre, el chat contesta y las cargas se aprueban; lo único que pasa es
 *   que los saldos envejecen en silencio. Por eso la red no es reintentar
 *   --eso ya está-- sino AVISAR cuando reintentar no alcanzó.
 *
 *     php t_waf_red.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$srcSalud = file_get_contents(__DIR__ . '/api/salud_colector.php');
$srcCfg   = file_get_contents(__DIR__ . '/api/config_crm.php');
$srcBot   = file_get_contents(__DIR__ . '/api/salud_bot.php');
$srcCol   = file_get_contents(__DIR__ . '/colector/aprobar_cargas.py');

// ===========================================================================
echo "\n=== 1. El colector cuenta que pudo LEER, no solo que esta vivo ===\n";

/* La diferencia importa: los dos watchdogs que ya habia (monitor-altas.sh y
   monitor-cargas.sh) miran el worker y la cola, y los dos dan VERDE mientras
   el WAF nos tiene ciegos -- porque el worker esta vivo y la cola vacia. */
chequear('el worker reporta cada pasada',
         str_contains($srcCol, 'def reportar_salud('));
chequear('y lo hace AFUERA del try, que es donde estan las pasadas que murieron',
         str_contains($srcCol, '# VA AFUERA DEL try/except A PROPOSITO'),
         'la pasada que mas hace falta reportar es la que se cayo');
foreach (['espejo', 'libro', 'stock'] as $k) {
    chequear("informa como salio: $k",
             str_contains($srcCol, 'PASADA["' . $k . '"] = "ok"')
             || str_contains($srcCol, 'PASADA["' . $k . '"] = "parcial"'));
}
chequear('y cuenta los challenges del WAF',
         str_contains($srcCol, 'PASADA["challenges"] = PASADA.get("challenges", 0) + 1'));

// ===========================================================================
echo "\n=== 2. Solo una lectura BUENA cuenta como lectura ===\n";

/* Si un 'waf' o un 'parcial' moviera la fecha, el indicador diria que estamos
   leyendo justo cuando no podemos: seria peor que no tenerlo. */
chequear('un waf no mueve la fecha de la ultima lectura',
         str_contains($srcSalud, "if (\$estado === 'ok') {"),
         'un indicador que miente es peor que ninguno');
chequear('pero igual queda registrado como salio la ultima pasada',
         str_contains($srcSalud, "_estado'] = \$estado"),
         'sirve para distinguir "vivo y peleando" de "muerto"');
chequear('un barrido cortado por presupuesto NO cuenta como completo',
         str_contains($srcCol, 'PASADA["espejo"] = "parcial" if _pagina_inicial() else "ok"'),
         'si solo hubiera parciales, media tabla envejeceria sin que nadie lo note');

// ===========================================================================
echo "\n=== 3. Se avisa por consecuencia, no por falla ===\n";

/* EL AVISO REPETIDO YA MOLESTO UNA VEZ (16/09/2026: un Telegram cada 15
   minutos por un jugador que nunca transfirio). El WAF desafia de a rafagas y
   una pasada perdida se recupera sola en la siguiente: avisar de eso seria
   exactamente el mismo error. Se avisa cuando paso tanto tiempo sin UNA
   lectura buena que la consecuencia ya se ve del lado del jugador. */
chequear('el umbral es de minutos sin leer, no de pasadas falladas',
         str_contains($srcSalud, "'min' =>") && str_contains($srcSalud, 'SC_LIMITES'));
chequear('el aviso va con clave de dedupe',
         str_contains($srcSalud, "'colector_' . \$k . '_viejo'"),
         'sin clave, tg_evento repite el mismo aviso cada minuto');
chequear('y el mensaje dice QUE SIGNIFICA, no solo que fallo',
         str_contains($srcSalud, "'Qué significa'"),
         'un aviso que no dice que hacer se aprende a ignorar');

/* NUNCA LO VIMOS no es lo mismo que QUEDO VIEJO: el dia del deploy todas las
   fechas estan vacias, y avisar ahi es ruido garantizado. */
chequear('una fecha que nunca existio no dispara aviso',
         str_contains($srcSalud, 'if ($edad === null || $edad < $lim[\'min\']) { continue; }'));

// ===========================================================================
echo "\n=== 4. Se puede mirar desde afuera, sin entrar al VPS ===\n";

/* Es el mismo motivo por el que existe salud_bot.php: la pregunta "¿esta roto
   o esta lento?" se contestaba solo por SSH, y eso alargo dos incidentes. */
chequear('salud_bot expone lo que el colector pudo leer',
         str_contains($srcBot, "'colector'                  => \$colector"));
chequear('con la edad de cada lectura',
         str_contains($srcBot, "'hace_seg' => \$edad"));
chequear('y cuantos challenges vio la ultima pasada',
         str_contains($srcBot, 'challenges_ultima_pasada'));

// ===========================================================================
echo "\n=== 5. Las claves estan en la lista blanca de config_crm ===\n";

/* cfg_crm_guardar() filtra por CFG_CRM_DEFAULTS y descarta en SILENCIO lo que
   no este. Sin esto el reporte no falla: simplemente no guarda nada, y el
   indicador se queda en null para siempre pareciendo que el colector no
   reporta. */
foreach (['colector_espejo_en', 'colector_espejo_estado', 'colector_libro_en',
          'colector_stock_en', 'colector_challenges', 'colector_visto_en'] as $k) {
    chequear("'$k' esta en CFG_CRM_DEFAULTS", str_contains($srcCfg, "'" . $k . "'"),
             'cfg_crm_guardar descarta en silencio lo que no este en la lista');
}

// ===========================================================================
echo "\n=== 5b. La billetera del panel, que no la reporta nadie ===\n";

/* ENCONTRADO EL 18/09/2026 auditando esto mismo: el cron horario de
   sync_bancos.py apuntaba a un contenedor apagado y fallaba una vez por hora
   en un log que nadie leia. `bancos_ganamos` llevaba 18 dias sin actualizarse.

   Y no es un dato de consulta: rl_banco_panel() le GANA a lo configurado en el
   panel del dueño, porque el jugador que pide un deposito adentro de la
   plataforma ve la billetera del panel y el chat tiene que decir lo mismo. Si
   el alias hubiera cambiado, el chat habria seguido dictando el viejo y esa
   plata no se acreditaba nunca. No paso, pero fue suerte. */
chequear('la billetera tambien se vigila',
         str_contains($srcSalud, "'bancos' => ["));
chequear('y se mira la TABLA, no un reporte',
         str_contains($srcSalud, 'SELECT MAX(visto_en) FROM bancos_ganamos'),
         'el chequeo no puede depender de que el que tiene que correr, corra');
chequear('se calcula ANTES de guardar, o no se guardaria nunca',
         strpos($srcSalud, 'FROM bancos_ganamos') < strpos($srcSalud, 'cfg_crm_guardar($pdo, $guardar'));
chequear('con un umbral propio: espeja cada hora, no cada cinco minutos',
         str_contains($srcSalud, "'bancos' => ['min' => 240"));
chequear("y 'colector_bancos_en' esta en la lista blanca",
         str_contains($srcCfg, "'colector_bancos_en'"));
// ===========================================================================
echo "\n=== 6. Y la regla que no se negocia: NUNCA reintentar una escritura ===\n";

/* Un challenge prueba que la request no llego al backend, y por eso una
   LECTURA se puede repetir sin riesgo. En una escritura no prueba nada: la
   respuesta puede ser ilegible con la operacion ya hecha, y repetirla paga dos
   veces. Esta duplicado con t_waf_reintento.py a proposito -- es la regla que
   mas caro sale si alguien la afloja. */
foreach (['aprobar', 'rechazar', 'retirar_del_jugador', 'fijar_bono'] as $fn) {
    $i = strpos($srcCol, "\ndef $fn(");
    chequear("existe $fn()", $i !== false);
    if ($i === false) { continue; }
    $j = strpos($srcCol, "\ndef ", $i + 5);
    $cuerpo = substr($srcCol, $i, ($j === false ? strlen($srcCol) : $j) - $i);
    chequear("$fn() no usa el reintento de lecturas",
             !str_contains($cuerpo, 'leer_json('),
             'una respuesta ilegible NO prueba que no haya pasado nada');
    chequear("$fn() ante la duda no afirma",
             str_contains($cuerpo, 'revisar') || str_contains($cuerpo, 'return False'),
             'tiene que caer en revisar, nunca en hecha');
}

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
