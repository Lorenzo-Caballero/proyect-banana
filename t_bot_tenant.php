<?php
/**
 * t_bot_tenant.php — El bot de un cliente no puede comer de la cola de otro.
 *
 * EL INCIDENTE (Nahuel, 29/09/2026): *"la landing creó usuarios que cuando los
 * busqué en ganamos no aparecían"* — holabeto299 y
 * holacarmendaianasoledadgomez491 — *"apareció luego de enlazar a Leandro"*.
 *
 * Los jugadores SÍ existían. Bajo otro agente. El panel lo venía diciendo, con
 * el id de nuestro agente adentro del error del depósito:
 *
 *     "User ID 20284777 is not in user ID 39252159 structure"
 *
 * LA CADENA. El bot de altas de un cliente sale apuntado a
 * `https://<dominio>[/<slug>]/gp-api/altas_cola.php`, y esa URL es la que
 * decide DE QUÉ COLA saca trabajo. Sus credenciales, en cambio, son las del
 * cliente. Si la URL resuelve a otro tenant, saca altas de esa cola y las crea
 * en SU cuenta de agente: el jugador existe, no aparece en el panel de quien lo
 * busca, y ninguna carga suya sale nunca.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN:
 *
 *  1. QUE UN DESTINO AJENO FRENE EL BOT, no que lo avise. Desde el 24/09 esto
 *     ya avisaba por Telegram y volvió a pasar igual: el aviso llega, el bot
 *     arranca lo mismo y sigue creando cuentas en el lugar equivocado.
 *  2. QUE NO SE BLOQUEE POR NO PODER PREGUNTAR. Una red caída no es evidencia
 *     de que la URL apunte mal; dejar sin bot a todos los clientes por eso
 *     sería peor que el problema.
 *  3. QUE CON LA BASE COMPARTIDA NO SE ELIJA UNA CREDENCIAL AL AZAR.
 *  4. QUE EL ERROR DEL PANEL SE RECONOZCA, que es lo que hace que la próxima
 *     vez se encuentre en minutos y no en días.
 *
 *     php t_bot_tenant.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$prov = file_get_contents(__DIR__ . '/panel/provisionar.php');
$acc  = file_get_contents(__DIR__ . '/api/acciones_cola.php');
$db   = file_get_contents(__DIR__ . '/api/db.php');
$reg  = file_get_contents(__DIR__ . '/landing/registro.html');
$mig  = file_get_contents(__DIR__ . '/panel/sql/13_altas_propias.sql');

// ===========================================================================
echo "=== 1. Un destino ajeno FRENA el bot (no solo avisa) ===\n";
chequear('existe la guarda destino_inseguro()',
         str_contains($prov, 'function destino_inseguro('));
/* LOS DOS bots del cliente. El de altas crea jugadores en la cuenta
   equivocada; el de sync le vuelca el padrón de otro en su base. */
chequear('el bot de ALTAS la consulta antes de levantarse',
         (bool)preg_match('/\$name = \'altas-\' \. \$slug;\s*(\/\*.*?\*\/\s*)?\$mal = destino_inseguro/s', $prov),
         'sin esto, el bot de un cliente crea los jugadores de otro en su propia cuenta');
chequear('el bot de SYNC también',
         (bool)preg_match('/\$name = \'bot-\' \. \$slug;\s*(\/\*.*?\*\/\s*)?\$mal = destino_inseguro/s', $prov));
/* Frenar tiene que BAJAR el que ya está corriendo: si solo se evitara
   levantarlo, el que arrancó mal ayer sigue trabajando para siempre. */
chequear('frenar BAJA el contenedor que ya existía',
         str_contains($prov, 'function frenar_bot_mal_apuntado(')
         && str_contains($prov, 'docker rm -f'),
         'evitar levantarlo no alcanza: el que ya arrancó mal sigue creando cuentas');
chequear('y avisa con clave propia (no se tapa con el dedupe de los otros)',
         str_contains($prov, "'bot_mal_apuntado:' . \$slug"));

// ===========================================================================
echo "\n=== 1b. Altas aisladas por cliente ===\n";
chequear('la migracion conserva el comportamiento actual por defecto',
         str_contains($mig, 'altas_propias TINYINT(1) NOT NULL DEFAULT 0'));
chequear('la resolucion de ruta toma el modo de la fila del tenant',
         str_contains($db, 'c.altas_propias') && str_contains($db, "\$GLOBALS['TENANT_ALTAS_PROPIAS']"));
chequear('el registro usa su API solo cuando el tenant lo habilita',
         str_contains($reg, 'd.altas_propias === true')
         && str_contains($reg, 'baseApiRegistro(true, slug, d.altas_propias === true)'));
chequear('el bot omite el override global para el agente propio',
         str_contains($prov, '!$altasPropias && $userGlobal !== \'\' && $passGlobal !== \''));
