/* ---------------------------------------------------------------------------
 * sw.js — Service Worker MÍNIMO, solo para notificaciones del sistema en el
 * navegador del CELULAR.
 *
 * Por qué existe: Chrome de Android NO deja usar `new Notification(...)` desde
 * la página (tira "Illegal constructor"). El único camino es un Service Worker
 * activo + `registration.showNotification(...)`, que es justo lo que llama
 * widget.js. En la compu `new Notification()` alcanza, pero este SW también
 * sirve ahí y unifica el comportamiento.
 *
 * MUY IMPORTANTE — este SW NO intercepta la red: no tiene ningún listener
 * 'fetch'. Eso es a propósito. La página sirve una réplica de la plataforma
 * (login, saldo, juegos en iframes); un SW que interceptara requests podría
 * romper todo eso en silencio. Acá solo maneja el click en la notificación.
 *
 * No hay Web Push / VAPID: nada de Firebase, igual que el resto del sistema.
 * Con la pestaña abierta, widget.js sondea y dispara showNotification(); con la
 * app cerrada el que avisa es el APK (SondeoWorker). Ver notas en CLAUDE.md.
 * ------------------------------------------------------------------------- */

// Tomar control apenas se instala/activa, sin esperar a que se cierren las
// pestañas viejas.
self.addEventListener("install", function () {
  self.skipWaiting();
});

self.addEventListener("activate", function (e) {
  e.waitUntil(self.clients.claim());
});

// Al tocar la notificación: enfocar una pestaña ya abierta del sitio, o abrir
// una nueva. Si la notificación traía una url, ir ahí.
self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  var destino = (e.notification.data && e.notification.data.url) || "/";
  e.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (lista) {
      for (var i = 0; i < lista.length; i++) {
        var c = lista[i];
        if (c.focus) {
          if (destino && destino !== "/" && "navigate" in c) {
            try { c.navigate(destino); } catch (err) {}
          }
          return c.focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(destino);
    })
  );
});
