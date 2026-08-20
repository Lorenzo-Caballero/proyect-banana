package com.goldpaw.app

import android.webkit.JavascriptInterface
import org.json.JSONObject

/**
 * Puente entre el asistente (widget.js, que corre en el WebView) y la app.
 *
 * Sirve para una sola cosa: que el APK sepa a QUIEN tiene que sondear cuando la
 * app este cerrada. El widget es el unico que ve la sesion de la plataforma, y
 * el worker es el unico que puede mostrar una notificacion con la app cerrada.
 *
 * SEGURIDAD — por que todos los metodos piden un token:
 * addJavascriptInterface expone este objeto a TODOS los frames del WebView, y
 * la plataforma abre los juegos en iframes de otros proveedores. Sin el token,
 * cualquiera de esos iframes podria llamar a vincular() y atar el celular a
 * otro jugador, quedandose con sus notificaciones. MainActivity genera el token
 * al azar en cada arranque y lo inyecta en window.__gp_app_tk del documento
 * principal; un iframe cross-origin no puede leer esa variable, asi que no
 * puede usar el puente.
 */
class PuenteApp(private val act: MainActivity, private val token: String) {

    private fun valido(tk: String?) = tk != null && tk == token

    /** Datos del celular para el alta que hace el widget contra la API. */
    @JavascriptInterface
    fun info(tk: String?): String {
        if (!valido(tk)) return "null"
        val version = try {
            act.packageManager.getPackageInfo(act.packageName, 0).versionName ?: ""
        } catch (e: Exception) {
            ""
        }
        return JSONObject()
            .put("modelo", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}".take(80))
            .put("version", version)
            .put("permitido", Notificaciones.permitidas(act))
            .toString()
    }

    /** Guarda a que jugador esta atado este celular. Usuario vacio = sin sesion. */
    @JavascriptInterface
    fun vincular(tk: String?, deviceId: String?, usuario: String?) {
        if (!valido(tk) || deviceId.isNullOrBlank()) return
        Notificaciones.guardarVinculo(act.applicationContext, deviceId, usuario ?: "")
    }

    /**
     * Muestra una notificación en la barra de estado AHORA, con el ícono de la
     * app. La usa el widget cuando llega una respuesta del chatbot (o cualquier
     * aviso) con la app abierta: sin esto, con la app en primer plano el worker
     * no corre y la respuesta solo aparecía dentro del chat, no en la barra.
     *
     * Va con token como todo el puente: un iframe de un juego no puede spamear
     * notificaciones haciéndose pasar por la app.
     */
    @JavascriptInterface
    fun notificar(tk: String?, id: Int, titulo: String?, cuerpo: String?, url: String?) {
        if (!valido(tk)) return
        val t = (titulo ?: "").trim()
        val c = (cuerpo ?: "").trim()
        if (t.isEmpty() && c.isEmpty()) return
        Notificaciones.mostrar(
            act.applicationContext,
            Aviso(
                id = if (id != 0) id else (System.currentTimeMillis() % 1_000_000L).toInt(),
                titulo = t.ifEmpty { "ganamos" },
                cuerpo = c,
                tipo = "chat",
                url = url?.takeIf { it.isNotBlank() }
            )
        )
    }

    /**
     * Boton "Activar" del cartelito del asistente.
     *
     * El widget no distingue "todavia se puede mostrar el dialogo" de "Android
     * ya no lo muestra mas", asi que esa decision la toma MainActivity y aca
     * hay un solo metodo. El permiso ademas ya se pide solo al arrancar: esto
     * es la segunda oportunidad para el que dijo que no.
     */
    @JavascriptInterface
    fun activar(tk: String?) {
        if (!valido(tk)) return
        // Llega en el hilo de JS; esto toca la UI.
        act.runOnUiThread { act.activarNotificaciones() }
    }
}