chequear('el bot de altas recibe el modo desde el registro de control',
         str_contains($prov, 'agente_password, altas_propias'));

// ===========================================================================
echo "\n=== 2. Los dos caminos que llevan al destino ajeno ===\n";
chequear('db_nombre compartido con otro cliente activo',
         str_contains($prov, 'isset(bases_compartidas()[$db])'));
chequear('y otro cliente reclamando el mismo punto de entrada',
         str_contains($prov, 'function duenos_del_destino(')
         && str_contains($prov, '$otros = array_values(array_diff($duenos, [$slug]))'));
/* EL CASO QUE COSTO CARO: un cliente cargado con NUESTRO dominio y sin
   path_tenant reclama la raiz, que es nuestra puerta. */
chequear('el caso raíz (dominio nuestro + path_tenant 0) se consulta aparte',
         str_contains($prov, "COALESCE(path_tenant,0) = 0\"")
         || str_contains($prov, 'COALESCE(path_tenant,0) = 0'));

/* LA VERSION ANTERIOR DE ESTO TENIA UN FALSO POSITIVO QUE HABRIA HECHO MUCHO
   DAÑO: comparaba el slug devuelto por tenant_info.php contra el del cliente,
   y db.php deja TENANT_SLUG VACIO para los de dominio propio --que es el caso
   normal, no el raro--. Daba distinto siempre: les habria bajado el bot a
   todos ellos. Por eso la resolucion se hace contra la base, no por HTTP. */
chequear('la verificación NO depende de una llamada HTTP',
         !str_contains($prov, 'tenant_de_url') && !str_contains($prov, '/gp-api/tenant_info.php'),
         'comparar el slug de tenant_info le baja el bot a todo cliente de dominio propio');
chequear('y un cliente de dominio propio, solo suyo, NO se frena',
         str_contains($prov, 'array_diff($duenos, [$slug])'),
         'si el único dueño del destino es él mismo, no hay con quién competir');

// ===========================================================================
echo "\n=== 3. No se bloquea por no poder preguntar ===\n";
/* Una red caída, un dominio que todavía no propagó, el server reiniciando:
   nada de eso prueba que la URL apunte mal. Solo bloquea la respuesta que
   dice, en letras, que ese endpoint es de otro tenant. */
chequear('si no se puede preguntar, devuelve null',
         str_contains($prov, '// no poder preguntar no frena a nadie'));
chequear('y null NO frena el bot',
         str_contains($prov, 'if (is_array($duenos) && $duenos)'),
         'un error de consulta no puede dejar sin bot a todos los clientes');

// ===========================================================================
echo "\n=== 4. Un cliente con la cola trabada se nota ===\n";
/* PASÓ EL 01/10/2026: la cola de `leandro` tenía 2 altas, la más vieja de 42
   minutos, con `intentos: 0` y sin un mensaje -- nadie las había tocado nunca,
   porque su bot no existía. El jugador se registró y nunca tuvo cuenta.

   EL AVISO YA EXISTÍA Y NO ALCANZABA: alta_avisar_trabadas() detecta este caso
   desde el 04/09/2026 y lo describe bien, pero avisa por el Telegram DEL
   TENANT donde corre, y un cliente recién dado de alta no lo tiene
   configurado. El aviso se generaba y no lo recibía nadie. */
chequear('provisionar revisa la cola de altas de cada cliente',
         str_contains($prov, 'ALTAS COLGADAS')
         && str_contains($prov, "estado IN ('pendiente', 'procesando')"),
         'el aviso del propio tenant no llega: un cliente nuevo no tiene Telegram');
chequear('y avisa por NUESTRO lado, que es el único que puede levantar el bot',
         str_contains($prov, "'altas_colgadas'"));
/* Las dos situaciones se arreglan en lugares distintos y el aviso tiene que
   decir cuál es: sin contenedor (faltan credenciales) vs. con contenedor que
   no saca trabajo (sesión, API key, WAF). */
chequear('distingue "no hay bot" de "el bot no toma trabajo"',
         str_contains($prov, "docker ps -q --filter")
         && str_contains($prov, 'no se reinicia, pero no saca trabajo'));
chequear('y nombra el arreglo concreto cuando faltan las credenciales',
         str_contains($prov, 'Configuracion -> Integracion con ganamos'),
         'un aviso que dice "algo no anda" se aprende a ignorar en dos días');
/* La clave de dedupe lleva el slug y el ESTADO, no la cantidad: con el número
   adentro, cada alta nueva volvería a sonar. */
/* La clave lleva el slug y el ESTADO del bot, nunca la cantidad: con el número
   adentro, cada alta nueva volvería a sonar. */
chequear('la clave del aviso no incluye la cantidad',
         !preg_match('/altas_colgadas:.*\$n\b/', $prov)
         && str_contains($prov, "'altas_colgadas:' . \$slug . ':'"),
         'con el número en la clave, cada alta nueva es un aviso nuevo');
