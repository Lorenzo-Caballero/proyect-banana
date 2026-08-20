/* ==========================================================================
 * ganamos — asistente con IA inyectado dentro de la plataforma (app Android).
 *
 * Corre en el origen de ganamos7.com, asi que ve su sesion directamente:
 * cuando el jugador entra, el asistente adopta su usuario sin preguntarselo.
 *
 * La IA es la misma que en landing/chat.html: la API propia en Hostinger
 * (chatbot.php -> Cohere command-r-08-2024, con tool use). Las herramientas
 * que el modelo puede llamar son identificar_usuario, crear_recarga y
 * consultar_recarga; de eso se encarga el server, aca solo se conversa.
 *
 * Este archivo se puede subir al server (WIDGET_REMOTO en MainActivity) para
 * corregir el asistente sin volver a distribuir el APK.
 * ==========================================================================*/
(function () {
  "use strict";
  if (window.self !== window.top) return;      // no adentro de los juegos
  if (window.__goldpaw) return;                // no duplicar si se reinyecta
  window.__goldpaw = true;

  /* =====================  DIAGNOSTICO  ===================================
   * Todo lo que hace el widget queda anotado, en consola y en un buffer.
   *
   * El buffer existe por el APK: adentro del WebView no hay consola a mano, y
   * pedirle a alguien que enchufe chrome://inspect para saber por que el chat
   * no lo reconoce no es un plan. Con __gp_copiar() se manda el registro
   * entero al portapapeles y se pega en el chat.
   *
   *   __gp_log()      lo devuelve como texto
   *   __gp_copiar()   lo copia al portapapeles
   *   __gp_quien()    que ve cada capa de deteccion AHORA
   *
   * Para apagarlo:  localStorage.setItem("goldpaw_debug","0")  y recargar.
   * ==================================================================== */
  var DIAG_MAX = 300;               // lineas que se recuerdan
  var diario   = [];
  var DIAG     = true;
  try { DIAG = localStorage.getItem("goldpaw_debug") !== "0"; } catch (e) {}

  function log(){
    var linea;
    try {
      linea = new Date().toTimeString().slice(0, 8) + " " +
        Array.prototype.slice.call(arguments).map(function (x){
          if (typeof x === "string") return x;
          try { return JSON.stringify(x); } catch (e) { return String(x); }
        }).join(" ");
    } catch (e) { return; }

    diario.push(linea);
    if (diario.length > DIAG_MAX) diario.shift();
    if (DIAG){ try { console.log("%c[gp]", "color:#25d366;font-weight:700", linea); } catch (e) {} }
  }

  try {
    window.__gp_log = function (){ return diario.join("\n"); };
    window.__gp_copiar = function (){
      var t = diario.join("\n");
      try {
        navigator.clipboard.writeText(t);
        return "copiado (" + diario.length + " lineas)";
      } catch (e) { return t; }   // sin permiso de portapapeles, al menos se ve
    };
  } catch (e) {}

  /* ---------------------------------------------------------------------
   * A donde se le habla a la API propia.
   *
   * Este MISMO archivo se sirve desde dos lugares y no puede usar la misma URL
   * en los dos:
   *
   *   - En la replica (dominio propio, nginx proxea /api/ a Hostinger) las
   *     llamadas van al mismo origen. Es la unica forma que funciona: cruzado,
   *     el WAF de Hostinger devuelve 403 y como su pagina de error no lleva las
   *     cabeceras CORS que si pone el PHP, el navegador lo reporta como "CORS
   *     policy" y manda a buscar el problema al lugar equivocado.
   *   - En la plataforma directa y en el APK no hay proxy: hay que ir a
   *     Hostinger por URL absoluta.
   *
   * Cuando agregues otro dominio con proxy propio, sumalo a REPLICAS.
   * ------------------------------------------------------------------- */
  // Multi-cliente: el panel crea un subdominio <slug>.faunotattoo.com por
  // cliente, todos réplicas nuestras con la API en /gp-api. Por eso cualquier
  // subdominio de faunotattoo.com cuenta como MISMO_ORIGEN, sin tener que
  // listarlos uno por uno. REPLICAS queda para dominios de cliente que NO
  // cuelguen de faunotattoo.com (uno propio que apunte al VPS).
  //
  // PENDIENTE: clientes SIN dominio propio (path-tenant, ganamoscrm.online/
  // <slug>/...) no están soportados acá todavía. Este widget se inyecta DENTRO
  // de ganamos7.com vía sub_filter, así que location.pathname es el de la
  // plataforma (/home), no el nuestro — no hay forma de leer el slug desde acá
  // sin que el proxy hacia la plataforma también lo preserve en el path de
  // cada pedido (cambio grande, deliberadamente no hecho todavía). Por ahora
  // los clientes por-path solo usan crm.html/admin.html/chat.html.
  var REPLICAS = ["ganamos.faunotattoo.com", "ganamoscrm.online", "www.ganamoscrm.online"];
  var MISMO_ORIGEN =
    /(^|\.)(faunotattoo\.com|ganamoscrm\.online)$/i.test(location.hostname) ||
    REPLICAS.indexOf(location.hostname) !== -1;

  /* OJO CON EL PREFIJO: en la replica es /gp-api, NO /api.
     /api/ es de la plataforma (ahi estan /api/user/login y /api/user/check).
     Si nuestra API viviera ahi, nginx se llevaria los pedidos de la plataforma
     a Hostinger y el sitio no podria loguear.
     Hostinger ya no tiene backend propio: fuera de una réplica esto no tiene
     a dónde ir (MISMO_ORIGEN debería ser siempre true en producción). */
  var BASE_API = MISMO_ORIGEN ? "/gp-api" : "/api";

  var API_CHAT   = BASE_API + "/chatbot.php";
  var API_CARGA  = BASE_API + "/carga_estado.php";
  var API_SUBIR  = BASE_API + "/subir.php";
  var API_MIS    = BASE_API + "/mis_mensajes.php";
  var API_RULETA = BASE_API + "/ruleta.php";
  var API_NOTIF  = BASE_API + "/notificaciones.php";
  var API_SALDO  = BASE_API + "/saldo_reportar.php";

  /* La API de la PLATAFORMA. Del bundle:
       Nn = { additionalBaseURL:"/api/user", loginUniversal:"/login",
              checkAuth:"/check", logOut:"/logout" }
     La sesion va en COOKIES (el bundle no guarda ningun token en
     localStorage), asi que estando en el mismo origen alcanza con pedirlo. */
  var API_PLATAFORMA_CHECK = "/api/user/check";

  /* ---------------------------------------------------------------------
   * Espejo del registro EN EL VPS.
   *
   * El widget corre en el navegador del jugador: sin esto, desde el servidor no
   * se ve absolutamente nada de lo que hace. Cada evento se manda como una
   * imagen a /gp-diag, que nginx contesta con 204 y anota en su propio log
   * (ver nginx-replica.conf). Asi el agente mira `tail -f gp-diag.log` y ve
   * pasar los logins en vivo, sin pedirle a nadie que abra la consola.
   *
   * new Image() y no fetch: no necesita CORS, no necesita preflight, no falla
   * si el endpoint no existe y no ensucia la pestaña de red con errores.
   *
   * Solo en la replica: en la plataforma directa y en el APK ese endpoint no
   * existe, seria un 404 por evento.
   * ------------------------------------------------------------------- */
  function avisarVps(ev, extra){
    if (!MISMO_ORIGEN || !DIAG) return;
    try {
      var q = "ev=" + encodeURIComponent(ev);
      for (var k in extra){
        if (!Object.prototype.hasOwnProperty.call(extra, k)) continue;
        var v = extra[k];
        if (v === undefined || v === null || v === "") continue;
        q += "&" + k + "=" + encodeURIComponent(String(v).slice(0, 60));
      }
      // El _ rompe la cache del navegador: sin eso, dos eventos iguales
      // seguidos no llegarian al servidor y el log mentiria por omision.
      new Image().src = "/gp-diag?" + q + "&_=" + Date.now();
    } catch (e) {}
  }

  log("arranca", {
    host:   location.hostname,
    origen: MISMO_ORIGEN ? "replica (API al mismo origen)" : "directo (API a Hostinger)",
    api:    BASE_API
  });
  avisarVps("arranca", { host: location.hostname });

  var CHAT_KEY = "goldpaw_chat";   // la charla guardada entre aperturas
  var MAX_GUARDADO = 40;           // cuantos mensajes se recuerdan
  var MAX_CONTEXTO = 20;           // cuantos turnos se le mandan al modelo

  /* =====================  EDITA ESTO  =====================================
   * Como se presenta el chat. El nombre y la foto son lo primero que ve el
   * jugador, asi que conviene que digan lo mismo que el resto de la marca.
   *
   * Nota sobre el nombre: del otro lado puede contestar el bot O un agente de
   * carne y hueso desde el CRM, asi que "en linea" no es mentira. Si preferis
   * que se lea claramente como un bot, poné acá "Asistente" y listo.
   * ==================================================================== */
  var AGENTE_NOMBRE = "Camila";
  var AGENTE_ESTADO = "en línea";
  // Foto del agente. Si no carga, queda el ícono de abajo y no se rompe nada.
  // En la réplica la sirve el VPS: pedírsela a Hostinger la deja a merced del
  // WAF y quedaría siempre el ícono de respaldo. subir.ps1 la copia al VPS.
  var AGENTE_FOTO   = MISMO_ORIGEN
    ? "/replica/logo.png"
    : "https://orange-crab-483661.hostingersite.com/img/logo-192.png";

  // Botones rápidos. El texto se manda al bot tal cual, así que tienen que
  // decir lo que el CONTEXTO de chatbot.php espera para disparar su herramienta.
  var ATAJOS = [
    { rot: "CARGAR",  txt: "Quiero cargar fichas",        clase: "ok" },
    { rot: "RETIRAR", txt: "Quiero retirar",              clase: "" },
    { rot: "SOPORTE", txt: "Necesito hablar con alguien", clase: "" }
  ];

  function ls(k){ try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lss(k,v){ try { localStorage.setItem(k,v); } catch (e) {} }
  function lsd(k){ try { localStorage.removeItem(k); } catch (e) {} }

  /* ---------------------------------------------------------------------
   * Quien es el jugador.
   *
   * La plataforma no deja el nombre de usuario en localStorage, asi que lo
   * tomamos del pedido de login que ella misma hace. Se lee UNICAMENTE el
   * campo `login`; la contraseña no se toca ni se guarda ni se manda a ningun
   * lado. Si no llegamos a verlo (por ejemplo, sesion ya abierta de antes),
   * el asistente simplemente lo pregunta como siempre.
   * ------------------------------------------------------------------- */
  var USUARIO = ls("goldpaw_user") || "";
  var visto   = "";     // lo ultimo que vimos pasar por la red

  /* El JWT del login PROPIO (auth.php), si el jugador se registro en el sitio.
     Vale mas que todo lo demas de este archivo: va firmado, el server lo
     verifica y ese usuario le gana al `usuario` suelto que mandamos nosotros.
     Todo lo otro (DOM, red, storage) lo dice el navegador y es falseable. */
  var AUTH = ls("API_AUTH_ACCESS_TOKEN") || "";

  function userDeToken(t){
    try {
      var p = String(t).split("."); if (p.length < 2) return "";
      var b = p[1].replace(/-/g, "+").replace(/_/g, "/"); while (b.length % 4) b += "=";
      var c = JSON.parse(decodeURIComponent(atob(b).split("").map(function (x){
        return "%" + ("00" + x.charCodeAt(0).toString(16)).slice(-2); }).join("")));
      return limpiarUser(c.username || c.user || c.preferred_username ||
                         c.nombre_usuario || c.name || c.sub || "");
    } catch (e) { return ""; }
  }

  /* Un nombre de usuario, limpio: sin @, sin espacios, sin saltos de linea.
     La celda del header suele traer la etiqueta abajo, en otra linea. */
  function limpiarUser(t){
    t = String(t || "").replace(/^@+/, "").trim();
    t = t.split(/[\s\r\n]+/)[0] || "";
    return /^[\w.\-]{2,50}$/.test(t) ? t.slice(0, 50) : "";
  }

  /* Busca en un JSON cualquiera un campo que sea el nombre de usuario.
     'usuario' NO esta en la lista a proposito: es el campo que devuelve
     NUESTRA API, y engancharlo seria detectarnos a nosotros mismos. */
  var CLAVES_USER = ["login", "username", "userName", "user_name", "nombre_usuario"];

  function buscarUser(o, prof){
    if (!o || typeof o !== "object" || prof > 4) return "";
    for (var k in o){
      if (!Object.prototype.hasOwnProperty.call(o, k)) continue;
      var v = o[k];
      if (typeof v === "string" && v && CLAVES_USER.indexOf(k) !== -1){
        var u = limpiarUser(v);
        if (u) return u;
      } else if (v && typeof v === "object"){
        var r = buscarUser(v, prof + 1);
        if (r) return r;
      }
    }
    return "";
  }

  /* ---------------------------------------------------------------------
   * Espia de red. Mira lo que MANDA la plataforma (el login) y tambien lo
   * que RECIBE: cualquier respuesta autenticada del SPA trae el usuario.
   * Eso cubre al que ya estaba adentro, que nunca dispara un login.
   *
   * Solo se lee el nombre de usuario. La contraseña no se toca, no se guarda
   * y no sale de la pagina.
   * ------------------------------------------------------------------- */
  (function espiarRed(){
    var XHR = window.XMLHttpRequest;
    if (XHR && !XHR.__goldpaw){
      var send = XHR.prototype.send;
      XHR.prototype.send = function (body){
        try {
          if (typeof body === "string" && body.indexOf('"login"') !== -1){
            var u = buscarUser(JSON.parse(body), 0);
            if (u) visto = u;
          }
        } catch (e) {}
        try {
          this.addEventListener("load", function (){
            try {
              var t = this.responseType;
              if (t !== "" && t !== "text" && t !== "json") return;
              var cuerpo = (t === "json") ? this.response : this.responseText;
              if (typeof cuerpo === "string"){
              
                if (cuerpo.length > 300000) return;
                if (cuerpo.indexOf('"login"') === -1 && cuerpo.indexOf('"username"') === -1) return;
                cuerpo = JSON.parse(cuerpo);
              }
              var u2 = buscarUser(cuerpo, 0);
              if (u2) visto = u2;
            } catch (e) {}
          });
        } catch (e) {}
        return send.apply(this, arguments);
      };
      XHR.__goldpaw = true;
    }

    // El SPA puede usar fetch en vez de XHR segun la pantalla.
    if (window.fetch && !window.fetch.__goldpaw){
      var orig = window.fetch;
      var envuelto = function (){
        /* De paso registramos NUESTRAS llamadas. Como todas pasan por aca, con
           un solo punto quedan cubiertas chat, saldo, notificaciones y mensajes.
           Las de la plataforma se ignoran: son decenas por minuto y no son
           nuestro problema. */
        var propia = "", arranque = 0;
        try {
          var a0 = arguments[0];
          var u0 = (typeof a0 === "string") ? a0 : (a0 && a0.url) || "";
          if (u0.indexOf("/api/") !== -1){
            propia   = u0.split("/api/")[1].split("?")[0];
            arranque = Date.now();
            log("->", propia);
          }
        } catch (e) {}

        var p = orig.apply(this, arguments);
        try {
          p.then(function (res){
            if (propia){
              var ms = Date.now() - arranque;
              // El 403 es el WAF de Hostinger, y es el que se disfraza de error
              // de CORS en la consola del navegador. Que se lea como lo que es.
              log(res.ok ? "<- ok" : "<- FALLO", propia, res.status,
                  res.status === 403 ? "(WAF de Hostinger, no es CORS)" : "",
                  ms + "ms");
              // Al VPS solo los fallos: los 200 ya se ven en el access.log.
              if (!res.ok) avisarVps("API-FALLO", { que: propia, cod: res.status });
            }
            try {
              // clone(): si le consumimos el body al SPA, le rompemos la pagina.
              res.clone().json().then(function (d){
                var u = buscarUser(d, 0);
                if (u) visto = u;
              }).catch(function (){});
            } catch (e) {}
            return res;
          }).catch(function (e){
            // Aca cae la red caida y el bloqueo por CORS de verdad: el navegador
            // no deja ver el status, asi que no hay mas dato que este.
            if (propia) log("<- SIN RESPUESTA", propia, String(e && e.message || e));
          });
        } catch (e) {}
        return p;
      };
      envuelto.__goldpaw = true;
      window.fetch = envuelto;
    }
  })();

  /* Ultimo recurso: el SPA suele dejar su sesion serializada en localStorage. */
  function usuarioDeStorage(){
    try {
      for (var i = 0; i < localStorage.length; i++){
        var k = localStorage.key(i);
        if (!k || k.indexOf("goldpaw") === 0) continue;
        var v = localStorage.getItem(k);
        if (!v || v.length > 20000) continue;
        if (v.indexOf('"login"') === -1 && v.indexOf('"username"') === -1) continue;
        try {
          var u = buscarUser(JSON.parse(v), 0);
          if (u) return u;
        } catch (e) {}
      }
    } catch (e) {}
    return "";
  }

  /* ---------------------------------------------------------------------
   * Quien esta logueado, leido del HEADER de la plataforma.
   *
   * El espia de arriba solo agarra al que se loguea con el widget ya cargado.
   * El que entro antes -o el que vuelve con la sesion abierta- no dispara
   * ningun XHR de login, y para ese el unico lugar del DOM que dice quien es,
   * es el bloque de usuario del header.
   *
   * NO se clickea ni se abre nada.
   *
   * OJO CON ESTO: los cuatro SEL_USER cuelgan del subarbol del MENU
   * DESPLEGABLE (user-block__menu). Eso da por sentado que el markup del menu
   * existe en el DOM aunque este cerrado, y un SPA puede perfectamente
   * renderizarlo recien al abrirlo. Si asi fuera, los cuatro devuelven ""
   * SIEMPRE, y al inspeccionar a mano no se nota: para copiar el selector hay
   * que abrir el menu, y con el menu abierto funciona.
   *
   * Por eso existe usuarioDelHeaderCerrado() mas abajo, que no depende del
   * menu. Si el diagnostico dice selector "cerrado", es exactamente esto.
   * ------------------------------------------------------------------- */
  var SEL_USER = [
    "#app > div.app__header-wrapper > header > div > div.header-desktop__right > " +
    "div.user-block > div.user-block__menu > div > div.user-block__menu-top-user > " +
    "div:nth-child(1) > div.user-block__menu-top-user-username",
    // El mismo nodo sin la cadena de 8 niveles: la clase es unica y sobrevive
    // a cualquier reacomodo del header.
    ".user-block__menu-top-user-username",
    // Y por si le agregan un sufijo al nombre de la clase (CSS modules, temas).
    '[class*="menu-top-user-username"]',
    '[class*="user-block__menu-top-user"] [class*="username"]'
  ];

  /* ---------------------------------------------------------------------
   * ABRIR EL MENU PARA LEER EL NOMBRE.
   *
   * Como el menu no existe en el DOM hasta que se lo despliega, la unica forma
   * de leerlo por DOM es abrirlo. En el bundle el ícono que lo abre es:
   *
   *   <div className={pt("border")}>
   *     <div onClick={()=>f(!d)} className={pt("user-wrapper")}> ... </div>
   *
   * y es el QUINTO hijo de user-block__top, que es el selector de abajo.
   *
   * CUIDADO — ACA NO VAN SELECTORES GENERICOS. En el header hay TRES
   * pt("user-wrapper") y dos de ellos llevan onClick:S, que es el mismo handler
   * que menu-top-logout: cerrar sesion. Un selector como
   * ".user-block__user-wrapper" tiene dos de tres probabilidades de DESLOGUEAR
   * al jugador en vez de abrirle el menu. Por eso solo se usa la posicion
   * exacta, verificada, y si no matchea no se prueba nada mas.
   * ------------------------------------------------------------------- */
  var SEL_ABRIR_MENU = [
    "#app > div.app__header-wrapper > header > div > div.header-desktop__right > " +
    "div.user-block > div.user-block__top > div:nth-child(5) > div",
    // El mismo nodo, sin la cadena completa. Sigue siendo posicional: el
    // 5º hijo de user-block__top, no "cualquier user-wrapper".
    ".user-block__top > div:nth-child(5) > div"
  ];

  /* ---------------------------------------------------------------------
   * PREGUNTARLE A LA PLATAFORMA. La fuente mas confiable de todas.
   *
   * El propio SPA usa GET /api/user/check para saber quien esta logueado, y la
   * sesion viaja en COOKIES (verificado: el bundle no guarda ningun token en
   * localStorage). Estando en el mismo origen, la cookie va sola.
   *
   * Ventajas sobre todo lo demas de este archivo:
   *   - no depende del markup ni de que exista el store de React;
   *   - anda apenas termina el login, sin esperar a que se repinte nada;
   *   - lo dice el servidor de la plataforma, no el navegador.
   *
   * Se guarda en localStorage para que la proxima carga arranque sabiendo
   * quien es, sin esperar la vuelta de la red.
   *
   * Es asincrono, asi que no puede devolver el nombre de una: deja el
   * resultado en `dePlataforma` y la proxima pasada de revisarSesion lo usa.
   * ------------------------------------------------------------------- */
  var dePlataforma  = ls("goldpaw_user") || "";
  var consultando   = false;
  var ultimaConsulta = 0;

  function preguntarPlataforma(forzar){
    if (consultando) return;
    // Sin esto seria una consulta cada 1,2 s por jugador.
    var ahora = Date.now();
    if (!forzar && ahora - ultimaConsulta < 15000) return;
    ultimaConsulta = ahora;
    consultando = true;

    try {
      fetch(API_PLATAFORMA_CHECK, {
        method: "GET",
        credentials: "include",          // la sesion es una cookie
        headers: { "Accept": "application/json" }
      })
        .then(function (r){ return r.ok ? r.json() : null; })
        .then(function (d){
          // La respuesta viene envuelta ({result:{...}}, {data:{...}}); se busca
          // el nombre donde sea que este, igual que con el espia de la red.
          var u = d ? limpiarUser(buscarUser(d, 0)) : "";
          if (u && u !== dePlataforma){
            dePlataforma = u;
            lss("goldpaw_user", u);
            log("la plataforma dice que sos:", u);
            avisarVps("PLATAFORMA", { u: u });
          } else if (!u && d){
            log("/api/user/check contesto pero sin usuario: sesion anonima");
          }
        })
        .catch(function (e){
          log("no pude consultar /api/user/check:", String(e && e.message || e));
        })
        .then(function (){ consultando = false; });
    } catch (e) { consultando = false; }
  }

  var delMenu   = "";        // lo que se leyo abriendo el menu
  var abriendo  = false;
  var intentos  = 0;
  var MAX_INTENTOS = 3;      // no insistir para siempre sobre la cara del jugador

  /* Abre el menu, lee, y lo vuelve a cerrar. Asincrono: React pinta el subarbol
     en el tick siguiente al click, no en el mismo.

     Se llama SOLO cuando ya fallo todo lo demas (el store y el DOM pasivo), y
     como maximo 3 veces por carga: el jugador no pidio abrir ese menu, asi que
     abrirselo es un costo que hay que justificar cada vez. */
  function leerAbriendoMenu(){
    if (abriendo || delMenu || intentos >= MAX_INTENTOS) return;

    var btn = null;
    for (var i = 0; i < SEL_ABRIR_MENU.length && !btn; i++){
      try { btn = document.querySelector(SEL_ABRIR_MENU[i]); } catch (e) {}
    }
    if (!btn){
      log("no encontre el boton del menu de usuario; no clickeo nada mas",
          "(un selector generico podria desloguear al jugador)");
      intentos = MAX_INTENTOS;      // no volver a intentar
      return;
    }

    abriendo = true; intentos++;
    log("abro el menu del usuario para leer el nombre (intento", intentos + ")");
    try { btn.click(); } catch (e) { abriendo = false; return; }

    setTimeout(function (){
      var u = "";
      try { u = usuarioDelHeader(); } catch (e) {}   // ahora el subarbol existe
      if (u){
        delMenu = u;
        log("leido del menu:", u);
        avisarVps("MENU-OK", { u: u });
      } else {
        log("abri el menu y igual no habia nombre");
      }
      // Cerrarlo: el jugador no pidio abrirlo. El mismo click lo alterna
      // (onClick es f(!d)), y ademas el menu se cierra con onMouseLeave.
      try { btn.click(); } catch (e) {}
      abriendo = false;
    }, 400);
  }

  // Que HAY alguien logueado, aunque no lleguemos a leer el nombre.
  var SEL_SESION = [
    "#app > div.app__header-wrapper > header > div > div.header-desktop__right > " +
    "div.user-block > div > div:nth-child(5)",
    ".user-block"
  ];

  /* Cual de los SEL_USER fue el que sirvio. Se anota para el diagnostico: si
     algun dia el 0 (la cadena larga) deja de funcionar y salva el 1, es el
     aviso temprano de que la plataforma reacomodo el header. Si NINGUNO
     matchea, esto queda en -1 y ahi hay que mirar el markup de nuevo. */
  var selUsado = "ninguno";

  /* ---------------------------------------------------------------------
   * EL ESTADO DE REACT. Esta es la capa que resuelve el problema de verdad;
   * las de abajo quedan de respaldo.
   *
   * Del bundle de la plataforma, desminificado:
   *
   *   const pt = ct("user-block");
   *   const {balance:t, user:{id:s, username:o}} = useSelector(v => v.auth);
   *   return <div className={pt()}>
   *            <div className={pt("top")}>
   *              <span className={pt("text")}>{t.toFixed(2)}</span>
   *            ...
   *            {d && <div className={pt("menu")}>
   *                    <div className={pt("menu-top-user-username")}>{o}</div>
   *
   * Dos cosas se leen ahi, y las dos rompen la deteccion por DOM:
   *
   *   1. el `d &&` es renderizado condicional: mientras el menu este cerrado
   *      —o sea siempre, salvo que el jugador lo despliegue— ese subarbol NO
   *      EXISTE en el DOM. Los cuatro SEL_USER cuelgan de ahi.
   *   2. user-block__text es el SALDO, no el nombre. Hay un solo pt("text")
   *      en todo el componente y es t.toFixed(2).
   *
   * Pero el dato esta en el store de Redux, que es de donde lo saca el propio
   * componente. Se llega por el fiber de React: <Provider store={...}> deja el
   * store en sus props, asi que se recorre el arbol hasta encontrarlo.
   *
   * Esto no depende del markup: la plataforma puede rediseniar el header entero
   * y sigue andando. Solo se rompe si cambian de Redux, y ahi todo lo de abajo
   * sigue como respaldo.
   * ------------------------------------------------------------------- */
  function storeDeReact(){
    try {
      var raiz = document.querySelector("#app") || document.body;
      var clave = null, ks = Object.keys(raiz);
      for (var i = 0; i < ks.length; i++){
        // React 18 pone __reactContainer$<hash> en el nodo raiz.
        if (ks[i].indexOf("__reactContainer$") === 0 || ks[i].indexOf("__reactFiber$") === 0){
          clave = ks[i]; break;
        }
      }
      if (!clave) return null;

      // Recorrido acotado: un arbol de React tiene miles de nodos y esto corre
      // cada 1,2 s. El Provider esta siempre cerca de la raiz.
      var cola = [raiz[clave]], n = 0;
      while (cola.length && n++ < 3000){
        var f = cola.shift();
        if (!f) continue;
        var p = f.memoizedProps;
        if (p && p.store && typeof p.store.getState === "function") return p.store;
        if (f.child)   cola.push(f.child);
        if (f.sibling) cola.push(f.sibling);
      }
    } catch (e) {}
    return null;
  }

  function authDeReact(){
    try {
      var st = storeDeReact();
      if (!st) return null;
      var s = st.getState();
      return (s && s.auth) || null;
    } catch (e) { return null; }
  }

  function usuarioDeReact(){
    try {
      var a = authDeReact();
      return (a && a.user) ? limpiarUser(a.user.username) : "";
    } catch (e) { return ""; }
  }

  /* El id de la plataforma. Es la MISMA columna que trae sync_usuarios.py, asi
     que sirve para casar sin ambiguedad aunque alguien se cambie el nombre. */
  function idDeReact(){
    try {
      var a = authDeReact();
      return (a && a.user && a.user.id) ? String(a.user.id) : "";
    } catch (e) { return ""; }
  }

  function saldoDeReact(){
    try {
      var a = authDeReact();
      var b = a && a.balance;
      return (typeof b === "number" && isFinite(b)) ? b : null;
    } catch (e) { return null; }
  }

  try { window.__gp_store = function (){
    var a = authDeReact();
    return a ? { usuario: usuarioDeReact(), id: idDeReact(), saldo: saldoDeReact(),
                 bonus: a.bonusBalance }
             : "no encontre el store de Redux";
  }; } catch (e) {}

  /* Ultimo manotazo: cualquier nodo del bloque de usuario que parezca un nombre.
   *
   * En el header de escritorio esto NO va a encontrar nada, y esta bien que sea
   * asi: mirando el bundle, .user-block__text es el saldo y el nombre solo se
   * dibuja dentro del menu desplegado. Queda por si alguna variante (el header
   * mobile, o un rediseño) si lo muestra, y porque no cuesta nada.
   *
   * Lo que resuelve el caso real es usuarioDeReact(), mas arriba. */
  function usuarioDelHeaderCerrado(){
    for (var i = 0; i < SEL_SALDO.length; i++){
      try {
        var nodos = document.querySelectorAll(SEL_SALDO[i]);
        for (var j = 0; j < nodos.length; j++){
          var t = (nodos[j].textContent || "").trim();
          if (!t) continue;
          if (/^[\d.,\s]+(ARS|USD|\$)?$/i.test(t)) continue;   // eso es el saldo
          var u = limpiarUser(t);
          if (u) return u;
        }
      } catch (e) {}
    }
    return "";
  }

  function usuarioDelHeader(){
    for (var i = 0; i < SEL_USER.length; i++){
      try {
        var n = document.querySelector(SEL_USER[i]);
        if (!n) continue;
        // textContent lee tambien lo que esta oculto por CSS, que es
        // justamente el caso del menu del usuario cuando esta cerrado.
        var u = limpiarUser(n.textContent);
        if (u){ selUsado = "SEL_USER[" + i + "]"; return u; }
      } catch (e) {}
    }

    // Ninguno del menu sirvio: probamos el header cerrado.
    var c = usuarioDelHeaderCerrado();
    if (c){ selUsado = "cerrado (.user-block__text)"; return c; }

    selUsado = "ninguno";
    return "";
  }

  /* ---------------------------------------------------------------------
   * Saldo en vivo. El header de la plataforma lo muestra siempre y nosotros ya
   * estamos adentro de esa pagina: es la forma mas barata y mas fresca de
   * saberlo. sync_usuarios.py tarda 5 minutos y le cuesta una pasada entera por
   * el panel de agentes; esto es gratis y es instantaneo.
   *
   * OJO: el numero lo manda el navegador. El server lo guarda en balance_web,
   * aparte del `balance` de confianza (ver migracion 17).
   * ------------------------------------------------------------------- */
  var SEL_SALDO = [
    "#app > div.app__header-wrapper > header > div > div.header-desktop__right > " +
    "div.user-block > div > div:nth-child(1) > div > span.user-block__text",
    ".user-block__text",
    '[class*="user-block__text"]'
  ];

  /* El header dice "1000.00 ARS": punto decimal, al reves del panel de agentes,
     que usa "1.440,40". Se decide por el ULTIMO separador que aparezca. */
  function numeroDe(txt){
    var s = String(txt || "").replace(/[^\d.,-]/g, "");
    if (!s) return null;
    var ultimaComa = s.lastIndexOf(","), ultimoPunto = s.lastIndexOf(".");
    if (ultimaComa > ultimoPunto)      s = s.replace(/\./g, "").replace(",", ".");
    else if (ultimoPunto > ultimaComa) s = s.replace(/,/g, "");
    else                               s = s.replace(/[.,]/g, "");
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  /* Se recorren TODOS los nodos que matchean, no solo el primero: la clase
     .user-block__text la usa tambien el nombre de usuario, y querySelector
     devolvia ese. De "NAHUELWIN26X" salia el numero 26 y se reportaba eso.
     Se queda con el primero que parezca plata de verdad. */
  function saldoDelHeader(){
    for (var i = 0; i < SEL_SALDO.length; i++){
      try {
        var nodos = document.querySelectorAll(SEL_SALDO[i]);
        for (var j = 0; j < nodos.length; j++){
          var t = (nodos[j].textContent || "").trim();
          // Tiene que ser un importe y nada mas: digitos, separadores y a lo
          // sumo la moneda. Un nombre de usuario con numeros no pasa.
          if (!/^[\d.,\s]+(ARS|USD|\$)?$/i.test(t)) continue;
          var v = numeroDe(t);
          if (v !== null) return v;
        }
      } catch (e) {}
    }
    return null;
  }

  /* Para diagnosticar desde la consola: __gp_saldo() */
  try { window.__gp_saldo = function (){
    return { store: saldoDeReact(), header: saldoDelHeader(),
             reportado: saldoReportado, usuario: USUARIO };
  }; } catch (e) {}

  /* ---------------------------------------------------------------------
   * El header de verdad, tal como esta AHORA en la pagina.
   *
   * Cuando la deteccion falla, adivinar el selector desde afuera no sirve:
   * hace falta ver el markup. Esto lo devuelve recortado, sin atributos de
   * imagen ni SVG, que es casi todo el volumen y no aporta nada.
   *
   * Trae ademas la lista de nodos candidatos con su texto, que suele alcanzar
   * para ver de una donde quedo el nombre.
   * ------------------------------------------------------------------- */
  function dumpHeader(){
    var out = [];
    try {
      var raiz = document.querySelector(".user-block") ||
                 document.querySelector('[class*="user-block"]') ||
                 document.querySelector("header");
      if (!raiz) return "NO ENCONTRE .user-block NI <header>: el widget no esta " +
                        "dentro de la pagina de la plataforma.";

      var html = raiz.outerHTML || "";
      html = html.replace(/<svg[\s\S]*?<\/svg>/gi, "<svg/>")
                 .replace(/ (src|srcset|style)="[^"]*"/gi, "");
      out.push("--- markup ---");
      out.push(html.length > 4000 ? html.slice(0, 4000) + " ...(recortado)" : html);

      out.push("--- nodos con texto ---");
      var todos = raiz.querySelectorAll("*");
      for (var i = 0; i < todos.length && out.length < 80; i++){
        var n = todos[i];
        // Solo hojas: los padres repiten el texto de los hijos.
        if (n.children.length) continue;
        var t = (n.textContent || "").trim();
        if (!t || t.length > 60) continue;
        out.push("  " + (n.className || n.tagName) + "  =>  " + JSON.stringify(t));
      }
    } catch (e) { out.push("error leyendo el header: " + e); }
    return out.join("\n");
  }

  try { window.__gp_header = dumpHeader; } catch (e) {}

  var saldoReportado = null;   // ultimo valor que confirmamos al server
  var saldoEnviando  = false;

  /* Freno para cuando el server contesta mal.
   *
   * "Solo cuando CAMBIA" alcanza mientras la respuesta sea OK, porque ahi se
   * anota el valor. Si falla no se anota nada, asi que al tick siguiente el
   * valor sigue siendo distinto del reportado y se reintenta... cada 1,2 s,
   * para siempre. Con el server caido eso son 50 requests por minuto por
   * jugador y una consola inusable.
   *
   * Se corta con espera creciente: 5s, 10s, 20s... hasta 5 minutos. */
  var fallosSaldo   = 0;
  var esperarHasta  = 0;

  function reportarSaldo(){
    if (saldoEnviando || !USUARIO) return;
    if (Date.now() < esperarHasta) return;

    // El store da el numero sin pasar por texto: ni separadores, ni moneda, ni
    // el riesgo de agarrar el nodo equivocado. El header queda de respaldo.
    var v = saldoDeReact();
    if (v === null) v = saldoDelHeader();
    // Solo cuando CAMBIA. Sin esto serian 50 requests por minuto por jugador
    // para escribir siempre el mismo numero.
    if (v === null || v === saldoReportado) return;

    saldoEnviando = true;
    var enviado = v;

    function fallo(motivo){
      fallosSaldo++;
      var espera = Math.min(5000 * Math.pow(2, fallosSaldo - 1), 300000);
      esperarHasta = Date.now() + espera;
      // Una sola linea por tanda, no una por intento.
      if (fallosSaldo === 1 || fallosSaldo % 5 === 0){
        log("saldo_reportar fallo (" + motivo + "); espero " +
            Math.round(espera / 1000) + "s antes de reintentar");
      }
    }

    fetch(API_SALDO, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ usuario: USUARIO, saldo: enviado })
    })
      .then(function (r){
        if (!r.ok){ fallo("HTTP " + r.status); return null; }
        return r.json();
      })
      .then(function (d){
        if (d && d.ok){
          saldoReportado = enviado;
          fallosSaldo = 0;          // anduvo: se levanta el freno
          esperarHasta = 0;
        } else if (d){
          fallo("el server dijo que no");
        }
      })
      .catch(function (e){ fallo(String(e && e.message || e)); })
      .then(function (){ saldoEnviando = false; });
  }

  function haySesionEnDom(){
    for (var i = 0; i < SEL_SESION.length; i++){
      try { if (document.querySelector(SEL_SESION[i])) return true; } catch (e) {}
    }
    return false;
  }

  /* Quien es, mirando todo lo que tenemos. El DOM primero porque es lo que se
     esta viendo AHORA; la red y el storage pueden traer al de la sesion previa. */
  function quienEs(){
    // El store primero: es la fuente de la que lee el propio header, y la unica
    // que no depende de que el jugador tenga el menu desplegado.
    // Lo que dijo la plataforma manda: lo afirma su servidor, no el navegador.
    if (dePlataforma){ selUsado = "plataforma (/api/user/check)"; return dePlataforma; }

    var r = usuarioDeReact();
    if (r){ selUsado = "react (auth.user.username)"; return r; }

    var d = usuarioDelHeader();
    if (d) return d;

    // Lo que se leyo abriendo el menu, si hubo que llegar a eso.
    if (delMenu){ selUsado = "menu abierto a mano"; return delMenu; }

    return visto || usuarioDeStorage() || userDeToken(AUTH);
  }

  /* Para diagnosticar desde chrome://inspect con el APK enchufado:
       __gp_quien()
     Devuelve que vio cada capa. Si las cuatro dan "", el problema es que el
     widget no esta corriendo dentro de la pagina de la plataforma. */
  try {
    window.__gp_quien = function (){
      return { plataforma: dePlataforma,
               react: usuarioDeReact(), id: idDeReact(),
               dom: usuarioDelHeader(), red: visto, storage: usuarioDeStorage(),
               token: userDeToken(AUTH), sesionEnDom: haySesionEnDom(),
               store: !!storeDeReact(),
               usando: USUARIO, origen: MISMO_ORIGEN ? "replica" : "directo" };
    };
  } catch (e) {}

  var sid = ls("goldpaw_sid");
  if (!sid){
    sid = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : ("s-" + Date.now() + "-" + Math.random().toString(36).slice(2));
    lss("goldpaw_sid", sid);
  }

  /* ---------- estilos ----------
   * Chat en vivo clásico: verde, cabecera oscura, fondo claro y burbujas tipo
   * mensajería. Se eligió así a propósito y no con la paleta violeta del sitio:
   * el jugador reconoce este patrón de cualquier otra app y no tiene que
   * aprender nada para saber que ahí adentro le contesta alguien.
   *
   * Todo va prefijado con gp- y con z-index altísimo porque esto se inyecta
   * DENTRO de la plataforma, que tiene sus propios estilos y no controlamos.
   */
  var VERDE = "#12b76a", VERDE_OSC = "#0e9a58", TEAL = "#0f5f54";

  var css =
  /* --- botón flotante --- */
  /* Contenedor: letrero ARRIBA + globo redondo abajo, pegados a la derecha */
  "#gp-fab-wrap{position:fixed;right:16px;bottom:78px;z-index:2147483000;display:none;flex-direction:column;align-items:flex-end;gap:9px}"+
  "#gp-fab-wrap.on{display:flex}"+
  /* letrero "Cargas/retiros" arriba del globo, con una flechita hacia abajo */
  "#gp-fab-tag{position:relative;background:#fff;color:" + TEAL + ";font:700 12.5px/1 system-ui,Segoe UI,Roboto,Arial,sans-serif;"+
  "padding:8px 10px;border-radius:13px;box-shadow:0 6px 16px rgba(0,0,0,.22);white-space:nowrap;letter-spacing:.2px;"+
  "display:flex;align-items:center;gap:5px}"+
  "#gp-fab-tag:after{content:'';position:absolute;bottom:-4px;right:23px;width:9px;height:9px;background:#fff;transform:rotate(45deg)}"+
  "#gp-fab-tag.oculto{display:none}"+
  /* dos flechitas que se turnan: mientras una baja la otra sube, en loop.
     Es el gesto universal de "tocá acá", pensado para que el ojo lo agarre
     de reojo sin tener que leer el texto. */
  "#gp-fab-tag svg{width:11px;height:11px;flex:none;fill:none;stroke:" + VERDE_OSC + ";stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}"+
  "#gp-fab-tag .fa1{animation:gp-flecha-baja 1.4s ease-in-out infinite}"+
  "#gp-fab-tag .fa2{animation:gp-flecha-sube 1.4s ease-in-out infinite}"+
  "@keyframes gp-flecha-baja{0%,100%{transform:translateY(-2px);opacity:.55}50%{transform:translateY(2px);opacity:1}}"+
  "@keyframes gp-flecha-sube{0%,100%{transform:translateY(2px);opacity:1}50%{transform:translateY(-2px);opacity:.55}}"+
  /* globo REDONDO, palpitando constante: zoom + anillo verde que se expande.
     El anillo (2do box-shadow) se ve aunque el zoom sea sutil. A PROPÓSITO sin
     prefers-reduced-motion: el palpito se pidió expresamente. */
  "#gp-fab{position:relative;width:58px;height:58px;border:0;border-radius:50%;cursor:pointer;padding:0;"+
  "background:linear-gradient(145deg," + VERDE + "," + VERDE_OSC + ");color:#fff;display:grid;place-items:center;"+
  "box-shadow:0 8px 24px rgba(18,183,106,.45);animation:gp-latido 1.5s ease-in-out infinite}"+
  "#gp-fab.quieto{animation:none}"+       /* pausado con el chat abierto */
  "@keyframes gp-latido{"+
  "0%{transform:scale(1);box-shadow:0 8px 20px rgba(18,183,106,.45),0 0 0 0 rgba(18,183,106,.55)}"+
  "50%{transform:scale(1.12);box-shadow:0 10px 24px rgba(18,183,106,.5),0 0 0 15px rgba(18,183,106,0)}"+
  "100%{transform:scale(1);box-shadow:0 8px 20px rgba(18,183,106,.45),0 0 0 0 rgba(18,183,106,0)}}"+
  "#gp-fab svg{width:27px;height:27px;fill:#fff;stroke:none}"+
  /* la burbujita roja con el contador, como en cualquier mensajería */
  "#gp-fab .pip{position:absolute;top:-3px;right:-3px;min-width:21px;height:21px;padding:0 5px;border-radius:11px;"+
  "background:#f43f5e;color:#fff;border:2px solid #fff;display:none;align-items:center;justify-content:center;"+
  "font:700 12px/1 system-ui,Segoe UI,Roboto,Arial,sans-serif}"+
  "#gp-fab.hay .pip{display:flex}"+

  /* --- panel --- */
  "#gp-panel{position:fixed;right:12px;left:12px;bottom:12px;max-width:390px;margin:0 auto;height:min(80vh,580px);"+
  "background:#e9e2dc;border-radius:14px;z-index:2147483001;display:none;flex-direction:column;overflow:hidden;"+
  "box-shadow:0 24px 60px rgba(0,0,0,.45);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial,sans-serif;color:#111b21}"+
  "#gp-panel.open{display:flex}"+
  /* mobile: ocupaba casi toda la pantalla y tapaba el juego de atras.
     Se achica el alto y se le deja un margen fijo arriba, asi respira. */
  "@media (max-width:480px){"+
  "#gp-panel{right:8px;left:8px;bottom:8px;top:64px;height:auto;max-width:none;border-radius:12px}"+
  ".gp-h{padding:9px 12px}.gp-h .a{width:34px;height:34px}.gp-h b{font-size:14px}.gp-h .s{font-size:11px}"+
  ".gp-b{padding:10px 9px;gap:5px}.gp-bub{font-size:13.5px;padding:7px 10px}"+
  ".gp-q{padding:7px 8px 2px}.gp-q button{padding:7px 4px;font-size:11.5px}"+
  ".gp-f textarea{min-height:38px;font-size:14px;padding:10px 13px}"+
  ".gp-f .snd{width:38px;height:38px}.gp-f .att{width:34px;height:34px}"+
  "}"+

  /* --- cabecera --- */
  ".gp-h{display:flex;align-items:center;gap:11px;padding:11px 14px;background:" + TEAL + ";flex:none;color:#fff}"+
  ".gp-h .a{position:relative;width:40px;height:40px;border-radius:50%;flex:none;overflow:hidden;display:grid;place-items:center;"+
  "background:linear-gradient(160deg,#1d7d6e," + TEAL + ")}"+
  ".gp-h .a svg{width:21px;height:21px;fill:#fff;stroke:none}"+
  ".gp-h .a img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}"+
  ".gp-h b{font-size:15px;font-weight:600;display:block;line-height:1.25}"+
  ".gp-h .s{color:#b9ded7;font-size:12px;display:flex;align-items:center;gap:5px}"+
  ".gp-h .dot{width:7px;height:7px;border-radius:50%;background:#5ee9a4}"+
  ".gp-h .x{margin-left:auto;background:0;border:0;color:#cfe9e3;font-size:26px;cursor:pointer;line-height:1;padding:0 4px}"+
  ".gp-h .x:hover{color:#fff}"+

  /* --- cuerpo --- */
  ".gp-b{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 12px;display:flex;flex-direction:column;gap:7px}"+
  ".gp-r{display:flex;max-width:82%}.gp-r.u{align-self:flex-end}.gp-r.b{align-self:flex-start}"+
  ".gp-bub{padding:8px 11px;border-radius:9px;font-size:14px;white-space:pre-wrap;word-break:break-word;"+
  "box-shadow:0 1px 1px rgba(11,20,26,.13)}"+
  ".gp-r.u .gp-bub{background:#d9fdd3;border-top-right-radius:2px}"+
  ".gp-r.b .gp-bub{background:#fff;border-top-left-radius:2px}"+
  ".gp-bub img{max-width:170px;border-radius:7px;display:block;margin-top:2px}"+
  ".gp-bub a{color:#0a7cff}"+
  /* hora + tildes flotadas abajo a la derecha, como WhatsApp: el texto las envuelve */
  ".gp-meta{float:right;margin:6px -3px -2px 9px;font-size:10.5px;color:#667781;line-height:1;white-space:nowrap;user-select:none}"+
  ".gp-r.u .gp-meta{color:#5c8a72}"+
  ".gp-tk{margin-left:3px;letter-spacing:-2px}"+
  ".gp-tk.rd{color:#53bdeb}"+
  /* aviso del sistema: centrado, sin remitente, como el "Conectado" */
  ".gp-sys{align-self:center;background:#d3edf7;color:#37697f;font-size:12.5px;padding:6px 13px;border-radius:9px;"+
  "max-width:90%;text-align:center;box-shadow:0 1px 1px rgba(11,20,26,.1)}"+
  ".gp-dots span{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:#93a0a6;animation:gpb 1s infinite}"+
  ".gp-dots span:nth-child(2){animation-delay:.15s}.gp-dots span:nth-child(3){animation-delay:.3s}"+
  "@keyframes gpb{0%,60%,100%{opacity:.25}30%{opacity:1}}"+

  /* --- atajos --- */
  ".gp-q{display:flex;gap:7px;padding:9px 10px 3px;flex:none;background:#e9e2dc}"+
  ".gp-q button{flex:1;padding:9px 4px;border-radius:7px;cursor:pointer;font:700 12.5px/1 inherit;letter-spacing:.4px;"+
  "border:1px solid #c3ccd0;background:#fff;color:#3f5661;transition:.12s}"+
  ".gp-q button:hover{border-color:" + VERDE + ";color:" + VERDE_OSC + "}"+
  ".gp-q button.ok{background:" + VERDE + ";border-color:" + VERDE + ";color:#fff}"+
  ".gp-q button.ok:hover{background:" + VERDE_OSC + ";color:#fff}"+
  ".gp-q button:disabled{opacity:.5;cursor:default}"+

  /* --- barra de escritura --- */
  ".gp-f{display:flex;gap:7px;align-items:flex-end;padding:9px 10px 10px;flex:none;background:#e9e2dc}"+
  ".gp-f .att{width:40px;height:40px;flex:none;border:0;background:0;color:#5c7079;display:grid;place-items:center;cursor:pointer;padding:0}"+
  ".gp-f .att:hover{color:" + TEAL + "}"+
  ".gp-f .snd{width:44px;height:44px;flex:none;border:0;border-radius:50%;display:grid;place-items:center;cursor:pointer;padding:0;"+
  "background:" + VERDE + ";color:#fff;box-shadow:0 2px 6px rgba(18,183,106,.4)}"+
  ".gp-f .snd:disabled{background:#a8b6bc;box-shadow:none}"+
  ".gp-f .att svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:2}"+
  ".gp-f .snd svg{width:19px;height:19px;fill:#fff;stroke:none}"+
  ".gp-f textarea{flex:1;min-height:44px;max-height:110px;padding:12px 15px;border-radius:22px;border:0;background:#fff;"+
  "color:#111b21;resize:none;outline:none;font-family:inherit;font-size:15px;line-height:1.3;"+
  "box-shadow:0 1px 1px rgba(11,20,26,.13)}"+
  ".gp-f textarea::placeholder{color:#8696a0}";
  var st = document.createElement("style"); st.textContent = css;
  document.head.appendChild(st);

  // ---------- DOM ----------
  // Globo de chat relleno, como el de cualquier mensajería.
  var SVG_GLOBO = '<svg viewBox="0 0 24 24"><path d="M12 3C6.9 3 3 6.6 3 11c0 2.2 1 4.2 2.7 5.6L5 21l4.6-2.1c.8.2 1.6.3 2.4.3 5.1 0 9-3.6 9-8s-3.9-8-9-8z"/></svg>';

  var esc = function (s){
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;")
      .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  };

  var fab = document.createElement("button");
  fab.id = "gp-fab";
  fab.setAttribute("aria-label", "Cargas y retiros — abrir chat");
  fab.innerHTML = SVG_GLOBO + '<i class="pip">1</i>';

  /* La foto del agente va sobre el ícono: si la URL falla, onerror la saca y
     abajo queda el globo. Así el avatar nunca aparece roto. */
  var avatar = '<div class="a">' + SVG_GLOBO +
    (AGENTE_FOTO ? '<img src="' + esc(AGENTE_FOTO) + '" alt="" onerror="this.remove()">' : '') +
    '</div>';

  var atajos = ATAJOS.map(function (a, i){
    return '<button type="button" class="' + a.clase + '" data-i="' + i + '">' + esc(a.rot) + '</button>';
  }).join("");

  var panel = document.createElement("div");
  panel.id = "gp-panel";
  panel.innerHTML =
    '<div class="gp-h">' + avatar +
    '<div><b>' + esc(AGENTE_NOMBRE) + '</b>'+
    '<div class="s"><span class="dot"></span>' + esc(AGENTE_ESTADO) + '</div></div>'+
    '<button class="x" id="gp-x" aria-label="Cerrar">&times;</button></div>'+
    '<div class="gp-b" id="gp-body"></div>'+
    '<div class="gp-q" id="gp-quick">' + atajos + '</div>'+
    '<form class="gp-f" id="gp-form">'+
    '<input type="file" id="gp-file" accept="image/*,application/pdf" hidden>'+
    '<button type="button" class="att" id="gp-att" aria-label="Adjuntar comprobante"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.48"/></svg></button>'+
    '<textarea id="gp-t" rows="1" placeholder="Escribí un mensaje…"></textarea>'+
    '<button type="submit" class="snd" id="gp-s" disabled aria-label="Enviar"><svg viewBox="0 0 24 24"><path d="M2.3 21.5 23 12 2.3 2.5 2.3 10l14.9 2-14.9 2z"/></svg></button>'+
    '</form>';

  // Letrero "Cargas/retiros" ARRIBA del globo, dentro de un contenedor común
  // (así el letrero queda quieto y el globo late por su cuenta).
  var tag = document.createElement("div");
  tag.id = "gp-fab-tag";
  tag.innerHTML =
    '<svg class="fa1" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>' +
    '<span>Cargas/retiros</span>' +
    '<svg class="fa2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>';

  var fabWrap = document.createElement("div");
  fabWrap.id = "gp-fab-wrap";
  fabWrap.appendChild(tag);
  fabWrap.appendChild(fab);

  document.body.appendChild(fabWrap);
  document.body.appendChild(panel);

  var $ = function (id) { return document.getElementById(id); };
  var body = $("gp-body"), text = $("gp-t"), snd = $("gp-s"), fileI = $("gp-file");
  var quick = [].slice.call(panel.querySelectorAll("#gp-quick button"));
  var pip = fab.querySelector(".pip");
  var sinLeer = 0;

  var historial = [];      // lo que ve el modelo
  var charla    = [];      // lo que ve el usuario (para repintar al reabrir)
  var enviando = false, saludado = false, lastAgentId = 0, timerAgente = null;

  /* ------------------------------------------------------------------
   * Persistencia. En una app esto importa: el usuario cierra y abre todo
   * el tiempo, y perder la conversacion cada vez seria insufrible.
   * ---------------------------------------------------------------- */
  function guardar(){
    lss(CHAT_KEY, JSON.stringify({
      u: USUARIO,
      charla: charla.slice(-MAX_GUARDADO),
      historial: historial.slice(-MAX_CONTEXTO),
      lastAgentId: lastAgentId
    }));
  }

  function restaurar(){
    var d;
    try { d = JSON.parse(ls(CHAT_KEY) || "null"); } catch (e) { return false; }
    // Si la charla guardada era de otro usuario, no se muestra.
    if (!d || (d.u || "") !== USUARIO) return false;
    charla    = d.charla || [];
    historial = d.historial || [];
    lastAgentId = d.lastAgentId || 0;
    charla.forEach(function (m){
      if (m.adj) pintarAdj(m.q, m.adj, true); else pintar(m.q, m.t, true);
    });
    return charla.length > 0;
  }

  function olvidar(){
    historial = []; charla = []; lastAgentId = 0;
    body.innerHTML = ""; saludado = false;
    lsd(CHAT_KEY);
  }

  // ---------- pintar ----------
  // Hora corta 24h, como WhatsApp.
  function hora(){
    return new Date().toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
  }

  // Estado en la cabecera: "en línea" (con puntito) o "escribiendo…" (sin puntito).
  function setEstado(txt, escribiendo){
    var s = panel.querySelector(".gp-h .s");
    if (!s) return;
    s.innerHTML = escribiendo ? esc(txt) : '<span class="dot"></span>' + esc(txt);
  }

  // Tildes tipo WhatsApp sobre un mensaje del usuario:
  //   "enviado"  -> ✓        "recibido" -> ✓✓ gris        "leido" -> ✓✓ azul
  function marcarTilde(fila, estado){
    if (!fila || !fila._tk) return;
    var tk = fila._tk;
    if (estado === "recibido"){ tk.textContent = "✓✓"; tk.className = "gp-tk"; }
    else if (estado === "leido"){ tk.textContent = "✓✓"; tk.className = "gp-tk rd"; }
    else { tk.textContent = "✓"; tk.className = "gp-tk"; }
  }

  function pintar(quien, txt, mudo){
    var r = document.createElement("div"); r.className = "gp-r " + (quien === "u" ? "u" : "b");
    var b = document.createElement("div"); b.className = "gp-bub";

    // La meta (hora + tildes) va PRIMERA y flotada: así el texto la envuelve y
    // queda abajo a la derecha, como WhatsApp.
    var meta = document.createElement("span"); meta.className = "gp-meta";
    meta.appendChild(document.createTextNode(hora()));
    if (quien === "u"){
      var tk = document.createElement("span"); tk.className = "gp-tk"; tk.textContent = "✓";
      if (mudo){ tk.textContent = "✓✓"; tk.className = "gp-tk rd"; }   // historial: ya leído
      meta.appendChild(tk);
      r._tk = tk;
    }
    b.appendChild(meta);
    b.appendChild(document.createTextNode(txt));

    r.appendChild(b); body.appendChild(r); body.scrollTop = body.scrollHeight;
    if (!mudo){ charla.push({ q: quien, t: txt }); guardar(); }
    return r;
  }

  function pintarAdj(quien, adj, mudo){
    var r = document.createElement("div"); r.className = "gp-r " + (quien === "u" ? "u" : "b");
    var b = document.createElement("div"); b.className = "gp-bub";
    var a = document.createElement("a"); a.href = adj.url; a.target = "_blank"; a.rel = "noopener";
    if (adj.tipo === "imagen"){
      var im = document.createElement("img"); im.src = adj.url; im.alt = "Comprobante"; a.appendChild(im);
    } else {
      a.textContent = "📄 " + (adj.nombre || "Comprobante.pdf");
    }
    b.appendChild(a); r.appendChild(b); body.appendChild(r); body.scrollTop = body.scrollHeight;
    if (!mudo){ charla.push({ q: quien, adj: adj }); guardar(); }
    return r;
  }

  function escribiendo(){
    var r = document.createElement("div"); r.className = "gp-r b";
    r.innerHTML = '<div class="gp-bub gp-dots"><span></span><span></span><span></span></div>';
    body.appendChild(r); body.scrollTop = body.scrollHeight;
    return r;
  }

  /* Aviso centrado, sin remitente. NO se guarda en `charla` a propósito: es
     estado de la conexión, no parte de la conversación, y repintarlo al
     reabrir el chat sería confuso. */
  function pintarSistema(txt){
    var d = document.createElement("div");
    d.className = "gp-sys"; d.textContent = txt;
    body.appendChild(d); body.scrollTop = body.scrollHeight;
    return d;
  }

  // ---------- abrir / cerrar ----------
  function saludar(){
    saludado = true;
    pintarSistema("Conectado. Escribinos y te respondemos.");
    pintar("b", USUARIO
      ? "¡Hola " + USUARIO + "! Soy Camila, del equipo de atención. ¿En qué te puedo ayudar con tu cuenta o tus fichas?"
      : "¡Hola! Soy Camila, del equipo de atención. Te ayudo con tu cuenta, tus fichas y las recargas. ¿Me decís tu nombre de usuario del juego?");
  }

  function reiniciarCharla(){
    olvidar();
    if (panel.classList.contains("open")) saludar();
  }

  function abrir(){
    panel.classList.add("open");
    fab.classList.remove("hay");
    fab.classList.add("quieto");   // con el chat abierto el latido no hace falta
    tag.classList.add("oculto");   // y el letrero tampoco
    sinLeer = 0;
    if (!saludado) saludar();
    pedirPermisoNavegador();   // gesto del usuario: momento válido para pedirlo
    audioDespertar();          // y para habilitar el sonidito (autoplay lo exige)
    // Arrancar en el ÚLTIMO mensaje, no en el primero: mientras el panel estaba
    // oculto (display:none) el scroll de restaurar() no tomaba, así que se
    // reacomoda acá, ya visible. Dos veces por si el layout todavía no cerró.
    body.scrollTop = body.scrollHeight;
    setTimeout(function (){ body.scrollTop = body.scrollHeight; text.focus(); }, 150);
  }
  function cerrar(){ panel.classList.remove("open"); fab.classList.remove("quieto"); tag.classList.remove("oculto"); }

  fab.addEventListener("click", abrir);
  $("gp-x").addEventListener("click", cerrar);

  text.addEventListener("input", function (){
    snd.disabled = text.value.trim() === "";
    text.style.height = "auto";
    text.style.height = Math.min(text.scrollHeight, 110) + "px";
  });

  // Enter envía; Shift+Enter hace salto de línea (como WhatsApp Web).
  text.addEventListener("keydown", function (e){
    if (e.key === "Enter" && !e.shiftKey){
      e.preventDefault();
      enviarMensaje(text.value);
    }
  });

  // ---------- hablar con la IA ----------
  /* Un solo camino para el formulario y para los atajos: si cada uno armara su
     propio fetch, el día que cambie el contrato de la API se arregla uno y se
     olvida el otro. */
  function enviarMensaje(t){
    t = String(t || "").trim();
    if (!t || enviando) return;

    var miFila = pintar("u", t);           // guardamos la fila para el tilde
    historial.push({ role: "user", content: t });
    text.value = ""; text.style.height = "auto"; snd.disabled = true;
    enviando = true;
    quick.forEach(function (b){ b.disabled = true; });

    /* La linea que contesta tu pregunta: con QUE usuario sale el mensaje.
       Si dice "(anonimo)", el bot va a preguntar el nombre y en el CRM la
       charla cae como anon:<session_id>, por mas que conteste bien. */
    log("chat ->", { usuario: USUARIO || "(anonimo)", conToken: !!AUTH, sid: sid });
    avisarVps("chat", { u: USUARIO || "(anonimo)" });

    /* Secuencia WhatsApp: primero le llega y se LEE tu mensaje (las tildes se
       ponen azules) y RECIÉN DESPUÉS aparece "escribiendo…". La respuesta se
       revela cuando el server contestó Y el "escribiendo…" ya se vio un rato. */
    var datos, esperando = null, mostrado = false, typingListo = false;

    function revelar(){
      if (mostrado || datos === undefined || !typingListo) return;
      mostrado = true;
      if (esperando){ esperando.remove(); esperando = null; }
      setEstado(AGENTE_ESTADO, false);
      var d = datos;
      if (d && d.ok){
        pintar("b", d.respuesta);
        historial.push({ role: "assistant", content: d.respuesta });
        guardar();
        if (d.carga) narrarCarga(d.carga);   // narrar el proceso de la carga
      } else if (d && d._neterr){
        pintar("b", "⚠️ No pude conectar. Fijate si tenés señal y probá de nuevo.");
      } else {
        pintar("b", "⚠️ " + ((d && d.error) || "No pude responder ahora."));
      }
      enviando = false;
      quick.forEach(function (b){ b.disabled = false; });
      /* El server encola un "te contestamos" por cada respuesta. Lo consumimos
         ya mismo en vez de esperar al sondeo: si el jugador cierra la app en los
         próximos segundos, no queremos que le suene un aviso por un mensaje que
         acaba de leer acá. */
      setTimeout(mirarNotif, 600);
    }

    // Tildes: enviado (✓, ya está) -> recibido -> leído; y ahí sí, "escribiendo…".
    setTimeout(function (){ marcarTilde(miFila, "recibido"); }, 350);
    setTimeout(function (){
      marcarTilde(miFila, "leido");
      esperando = escribiendo();
      setEstado("escribiendo…", true);
      // "escribiendo…" tiene que verse un mínimo, aunque la respuesta ya llegó.
      setTimeout(function (){ typingListo = true; revelar(); }, 800);
    }, 1100);

    fetch(API_CHAT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        mensajes: historial.slice(-MAX_CONTEXTO),
        session_id: sid,
        usuario: USUARIO || undefined,
        // Si hay login propio, el server verifica la firma y ESE usuario manda
        // sobre el `usuario` de arriba, que lo dice el navegador.
        token: AUTH || undefined
      })
    })
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (d && !d.ok) log("chat <- ERROR del server:", d.error || "(sin detalle)");
        datos = d; revelar();
      })
      .catch(function (e){
        // Ojo: si el server contesto HTML (error de PHP, o la pagina del WAF),
        // el que falla es el .json() de arriba, no la red.
        log("chat <- NO SE PUDO LEER:", String(e && e.message || e));
        datos = { _neterr: true }; revelar();
      });
  }

  /* Narra el proceso de una carga como lo haría una persona: manda un mensaje,
     sondea carga_estado.php con el id que devolvió el chat, y va contando cada
     etapa (pendiente -> procesando -> hecha/error). Cada paso muestra el
     "escribiendo…" un instante antes, para que se sienta humano y no de golpe. */
  function narrarCarga(info){
    if (!info || !info.id) return;
    var id = info.id, intentos = 0, dijoProc = false;

    // Muestra el "escribiendo…", espera, y recién ahí pinta el mensaje.
    function decir(txt, cb){
      setEstado("escribiendo…", true);
      var esp = escribiendo();
      setTimeout(function (){
        esp.remove();
        setEstado(AGENTE_ESTADO, false);
        pintar("b", txt);
        if (cb) cb();
      }, 1500);
    }

    // Primer mensaje humano, apenas queda encolada.
    decir("Perfecto, dame un minutito que la proceso. 🙌", sondear);

    function sondear(){
      if (intentos++ > 40){   // ~2 min y medio de paciencia
        decir("La sigo procesando. Apenas se acredite te aviso por acá.");
        return;
      }
      fetch(API_CARGA + "?id=" + id + "&_=" + Date.now())
        .then(function (r){ return r.json(); })
        .then(function (d){
          if (!d || !d.ok){ setTimeout(sondear, 3500); return; }

          if (d.estado === "hecha"){
            decir("¡Listo! Ya te acredité la carga. En un ratito la vas a ver reflejada en tu panel. ✅");
            return;
          }
          if (d.estado === "error" || d.estado === "revisar" || d.estado === "cancelada"){
            decir("Uy, tuve un inconveniente para acreditarte esta carga. Ya la estoy revisando yo misma, quedate tranquilo.");
            return;
          }
          if (d.estado === "procesando" && !dijoProc){
            dijoProc = true;
            decir("Ya te ubiqué en el sistema, te estoy acreditando las fichas…", function (){ setTimeout(sondear, 3500); });
            return;
          }
          setTimeout(sondear, 3500);
        })
        .catch(function (){ setTimeout(sondear, 3500); });
    }
  }

  $("gp-form").addEventListener("submit", function (e){
    e.preventDefault();
    enviarMensaje(text.value);
  });

  /* Atajos. Mandan un mensaje común y corriente: el bot ya sabe qué hacer con
     "quiero cargar fichas" porque es lo que dice su CONTEXTO. Así no hay una
     segunda vía que mantener del lado del server. */
  $("gp-quick").addEventListener("click", function (e){
    var b = e.target.closest("button"); if (!b) return;
    var a = ATAJOS[+b.getAttribute("data-i")]; if (!a) return;
    abrir();
    enviarMensaje(a.txt);
  });

  // ---------- comprobantes ----------
  $("gp-att").addEventListener("click", function (){ fileI.click(); });

  fileI.addEventListener("change", function (){
    var f = fileI.files[0]; fileI.value = ""; if (!f) return;
    if (f.size > 8 * 1024 * 1024){ pintar("b", "⚠️ El archivo supera 8 MB."); return; }

    var fd = new FormData();
    fd.append("archivo", f); fd.append("session_id", sid);
    if (USUARIO) fd.append("usuario", USUARIO);

    var aviso = pintar("u", "📎 Enviando comprobante…", true);
    fetch(API_SUBIR, { method: "POST", body: fd })
      .then(function (r){ return r.json(); })
      .then(function (d){
        aviso.remove();
        if (d.ok && d.adjunto) pintarAdj("u", d.adjunto);
        else pintar("b", "⚠️ " + (d.error || "No pude subir el archivo."));
      })
      .catch(function (){ aviso.remove(); pintar("b", "⚠️ No pude subir el archivo."); });
  });

  // ---------- respuestas humanas del agente (CRM) ----------
  function mirarAgente(){
    fetch(API_MIS + "?session_id=" + encodeURIComponent(sid) + "&desde=" + lastAgentId)
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (d.ok && d.mensajes && d.mensajes.length){
          if (!panel.classList.contains("open")){
            sinLeer += d.mensajes.length;
            pip.textContent = sinLeer > 9 ? "9+" : String(sinLeer);
            fab.classList.add("hay");
          }
          d.mensajes.forEach(function (m){
            if (m.adjunto && m.adjunto.url) pintarAdj("b", m.adjunto); else pintar("b", m.texto);
          });
          /* Avisar (sonido + notificación en la barra) por la respuesta del
             agente, DIRECTO desde acá. El mensaje ya llegó por mis_mensajes
             (emparejado por session_id), así que el aviso no depende de que la
             cola de notificaciones esté dirigida a este device —que es lo que
             fallaba. La cola igual se consume abajo, en silencio, para que el
             worker del APK no lo repita con la app cerrada. */
          var ult = d.mensajes[d.mensajes.length - 1];
          notificarSO({
            id: 0,
            titulo: AGENTE_NOMBRE,
            cuerpo: (ult && ult.texto) ? ult.texto : "Nuevo mensaje",
            tipo: "chat",
            solo_app: true
          });
          mirarNotif();   // consume la cola; los solo_app se saltean sin re-avisar

        }
        if (typeof d.ultimo_id === "number" && d.ultimo_id !== lastAgentId){
          lastAgentId = d.ultimo_id; guardar();
        }
      })
      .catch(function (){});
  }

  /* ---------------------------------------------------------------------
   * El asistente esta SIEMPRE disponible, con o sin sesion: el que todavia
   * no entro tambien necesita ayuda (como registrarse, como recargar).
   *
   * Lo que si sigue la sesion es la identidad: al entrar adopta el usuario
   * de la plataforma y al salir lo olvida, para que dos personas en el mismo
   * telefono no compartan la conversacion.
   * ------------------------------------------------------------------- */
  var teniaSesion = null;   // null = todavia no miramos
  var ultimoDiag  = "";     // para no repetir la misma linea 50 veces por minuto
  var volcado     = false;  // el markup del header se vuelca una sola vez

  function revisarSesion(){
    // Alcanza con que UNA capa lo vea. ig_token solo no sirve: puede quedar en
    // localStorage despues de un logout a medias, y no dice quien es.
    var quien = quienEs();
    /* selUsado hay que copiarlo YA. Mas abajo el diagnostico llama a
       usuarioDelHeader() para mostrar que ve el DOM, y esa funcion lo pisa: sin
       esta copia, el registro dice "ninguno" justo cuando la deteccion
       funciono, que es la forma mas confusa posible de estar mal. */
    var capa  = selUsado;
    var hay   = !!quien || haySesionEnDom() || !!ls("ig_token");

    /* Esto corre cada 1,2 s: si logueara siempre, el registro seria inutil.
       Solo se anota cuando algo CAMBIA, asi el diario queda siendo la historia
       de lo que paso y no un goteo. */
    var firma = quien + "|" + hay + "|" + capa;
    if (firma !== ultimoDiag){
      log("deteccion", {
        usuario:  quien || "(nadie)",
        haySesion: hay,
        selector: capa,
        react:    usuarioDeReact() || "",
        id:       idDeReact() || "",
        dom:      usuarioDelHeader() || "",
        red:      visto || "",
        storage:  usuarioDeStorage() || "",
        token:    userDeToken(AUTH) || "",
        igToken:  !!ls("ig_token")
      });
      ultimoDiag = firma;
      avisarVps("deteccion", {
        u: quien || "(nadie)", sel: capa, id: idDeReact(),
        sesion: hay ? 1 : 0, store: storeDeReact() ? 1 : 0
      });

      /* Hay alguien logueado y no sabemos quien: este es EL caso a diagnosticar,
         y el markup es el unico dato que lo resuelve. Se vuelca una sola vez
         por carga (son varios KB) y solo cuando falla de verdad. */
      if (hay && !quien && !volcado){
        volcado = true;
        log("NO IDENTIFICO al jugador pero HAY sesion. Header:\n" + dumpHeader());
        // Al VPS va solo el aviso: el markup entero no entra en una query.
        avisarVps("SIN-IDENTIFICAR", { sel: capa, store: storeDeReact() ? 1 : 0 });
      }
    }

    /* Hay sesion y seguimos sin nombre: ultimo recurso, abrirle el menu.
       Va fuera del bloque de arriba porque ese solo corre cuando el estado
       CAMBIA, y esto tiene que poder reintentar en las pasadas siguientes
       (el header puede no estar montado todavia en la primera). */
    /* Preguntarle a la plataforma. Se hace SIEMPRE, no solo cuando falla lo
       demas: es lo unico que detecta el login en el momento en que ocurre, sin
       depender de que el header ya se haya repintado. Adentro se limita a una
       consulta cada 15 s. */
    preguntarPlataforma(false);

    if (hay && !quien){
      leerAbriendoMenu();
    }

    // Va aca porque este chequeo ya corre cada 1,2 s: el saldo del header se
    // mira siempre, pero solo sale una request cuando el numero cambia.
    reportarSaldo();

    // Entro: adoptamos el usuario del header (o el que le vimos escribir).
    if (hay && quien && quien !== USUARIO){
      log("ADOPTA usuario:", quien, "(antes:", USUARIO || "(ninguno)", ")");
      avisarVps("ADOPTA", { u: quien, sel: capa, id: idDeReact() });
      USUARIO = quien;
      lss("goldpaw_user", USUARIO);
      reiniciarCharla();
      notifRegistrar();     // este celular ahora es de este jugador
    }

    // Salio: se olvida de quien era.
    if (teniaSesion === true && !hay && USUARIO){
      log("SUELTA usuario:", USUARIO, "(cerro sesion)");
      avisarVps("SUELTA", { u: USUARIO });
      // Tambien lo que dijo la plataforma y lo leido del menu: si no, el
      // proximo que entre en este navegador hereda el nombre del anterior.
      USUARIO = ""; visto = ""; dePlataforma = ""; delMenu = ""; intentos = 0;
      lsd("goldpaw_user");
      reiniciarCharla();
      notifRegistrar();     // lo desatamos: que no reciba los avisos del otro
    }

    teniaSesion = hay;
  }

  /* =====================================================================
   * RULETA DE BONOS
   *
   * Mismo contrato que en la web (api/ruleta.php): se gira primero — el
   * SERVIDOR elige el premio y lo ata a un token, sin acreditar nada — y
   * recien al reclamar con un usuario valido se suma a usuarios.bonus.
   *
   * En la app, si el jugador ya inicio sesion no hay que preguntarle el
   * usuario: lo reclamamos con el que ya conocemos.
   * ===================================================================*/
  var RULETA_DIA = "goldpaw_ruleta_dia";

  var cssR =
  "#gpr-ov{position:fixed;inset:0;z-index:2147483002;display:none;align-items:center;justify-content:center;padding:18px;"+
  "background:rgba(6,3,15,.78);backdrop-filter:blur(3px);"+
  "font:14px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif;color:#eef0fb}"+
  "#gpr-ov.show{display:flex}"+
  ".gpr-card{position:relative;width:100%;max-width:340px;background:linear-gradient(170deg,#1a1440,#120d2b);"+
  "border:1px solid #2a2350;border-radius:20px;padding:22px 20px;text-align:center;box-shadow:0 26px 70px rgba(0,0,0,.65)}"+
  ".gpr-card h2{font-size:19px;margin:0 0 4px;font-weight:700}"+
  ".gpr-card .sub{color:#9aa0c4;font-size:13px;margin-bottom:16px}"+
  ".gpr-x{position:absolute;top:9px;right:11px;background:0;border:0;color:#9aa0c4;font-size:24px;line-height:1;cursor:pointer;padding:2px 6px}"+
  ".gpr-wrap{position:relative;width:206px;height:206px;margin:0 auto 16px}"+
  ".gpr-wheel{width:206px;height:206px;border-radius:50%;transition:transform 4.6s cubic-bezier(.12,.72,.12,1);"+
  "box-shadow:0 0 0 5px #241a5e,0 0 0 7px #3a2f7a,0 12px 34px rgba(0,0,0,.5)}"+
  ".gpr-pin{position:absolute;top:-8px;left:50%;transform:translateX(-50%);z-index:2;"+
  "width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-top:17px solid #E3B14A;"+
  "filter:drop-shadow(0 2px 3px rgba(0,0,0,.5))}"+
  ".gpr-hub{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;width:44px;height:44px;"+
  "border-radius:50%;background:linear-gradient(160deg,#241a5e,#120d2b);border:2px solid #3a2f7a;display:grid;place-items:center;"+
  "color:#E3B14A}"+
  ".gpr-hub svg{width:22px;height:22px}"+
  ".gpr-in{width:100%;padding:11px 13px;border-radius:11px;border:1px solid #2a2350;background:#1b1540;color:#eef0fb;"+
  "font-size:15px;outline:none;margin-bottom:10px;display:none;font-family:inherit}"+
  ".gpr-btn{width:100%;padding:13px;border:0;border-radius:12px;cursor:pointer;font:700 15px inherit;color:#fff;"+
  "background:linear-gradient(145deg,#9a8cff,#7c6cf0);box-shadow:0 10px 26px rgba(124,108,240,.4)}"+
  ".gpr-btn:disabled{opacity:.55}"+
  ".gpr-skip{width:100%;margin-top:9px;padding:10px;background:0;border:1px solid #2a2350;border-radius:11px;"+
  "color:#9aa0c4;cursor:pointer;font-size:13px;font-family:inherit}"+
  ".gpr-msg{font-size:13px;color:#ff8aa3;min-height:17px;margin-bottom:8px}"+
  ".gpr-res{display:none;font-size:13.5px;color:#eef0fb;margin-bottom:12px}"+
  ".gpr-res.show{display:block}.gpr-res b{display:block;font-size:23px;color:#E3B14A;margin-bottom:2px}"+
  ".gpr-res.nada b{color:#9aa0c4}"+
  /* contenedor: letrero "Girá y ganá!" arriba + globo dorado abajo, mismo
     patron visual que #gp-fab-wrap (chat) para que se lean como hermanos */
  "#gpr-fab-wrap{position:fixed;right:16px;bottom:196px;z-index:2147483000;display:none;"+
  "flex-direction:column;align-items:flex-end;gap:8px}"+
  "#gpr-fab-wrap.on{display:flex}"+
  "#gpr-fab-tag{position:relative;background:#fff;color:#8a5a00;font:700 12px/1 system-ui,Segoe UI,Roboto,Arial,sans-serif;"+
  "padding:7px 11px;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.22);white-space:nowrap;letter-spacing:.2px;"+
  "display:flex;align-items:center;gap:5px}"+
  "#gpr-fab-tag:after{content:'';position:absolute;bottom:-4px;right:20px;width:8px;height:8px;background:#fff;transform:rotate(45deg)}"+
  "#gpr-fab-tag.oculto{display:none}"+
  "#gpr-fab-tag svg{width:10px;height:10px;flex:none;fill:none;stroke:#E3B14A;stroke-width:2.8;stroke-linecap:round;stroke-linejoin:round;"+
  "animation:gpr-flecha-baja 1.4s ease-in-out infinite}"+
  "@keyframes gpr-flecha-baja{0%,100%{transform:translateY(-2px);opacity:.55}50%{transform:translateY(2px);opacity:1}}"+
  /* el globo late SOLO cuando queda un giro disponible hoy (.listo);
     ya reclamado hoy = quieto, para no insistir de gusto */
  "#gpr-fab{position:relative;width:48px;height:48px;border:0;border-radius:50%;cursor:pointer;"+
  "background:linear-gradient(145deg,#F0C567,#E3B14A);color:#4a2c00;display:grid;"+
  "place-items:center;box-shadow:0 8px 22px rgba(227,177,74,.45);padding:0}"+
  "#gpr-fab svg{width:26px;height:26px}"+
  "#gpr-fab.listo{animation:gpr-latido 1.6s ease-in-out infinite}"+
  "@keyframes gpr-latido{"+
  "0%{transform:scale(1);box-shadow:0 8px 20px rgba(227,177,74,.45),0 0 0 0 rgba(227,177,74,.55)}"+
  "50%{transform:scale(1.12);box-shadow:0 10px 24px rgba(227,177,74,.5),0 0 0 14px rgba(227,177,74,0)}"+
  "100%{transform:scale(1);box-shadow:0 8px 20px rgba(227,177,74,.45),0 0 0 0 rgba(227,177,74,0)}}"+
  "#gpr-fab:active{transform:scale(.94)}";
  var stR = document.createElement("style"); stR.textContent = cssR;
  document.head.appendChild(stR);

  // Ruleta de casino minimalista: aro exterior con la bolita, gajos marcados
  // por los radios internos y el eje central. Se lee como ruleta sin texto.
  // Declarado ANTES de armar el modal: se usa en el hub del centro.
  var SVG_RULETA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'+
    '<circle cx="12" cy="12" r="9.2"/>'+
    '<circle cx="12" cy="12" r="5"/>'+
    '<circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>'+
    '<path d="M12 6.8V2.8M12 21.2v-4M6.8 12H2.8M21.2 12h-4"/>'+
    '<path d="M8.46 8.46 5.63 5.63M18.37 18.37l-2.83-2.83M15.54 8.46l2.83-2.83M5.63 18.37l2.83-2.83"/>'+
    '<circle cx="12" cy="3.4" r="1.05" fill="currentColor" stroke="none"/>'+
    '</svg>';

  var ov = document.createElement("div");
  ov.id = "gpr-ov";
  ov.innerHTML =
    '<div class="gpr-card">'+
    '<button class="gpr-x" id="gpr-x" aria-label="Cerrar">&times;</button>'+
    '<h2>Giro <span style="color:#E3B14A">gratis</span></h2>'+
    '<div class="sub">Girá y reclamá tu premio en bonos</div>'+
    '<div class="gpr-wrap"><div class="gpr-pin"></div>'+
    '<svg class="gpr-wheel" id="gpr-wheel" viewBox="0 0 200 200"></svg>'+
    '<div class="gpr-hub">'+SVG_RULETA+'</div></div>'+
    '<div class="gpr-res" id="gpr-res"></div>'+
    '<input class="gpr-in" id="gpr-user" type="text" placeholder="Tu usuario del juego" autocomplete="username">'+
    '<div class="gpr-msg" id="gpr-msg"></div>'+
    '<button class="gpr-btn" id="gpr-spin" type="button">Girar</button>'+
    '<button class="gpr-skip" id="gpr-skip" type="button">Ahora no</button>'+
    '</div>';
  document.body.appendChild(ov);

  var fabR = document.createElement("button");
  fabR.id = "gpr-fab";
  fabR.setAttribute("aria-label", "Ruleta de bonos — girá y ganá");
  fabR.innerHTML = SVG_RULETA;

  var tagR = document.createElement("div");
  tagR.id = "gpr-fab-tag";
  tagR.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg><span>¡Girá y ganá!</span>';

  var fabRWrap = document.createElement("div");
  fabRWrap.id = "gpr-fab-wrap";
  fabRWrap.appendChild(tagR);
  fabRWrap.appendChild(fabR);
  document.body.appendChild(fabRWrap);

  var wheel = $("gpr-wheel"), inUser = $("gpr-user"), msgR = $("gpr-msg");
  var btnR = $("gpr-spin"), resR = $("gpr-res");

  var COLORES = [
    ["#00e5ff","#5b6cff"], ["#ff5fd0","#8a4bff"], ["#8a94a6","#5a6272"],
    ["#7dff9e","#18b46a"], ["#ffd34e","#ff5f6d"]
  ];
  var FALLBACK = [{label:"400 🎁"},{label:"1.000 🎁"},{label:"Nada 😢"},{label:"500 🎁"},{label:"2.000 🎁"}];

  var premios = [], rot = 0, fase = "girar", giro = null, girando = false;

  function pol(r, a){ var rad = Math.PI * a / 180; return [100 + r*Math.cos(rad), 100 + r*Math.sin(rad)]; }

  function dibujar(){
    var n = premios.length, g = 360 / n, defs = "<defs>", paths = "";
    COLORES.forEach(function (c, i){
      defs += '<linearGradient id="gprg'+i+'" x1="0%" y1="0%" x2="100%" y2="100%">'+
              '<stop offset="0%" stop-color="'+c[0]+'"/><stop offset="100%" stop-color="'+c[1]+'"/></linearGradient>';
    });
    defs += "</defs>";
    premios.forEach(function (p, i){
      var a0 = g*i, a1 = g*(i+1);
      var p1 = pol(100, a0), p2 = pol(100, a1), tc = pol(62, (a0+a1)/2);
      var large = (a1 - a0) > 180 ? 1 : 0;
      paths += '<path d="M100,100 L'+p1[0].toFixed(2)+','+p1[1].toFixed(2)+
               ' A100,100 0 '+large+',1 '+p2[0].toFixed(2)+','+p2[1].toFixed(2)+
               ' Z" fill="url(#gprg'+(i % COLORES.length)+')" stroke="rgba(0,0,0,.25)" stroke-width="0.5"/>';
      paths += '<text x="'+tc[0].toFixed(2)+'" y="'+tc[1].toFixed(2)+'" fill="#fff" font-size="9" font-weight="700"'+
               ' text-anchor="middle" dominant-baseline="middle"'+
               ' style="paint-order:stroke;stroke:rgba(0,0,0,.35);stroke-width:.6px">'+p.label+"</text>";
    });
    wheel.innerHTML = defs + paths;
  }

  function girarHasta(indice){
    var n = premios.length, g = 360 / n;
    var destino = 270 - (g*indice + g/2);          // el puntero mira arriba
    rot += 360*20 + ((destino - (rot % 360)) + 360) % 360;
    wheel.style.transform = "rotate(" + rot + "deg)";
  }

  function abrirRuleta(){
    ov.classList.add("show");
    lss(RULETA_DIA, new Date().toISOString().slice(0, 10));
    // Ya la va a jugar ahora: el palpito y el letrero dejaron de tener sentido.
    fabR.classList.remove("listo");
    tagR.classList.add("oculto");
  }
  function cerrarRuleta(){ ov.classList.remove("show"); }

  fabR.addEventListener("click", abrirRuleta);
  $("gpr-x").addEventListener("click", cerrarRuleta);
  $("gpr-skip").addEventListener("click", cerrarRuleta);

  function reclamar(usuario){
    btnR.disabled = true; btnR.textContent = "Reclamando…"; msgR.textContent = "";
    fetch(API_RULETA, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion: "reclamar", token: giro.token, usuario: usuario })
    })
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (d.ok){
          resR.className = "gpr-res show";
          resR.innerHTML = "<b>🎉 +" + d.bonus + " bonos</b>Acreditados en tu cuenta. ¡Volvé mañana!";
          inUser.style.display = "none";
          btnR.style.display = "none";
          $("gpr-skip").textContent = "Listo";
        } else {
          msgR.textContent = d.error || "No se pudo reclamar.";
          btnR.disabled = false; btnR.textContent = "Reclamar premio";
        }
      })
      .catch(function (){
        msgR.textContent = "No se pudo conectar. Probá de nuevo.";
        btnR.disabled = false; btnR.textContent = "Reclamar premio";
      });
  }

  function doGirar(){
    girando = true; btnR.disabled = true; btnR.textContent = "Girando…"; msgR.textContent = "";
    fetch(API_RULETA, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion: "girar", session_id: sid })
    })
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (!d.ok || typeof d.indice !== "number"){
          msgR.textContent = d.error || "No se pudo girar.";
          btnR.disabled = false; btnR.textContent = "Girar"; girando = false;
          return;
        }
        giro = d;
        girarHasta(d.indice);
        setTimeout(function (){
          girando = false;
          if (d.reclamado){
            resR.className = "gpr-res show nada";
            resR.innerHTML = "<b>" + d.label + "</b>" + (d.mensaje || "Ya reclamaste tu premio de hoy.");
            btnR.style.display = "none";
            return;
          }
          if (d.bonus > 0){
            resR.className = "gpr-res show";
            if (USUARIO){
              // Ya sabemos quien es: se reclama solo.
              resR.innerHTML = "<b>🎉 ¡Salió " + d.label + "!</b>Acreditando a " + USUARIO + "…";
              fase = "reclamar";
              reclamar(USUARIO);
            } else {
              resR.innerHTML = "<b>🎉 ¡Salió " + d.label + "!</b>Ingresá tu usuario para reclamarlo en bonos.";
              inUser.style.display = "block"; inUser.focus();
              btnR.disabled = false; btnR.textContent = "Reclamar premio";
              fase = "reclamar";
            }
          } else {
            resR.className = "gpr-res show nada";
            resR.innerHTML = "<b>" + d.label + "</b>¡Suerte la próxima! Volvé mañana.";
            btnR.style.display = "none";
          }
        }, 4700);   // dura lo que la animacion de la rueda
      })
      .catch(function (){
        msgR.textContent = "No se pudo conectar. Probá de nuevo.";
        btnR.disabled = false; btnR.textContent = "Girar"; girando = false;
      });
  }

  btnR.addEventListener("click", function (){
    if (girando) return;
    if (fase === "girar") { doGirar(); return; }
    var u = (USUARIO || inUser.value).trim();
    if (!u){ msgR.textContent = "Escribí tu usuario del juego."; inUser.focus(); return; }
    reclamar(u);
  });

  function cargarRuleta(){
    // El FAB tiene que aparecer SIEMPRE, pase lo que pase con la API o con
    // el dibujo de la rueda -- eso se decide acá, no adentro del .then() de
    // la red, para que un fetch caído o un error de dibujo no lo tumben.
    fabRWrap.classList.add("on");
    var yaJugoHoy = ls(RULETA_DIA) === new Date().toISOString().slice(0, 10);
    fabR.classList.toggle("listo", !yaJugoHoy);
    tagR.classList.toggle("oculto", yaJugoHoy);

    fetch(API_RULETA)
      .then(function (r){ return r.json(); })
      .then(function (d){ premios = (d.premios && d.premios.length) ? d.premios : FALLBACK; })
      .catch(function (){ premios = FALLBACK; })
      .then(function (){
        try { dibujar(); } catch (e) { log("ruleta: error al dibujar", String(e && e.message || e)); }
        // Se abre sola una vez por dia, para no ser pesada en cada apertura.
        if (!yaJugoHoy){
          setTimeout(abrirRuleta, 900);
        }
      });
  }

  /* =====================================================================
   * NOTIFICACIONES
   *
   * No hay Firebase: el server deja los avisos en una cola y cada dispositivo
   * los va a buscar. Hay DOS sondeos, y se reparten el trabajo solos:
   *
   *   - Este, cada 25 s, mientras la app esta abierta y a la vista. Muestra
   *     una tarjeta arriba de todo, que es mucho mejor que una notificacion
   *     del sistema para alguien que ya esta mirando la pantalla.
   *   - El del APK (WorkManager, cada ~15 min), que corre con la app cerrada
   *     y muestra la notificacion en la barra de Android.
   *
   * Los dos usan el MISMO device_id y el server entrega cada aviso una sola
   * vez, asi que nunca se duplica.
   *
   * El puente con el APK esta protegido con un token que MainActivity inyecta
   * en window.__gp_app_tk justo antes de este archivo. Los juegos vienen en
   * iframes de otros dominios y no pueden leer una variable del documento
   * padre, asi que no pueden usar el puente para hacerse pasar por otro
   * jugador y quedarse con sus notificaciones.
   * ===================================================================*/
  var NOTIF_MS   = 25000;
  var DEV_KEY    = "goldpaw_device";
  var APP        = (typeof window.GoldpawApp !== "undefined") ? window.GoldpawApp : null;
  var APP_TK     = window.__gp_app_tk || "";

  var DEVICE = ls(DEV_KEY);
  if (!DEVICE){
    DEVICE = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : ("d-" + Date.now() + "-" + Math.random().toString(36).slice(2));
    lss(DEV_KEY, DEVICE);
  }

  var notifRegistrado = null;    // con que usuario quedo registrado (null = todavia no)
  var timerNotif = null;

  /* ---------------------------------------------------------------------
   * Service Worker — SOLO para poder mostrar notificaciones del sistema en el
   * navegador del CELULAR. Chrome de Android PROHIBE new Notification() desde la
   * página; con un SW activo sí se puede con registration.showNotification().
   * En la compu new Notification() alcanza, pero el SW también sirve ahí.
   *
   * El SW NO intercepta la red (no tiene handler 'fetch'): no toca la plataforma
   * ni los juegos. Solo se registra en la réplica (mismo origen) y fuera del APK
   * (adentro las notificaciones van por el puente nativo, con el ícono del perro).
   * ------------------------------------------------------------------- */
  var swReg = null;
  if (!APP && MISMO_ORIGEN && "serviceWorker" in navigator){
    try {
      navigator.serviceWorker.register("/sw.js").catch(function (){});
      navigator.serviceWorker.ready.then(function (reg){ swReg = reg; }).catch(function (){});
    } catch (e) {}
  }

  /* ---------------------------------------------------------------------
   * Sonidito. Web Audio en vez de un mp3: no hay que subir ningún archivo y
   * suena igual en todos lados. Dos melodías distintas: una para el CHAT ("te
   * contestamos") y otra para el resto (bono, fichas, recarga), así se
   * distinguen sin mirar la pantalla.
   *
   * El AudioContext se despierta con un gesto del usuario (abrir el chat): los
   * navegadores no dejan sonar sin interacción previa. Con la pestaña a la vista
   * suena el sonidito; en segundo plano el sistema pone su propio sonido al
   * mostrar la notificación.
   * ------------------------------------------------------------------- */
  var _actx = null;
  function audioDespertar(){
    try {
      if (!_actx){
        var AC = window.AudioContext || window.webkitAudioContext;
        if (AC) _actx = new AC();
      }
      if (_actx && _actx.state === "suspended") _actx.resume();
    } catch (e) {}
  }
  function sonar(esChat){
    audioDespertar();
    if (!_actx) return;
    try {
      // chat: dos notas agudas cortas.  resto: un acordecito ascendente.
      var notas = esChat ? [784, 1175] : [523, 659, 880];
      var t0 = _actx.currentTime;
      for (var i = 0; i < notas.length; i++){
        var o = _actx.createOscillator(), g = _actx.createGain();
        o.type = "sine"; o.frequency.value = notas[i];
        o.connect(g); g.connect(_actx.destination);
        var t = t0 + i * 0.12;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.22, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
        o.start(t); o.stop(t + 0.13);
      }
    } catch (e) {}
  }

  var cssN =
  "#gpn-wrap{position:fixed;top:10px;left:10px;right:10px;z-index:2147483003;display:flex;flex-direction:column;gap:8px;"+
  "pointer-events:none;font:14px/1.45 system-ui,Segoe UI,Roboto,Arial,sans-serif}"+
  ".gpn{pointer-events:auto;max-width:400px;width:100%;margin:0 auto;display:flex;gap:11px;align-items:flex-start;"+
  "padding:12px 13px;border-radius:15px;background:linear-gradient(165deg,#1f1750,#150f36);border:1px solid #3a2f7a;"+
  "box-shadow:0 16px 40px rgba(0,0,0,.6);color:#eef0fb;cursor:pointer;"+
  "transform:translateY(-16px);opacity:0;transition:transform .25s,opacity .25s}"+
  ".gpn.on{transform:none;opacity:1}"+
  ".gpn .ic{flex:none;width:36px;height:36px;border-radius:11px;display:grid;place-items:center;font-size:19px;"+
  "background:linear-gradient(160deg,#F0C567,#E3B14A)}"+
  ".gpn .tx{flex:1;min-width:0}"+
  ".gpn .tt{font-weight:700;font-size:14px;margin-bottom:1px}"+
  ".gpn .cp{font-size:12.5px;color:#c7cbe6}"+
  ".gpn .cerrar{flex:none;background:0;border:0;color:#9aa0c4;font-size:20px;line-height:1;padding:0 2px;cursor:pointer}"+
  ".gpn.aviso{border-color:#E3B14A;background:linear-gradient(165deg,#2a1f4d,#1a1338)}"+
  ".gpn.aviso .ir{margin-top:7px;padding:7px 13px;border:0;border-radius:9px;cursor:pointer;"+
  "font:700 12.5px inherit;color:#4a2c00;background:linear-gradient(145deg,#F0C567,#E3B14A)}";
  var stN = document.createElement("style"); stN.textContent = cssN;
  document.head.appendChild(stN);

  var wrapN = document.createElement("div");
  wrapN.id = "gpn-wrap";
  document.body.appendChild(wrapN);

  var ICONO = { bono:"🎁", fichas:"🪙", recarga:"✅", ruleta:"🎯", promo:"🔥", aviso:"🔔" };

  function marcarLeida(id){
    fetch(API_NOTIF, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion: "leida", device_id: DEVICE, id: id })
    }).catch(function (){});
  }

  function pintarNotif(n){
    var card = document.createElement("div");
    card.className = "gpn";
    var ic = document.createElement("div");
    ic.className = "ic"; ic.textContent = ICONO[n.tipo] || ICONO.aviso;
    var tx = document.createElement("div"); tx.className = "tx";
    var tt = document.createElement("div"); tt.className = "tt"; tt.textContent = n.titulo;
    var cp = document.createElement("div"); cp.className = "cp"; cp.textContent = n.cuerpo;
    tx.appendChild(tt); tx.appendChild(cp);
    var x = document.createElement("button");
    x.className = "cerrar"; x.setAttribute("aria-label", "Cerrar"); x.innerHTML = "&times;";
    card.appendChild(ic); card.appendChild(tx); card.appendChild(x);
    wrapN.appendChild(card);
    requestAnimationFrame(function (){ card.classList.add("on"); });

    var ido = false;
    function sacar(){
      if (ido) return; ido = true;
      card.classList.remove("on");
      setTimeout(function (){ if (card.parentNode) card.parentNode.removeChild(card); }, 260);
    }
    x.addEventListener("click", function (e){ e.stopPropagation(); sacar(); });
    card.addEventListener("click", function (){
      marcarLeida(n.id);
      if (n.url) { try { location.href = n.url; } catch (e) {} }
      // Si es un "te contestamos", lo lógico al tocarlo es abrir el chat.
      else if (n.solo_app) abrir();
      sacar();
    });
    setTimeout(sacar, 9000);
  }

  /* ---------------------------------------------------------------------
   * Notificación del SISTEMA (barra de estado en el APK, o del navegador).
   *
   * Es lo que hace que la respuesta del chatbot se vea en la barra de tareas
   * aunque estés con la app/pestaña abierta. La tarjeta de arriba (pintarNotif)
   * es para cuando estás mirando la pantalla; esto es para el sistema.
   *
   * En el APK va por el puente nativo (con el ícono de la app, el perro). En el
   * navegador va por Web Notifications, con el logo como ícono.
   * ------------------------------------------------------------------- */
  function notificarSO(n){
    // Es un mensaje del chat ("te contestamos") o un aviso (bono/ficha/recarga).
    var esChat = !!(n.solo_app || n.tipo === "chat");

    // APK: barra de estado del sistema, ícono de la app (el perro). Trae su
    // propio sonido/vibración por el canal nativo, así que acá no sonamos.
    if (APP && APP_TK && typeof APP.notificar === "function"){
      try { APP.notificar(APP_TK, n.id || 0, n.titulo || "", n.cuerpo || "", n.url || ""); return; }
      catch (e) {}
    }

    // Navegador: primero el sonidito (suena aunque no haya permiso de notis),
    // después la notificación del sistema.
    sonar(esChat);
    try {
      if (typeof Notification === "undefined" || Notification.permission !== "granted") return;
      var opts = {
        body:     n.cuerpo || "",
        icon:     AGENTE_FOTO,
        badge:    AGENTE_FOTO,
        tag:      "gp-" + (n.id || Date.now()),
        renotify: true,
        data:     { url: n.url || "" }
      };
      // Preferir el Service Worker: es el ÚNICO que muestra notificaciones en
      // Chrome de Android. new Notification() queda de respaldo para la compu.
      if (swReg && swReg.showNotification){
        swReg.showNotification(n.titulo || "ganamos", opts);
        return;
      }
      var no = new Notification(n.titulo || "ganamos", opts);
      no.onclick = function (){
        try { window.focus(); } catch (e) {}
        marcarLeida(n.id);
        if (n.url) { try { location.href = n.url; } catch (e) {} }
        else { abrir(); }
        no.close();
      };
    } catch (e) {}
  }

  /* Pide el permiso de notificaciones del NAVEGADOR (el APK las pide aparte, en
     forma nativa). Se llama al abrir el chat, que es un gesto del usuario —
     los navegadores no dejan pedirlo sin interacción. Una sola vez. */
  function pedirPermisoNavegador(){
    if (APP) return;   // en el APK lo maneja el lado nativo
    try {
      if (typeof Notification !== "undefined" && Notification.permission === "default"){
        Notification.requestPermission().catch(function (){});
      }
    } catch (e) {}
  }

  /* ---------------------------------------------------------------------
   * Cartelito "activá las notificaciones".
   *
   * Android deja de mostrar el diálogo del permiso después del segundo "no", y
   * a partir de ahí la app no puede volver a pedirlo: el único camino son los
   * ajustes del sistema. Sin este cartel, el jugador queda sin notificaciones
   * para siempre y sin ninguna pista de cómo recuperarlas.
   *
   * Se muestra como máximo una vez por día, y solo dentro del APK.
   * ------------------------------------------------------------------- */
  var AVISO_KEY = "goldpaw_notif_aviso";
  var avisoN = null;

  function hoyISO(){ return new Date().toISOString().slice(0, 10); }

  function avisoPermiso(hacefalta){
    if (!hacefalta){
      if (avisoN){ avisoN.remove(); avisoN = null; }
      return;
    }
    if (avisoN || ls(AVISO_KEY) === hoyISO()) return;

    var card = document.createElement("div");
    card.className = "gpn aviso";
    var ic = document.createElement("div"); ic.className = "ic"; ic.textContent = "🔔";
    var tx = document.createElement("div"); tx.className = "tx";
    var tt = document.createElement("div"); tt.className = "tt";
    tt.textContent = "Activá las notificaciones";
    var cp = document.createElement("div"); cp.className = "cp";
    cp.textContent = "Enterate al toque de tus bonos, fichas y respuestas.";
    var btn = document.createElement("button");
    btn.className = "ir"; btn.type = "button"; btn.textContent = "Activar";
    tx.appendChild(tt); tx.appendChild(cp); tx.appendChild(btn);
    var x = document.createElement("button");
    x.className = "cerrar"; x.setAttribute("aria-label", "Cerrar"); x.innerHTML = "&times;";
    card.appendChild(ic); card.appendChild(tx); card.appendChild(x);
    wrapN.appendChild(card);
    requestAnimationFrame(function (){ card.classList.add("on"); });
    avisoN = card;

    btn.addEventListener("click", function (){
      // La app decide sola si todavía puede mostrar el diálogo del permiso o si
      // hay que mandarlo a los ajustes del sistema.
      try { APP.activar(APP_TK); } catch (e) {}
    });
    x.addEventListener("click", function (){
      lss(AVISO_KEY, hoyISO());       // no insistir hasta mañana
      avisoPermiso(false);
    });
  }

  /** Da de alta el celular (o actualiza a que jugador esta atado). */
  function notifRegistrar(){
    // Se marca ANTES de mandar: revisarSesion corre cada 1,2 s y sin esto se
    // dispararia un registro por tick hasta que conteste el server.
    notifRegistrado = USUARIO || "";

    var info = null;
    if (APP && APP_TK){
      try { info = JSON.parse(APP.info(APP_TK) || "null"); } catch (e) {}
    }
    // Si están apagadas, ofrecer el camino a los ajustes (y sacar el cartel
    // apenas las active, sin tener que reiniciar la app).
    avisoPermiso(!!(info && !info.permitido));

    var datos = {
      accion:     "registrar",
      device_id:  DEVICE,
      usuario:    USUARIO || undefined,
      plataforma: info ? "android" : "web",
      modelo:     info ? info.modelo : undefined,
      version:    info ? info.version : undefined,
      permitido:  info ? !!info.permitido : true,
      // Sin usuario Y sin sesion abierta = se fue. Distinto de "todavia no
      // sabemos quien es", donde el server tiene que dejar el celular como esta.
      soltar:     (!USUARIO && !ls("ig_token")) || undefined
    };
    fetch(API_NOTIF, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify(datos)
    })
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (!d.ok) { notifRegistrado = null; return; }
        // Que el APK sepa a quien sondear cuando la app este cerrada.
        if (APP && APP_TK){
          try { APP.vincular(APP_TK, DEVICE, USUARIO || ""); } catch (e) {}
        }
      })
      .catch(function (){ notifRegistrado = null; });   // reintenta en el proximo sondeo
  }

  function mirarNotif(){
    // En el APK, si la app no está a la vista la toma el worker nativo (barra de
    // estado). En el NAVEGADOR no hay worker: seguimos sondeando aunque la
    // pestaña esté en segundo plano, así podemos notificar igual.
    if (APP && document.visibilityState !== "visible") return;

    // Si el alta fallo, o el jugador cambio, se reintenta aca (cada 25 s) y no
    // en revisarSesion, que corre muchisimo mas seguido.
    if (notifRegistrado !== (USUARIO || "")) { notifRegistrar(); }

    fetch(API_NOTIF + "?accion=pendientes&device_id=" + encodeURIComponent(DEVICE))
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (!d.ok || !d.notificaciones) return;
        d.notificaciones.forEach(function (n){
          /* Los avisos de CHAT (solo_app) NO se avisan acá: los dispara
             mirarAgente cuando llega el mensaje por mis_mensajes.php (emparejado
             por session_id), así el aviso no depende de que la COLA esté dirigida
             a este device. Acá solo se CONSUMEN —el fetch de arriba ya los marcó
             entregados— para que el worker del APK no los repita con la app
             cerrada. Con la app cerrada, en cambio, mirarAgente no corre y el
             worker sí los muestra. */
          if (n.solo_app) return;

          /* El resto (push a mano, bono, fichas, recarga): sistema + tarjeta. */
          notificarSO(n);
          pintarNotif(n);
        });
      })
      .catch(function (){});
  }

  // ---------- arranque ----------
  saludado = restaurar();          // si habia charla previa, no vuelve a saludar
  fabWrap.classList.add("on");
  mirarAgente();
  timerAgente = setInterval(mirarAgente, 6000);
  revisarSesion();
  setInterval(revisarSesion, 1200);
  cargarRuleta();

  notifRegistrar();
  setTimeout(mirarNotif, 3000);
  timerNotif = setInterval(mirarNotif, NOTIF_MS);
  // Al volver de los ajustes del sistema hay que releer si ya las activó: si no,
  // el cartel se queda ahí aunque el permiso ya esté dado.
  document.addEventListener("visibilitychange", function (){
    if (document.visibilityState === "visible") notifRegistrar();
  });
  /* El permiso NO se pide desde acá: lo pide la app al arrancar. Android solo
     muestra el diálogo dos veces y después lo bloquea para siempre, así que dos
     lugares pidiéndolo se comen las dos oportunidades enseguida. Si quedó sin
     permiso, aparece el cartelito con el botón "Activar". */
})();
