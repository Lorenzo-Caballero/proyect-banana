/**
 * t_nota_chat.js — El sondeo no puede borrar la nota que estás escribiendo.
 *
 * EL BUG (Nahuel, 15/09/2026): "cuando intento agregar una nota, al momento en
 * el que la escribo, inmediatamente se borra. Si la escribo muy rápido y le doy
 * guardar, ahí sí funciona".
 *
 * La causa: la conversación abierta se refresca cada 9 segundos, cada refresco
 * repinta la ficha, y el repintado reescribía el textarea con lo que hay
 * GUARDADO en el server. Todo lo tipeado desde el último guardado se perdía en
 * el siguiente tick — y tipear rápido "funcionaba" porque ganabas la carrera.
 *
 * Es un bug con una forma peligrosa: no falla siempre, falla cuando tardás. O
 * sea que aparece justo en la nota larga, la que costó escribir.
 *
 * Se EXTRAE el bloque de landing/crm.html, no se copia.
 *
 *     node t_nota_chat.js
 */
const fs = require("fs");
const path = require("path");

const SRC = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8")
            .split("\r\n").join("\n");

let ok = 0, fallas = 0;
function chequear(que, cond, detalle) {
  if (cond) { ok++; console.log("  OK    " + que); }
  else { fallas++; console.log("  FALLA " + que + (detalle ? " -- " + detalle : "")); }
}

const DESDE = '    const _nt = $("#notas");';
const i = SRC.indexOf(DESDE);
if (i < 0) { console.error("No encontré el pintado de la nota en crm.html"); process.exit(1); }
const j = SRC.indexOf("\n    }\n", i);
if (j < 0) { console.error("No encontré el cierre del bloque de la nota"); process.exit(1); }
const FUENTE = SRC.slice(i, j + 6);

/**
 * Corre el repintado una vez.
 *  enPantalla  lo que hay ahora en el textarea
 *  delServer   lo que trae la respuesta del server
 *  notaServer  lo que el server había mandado la vez anterior
 *  conFoco     si el operador está escribiendo en ese momento
 */
function repintar({ enPantalla, delServer, notaServer, conFoco }) {
  const nt = { value: enPantalla };
  const st = { notaServer };
  const ctx = {
    $: () => nt,
    st,
    notas: delServer,
    document: { activeElement: conFoco ? nt : null },
  };
  const nombres = Object.keys(ctx);
  new Function(...nombres, FUENTE)(...nombres.map(k => ctx[k]));
  return { valor: nt.value, notaServer: st.notaServer };
}

console.log("\n=== 1. EL BUG: escribir y que el sondeo lo borre ===");
{
  /* El caso exacto. El operador venía de una nota vacía, tipeó "cuidado, a
     veces intenta hacer trampa" y todavía no guardó. Llega el tick. */
  const r = repintar({
    enPantalla: "cuidado, a veces intenta hacer trampa",
    delServer: "",
    notaServer: "",
    conFoco: false,
  });
  chequear("con cambios sin guardar, el sondeo NO lo pisa",
           r.valor === "cuidado, a veces intenta hacer trampa", r.valor);
}
{
  const r = repintar({
    enPantalla: "escribiendo…",
    delServer: "lo viejo",
    notaServer: "lo viejo",
    conFoco: true,
  });
  chequear("con el cursor puesto tampoco, aunque no haya cambios todavía",
           r.valor === "escribiendo…", r.valor);
}

console.log("\n=== 2. Cuando SÍ conviene refrescar ===");
{
  /* Sin foco y sin ediciones: no hay nada que perder, y otro agente pudo
     haberla cambiado. */
  const r = repintar({
    enPantalla: "lo viejo",
    delServer: "lo que escribió otro agente",
    notaServer: "lo viejo",
    conFoco: false,
  });
  chequear("sin foco y sin cambios, se trae lo del server",
           r.valor === "lo que escribió otro agente", r.valor);
}
{
  /* Primera pintada de una conversación: notaServer todavía no existe. No hay
     ediciones posibles, así que se pinta. */
  const r = repintar({
    enPantalla: "",
    delServer: "nota guardada",
    notaServer: undefined,
    conFoco: false,
  });
  chequear("al abrir la conversación se pinta la nota guardada",
           r.valor === "nota guardada", r.valor);
}

console.log("\n=== 3. La referencia queda al día ===");
{
  /* `notaServer` tiene que actualizarse SIEMPRE, incluso cuando no se pisa el
     textarea: es contra ese valor que se detecta "hay cambios sin guardar". Si
     quedara viejo, una nota que el operador nunca tocó parecería editada para
     siempre y no se refrescaría nunca más. */
  const r = repintar({
    enPantalla: "mi borrador",
    delServer: "lo que hay guardado",
    notaServer: "",
    conFoco: false,
  });
  chequear("aunque no se pise, se anota lo que mandó el server",
           r.notaServer === "lo que hay guardado", String(r.notaServer));
  chequear("y el borrador sigue intacto", r.valor === "mi borrador", r.valor);
}

console.log("\n=== 4. Una nota borrada a propósito ===");
{
  /* Vaciar el campo es una edición como cualquier otra: no se puede "corregir"
     volviendo a poner lo que había. */
  const r = repintar({
    enPantalla: "",
    delServer: "lo de antes",
    notaServer: "lo de antes",
    conFoco: false,
  });
  chequear("vaciarla a mano no se revierte sola", r.valor === "", r.valor);
}

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
