/**
 * t_registro_tenant.js — Dónde entra el alta y a dónde va el jugador.
 *
 * Son DOS decisiones distintas y es facilísimo confundirlas, porque las dos
 * salen del mismo pathname:
 *
 *   dónde se ENCOLA el alta   -> en qué base entra el pedido de cuenta
 *   a dónde se MANDA al jugador -> qué plataforma abre después
 *
 * El tenant decide si usa su cola/agente propios o si conserva el circuito
 * global anterior. El destino de la plataforma sigue siendo una decisión
 * independiente.
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

/* Se ejecuta el helper REAL de la página, no una copia de la lógica. */
const helper = src.match(/function baseApiRegistro\(esPath, slug, altasPropias\) \{[\s\S]*?\n  \}/);
if (!helper) throw new Error("no encontré baseApiRegistro en registro.html");
const baseApi = new Function(helper[0] + "\nreturn baseApiRegistro;")();
const destinoSrc = src.match(/const destino = .*?;/);
if (!destinoSrc) throw new Error("no encontré la línea del destino");
function destino(pathname) {
  return new Function("esPorPath", "partesRuta", destinoSrc[0] + "\nreturn destino;")(
    pathname.split("/").filter(Boolean).length >= 2,
    pathname.split("/").filter(Boolean)
  );
}

// ===========================================================================
console.log("=== 1. El cliente elige la cola y agente propios ===");
chequear("Leandro con altas propias usa su endpoint tenant",
         baseApi(true, "leandro", true) === "/leandro/gp-api");
chequear("los clientes sin la opción conservan el circuito global",
         baseApi(true, "otro", false) === "/gp-api");
chequear("el registro de la raíz conserva su endpoint",
         baseApi(false, "", true) === "/gp-api");
chequear("la página resuelve el destino desde tenant_info",
         /tenant_info\.php/.test(src) && /d\.altas_propias === true/.test(src));
chequear("si no puede verificar tenant_info, no envía el alta a otra base",
         /if \(!\(await API_LISTO\) \|\| !API\)/.test(src));
chequear("espera la resolución antes de encolar el alta",
         /await API_LISTO/.test(src) && /fetch\(API,/.test(src));

const cliente = { ruta: destino("/leandro/registro.html") };
const raiz = { ruta: destino("/registro.html") };

// ===========================================================================
console.log("\n=== 2. Pero el jugador termina en SU plataforma ===");
/* Esta es la mitad que se pierde si alguien "simplifica" borrando esPorPath:
   el alta saldría igual y el jugador caería en la plataforma de la casa. */
chequear("desde /leandro/registro.html se lo manda a /leandro/",
         cliente.ruta === "/leandro/#gp-chat",
         "sin esto el jugador de un cajero termina en la plataforma de otro");
chequear("y desde la raíz, a la raíz",
         raiz.ruta === "/#gp-chat");

// ===========================================================================
console.log("\n=== 3. Las dos decisiones siguen siendo separables ===");
/* El destino del registro puede variar por tenant; el destino final al juego
   conserva la ruta pública del mismo cliente. */
chequear("la configuración de cola no altera el destino del jugador",
         /const destino = esPorPath \?/.test(src));
chequear("el alta propia es una opción por cliente en control",
         /altas_propias/.test(src) && /tenant_info\.php/.test(src));

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
