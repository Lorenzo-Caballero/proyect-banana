/* meta-pixel.js — Pixel de Meta, configurado desde el CRM.
 *
 * Se incluye en registro.html y en el CRM. NO trae el pixel_id hardcodeado:
 * lo pide a la API, porque cada cliente tiene el suyo (config_crm) y el mismo
 * archivo lo sirven todos.
 *
 * QUE HACE Y QUE NO
 * Acá solo van los eventos de NAVEGACIÓN (PageView, ViewContent): cosas que
 * pasan en la pantalla y que el servidor no ve. Los que valen plata -- Purchase,
 * CompleteRegistration, Contact -- los manda el backend por Conversions API,
 * porque son los únicos que saben si la cosa pasó de verdad (ver meta_lib.php).
 *
 * DEDUPLICACIÓN
 * Si un día se disparara el mismo evento por los dos lados, hay que pasarle el
 * mismo eventID acá y en CAPI. Meta los junta y cuenta uno. Hoy no se duplica
 * ninguno a propósito: cada evento sale por un solo camino.
 *
 * COOKIES fbp/fbc
 * Las pone el Pixel y son lo que ata el evento al click del anuncio. El backend
 * las necesita para atribuir, así que se leen acá y se mandan en los pedidos
 * (ver metaCookies()).
 */
(function () {
  "use strict";

  // Mismo criterio que widget.js: en la réplica la API vive en /gp-api.
  var esPorPath = location.pathname.split("/").filter(Boolean).length >= 2;
  var partes    = location.pathname.split("/").filter(Boolean);
  var BASE_API  = esPorPath ? "/" + partes[0] + "/gp-api" : "/gp-api";

  /* Si la landing trae ?pub=<slug> (el link que se le dio a un publicista),
     se pide el pixel PROPIO de ese publicista en vez del general del
     cliente -- meta_config.php resuelve el fallback solo si no tiene uno
     configurado. Sin el parametro, query queda vacio y el comportamiento es
     exactamente el de siempre. */
  var pub = new URLSearchParams(location.search).get("pub") || "";
  var query = pub ? "?pub=" + encodeURIComponent(pub) : "";

  /* Dónde estamos, para decidir si corresponde el PageView. El agente elige
     entre "registro", "panel", "ambos" u "off" desde Configuración. */
  function donde() {
    var p = location.pathname;
    if (/registro\.html$/i.test(p)) return "registro";
    if (/(crm|admin|panel)\.html$/i.test(p)) return "panel";
    return "otro";
  }

  function cargarPixel(id) {
    if (window.fbq) return;
    /* Snippet oficial de Meta. Se deja tal cual (incluida la forma rara de
       inicializar): es el que Meta documenta y el que sus herramientas de
       diagnóstico esperan encontrar. */
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n;
      n.push = n; n.loaded = !0; n.version = "2.0"; n.queue = [];
      t = b.createElement(e); t.async = !0; t.src = v;
      s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    }(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
    window.fbq("init", id);
  }

  /* Las cookies que el Pixel deja y que el backend necesita para atribuir el
     evento al anuncio. Se exponen en window para que widget.js y registro.html
     las manden en sus pedidos. */
  window.metaCookies = function () {
    function leer(n) {
      var m = document.cookie.match("(^|;)\\s*" + n + "\\s*=\\s*([^;]+)");
      return m ? m.pop() : "";
    }
    return { fbp: leer("_fbp"), fbc: leer("_fbc") };
  };

  /* Disparar un evento del Pixel. Si no está configurado, no hace nada -- así
     el llamador no tiene que preguntar antes. */
  window.metaEvento = function (nombre, datos, eventID) {
    if (!window.fbq) return;
    var opts = eventID ? { eventID: eventID } : undefined;
    window.fbq("track", nombre, datos || {}, opts);
  };

  fetch(BASE_API + "/meta_config.php" + query, { credentials: "same-origin" })
    .then(function (r) { return r.json(); })
    .then(function (c) {
      if (!c || !c.activo || !c.pixel_id) return;
      cargarPixel(c.pixel_id);

      /* PageView según lo configurado. "off" no dispara nada: sirve para tener
         el pixel cargado (y las cookies fbp/fbc, que el backend usa para
         atribuir) sin contar visitas. */
      var modo = c.pageview_en || "registro";
      var aca  = donde();
      var va = modo === "ambos"
            || (modo === "registro" && aca === "registro")
            || (modo === "panel"    && aca === "panel");
      if (va) window.fbq("track", "PageView");
    })
    .catch(function () { /* sin pixel, el sitio funciona igual */ });
})();
