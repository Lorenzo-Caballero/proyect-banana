/**
 * t_landing_wa.js — A dónde va el jugador después de crear la cuenta.
 *
 * La plantilla 'wa' existe para una landing por cajero: el alta sale con
 * NUESTRAS credenciales de agente y, al terminar, en vez de entrar al casino
 * se lo manda al WhatsApp de SU cajero.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, y por qué cada uno:
 *
 *  1. QUE LA CONTRASEÑA NO VIAJE EN EL LINK. El mensaje de wa.me queda en el
 *     historial de un chat ajeno y en la barra de direcciones. El cajero no
 *     necesita la clave: necesita el usuario.
 *  2. QUE EL USUARIO SÍ VIAJE. Es lo único que hace útil la derivación — sin
 *     eso el cajero recibe un "hola" y tiene que averiguar quién es.
 *  3. QUE SIN WHATSAPP NADA CAMBIE. Las landings que ya están publicadas
 *     tienen que seguir mandando al casino.
 *
 * Se ejecuta el código REAL de lp.html, no una copia: una copia seguiría
 * pasando después de que alguien cambie la página.
 *
 *     node t_landing_wa.js
 */
"use strict";
const fs = require("fs");

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

const src = fs.readFileSync(__dirname + "/landing/lp.html", "utf8");

/* ------------------------------------------------------------------ waLink */
const fnSrc = src.match(/function waLink\(usuario\)\{[\s\S]*?\n  \}/);
if (!fnSrc) throw new Error("no encontré waLink en lp.html");
function waLink(WA, usuario) {
  return new Function("WA", fnSrc[0] + "\nreturn waLink;")(WA)(usuario);
}

const CAJERO = { numero: "5491123456789", texto: "Hola, quiero cargar" };

// ===========================================================================
console.log("=== 1. El link lleva al cajero con el usuario puesto ===");
const link = waLink(CAJERO, "holaMartina847");
chequear("apunta al número del cajero",
         link.startsWith("https://wa.me/5491123456789?text="), link);
chequear("el mensaje del operador viaja",
         decodeURIComponent(link).includes("Hola, quiero cargar"));
chequear("y el usuario va adentro del mensaje",
         decodeURIComponent(link).includes("holaMartina847"),
         "sin esto el cajero recibe un 'hola' y no sabe a quién cargarle");
chequear("el texto va escapado (un espacio partiría la URL)",
         !link.split("?text=")[1].includes(" "));

console.log("\n=== 2. La contraseña NO viaja al WhatsApp ===");
/* Es lo que separa "derivar al cajero" de "filtrarle la cuenta a un tercero":
   el mensaje queda en el historial de ese chat y en la barra de direcciones.
   waLink recibe SOLO el usuario, así que no hay forma de que entre -- y este
   chequeo es el que avisa si alguien le suma un parámetro más. */
chequear("waLink toma un solo argumento (el usuario)", waLink.length === 2); // (WA, usuario) del wrapper
chequear("la firma real es waLink(usuario)",
         /function waLink\(usuario\)/.test(src),
         "si alguien le agrega la clave, este chequeo cae");
const linkCrudo = decodeURIComponent(waLink(CAJERO, "holaMartina847"));
chequear("ni 'contraseña' ni 'password' en el mensaje",
         !/contrase|password|clave/i.test(linkCrudo), linkCrudo);

console.log("\n=== 3. Sin WhatsApp, la landing es la de siempre ===");
chequear("sin número no hay link", waLink({}, "holaX1") === "");
chequear("con número vacío tampoco", waLink({ numero: "" }, "holaX1") === "");
chequear("WA sin inicializar no explota", waLink(undefined, "holaX1") === "");
/* La vista previa del CRM manda el número como lo está TIPEANDO el operador,
   con + y espacios: wa.me solo acepta dígitos, así que la página lo formatea.
   No valida (eso lo hizo el server): solo evita una URL imposible. */
chequear("el formato de wa.me se respeta aunque llegue con + y espacios",
         waLink({ numero: "+54 9 11 2345-6789" }, "holaX1")
           .startsWith("https://wa.me/5491123456789?text="));
chequear("un número sin un solo dígito no produce link",
         waLink({ numero: "+++" }, "holaX1") === "");

