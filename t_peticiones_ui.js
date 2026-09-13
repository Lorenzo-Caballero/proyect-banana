/* t_peticiones_ui.js -- como se LEE la pantalla "Cargas pedidas en el juego".
 *
 * Las dos funciones son copias de landing/crm.html. Si alguien las toca alla y
 * no aca, este test deja de proteger nada: mantenerlas iguales.
 *
 * QUE BLINDA (13/9/2026, sobre una captura de produccion):
 *   - el renglon mas visible de casi todas las filas era la respuesta CRUDA de
 *     la plataforma: {"status":0,"result":{"status":"success"},...}. No le dice
 *     nada a nadie y tapaba el motivo cuando SI habia uno que leer;
 *   - "hace 1648 min que espera" son 27 horas. Nadie divide eso mentalmente.
 *
 *     node t_peticiones_ui.js
 */

function ptEspera(min){
    min = Math.max(0, Math.round(min || 0));
    if(min < 120) return `hace ${min} min`;
    const h = Math.round(min / 60);
    if(h < 48) return `hace ${h} h`;
    const d = Math.round(h / 24);
    return `hace ${d} ${d === 1 ? "día" : "días"}`;
  }

  /* EL MOTIVO, EN CASTELLANO. El worker guarda ahi la respuesta CRUDA de la
     plataforma, y en pantalla quedaba el renglon mas visible de la fila
     ocupado por {"status":0,"result":{"status":"success"},"error_message":null}
     -- que no le dice nada a nadie y encima es lo que se ve en las aprobadas,
     o sea en casi todas.
     Cuando la respuesta es un exito, el detalle no aporta: se calla. Cuando es
     un error, se muestra el error_message, que es la unica parte util. Y si el
     motivo no es JSON (los que escribe nuestro matcher, como "todavia no entro
     ninguna transferencia por ese monto") se muestra tal cual: esos SI estan
     escritos para leerse. */
  function ptMotivo(p){
    const crudo = (p.motivo || "").trim();
    if(!crudo) return "";
    const i = crudo.indexOf("{");
    if(i < 0) return crudo;                     // texto nuestro, se lee bien
    const antes = crudo.slice(0, i).trim();
    try{
      const j = JSON.parse(crudo.slice(i));
      if(Number(j.status) === 0) return "";     // exito: el detalle no suma
      const msg = j.error_message || j.message || "";
      return msg ? `La plataforma la rechazó: ${msg}` : (antes || crudo);
    }catch(e){
      /* No se pudo parsear: puede ser el HTML del WAF o una respuesta rara.
         Se muestra recortado -- volcar 300 caracteres de HTML es peor que no
         mostrar nada, pero esconderlo del todo taparia un problema real. */
      return (antes ? antes + " · " : "") + crudo.slice(i, i + 90) + "…";
    }
  }

  
let ok=0, fallas=0;
const chequear=(q,c,d="")=>{ if(c){ok++;console.log("  OK    "+q);} else {fallas++;console.log("  FALLA "+q+"   "+d);} };

console.log("\n=== La espera, legible ===");
chequear("1648 min -> 27 h (el de holacarlos114)", ptEspera(1648)==="hace 27 h", ptEspera(1648));
chequear("45 min se dejan en minutos",             ptEspera(45)==="hace 45 min", ptEspera(45));
chequear("119 min todavia en minutos",             ptEspera(119)==="hace 119 min", ptEspera(119));
chequear("120 min pasan a horas",                  ptEspera(120)==="hace 2 h", ptEspera(120));
chequear("4320 min -> 3 dias",                     ptEspera(4320)==="hace 3 días", ptEspera(4320));
chequear("1440 min -> 24 h (todavia no es dia)",   ptEspera(1440)==="hace 24 h", ptEspera(1440));

console.log("\n=== El motivo, en castellano ===");
const EXITO = 'aprobada por API (200) {"status":0,"result":{"status":"success"},"error_message":null}';
chequear("una aprobada no muestra el JSON crudo", ptMotivo({motivo:EXITO})==="", ptMotivo({motivo:EXITO}));
const ERR = 'aprobada por API (200) {"status":501,"result":{},"error_message":"saldo insuficiente"}';
chequear("un rechazo muestra el motivo real",
         ptMotivo({motivo:ERR})==="La plataforma la rechazó: saldo insuficiente", ptMotivo({motivo:ERR}));
const NUESTRO = "todavia no entro ninguna transferencia por ese monto";
chequear("el texto nuestro se muestra tal cual", ptMotivo({motivo:NUESTRO})===NUESTRO, ptMotivo({motivo:NUESTRO}));
chequear("sin motivo, nada", ptMotivo({motivo:""})==="" && ptMotivo({})==="");
const WAF = 'respuesta rara (200) <!DOCTYPE html><html><head><noscript>...';
chequear("algo que no es JSON no se esconde (pero se recorta)",
         ptMotivo({motivo:WAF}).length>0 && ptMotivo({motivo:WAF}).length<130, ptMotivo({motivo:WAF}));

console.log("\n---------------------------------------");
console.log(ok+" OK, "+fallas+" fallas");
process.exit(fallas?1:0);
