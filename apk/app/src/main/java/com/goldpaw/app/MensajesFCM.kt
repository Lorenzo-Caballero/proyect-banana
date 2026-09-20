package com.goldpaw.app

import android.content.Context
import android.util.Log
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * El empujon de Firebase: lo que hace que un aviso llegue en segundos y no en
 * quince minutos.
 *
 * QUE PROBLEMA RESUELVE. Hasta la 1.6 el celular PREGUNTABA cada 15 minutos
 * (SondeoWorker). Ese "cada 15 minutos" es un pedido, no una garantia: el
 * administrador de bateria del fabricante decide si lo deja correr, y medido
 * sobre 42 telefonos en 24 horas decidia que casi siempre no -- 5 de 6 en
 * Samsung, pero 1 de 20 en Xiaomi. No es un bug del worker y no se arregla
 * programando mejor: es el sistema operativo matando procesos de apps de
 * terceros a proposito.
 *
 * FCM no corre en nuestro proceso: corre dentro de Google Play Services, que es
 * del sistema. Xiaomi no lo mata porque matarlo romperia Gmail, WhatsApp y el
 * propio Android.
 *
 * ESTE SERVICIO NO DIBUJA NADA POR SU CUENTA, Y ES LO MAS IMPORTANTE DE ACA.
 * El push que manda el server viene VACIO: es un "fijate", no el aviso. Todo
 * termina en SondeoWorker.revisar(), el mismo codigo que corre el sondeo. Por
 * que se eligio asi:
 *
 *   1. La entrega unica ya estaba resuelta y sigue estandolo. La garantiza la
 *      PK (notificacion_id, device_id) de `notificaciones_entregas`, del lado
 *      del server. Con el contenido adentro del push, el mismo aviso podria
 *      dibujarse por el push Y por el sondeo, y habria que inventar una segunda
 *      deduplicacion en el telefono.
 *   2. `solo_app` se aplica en un solo lugar. Ese aviso el widget lo consume
 *      pero no lo dibuja; duplicar el camino era duplicar esa regla.
 *   3. El permiso se chequea ANTES de pedir la lista, una sola vez, en
 *      revisar(). Pedirla sin poder mostrarla quema el aviso para siempre.
 *   4. El texto de los avisos no viaja por Google. No es poca cosa: dicen
 *      cuanta plata se le acredito a quien.
 *
 * El precio es una llamada HTTP extra al recibir el push. A cambio, Firebase
 * queda siendo SOLO un timbre: si algun dia falla o se saca, el sondeo cada 15
 * min sigue funcionando sin tocar una linea.
 */
class MensajesFCM : FirebaseMessagingService() {

    /**
     * Google desperto la app porque hay algo. Corre en un hilo de fondo que
     * Firebase ya provee, asi que la llamada a la red va acá directo.
     *
     * No se mira el contenido del mensaje A PROPOSITO: lo unico que significa
     * es "vino algo". Si algun dia el server empieza a mandar datos adentro,
     * este metodo no tiene que cambiar.
     */
    override fun onMessageReceived(mensaje: RemoteMessage) {
        SondeoWorker.revisar(applicationContext, conEnganche = false)
    }

    /**
     * Google cambio el token de este telefono. Pasa al reinstalar, al restaurar
     * un backup en otro aparato o al limpiar los datos de la app.
     *
     * Hay que mandarlo SIEMPRE: un token viejo no da error, simplemente no
     * despierta a nadie, y el sintoma seria "a este jugador dejaron de llegarle
     * las notificaciones" sin nada roto a la vista.
     */
    override fun onNewToken(token: String) {
        Notificaciones.guardarToken(applicationContext, token)
    }

    companion object {
        private const val TAG = "goldpaw-fcm"

        /**
         * Conseguir el token en cada arranque.
         *
         * NO ALCANZA CON onNewToken: ese solo dispara cuando el token CAMBIA.
         * En una app ya instalada no vuelve a dispararse nunca, asi que si el
         * registro contra el server fallo una vez (sin red, server caido, o el
         * device_id todavia no existia) ese telefono se quedaria sin push para
         * siempre. Preguntarlo en cada arranque cierra ese agujero.
         */
        /**
         * Suscribe este telefono al canal de avisos masivos del cliente.
         *
         * POR QUE UN TOPICO Y NO MANDARLE A CADA UNO. La API v1 de Firebase
         * manda de a UN mensaje por llamada HTTP (el envio en lote se
         * discontinuo). Una promo para trescientos celulares serian trescientas
         * llamadas adentro del request del agente, que se quedaria esperando un
         * minuto largo. Con un topico es una sola llamada, la reparte Google, y
         * da igual si manana son diez mil.
         *
         * SE RECUERDA A CUAL YA SE SUSCRIBIO para no repetir la llamada en cada
         * arranque. Si el server cambia el nombre (o el jugador entra por otro
         * cliente), se da de baja del anterior ANTES de tomar el nuevo: sin
         * eso, un telefono que paso por dos casinos recibiria las promos de los
         * dos, que es peor que no recibir ninguna.
         */
        fun suscribir(ctx: Context, topico: String) {
            if (topico.isBlank()) return
            val p = Notificaciones.prefs(ctx)
            val anterior = p.getString(Notificaciones.K_FCM_TOPICO, null)
            if (anterior == topico) return
            try {
                val fm = FirebaseMessaging.getInstance()
                if (!anterior.isNullOrBlank()) fm.unsubscribeFromTopic(anterior)
                fm.subscribeToTopic(topico).addOnCompleteListener { t ->
                    if (t.isSuccessful) {
                        p.edit().putString(Notificaciones.K_FCM_TOPICO, topico).apply()
                    } else {
                        /* NO se guarda: que reintente en el proximo arranque.
                           Una suscripcion que fallo y quedo anotada como hecha
                           es un telefono que no recibe promos nunca mas. */
                        Log.w(TAG, "no se pudo suscribir a " + topico)
                    }
                }
            } catch (e: Exception) {
                Log.w(TAG, "FCM no disponible para suscribir: " + e.message)
            }
        }

        fun asegurarToken(ctx: Context) {
            try {
                FirebaseMessaging.getInstance().token.addOnCompleteListener { t ->
                    if (t.isSuccessful) {
                        t.result?.let { Notificaciones.guardarToken(ctx, it) }
                    } else {
                        Log.w(TAG, "sin token: " + (t.exception?.message ?: "?"))
                    }
                }
            } catch (e: Exception) {
                /* Telefono sin Google Play Services (pasa, sobre todo en
                   Huawei). NO es fatal y no se le avisa al jugador: el sondeo
                   cada 15 min lo sigue cubriendo igual que en la 1.6. */
                Log.w(TAG, "FCM no disponible en este telefono: " + e.message)
            }
        }
    }
}
