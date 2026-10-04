/**
 * t_registro_tenant.js — Dónde entra el alta y a dónde va el jugador.
 *
 * Son DOS decisiones distintas y es facilísimo confundirlas, porque las dos
 * salen del mismo pathname:
 *
 *   dónde se ENCOLA el alta   -> en qué base entra el pedido de cuenta
 *   a dónde se MANDA al jugador -> qué plataforma abre después
 *
 * Pedido del dueño (03/10/2026): *"en /<slug>/registro.html usá
 * /registro.html, pero que redirija a /<slug>"*. El motivo: el alta por la
 * raíz funciona --el bot de la plataforma crea en 2 segundos-- y la del
 * cliente no, porque su bot no puede loguearse.
 *
 * Si alguien más adelante "unifica" las dos, rompe una de las dos cosas:
 * o el alta vuelve a la cola muerta, o el jugador termina en la plataforma
 * equivocada. Por eso este test las mira por separado.
 *
 *     node t_registro_tenant.js
 */
"use strict";
const fs = require("fs");

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

const src = fs.readFileSync(__dirname + "/landing/registro.html", "utf8");

/* Se ejecuta la lógica REAL del archivo, no una copia: una copia se queda
   vieja justo cuando el original cambia. */
function resolver(pathname) {
  const m = src.match(
    /const partesRuta = location\.pathname[\s\S]*?const API = BASE_API \+ "\/crear_cuenta\.php";/
  );
  if (!m) { throw new Error("no encontré el bloque de resolución en registro.html"); }
  const destinoSrc = src.match(/const destino = .*?;/);
  if (!destinoSrc) { throw new Error("no encontré la línea del destino"); }

  const fn = new Function("location",
    m[0] + "\n" + destinoSrc[0] + "\nreturn { api: API, destino: destino };");
  return fn({ pathname: pathname });
}

// ===========================================================================
console.log("=== 1. El alta entra por el circuito que funciona ===");
const cli = resolver("/leandro/registro.html");
chequear("desde /leandro/registro.html el alta va a la raíz",
         cli.api === "/gp-api/crear_cuenta.php",
         "ahí está el bot que crea en 2 segundos; el del cliente no puede loguearse");

const raiz = resolver("/registro.html");
chequear("y desde la raíz, igual que siempre",
         raiz.api === "/gp-api/crear_cuenta.php");

// ===========================================================================
console.log("\n=== 2. Pero el jugador termina en SU plataforma ===");
/* Esta es la mitad que se pierde si alguien "simplifica" borrando esPorPath:
   el alta saldría igual y el jugador caería en la plataforma de la casa. */
chequear("desde /leandro/registro.html se lo manda a /leandro/",
         cli.destino === "/leandro/#gp-chat",
         "sin esto el jugador de un cajero termina en la plataforma de otro");
chequear("y desde la raíz, a la raíz",
         raiz.destino === "/#gp-chat");

// ===========================================================================
console.log("\n=== 3. Las dos decisiones siguen siendo separables ===");
/* El interruptor existe porque esto es una decisión de negocio, no una
   constante de la naturaleza: el día que cada cliente tenga su bot andando,
   se vuelve atrás cambiando false. */
chequear("hay un interruptor explícito para volver atrás",
         /const ALTAS_EN_RAIZ = true;/.test(src));
chequear("y el destino NO depende de él",
         /const destino = esPorPath \?/.test(src),
         "si el destino colgara de ALTAS_EN_RAIZ, apagarlo mandaría al jugador a la raíz");
chequear("está dicho que el jugador queda en nuestra base",
         /queda en NUESTRA base/.test(src),
         "es la consecuencia que va a aparecer como «no veo al jugador en mi CRM»");

// ===========================================================================
console.log("\n=== 4. Entra solo, con la sesión ya hecha ===");
/* EL BUG QUE ESTO FIJA: el auto-login excluía a los clientes por path
   (`if (esPorPath || ...) return false`), por un motivo que no aplicaba -- "el
   proxy no preserva el slug". El login no necesita el slug: /api/user/login es
   absoluto, va a LA MISMA plataforma para todos, y la sesión es una cookie del
   ORIGEN, que es el mismo para la raíz y para /<slug>/.

   El efecto era que el jugador de un cajero --justo el que menos sabe qué
   hacer-- aterrizaba en la pantalla de login con una cuenta recién creada,
   mientras el de la raíz entraba derecho. */
chequear("el auto-login ya no excluye a los clientes por path",
         !/if \(esPorPath \|\| !usuario \|\| !clave\)/.test(src),
         "el jugador de un cajero caía en la pantalla de login con la cuenta hecha");
chequear("y sigue cortando si falta usuario o clave",
         /if \(!usuario \|\| !clave\) return false;/.test(src));
chequear("el login va al endpoint de la plataforma, con la cookie del origen",
         /fetch\('\/api\/user\/login'/.test(src) && /credentials: 'include'/.test(src));

/* ENTRAR SOLO, PERO NO AL INSTANTE. En esta pantalla están el usuario y la
   contraseña, y es la ÚNICA vez que el jugador los ve: irse de inmediato lo
   deja adentro hoy y afuera mañana, cuando se cierre la sesión. */
chequear("la cuenta regresiva espera a que la sesión esté lista",
         /for \(let i = 0; i < 40 && !sesionLista; i\+\+\)/.test(src),
         "entrar sin sesión es mandarlo justo a la pantalla de login que esto evita");
chequear("y no arranca si el auto-login falló",
         /if \(!sesionLista \|\| yendo\) return;/.test(src));
chequear("le copia los datos antes de llevarlo",
         /clipboard\.writeText\(/.test(src) && /Contraseña: /.test(src));
chequear("y puede frenarla para anotarlos",
         /autoQuedarse/.test(src),
         "es la única pantalla donde ve su contraseña");
/* Un solo `yendo` para los dos caminos (el copiado manual y el reloj): si
   fueran dos, copiar mientras corre la cuenta dispararía dos navegaciones. */
chequear("un solo guard contra la doble navegación",
         (src.match(/let yendo = false;/g) || []).length === 1,
         "con dos, copiar durante la cuenta regresiva navega dos veces");

// La landing custom usa el mismo auto-login y debe llevar al juego integrado,
// nunca al chat interno que exige otra autenticación.
const lp = fs.readFileSync(__dirname + "/landing/lp.html", "utf8");
chequear("la landing custom permite auto-login también bajo /<slug>/",
         !/if \(esPorPath \|\| !usuario \|\| !clave\) return false;/.test(lp));
chequear("la landing custom abre el chat integrado de la plataforma del cliente",
         /const destino = esPorPath \? '\/' \+ partesRuta\[0\] \+ '\/#gp-chat' : '\/#gp-chat';/.test(lp));
const chat = fs.readFileSync(__dirname + "/landing/chat.html", "utf8");
chequear("el chat legacy ya no manda al acceso 404 del tenant",
         !/href="acceso\.html"/.test(chat) && /Ingresar a Ganamos/.test(chat));
const bono = fs.readFileSync(__dirname + "/landing/bono.html", "utf8");
chequear("la landing de bono también hace auto-login para clientes por ruta",
         !/if \(esPorPath \|\| !usuario \|\| !clave\) return false;/.test(bono));
chequear("y termina dentro de la plataforma del mismo cliente",
         bono.includes("const destino = esPorPath ? '/' + partesRuta[0] + '/#gp-chat' : '/#gp-chat';"));

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
