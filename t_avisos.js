/**
 * t_avisos.js — Los avisos prioritarios: se sacan de encima y no vuelven.
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026), en tres pasos:
 *   1. *"que aparezcan unos segundos y luego se vayan, no que queden constantes"*
 *   2. probando la versión que los encogía a un chip: *"quiero que aparezcan una
 *      vez cuando inicia sesión y luego se vayan, porque en mobile es molesto"*
 *   3. y la aclaración que ordenó todo: **el cartel es para SUS CLIENTES**, los
 *      agentes que todavía no configuraron su CRM. *"está bueno que le salga el
 *      aviso, pero que lo pueda deslizar hacia arriba o que aparezca durante dos,
 *      tres segundos y desaparezca"*.
 *
 * O sea: tiene que verse —es lo que le avisa al cliente nuevo que su plataforma
 * no funciona todavía— pero tiene que poder sacarse de encima al instante.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es donde esto se rompe:
 *
 *  1. **Que deslizar no navegue.** El aviso es clickeable (lleva a la sección
 *     que lo resuelve). Si un deslizamiento contara como toque, intentar
 *     sacarlo de encima te mandaría a Configuración — lo contrario de lo que
 *     quisiste hacer.
 *  2. **Que las tres formas de irse dejen el MISMO estado.** Reloj, cruz y
 *     deslizamiento: si una no marcara "ya se vio", el aviso volvería en el
 *     próximo repintado por haberse ido "de la forma equivocada".
 *  3. **Que el repintado no lo reviva** — `gpAvisosRevisar()` corre cada 30 s
 *     mientras el espejo se llena.
 *  4. **Que el reloj no se reinicie en cada repintado**, o el que se repinta
 *     cada 30 s no se iría nunca.
 *  5. **Que si el problema se resuelve y VUELVE, se vea otra vez.**
 *
 * Se EXTRAE de landing/crm.html, no se copia.
 *
 *     node t_avisos.js
 */
const fs = require("fs");
const path = require("path");

const SRC = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8")
            .split("\r\n").join("\n");

let ok = 0, fallas = 0;
function chequear(que, cond, detalle) {
  if (cond) { ok++; console.log("  OK    " + que); }
  else { fallas++; console.log("  FALLA " + que + (detalle ? "   " + detalle : "")); }
}

const DESDE = "  const GP_AVISO_SEG = 5;";
const HASTA = "  let gpAvisoTimer = null;";
const i = SRC.indexOf(DESDE), j = SRC.indexOf(HASTA, i);
if (i < 0 || j < 0) { console.error("No encontré gpAvisoPintar en crm.html"); process.exit(1); }
const FUENTE = SRC.slice(i, j);

/**
 * DOM y sessionStorage mínimos.
 *  `sesion` se comparte entre montajes para simular "la misma pestaña".
 *  `storageRoto` simula la ventana privada, donde leer TIRA.
 */
function montar(sesion, storageRoto) {
  const nodos = {};
  const pendientes = [];
  const nuevo = (id) => {
    const oyentes = {};
    return {
      id, innerHTML: "", onclick: null,
      style: { cssText: "", opacity: "", transform: "", transition: "" },
      addEventListener(ev, fn) { (oyentes[ev] = oyentes[ev] || []).push(fn); },
      _disparar(ev, e) { (oyentes[ev] || []).forEach(f => f(e)); },
      remove() { delete nodos[this.id]; },
    };
  };
  const ctx = {
    gpAvisosContenedor: () => ({ prepend(){}, appendChild(){} }),
    document: { getElementById: (id) => nodos[id] || null, createElement: () => ({}) },
    sessionStorage: {
      getItem(k) { if (storageRoto) throw new Error("bloqueado"); return sesion[k] ?? null; },
      setItem(k, v) { if (storageRoto) throw new Error("bloqueado"); sesion[k] = v; },
      removeItem(k) { if (storageRoto) throw new Error("bloqueado"); delete sesion[k]; },
    },
    setTimeout: (fn, ms) => { pendientes.push({ fn, ms }); return pendientes.length; },
    clearTimeout: () => {},
  };
  const nombres = Object.keys(ctx);
  const pintarReal = new Function(...nombres, FUENTE + "; return gpAvisoPintar;")
                     (...nombres.map(k => ctx[k]));

  return {
    pintar(id, html, tipo, alClick) {
      if (html && !nodos[id]) { nodos[id] = nuevo(id); }
      pintarReal(id, html, tipo, alClick);
      return nodos[id] || null;
    },
    correrRelojes() {
      for (let v = 0; v < 4 && pendientes.length; v++) {
        pendientes.splice(0, pendientes.length).forEach(x => x.fn());
      }
    },
    cuantosRelojes: () => pendientes.length,
    nodo: (id) => nodos[id] || null,
    /** Simula el gesto: apoyar, arrastrar `px` y soltar. */
    deslizar(id, px) {
      const n = nodos[id];
      n._disparar("touchstart", { touches: [{ clientY: 300 }] });
      n._disparar("touchmove",  { touches: [{ clientY: 300 + px }] });
      n._disparar("touchend", {});
    },
  };
}

const HTML_INTEG = '<b>⚠️ Integrá tu panel de ganamos</b> — sin esto no se crean ' +
                   'cuentas, no se cargan fichas y tus jugadores no se espejan.';

