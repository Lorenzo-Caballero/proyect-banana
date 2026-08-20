package com.goldpaw.app

/**
 * Config del APK específica del CLIENTE.
 *
 * Para armar el APK de OTRO cliente, cambiá SOLO `SITIO` y recompilá: de acá
 * salen la URL que carga la app, el asistente que inyecta y el endpoint de
 * notificaciones. Todo cuelga del MISMO dominio (la réplica del cliente en el
 * VPS), así queda mismo-origen y db.php resuelve la base por el dominio.
 *
 * Por qué la réplica y no ganamos7.com directo: el CRM y las recargas escriben
 * las notificaciones en la base del VPS (por dominio). Si el APK cargara
 * ganamos7.com, el widget hablaría con Hostinger/otro origen y el sondeo miraría
 * un buzón distinto al que llena el CRM. Cargando la réplica, todo va a /gp-api
 * de ESTE dominio y el celular ve lo que el agente manda desde su CRM.
 */
object Config {
    /** La réplica del cliente en el VPS (sin barra final). */
    const val SITIO = "https://ganamoscrm.online"

    /** Lo que carga la app al abrirse (la plataforma, servida por la réplica). */
    const val INICIO = "$SITIO/home"

    /** El asistente que inyecta la app. Es el mismo que sirve la réplica; el
     *  nginx NO lo inyecta por sub_filter cuando el User-Agent es el APK, para
     *  que no queden dos widgets (uno del server sin token y otro nuestro). */
    const val WIDGET = "$SITIO/replica/widget.js"

    /** Cola de notificaciones: el sondeo con la app cerrada (SondeoWorker). */
    const val NOTIF_API = "$SITIO/gp-api/notificaciones.php"
}