/* Las que no tienen password no cuentan: no se pueden crear igual, y mezclarlas
   haría sonar el aviso por algo que este arreglo no resuelve. */
chequear('solo cuenta las altas que el bot PUEDE crear',
         str_contains($prov, 'AND password IS NOT NULL'));
chequear('y deja pasar el backoff normal antes de avisar',
         str_contains($prov, 'ALTAS_COLGADAS_MIN'),
         'avisar a los 2 minutos sería avisar del backoff, que es el sistema funcionando');
chequear('el bot propio no se vigila por acá (ya tiene lo suyo)',
         str_contains($prov, 'tiene_bot_propio($slug)) { continue; }'));

/* "EXISTE" NO ES "FUNCIONA". Un contenedor con `--restart unless-stopped` que
   arranca, se muere y Docker relanza aparece en `docker ps` como si estuviera
   sano: `Up 19 seconds` sobre un `Created 2 days ago`. Pasó el 03/10/2026 --el
   bot de un cliente llevaba dos días así-- y este mismo aviso decía
   "bot: existe", que es lo contrario de lo que había que mirar. */
chequear('se distingue un bot sano de uno que se reinicia solo',
         str_contains($prov, 'RestartCount') && str_contains($prov, '$enBucle'),
         'docker ps muestra "Up 19 seconds" igual para un bot sano y uno en crash loop');
chequear('y el arreglo que se sugiere es otro en ese caso',
         str_contains($prov, 'SE ESTA REINICIANDO SOLO')
         && str_contains($prov, 'el panel le rechaza el login'),
         'mandar a mirar la sesión cuando el problema es la credencial pierde el tiempo');
/* El estado entra en la clave de dedupe: si el bot pasa de "no existe" a
   "reiniciándose", eso es una novedad y tiene que volver a sonar. */
chequear('el estado del bot entra en la clave del aviso',
         str_contains($prov, "'altas_colgadas:' . \$slug . ':' . \$comoEsta"));
chequear('hacen falta varios reinicios para llamarlo bucle',
         str_contains($prov, '$reinicios >= 3'),
         'un reinicio suelto es un deploy, no un bot roto');

// ===========================================================================
echo "\n=== 5. Con la base compartida no se elige una credencial al azar ===\n";
/* El otro lado del mismo problema: nuestro bot pidiendo las credenciales del
   panel. Un LIMIT 1 sobre dos clientes que comparten db_nombre devuelve
   cualquiera de los dos, al azar del orden del índice -- y logueado como el
   agente equivocado crea NUESTRAS altas en la cuenta de ese otro. */
chequear('la consulta de credenciales ya no lleva LIMIT 1',
         !preg_match('/FROM clientes\s+WHERE db_nombre = \? LIMIT 1/', $acc),
         'con dos filas, LIMIT 1 elige una al azar y el bot se loguea como el agente de otro');
chequear('con más de una fila no se entrega ninguna',
         str_contains($acc, 'if (count($filas) > 1) {')
         && str_contains($acc, 'no se entrega ninguna credencial'));
chequear('y queda registrado cuál base era',
         str_contains($acc, "clientes activos comparten"));
chequear('el bot cae a su .env, que es el comportamiento de siempre',
         str_contains($acc, 'se queda con las de su .env'));

// ===========================================================================
echo "\n=== 6. El error del panel se reconoce y se avisa ===\n";
/* El mensaje REAL que quedó guardado en producción. Si el reconocimiento se
   rompe, este test lo dice antes que un jugador. */
$real = 'deposito por API (200) {"status":234,"result":{},"error_message":'
      . '"User ID 20284777 is not in user ID 39252159 structure"}';
chequear('el mensaje real de producción matchea el patrón',
         (bool)preg_match('/user id (\d+) is not in user id (\d+) structure/i', $real, $m)
         && $m[1] === '20284777' && $m[2] === '39252159',
         'es el único error que prueba que el jugador no cuelga de nuestro agente');
chequear('acciones_cola lo detecta al marcar el error',
         str_contains($acc, "stripos(\$mensaje, 'is not in user id') !== false"));
chequear('avisa con su propia clave, no como un error de carga más',
         str_contains($acc, "'jugador_de_otro_agente:' . \$quien"),
         'reintentar no lo arregla: lo que hay que revisar es a qué tenant apunta cada bot');
chequear('y nombra al jugador afectado (uno por jugador, no por reintento)',
         str_contains($acc, "'Jugador'  => \$quien"));
/* Solo sobre 'error': un mensaje parecido en una carga que salió bien no
   tiene que disparar el aviso. */
chequear('solo se dispara con estado error',
         str_contains($acc, "if (\$estado === 'error' && stripos"));

// ===========================================================================
printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
