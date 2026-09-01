package com.goldpaw.app

import android.Manifest
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.Settings
import android.util.Log
import android.view.View
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceError
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

/**
 * GOLDPAW — la plataforma corre como documento principal (no en un iframe), asi
 * que el login cierra normal: las cookies son de primera parte y el localStorage
 * no va particionado. Encima se inyecta el asistente con evaluateJavascript.
 */
class MainActivity : AppCompatActivity() {

    companion object {
        /** Lo que carga la app al abrirse: la réplica del cliente en el VPS
         *  (que proxea la plataforma). Sale de Config para armar el APK de otro
         *  cliente cambiando una sola línea. Antes cargaba ganamos7.com directo,
         *  pero entonces el widget y el sondeo hablaban con Hostinger y no veían
         *  lo que el agente manda desde su CRM (que ahora vive en el VPS). */
        private const val INICIO = Config.INICIO

        /**
         * Version del asistente que se baja al arrancar. Como el APK se instala
         * a mano y no se actualiza solo, esto permite corregir el chat sin tener
         * que redistribuir la app. Si falla, se usa el de assets/widget.js.
         * Dejalo en "" para usar siempre el que viene adentro del APK.
         *
         * Apunta a la réplica (mismo dominio que INICIO). El nginx NO inyecta el
         * widget por sub_filter cuando el User-Agent es el APK, así este es el
         * único widget y conserva el token del puente en el orden correcto.
         */
        private const val WIDGET_REMOTO = Config.WIDGET

        private const val TAG = "goldpaw"

        /** Extras que deja una notificacion al tocarla. */
        const val EXTRA_NOTIF_ID = "notif_id"
        const val EXTRA_NOTIF_URL = "notif_url"
    }

    private lateinit var web: WebView
    private lateinit var pantallaCompleta: FrameLayout
    private lateinit var cargando: LinearLayout
    private lateinit var error: LinearLayout

    private var widgetJs: String? = null
    private var huboError = false
    private var ultimoAtras = 0L

    /** Clave del puente con el widget. Ver PuenteApp: sin esto, un iframe de un
     *  proveedor de juegos podria atar el celular a otro jugador. */
    private val tokenPuente = UUID.randomUUID().toString()

    // --- pantalla completa de los juegos ---
    private var vistaCustom: View? = null
    private var callbackCustom: WebChromeClient.CustomViewCallback? = null

    // --- seleccion de archivos (comprobantes) ---
    private var callbackArchivos: ValueCallback<Array<Uri>>? = null
    private var paramsArchivos: WebChromeClient.FileChooserParams? = null
    private var uriCamara: Uri? = null

    private val selectorArchivos =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
            val cb = callbackArchivos
            callbackArchivos = null
            paramsArchivos = null
            if (cb == null) return@registerForActivityResult

