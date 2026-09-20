package com.goldpaw.app

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit

/**
 * Va a buscar los avisos con la app cerrada y los muestra en la barra.
 *
 * DESDE LA 1.7 ESTO ES EL RESPALDO, NO EL CAMINO PRINCIPAL. El principal es el
 * empujon de Firebase (MensajesFCM), que llega en segundos. Este sigue vivo
 * porque cubre lo que Firebase no: el telefono sin Google Play Services, el que
 * todavia no consiguio token, y cualquier caida del lado de Google. Un respaldo
 * que tarda 15 minutos es infinitamente mejor que ninguno.
 *
 * 15 minutos es el minimo que Android permite para trabajo periodico; no se
 * puede bajar. Con la app abierta el hueco no se nota, porque el widget sondea
 * cada 25 s por su cuenta y muestra el aviso como tarjeta.
 */
class SondeoWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        // El sondeo es la UNICA oportunidad de mirar si toca un recordatorio:
        // el empujon de Firebase no sirve para eso (ver revisar()).
        revisar(applicationContext, conEnganche = true)
        return Result.success()
    }

    companion object {

        /**
         * Ir a buscar lo que haya y mostrarlo.
         *
         * LO LLAMAN LOS DOS CAMINOS —el sondeo periodico y el empujon de
         * Firebase— y es a proposito que sea el mismo codigo. Si se
         * duplicara, la forma normal de romperlo seria que dentro de tres meses
         * uno de los dos chequee el permiso y el otro no, y ahi los avisos se
         * queman en silencio: el server los marca entregados al devolverlos.
         *
         * FIREBASE NO TRAE EL AVISO, TRAE UN "FIJATE". Por eso los dos
         * caminos terminan en la MISMA llamada a `pendientes()`, y por eso el
         * empujon no puede duplicar una notificacion: la entrega unica la sigue
         * garantizando la PK (notificacion_id, device_id) del server, igual que
         * cuando el widget y el worker sondeaban a la vez. Mandar el contenido
         * adentro del push habria significado dos fuentes de verdad para el
         * mismo texto, dos lugares donde aplicar `solo_app`, y el contenido de
         * los avisos viajando por Google sin ninguna necesidad.
         *
         * @param conEnganche si, NO habiendo nada real que decir, corresponde
         *        evaluar un recordatorio para volver a jugar. El sondeo dice
         *        que si. Firebase dice que NO: el empujon significa que habia
         *        un aviso, y si la lista vuelve vacia es porque el widget se lo
         *        llevo primero. Empujar ahi seria molestar a alguien que acaba
         *        de leer el mensaje en pantalla.
         */
        fun revisar(ctx: Context, conEnganche: Boolean) {
            /* Sin permiso NO se pide la lista. Es importante: el server marca
               los avisos como entregados al devolverlos, asi que pedirlos sin
               poder mostrarlos los quemaria para siempre. */
            if (!Notificaciones.permitidas(ctx)) return

            /* Con la app abierta no hay que meterse: el widget sondea cada 25 s
               y le muestra todo en pantalla. Sin esto, una respuesta del chat
               que acaba de leer le podria sonar en la barra. */
            if (Enganche.enPrimerPlano(ctx)) return

            // El alta la hace el widget. Si todavia no corrio, no hay nada que pedir.
            val device = Notificaciones.deviceId(ctx) ?: return

            val avisos = Notificaciones.pendientes(ctx, device)
            avisos.forEach { Notificaciones.mostrar(ctx, it) }

            // Solo si no hubo nada real que decir: un recordatorio encima de un
            // "te acreditamos la recarga" seria ruido sobre la buena noticia.
            if (conEnganche && avisos.isEmpty()) Enganche.intentar(ctx)
        }
        private const val NOMBRE = "goldpaw-notificaciones"

        /** Idempotente: se puede llamar en cada arranque sin duplicar nada. */
        fun programar(ctx: Context) {
            val pedido = PeriodicWorkRequestBuilder<SondeoWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(
                NOMBRE,
                // KEEP y no UPDATE: si se reemplaza en cada arranque, el periodo
                // vuelve a empezar de cero y con alguien que abre la app seguido
                // el sondeo no se ejecutaria nunca.
                ExistingPeriodicWorkPolicy.KEEP,
                pedido
            )
        }
    }
}