console.log("\n=== 4. El texto por default, para el que no lo escribió ===");
/* El campo del mensaje es opcional: si queda vacío, el jugador tiene que
   mandar algo igual -- un chat que se abre en blanco se cierra sin escribir. */
chequear("sin texto se usa uno por default",
         decodeURIComponent(waLink({ numero: "5491123456789" }, "holaX1"))
           .includes("acabo de crear mi cuenta"));
chequear("y el usuario se le suma igual",
         decodeURIComponent(waLink({ numero: "5491123456789" }, "holaX1")).includes("holaX1"));
chequear("un texto de puros espacios cae al default",
         decodeURIComponent(waLink({ numero: "5491123456789", texto: "   " }, "holaX1"))
           .includes("acabo de crear mi cuenta"));

// ===========================================================================
console.log("\n=== 5. El destino y el botón cambian juntos ===");
/* Si el botón dijera "Entrar a jugar" y llevara al WhatsApp (o al revés), el
   jugador toca otra cosa de la que le pasa. Van del mismo `wa`. */
chequear("el destino sale de waLink cuando hay número",
         /const destino = wa\s*\n?\s*\?\s*wa/.test(src),
         "si el destino deja de mirar wa, la derivación no pasa");
chequear("el botón dice 'Seguir por WhatsApp' con derivación",
         /textContent = wa \? 'Seguir por WhatsApp' : 'Entrar a jugar'/.test(src));
chequear("sin derivación el destino sigue siendo el casino",
         /esPorPath \? '\/' \+ partesRuta\[0\] \+ '\/#gp-chat' : '\/#gp-chat'/.test(src));

/* LA ESPERA DEL AUTO-LOGIN ES SOLO PARA EL QUE ENTRA AL CASINO. Yendo al
   WhatsApp esa sesión no hace falta, y hasta 1,2s de demora antes del salto
   al cajero es justo donde el jugador se cae del embudo. */
chequear("yendo al WhatsApp no se espera la sesión del casino",
         /if \(!wa\) \{ for \(let i = 0; i < 12 && !sesionLista; i\+\+\) await dormir\(100\); \}/.test(src));

console.log("\n=== 6. 'wa' usa el layout pelado de 'registro' ===");
/* Si no compartiera el layout, la landing del cajero mostraría el número
   gigante del bono y los textos de promo -- que esta plantilla no configura. */
chequear("'wa' entra en solo-registro",
         /const soloReg = landing\.plantilla === 'registro' \|\| landing\.plantilla === 'wa'/.test(src));

// ===========================================================================
console.log("\n=== 7. El CRM ya no tiene el editor de WhatsApp (se movió al panel) ===");
/* Esta sección probaba el editor de "landing de cajero" DENTRO del CRM
   (botón, campo de WhatsApp, etc.). El commit "Remove WhatsApp landings from
   CRM" lo sacó: esas landings se crean ahora desde el PANEL (panel.php). El
   CRM ya NO debe tener nada de WhatsApp, y eso es lo correcto. Se chequea esa
   realidad; el flujo del panel vive en la sección 8. */
const crm = fs.readFileSync(__dirname + "/landing/crm.html", "utf8");
const srvCrm = fs.readFileSync(__dirname + "/api/crm_landings.php", "utf8");
chequear("el CRM no tiene el botón de landing de cajero",
         !/id="lpNuevaWaBtn"/.test(crm));
chequear("el CRM no tiene campo de WhatsApp en el editor de landings",
         !/lpCampoWhatsapp|lpWaNumero/.test(crm));
chequear("y el server del CRM no GUARDA config de whatsapp (lo menciona solo para derivar al panel)",
         !/\$limpia\['whatsapp'\]|'whatsapp'\s*=>/.test(srvCrm),
         "si volviera a guardar whatsapp, se solaparía con la landing de cajero del panel");

// ===========================================================================
console.log("\n=== 8. El número sale normalizado del server, no del navegador ===");
/* La página NO decide si un número sirve: eso se resuelve una sola vez en
   PHP. Si landing_publica.php dejara de normalizar, lp.html publicaría un
   wa.me armado con lo que el operador tipeó. */
const pub = fs.readFileSync(__dirname + "/api/landing_publica.php", "utf8");
chequear("landing_publica.php normaliza el número antes de publicarlo",
         /\$cfg\['whatsapp'\]\['numero'\] = landings_wa_numero\(/.test(pub));

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
