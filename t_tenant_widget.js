/**
 * t_tenant_widget.js — A qué CRM manda los mensajes el widget.
 *
 * EL INCIDENTE (Nahuel, 01/10/2026): *"creé una copia para un cliente, su url
 * es ganamoscrm.online/leandro/, escribí por ahí y los mensajes llegan al CRM
 * nuestro, no al de Leandro"*.
 *
 * El backend estaba bien: /leandro/gp-api/tenant_info.php contesta
 * {"ok":true,"slug":"leandro"} y la raíz contesta slug vacío. El que elegía mal
 * era el widget, que es quien decide a qué URL mandar cada mensaje.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN. Las dos formas de equivocarse cuestan lo mismo
 * y ninguna falla a la vista: los mensajes aparecen en el CRM de otro.
 *
 *   1. QUE EL SLUG SE ENCUENTRE AUNQUE EL SPA YA HAYA NAVEGADO. El jugador
 *      entra por /leandro/ y el SPA pisa la URL en milisegundos.
 *   2. QUE LA RAÍZ SUELTE AL CLIENTE ANTERIOR. localStorage es por ORIGEN, así
 *      que sin esto un navegador que alguna vez entró a /leandro/ mandaba al
 *      CRM de Leandro, para siempre, lo que se escribiera en NUESTRA
 *      plataforma.
 *   3. QUE UNA RUTA DEL SPA NO SE ADOPTE COMO SI FUERA UN CLIENTE.
 *
 *     node t_tenant_widget.js
 */
"use strict";
const fs = require("fs");

let ok = 0, fail = 0;
function chequear(q, c, d) {
  if (c) { ok++; console.log("  OK    " + q); }
  else { fail++; console.log("  FALLA " + q + (d ? "   " + d : "")); }
}

const src = fs.readFileSync(__dirname + "/landing/widget.js", "utf8");

/* Se extrae la función REAL del widget, no una copia: una copia se queda vieja
   justo cuando el original cambia, que es cuando el test tendría que avisar. */
const m = src.match(/function gpSlugCandidato\(\)\s*\{[\s\S]*?\n  \}/);
if (!m) {
  console.log("  FALLA no encontré gpSlugCandidato() en widget.js");
  process.exit(1);
}

function candidatoCon(pathname, referrer, host, hash) {
  host = host || "ganamoscrm.online";
  const sandbox = {
    MISMO_ORIGEN: true,
    location: { pathname: pathname, host: host, hash: hash || "" },
    document: { referrer: referrer || "" },
    URL: URL,
  };
  const fn = new Function(
    "MISMO_ORIGEN", "location", "document", "URL",
    m[0] + "\nreturn gpSlugCandidato();"
  );
  return fn(sandbox.MISMO_ORIGEN, sandbox.location, sandbox.document, sandbox.URL);
}

// ===========================================================================
console.log("=== 1. El slug se encuentra aunque el SPA haya navegado ===");
chequear("el link que se comparte: /leandro/", candidatoCon("/leandro/") === "leandro");
chequear("sin barra final: /leandro", candidatoCon("/leandro") === "leandro");
/* LO QUE FALTABA. Hasta el 01/10 solo se miraba el path de UN segmento exacto,
   así que bastaba un paso del SPA para perder de quién era la página. */
chequear("con el SPA ya adentro: /leandro/home",
         candidatoCon("/leandro/home") === "leandro",
         "antes esto daba vacío y el mensaje se iba al CRM de la plataforma");
chequear("y más adentro todavía: /leandro/games/x",
         candidatoCon("/leandro/games/x") === "leandro");

/* EL REFERRER es la red para cuando el SPA ya pisó la URL por completo: el
   path no dice nada pero el referrer todavía cuenta por dónde entró. */
chequear("el SPA pisó la URL, pero el referrer recuerda la entrada",
         candidatoCon("/home", "https://ganamoscrm.online/leandro/") === "leandro",
         "sin esto, un refresh en /home pierde al cliente");
/* Con un referrer ajeno NO se adopta ese slug. Queda "home" --el path, que la
   API descarta-- y nunca "leandro": si el referrer de otro host contara,
   cualquiera elegiría en qué CRM caen los mensajes mandando tráfico. */
chequear("un referrer de OTRO host no decide el tenant",
         candidatoCon("/home", "https://google.com/leandro/") !== "leandro",
         "cualquiera podría mandar tráfico con un referrer armado");
/* Y el path manda sobre el referrer cuando dice algo: estar DENTRO de
   /leandro/ gana sobre haber venido de otro lado. */
chequear("el path gana sobre el referrer cuando es inequívoco",
         candidatoCon("/leandro/home", "https://ganamoscrm.online/otro/") === "leandro");

// ===========================================================================
console.log("\n=== 2. La raíz es nuestra y suelta al cliente anterior ===");
/* localStorage es por ORIGEN: ganamoscrm.online es el MISMO origen para
   nosotros y para todos los clientes por path. Sin esta limpieza, el navegador
   que entró una vez a /leandro/ queda pegado a Leandro para siempre. */
