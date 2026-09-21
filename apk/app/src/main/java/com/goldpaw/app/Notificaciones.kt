package com.goldpaw.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.graphics.Bitmap
import android.graphics.Canvas
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
 * GOLDPAW — la cola de avisos.
 *
 * El server deja los avisos en una cola (tabla `notificaciones`) y el celular
 * los va a buscar. ESO NO CAMBIO CON FIREBASE, y es el punto: Firebase manda un
 * "fijate" sin contenido, y el celular viene igual a `pendientes()`. La
 * cola sigue siendo la unica fuente de verdad del texto de un aviso.
 *
 * Quien pide la lista:
 *   - MensajesFCM, apenas Google despierta la app  -> barra de Android
 *   - SondeoWorker, cada 15 min, como respaldo     -> barra de Android
 *   - el widget, cada 25 s con la app a la vista   -> tarjeta en pantalla
 *
 * Los tres usan el mismo device_id y el server entrega cada aviso una sola vez
 * (PK `notificacion_id, device_id`), asi que pueden pisarse sin duplicar nada.
 *
 * POR QUE HASTA LA 1.6 NO HABIA FIREBASE: era deliberado (nada de cuenta de
 * Google en el medio, todo en el mismo servidor que el resto de la API). Lo que
 * dio vuelta la decision fue medirlo: con la app cerrada el sondeo dependia de
 * la MARCA del telefono —en 24 horas, 5 de 6 Samsung pero 1 de 20 Xiaomi—
 * porque el administrador de bateria del fabricante mata el trabajo periodico
 * de las apps de terceros. FCM no corre en nuestro proceso sino dentro de
 * Google Play Services, que no matan porque romperia el telefono entero.
 */
object Notificaciones {

    const val CANAL = "goldpaw_premios"

    /* Prefijo de la etiqueta de cada aviso. Lo comparte con el server: si
       uno de los dos cambia y el otro no, vuelven los duplicados. */
    const val TAG_AVISO = "gp-"

    // Cola de notificaciones en la réplica del cliente (VPS), NO Hostinger: es
    // donde el CRM y las recargas dejan los avisos, resueltos por dominio. Sale
    // de Config para armar el APK de otro cliente con un solo cambio.
    private const val API = Config.NOTIF_API
    private const val PREFS = "goldpaw"
    private const val K_DEVICE = "device_id"
    private const val K_USUARIO = "usuario"
    private const val K_PERMISO_PEDIDO = "permiso_pedido"
    private const val K_FCM_TOKEN = "fcm_token"
    private const val K_FCM_ENVIADO = "fcm_enviado"
    /* internal y no private: MensajesFCM guarda la suscripcion en el mismo
       archivo, y la clave tiene que ser UNA. */
    internal const val K_FCM_TOPICO = "fcm_topico"
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

    /* Lo mismo para la exencion de bateria: se pide UNA vez y no se vuelve a
       insistir. Si el jugador dijo que no, ya esta -- un dialogo del sistema
       repitiendose cada vez que abre la app es la forma mas rapida de que
       desinstale. */
    private const val K_BATERIA_PEDIDA = "bateria_pedida"

    fun bateriaYaPedida(ctx: Context): Boolean =
        prefs(ctx).getBoolean(K_BATERIA_PEDIDA, false)

    fun marcarBateriaPedida(ctx: Context) {
        prefs(ctx).edit().putBoolean(K_BATERIA_PEDIDA, true).apply()
    }

    fun permisoYaPedido(ctx: Context): Boolean =
        prefs(ctx).getBoolean(K_PERMISO_PEDIDO, false)

    fun guardarVinculo(ctx: Context, deviceId: String, usuario: String) {
        prefs(ctx).edit()
            .putString(K_DEVICE, deviceId)
            .putString(K_USUARIO, usuario.ifBlank { null })
            .apply()
        /* Recien ACA se sabe a que device_id pertenece el token de Firebase, y
           por eso la sincronizacion cuelga de aca y no solo del arranque: el
           token suele estar mucho ANTES que el device_id (lo da Google apenas
           abre la app; el device_id lo trae el widget cuando carga). Sin este
           enganche, el primer token de una instalacion nueva no se mandaba
           nunca y ese telefono se quedaba con el sondeo de 15 minutos para
           siempre — o sea, sin el arreglo, y sin ninguna senal de que le
           falta. */
        sincronizarToken(ctx)
    }

    // -------------------------------------------------------------- token FCM

    /** Lo llama MensajesFCM: en onNewToken y en el chequeo de cada arranque. */
    fun guardarToken(ctx: Context, token: String) {
        if (token.isBlank()) return
        prefs(ctx).edit().putString(K_FCM_TOKEN, token).apply()
        sincronizarToken(ctx)
    }

