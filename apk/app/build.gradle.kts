import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")

    /* OJO: este plugin EXIGE que exista app/google-services.json. Si falta, el
       build muere con "File google-services.json is missing" y no con algo que
       se parezca a Firebase. El archivo lo baja el dueño de la cuenta desde la
       consola (Configuracion del proyecto > Tus apps) y NO es secreto: viaja
       adentro del APK, cualquiera que lo descargue lo puede leer. Solo
       identifica al proyecto. La clave que SI es secreta es la de cuenta de
       servicio, que vive en el VPS (/etc/goldpaw/firebase.json) y nunca toca
       este repo. */
    id("com.google.gms.google-services")
}

// Firma de release. Si no existe keystore.properties, el release se firma con
// la clave de debug para que el build nunca se rompa (util para probar).
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
val tieneKeystore = keystoreProps.getProperty("storeFile") != null

android {
    namespace = "com.goldpaw.app"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.goldpaw.app"
        // 26 = Android 8.0. Permite usar icono adaptativo sin PNGs y cubre
        // practicamente todo el parque de telefonos en uso.
        minSdk = 26
        targetSdk = 34
        // El APK se instala a mano, asi que subir esto es la unica forma de
        // saber que build tiene cada jugador (se lee en Ajustes > Apps).
        //   1.1  notificaciones de premios
        //   1.2  icono nuevo + salida a los ajustes si el permiso quedo bloqueado
        //   1.3  respuesta del chatbot como notificacion del sistema + icono de la app
        //   1.4  apunta a la replica del VPS (multi-cliente): chat, mensajes del
        //        CRM y notificaciones ahora salen de /gp-api del dominio propio
        //   1.5  icono nuevo (el perrito) + el User-Agent ya no queda pegado en
        //        "GOLDPAW/1.0": ahora coincide con versionName
        //   1.6  pide quedar fuera de la optimizacion de bateria. El sondeo con
        //        la app cerrada casi no corria: de 42 celulares con permiso,
        //        en 24 horas sondearon 8 -- y dependia de la MARCA (Samsung 5
        //        de 6, Xiaomi 1 de 20), que es la firma del administrador de
        //        bateria del fabricante matando el trabajo periodico.
        //   1.7  Firebase. La exencion de bateria de la 1.6 ayuda pero no
        //        alcanza: el jugador puede decir que no, y varias marcas la
        //        ignoran igual. FCM no corre en nuestro proceso sino dentro de
        //        Google Play Services, que el administrador de bateria del
        //        fabricante no mata porque romperia el telefono entero. El
        //        sondeo cada 15 min QUEDA, como respaldo.
        //   1.8  el push lleva el TEXTO adentro, no solo la senal. La 1.7
        //        mandaba un "fijate" vacio, y eso necesita que Android arranque
        //        la app: un telefono con la app deslizada de recientes no la
        //        arranca. Medido en un Moto G52 con la bateria sin
        //        restricciones -- push vacio: nunca llega; push con texto:
        //        20-30 s. Cada aviso lleva la etiqueta gp-<id>, compartida con
        //        el server, para que Android y la app no lo muestren dos veces.
        //   1.9  los recordatorios para volver a jugar se configuran desde el
        //        CRM (venian cableados en 4 por dia desde 3 h sin abrir) y van
        //        en SU PROPIO canal de Android. Compartiendo canal con los
        //        avisos de plata, al jugador que le molestaban solo le quedaba
        //        silenciar la app entera -- y ahi perdia tambien el bono, la
        //        recarga y la respuesta del chat.
        //
        // EL versionCode HAY QUE SUBIRLO SIEMPRE, y es facil de olvidar porque
        // el build sale igual: Android NO instala encima de una version
        // instalada si el numero no es mayor. El jugador toca "instalar", no
        // pasa nada, y no hay ningun error que lo explique.
        versionCode = 10
        versionName = "1.9"
    }

    signingConfigs {
        if (tieneKeystore) {
            create("release") {
                storeFile = rootProject.file(keystoreProps.getProperty("storeFile"))
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = if (tieneKeystore) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    // AGP 8+ no genera BuildConfig si no se pide: lo necesita MainActivity
    // para mandar la version real en el User-Agent (antes quedaba hardcodeada).
    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-ktx:1.9.0")

    // Sondeo de notificaciones con la app cerrada. Desde la 1.7 ya no es el
    // camino principal (lo es Firebase) sino el RESPALDO, y por eso se queda:
    // cubre al telefono sin Google Play Services, al que se quedo sin token, y
    // a cualquier caida del lado de Google. Minimo 15 minutos entre corridas.
    implementation("androidx.work:work-runtime-ktx:2.9.1")

    /* Firebase Cloud Messaging: el empujon que despierta a la app en segundos.
       Via BOM para no tener que versionar cada libreria de Firebase a mano.

       NO SUBIR A LA 34.x SIN SUBIR TODO LO DEMAS, aunque la consola de Firebase
       la recomiende (el 20/09/2026 ofrecia la 34.19.0). Esa linea se compila
       contra compileSdk 35, y AGP 8.5.2 corta con:

           Dependency X requires libraries and applications that depend on it
           to compile against version 35 or later of the Android APIs

       o sea que arrastra compileSdk 34 -> 35 y AGP 8.5.2 -> 8.6+. Se puede
       hacer, pero no se gana nada: lo que usamos de FCM
       (FirebaseMessagingService, onNewToken, getToken) no cambio en anos. Si
       algun dia hay que subirla, que sea por un motivo, y los dos cambios van
       juntos o el build no arranca. */
    implementation(platform("com.google.firebase:firebase-bom:33.5.1"))
    implementation("com.google.firebase:firebase-messaging-ktx")
}