            var uris: Array<Uri>? = null
            if (res.resultCode == RESULT_OK) {
                val data = res.data
                val clip = data?.clipData
                uris = when {
                    data?.data != null -> arrayOf(data.data!!)
                    clip != null && clip.itemCount > 0 ->
                        Array(clip.itemCount) { clip.getItemAt(it).uri }
                    // Sin data = vino de la camara, que escribe en la uri que le pasamos
                    uriCamara != null -> arrayOf(uriCamara!!)
                    else -> null
                }
            }
            cb.onReceiveValue(uris)
            uriCamara = null
        }

    private val pedirCamara =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {
            // Con o sin permiso abrimos el selector: sin camara queda solo galeria.
            abrirSelector()
        }

    private val pedirNotificaciones =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
            Log.i(TAG, if (ok) "notificaciones permitidas" else "notificaciones rechazadas")
            // El widget vuelve a registrar el celular unos segundos despues, asi
            // que el server se entera solo de como quedo.
            if (ok) return@registerForActivityResult

            /* Android deja de mostrar el dialogo despues del segundo "no": a
               partir de ahi launch() no hace absolutamente nada y desde la app
               ya no se puede pedir. Se avisa, pero NO se lo manda de prepo a los
               ajustes: acaba de decir que no. El camino queda en el cartelito
               del asistente, que se toca a propósito. */
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                !shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)
            ) {
                Toast.makeText(this, R.string.notif_bloqueadas, Toast.LENGTH_LONG).show()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        web = findViewById(R.id.web)
        pantallaCompleta = findViewById(R.id.pantallaCompleta)
        cargando = findViewById(R.id.cargando)
        error = findViewById(R.id.error)
        findViewById<Button>(R.id.btnReintentar).setOnClickListener { reintentar() }

        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        configurarWebView()
        cargarWidget()
        manejarAtras()

        Notificaciones.crearCanal(this)
        SondeoWorker.programar(this)
        Bienvenida.programar(this)

        /* El permiso se pide de entrada. Antes se esperaba a un gesto del
           jugador, pero el que no lo da nunca se queda sin bonos, sin avisos de
           recarga y sin las respuestas del chat, que es justo lo que la app
           tiene para ofrecer. Si ya lo dio, no hace nada. */
        pedirPermisoNotificaciones()

        if (savedInstanceState == null) web.loadUrl(urlDeNotificacion(intent) ?: INICIO)
        acusarNotificacion(intent)
    }

    /** Vino de tocar una notificacion estando la app viva. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        acusarNotificacion(intent)
        urlDeNotificacion(intent)?.let { web.loadUrl(it) }
    }

    // ------------------------------------------------------------------ setup

    private fun configurarWebView() {
        val esDebug = (applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0
        if (esDebug) WebView.setWebContentsDebuggingEnabled(true)

        web.settings.apply {
            javaScriptEnabled = true

            // ESTO es lo que hace que la plataforma funcione. Viene apagado por
            // defecto y sin esto el SPA rompe en silencio, igual que en el iframe.
            domStorageEnabled = true

            @Suppress("DEPRECATION")
            databaseEnabled = true

            mediaPlaybackRequiresUserGesture = false
            javaScriptCanOpenWindowsAutomatically = true
            setSupportMultipleWindows(false)   // window.open carga en esta misma vista

            useWideViewPort = true
            loadWithOverviewMode = true
            setSupportZoom(false)
            builtInZoomControls = false
            displayZoomControls = false

            cacheMode = WebSettings.LOAD_DEFAULT
            // La version va de BuildConfig, no hardcodeada: quedaba pegada en
            // "1.0" desde el primer release aunque versionName ya iba por 1.4.
            // nginx solo mira que contenga "GOLDPAW" (ver nginx-replica.conf),
            // asi que esto no cambia el enrutamiento, solo deja de mentir.
            userAgentString = "$userAgentString GOLDPAW/${BuildConfig.VERSION_NAME}"
        }

        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            // Los juegos vienen embebidos de proveedores externos: sin esto no
            // levantan sesion adentro de sus iframes.
            setAcceptThirdPartyCookies(web, true)
        }

        web.webViewClient = ClienteWeb()
        web.webChromeClient = ClienteChrome()

        // El asistente le pasa por aca quien es el jugador, para que el sondeo
        // de notificaciones sepa a quien buscar con la app cerrada.
        web.addJavascriptInterface(PuenteApp(this, tokenPuente), "GoldpawApp")

        web.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            descargar(url, userAgent, contentDisposition, mimeType)
        }
    }

    /** Baja el asistente (o usa el de assets si no hay red / no existe). */
    private fun cargarWidget() {
        Thread {
            var js: String? = null
            if (WIDGET_REMOTO.isNotEmpty()) {
                try {
                    val con = (URL(WIDGET_REMOTO).openConnection() as HttpURLConnection).apply {
                        connectTimeout = 4000
                        readTimeout = 4000
                        requestMethod = "GET"
                    }
                    if (con.responseCode == 200) {
                        val cuerpo = con.inputStream.bufferedReader().readText()
                        // Algunos hostings devuelven una pagina de error con codigo
                        // 200. Si no lleva nuestra marca, no es el asistente.
                        if (cuerpo.contains("__goldpaw")) js = cuerpo
                        else Log.w(TAG, "widget remoto invalido, uso el de assets")
                    }
                    con.disconnect()
                } catch (e: Exception) {
                    Log.i(TAG, "widget remoto no disponible: ${e.message}")
                }
            }
            if (js.isNullOrBlank()) {
                js = try {
                    assets.open("widget.js").bufferedReader().use { it.readText() }
                } catch (e: Exception) {
                    Log.e(TAG, "no se pudo leer assets/widget.js", e); null
                }
            }
            runOnUiThread {
                widgetJs = js
                // Si la pagina ya cargo mientras bajaba, inyectar ahora.
                if (!huboError && web.progress == 100) inyectarWidget()
            }
        }.start()
    }

    private fun inyectarWidget() {
        val js = widgetJs ?: return
        // El token va PRIMERO: el widget lo lee de window.__gp_app_tk para poder
        // usar el puente. Queda solo en el documento principal, asi que los
        // iframes de los juegos (otro origen) no pueden leerlo.
        web.evaluateJavascript("window.__gp_app_tk=${JSONObject.quote(tokenPuente)};", null)
        web.evaluateJavascript(js, null)
    }

    private fun manejarAtras() {
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                when {
                    vistaCustom != null -> (web.webChromeClient as? ClienteChrome)?.onHideCustomView()
                    web.canGoBack() -> web.goBack()
                    else -> {
                        val ahora = System.currentTimeMillis()
                        if (ahora - ultimoAtras < 2000) {
                            isEnabled = false
                            onBackPressedDispatcher.onBackPressed()
                        } else {
                            ultimoAtras = ahora
                            Toast.makeText(
                                this@MainActivity, R.string.salir_confirm, Toast.LENGTH_SHORT
                            ).show()
                        }
                    }
                }
            }
        })
    }

    // ------------------------------------------------------------- webclients

    private inner class ClienteWeb : WebViewClient() {

        override fun shouldOverrideUrlLoading(v: WebView, req: WebResourceRequest): Boolean {
            val url = req.url
            val esWeb = url.scheme == "https" || url.scheme == "http"

            // Todo lo que sea web se queda adentro: la plataforma abre los juegos
            // en dominios de proveedores y mandarlos al navegador rompe la sesion.
            if (esWeb) return false

            // whatsapp:, tel:, mailto:, intent:... a su app correspondiente.
            return abrirAfuera(url)
        }

        override fun onPageFinished(v: WebView, url: String) {
            if (!huboError) {
                cargando.visibility = View.GONE
                inyectarWidget()
            }
            CookieManager.getInstance().flush()
        }

        override fun onReceivedError(v: WebView, req: WebResourceRequest, err: WebResourceError) {
            // Solo nos importa que falle la pagina principal, no un recurso suelto.
            if (!req.isForMainFrame) return
            huboError = true
            cargando.visibility = View.GONE
            error.visibility = View.VISIBLE
        }
    }

    private inner class ClienteChrome : WebChromeClient() {

        override fun onShowCustomView(view: View, callback: CustomViewCallback) {
            if (vistaCustom != null) { callback.onCustomViewHidden(); return }
            vistaCustom = view
            callbackCustom = callback
            pantallaCompleta.addView(view)
            pantallaCompleta.visibility = View.VISIBLE
            web.visibility = View.GONE
            barrasDelSistema(false)
        }

        override fun onHideCustomView() {
            val v = vistaCustom ?: return
            pantallaCompleta.removeView(v)
            pantallaCompleta.visibility = View.GONE
            web.visibility = View.VISIBLE
            vistaCustom = null
            callbackCustom?.onCustomViewHidden()
            callbackCustom = null
            barrasDelSistema(true)
        }

        override fun onShowFileChooser(
            v: WebView,
            callback: ValueCallback<Array<Uri>>,
            params: FileChooserParams
        ): Boolean {
            callbackArchivos?.onReceiveValue(null)   // si quedo uno colgado
            callbackArchivos = callback
            paramsArchivos = params

            val tieneCamara = ContextCompat.checkSelfPermission(
                this@MainActivity, Manifest.permission.CAMERA
            ) == PackageManager.PERMISSION_GRANTED

            if (tieneCamara) abrirSelector() else pedirCamara.launch(Manifest.permission.CAMERA)
            return true
        }

        /** Un juego pide camara o microfono: se lo damos si ya tenemos el permiso. */
        override fun onPermissionRequest(request: PermissionRequest) {
            val ok = ContextCompat.checkSelfPermission(
                this@MainActivity, Manifest.permission.CAMERA
            ) == PackageManager.PERMISSION_GRANTED
            if (ok) request.grant(request.resources) else request.deny()
        }
    }

    // -------------------------------------------------------------- archivos

    private fun abrirSelector() {
        val params = paramsArchivos
        if (params == null) { callbackArchivos?.onReceiveValue(null); callbackArchivos = null; return }

        val intentContenido = params.createIntent()
        val intents = mutableListOf<Intent>()

        // Sacar una foto del comprobante, si hay permiso y camara.
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED
        ) {
            crearIntentCamara()?.let { intents.add(it) }
        }

        val chooser = Intent.createChooser(intentContenido, getString(R.string.elegir_archivo))
        if (intents.isNotEmpty()) {
            chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, intents.toTypedArray())
        }

        try {
            selectorArchivos.launch(chooser)
        } catch (e: ActivityNotFoundException) {
            callbackArchivos?.onReceiveValue(null)
            callbackArchivos = null
            Toast.makeText(this, "No hay ninguna app para elegir archivos", Toast.LENGTH_SHORT).show()
        }
    }

    private fun crearIntentCamara(): Intent? = try {
        val dir = File(cacheDir, "capturas").apply { mkdirs() }
        val sello = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(Date())
        val archivo = File(dir, "comprobante_$sello.jpg")
        val uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", archivo)
        uriCamara = uri
        Intent(android.provider.MediaStore.ACTION_IMAGE_CAPTURE).apply {
            putExtra(android.provider.MediaStore.EXTRA_OUTPUT, uri)
            addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
        }
    } catch (e: Exception) {
        Log.e(TAG, "no se pudo preparar la camara", e)
        uriCamara = null
        null
    }

    // -------------------------------------------------------------- descargas

    private fun descargar(url: String, ua: String?, disposition: String?, mime: String?) {
        if (!url.startsWith("http")) {
            Toast.makeText(this, "No se puede descargar este archivo", Toast.LENGTH_SHORT).show()
            return
        }
        try {
            val nombre = URLUtil.guessFileName(url, disposition, mime)
            val pedido = DownloadManager.Request(Uri.parse(url)).apply {
                setMimeType(mime)
                addRequestHeader("cookie", CookieManager.getInstance().getCookie(url) ?: "")
                addRequestHeader("User-Agent", ua ?: "")
                setTitle(nombre)
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nombre)
            }
            (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(pedido)
            Toast.makeText(this, "Descargando $nombre", Toast.LENGTH_SHORT).show()
        } catch (e: Exception) {
            Log.e(TAG, "fallo la descarga", e)
            abrirAfuera(Uri.parse(url))
        }
    }

    // -------------------------------------------------------- notificaciones

    /**
     * Adonde lleva la notificacion que toco, si traia destino.
     *
     * Se valida que sea https y nada mas: esta activity esta exportada, asi que
     * cualquier app del telefono puede mandarle un Intent. Sin este filtro, un
     * extra con "javascript:..." se ejecutaria adentro de la sesion del jugador.
     */
    private fun urlDeNotificacion(intent: Intent?): String? {
        val url = intent?.getStringExtra(EXTRA_NOTIF_URL) ?: return null
        return if (url.startsWith("https://")) url else null
    }

    /** Le avisa al server que la notificacion se toco (solo para estadistica). */
    private fun acusarNotificacion(intent: Intent?) {
        val id = intent?.getIntExtra(EXTRA_NOTIF_ID, 0) ?: 0
        // Los recordatorios los arma el propio celular: no existen en la base.
        if (id <= 0 || id >= Enganche.ID_NOTIFICACION) return
        intent?.removeExtra(EXTRA_NOTIF_ID)   // que no se cuente dos veces al rotar
        val device = Notificaciones.deviceId(this) ?: return
        Thread { Notificaciones.marcarLeida(device, id) }.start()
    }

    /**
     * Permiso de Android 13+. Lo dispara el widget sobre un gesto del jugador
     * (abrir el chat, girar la ruleta): pedirlo apenas abre la app, antes de
     * haberle dado nada, es la forma mas rapida de que lo rechace para siempre.
     */
    fun pedirPermisoNotificaciones() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        if (concedido()) return
        Notificaciones.marcarPermisoPedido(this)
        pedirNotificaciones.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun concedido(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED

    /**
     * "Activar" del cartelito del asistente. Elige solo el camino correcto,
     * porque el widget no tiene con que saberlo:
     *
     *   - se puede mostrar el dialogo  -> se muestra
     *   - Android ya no lo muestra mas -> se va a los ajustes del sistema
     *   - el permiso esta pero el canal apagado -> tambien a los ajustes
     */
    fun activarNotificaciones() {
        if (concedido()) {
            // Tiene el permiso pero puede haber apagado los avisos a mano.
            if (!Notificaciones.permitidas(this)) abrirAjustesNotificaciones()
            return
        }
        // Android deja de mostrar el dialogo despues del segundo "no". Ahi
        // shouldShowRequestPermissionRationale queda en false y launch() no hace
        // nada: si no mandaramos a los ajustes, el boton no haria absolutamente
        // nada y el jugador quedaria sin salida.
        val sinDialogo = Notificaciones.permisoYaPedido(this) &&
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            !shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)

        if (sinDialogo) abrirAjustesNotificaciones() else pedirPermisoNotificaciones()
    }

    /**
     * Abre la pantalla de notificaciones de la app en los ajustes del sistema.
     *
     * Es la unica salida cuando el permiso quedo denegado para siempre (Android
     * no vuelve a mostrar el dialogo) o cuando el jugador las apago a mano. La
     * dispara el cartelito del asistente, nunca sola.
     */
    fun abrirAjustesNotificaciones() {
        val ajustes = Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
            .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        try {
            startActivity(ajustes)
            return
        } catch (e: ActivityNotFoundException) {
            Log.i(TAG, "sin pantalla de notificaciones: ${e.message}")
        }
        // Algunas capas de fabricante no tienen esa pantalla: vamos a la ficha
        // de la app, que siempre existe.
        try {
            startActivity(
                Intent(
                    Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", packageName, null)
                ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.notif_bloqueadas, Toast.LENGTH_LONG).show()
        }
    }

    // ----------------------------------------------------------------- varios

    private fun abrirAfuera(uri: Uri): Boolean = try {
        startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        true
    } catch (e: ActivityNotFoundException) {
        Log.i(TAG, "sin app para $uri")
        true   // igual lo consumimos: el WebView no sabe abrirlo
    }

    private fun barrasDelSistema(mostrar: Boolean) {
        @Suppress("DEPRECATION")
        window.decorView.systemUiVisibility = if (mostrar) {
            View.SYSTEM_UI_FLAG_VISIBLE
        } else {
            View.SYSTEM_UI_FLAG_FULLSCREEN or
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE or
                View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
        }
    }

    private fun reintentar() {
        huboError = false
        error.visibility = View.GONE
        cargando.visibility = View.VISIBLE
        web.loadUrl(INICIO)
    }

    override fun onPause() {
        super.onPause()
        web.onPause()
        CookieManager.getInstance().flush()   // que la sesion sobreviva al cierre
        // Desde aca empieza a contar el "hace cuanto que no entra".
        Enganche.marcarApertura(this, false)
    }

    override fun onResume() {
        super.onResume()
        web.onResume()
        // Esta adentro: el sondeo de fondo no tiene que mostrarle nada en la
        // barra, de eso se ocupa el widget en pantalla.
        Enganche.marcarApertura(this, true)
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onRestoreInstanceState(savedInstanceState: Bundle) {
        super.onRestoreInstanceState(savedInstanceState)
        web.restoreState(savedInstanceState)
    }

    override fun onDestroy() {
        callbackArchivos?.onReceiveValue(null)
        callbackArchivos = null
        super.onDestroy()
    }
}
