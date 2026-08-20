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

# Páginas del CRM servidas por el VPS bajo /replica/ (así el CRM de cada cliente
# queda en https://<cliente>.faunotattoo.com/replica/crm.html y db.php resuelve
# la base por el dominio). Necesitan el `location` de /replica/*.html en nginx
# (subir.ps1 -Config lo instala). Si falta alguno, se saltea sin romper.
foreach ($pagina in @("crm.html", "admin.html", "chat.html", "sw.js")) {
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
    # OJO: este script se pasa por  ssh $destino $remoto  y PowerShell mastica
    # las comillas al hacerlo. NO uses parentesis en textos de echo ni comillas
    # dobles alrededor de mensajes: cuando PowerShell las come, bash ve un '('
    # suelto y tira "syntax error near unexpected token". Frases planas, sin ( ).
    # El $(date) va sin comillas y sobrevive; lo que rompe son las comillas
    # dobles de los echo con parentesis adentro.
    $remoto = @'
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
    ssh $destino $remoto
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
