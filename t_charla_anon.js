/**
 * t_charla_anon.js — La charla no se borra cuando el jugador se identifica.
 *
 * LO QUE PASÓ (Nahuel, 05/10/2026): *"apenas le da el nombre de usuario y
 * contraseña la ventana del chat se limpia y se borra todo"*.
 *
 * La charla se guarda con el dueño adentro, y al restaurarla el widget
 * comparaba `d.u !== USUARIO` sin distinguir dos casos muy distintos:
 *
 *   otro jugador        -> correcto descartarla, son dos personas
 *   el mismo, un segundo después -> hay que ADOPTARLA
 *
 * El segundo es justo el del alta por chat: el jugador entra anónimo
 * (USUARIO=""), pide la cuenta, el bot se la crea, el widget pasa a
 * USUARIO="holaXXX" — y la charla queda "de otro". Se borraba la pantalla
 * entera EN EL MOMENTO EXACTO en que acababa de recibir sus credenciales, que
 * es la única vez que las ve.
 *
 * Es la misma regla que el servidor ya aplica en crm_adoptar_anon().
 *
 *     node t_charla_anon.js
 */
"use strict";
const fs = require("fs");

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

const src = fs.readFileSync(__dirname + "/landing/widget.js", "utf8");

/* Se ejecuta la decisión REAL del widget, extraída del archivo: una copia de
   la lógica se queda vieja justo cuando el original cambia. */
const m = src.match(/var dueno = d\.u \|\| "";[\s\S]*?\n    \}/);
if (!m) { console.log("  FALLA no encontré el bloque de adopción en widget.js"); process.exit(1); }

/** Devuelve {mostrar, guardado} corriendo el código real. */
function restaurarCon(dueno, usuario) {
  const d = { u: dueno, charla: [{ q: "bot", t: "hola" }] };
  let guardado = null;
  const fn = new Function("d", "USUARIO", "lss", "gpChatKey", "JSON",
    m[0] + "\nreturn { mostrar: true, d: d };");
  /* El código real hace `return false` cuando no se adopta, así que la
     función puede devolver un booleano O el objeto: hay que distinguirlos, no
     leerle `.mostrar` a un false (da undefined y el test miente). */
  const r = fn(d, usuario, (k, v) => { guardado = JSON.parse(v); }, () => "k", JSON);
  return { mostrar: r !== false && !!r.mostrar, guardado: guardado };
}

// ===========================================================================
console.log("=== 1. El caso del alta por chat ===");
/* Entra anónimo, pide cuenta, el bot se la crea: USUARIO pasa de "" a holaXXX
   en el mismo segundo. La charla --con sus credenciales adentro-- es suya. */
const alta = restaurarCon("", "holaMartina847");
chequear("una charla anónima se ADOPTA al identificarse",
         alta.mostrar === true,
         "acá se borraban las credenciales justo cuando se las acababan de dar");
chequear("y se re-guarda con el dueño nuevo",
         alta.guardado && alta.guardado.u === "holaMartina847",
         "sin re-guardar, la próxima restauración la vuelve a perder");
chequear("sin perder lo que se había hablado",
         alta.guardado && Array.isArray(alta.guardado.charla) && alta.guardado.charla.length === 1);

// ===========================================================================
console.log("\n=== 2. Pero la de OTRO jugador sigue sin mostrarse ===");
/* Acá sí son dos personas: el que entra no tiene por qué ver la conversación
   del anterior, ni sus credenciales. */
chequear("la charla de otro usuario con nombre NO se adopta",
         restaurarCon("holaPedro12", "holaMartina847").mostrar === false,
         "sería mostrarle a alguien la conversación de otro");
chequear("ni al revés: identificado que pasa a anónimo",
         restaurarCon("holaPedro12", "").mostrar === false,
         "cerrar sesión no puede dejar la charla del anterior a la vista");

// ===========================================================================
console.log("\n=== 3. Lo de siempre sigue igual ===");
chequear("el mismo usuario ve su charla",
         restaurarCon("holaMartina847", "holaMartina847").mostrar === true);
chequear("y el anónimo la suya",
         restaurarCon("", "").mostrar === true);

// ===========================================================================
console.log("\n=== 4. La regla está escrita donde se entiende ===");
chequear("se nombra el espejo del servidor (crm_adoptar_anon)",
         /crm_adoptar_anon/.test(src),
         "es la misma decisión de los dos lados: conviene que se vea");
chequear("y el caso que lo motivó queda documentado",
         /se limpia y se borra\s*\n?\s*\* todo|acababa de recibir su usuario y su contraseña/.test(src));

