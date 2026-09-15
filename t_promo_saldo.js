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

/* Desde promoArmado() hasta el cierre de mirarSaldoBajo(). */
const ini = src.indexOf("function promoArmado(){");
const fin = src.indexOf("\n  }", src.indexOf("function mirarSaldoBajo"));
if (ini < 0 || fin < 0) {
  console.error("No pude extraer mirarSaldoBajo() de landing/widget.js");
  process.exit(1);
}
const CODIGO = src.slice(ini, fin + 4);

/* La otra mitad: a quien NO se le muestra mas. Se extrae igual, de la misma
   fuente, para que no pueda quedar desincronizada. */
const iniSup = src.indexOf("function promoAppSuprimida(){");
const finSup = src.indexOf("\n  }", iniSup);
if (iniSup < 0 || finSup < 0) {
  console.error("No pude extraer promoAppSuprimida() de landing/widget.js");
  process.exit(1);
}
const CODIGO_SUP = src.slice(iniSup, finSup + 4);

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

/* Cada escenario es una instancia limpia: el estado vive en la funcion, asi
   que dos casos no pueden compartirlo. */
function escenario(promo, guardadoInicial) {
  let mostrados = [];
  let ultimaPromoApp = promo;                 // lo lee la funcion extraida
  function mostrarPromoApp(p) { mostrados.push(p); }
  /* El armado vive en localStorage, asi que el test tiene que simularlo. Es
     justo lo que hay que probar: que sobreviva a recargar la pagina. */
  let guardado = Object.assign({}, guardadoInicial || {});
  function ls(k) { return Object.prototype.hasOwnProperty.call(guardado, k) ? guardado[k] : null; }
  function lss(k, v) { guardado[k] = String(v); }
  function lsd(k) { delete guardado[k]; }
  /* eval() en modo no estricto mete `promoSaldoArmado` y `mirarSaldoBajo` en
     ESTE scope, que es lo que se quiere: cada escenario tiene su propio estado
     y no puede contaminar al de al lado. Declararlos aca arriba con let
     chocaria con la declaracion que trae el codigo extraido. */
  eval(CODIGO);
  return {
    saldo: v => mirarSaldoBajo(v),
    veces: () => mostrados.length,
    apagar: () => { ultimaPromoApp = null; },
    // Lo que quedaria guardado en el navegador: sirve para simular una recarga.
    guardado: () => Object.assign({}, guardado),
  };
}

/* promoAppSuprimida() lee localStorage a traves de ls(). Se le da uno falso
   para poder poner cada caso sin un navegador de por medio. */
function suprimida(guardado) {
  function ls(k) { return Object.prototype.hasOwnProperty.call(guardado, k) ? guardado[k] : null; }
  /* Sin declararla antes: eval() la mete en este scope, igual que en
     escenario(). Un `let` aca chocaria con la declaracion extraida. */
  eval(CODIGO_SUP);
  return promoAppSuprimida();
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

console.log("\n=== 2. Con saldo bajo desde el arranque, no salta solo ===");
/* El server ya no le ofrece la promo a quien nunca cargo, pero el armado sigue
   haciendo falta: sin el, entrar con poco saldo dispararia el cartel en el
   primer tick, sin que haya pasado nada. */
{
  const e = escenario(PROMO);
  e.saldo(0);
  e.saldo(0);
  e.saldo(0);
  chequear("con 0 fichas desde el arranque, no sale", e.veces() === 0,
           "veces=" + e.veces());
  e.saldo(3000);                       // carga
  chequear("al cargar tampoco sale", e.veces() === 0);
  e.saldo(100);                        // y ahora si jugo
  chequear("recien cuando jugo y bajo, sale", e.veces() === 1);
}

console.log("\n=== 2b. EL CASO DEL CELULAR: volver de un juego ===");
/* EL BUG QUE ESTO FIJA (15/09/2026). En el celular, abrir un juego NAVEGA a
   otro dominio -- el del proveedor, tipo prrplt3.com -- y al volver la pagina
   se recarga entera. Con el armado en una variable de memoria, esa vuelta lo
   reseteaba: el jugador volvia del juego con 50 fichas, nunca mas veia un
   saldo alto, y el cartel no salia NUNCA. Guardado en el navegador, sobrevive. */
{
  const antes = escenario(PROMO);
  antes.saldo(5000);                   // juega con saldo alto: queda armado
  chequear("antes de irse al juego quedo armado",
           antes.guardado().gp_promo_armado === "1", JSON.stringify(antes.guardado()));

  // Vuelve del juego: pagina nueva, memoria en cero, pero el navegador recuerda.
  const despues = escenario(PROMO, antes.guardado());
  despues.saldo(50);                   // volvio sin fichas
  chequear("al volver del juego con poco saldo, SI sale",
           despues.veces() === 1, "veces=" + despues.veces());
  chequear("y queda desarmado para no repetir",
           !despues.guardado().gp_promo_armado, JSON.stringify(despues.guardado()));

  // La contracara: si nunca estuvo armado, una recarga de pagina no lo inventa.
  const limpio = escenario(PROMO, {});
  limpio.saldo(50);
  chequear("sin armado previo, recargar la pagina no dispara nada",
           limpio.veces() === 0, "veces=" + limpio.veces());
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

console.log("\n=== 8. A quien YA tiene la app no se le muestra mas ===");
/* Pedido de Nahuel (14/09/2026): "que dejen de aparecer esos carteles cuando
   sepamos que el jugador tiene la app ya descargada e instalada". */
chequear("navegador limpio: se le puede ofrecer", suprimida({}) === false);
chequear("el server confirmo que la tiene: no",
         suprimida({ gp_app_tiene: "1" }) === true);
chequear("toco Descargar: tampoco",
         suprimida({ gp_app_bajada: "1" }) === true);
chequear("las dos juntas, igual",
         suprimida({ gp_app_tiene: "1", gp_app_bajada: "1" }) === true);

/* EL CASO QUE JUSTIFICA GUARDARLO EN EL NAVEGADOR y no solo mirar el server:
   el mismo navegador vuelve ANONIMO. Ahi el server no tiene a quien mirarle
   el tiene_app y manda la promo igual, asi que sin la marca local, cerrar
   sesion hacia reaparecer el cartel al que ya la habia instalado. */
chequear("sigue suprimido aunque navegue anonimo",
         suprimida({ gp_app_tiene: "1" }) === true);

/* Valores raros no tienen que apagar la promo por accidente: solo "1" cuenta.
   Un "0" guardado por una version vieja no puede dejar a alguien sin ver
   nunca el cartel. */
chequear("un 0 guardado no suprime", suprimida({ gp_app_tiene: "0" }) === false);
chequear("basura tampoco", suprimida({ gp_app_tiene: "si", gp_app_bajada: "true" }) === false);
chequear("otra clave cualquiera no interfiere",
         suprimida({ gp_app_promo_visto: "123456" }) === false);

console.log("\n---------------------------------------");
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
