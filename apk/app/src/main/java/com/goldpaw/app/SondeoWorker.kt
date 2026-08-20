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
 * 15 minutos es el minimo que Android permite para trabajo periodico; no se
 * puede bajar. Con la app abierta el hueco no se nota, porque el widget sondea
 * cada 25 s por su cuenta y muestra el aviso como tarjeta.
 */
class SondeoWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        val ctx = applicationContext

        /* Sin permiso NO se pide la lista. Es importante: el server marca los
           avisos como entregados al devolverlos, asi que pedirlos sin poder
           mostrarlos los quemaria para siempre. */
        if (!Notificaciones.permitidas(ctx)) return Result.success()

        /* WorkManager corre igual con la app abierta. Si el jugador esta adentro
           no hay que meterse: el widget sondea cada 25 s y le muestra todo en
           pantalla. Sin esto, una respuesta del chat que acaba de leer le podria
           sonar en la barra. */
        if (Enganche.enPrimerPlano(ctx)) return Result.success()

        // El alta la hace el widget. Si todavia no corrio, no hay nada que pedir.
        val device = Notificaciones.deviceId(ctx)

        val avisos = if (device != null) Notificaciones.pendientes(device) else emptyList()
        avisos.forEach { Notificaciones.mostrar(ctx, it) }

        // Solo si no hubo nada real que decir: un recordatorio encima de un
        // "te acreditamos la recarga" seria ruido sobre la buena noticia.
        if (avisos.isEmpty()) Enganche.intentar(ctx)

        return Result.success()
    }

    companion object {
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