chequear("entrar a / limpia el slug guardado",
         /location\.pathname === "\/"/.test(src) &&
         /localStorage\.removeItem\("gp_tenant_slug"\)/.test(src),
         "sin esto, lo escrito en NUESTRA plataforma va al CRM del último cliente visitado");
chequear("y eso pasa ANTES de buscar candidato",
         src.indexOf('location.pathname === "/"') < src.indexOf("var cand = gpSlugCandidato()"));

// ===========================================================================
console.log("\n=== 3. Una ruta del SPA no se adopta como cliente ===");
/* `/home` es candidato --tiene la forma-- y se descarta al validarlo contra la
   API. Lo que NO puede pasar es que se adopte mientras tanto: ahí el widget le
   pegaría a /home/gp-api/... y el que escriba en ese instante no le escribe a
   nadie. Por eso la adopción optimista es solo con barra final. */
chequear("/home tiene forma de candidato (y lo descarta la API)",
         candidatoCon("/home") === "home");
chequear("la adopción optimista exige barra final",
         /if \(\/\\\/\$\/\.test\(location\.pathname\)\) \{ TENANT_SLUG = cand; \}/.test(src),
         "adoptar /home de entrada rompe el chat durante la validación");
chequear("y se valida que el slug que vuelve sea el pedido",
         /\(d\.slug \|\| ""\)\.toLowerCase\(\) !== cand/.test(src));

// ===========================================================================
console.log("\n=== 4. No poder preguntar no es 'no existe' ===");
/* Con el código viejo, un 502 de un deploy dejaba el slug NEGADO en toda la
   pestaña: el jugador seguía escribiendo, y todo iba a nuestro CRM. */
chequear("una respuesta que no es 200 no niega el slug",
         /if \(!r\.ok\) \{ throw \{ red: 1 \}; \}/.test(src) &&
         /if \(e && e\.red\) \{/.test(src),
         "un parpadeo de red dejaba al cliente escribiendo en el CRM de la plataforma");
chequear("solo un ok:false explícito lo descarta",
         /throw \{ red: 0 \}/.test(src));

// ===========================================================================
console.log("\n=== 5. Se puede responder a qué CRM está mandando ===");
chequear("el widget expone el tenant que resolvió",
         /window\.__gp_tenant/.test(src),
         "fue lo primero que hizo falta y no existía");

// ===========================================================================
console.log("\n=== 6. El tenant queda escrito en la URL (el hash) ===");
/* EL PROBLEMA QUE RESUELVE (05/10/2026): el SPA de la plataforma se lleva
   puesto el path -- entrar a /<slug>/ termina en /home en milisegundos. Medido:
   el servidor devuelve 200 sin redirect, el salto lo hace el SPA porque no
   reconoce esa ruta. Es su código.

   El hash NO lo toca nadie. Ahí el tenant sobrevive a lo que al path se le
   escapa: un refresh, una pestaña nueva, el incógnito, un link compartido. Sin
   esto el único rastro vive en localStorage, y el día que el jugador entra con
   los datos limpios su chat aparece en el CRM de la plataforma. */
chequear("parado en /home, el hash dice de quién es la página",
         candidatoCon("/home", "", null, "#t=oromaris") === "oromaris",
         "es lo único que sobrevive cuando el SPA ya se llevó el path");
chequear("y gana sobre el path, que para entonces miente",
         candidatoCon("/home", "", null, "#t=oromaris") !== "home",
         "en /home el path dice 'home', que no es ningún cliente");
chequear("convive con el #gp-chat que ya usábamos",
         candidatoCon("/home", "", null, "#t=oromaris&gp-chat") === "oromaris");
chequear("un hash sin tenant no inventa nada",
         candidatoCon("/home", "", null, "#gp-chat") === "home");

/* Y alguien tiene que ESCRIBIRLO, si no el hash nunca aparece. */
chequear("el widget lo escribe en la URL",
         /function gpMarcarTenantEnUrl\(\)/.test(src));
chequear("con replaceState y tocando SOLO el hash",
         /history\.replaceState\(null, "", location\.pathname \+ location\.search \+ "#"/.test(src),
         "tocar el path sería pelearle el routing al SPA, que es de ellos");
/* El SPA hace su primer replaceState en los primeros milisegundos: si el
   widget escribe antes, se lo lleva puesto. Por eso se reintenta. */
chequear("y lo reintenta, porque el SPA pisa la URL al arrancar",
         (src.match(/setTimeout\(gpMarcarTenantEnUrl/g) || []).length >= 2,
         "una sola pasada la borra el primer replaceState del SPA");

console.log("\n" + "-".repeat(39));
console.log(ok + " OK, " + fail + " fallas");
process.exit(fail > 0 ? 1 : 0);
