/**
 * t_bono_tipos.js — Las cuatro clases de bono del chat, y cuál se cobra cuándo.
 *
 * DE DÓNDE SALE (Nahuel, 16/09/2026): *"si le quiero dar un bono a alguien para
 * su próxima jugada, no me da la opción si quiero que sea en porcentaje o en
 * fichas regaladas"*. Venía de un caso real: le había cargado $35.000 de más a
 * un jugador por error y le ofreció por el chat *"un bono del 10% por las
 * molestias"* — justo lo que el modal no sabía hacer.
 *
 * El server ya sabía: `bonos_pendientes` (migración 33) con tipo fichas/pct/giro,
 * aplicados solos en la próxima recarga acreditada. Lo que faltaba era poder
 * elegirlo desde el chat, sin ir a Difusión a tipear el nombre del jugador.
 *
 * LO QUE CUIDA ESTE TEST, que es lo que duele si se rompe:
 *
 *  1. Los nombres de los tipos tienen que ser LOS DEL SERVER. `bono_crear`
 *     valida contra CRMNOTIF_BONO_TIPOS = ['fichas','pct','giro'] y contesta
 *     "Tipo de bono inválido". Un typo acá no rompe nada al cargar la página:
 *     falla recién cuando alguien intenta regalar algo.
 *
 *  2. `ahora` va por `cargar_bono` y los otros tres por `bono_crear`. Si se
 *     mezclan, un porcentaje prometido se convierte en fichas acreditadas al
 *     instante — o al revés, y el jugador nunca recibe lo que se le prometió.
 *
 *  3. El modal vuelve a `ahora` en cada apertura. Que recuerde la última
 *     elección es exactamente como se regala un 500% creyendo que son 500
 *     fichas.
 *
 * Se EXTRAE de landing/crm.html, no se copia.
 *
 *     node t_bono_tipos.js
 */
const fs = require("fs");
const path = require("path");

const SRC = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8")
            .split("\r\n").join("\n");
const PHP = fs.readFileSync(path.join(__dirname, "api", "crm_notificaciones.php"), "utf8");

let ok = 0, fallas = 0;
function chequear(que, cond, detalle) {
  if (cond) { ok++; console.log("  OK    " + que); }
  else { fallas++; console.log("  FALLA " + que + (detalle ? "   " + detalle : "")); }
}

/* ---- Se extraen la tabla y la función de pintado ---- */
const iTabla = SRC.indexOf("const BONO_TIPOS = {");
const iPintar = SRC.indexOf("function bonoTipoPintar(){");
const fPintar = SRC.indexOf('$("#mBonoTipos").innerHTML', iPintar);
if (iTabla < 0 || iPintar < 0 || fPintar < 0) {
  console.error("No encontré BONO_TIPOS / bonoTipoPintar en crm.html");
  process.exit(1);
}
const FUENTE = SRC.slice(iTabla, fPintar);

console.log("\n=== 1. Los tipos son los que el server acepta ===");

/* El `ahora` NO existe del lado del server: es el camino viejo (`cargar_bono`),
   que acredita al instante y no deja fila en `bonos_pendientes`. Los otros tres
   tienen que coincidir exactamente. */
