/**
 * t_signo_auditoria.js — En Auditoría, lo que suma no se puede ver como resta.
 *
 * EL BUG (16/09/2026). Nahuel abrió Auditoría para contestar exactamente
 * *"¿a este jugador le cargamos dos veces?"*, y la fila de SU PROPIA carga de
 * +$35.000 le decía **-35.000**. La pantalla hecha para no tener que creerle a
 * nadie contestaba al revés.
 *
 * Eran dos cosas encadenadas:
 *
 *   1. `crm_auditoria.php` devolvía `ABS(m.monto)`, tirando el signo que
 *      `movimientos` sí guarda (+35000 es una carga, -35000 fichas gastadas).
 *   2. Esta función lo reponía por el TIPO:
 *          const esIngreso = f.tipo === "deposito" || f.tipo === "bono";
 *      y como 'ajuste' no es ninguno de los dos, TODOS los ajustes salían en
 *      rojo con un menos.
 *
 * La parte que se cuida acá es la 2; la 1 la cuida t_auditoria.php (sección 7).
 * Hacen falta las dos: con el server arreglado y la pantalla no, se ve igual de
 * mal, y al revés también.
 *
 * LA REGLA QUE SE PRUEBA, y por qué no es una sola: las filas no vienen todas
 * iguales. `deposito` y `retiro` salen de `recargas` / `acciones_saldo` como
 * MAGNITUDES (siempre positivas) y ahí el sentido lo da el tipo; `ajuste` y
 * `bono` salen de `movimientos`, donde el monto ya viene firmado.
 *
 * Se EXTRAE la función de landing/crm.html, no se copia.
 *
 *     node t_signo_auditoria.js
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

const DESDE = "function auFilaHtml(f, idx){";
const i = SRC.indexOf(DESDE);
if (i < 0) { console.error("No encontré auFilaHtml en crm.html"); process.exit(1); }
const j = SRC.indexOf("function auVacioHtml", i);
if (j < 0) { console.error("No encontré el final de auFilaHtml"); process.exit(1); }
const FUENTE = SRC.slice(i, j);

/* El entorno mínimo que la función toca. Todo lo que no hace al signo se
   reemplaza por la identidad: lo que se prueba es una celda, no el HTML. */
const entorno = {
  nf: { format: n => String(n) },
  esc: s => String(s),
  AU_TIPO_TXT: { deposito: "Depósito", retiro: "Retiro", bono: "Bono", ajuste: "Ajuste" },
  AU_BADGE_ICO: {},
  auFechaFmt: s => s,
  auFechaMobile: s => s,
  auEstadoBadge: s => s,
  auOperadorHtml: () => "",
};
const nombres = Object.keys(entorno);
const auFilaHtml = new Function(...nombres, FUENTE + "; return auFilaHtml;")
                   (...nombres.map(k => entorno[k]));

/** Devuelve {clase, texto} de la celda del monto. */
function celda(fila) {
  const m = auFilaHtml(fila, 0).match(/class="au-monto (pos|neg)">([^<]*)</);
  return m ? { clase: m[1], texto: m[2] } : null;
}

function caso(que, fila, esperado) {
  const c = celda(fila);
  const bien = c && c.texto === esperado
            && c.clase === (esperado[0] === "+" ? "pos" : "neg");
  chequear(que, !!bien, c ? c.clase + " " + c.texto : "no encontré la celda");
}

console.log("\n=== 1. Las dos filas de aquella noche ===");
/* Las dos que estaban una al lado de la otra en la pantalla, con dos minutos de
   diferencia, y que se veían IGUALES. */
caso('la carga a mano de nahuel suma: "+35000"',
     { tipo: "ajuste", monto: 35000, usuario: "x", detalle: "saldo +35000", referencia: 1 },
     "+35000");
caso('las fichas que se fueron al juego restan: "-35000"',
     { tipo: "ajuste", monto: -35000, usuario: "x", detalle: "Carga al juego", referencia: 2 },
     "-35000");

console.log("\n=== 2. Lo que ya andaba tiene que seguir andando ===");
/* `deposito` y `retiro` llegan como magnitudes: el signo lo pone el tipo, y
   cambiarlo hubiera roto la mitad de la pantalla que estaba bien. */
caso("un depósito sigue en verde y con más",
     { tipo: "deposito", monto: 35000, usuario: "x", detalle: "", referencia: 3 }, "+35000");
caso("un retiro sigue en rojo y con menos",
     { tipo: "retiro", monto: 1000, usuario: "x", detalle: "", referencia: 4 }, "-1000");
caso("un bono sigue sumando",
     { tipo: "bono", monto: 5000, usuario: "x", detalle: "", referencia: 5 }, "+5000");

console.log("\n=== 3. Los bordes ===");
/* Un retiro nunca llega negativo desde su rama, pero si algún día llegara, el
   signo no se puede aplicar dos veces y terminar en "+". */
caso("un retiro que llegara firmado no se da vuelta",
     { tipo: "retiro", monto: -1000, usuario: "x", detalle: "", referencia: 6 }, "-1000");
/* Un ajuste de 0 no es ni una cosa ni la otra. Que se muestre como "+0" es una
   decisión, no un descuido: "-0" se lee como una resta que no pasó. */
caso("un ajuste en cero no se muestra como resta",
     { tipo: "ajuste", monto: 0, usuario: "x", detalle: "", referencia: 7 }, "+0");
/* El server manda el monto como número, pero si algún día llegara como texto
   (un JSON.stringify de más, un cast perdido), "-35000" no puede leerse como
   positivo por ser una string no vacía. */
caso("un monto que llega como texto se interpreta igual",
     { tipo: "ajuste", monto: "-35000", usuario: "x", detalle: "", referencia: 8 }, "-35000");

console.log("\n---------------------------------------");
console.log(`${ok} OK, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
