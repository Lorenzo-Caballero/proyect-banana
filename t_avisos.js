/**
 * t_avisos.js — Los avisos prioritarios se encogen, pero no se van.
 *
 * EL PEDIDO (Nahuel, 16/09/2026): *"hay dos carteles que aparecen como avisos
 * importantes... quiero que aparezcan durante unos segundos pero que luego se
 * vayan. Que no queden constantes porque son molestos"*.
 *
 * Tenía razón, pero desaparecer del todo sería peor: son las dos cosas sin las
 * cuales su plataforma no funciona — sin credenciales no se crea ni una cuenta,
 * y sin cuenta de cobro nadie le puede pagar. Un cliente que se olvida de
 * cargarlas no tiene sistema y no sabe por qué.
 *
 * Por eso se ENCOGEN a un chip en vez de irse. Y eso tiene ESTADO, que es donde
 * este tipo de cosa se rompe:
 *
 *  - `gpAvisosRevisar()` repinta cada 30 s mientras el espejo se llena. Si el
 *    repintado no respetara el encogido, el cartel volvería a tamaño completo
 *    cada medio minuto — o sea, peor que antes de "arreglarlo".
 *  - Si el problema se RESUELVE y después vuelve (le borran las credenciales),
 *    tiene que volver a verse entero. Si el estado quedara pegado, reaparecería
 *    ya encogido y nadie lo vería.
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

/** Un DOM mínimo: lo único que importa es qué queda en el elemento. */
function montar() {
  const nodos = {};
  const nuevo = (id) => ({
    id, style: { cssText: "" }, innerHTML: "", title: "", onclick: null,
    remove() { delete nodos[this.id]; },
  });
  const cont = { prepend(){}, appendChild(){} };
  const ctx = {
    gpAvisosContenedor: () => cont,
    document: { getElementById: (id) => nodos[id] || null },
    esc: (s) => String(s),
    setTimeout: (fn, ms) => { (montar._pend = montar._pend || []).push({ fn, ms }); return montar._pend.length; },
    clearTimeout: () => {},
  };
  montar._pend = [];
  const nombres = Object.keys(ctx);
  const api = new Function(...nombres,
    FUENTE + "; return {pintar: gpAvisoPintar, chico: gpAvisoChico};"
  )(...nombres.map(k => ctx[k]));

  return {
    pintar(id, html, tipo, alClick) {
      if (html && !nodos[id]) { nodos[id] = nuevo(id); }
      api.pintar(id, html, tipo, alClick);
      return nodos[id] || null;
    },
    correrRelojes() { const p = montar._pend; montar._pend = []; p.forEach(x => x.fn()); },
    nodo: (id) => nodos[id] || null,
  };
}

const HTML_INTEG = '<b>⚠️ Integrá tu panel de ganamos</b> — sin esto no se crean ' +
                   'cuentas, no se cargan fichas y tus jugadores no se espejan. ' +
                   '<u>Cargar credenciales</u>';

console.log("\n=== 1. Primero se ve entero ===");
{
  const m = montar();
  const el = m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("arranca con el texto completo", el.innerHTML === HTML_INTEG);
  chequear("y ocupa el ancho (es un cartel, no un chip)",
           el.style.cssText.includes("width:100%"), el.style.cssText.slice(0, 60));
  chequear("sin title, porque el texto ya está a la vista", el.title === "");
}

console.log("\n=== 2. Después se encoge, no se va ===");
{
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  const el = m.nodo("gpAvisoInteg");
  chequear("sigue existiendo (no desaparece)", !!el);
  chequear("ahora es un chip", el.style.cssText.includes("border-radius:999px"),
           el.style.cssText.slice(0, 80));
  chequear("ya no ocupa el ancho", !el.style.cssText.includes("width:100%"));
  chequear("muestra el titular y nada más",
           el.innerHTML.includes("Integrá tu panel de ganamos")
           && !el.innerHTML.includes("no se crean cuentas"), el.innerHTML);
  /* Encogerlo no puede perder la explicación de por qué importa. */
  chequear("el texto entero queda en el title",
           el.title.includes("no se crean cuentas"), el.title);
  chequear("y el title dice que se puede tocar", el.title.includes("Tocá para resolverlo"));
}

