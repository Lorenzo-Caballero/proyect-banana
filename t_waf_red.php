<?php
/**
 * t_waf_red.php — la red que agarra lo que falla EN SILENCIO.
 *
 * Empezó por el WAF y terminó siendo más grande, porque el patrón resultó ser
 * el mismo: lo peligroso no es lo que se rompe con un error a la vista, es lo
 * que deja de pasar sin que nadie se entere.
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
   fechas estan vacias y avisar ahi seria ruido garantizado. Lo que cambio
   despues es que "nunca" tampoco se queda callado PARA SIEMPRE (seccion 7). */
chequear('el dia del deploy, una fecha vacia no molesta',
         str_contains($srcSalud, 'if ($edad === null) {'),
         'todas las fechas empiezan vacias: avisar ahi seria ruido');

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
chequear('se mide con bancos_sync_en, que es la clave que corresponde',
         str_contains($srcSalud, "'clave' => 'bancos_sync_en'"),
         'la escribe el cron del sync, no el colector');

/* EL ERROR QUE COMETI Y QUE ESTE TEST FRENA A PARTIR DE AHORA. La primera
   version miraba `bancos_ganamos.visto_en`, que es ON UPDATE
   CURRENT_TIMESTAMP: mide cuando CAMBIO la billetera, no cuando la leimos --
   MySQL no lo dispara si la fila queda igual, y una billetera no cambia nunca.
   Medido el 18/09/2026: decia 31/08 con el sync corriendo, o sea que el aviso
   habria saltado para siempre. Es el mismo error de `usuarios.actualizado_en`
   que ya esta documentado en CLAUDE.md, y la migracion 47 lo habia previsto
   creando `bancos_sync_en` justamente para esto. */
chequear('y NO con visto_en, que mide otra cosa',
         !str_contains($srcSalud, 'MAX(visto_en) FROM bancos_ganamos'),
         'visto_en mide cuando cambio la billetera, no cuando la leimos');

$srcBan = file_get_contents(__DIR__ . '/api/bancos_sync.php');
chequear('y visto_en pasa a decir lo que su nombre dice',
         str_contains($srcBan, 'visto_en = NOW()'),
         'sin esto la columna se queda clavada en el dia que se cargo la billetera');

chequear('con un umbral propio: espeja cada hora, no cada cinco minutos',
         str_contains($srcSalud, "'bancos' => ['min' => 240"));
chequear('y el colector no puede inventar ese estado',
         str_contains($srcSalud, "if (isset(SC_LIMITES[\$k]['clave'])) { continue; }"),
         'lo escribe su propio cron: un body que lo mande estaria mintiendo');
// ===========================================================================
echo "\n=== 5c. El detector del 200 falso es UNO solo ===\n";

/* ServicePipe no devuelve 403: devuelve 200 con una pagina que redirige, asi
   que el codigo HTTP no dice nada y hay que mirar el cuerpo. Estaba escrito
   CUATRO VECES con reglas distintas y ninguna reconocia la firma del
   <noscript> con refresh -- la que el test del deposito exige desde hace
   semanas. Un challenge con esa forma no se detectaba, no se reintentaba, y la
   operacion caia en 'revisar' esperando a una persona. */
chequear('existe es_challenge() y es el unico',
         str_contains($srcCol, 'def es_challenge(')
         && substr_count($srcCol, 'startswith("<!doctype html")') === 1,
         'cuatro copias con reglas distintas es como se pierde una firma');
chequear('y conoce la firma que faltaba',
         str_contains($srcCol, '"<noscript" in cabeza'));
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
    /* Pero SI vuelve a intentar cuando SABE que fue el WAF, y eso es lo que al
       escalar decide si una carga sale sola o cae en 'revisar' esperando a una
       persona: un challenge prueba que la request no llego al backend, asi que
       repetirla es gratis.

       Hay DOS formas validas de hacerlo y las dos estan bien:
         · en linea, con la espera que crece (aprobar, rechazar);
         · devolviendo la accion a la cola con 'reintentar', para que la tome
           la pasada siguiente con la sesion fresca. Es lo que hace el retiro,
           que es la escritura mas peligrosa de todas: ahi conviene la vuelta
           limpia antes que insistir en caliente. */
    if ($fn !== 'fijar_bono') {
        chequear("$fn() vuelve a intentar un challenge",
                 str_contains($cuerpo, 'es_challenge(')
                 && (str_contains($cuerpo, 'WAF_ESPERAS_S')
                     || str_contains($cuerpo, '"reintentar"')),
                 'si no reintenta, cada challenge deja trabajo manual');
    }
}

