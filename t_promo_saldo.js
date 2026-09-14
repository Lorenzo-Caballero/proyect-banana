/* t_promo_saldo.js -- el cartel de la app cuando se le acaban las fichas.
 *
 * POR QUE EXISTE (Nahuel, 14/09/2026): "me gustaria que esa ventana que sale
 * para descargar la aplicacion aparezca cuando se estan quedando con pocas
 * fichas, por ejemplo 500".
 *
 * Suena trivial y no lo es: la funcion tiene ESTADO, y del estado depende que
 * el cartel no le salte en la cara al que recien se registro. Una cuenta nueva
 * tiene 0 fichas, o sea que esta "por debajo de 500" desde el primer segundo.
 * Por eso arranca DESARMADA y solo se arma cuando vio un saldo ARRIBA del
 * umbral -- asi el cartel sale despues de haber jugado, que es el momento que
 * se pidio, y no antes de empezar.
 *
 * NO COPIA LA FUNCION: la extrae de landing/widget.js y la evalua. Es lo que
 * distingue este test de t_peticiones_ui.js, que copia y avisa en su cabecera
 * que si alguien toca el original y no la copia, deja de proteger nada.
 *
 *     node t_promo_saldo.js
 */
const fs = require("fs");
const path = require("path");

const src = fs.readFileSync(path.join(__dirname, "landing", "widget.js"), "utf8");

/* Desde la declaracion del estado hasta el cierre de mirarSaldoBajo(). */
const ini = src.indexOf("var promoSaldoArmado = false;");
const fin = src.indexOf("\n  }", src.indexOf("function mirarSaldoBajo"));
if (ini < 0 || fin < 0) {
  console.error("No pude extraer mirarSaldoBajo() de landing/widget.js");
  process.exit(1);
}
const CODIGO = src.slice(ini, fin + 4);

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

/* Cada escenario es una instancia limpia: el estado vive en la funcion, asi
   que dos casos no pueden compartirlo. */
function escenario(promo) {
  let mostrados = [];
  let ultimaPromoApp = promo;                 // lo lee la funcion extraida
  function mostrarPromoApp(p) { mostrados.push(p); }
  /* eval() en modo no estricto mete `promoSaldoArmado` y `mirarSaldoBajo` en
     ESTE scope, que es lo que se quiere: cada escenario tiene su propio estado
     y no puede contaminar al de al lado. Declararlos aca arriba con let
     chocaria con la declaracion que trae el codigo extraido. */
  eval(CODIGO);
  return {
    saldo: v => mirarSaldoBajo(v),
    veces: () => mostrados.length,
    apagar: () => { ultimaPromoApp = null; },
  };
}

const PROMO = { fichas: 1000, url: "https://x/app.apk", saldo_bajo: 500 };

console.log("\n=== 1. El caso que se pidio: jugando, se queda sin fichas ===");
{
  const e = escenario(PROMO);
  e.saldo(5000);                       // carga y juega
  chequear("con saldo alto no sale", e.veces() === 0);
  e.saldo(2000);
  chequear("bajando pero arriba del umbral, tampoco", e.veces() === 0);
  e.saldo(400);
  chequear("al cruzar para abajo, sale", e.veces() === 1, "veces=" + e.veces());
}

console.log("\n=== 2. Al recien registrado NO le salta en la cara ===");
/* ES EL CASO QUE JUSTIFICA TODO EL ESTADO. Una cuenta nueva tiene 0 fichas:
   sin el armado, el cartel saldria en el primer tick, antes de que el jugador
   haya hecho nada. */
{
  const e = escenario(PROMO);
  e.saldo(0);
  e.saldo(0);
  e.saldo(0);
  chequear("con 0 fichas desde el arranque, no sale nunca", e.veces() === 0,
           "veces=" + e.veces());
  e.saldo(3000);                       // carga por primera vez
  chequear("al cargar tampoco sale", e.veces() === 0);
  e.saldo(100);                        // y ahora si jugo
  chequear("recien cuando jugo y bajo, sale", e.veces() === 1);
}

console.log("\n=== 3. Una sola vez por bajada, no acoso ===");
/* El saldo se reporta cada 1,2 s. Sin el desarmado, quedarse en 300 fichas
   serian 50 carteles por minuto. */
{
  const e = escenario(PROMO);
  e.saldo(5000);
  for (let i = 0; i < 40; i++) e.saldo(300);
  chequear("40 ticks seguidos abajo = un solo cartel", e.veces() === 1,
           "veces=" + e.veces());
  e.saldo(250);
  e.saldo(10);
  chequear("y sigue sin repetir mientras baja", e.veces() === 1, "veces=" + e.veces());
}

console.log("\n=== 4. Si vuelve a cargar, se re-arma ===");
{
  const e = escenario(PROMO);
  e.saldo(5000); e.saldo(200);
  chequear("primera bajada", e.veces() === 1);
  e.saldo(4000);                       // recargo
  chequear("volver a subir no muestra nada", e.veces() === 1);
  e.saldo(100);                        // se volvio a quedar sin
  chequear("la segunda bajada si muestra", e.veces() === 2, "veces=" + e.veces());
}

console.log("\n=== 5. El borde exacto del umbral ===");
/* "menos de 500" incluye 500: si alguien pone 500, con 500 en pantalla ya se
   esta quedando sin fichas. Lo importante es que sea consistente. */
{
  const e = escenario(PROMO);
  e.saldo(5000);
  e.saldo(501);
  chequear("501 todavia no", e.veces() === 0);
  e.saldo(500);
  chequear("500 justo si", e.veces() === 1, "veces=" + e.veces());
}

console.log("\n=== 6. Apagado = no molesta ===");
{
  const e = escenario({ fichas: 1000, url: "x", saldo_bajo: 0 });
  e.saldo(9000); e.saldo(1); e.saldo(0);
  chequear("umbral 0 no muestra nunca", e.veces() === 0, "veces=" + e.veces());
}
{
  const e = escenario({ fichas: 1000, url: "x" });   // el server no mando el campo
  e.saldo(9000); e.saldo(1);
  chequear("sin el campo tampoco (server viejo)", e.veces() === 0);
}
{
  /* El server deja de ofrecer la app -- ya la instalo, o se apago la promo. */
  const e = escenario(PROMO);
  e.saldo(5000);
  e.apagar();
  e.saldo(100);
  chequear("si el server dejo de ofrecerla, no sale", e.veces() === 0);
}

console.log("\n=== 7. Un saldo ilegible no dispara nada ===");
/* saldoDeReact() y saldoDelHeader() devuelven null si no pudieron leer, y el
   header puede dar NaN. Tomar eso como "cero fichas" le mostraria el cartel a
   alguien con la cuenta llena, justo cuando menos sentido tiene. */
{
  const e = escenario(PROMO);
  e.saldo(5000);
  e.saldo(null);
  e.saldo(NaN);
  e.saldo(undefined);
  chequear("null, NaN y undefined se ignoran", e.veces() === 0, "veces=" + e.veces());
  e.saldo(100);
  chequear("y el siguiente saldo real sigue funcionando", e.veces() === 1);
}

console.log("\n---------------------------------------");
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
