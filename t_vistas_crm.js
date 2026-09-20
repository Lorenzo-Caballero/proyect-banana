/* t_vistas_crm.js -- que cada pantalla del rail quede VISIBLE al abrirla.
 *
 * EL BUG (Nahuel, 18/09/2026): "crm.html#/peticiones no carga nada". No era
 * el endpoint ni el routing -- los datos se pedian bien y el titulo de la
 * pestaña hasta cambiaba. La pantalla se mostraba y se volvia a ocultar en la
 * MISMA pasada:
 *
 *   VISTA_CONTENEDOR tiene DOS rutas apuntando al mismo contenedor
 *   (`peticiones` y `retiros`, desde que las dos pantallas se fusionaron), y
 *   el switch recorria por NOMBRE DE RUTA. Con nombre="peticiones", la vuelta
 *   de `peticiones` ponia display:flex y la de `retiros` ponia display:none
 *   sobre el mismo nodo. Gana la ultima escritura.
 *
 * Por eso #/retiros "andaba" (su clave va ultima) y #/peticiones no, ni desde
 * el rail. Un alias de ruta es algo que se va a volver a agregar --las
 * pantallas se fusionan cada tanto y los links viejos tienen que seguir
 * funcionando--, asi que esto queda blindado.
 *
 * NO COPIA EL CODIGO: lo EXTRAE de landing/crm.html y lo ejecuta. Una copia
 * pegada aca se desincroniza el dia que alguien toca el original, que es
 * justo el dia en que este test tendria que avisar.
 *
 *     node t_vistas_crm.js
 */
const fs = require("fs");
const path = require("path");

const src = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8");

let ok = 0, fallas = 0;
const chequear = (q, c, d = "") => {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fallas++; console.log("  FALLA " + q + "   " + d); }
};

/* ---- extraer del HTML real: la lista de vistas, el mapa y el switch ---- */
const mVistas = src.match(/const VISTAS = \[[^\]]*\];/);
const mMapa   = src.match(/const VISTA_CONTENEDOR = \{[\s\S]*?\n  \};/);
/* El switch: desde donde se resuelve el contenedor activo hasta el cierre del
   forEach. Se ancla en `new Set(Object.values(` -- que ES el arreglo: si
   alguien vuelve al recorrido por clave, este match falla y el test grita. */
const mSwitch = src.match(/const selActivo = VISTA_CONTENEDOR\[nombre\];[\s\S]*?\n    \}\);/);

chequear("se encuentra VISTAS en crm.html", !!mVistas);
chequear("se encuentra VISTA_CONTENEDOR", !!mMapa);
chequear("el switch resuelve el contenedor ANTES de recorrer",
         !!mSwitch,
         "si volvio a ser Object.entries(...).forEach(([nom,sel])=>...), el bug esta de vuelta");

if (!mVistas || !mMapa || !mSwitch) {
  console.log("\nNo se pudo extraer el dispatcher: el resto no se puede probar.");
  console.log("\n---------------------------------------");
  console.log(ok + " OK, " + (fallas + 1) + " fallas");
  process.exit(1);
}

/* `display` de cada selector, como lo dejaria el navegador. */
const displays = {};
const $ = (sel) => (displays[sel] = displays[sel] || { style: {} });

const VISTAS = eval(mVistas[0].replace("const VISTAS =", "") .replace(/;$/, ""));
const VISTA_CONTENEDOR = eval("(" + mMapa[0].replace("const VISTA_CONTENEDOR =", "").replace(/;$/, "") + ")");

/** Corre el switch REAL de crm.html para una vista. */
function mostrar(nombre) {
  for (const k of Object.keys(displays)) { delete displays[k]; }
  eval(mSwitch[0]);
  return displays;
}

/* ---- 1. cada vista con contenedor propio queda visible ---- */
console.log("\n=== Abrir cada pantalla del rail la deja VISIBLE ===");
for (const nombre of VISTAS) {
  const sel = VISTA_CONTENEDOR[nombre];
  if (!sel) { continue; }   // "chats" no tiene contenedor propio: son las 3 columnas
  const d = mostrar(nombre);
  chequear(`#/${nombre} muestra ${sel}`,
           d[sel] && d[sel].style.display === "flex",
           `quedo en "${d[sel] ? d[sel].style.display : "(no tocado)"}"`);
}

/* ---- 2. el caso exacto del reporte: dos rutas, un contenedor ---- */
console.log("\n=== Dos rutas al mismo contenedor (el bug del 18/09) ===");
const alias = {};
for (const [nom, sel] of Object.entries(VISTA_CONTENEDOR)) {
  (alias[sel] = alias[sel] || []).push(nom);
}
const compartidos = Object.entries(alias).filter(([, noms]) => noms.length > 1);
chequear("hay al menos un contenedor con varias rutas (si no, el test no prueba nada)",
         compartidos.length > 0,
         "si se quitaron los alias, este bloque quedo sin cubrir");
for (const [sel, noms] of compartidos) {
  for (const nom of noms) {
    const d = mostrar(nom);
    chequear(`#/${nom} -> ${sel} visible (comparte ruta con ${noms.filter(n => n !== nom).join(", ")})`,
             d[sel] && d[sel].style.display === "flex",
             `quedo en "${d[sel] ? d[sel].style.display : "(no tocado)"}"`);
  }
}

/* ---- 3. y las demas quedan ocultas (no se apilan dos pantallas) ---- */
console.log("\n=== Las otras pantallas quedan ocultas ===");
const d = mostrar("peticiones");
const visibles = Object.entries(d).filter(([, n]) => n.style.display === "flex").map(([s]) => s);
chequear("abriendo Peticiones queda UN solo contenedor visible",
         visibles.length === 1 && visibles[0] === "#viewPeticiones",
         JSON.stringify(visibles));
const dc = mostrar("chats");
const visiblesChats = Object.entries(dc).filter(([, n]) => n.style.display === "flex");
chequear("en Chats no queda ningun contenedor de modulo visible",
         visiblesChats.length === 0,
         JSON.stringify(visiblesChats.map(([s]) => s)));

/* ---- 4. el bug cosmetico de los titulos filtrados ---- */
console.log("\n=== Los titulos de seccion se pueden ocultar ===");
/* toggleAttribute("hidden") no alcanza cuando la clase declara display:flex:
   el [hidden]{display:none} del navegador pierde por especificidad. */
chequear("existe la regla .pt-sec-tit[hidden]",
         /\.pt-sec-tit\[hidden\]\s*\{\s*display:\s*none/.test(src),
         "sin ella, filtrar por Depositos o Retiros deja los dos titulos");

console.log("\n---------------------------------------");
console.log(ok + " OK, " + fallas + " fallas");
process.exit(fallas ? 1 : 0);