    /**
     * Le avisa al server que a este device_id se lo despierta con este token.
     *
     * ES IDEMPOTENTE Y BARATA A PROPOSITO: se la llama desde tres lados (token
     * nuevo, arranque, y cuando aparece el device_id) porque no se sabe cual de
     * los tres va a llegar primero. Si el par (device, token) es el que ya se
     * mando, no sale ni un byte a la red.
     *
     * UN TOKEN DE FCM NO ES ETERNO: Google lo rota al reinstalar la app, al
     * restaurar un backup en otro telefono o al limpiar los datos. Un token
     * viejo NO da error al usarlo — simplemente el aviso no llega a ningun
     * lado. Por eso se reintenta en cada arranque y no solo en onNewToken: un
     * token muerto no se queja, y el sintoma seria "a este jugador no le
     * llegan mas las notificaciones" sin nada roto a la vista.
     */
    fun sincronizarToken(ctx: Context) {
        val p = prefs(ctx)
        val token = p.getString(K_FCM_TOKEN, null) ?: return
        val device = deviceId(ctx) ?: return
        val huella = device + "|" + token
        /* NO ALCANZA CON QUE EL TOKEN YA SE HAYA MANDADO: tambien tiene que
           estar hecha la suscripcion al topico, porque se hace con la
           RESPUESTA de esta misma llamada. Cortando solo por el token, una
           suscripcion que fallo --un corte de red de un segundo mientras
           Google contestaba-- quedaba sin reintentar para siempre, y ese
           telefono no recibia nunca mas un aviso masivo. Nada lo avisaba: los
           avisos personales le seguian llegando bien.

           Reintentarlo cuesta una llamada HTTP en el arranque siguiente, y
           solo mientras falte. */
        val yaSuscripto = !p.getString(K_FCM_TOPICO, null).isNullOrBlank()
        if (p.getString(K_FCM_ENVIADO, null) == huella && yaSuscripto) return

        /* Se la llama desde el hilo principal (MainActivity) y desde uno de
           fondo (onNewToken). Un hilo suelto sirve para los dos y no hay nada
           que coordinar: si falla, el proximo arranque reintenta. */
        Thread {
            val body = JSONObject()
                .put("accion", "token")
                .put("device_id", device)
                .put("token", token)
                .toString()
            val r = pedir(API, body)
            var topico = ""
            val ok = try {
                val j = if (r != null) JSONObject(r) else null
                topico = j?.optString("topico") ?: ""
                j != null && j.optBoolean("ok")
            } catch (e: Exception) {
                false
            }
            if (ok) {
                p.edit().putString(K_FCM_ENVIADO, huella).apply()
                /* EL TOPICO LO DICE EL SERVER, NO EL APK. Es el canal por el
                   que llegan los avisos masivos, y es POR CLIENTE: el server ya
                   resolvio de que casino se trata por el dominio, y el telefono
                   no tiene por que deducirlo. Si el APK lo armara solo, un error
                   ahi le mandaria la promo de un casino a los jugadores de
                   otro. */
                if (topico.isNotBlank()) MensajesFCM.suscribir(ctx, topico)
            } else {
                /* NO se marca como enviado: que reintente. Un server viejo, sin
                   la columna, contesta que no y el telefono se queda sondeando
                   cada 15 min, que es exactamente como se portaba la 1.6. */
                Log.w(TAG, "el server no acepto el token FCM")
            }
        }.start()
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

    /**
     * El ícono de la app como bitmap, para el setLargeIcon (lo que hace que la
     * notificación se reconozca como de ganamos). Se dibuja el drawable del
     * launcher a un bitmap: así funciona igual con el ícono adaptativo (API 26+)
     * que con el PNG viejo. El ícono chico de la barra de estado NO puede ser
     * este —Android lo exige monocromo— y sigue siendo ic_notificacion.
     */
    private fun iconoApp(ctx: Context): Bitmap? = try {
        val d = ContextCompat.getDrawable(ctx, R.mipmap.ic_launcher)
        if (d == null) null else {
            val w = if (d.intrinsicWidth > 0) d.intrinsicWidth else 128
            val h = if (d.intrinsicHeight > 0) d.intrinsicHeight else 128
            val b = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
            val c = Canvas(b)
            d.setBounds(0, 0, w, h)
            d.draw(c)
            b
        }
    } catch (e: Exception) {
        null
    }

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
            .setSmallIcon(R.drawable.ic_notificacion)     // silueta monocroma (barra de estado)
            .setLargeIcon(iconoApp(ctx))                  // ícono de la app (perro), en el cuerpo
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
            /* CON ETIQUETA, Y TIENE QUE SER LA MISMA QUE MANDA EL SERVER
               (fcm_lib.php: tag => gp-<id>). Desde que el push lleva el texto
               adentro, un mismo aviso lo puede dibujar Android al recibirlo Y
               esta funcion al encontrarlo despues en la cola. Con la etiqueta
               compartida, el segundo REEMPLAZA al primero; sin ella el jugador
               ve el mismo aviso dos veces, que molesta mas que verlo tarde.
               t_fcm.php falla si los dos lados dejan de coincidir. */
            NotificationManagerCompat.from(ctx).notify(TAG_AVISO + a.id, a.id, n)
        } catch (e: SecurityException) {
            // Revocaron el permiso entre el chequeo y el notify.
            Log.w(TAG, "sin permiso para notificar: ${e.message}")
        }
    }

    // --------------------------------------------------------------------- red

    /** Lo que este celular todavia no vio. El server los marca al devolverlos. */
    fun pendientes(ctx: Context, deviceId: String): List<Aviso> {
        val url = "$API?accion=pendientes&device_id=" + URLEncoder.encode(deviceId, "UTF-8")
        val cuerpo = pedir(url, null) ?: return emptyList()
        return try {
            val raiz = JSONObject(cuerpo)
            if (!raiz.optBoolean("ok")) return emptyList()
            /* El server aprovecha el sondeo para contar si la RULETA esta
               prendida. Se guarda para que Enganche no prometa un giro que el
               CRM apago. Si el server no lo manda (version vieja), no se toca
               lo guardado. */
            if (raiz.has("ruleta")) {
                prefs(ctx).edit().putBoolean("ruleta_activa", raiz.optBoolean("ruleta", true)).apply()
            }
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
