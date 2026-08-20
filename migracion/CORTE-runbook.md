# Corte Fase 0 — Hostinger → VPS

El paso que mueve la fuente de verdad de Hostinger al VPS. **Toca saldos reales.**
Hacelo en ventana de poco tráfico, con ~30-40 min y estando presente.

Regla de oro: **todos los que escriben en la base pasan al VPS lo más junto posible.**
Si a mitad de camino unos escriben en Hostinger y otros en el VPS, las dos bases
divergen y se pierden saldos. Por eso primero se prepara TODO, y el flip es rápido.

## Antes de empezar (verificar, no cambia nada)

```bash
# el fix del bot está en el contenedor (debe dar >=4)
cd ~/Bot-python && docker compose exec creador grep -c esperar_busqueda /app/bot_cargar_fichas.py
# la API del VPS responde por HTTP (harness localhost)
curl -s -X POST -H "Content-Type: application/json" -d '{}' http://127.0.0.1:8081/chatbot.php
```

## Paso 1 — dejar TODO cargado pero sin activar

**a) `/gp-api/` público en el nginx del host** (apunta a php-fpm local). Insertar en
el server 443 de `/etc/nginx/sites-available/replica`, junto a las otras `location`:

```nginx
    location ^~ /gp-api/ {
        alias /var/www/api/;
        location ~ ^/gp-api/(?<f>.+\.php)$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/api/$f;
        }
    }
```

```bash
sudo cp /etc/nginx/sites-available/replica /root/replica-precorte-$(date +%F-%H%M).conf
# (editar e insertar el bloque)
sudo nginx -t && sudo systemctl reload nginx
# probar por HTTPS público:
curl -s -X POST -H "Content-Type: application/json" -d '{}' https://ganamos.faunotattoo.com/gp-api/chatbot.php
```

> OJO: apenas esto quede activo, el navegador de la réplica empieza a escribir en
> la base del VPS (el widget ya pega a `/gp-api/`). Por eso este paso arranca el
> flip — de acá al final, ir rápido.

**b) forwarder de Hostinger, subido pero NO activo todavía.** Subir
`migracion/hostinger-forwarder/_forward.php` a la carpeta `api/` de Hostinger.
Todavía NO tocar el `.htaccess` (eso lo activa).

**c) bots y colector, listos para apuntar al VPS** (editar pero no reiniciar aún):
en `~/Bot-python/.env`, `API_URL` → `https://ganamos.faunotattoo.com/gp-api/usuarios_sync.php`
(o localhost). Igual el colector.

## Paso 2 — el flip (rápido y seguido)

```bash
# 1. DUMP FRESCO de Hostinger -> VPS (captura todo lo escrito hasta este segundo)
#    Exportar de nuevo desde phpMyAdmin de Hostinger y subir como dump_corte.sql
sudo mariadb u722310012_fauno888 < /root/dump_corte.sql

# 2. activar el forwarder en Hostinger: renombrar el .htaccess viejo y subir el nuevo
#    (.htaccess viejo -> .htaccess.pre-corte ; subir migracion/hostinger-forwarder/.htaccess)

# 3. activar /gp-api/ (ya recargado en el paso 1a)

# 4. reiniciar bots y colector apuntando al VPS
cd ~/Bot-python && docker compose up -d --force-recreate
```

Desde acá, la base del VPS es la única que recibe escrituras. La de Hostinger
queda congelada.

## Paso 3 — verificar

- Chat desde el navegador (réplica) y desde la APK → responde.
- Una carga de fichas de prueba → aparece en `movimientos` del VPS.
- `docker compose logs -f creador` → sin errores de conexión.
- En el VPS: `SELECT MAX(id) FROM movimientos;` sube al usar el sitio.

## Rollback (si algo falla en los primeros minutos)

Mientras NO se hayan acumulado escrituras importantes en el VPS:
1. Hostinger: restaurar `.htaccess.pre-corte`.
2. VPS nginx: sacar el `location /gp-api/` y recargar.
3. Bots: `.env` de vuelta a Hostinger, recrear contenedores.

Una vez que hay saldos nuevos en el VPS, el rollback los perdería: a esa altura,
se sigue para adelante y se arregla puntual.

## Después del corte

- La APK sigue andando vía el forwarder. Cuando se saque una versión nueva que
  apunte directo al VPS, se puede quitar el forwarder.
- Bajar el harness de prueba: `sudo rm /etc/nginx/sites-enabled/gp-test && sudo systemctl reload nginx`.
- Reboot pendiente del kernel del VPS, en otra ventana tranquila.
