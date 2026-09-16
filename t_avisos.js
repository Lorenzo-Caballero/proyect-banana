/**
 * t_avisos.js — Los avisos prioritarios se muestran UNA vez por sesión.
 *
 * EL PEDIDO (Nahuel, 16/09/2026), en dos pasos: primero *"que aparezcan unos
 * segundos y luego se vayan, no que queden constantes"*, y después —probando la
 * versión que los encogía a un chip— *"pero quiero que aparezcan una vez cuando
 * inicia sesión y luego se vayan, porque en mobile es molesto"*.
 *
 * Tenía razón las dos veces: en una pantalla de teléfono cualquier cosa fija
 * arriba se come el espacio útil, y un chip permanente sigue siendo permanente.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es donde esto se rompe:
 *
 *  1. **Que el repintado no los reviva.** `gpAvisosRevisar()` corre cada 30 s
 *     mientras el espejo se llena. Sin cuidado, el cartel reaparecería solo cada
 *     medio minuto — justo lo que se vino a sacar.
 *
 *  2. **Que el reloj no se reinicie en cada repintado.** Si cada pasada armara
 *     un timer nuevo, el aviso que se repinta cada 30 s no se iría nunca.
 *
 *  3. **Que si el problema se resuelve y VUELVE, se vea otra vez.** Son las dos
 *     cosas sin las cuales la plataforma no funciona; si el "ya lo vio" quedara
 *     pegado, a quien le borran las credenciales no le avisaría nadie.
 *
 *  4. **Que sobreviva a un storage bloqueado.** En una ventana privada leer
 *     sessionStorage tira excepción. Mostrarlo de más es molesto; no mostrarlo
 *     nunca es dejar al cliente sin sistema sin saber por qué.
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

const DESDE = "  const GP_AVISO_SEG = 9;";
const HASTA = "  let gpAvisoTimer = null;";
const i = SRC.indexOf(DESDE), j = SRC.indexOf(HASTA, i);
if (i < 0 || j < 0) { console.error("No encontré gpAvisoPintar en crm.html"); process.exit(1); }
const FUENTE = SRC.slice(i, j);

/**
 * Un DOM y un sessionStorage mínimos.
 *  `storageRoto` simula la ventana privada, donde leer TIRA.
 *  `sesion` se comparte entre montajes para simular "la misma pestaña".
 */
function montar(sesion, storageRoto) {
  const nodos = {};
  const pendientes = [];
  const nuevo = (id) => ({
    id, style: { cssText: "", opacity: "" }, innerHTML: "", onclick: null,
    remove() { delete nodos[this.id]; },
  });
  const ctx = {
    gpAvisosContenedor: () => ({ prepend(){}, appendChild(){} }),
    document: {
      getElementById: (id) => nodos[id] || null,
      createElement: () => ({}),
    },
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
    /** Corre los relojes pendientes (el de los 9 s y el del fundido). */
    correrRelojes() {
      for (let v = 0; v < 3 && pendientes.length; v++) {
        const p = pendientes.splice(0, pendientes.length);
        p.forEach(x => x.fn());
      }
    },
    cuantosRelojes: () => pendientes.length,
    nodo: (id) => nodos[id] || null,
  };
}

const HTML_INTEG = '<b>⚠️ Integrá tu panel de ganamos</b> — sin esto no se crean ' +
                   'cuentas, no se cargan fichas y tus jugadores no se espejan.';

console.log("\n=== 1. Se ve al entrar, y después se va ===");
{
  const s = {};
  const m = montar(s);
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("al entrar se ve entero", el.innerHTML === HTML_INTEG);
  chequear("y ocupa el ancho", el.style.cssText.includes("width:100%"));

  m.correrRelojes();
  chequear("pasados los segundos, DESAPARECE", m.nodo("gpAvisoInteg") === null);
  chequear("y queda anotado que ya se vio", s["gp_aviso_gpAvisoInteg"] === "1");
}

console.log("\n=== 2. EL REPINTADO NO LO REVIVE ===");
/* gpAvisosRevisar() corre cada 30 s mientras el espejo se llena. Sin esto el
   cartel reaparecería solo cada medio minuto. */
{
  const s = {};
  const m = montar(s);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});        // el de los 30 s
  chequear("no vuelve después de repintar", m.nodo("gpAvisoInteg") === null);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("ni después de tres repintados más", m.nodo("gpAvisoInteg") === null);
}

console.log("\n=== 3. El reloj no se reinicia en cada repintado ===");
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
  m.correrRelojes();
  chequear("y ese reloj lo saca", m.nodo("gpAvisoInteg") === null);
}

console.log("\n=== 4. Misma sesión: no reaparece. Sesión nueva: sí ===");
{
  const s = {};
  const m1 = montar(s);
  m1.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m1.correrRelojes();

  /* Recargar la página (el celular lo hace solo al volver de un juego) es el
     MISMO sessionStorage: no tiene que volver a salir. */
  const m2 = montar(s);
  m2.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("recargar la página no lo trae de vuelta", m2.nodo("gpAvisoInteg") === null);

  /* Cerrar la pestaña y volver a entrar: sessionStorage nuevo. */
  const m3 = montar({});
  const el = m3.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("en una sesión nueva vuelve a verse", el !== null && el.innerHTML === HTML_INTEG);
}

console.log("\n=== 5. Si se resuelve y VUELVE, se ve otra vez ===");
/* Son las dos cosas sin las cuales la plataforma no funciona: si el "ya lo vio"
   quedara pegado, a quien le borran las credenciales no le avisaría nadie. */
{
  const s = {};
  const m = montar(s);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  chequear("se fue", m.nodo("gpAvisoInteg") === null);

  m.pintar("gpAvisoInteg", "", "rojo", null);                    // resuelto
  chequear("al resolverse se olvida que ya se vio",
           s["gp_aviso_gpAvisoInteg"] === undefined, JSON.stringify(s));

  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("si el problema vuelve, se ve de nuevo",
           el !== null && el.innerHTML === HTML_INTEG);
}

console.log("\n=== 6. Los dos avisos son independientes ===");
{
  const HTML_COBRO = '<b>⚠️ Cargá tu cuenta de cobro</b> — tus jugadores no tienen a dónde transferir.';
  const s = {};
  const m = montar(s);
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.pintar("gpAvisoCobro", HTML_COBRO, "rojo", () => {});
  chequear("los dos se ven al entrar",
           m.nodo("gpAvisoInteg") !== null && m.nodo("gpAvisoCobro") !== null);
  m.correrRelojes();
  chequear("y los dos se van", m.nodo("gpAvisoInteg") === null && m.nodo("gpAvisoCobro") === null);
  chequear("cada uno con su marca",
           s["gp_aviso_gpAvisoInteg"] === "1" && s["gp_aviso_gpAvisoCobro"] === "1");
}

console.log("\n=== 7. Con el storage bloqueado, se muestra igual ===");
/* Ventana privada: leer sessionStorage TIRA. Mostrarlo de más es molesto; no
   mostrarlo nunca es dejar al cliente sin sistema sin saber por qué. */
{
  const m = montar({}, true);
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("se ve igual", el !== null && el.innerHTML === HTML_INTEG);
  m.correrRelojes();
  chequear("y se va igual", m.nodo("gpAvisoInteg") === null);
  const m2 = montar({}, true);
  chequear("sin poder recordar, vuelve a mostrarse (el lado seguro)",
           m2.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {}) !== null);
}

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
