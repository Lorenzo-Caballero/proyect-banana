# ---------------------------------------------------------------------------
# subir.ps1 — Sube la réplica al VPS.
#
#   .\replica\subir.ps1                 solo el widget (lo más habitual)
#   .\replica\subir.ps1 -Config         además la config de nginx y recarga
#   .\replica\subir.ps1 -Usuario ubuntu -Host 1.2.3.4
#
# Correlo desde la RAÍZ del repo.
#
# OJO: si tu IP está baneada en el firewall del VPS, el scp se va a quedar
# colgado sin decir nada útil. Probá primero `ssh <usuario>@<host> echo ok`;
# si eso no responde, no es el script, es el firewall.
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [string]$Usuario = "root",
    [string]$Maquina = "168.231.98.136",
    [switch]$Config
)

$ErrorActionPreference = "Stop"
$destino = "$Usuario@$Maquina"

function Verificar($ruta) {
    if (-not (Test-Path $ruta)) {
        throw "No encuentro $ruta. ¿Estás parado en la raíz del repo?"
    }
}

# Se sube landing/widget.js, NO una copia aparte para la réplica.
#
# Antes había dos widgets y se fueron separando hasta que el de replica quedó
# con 276 líneas contra 1190: toda la detección del usuario logueado estaba
# escrita en el de landing y el VPS servía el otro, así que en el subdominio
# no pasaba nada. Ahora es un archivo solo que decide sus URLs por el hostname
# (ver REPLICAS arriba de landing/widget.js).
Verificar "landing/widget.js"

Write-Host "→ widget.js (desde landing/)" -ForegroundColor Cyan
scp landing/widget.js "${destino}:/var/www/replica/widget.js"
if ($LASTEXITCODE -ne 0) { throw "falló el scp del widget" }

# Páginas propias servidas por el VPS bajo /replica/ (así el CRM/landing de
# cada cliente queda en https://<cliente>.faunotattoo.com/replica/crm.html y
# db.php resuelve la base por el dominio). Necesitan el `location` de
# /replica/*.html en nginx (subir.ps1 -Config lo instala). registro.html es
# la landing pública de auto-alta (crea el pedido en `altas`, lo cumple
# bot_crear_jugador.py). Si falta alguno, se saltea sin romper.
foreach ($pagina in @("crm.html", "admin.html", "chat.html", "registro.html", "sw.js")) {
    if (Test-Path "landing/$pagina") {
        Write-Host "→ $pagina" -ForegroundColor Cyan
        scp "landing/$pagina" "${destino}:/var/www/replica/$pagina"
        if ($LASTEXITCODE -ne 0) { throw "falló el scp de $pagina" }
    }
}

# El avatar se sirve desde el VPS: pedírselo a Hostinger lo deja a merced del
# WAF. Si todavía no generaste los íconos, se saltea sin romper nada.
if (Test-Path "landing/img/logo-192.png") {
    Write-Host "→ logo.png (avatar del chat)" -ForegroundColor Cyan
    scp landing/img/logo-192.png "${destino}:/var/www/replica/logo.png"
    if ($LASTEXITCODE -ne 0) { throw "falló el scp del logo" }
} else {
    Write-Host "  (sin landing/img/logo-192.png: corre herramientas/generar_logo.py)" -ForegroundColor Yellow
}

if (Test-Path "replica/ver-logs.sh") {
    Write-Host "→ ver-logs.sh" -ForegroundColor Cyan
    scp replica/ver-logs.sh "${destino}:~/ver-logs.sh"
}

if ($Config) {
    Verificar "replica/nginx-replica.conf"
    Verificar "replica/replica-proxy.conf"

    Write-Host "→ configuración de nginx" -ForegroundColor Cyan
    # A /tmp primero: el usuario de scp puede no tener permiso de escritura
    # directa en /etc, y así el sudo queda del lado del servidor.
    scp replica/nginx-replica.conf replica/replica-proxy.conf "${destino}:/tmp/"
    if ($LASTEXITCODE -ne 0) { throw "falló el scp de la config" }

    Write-Host "→ instalando y probando (nginx -t)" -ForegroundColor Cyan
    # Se recarga SOLO si nginx -t pasa: recargar una config rota deja el sitio
    # caído hasta que alguien lo note.
    # OJO: ni `ssh $destino $remoto` (arg posicional) ni `$remoto | ssh ... "bash -s"`
    # (pipe) sirven en Windows PowerShell 5.1: el pipeline hacia un binario
    # nativo pasa por la consola y le mete CRLF a cada línea -- bash ve un \r
    # pegado al final de "set -e" y tira "invalid option: -" /
    # "syntax error: unexpected end of file". El arreglo que sí funciona:
    # escribir el script a un archivo LOCAL con salto de línea LF forzado
    # (Out-File normal en 5.1 usa CRLF), subirlo por scp, y correrlo del
    # lado remoto con `bash archivo` -- sin pipe ni argumento posicional de
    # por medio.
    $remotoTxt = @'
set -e
sudo mkdir -p /etc/nginx/snippets /var/cache/nginx/replica /var/www/replica
BK=/etc/nginx/sites-available/replica.bak.$(date +%Y%m%d-%H%M%S)
if [ -f /etc/nginx/sites-available/replica ]; then sudo cp -a /etc/nginx/sites-available/replica $BK; fi
sudo mv /tmp/replica-proxy.conf /etc/nginx/snippets/replica-proxy.conf
sudo mv /tmp/nginx-replica.conf /etc/nginx/sites-available/replica
sudo ln -sf /etc/nginx/sites-available/replica /etc/nginx/sites-enabled/replica
if sudo nginx -t; then
    sudo systemctl reload nginx
    echo OK: nginx recargado. backup en $BK
else
    echo ERROR: nginx -t fallo. Restaurando la config anterior... >&2
    if [ -f $BK ]; then sudo cp -a $BK /etc/nginx/sites-available/replica; fi
    echo Restaurado. El sitio sigue con la config que andaba. >&2
    exit 1
fi
'@
    $tmpLocal = [System.IO.Path]::GetTempFileName()
    # LF explícito, sin BOM: [IO.File]::WriteAllText no agrega ninguno de los
    # dos por default, a diferencia de Out-File/Set-Content en 5.1.
    [System.IO.File]::WriteAllText($tmpLocal, $remotoTxt.Replace("`r`n", "`n"))

    scp $tmpLocal "${destino}:/tmp/aplicar_nginx.sh"
    if ($LASTEXITCODE -ne 0) { Remove-Item $tmpLocal; throw "falló el scp del script de config" }
    Remove-Item $tmpLocal

    ssh $destino "bash /tmp/aplicar_nginx.sh && rm -f /tmp/aplicar_nginx.sh"
    if ($LASTEXITCODE -ne 0) { throw "la config no pasó nginx -t" }

    Write-Host ""
    Write-Host "Recordá que proxy_cache_path va en /etc/nginx/nginx.conf," -ForegroundColor Yellow
    Write-Host "dentro de http { }. Está explicado en nginx-replica.conf." -ForegroundColor Yellow
}

if (-not $Config) {
    Write-Host ""
    Write-Host "AVISO: el widget ahora pide /api/ a ESTE dominio." -ForegroundColor Yellow
    Write-Host "Si nginx todavia no tiene el location ^~ /api/, el chat va a dar 404." -ForegroundColor Yellow
    Write-Host "Corre una vez:  .\replica\subir.ps1 -Config" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Listo. Comprobá con:" -ForegroundColor Green
Write-Host "  curl -sI https://ganamos.faunotattoo.com/replica/widget.js"
Write-Host "y en el navegador refrescá con Ctrl+Shift+R."
