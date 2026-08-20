package com.goldpaw.app

import android.content.Context
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/**
 * Recordatorios para volver a jugar.
 *
 * A diferencia del resto de las notificaciones, estas NO salen del server: las
 * arma el propio celular. Asi no hace falta un cron en Hostinger ni una fila en
 * la base por cada recordatorio de cada jugador — el worker ya corre cada 15
 * minutos por las notificaciones reales, y de paso mira si toca un empujon.
 *
 * Las reglas de abajo existen para que el recordatorio siga siendo util y no se
 * vuelva ruido: un aviso que el jugador silencia deja de servir para siempre, y
 * ahi se pierden tambien los avisos que importan (bonos, recargas, respuestas
 * del chat). Estan todas juntas arriba para que las puedas mover.
 */
object Enganche {

    // =====================  EDITA ESTO  ===================================
    /** No molestar si estuvo en la app hace menos de esto. */
    private const val HORAS_SIN_ABRIR = 3

    /** Ritmo minimo entre un recordatorio y el siguiente. */
    private const val HORAS_ENTRE_AVISOS = 4

    /** Techo diario. */
    private const val MAX_POR_DIA = 4

    /** Ventana horaria: de HORA_DESDE a HORA_HASTA (cruza la medianoche). */
    private const val HORA_DESDE = 9    // no antes de las 9 de la mañana
    private const val HORA_HASTA = 1    // ni despues de la 1 de la madrugada

    /** Los textos. Van rotando en orden para no repetir siempre el mismo. */
    private val MENSAJES = listOf(
        "🎰 Te esperan tus fichas" to "Entrá a ganamos y probá tu suerte. Hoy puede ser tu día.",
        "🎁 Tenés un giro gratis" to "Girá la ruleta y llevate bonos para jugar.",
        "🔥 ¿Volvemos a jugar?" to "Tus juegos favoritos te están esperando en la app.",
        "💜 Tu cuenta te extraña" to "Entrá y mirá cómo quedaron tus fichas y tus bonos.",
        "🪙 ¿Te quedaste sin fichas?" to "Recargá en segundos desde el chat y seguí jugando.",
        "🎯 Probá la ruleta de hoy" to "Un giro por día, gratis. Puede salir 2.000 en bonos."
    )
    // ======================================================================

    /** Id fijo y alto: los del server son autoincrementales y arrancan en 1, y
     *  ademas asi el recordatorio nuevo reemplaza al viejo en vez de apilarse. */
    const val ID_NOTIFICACION = 1_000_000

    private const val K_ULTIMA_APERTURA = "ultima_apertura"
    private const val K_PRIMER_PLANO = "en_primer_plano"
    private const val K_ULTIMO_AVISO = "ultimo_enganche"
    private const val K_DIA = "dia_enganche"
    private const val K_HOY = "enganches_hoy"
    private const val K_INDICE = "indice_enganche"

    private const val UNA_HORA = 3_600_000L

    // ------------------------------------------------------------ estado

    fun marcarApertura(ctx: Context, enPrimerPlano: Boolean) {
        Notificaciones.prefs(ctx).edit()
            .putLong(K_ULTIMA_APERTURA, System.currentTimeMillis())
            .putBoolean(K_PRIMER_PLANO, enPrimerPlano)
            .apply()
    }

    /**
     * ¿Esta usando la app ahora mismo?
     *
     * Se pide ademas que la marca sea reciente: si la app muere de golpe, el
     * flag queda en true para siempre y el sondeo no volveria a mostrar nada.
     */
    fun enPrimerPlano(ctx: Context): Boolean {
        val p = Notificaciones.prefs(ctx)
        if (!p.getBoolean(K_PRIMER_PLANO, false)) return false
        val desde = System.currentTimeMillis() - p.getLong(K_ULTIMA_APERTURA, 0)
        return desde < 30 * 60_000L
    }

    // ------------------------------------------------------------ el aviso

    /** Muestra un recordatorio si corresponde. Devuelve true si lo mostro. */
    fun intentar(ctx: Context): Boolean {
        val p = Notificaciones.prefs(ctx)
        val ahora = System.currentTimeMillis()

        if (!enHorario()) return false

        // Recien lo usaste: no tiene sentido invitarte a entrar.
        val ultimaApertura = p.getLong(K_ULTIMA_APERTURA, 0)
        if (ultimaApertura > 0 && ahora - ultimaApertura < HORAS_SIN_ABRIR * UNA_HORA) return false

        // Nunca abrio la app: no hay a que invitarlo a volver.
        if (ultimaApertura == 0L) return false

        val ultimo = p.getLong(K_ULTIMO_AVISO, 0)
        if (ahora - ultimo < HORAS_ENTRE_AVISOS * UNA_HORA) return false

        val hoy = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        val mismoDia = p.getString(K_DIA, "") == hoy
        val cuantos = if (mismoDia) p.getInt(K_HOY, 0) else 0
        if (cuantos >= MAX_POR_DIA) return false

        val i = p.getInt(K_INDICE, 0) % MENSAJES.size
        val (titulo, cuerpo) = MENSAJES[i]

        Notificaciones.mostrar(
            ctx,
            Aviso(ID_NOTIFICACION, titulo, cuerpo, "promo", null)
        )

        p.edit()
            .putLong(K_ULTIMO_AVISO, ahora)
            .putString(K_DIA, hoy)
            .putInt(K_HOY, cuantos + 1)
            .putInt(K_INDICE, (i + 1) % MENSAJES.size)
            .apply()
        return true
    }

    private fun enHorario(): Boolean {
        val hora = Calendar.getInstance().get(Calendar.HOUR_OF_DAY)
        return if (HORA_DESDE <= HORA_HASTA) {
            hora in HORA_DESDE until HORA_HASTA
        } else {
            // La ventana cruza la medianoche (ej: 9 a 1).
            hora >= HORA_DESDE || hora < HORA_HASTA
        }
    }
}