// ===========================================================================
echo "\n=== 7. Lo que corre SOLO tambien se vigila ===\n";

/* EL 18/09/2026 APARECIERON TRES TAREAS APUNTANDO A LA NADA, y las tres se
   encontraron mirando a mano, no porque algo avisara:

     · el cron de sync_bancos.py iba a un contenedor apagado hacia dos dias y
       fallaba una vez por hora en un log que nadie lee;
     · el de fidelizacion.php NUNCA se instalo: la promo figura prendida en el
       CRM, corrio una sola vez a mano, y desde entonces nada (600 jugadores
       con un bono prometido y el motor parado);
     · el espejo de saldos moria en el primer challenge del WAF.

   Un proceso que no corre NO SE QUEJA. Simplemente no pasa nada, y eso se ve
   exactamente igual que "no habia nada que hacer". Por eso lo que se vigila no
   es si el proceso vive --los dos watchdogs que ya habia miran eso y daban
   verde-- sino cuando fue la ultima vez que FUNCIONO. */
chequear('existe la tabla de tareas programadas',
         str_contains($srcSalud, 'const SC_TAREAS'));
foreach (['fidelizacion' => 'fid_visto_en',
          'difusiones'   => 'difusiones_visto_en',
          'ruleta_aviso' => 'ruleta_aviso_visto_en'] as $tarea => $clave) {
    chequear("se vigila: $tarea", str_contains($srcSalud, "'" . $clave . "'"));
    chequear("y '$clave' esta en la lista blanca", str_contains($srcCfg, "'" . $clave . "'"),
             'cfg_crm_guardar descarta en silencio lo que no este');
}

/* Y que cada tarea SELLE su latido, o la vigilancia mira una fecha que nadie
   escribe y avisa para siempre.

   NO ALCANZA CON QUE EL SELLO ESTE ESCRITO: tiene que poder EJECUTARSE. La
   primera version de este test miraba solo que la clave apareciera en el
   archivo, y paso en verde con el latido roto -- ninguno de los dos endpoints
   cargaba `config_crm.php`, asi que `function_exists('cfg_crm_guardar')` daba
   false y el sello se convertia en un no-op SILENCIOSO. El cron corria bien,
   contestaba ok, y el indicador marcaba "nunca corrio" igual.

   Es la misma clase de falla que el latido viene a detectar, cometida por el
   latido. De ahi el segundo chequeo. */
foreach (['api/difusiones_chat_procesar.php' => 'difusiones_visto_en',
          'api/ruleta_recordatorio.php'      => 'ruleta_aviso_visto_en'] as $arch => $clave) {
    $src = file_get_contents(__DIR__ . '/' . $arch);
    chequear(basename($arch) . ' deja su latido',
             str_contains($src, "'" . $clave . "'"));
    chequear(basename($arch) . ' PUEDE sellarlo (carga config_crm)',
             str_contains($src, "require_once __DIR__ . '/config_crm.php'")
             || str_contains($src, "require __DIR__ . '/config_crm.php'"),
             'sin eso function_exists da false y el sello es un no-op silencioso');
}

/* UNA PROMO APAGADA NO TIENE POR QUE CORRER. Sin esto el aviso saltaria por
   cada cosa que el dueño decidio no usar, y un canal que molesta por algo que
   esta bien es un canal que se deja de mirar. */
chequear('una tarea apagada no dispara aviso',
         str_contains($srcSalud, "'activa_si' => 'fid_activa'")
         && str_contains($srcSalud, "!cfg_crm_activo(\$pdo, \$lim['activa_si'])"));

/* Y que el aviso diga QUE HACER. Uno que dice "algo no corre" y nada mas se
   aprende a ignorar en dos dias. */