// ===========================================================================
console.log("\n=== 5. La cuenta recién creada no se suelta antes de entrar ===");
/* EL CASO (Nahuel, 05/10/2026): "sigue borrando el chat pocos segundos después
   de haber otorgado al usuario el nombre de usuario y contraseña".

   El sondeo de sesión corre cada ~1,2 s y, si ve que hay USUARIO pero la
   plataforma dice que no hay nadie, a las 3 pasadas (~4 s) lo SUELTA: limpia
   la sesión y borra la charla. Para una cuenta recién creada eso no es una
   sesión perdida -- es una que todavía no empezó: el jugador tiene las
   credenciales en pantalla y aún no entró. Le borraba lo único que necesitaba
   justo cuando lo necesitaba. */
chequear("se marca el alta al ENTREGAR las credenciales",
         /marcarAltaReciente\(d\.usuario \|\| ""\);/.test(src),
         "si no se marca, el sondeo la suelta a los ~4 segundos");
chequear("y el sondeo no la suelta mientras no haya entrado",
         /if \(reciencreada && USUARIO === reciencreada\)\{\s*\n\s*sinSesion = 0;/.test(src),
         "es el suelte que le borraba la pantalla");
/* El suelte original sigue intacto para lo que fue escrito: el que cerró
   sesión, o la página que carga con un usuario viejo guardado. */
chequear("pero el suelte de siempre sigue en pie",
         /\} else if \(teniaSesion === true \|\| sinSesion >= 3\)\{/.test(src),
         "un jugador que cerró sesión SÍ tiene que soltarse");

console.log("\n=== 6. Y se borra cuando entra, que es lo pedido ===");
/* "quiero que se elimine el chat después de que el usuario haya iniciado
   sesión con la cuenta recién creada". Ya usó las credenciales: dejarlas en
   pantalla no suma, y empezar limpio con su nombre sí. */
chequear("al entrar con la cuenta nueva se reinicia la charla",
         /var entroConLaNueva = reciencreada && quien === reciencreada;/.test(src)
         && /if \(entroConLaNueva\)\{[\s\S]{0,400}?reiniciarCharla\(/.test(src),
         "es el borrado que SÍ se pidió: cuando ya usó las credenciales");
chequear("y se levanta la marca, para no repetirlo",
         /if \(entroConLaNueva\) \{ marcarAltaReciente\(""\); \}/.test(src));
/* La marca sobrevive a una recarga: el jugador puede cerrar y volver antes de
   entrar, y ahí el sondeo lo soltaría igual. */
chequear("la marca sobrevive a recargar la página",
         /ls\("gp_alta_reciente"\)/.test(src) && /lss\("gp_alta_reciente"/.test(src),
         "sin persistirla, una recarga antes de entrar vuelve a borrarle todo");

// ===========================================================================
console.log("\n=== 7. La guarda está en el EMBUDO, no en cada camino ===");
/* POR QUÉ ESTO IMPORTA MÁS QUE EL ARREGLO EN SÍ. El 05/10/2026 se arreglaron
   TRES caminos de a uno --el aislamiento por tenant, la restauración de la
   charla, el suelte de sesión-- y el chat se seguía borrando: cada arreglo
   tapaba una puerta y quedaba otra abierta. Son CUATRO los que llaman a
   reiniciarCharla(), más el aislamiento. Taparlas una por una es una carrera
   que se pierde.

   La regla vive en olvidar(), que es por donde pasan todos. Se escribe una vez
   y vale para los cinco. */
chequear("olvidar() es quien decide, y recibe el motivo",
         /function olvidar\(motivo\)\{/.test(src));
chequear("con una cuenta recién creada sin entrar, NO borra",
         /if \(reciencreada && !gpEntroAlgunaVez\)\{[\s\S]{0,200}return false;/.test(src),
         "es la regla única que cubre los cinco caminos");
chequear("y la bandera solo se levanta cuando la plataforma confirma sesión",
         /gpEntroAlgunaVez = true;\s*\/\/ la plataforma confirmo una sesion de verdad/.test(src),
         "si se levantara sola, la guarda no serviría de nada");

/* CADA CAMINO DICE QUIÉN ES. Las tres vueltas anteriores se perdieron
   justamente por no saber cuál de ellos era: con el motivo en el log, la
   próxima vez se ve en un segundo. */
const motivos = (src.match(/reiniciarCharla\("[^"]+"\)/g) || []);
chequear("los cuatro llamadores están etiquetados",
         motivos.length >= 4,
         "sin el motivo, un borrado inesperado vuelve a costar tres vueltas");
chequear("y el que borra lo deja escrito en el log",
         /log\("se borra la charla:", motivo/.test(src));
chequear("igual que el que NO borra",
         /log\("NO se borra la charla \(/.test(src));

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
