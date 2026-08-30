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
 * El PageView SÍ se manda por los dos lados a propósito (Pixel del navegador +
 * Conversions API vía meta_pageview.php), para no perder visitas cuando un
 * bloqueador de anuncios frena fbevents.js pero deja pasar un fetch propio.
 * Los dos lados llevan el MISMO eventID (generado acá, ver idEvento()) -- Meta
 * los junta y cuenta uno solo. El resto de los eventos sigue saliendo por un
 * solo camino (el backend), sin necesidad de dedup.
 *
 * COOKIES fbp/fbc
 * Las pone el Pixel y son lo que ata el evento al click del anuncio. El backend
 * las necesita para atribuir, así que se leen acá y se mandan en los pedidos
 * (ver metaCookies()). Si el Pixel real no llegó a cargar (bloqueado) pero hay
 * un fbclid en la URL, se arma un fbc a mano (ver fbcSintetico()) para no
 * mandar el PageView por API sin forma de atribuirlo.
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
    // OJO, bug real (detectado con Pixel Helper Pro: "PIXEL_NOT_INITIALIZED
    // -- Track event before pixel init", pixelIds:[]): antes el guard de
    // arriba era "if (window.fbq) return" y cortaba TODA la función,
    // incluido el init de abajo -- si window.fbq YA existia por CUALQUIER
    // motivo ajeno (una extension de diagnostico de pixels que inyecta su
    // propio stub temprano para espiar las llamadas, el caso mas probable;
    // u otro script), este init para NUESTRO pixel_id se salteaba entero,
    // pero el track("PageView") de mas abajo se disparaba igual -- de ahi
    // "track antes de init" y pixelIds vacio.
    //
    // Ahora se trackea CON UNA BANDERA PROPIA si YA inicializamos, en vez de
    // confiar en si window.fbq existe (puede existir por otra razon).
    // fbq("init", ...) es idempotente por diseño de Meta -- llamarlo de mas
    // no rompe nada, así que el fix es simplemente "no confiar en que fbq
    // exista para decidir si ya hicimos NUESTRO init".
    if (window.__gpPixelInit === id) return;

    if (!window.fbq) {
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
    }
    window.fbq("init", id);
    window.__gpPixelInit = id;
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

  /* Id propio para deduplicar el PageView entre el Pixel del navegador y
     Conversions API (ver docblock arriba). No hace falta que sea
     criptográfico, solo que coincida entre las dos llamadas de ESTA carga
     de página. */
  function idEvento() {
    return "pv." + Date.now().toString(36) + "." + Math.random().toString(36).slice(2);
  }

  /* fbc a mano si la cookie del Pixel real todavía no existe (bloqueado, o
     esta es la primera visita y fbevents.js no llegó a correr) pero SÍ hay
     un fbclid en la URL -- formato documentado por Meta, sin hashear:
     https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc
     Es una red de contención: si el Pixel real cargó, ya viene la cookie de
     verdad y esto no se usa. */
  function fbcSintetico() {
    var fbclid = new URLSearchParams(location.search).get("fbclid");
    if (!fbclid) return "";
    return "fb.1." + Date.now() + "." + fbclid;
  }

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
      if (!va) return;

      var evId = idEvento();
      window.fbq("track", "PageView", {}, { eventID: evId });

      // Por Conversions API, con el MISMO event_id -- fire-and-forget, la
      // página no depende de esta respuesta (ver meta_pageview.php).
      var cookies = window.metaCookies();
      try {
        fetch(BASE_API + "/meta_pageview.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            event_id: evId,
            pub: pub,
            fbp: cookies.fbp,
            fbc: cookies.fbc || fbcSintetico()
          })
        }).catch(function () { /* sin CAPI, el Pixel del navegador ya salió */ });
      } catch (e) { /* fetch no disponible: no rompe el resto de la página */ }
    })
    .catch(function () { /* sin pixel, el sitio funciona igual */ });
})();
