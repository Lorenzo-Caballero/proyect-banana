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

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
