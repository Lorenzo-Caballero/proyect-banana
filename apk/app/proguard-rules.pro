# La app es una Activity con un WebView: no hay nada que ofuscar que valga la
# pena, y minify esta apagado. Se deja el archivo porque AGP lo referencia.
-keepattributes JavascriptInterface
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