console.log("\n=== 3. El click sigue funcionando encogido ===");
{
  let clicks = 0;
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => { clicks++; });
  m.correrRelojes();
  const el = m.nodo("gpAvisoInteg");
  el.onclick();
  chequear("achicarse no le cuesta un paso más al que quiere arreglarlo", clicks === 1);
  chequear("y se sigue viendo clickeable", el.style.cssText.includes("cursor:pointer"));
}

console.log("\n=== 4. EL REPINTADO NO LO AGRANDA DE NUEVO ===");
/* gpAvisosRevisar() repinta cada 30 s mientras el espejo se llena. Sin este
   cuidado, el cartel volvería a tamaño completo cada medio minuto -- peor que
   antes de "arreglarlo". */
{
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});   // el repintado de los 30 s
  const el = m.nodo("gpAvisoInteg");
  chequear("sigue encogido después de repintar",
           el.style.cssText.includes("border-radius:999px"), el.style.cssText.slice(0, 80));
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  chequear("y después de repintar tres veces más",
           m.nodo("gpAvisoInteg").style.cssText.includes("border-radius:999px"));
}

console.log("\n=== 5. Si se resuelve y VUELVE, se ve entero otra vez ===");
/* Si el estado quedara pegado, un cliente al que le borran las credenciales
   vería el aviso ya encogido y no se enteraría. */
{
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();
  chequear("encogido", m.nodo("gpAvisoInteg").style.cssText.includes("999px"));

  m.pintar("gpAvisoInteg", "", "rojo", null);          // resuelto: se va
  chequear("al resolverse desaparece", m.nodo("gpAvisoInteg") === null);

  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});  // volvió el problema
  const el = m.nodo("gpAvisoInteg");
  chequear("si el problema vuelve, se ve ENTERO de nuevo",
           el.innerHTML === HTML_INTEG && el.style.cssText.includes("width:100%"),
           el.style.cssText.slice(0, 60));
}

console.log("\n=== 6. Los dos avisos son independientes ===");
{
  const HTML_COBRO = '<b>⚠️ Cargá tu cuenta de cobro</b> — tus jugadores no tienen a dónde transferir.';
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INTEG, "rojo", () => {});
  m.correrRelojes();                                   // solo se encoge el primero
  m.pintar("gpAvisoCobro", HTML_COBRO, "rojo", () => {});
  chequear("el de integración quedó chico",
           m.nodo("gpAvisoInteg").style.cssText.includes("999px"));
  chequear("y el de cobro arranca entero",
           m.nodo("gpAvisoCobro").innerHTML === HTML_COBRO);
  chequear("cada uno con su propio titular",
           m.nodo("gpAvisoInteg").innerHTML.includes("panel de ganamos"));
}

console.log("\n=== 7. El informativo también se encoge, y sin el ⚠️ ===");
/* "Trayendo tus jugadores…" no es un problema, es una espera: no puede gritar
   como los otros dos. */
{
  const HTML_INFO = '<span class="gp-spin"></span><b>Trayendo tus jugadores desde ganamos…</b> ' +
                    'La primera sincronización puede demorar unos minutos.';
  const m = montar();
  m.pintar("gpAvisoInteg", HTML_INFO, "info", null);
  m.correrRelojes();
  const el = m.nodo("gpAvisoInteg");
  chequear("se encoge igual", el.style.cssText.includes("999px"));
  chequear("pero sin el signo de alerta", !el.innerHTML.includes("⚠️"), el.innerHTML);
  chequear("y sin cursor de clickeable", el.style.cssText.includes("cursor:default"));
}

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