chequear('el aviso trae el arreglo concreto',
         str_contains($srcSalud, "'Qué hacer'          => \$lim['arreglo']")
         && str_contains($srcSalud, 'instalar-cron-fidelizacion.sh'));

/* El mismo bucle para las dos tablas: dos copias del chequeo es como se pierde
   una (paso con el detector del challenge, que estaba escrito cuatro veces). */
chequear('lecturas y tareas se revisan con el mismo bucle',
         str_contains($srcSalud, '$aRevisar[$k] = $lim + ')
         && substr_count($srcSalud, 'foreach ($aRevisar as $k => $lim)') === 1,
         'dos copias del chequeo es como se pierde una');

chequear('y salud_bot las muestra sin entrar al VPS',
         str_contains($srcBot, "'fidelizacion' => 'fid_visto_en'"));

/* EL AGUJERO QUE TENIA ESTE MISMO DISEÑO, y que es el caso de la
   fidelizacion: una tarea cuyo cron NUNCA se instalo se queda en "nunca
   corrio" para siempre, y "nunca" no dispara aviso -- porque el dia del
   deploy todas las fechas estan vacias y avisar ahi seria ruido.

   La fidelizacion solo se descubrio porque alguien la habia corrido UNA vez a
   mano y esa fecha envejecio. Sin esa casualidad, seguiria invisible.

   Se ancla con 'tareas_vigilando_desde': pasada su ventana desde que la
   empezamos a mirar, "nunca corrio" tambien avisa -- y con otro mensaje,
   porque el problema es otro: no es que se paro, es que nunca arranco. */
chequear('"nunca corrio" tambien envejece',
         str_contains($srcSalud, "'tareas_vigilando_desde'")
         && str_contains($srcSalud, 'NUNCA corrió'));
chequear('y se ancla en cuando lo empezamos a mirar',
         str_contains($srcSalud, "\$vigDesde < \$lim['min']"));
chequear("y 'tareas_vigilando_desde' esta en la lista blanca",
         str_contains($srcCfg, "'tareas_vigilando_desde'"));
/* Pero NO para las lecturas: una lectura sin fecha es el colector que todavia
   no reporto, y de eso ya avisa su propio indicador. */
chequear('una LECTURA sin fecha no dispara ese aviso',
         str_contains($srcSalud, "if (\$lim['lectura'] || \$vigDesde === null"));

/* ---------------------------------------------------------------------------
   UNA TAREA QUE SE PUEDE APAGAR TIENE QUE LLEVAR `activa_si`.

   Lo reporto Nahuel el 24/09/2026: le llegaba todo el tiempo "el aviso diario
   de la ruleta dejo de correr", y habia apagado la ruleta a proposito.

   Y era cierto que dejo de correr. ruleta_recordatorio.php sale en su primera
   linea util --si cfg_crm_activo('ruleta_activa') es false, exit-- asi que
   nunca llega a sellar su latido. El vigilante veia una fecha vieja para
   siempre. Al mirarlo, el latido tenia 5.842 minutos: cuatro dias avisando por
   algo que estaba bien.

   Es justo lo que este archivo viene a proteger: un canal que molesta por algo
   que esta bien se deja de mirar, y el dia que avise por algo de verdad lo van
   a ignorar igual.
--------------------------------------------------------------------------- */
chequear('el aviso de la ruleta no se vigila con la ruleta apagada',
         str_contains($srcSalud, "'activa_si' => 'ruleta_activa'"),
         'sin esto avisa para siempre por una promo que el dueño apago');

/* `difusiones` NO lleva `activa_si` y esta bien: no es una promo que se
   apague, su cron corre siempre y sella aunque no tenga nada que mandar. Se
   deja dicho para que nadie se lo agregue "por consistencia" y apague el
   unico aviso que cubre las difusiones programadas. */
$blqDif = substr($srcSalud, (int)strpos($srcSalud, "'difusiones' =>"), 420);
chequear('las difusiones siguen vigiladas siempre',
         !str_contains($blqDif, 'activa_si'),
         'su cron corre siempre y sella aunque no haya nada que mandar');

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
