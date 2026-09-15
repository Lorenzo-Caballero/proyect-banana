/**
 * t_exportar_chat.js — Llevarse una conversación como texto.
 *
 * Lo pidió Nahuel el 15/09/2026: poder exportar el chat de un jugador desde los
 * tres puntos.
 *
 * SE PRUEBA PORQUE ES UNA CONSTANCIA. Un export de un chat se usa para pasarle
 * el caso a otro agente, para guardar qué se le prometió a alguien, o para
 * revisar una discusión de plata fuera del CRM. Un export que se come mensajes
 * —o que esconde los que se borraron— es peor que no tener export: da una
 * seguridad que no corresponde.
 *
 * Lo que garantiza:
 *   - salen TODOS los mensajes, en orden;
 *   - los eliminados aparecen COMO eliminados, no desaparecen;
 *   - las notas internas quedan marcadas (no son cosas que el jugador leyó);
 *   - se distingue quién dijo cada cosa: el jugador, el bot, o qué agente.
 *
 * Se EXTRAE la función de landing/crm.html, no se copia.
 *
 *     node t_exportar_chat.js
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

const DESDE = "  function exportarChat(){";
const i = SRC.indexOf(DESDE);
if (i < 0) { console.error("No encontré exportarChat() en crm.html"); process.exit(1); }
const j = SRC.indexOf("\n  }\n", i);
if (j < 0) { console.error("No encontré el cierre de exportarChat()"); process.exit(1); }
const FUENTE = SRC.slice(i, j + 4);

/* ------------------------------------------------------------------ */
function correr(msgs, usuario) {
  let guardado = null, nombre = null;
  const avisos = [];

  const ctx = {
    st: { msgs, usuario },
    toast: (t) => avisos.push(t),
    horaExacta: (f) => String(f || "").slice(0, 16),
    // Lo mínimo del navegador que la función toca.
    Blob: function (partes) { guardado = partes.join(""); },
    URL: { createObjectURL: () => "blob:x", revokeObjectURL: () => {} },
    document: {
      createElement: () => ({
        set download(v) { nombre = v; },
        get download() { return nombre; },
        href: "", click() {}, remove() {},
      }),
      body: { appendChild() {} },
    },
  };

  const nombres = Object.keys(ctx);
  new Function(...nombres, FUENTE + "\nexportarChat();")(...nombres.map(k => ctx[k]));
  return { texto: guardado, nombre, avisos };
}

const MSGS = [
  { rol: "user",   texto: "hola, cargué 1000",        creado_en: "2026-09-15 03:10:00" },
  { rol: "bot",    texto: "¡Listo! Ya te acredité",   creado_en: "2026-09-15 03:11:00" },
  { rol: "agente", texto: "le avisé por teléfono",    creado_en: "2026-09-15 03:12:00",
    operador: "nahuel", interno: true },
  { rol: "agente", texto: "esto lo borré",            creado_en: "2026-09-15 03:13:00",
    operador: "nahuel", borrado_en: "2026-09-15 03:14:00" },
];

console.log("\n=== 1. Sale la conversación entera ===");
{
  const r = correr(MSGS, "holajuan969");
  chequear("se genera el archivo", typeof r.texto === "string" && r.texto.length > 0);
  chequear("con el nombre del jugador en el encabezado",
           /Conversación con holajuan969/.test(r.texto));
  chequear("dice cuántos mensajes son", /Mensajes: 4/.test(r.texto), r.texto.slice(0, 120));
  chequear("y están los cuatro",
           ["cargué 1000", "Ya te acredité", "por teléfono", "eliminado"]
             .every(s => r.texto.includes(s)));
}

console.log("\n=== 2. Quién dijo cada cosa ===");
{
  const r = correr(MSGS, "holajuan969");
  chequear("el jugador aparece con su usuario", /holajuan969:/.test(r.texto));
  chequear("el bot aparece como Bot", /\bBot:/.test(r.texto));
  chequear("el agente, con su nombre", /nahuel/.test(r.texto));
}

console.log("\n=== 3. Lo que un export NO puede esconder ===");
{
  const r = correr(MSGS, "holajuan969");
  /* Un mensaje eliminado tiene que figurar COMO eliminado: si desapareciera,
     el export contaría una conversación que no fue la que pasó. */
  chequear("un mensaje eliminado se marca, no se omite",
           /\[mensaje eliminado\]/.test(r.texto), r.texto);
  chequear("y NO sale su texto original",
           !r.texto.includes("esto lo borré"), r.texto);
  /* Una nota interna nunca la leyó el jugador: mezclarla con lo que sí leyó
     haría creer que se le dijo algo que no se le dijo. */
  chequear("la nota interna queda marcada como interna",
           /nota interna/.test(r.texto), r.texto);
}

console.log("\n=== 4. El nombre del archivo ===");
{
  const r = correr(MSGS, "hola juan/969");
  chequear("lleva el usuario y la fecha",
           /^chat_hola_juan_969_\d{4}-\d{2}-\d{2}\.txt$/.test(r.nombre || ""), r.nombre);
  chequear("sin caracteres que rompan un nombre de archivo",
           !/[\/\\:*?"<>|]/.test(r.nombre || ""), r.nombre);
}

console.log("\n=== 5. Sin mensajes no genera un archivo vacío ===");
{
  const r = correr([], "holajuan969");
  chequear("no escribe nada", r.texto === null);
  chequear("y lo dice", r.avisos.some(a => /No hay mensajes/.test(a)),
           JSON.stringify(r.avisos));
}

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
