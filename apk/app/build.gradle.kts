import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
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
        versionCode = 6
        versionName = "1.5"
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

    // Sondeo de notificaciones con la app cerrada. Es lo que reemplaza a
    // Firebase: WorkManager sobrevive al reinicio del telefono y respeta las
    // reglas de bateria, a cambio de un minimo de 15 minutos entre corridas.
    implementation("androidx.work:work-runtime-ktx:2.9.1")
}