console.log("\n=== 1. Se ve al entrar y se va solo ===");
{
  const s = {};
  const m = montar(s);
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("al entrar se ve el texto", el.innerHTML.includes("Integrá tu panel de ganamos"));
  chequear("y trae la cruz para sacarlo ya", el.innerHTML.includes('data-cerrar="1"'));
  m.correrRelojes();
  chequear("pasados los segundos DESAPARECE", m.nodo("gpAvisoInteg") === null);
  chequear("y queda anotado que se vio", s["gp_aviso_gpAvisoInteg"] === "1");
}

console.log("\n=== 2. Deslizar hacia arriba lo saca ===");
{
  const s = {};
  const m = montar(s);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.deslizar("gpAvisoInteg", -60);
  m.correrRelojes();                       // el fundido
  chequear("deslizando hacia arriba se va", m.nodo("gpAvisoInteg") === null);
  chequear("y cuenta como visto (no vuelve)", s["gp_aviso_gpAvisoInteg"] === "1");
}
{
  /* Un movimiento chico es un temblor de dedo, no una intención. */
  const m = montar({});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.deslizar("gpAvisoInteg", -8);
  chequear("un movimiento chico NO lo saca", m.nodo("gpAvisoInteg") !== null);
}
{
  /* Hacia abajo no hace nada: el gesto es hacia arriba. */
  const m = montar({});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.deslizar("gpAvisoInteg", 80);
  chequear("deslizar hacia abajo tampoco", m.nodo("gpAvisoInteg") !== null);
}

console.log("\n=== 3. DESLIZAR NO PUEDE NAVEGAR ===");
/* El aviso lleva a la sección que lo resuelve. Si el deslizamiento contara como
   toque, sacarlo de encima te mandaría a Configuración -- lo contrario de lo
   que quisiste hacer. */
{
  let fue = 0;
  const m = montar({});
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => { fue++; });
  m.deslizar("gpAvisoInteg", -60);
  if (el.onclick) el.onclick({ target: {} });
  chequear("después de deslizar, el toque no navega", fue === 0, "navegó " + fue + " vez");
}
{
  let fue = 0;
  const m = montar({});
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => { fue++; });
  el.onclick({ target: {} });
  chequear("pero un toque normal SÍ navega", fue === 1);
}

console.log("\n=== 4. La cruz lo saca, y no navega ===");
{
  let fue = 0;
  const s = {};
  const m = montar(s);
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => { fue++; });
  el.onclick({
    target: { getAttribute: (k) => (k === "data-cerrar" ? "1" : null) },
    stopPropagation() {},
  });
  m.correrRelojes();
  chequear("tocar la cruz lo saca", m.nodo("gpAvisoInteg") === null);
  chequear("y no navega", fue === 0);
  chequear("y cuenta como visto", s["gp_aviso_gpAvisoInteg"] === "1");
}

console.log("\n=== 5. El repintado no lo revive ===");
/* gpAvisosRevisar() corre cada 30 s mientras el espejo se llena. */
{
  const m = montar({});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("no vuelve después de repintar", m.nodo("gpAvisoInteg") === null);
}
{
  /* Y lo mismo si se fue deslizando: las tres formas de irse tienen que dejar
     el mismo estado, o volvería por haberse ido "de la forma equivocada". */
  const m = montar({});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.deslizar("gpAvisoInteg", -60);
  m.correrRelojes();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("tampoco vuelve si se fue deslizado", m.nodo("gpAvisoInteg") === null);
}

console.log("\n=== 6. El reloj no se reinicia en cada repintado ===");
/* Si cada pasada armara un timer nuevo, el aviso que se repinta cada 30 s no se
   iría NUNCA: siempre habría un reloj recién empezado. */
{
  const m = montar({});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  const tras1 = m.cuantosRelojes();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("tres repintados dejan UN solo reloj",
           m.cuantosRelojes() === tras1, `${tras1} -> ${m.cuantosRelojes()}`);
}

console.log("\n=== 7. Vuelve en la sesión siguiente, no en esta ===");
{
  const s = {};
  const m1 = montar(s);
  m1.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m1.correrRelojes();
  /* Recargar la página --el celular lo hace solo al volver de un juego-- es el
     MISMO sessionStorage: no tiene que volver a salir. */
  const m2 = montar(s);
  m2.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("recargar la página no lo trae de vuelta", m2.nodo("gpAvisoInteg") === null);
  const m3 = montar({});
  chequear("en una sesión nueva vuelve a verse",
           m3.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {}) !== null);
}

console.log("\n=== 8. Si se resuelve y VUELVE, se ve otra vez ===");
{
  const s = {};
  const m = montar(s);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  m.pintar("gpAvisoInteg", "", "rojo", null);                   // resuelto
  chequear("al resolverse se olvida que ya se vio",
           s["gp_aviso_gpAvisoInteg"] === undefined, JSON.stringify(s));
  chequear("y si el problema vuelve, se ve de nuevo",
           m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {}) !== null);
}

console.log("\n=== 9. Con el storage bloqueado, se muestra igual ===");
/* Ventana privada: leer sessionStorage TIRA. Mostrarlo de más es molesto; no
   mostrarlo nunca es dejar al cliente sin sistema sin saber por qué. */
{
  const m = montar({}, true);
  chequear("se ve igual",
           m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {}) !== null);
  m.correrRelojes();
  chequear("y se va igual", m.nodo("gpAvisoInteg") === null);
}

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
