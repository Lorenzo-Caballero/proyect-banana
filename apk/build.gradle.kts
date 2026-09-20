plugins {
    id("com.android.application") version "8.5.2" apply false
    id("org.jetbrains.kotlin.android") version "1.9.24" apply false

    // Lee google-services.json y genera de ahi la config de Firebase. Version
    // dictada por la consola de Firebase al registrar la app (20/09/2026).
    id("com.google.gms.google-services") version "4.5.0" apply false
}