const mPhp = PHP.match(/CRMNOTIF_BONO_TIPOS',\s*\[([^\]]+)\]/);
const delServer = mPhp ? mPhp[1].match(/'([a-z]+)'/g).map(s => s.replace(/'/g, "")) : [];
chequear("se leyó la lista del server", delServer.length === 3, JSON.stringify(delServer));

/* La tabla se evalúa de verdad: así se prueba el objeto, no un regex sobre él. */
const tabla = new Function(FUENTE.slice(0, FUENTE.indexOf("function bonoTipoPintar")) +
                           "; return BONO_TIPOS;")();
const claves = Object.keys(tabla);

chequear("existe el bono que se acredita al instante", claves.includes("ahora"));
for (const t of delServer) {
  chequear(`existe el tipo del server: ${t}`, claves.includes(t), JSON.stringify(claves));
}
chequear("y no hay ninguno inventado de más",
         claves.length === delServer.length + 1, JSON.stringify(claves));
for (const k of claves) {
  if (k === "ahora") continue;
  chequear(`"${k}" es un tipo que el server valida`, delServer.includes(k));
}

console.log("\n=== 2. Cada uno dice qué es, y el % da un ejemplo ===");
/* La ayuda no es decorativa: es lo único que separa "se acredita ya" de "se
   acredita cuando vuelva a cargar", que es toda la diferencia. */
for (const k of claves) {
  chequear(`"${k}" tiene texto de ayuda`, !!(tabla[k].ayuda || "").trim());
}
chequear("el porcentaje explica con un ejemplo en plata",
         /10\.000/.test(tabla.pct.ayuda) && /1\.000/.test(tabla.pct.ayuda),
         tabla.pct.ayuda);
chequear('los tres diferidos aclaran que NO se acredita ahora',
         ["pct", "fichas", "giro"].every(k => /No se acredita ahora|No espera/.test(tabla[k].ayuda)));
chequear('"ahora" dice que sí se acredita ya', /acreditan ya/.test(tabla.ahora.ayuda));
chequear("el campo del porcentaje NO se llama Cantidad",
         tabla.pct.label === "Porcentaje", tabla.pct.label);

console.log("\n=== 3. El pintado del formulario ===");

/** Corre bonoTipoPintar() con un DOM mínimo y devuelve cómo quedó. */
function pintar(tipo) {
  const nodo = () => ({ style: {}, classList: { toggle(){} }, textContent: "", placeholder: "", innerHTML: "" });
  const els = {
    "#mBonoAyuda": nodo(), "#mMontoLbl": nodo(), "#mMonto": nodo(),
    "#mMotivoLbl": nodo(), "#mMotivo": nodo(),
  };
  const ctx = {
    $: s => els[s],
    st: { bonoTipo: tipo },
    document: { querySelectorAll: () => [] },
  };
  const nombres = Object.keys(ctx);
  new Function(...nombres, FUENTE + "; bonoTipoPintar();")(...nombres.map(k => ctx[k]));
  return els;
}

{
  const e = pintar("giro");
  chequear("un giro esconde el campo de cantidad (no hay número que poner)",
           e["#mMonto"].style.display === "none" && e["#mMontoLbl"].style.display === "none");
}
{
  const e = pintar("pct");
  chequear("el porcentaje muestra el campo y lo renombra",
           e["#mMonto"].style.display === "" && e["#mMontoLbl"].textContent === "Porcentaje",
           e["#mMontoLbl"].textContent);
  /* `bonos_pendientes` guarda quién lo prometió, no por qué: un campo que se
     descarta en silencio es peor que un campo que no está. */
  chequear("y esconde el motivo, que el server no guardaría",
           e["#mMotivo"].style.display === "none");
}
{
  const e = pintar("ahora");
  chequear('"ahora" sí deja escribir el motivo',
           e["#mMotivo"].style.display === "" && e["#mMotivoLbl"].style.display === "");
  chequear("y el campo vuelve a llamarse Cantidad",
           e["#mMontoLbl"].textContent === "Cantidad", e["#mMontoLbl"].textContent);
}
{
  /* Un tipo desconocido (un data-bt mal escrito en el HTML) no puede tirar la
     página entera: el CRM es UN script y un TypeError acá lo mata todo. */
  const e = pintar("loquesea");
  chequear("un tipo desconocido cae en 'ahora' y no explota",
           e["#mMontoLbl"].textContent === "Cantidad");
}

console.log("\n=== 4. Por dónde sale cada uno ===");
/* Posicional sobre el handler: no se puede ejecutar sin media pantalla montada,
   pero la bifurcación es de una línea y es la que no puede equivocarse. */
const iOk = SRC.indexOf('$("#mOk").addEventListener("click"');
const fOk = SRC.indexOf("/* ---------- Notificaciones", iOk);
const HANDLER = SRC.slice(iOk, fOk);

chequear("la promesa es todo lo que no es 'ahora'",
         /const promesa = bt !== "ahora"/.test(HANDLER));
chequear("las promesas van por bono_crear",
         /promesa\s*\?\s*await jpost\(\{accion:"bono_crear"/.test(HANDLER));
chequear("y el resto sigue yendo por el camino de siempre",
         /:\s*await jpost\(\{accion:st\.accion/.test(HANDLER));
chequear("el giro se manda con valor 0 (el server lo ignora igual)",
         /valor: bt === "giro" \? 0 : monto/.test(HANDLER));
chequear("un porcentaje de más de 100 pide confirmación, no se bloquea",
         /bt === "pct" && monto > 100/.test(HANDLER) && /confirm\(/.test(HANDLER));

console.log("\n=== 5. El modal no recuerda la elección anterior ===");
/* Si la recordara, abrir el modal para regalar 500 fichas con "%"" todavía
   puesto regala un 500%. */
const iAbrir = SRC.indexOf("function abrirModal(accion, titulo, ganamos){");
const fAbrir = SRC.indexOf('$("#back").classList.add("on")', iAbrir);
const ABRIR = SRC.slice(iAbrir, fAbrir);
chequear("cada apertura vuelve a 'ahora'", /st\.bonoTipo = "ahora"/.test(ABRIR));
chequear("los chips solo se muestran en el modal de bono",
         /esBono = accion === "cargar_bono"/.test(ABRIR) &&
         /#mBonoTipos"\)\.style\.display = esBono/.test(ABRIR));
chequear("y los otros modales recuperan sus campos",
         /#mMotivoLbl"\)\.style\.display = ""/.test(ABRIR));

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
