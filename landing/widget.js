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

  var API_CHAT   = "https://orange-crab-483661.hostingersite.com/api/chatbot.php";
  var API_SUBIR  = "https://orange-crab-483661.hostingersite.com/api/subir.php";
  var API_MIS    = "https://orange-crab-483661.hostingersite.com/api/mis_mensajes.php";
  var API_RULETA = "https://orange-crab-483661.hostingersite.com/api/ruleta.php";
  var API_NOTIF  = "https://orange-crab-483661.hostingersite.com/api/notificaciones.php";
  var API_SALDO  = "https://orange-crab-483661.hostingersite.com/api/saldo_reportar.php";

  var CHAT_KEY = "goldpaw_chat";   // la charla guardada entre aperturas
  var MAX_GUARDADO = 40;           // cuantos mensajes se recuerdan
  var MAX_CONTEXTO = 20;           // cuantos turnos se le mandan al modelo

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
                // Filtro barato antes de parsear: estas respuestas pasan de a
                // decenas por minuto y no vale la pena parsearlas todas.
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
        var p = orig.apply(this, arguments);
        try {
          p.then(function (res){
            try {
              // clone(): si le consumimos el body al SPA, le rompemos la pagina.
              res.clone().json().then(function (d){
                var u = buscarUser(d, 0);
                if (u) visto = u;
              }).catch(function (){});
            } catch (e) {}
            return res;
          }).catch(function (){});
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
   * NO se clickea ni se abre nada: el markup del menu esta en el DOM aunque
   * este cerrado. Si algun dia deja de estarlo, esto devuelve "" y el
   * asistente vuelve a preguntar el usuario, como antes. Nunca rompe.
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

  // Que HAY alguien logueado, aunque no lleguemos a leer el nombre.
  var SEL_SESION = [
    "#app > div.app__header-wrapper > header > div > div.header-desktop__right > " +
    "div.user-block > div > div:nth-child(5)",
    ".user-block"
  ];

  function usuarioDelHeader(){
    for (var i = 0; i < SEL_USER.length; i++){
      try {
        var n = document.querySelector(SEL_USER[i]);
        if (!n) continue;
        // textContent lee tambien lo que esta oculto por CSS, que es
        // justamente el caso del menu del usuario cuando esta cerrado.
        var u = limpiarUser(n.textContent);
        if (u) return u;
      } catch (e) {}
    }
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
    return { leido: saldoDelHeader(), reportado: saldoReportado, usuario: USUARIO };
  }; } catch (e) {}

  var saldoReportado = null;   // ultimo valor que confirmamos al server
  var saldoEnviando  = false;

  function reportarSaldo(){
    if (saldoEnviando || !USUARIO) return;
    var v = saldoDelHeader();
    // Solo cuando CAMBIA. Sin esto serian 50 requests por minuto por jugador
    // para escribir siempre el mismo numero.
    if (v === null || v === saldoReportado) return;

    saldoEnviando = true;
    var enviado = v;
    fetch(API_SALDO, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ usuario: USUARIO, saldo: enviado })
    })
      .then(function (r){ return r.json(); })
      .then(function (d){ if (d && d.ok) saldoReportado = enviado; })
      .catch(function (){})           // se reintenta solo en el proximo cambio
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
    return usuarioDelHeader() || visto || usuarioDeStorage();
  }

  /* Para diagnosticar desde chrome://inspect con el APK enchufado:
       __gp_quien()
     Devuelve que vio cada capa. Si las cuatro dan "", el problema es que el
     widget no esta corriendo dentro de la pagina de la plataforma. */
  try {
    window.__gp_quien = function (){
      return { dom: usuarioDelHeader(), red: visto, storage: usuarioDeStorage(),
               sesionEnDom: haySesionEnDom(), usando: USUARIO };
    };
  } catch (e) {}

  var sid = ls("goldpaw_sid");
  if (!sid){
    sid = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : ("s-" + Date.now() + "-" + Math.random().toString(36).slice(2));
    lss("goldpaw_sid", sid);
  }

  // ---------- estilos ----------
  var css =
  "#gp-fab{position:fixed;right:16px;bottom:78px;width:56px;height:56px;border:0;border-radius:50%;cursor:pointer;z-index:2147483000;"+
  "background:linear-gradient(145deg,#4a7bff,#7a5cff);color:#fff;box-shadow:0 10px 28px rgba(74,123,255,.5);display:none;place-items:center;"+
  "transition:transform .15s;padding:0}"+
  "#gp-fab.on{display:grid}#gp-fab:active{transform:scale(.94)}#gp-fab svg{width:25px;height:25px;fill:none;stroke:#fff;stroke-width:2}"+
  "#gp-fab .pip{position:absolute;top:-2px;right:-2px;width:14px;height:14px;border-radius:50%;background:#37c17a;border:2px solid #120d2b;display:none}"+
  "#gp-fab.hay .pip{display:block}"+
  "#gp-panel{position:fixed;right:12px;left:12px;bottom:12px;max-width:400px;margin:0 auto;height:min(78vh,560px);"+
  "background:#120d2b;border:1px solid #2a2350;border-radius:18px;z-index:2147483001;display:none;flex-direction:column;overflow:hidden;"+
  "box-shadow:0 24px 60px rgba(0,0,0,.6);font:14px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif;color:#eef0fb}"+
  "#gp-panel.open{display:flex}"+
  ".gp-h{display:flex;align-items:center;gap:10px;padding:13px 15px;border-bottom:1px solid #2a2350;background:#1a1440;flex:none}"+
  ".gp-h .a{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:linear-gradient(160deg,#9a8cff,#7c6cf0);flex:none}"+
  ".gp-h .a svg{width:19px;height:19px;fill:none;stroke:#fff;stroke-width:2}.gp-h b{font-size:14.5px}.gp-h b span{color:#9a8cff}"+
  ".gp-h .s{color:#9aa0c4;font-size:11.5px;display:flex;align-items:center;gap:5px}.gp-h .dot{width:7px;height:7px;border-radius:50%;background:#37c17a}"+
  ".gp-h .x{margin-left:auto;background:0;border:0;color:#9aa0c4;font-size:26px;cursor:pointer;line-height:1;padding:0 4px}"+
  ".gp-b{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:15px;display:flex;flex-direction:column;gap:10px}"+
  ".gp-r{display:flex;max-width:88%}.gp-r.u{align-self:flex-end}.gp-r.b{align-self:flex-start}"+
  ".gp-bub{padding:9px 12px;border-radius:14px;font-size:13.5px;white-space:pre-wrap;word-break:break-word}"+
  ".gp-r.u .gp-bub{background:linear-gradient(135deg,#7c6cf0,#6a5be8);color:#fff;border-bottom-right-radius:5px}"+
  ".gp-r.b .gp-bub{background:#1b1540;border:1px solid #2a2350;border-bottom-left-radius:5px}"+
  ".gp-bub img{max-width:170px;border-radius:9px;display:block;margin-top:2px}"+
  ".gp-bub a{color:inherit}"+
  ".gp-dots span{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:#9a8cff;animation:gpb 1s infinite}"+
  ".gp-dots span:nth-child(2){animation-delay:.15s}.gp-dots span:nth-child(3){animation-delay:.3s}"+
  "@keyframes gpb{0%,60%,100%{opacity:.25}30%{opacity:1}}"+
  ".gp-f{display:flex;gap:7px;align-items:flex-end;padding:11px;border-top:1px solid #2a2350;flex:none}"+
  ".gp-f .att,.gp-f .snd{width:40px;height:40px;flex:none;border-radius:11px;display:grid;place-items:center;cursor:pointer;padding:0}"+
  ".gp-f .att{border:1px solid #2a2350;background:#1b1540;color:#9aa0c4}.gp-f .snd{border:0;background:linear-gradient(180deg,#9a8cff,#7c6cf0);color:#fff}"+
  ".gp-f .snd:disabled{opacity:.5}.gp-f svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2}"+
  ".gp-f textarea{flex:1;min-height:40px;max-height:110px;padding:10px 12px;border-radius:11px;border:1px solid #2a2350;background:#1b1540;color:#eef0fb;resize:none;outline:none;font-family:inherit;font-size:15px}";
  var st = document.createElement("style"); st.textContent = css;
  document.head.appendChild(st);

  // ---------- DOM ----------
  var fab = document.createElement("button");
  fab.id = "gp-fab";
  fab.setAttribute("aria-label", "Abrir soporte");
  fab.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><i class="pip"></i>';

  var panel = document.createElement("div");
  panel.id = "gp-panel";
  panel.innerHTML =
    '<div class="gp-h"><div class="a"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>'+
    '<div><b>Asistente <span>ganamos</span></b><div class="s"><span class="dot"></span>En línea</div></div>'+
    '<button class="x" id="gp-x" aria-label="Cerrar">&times;</button></div>'+
    '<div class="gp-b" id="gp-body"></div>'+
    '<form class="gp-f" id="gp-form">'+
    '<input type="file" id="gp-file" accept="image/*,application/pdf" hidden>'+
    '<button type="button" class="att" id="gp-att" aria-label="Adjuntar comprobante"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.48"/></svg></button>'+
    '<textarea id="gp-t" rows="1" placeholder="Escribí tu mensaje…"></textarea>'+
    '<button type="submit" class="snd" id="gp-s" disabled aria-label="Enviar"><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>'+
    '</form>';

  document.body.appendChild(fab);
  document.body.appendChild(panel);

  var $ = function (id) { return document.getElementById(id); };
  var body = $("gp-body"), text = $("gp-t"), snd = $("gp-s"), fileI = $("gp-file");

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
  function pintar(quien, txt, mudo){
    var r = document.createElement("div"); r.className = "gp-r " + (quien === "u" ? "u" : "b");
    var b = document.createElement("div"); b.className = "gp-bub"; b.textContent = txt;
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

  // ---------- abrir / cerrar ----------
  function saludar(){
    saludado = true;
    pintar("b", USUARIO
      ? "¡Hola " + USUARIO + "! 👋 Soy el asistente de ganamos. ¿En qué te ayudo con tu cuenta o tus fichas?"
      : "¡Hola! 👋 Soy el asistente de ganamos. Te ayudo con tu cuenta, tus fichas y las recargas. ¿Me decís tu nombre de usuario del juego?");
  }

  /** Borron y cuenta nueva: al entrar o salir cambia quien es el que habla. */
  function reiniciarCharla(){
    olvidar();
    if (panel.classList.contains("open")) saludar();
  }

  function abrir(){
    panel.classList.add("open");
    fab.classList.remove("hay");
    if (!saludado) saludar();
    setTimeout(function (){ text.focus(); }, 150);
  }
  function cerrar(){ panel.classList.remove("open"); }

  fab.addEventListener("click", abrir);
  $("gp-x").addEventListener("click", cerrar);

  text.addEventListener("input", function (){
    snd.disabled = text.value.trim() === "";
    text.style.height = "auto";
    text.style.height = Math.min(text.scrollHeight, 110) + "px";
  });

  // ---------- hablar con la IA ----------
  $("gp-form").addEventListener("submit", function (e){
    e.preventDefault();
    var t = text.value.trim(); if (!t || enviando) return;

    pintar("u", t);
    historial.push({ role: "user", content: t });
    text.value = ""; text.style.height = "auto"; snd.disabled = true;
    enviando = true;
    var esperando = escribiendo();

    fetch(API_CHAT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        mensajes: historial.slice(-MAX_CONTEXTO),
        session_id: sid,
        usuario: USUARIO || undefined
      })
    })
      .then(function (r){ return r.json(); })
      .then(function (d){
        esperando.remove();
        if (d.ok){
          pintar("b", d.respuesta);
          historial.push({ role: "assistant", content: d.respuesta });
          guardar();
        } else {
          pintar("b", "⚠️ " + (d.error || "No pude responder ahora."));
        }
      })
      .catch(function (){
        esperando.remove();
        pintar("b", "⚠️ No pude conectar. Fijate si tenés señal y probá de nuevo.");
      })
      .then(function (){
        enviando = false;
        /* El server encola un "te contestamos" por cada respuesta. Lo consumimos
           ya mismo en vez de esperar al sondeo: si el jugador cierra la app en
           los proximos segundos, no queremos que le suene un aviso por un
           mensaje que acaba de leer acá. */
        setTimeout(mirarNotif, 600);
      });
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
          if (!panel.classList.contains("open")) fab.classList.add("hay");
          d.mensajes.forEach(function (m){
            if (m.adjunto && m.adjunto.url) pintarAdj("b", m.adjunto); else pintar("b", m.texto);
          });
          // Ya le mostramos la respuesta del agente acá: consumir el aviso que
          // el CRM encoló, asi no le repica despues en la barra de Android.
          mirarNotif();
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

  function revisarSesion(){
    // Alcanza con que UNA capa lo vea. ig_token solo no sirve: puede quedar en
    // localStorage despues de un logout a medias, y no dice quien es.
    var quien = quienEs();
    var hay   = !!quien || haySesionEnDom() || !!ls("ig_token");

    // Va aca porque este chequeo ya corre cada 1,2 s: el saldo del header se
    // mira siempre, pero solo sale una request cuando el numero cambia.
    reportarSaldo();

    // Entro: adoptamos el usuario del header (o el que le vimos escribir).
    if (hay && quien && quien !== USUARIO){
      USUARIO = quien;
      lss("goldpaw_user", USUARIO);
      reiniciarCharla();
      notifRegistrar();     // este celular ahora es de este jugador
    }

    // Salio: se olvida de quien era.
    if (teniaSesion === true && !hay && USUARIO){
      USUARIO = ""; visto = "";
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
  "border-radius:50%;background:linear-gradient(160deg,#241a5e,#120d2b);border:2px solid #3a2f7a;display:grid;place-items:center;font-size:19px}"+
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
  "#gpr-fab{position:fixed;right:16px;bottom:142px;width:48px;height:48px;border:0;border-radius:50%;cursor:pointer;"+
  "z-index:2147483000;background:linear-gradient(145deg,#F0C567,#E3B14A);color:#4a2c00;font-size:22px;display:none;"+
  "place-items:center;box-shadow:0 8px 22px rgba(227,177,74,.45);padding:0}"+
  "#gpr-fab.on{display:grid}#gpr-fab:active{transform:scale(.94)}";
  var stR = document.createElement("style"); stR.textContent = cssR;
  document.head.appendChild(stR);

  var ov = document.createElement("div");
  ov.id = "gpr-ov";
  ov.innerHTML =
    '<div class="gpr-card">'+
    '<button class="gpr-x" id="gpr-x" aria-label="Cerrar">&times;</button>'+
    '<h2>🎁 Giro <span style="color:#E3B14A">gratis</span></h2>'+
    '<div class="sub">Girá y reclamá tu premio en bonos</div>'+
    '<div class="gpr-wrap"><div class="gpr-pin"></div>'+
    '<svg class="gpr-wheel" id="gpr-wheel" viewBox="0 0 200 200"></svg>'+
    '<div class="gpr-hub">🎯</div></div>'+
    '<div class="gpr-res" id="gpr-res"></div>'+
    '<input class="gpr-in" id="gpr-user" type="text" placeholder="Tu usuario del juego" autocomplete="username">'+
    '<div class="gpr-msg" id="gpr-msg"></div>'+
    '<button class="gpr-btn" id="gpr-spin" type="button">Girar</button>'+
    '<button class="gpr-skip" id="gpr-skip" type="button">Ahora no</button>'+
    '</div>';
  document.body.appendChild(ov);

  var fabR = document.createElement("button");
  fabR.id = "gpr-fab";
  fabR.setAttribute("aria-label", "Ruleta de bonos");
  fabR.textContent = "🎁";
  document.body.appendChild(fabR);

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
    fetch(API_RULETA)
      .then(function (r){ return r.json(); })
      .then(function (d){ premios = (d.premios && d.premios.length) ? d.premios : FALLBACK; })
      .catch(function (){ premios = FALLBACK; })
      .then(function (){
        dibujar();
        fabR.classList.add("on");
        // Se abre sola una vez por dia, para no ser pesada en cada apertura.
        if (ls(RULETA_DIA) !== new Date().toISOString().slice(0, 10)){
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
    if (document.visibilityState !== "visible") return;   // que las tome el APK

    // Si el alta fallo, o el jugador cambio, se reintenta aca (cada 25 s) y no
    // en revisarSesion, que corre muchisimo mas seguido.
    if (notifRegistrado !== (USUARIO || "")) { notifRegistrar(); }

    fetch(API_NOTIF + "?accion=pendientes&device_id=" + encodeURIComponent(DEVICE))
      .then(function (r){ return r.json(); })
      .then(function (d){
        if (!d.ok || !d.notificaciones) return;
        d.notificaciones.forEach(function (n){
          /* solo_app = "te contestamos".
             Con el panel del chat ABIERTO la respuesta ya está en pantalla:
             dibujar además una tarjeta sería la misma cosa dos veces. El aviso
             igual quedó consumido por el sondeo, así que tampoco le va a
             repicar en la barra un rato después.
             Con el panel CERRADO sí se dibuja: es la única forma de que se
             entere de que le contestamos. */
          if (n.solo_app && panel.classList.contains("open")) return;
          pintarNotif(n);
        });
      })
      .catch(function (){});
  }

  // ---------- arranque ----------
  saludado = restaurar();          // si habia charla previa, no vuelve a saludar
  fab.classList.add("on");
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
