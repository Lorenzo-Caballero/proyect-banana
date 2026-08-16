package com.goldpaw.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.UUID

/** Un aviso ya listo para mostrar, tal como lo devuelve el server. */
data class Aviso(
    val id: Int,
    val titulo: String,
    val cuerpo: String,
    val tipo: String,
    val url: String?
)

/**
 * GOLDPAW — notificaciones sin Firebase.
 *
 * El server deja los avisos en una cola (tabla `notificaciones`) y el celular
 * los va a buscar. No hay push de verdad y es a proposito: no depende de una
 * cuenta de Google, no hay google-services.json que mantener y todo el sistema
 * vive en el mismo Hostinger que el resto de la API. El precio es la demora:
 * con la app cerrada, el aviso puede tardar hasta ~15 minutos (lo que Android
 * permite como minimo para trabajo periodico, y Doze puede estirarlo mas).
 *
 * Quien sondea:
 *   - SondeoWorker, cada 15 min, aunque la app este cerrada -> barra de Android
 *   - el widget, cada 25 s con la app a la vista            -> tarjeta en pantalla
 *
 * Los dos usan el mismo device_id y el server entrega cada aviso una sola vez.
 */
object Notificaciones {

    const val CANAL = "goldpaw_premios"

    private const val API = "https://orange-crab-483661.hostingersite.com/api/notificaciones.php"
    private const val PREFS = "goldpaw"
    private const val K_DEVICE = "device_id"
    private const val K_USUARIO = "usuario"
    private const val K_PERMISO_PEDIDO = "permiso_pedido"
    private const val TAG = "goldpaw-notif"

    /* El WAF de Hostinger corta los pedidos que no parecen de un navegador. El
       widget no tiene el problema (corre adentro del WebView), pero el worker
       si: sin este User-Agent el sondeo vuelve bloqueado. */
    private const val UA =
        "Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) " +
            "Chrome/120.0.0.0 Mobile Safari/537.36 GOLDPAW/1.1"

    // ------------------------------------------------------------- preferencias

    /** internal y no private: Enganche guarda su estado en el mismo archivo. */
    internal fun prefs(ctx: Context): SharedPreferences =
        ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    /**
     * El id de este celular. Lo manda el widget por el puente (es el mismo que
     * usa en el navegador, asi los dos sondeos comparten los acuses). Si todavia
     * no lo mando, no hay nada que sondear: el alta la hace siempre el widget.
     */
    fun deviceId(ctx: Context): String? = prefs(ctx).getString(K_DEVICE, null)

    fun usuario(ctx: Context): String? = prefs(ctx).getString(K_USUARIO, null)

    /* Hace falta recordar si ya se pidio el permiso alguna vez, y que sobreviva
       al reinicio: shouldShowRequestPermissionRationale devuelve false tanto
       ANTES de la primera vez como DESPUES del segundo "no", y sin esta marca
       los dos casos son indistinguibles. */
    fun marcarPermisoPedido(ctx: Context) {
        prefs(ctx).edit().putBoolean(K_PERMISO_PEDIDO, true).apply()
    }

    fun permisoYaPedido(ctx: Context): Boolean =
        prefs(ctx).getBoolean(K_PERMISO_PEDIDO, false)

    fun guardarVinculo(ctx: Context, deviceId: String, usuario: String) {
        prefs(ctx).edit()
            .putString(K_DEVICE, deviceId)
            .putString(K_USUARIO, usuario.ifBlank { null })
            .apply()
    }

    /** Solo para el caso raro de tener que sondear antes de que hable el widget. */
    fun deviceIdOCrear(ctx: Context): String {
        deviceId(ctx)?.let { return it }
        val nuevo = UUID.randomUUID().toString()
        prefs(ctx).edit().putString(K_DEVICE, nuevo).apply()
        return nuevo
    }

    // ------------------------------------------------------------------- canal

