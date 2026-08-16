/* ==========================================================================
 * ganamos — widget del asistente para inyectar por el reverse proxy.
 *
 * Se inyecta con sub_filter antes de </body>. Como todo corre en el MISMO
 * origen (gracias al proxy), lee el token de la plataforma del localStorage
 * y saluda al jugador por su nombre, sin pedirlo.
 *
 * El backend del chat (Cohere) sigue en tu API de Hostinger (CORS habilitado).
 * ==========================================================================*/
(function () {
  "use strict";
  if (window.self !== window.top) return;          // solo en la página principal
  if (document.getElementById("gp-fab")) return;    // no duplicar

  var API_CHAT  = "https://orange-crab-483661.hostingersite.com/api/chatbot.php";
  var API_SUBIR = "https://orange-crab-483661.hostingersite.com/api/subir.php";
  var API_MIS   = "https://orange-crab-483661.hostingersite.com/api/mis_mensajes.php";

  function ls(k){ try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lss(k,v){ try { localStorage.setItem(k,v); } catch (e) {} }

  // Token de la plataforma (mismo origen por el proxy).
  var AUTH = ls("API_AUTH_ACCESS_TOKEN");
  function userDeToken(t){
    try {
      var p = t.split("."); if (p.length < 2) return "";
      var b = p[1].replace(/-/g,"+").replace(/_/g,"/"); while (b.length % 4) b += "=";
      var c = JSON.parse(decodeURIComponent(atob(b).split("").map(function(x){
        return "%" + ("00" + x.charCodeAt(0).toString(16)).slice(-2); }).join("")));
      return String(c.username||c.user||c.preferred_username||c.nombre_usuario||c.name||c.sub||c.email||"").trim();
    } catch (e) { return ""; }
  }
  var USUARIO = AUTH ? userDeToken(AUTH) : "";

  var sid = ls("goldpaw_sid");
  if (!sid){ sid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ("s-"+Date.now()+"-"+Math.random().toString(36).slice(2)); lss("goldpaw_sid", sid); }

  // ---------- estilos (namespaced, alto z-index) ----------
  var css =
  "#gp-fab{position:fixed;right:22px;bottom:22px;width:60px;height:60px;border:0;border-radius:50%;cursor:pointer;z-index:2147483000;"+
  "background:linear-gradient(145deg,#4a7bff,#7a5cff);color:#fff;box-shadow:0 10px 28px rgba(74,123,255,.5);display:grid;place-items:center;transition:transform .15s}"+
  "#gp-fab:hover{transform:translateY(-2px)}#gp-fab svg{width:26px;height:26px;fill:none;stroke:#fff;stroke-width:2}"+
  "#gp-panel{position:fixed;right:22px;bottom:94px;width:370px;max-width:calc(100vw - 32px);height:540px;max-height:calc(100vh - 130px);"+
  "background:#120d2b;border:1px solid #2a2350;border-radius:18px;z-index:2147483000;display:none;flex-direction:column;overflow:hidden;"+
  "box-shadow:0 24px 60px rgba(0,0,0,.5);font:14px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif;color:#eef0fb}"+
  "#gp-panel.open{display:flex}"+
  ".gp-h{display:flex;align-items:center;gap:10px;padding:13px 15px;border-bottom:1px solid #2a2350;background:#1a1440}"+
  ".gp-h .a{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:linear-gradient(160deg,#9a8cff,#7c6cf0)}"+
  ".gp-h .a svg{width:19px;height:19px;fill:none;stroke:#fff;stroke-width:2}.gp-h b{font-size:14.5px}.gp-h b span{color:#9a8cff}"+
  ".gp-h .s{color:#9aa0c4;font-size:11.5px;display:flex;align-items:center;gap:5px}.gp-h .dot{width:7px;height:7px;border-radius:50%;background:#37c17a}"+
  ".gp-h .x{margin-left:auto;background:0;border:0;color:#9aa0c4;font-size:20px;cursor:pointer;line-height:1}"+
  ".gp-b{flex:1;overflow-y:auto;padding:15px;display:flex;flex-direction:column;gap:10px}"+
  ".gp-r{display:flex;max-width:88%}.gp-r.u{align-self:flex-end}.gp-r.b{align-self:flex-start}"+
  ".gp-bub{padding:9px 12px;border-radius:14px;font-size:13.5px;white-space:pre-wrap;word-break:break-word}"+
  ".gp-r.u .gp-bub{background:linear-gradient(135deg,#7c6cf0,#6a5be8);color:#fff;border-bottom-right-radius:5px}"+
  ".gp-r.b .gp-bub{background:#1b1540;border:1px solid #2a2350;border-bottom-left-radius:5px}"+
  ".gp-bub img{max-width:180px;border-radius:9px;display:block;margin-top:2px;cursor:pointer}"+
  ".gp-bub a{color:inherit}"+
  ".gp-f{display:flex;gap:7px;align-items:flex-end;padding:11px;border-top:1px solid #2a2350}"+
  ".gp-f .att,.gp-f .snd{width:40px;height:40px;flex:none;border-radius:11px;display:grid;place-items:center;cursor:pointer}"+
  ".gp-f .att{border:1px solid #2a2350;background:#1b1540;color:#9aa0c4}.gp-f .snd{border:0;background:linear-gradient(180deg,#9a8cff,#7c6cf0);color:#fff}"+
  ".gp-f .snd:disabled{opacity:.5;cursor:default}.gp-f svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2}"+
  ".gp-f textarea{flex:1;min-height:40px;max-height:110px;padding:10px 12px;border-radius:11px;border:1px solid #2a2350;background:#1b1540;color:#eef0fb;resize:none;outline:none;font-family:inherit}"+
  "@media(max-width:480px){#gp-panel{right:10px;left:10px;width:auto;bottom:88px}#gp-fab{right:16px;bottom:16px}}";
  var st = document.createElement("style"); st.textContent = css; document.head.appendChild(st);

  // ---------- DOM ----------
  var fab = document.createElement("button");
  fab.id = "gp-fab";
  fab.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

  var panel = document.createElement("div");
  panel.id = "gp-panel";
  panel.innerHTML =
    '<div class="gp-h"><div class="a"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>'+
    '<div><b>Asistente <span>ganamos</span></b><div class="s"><span class="dot"></span>En línea</div></div>'+
    '<button class="x" id="gp-x" aria-label="Cerrar">&times;</button></div>'+
    '<div class="gp-b" id="gp-body"></div>'+
    '<form class="gp-f" id="gp-form">'+
    '<input type="file" id="gp-file" accept="image/*,application/pdf" hidden>'+
    '<button type="button" class="att" id="gp-att" title="Adjuntar"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.48"/></svg></button>'+
    '<textarea id="gp-t" rows="1" placeholder="Escribí tu mensaje…"></textarea>'+
    '<button type="submit" class="snd" id="gp-s" disabled><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>'+
    '</form>';

  document.body.appendChild(fab);
  document.body.appendChild(panel);

  var $ = function(id){ return document.getElementById(id); };
  var body = $("gp-body"), text = $("gp-t"), snd = $("gp-s"), fileI = $("gp-file");
  var historial = [], enviando = false, saludado = false, lastAgentId = 0;

  function pintar(quien, txt){
    var r = document.createElement("div"); r.className = "gp-r " + (quien==="u"?"u":"b");
    var b = document.createElement("div"); b.className = "gp-bub"; b.textContent = txt;
    r.appendChild(b); body.appendChild(r); body.scrollTop = body.scrollHeight;
  }
  function pintarAdj(quien, adj){
    var r = document.createElement("div"); r.className = "gp-r " + (quien==="u"?"u":"b");
    var b = document.createElement("div"); b.className = "gp-bub"; var a = document.createElement("a");
    a.href = adj.url; a.target = "_blank";
    if (adj.tipo === "imagen"){ var im = document.createElement("img"); im.src = adj.url; a.appendChild(im); }
    else { a.textContent = "📄 " + (adj.nombre || "Comprobante.pdf"); }
    b.appendChild(a); r.appendChild(b); body.appendChild(r); body.scrollTop = body.scrollHeight;
  }

  function abrir(){
    panel.classList.add("open"); fab.style.display = "none";
    if (!saludado){
      saludado = true;
      pintar("b", USUARIO ? ("¡Hola " + USUARIO + "! 👋 Soy el asistente de ganamos. ¿En qué te ayudo con tu cuenta o tus fichas?")
                          : "¡Hola! 👋 Soy el asistente de ganamos. ¿Me decís tu nombre de usuario del juego?");
    }
    setTimeout(function(){ text.focus(); }, 150);
  }
  function cerrar(){ panel.classList.remove("open"); fab.style.display = "grid"; }
  fab.addEventListener("click", abrir);
  $("gp-x").addEventListener("click", cerrar);

  text.addEventListener("input", function(){
    snd.disabled = text.value.trim() === "";
    text.style.height = "auto"; text.style.height = Math.min(text.scrollHeight, 110) + "px";
  });

  $("gp-form").addEventListener("submit", function(e){
    e.preventDefault();
    var t = text.value.trim(); if (!t || enviando) return;
    pintar("u", t); historial.push({ role:"user", content:t });
    text.value = ""; text.style.height = "auto"; snd.disabled = true; enviando = true;
    fetch(API_CHAT, { method:"POST", headers:{ "Content-Type":"application/json" },
      body: JSON.stringify({ mensajes:historial, session_id:sid, usuario:USUARIO||undefined, token:AUTH||undefined }) })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.ok){ pintar("b", d.respuesta); historial.push({ role:"assistant", content:d.respuesta }); }
        else pintar("b", "⚠️ " + (d.error || "No pude responder ahora."));
      })
      .catch(function(){ pintar("b", "⚠️ No pude conectar con el servidor."); })
      .then(function(){ enviando = false; text.focus(); });
  });

  $("gp-att").addEventListener("click", function(){ fileI.click(); });
  fileI.addEventListener("change", function(){
    var f = fileI.files[0]; fileI.value = ""; if (!f) return;
    if (f.size > 8*1024*1024){ pintar("b", "⚠️ El archivo supera 8 MB."); return; }
    var fd = new FormData(); fd.append("archivo", f); fd.append("session_id", sid); if (USUARIO) fd.append("usuario", USUARIO);
    pintar("u", "📎 Enviando comprobante…");
    fetch(API_SUBIR, { method:"POST", body: fd }).then(function(r){ return r.json(); })
      .then(function(d){ if (d.ok && d.adjunto) pintarAdj("u", d.adjunto); else pintar("b", "⚠️ " + (d.error || "No pude subir.")); })
      .catch(function(){ pintar("b", "⚠️ No pude subir el archivo."); });
  });

  // Respuestas del agente (CRM) por polling.
  setInterval(function(){
    fetch(API_MIS + "?session_id=" + encodeURIComponent(sid) + "&desde=" + lastAgentId)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.ok && d.mensajes && d.mensajes.length){
          if (!panel.classList.contains("open")) fab.style.boxShadow = "0 0 0 4px rgba(55,193,122,.5)";
          d.mensajes.forEach(function(m){ if (m.adjunto && m.adjunto.url) pintarAdj("b", m.adjunto); else pintar("b", m.texto); });
        }
        if (typeof d.ultimo_id === "number") lastAgentId = d.ultimo_id;
      })
      .catch(function(){});
  }, 6000);
})();
