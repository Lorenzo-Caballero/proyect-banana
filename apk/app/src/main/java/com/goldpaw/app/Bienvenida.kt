package com.goldpaw.app

import android.content.Context
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit

/**
 * El primer aviso, el de recien instalada: "girá la ruleta".
 *
 * Va con unos minutos de demora a proposito. Mandarlo en el mismo momento en
 * que abre la app por primera vez no sirve de nada — la tiene delante de los
 * ojos —; el aviso vale cuando ya la cerro y hay que traerlo de vuelta.
 *
 * Se encola una sola vez por instalacion: el trabajo es unico y va con KEEP, asi
 * que aunque MainActivity lo pida en cada arranque, el segundo pedido no hace
 * nada. Si el jugador desinstala y reinstala, vuelve a salir (y esta bien: es
 * una instalacion nueva).
 */
class BienvenidaWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        val ctx = applicationContext

        // Sin permiso no se muestra nada. No se reintenta: cuando lo active, ya
        // le van a llegar los recordatorios normales de Enganche.
        if (!Notificaciones.permitidas(ctx)) return Result.success()

        Notificaciones.mostrar(
            ctx,
            Aviso(
                Bienvenida.ID_NOTIFICACION,
                "🎁 Tenés un giro gratis esperándote",
                "Girá la ruleta y llevate bonos para arrancar. Es gratis y es una vez por día.",
                "ruleta",
                null
            )
        )
        return Result.success()
    }
}

object Bienvenida {

    // =====================  EDITA ESTO  ===================================
    /** Cuanto se espera despues de la primera apertura. */
    private const val MINUTOS_DEMORA = 4L
    // ======================================================================

    /** Id propio, fuera del rango de los ids del server y del de Enganche. */
    const val ID_NOTIFICACION = 1_000_001

    private const val NOMBRE = "goldpaw-bienvenida"

    /** Idempotente: llamalo en cada arranque, se encola una sola vez. */
    fun programar(ctx: Context) {
        val pedido = OneTimeWorkRequestBuilder<BienvenidaWorker>()
            .setInitialDelay(MINUTOS_DEMORA, TimeUnit.MINUTES)
            .build()

        WorkManager.getInstance(ctx)
            .enqueueUniqueWork(NOMBRE, ExistingWorkPolicy.KEEP, pedido)
    }
}