    fun crearCanal(ctx: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val canal = NotificationChannel(
            CANAL,
            "Premios y promociones",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Bonos, fichas de regalo y novedades de ganamos"
            enableLights(true)
            lightColor = ContextCompat.getColor(ctx, R.color.oro)
            enableVibration(true)
        }
        ctx.getSystemService(NotificationManager::class.java)?.createNotificationChannel(canal)
    }

    fun permitidas(ctx: Context): Boolean =
        NotificationManagerCompat.from(ctx).areNotificationsEnabled()

    // ------------------------------------------------------------------ mostrar

    fun mostrar(ctx: Context, a: Aviso) {
        // Sin permiso, notify() no hace nada y el aviso ya quedo marcado como
        // entregado en el server: se perderia. Por eso el worker chequea ANTES
        // de pedir la lista, y esto es solo la ultima red.
        if (!permitidas(ctx)) return

        val intent = Intent(ctx, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra(MainActivity.EXTRA_NOTIF_ID, a.id)
            if (!a.url.isNullOrBlank()) putExtra(MainActivity.EXTRA_NOTIF_URL, a.url)
        }
        val pi = PendingIntent.getActivity(
            ctx, a.id, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val n = NotificationCompat.Builder(ctx, CANAL)
            .setSmallIcon(R.drawable.ic_notificacion)
            .setColor(ContextCompat.getColor(ctx, R.color.oro))
            .setContentTitle(a.titulo)
            .setContentText(a.cuerpo)
            .setStyle(NotificationCompat.BigTextStyle().bigText(a.cuerpo))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_PROMO)
            .setAutoCancel(true)
            .setContentIntent(pi)
            .build()

        try {
            NotificationManagerCompat.from(ctx).notify(a.id, n)
        } catch (e: SecurityException) {
            // Revocaron el permiso entre el chequeo y el notify.
            Log.w(TAG, "sin permiso para notificar: ${e.message}")
        }
    }

    // --------------------------------------------------------------------- red

    /** Lo que este celular todavia no vio. El server los marca al devolverlos. */
    fun pendientes(deviceId: String): List<Aviso> {
        val url = "$API?accion=pendientes&device_id=" + URLEncoder.encode(deviceId, "UTF-8")
        val cuerpo = pedir(url, null) ?: return emptyList()
        return try {
            val raiz = JSONObject(cuerpo)
            if (!raiz.optBoolean("ok")) return emptyList()
            val arr = raiz.optJSONArray("notificaciones") ?: return emptyList()
            (0 until arr.length()).map { i ->
                val o = arr.getJSONObject(i)
                Aviso(
                    id = o.optInt("id"),
                    titulo = o.optString("titulo"),
                    cuerpo = o.optString("cuerpo"),
                    tipo = o.optString("tipo", "aviso"),
                    url = o.optString("url").ifBlank { null }
                )
            }
        } catch (e: Exception) {
            Log.w(TAG, "respuesta ilegible: ${e.message}")
            emptyList()
        }
    }

    /** El jugador la toco. Es solo estadistica: si falla, no importa. */
    fun marcarLeida(deviceId: String, id: Int) {
        val body = JSONObject()
            .put("accion", "leida")
            .put("device_id", deviceId)
            .put("id", id)
            .toString()
        pedir(API, body)
    }

    private fun pedir(url: String, cuerpoPost: String?): String? = try {
        val con = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 8000
            readTimeout = 8000
            setRequestProperty("User-Agent", UA)
            setRequestProperty("Accept", "application/json")
            if (cuerpoPost != null) {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }
        cuerpoPost?.let { con.outputStream.use { os -> os.write(it.toByteArray()) } }
        val texto = if (con.responseCode in 200..299) {
            con.inputStream.bufferedReader().use(BufferedReader::readText)
        } else {
            Log.w(TAG, "HTTP ${con.responseCode}")
            null
        }
        con.disconnect()
        texto
    } catch (e: Exception) {
        Log.i(TAG, "sin conexion: ${e.message}")
        null
    }
}
