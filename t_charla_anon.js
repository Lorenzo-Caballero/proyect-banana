/**
 * t_charla_anon.js — La charla no se borra cuando el jugador se identifica.
 *
 * LO QUE PASÓ (Nahuel, 05/10/2026): *"apenas le da el nombre de usuario y
 * contraseña la ventana del chat se limpia y se borra todo"*.
 *
 * La charla se guarda con el dueño adentro, y al restaurarla el widget
 * comparaba `d.u !== USUARIO` sin distinguir dos casos muy distintos:
 *
 *   otro jugador        -> correcto descartarla, son dos personas
 *   el mismo, un segundo después -> hay que ADOPTARLA
 *
 * El segundo es justo el del alta por chat: el jugador entra anónimo
 * (USUARIO=""), pide la cuenta, el bot se la crea, el widget pasa a
 * USUARIO="holaXXX" — y la charla queda "de otro". Se borraba la pantalla
 * entera EN EL MOMENTO EXACTO en que acababa de recibir sus credenciales, que
 * es la única vez que las ve.
 *
 * Es la misma regla que el servidor ya aplica en crm_adoptar_anon().
 *
 *     node t_charla_anon.js
 */
"use strict";
const fs = require("fs");

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

const src = fs.readFileSync(__dirname + "/landing/widget.js", "utf8");

/* Se ejecuta la decisión REAL del widget, extraída del archivo: una copia de
   la lógica se queda vieja justo cuando el original cambia. */
const m = src.match(/var dueno = d\.u \|\| "";[\s\S]*?\n    \}/);
if (!m) { console.log("  FALLA no encontré el bloque de adopción en widget.js"); process.exit(1); }

/** Devuelve {mostrar, guardado} corriendo el código real. */
function restaurarCon(dueno, usuario) {
  const d = { u: dueno, charla: [{ q: "bot", t: "hola" }] };
  let guardado = null;
  const fn = new Function("d", "USUARIO", "lss", "gpChatKey", "JSON",
    m[0] + "\nreturn { mostrar: true, d: d };");
  /* El código real hace `return false` cuando no se adopta, así que la
     función puede devolver un booleano O el objeto: hay que distinguirlos, no
     leerle `.mostrar` a un false (da undefined y el test miente). */
  const r = fn(d, usuario, (k, v) => { guardado = JSON.parse(v); }, () => "k", JSON);
  return { mostrar: r !== false && !!r.mostrar, guardado: guardado };
}

// ===========================================================================
console.log("=== 1. El caso del alta por chat ===");
/* Entra anónimo, pide cuenta, el bot se la crea: USUARIO pasa de "" a holaXXX
   en el mismo segundo. La charla --con sus credenciales adentro-- es suya. */
const alta = restaurarCon("", "holaMartina847");
chequear("una charla anónima se ADOPTA al identificarse",
         alta.mostrar === true,
         "acá se borraban las credenciales justo cuando se las acababan de dar");
chequear("y se re-guarda con el dueño nuevo",
         alta.guardado && alta.guardado.u === "holaMartina847",
         "sin re-guardar, la próxima restauración la vuelve a perder");
chequear("sin perder lo que se había hablado",
         alta.guardado && Array.isArray(alta.guardado.charla) && alta.guardado.charla.length === 1);

// ===========================================================================
console.log("\n=== 2. Pero la de OTRO jugador sigue sin mostrarse ===");
/* Acá sí son dos personas: el que entra no tiene por qué ver la conversación
   del anterior, ni sus credenciales. */
chequear("la charla de otro usuario con nombre NO se adopta",
         restaurarCon("holaPedro12", "holaMartina847").mostrar === false,
         "sería mostrarle a alguien la conversación de otro");
chequear("ni al revés: identificado que pasa a anónimo",
         restaurarCon("holaPedro12", "").mostrar === false,
         "cerrar sesión no puede dejar la charla del anterior a la vista");

// ===========================================================================
console.log("\n=== 3. Lo de siempre sigue igual ===");
chequear("el mismo usuario ve su charla",
         restaurarCon("holaMartina847", "holaMartina847").mostrar === true);
chequear("y el anónimo la suya",
         restaurarCon("", "").mostrar === true);

// ===========================================================================
console.log("\n=== 4. La regla está escrita donde se entiende ===");
chequear("se nombra el espejo del servidor (crm_adoptar_anon)",
         /crm_adoptar_anon/.test(src),
         "es la misma decisión de los dos lados: conviene que se vea");
chequear("y el caso que lo motivó queda documentado",
         /se limpia y se borra\s*\n?\s*\* todo|acababa de recibir su usuario y su contraseña/.test(src));

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
