# replica — ganamos7.com en un dominio propio

Sirve la **misma** app de `ganamos7.com` desde un dominio tuyo, con un chat
inyectado. No es una copia del código: un reverse proxy reenvía cada request al
origen real y le agrega el chat al vuelo. Es exactamente lo que hace
`ganandolive.com` (mismo bundle `index-D6Ps1_XS.js`, servido desde otro dominio
detrás de Cloudflare).

## Por qué esto resuelve el login

Embebida en un `<iframe>`, la plataforma rompe: el navegador particiona el
`localStorage` y, si el usuario bloquea cookies de terceros, tira `SecurityError`
y el login falla en silencio. Servida por este proxy, el navegador ve **un solo
origen** (el tuyo), así que:

- el login anda en todos los navegadores, con o sin cookies de terceros;
- el chat lee `API_AUTH_ACCESS_TOKEN` directo del `localStorage` y saluda al
  jugador por su nombre, sin la gimnasia de espiar el DOM.

## ⚠️ Antes de desplegar

Por este proxy pasa el **login de los jugadores** (usuario y contraseña de la
plataforma). Proxear `ganamos7.com` requiere **autorización del operador**. No es
una decisión técnica: es tu responsabilidad legal conseguirla por escrito.

## Qué hay acá

| Archivo | Qué es |
|---|---|
| `nginx-replica.conf` | El reverse proxy. Reenvía a ganamos7.com e inyecta el chat. |
| `replica-proxy.conf` | Cabeceras comunes. Va en `/etc/nginx/snippets/`. |
| `widget.js` | El chat. Se sirve local y se inyecta antes de `</body>`. |
| `docker-compose.yml` | Levanta nginx + certbot (TLS automático) con un comando. |

Todos necesitan lo mismo: un dominio tuyo y una IP donde correr.

### La velocidad: por qué el proxy cachea

Medido, no supuesto: **la plataforma está en Moscú** (~250-320 ms desde
Argentina) y su bundle pesa **3,66 MB, sin comprimir y sin `Cache-Control`** —
7,4 s por jugador y por visita.

Nada de eso se puede cambiar desde acá. Lo que sí se puede es que este VPS haga
de CDN: como los assets llevan hash de Vite en el nombre (`index-C4Ib8z9Z.js`),
cambian en cada deploy y por lo tanto se pueden cachear sin miedo a servir una
versión vieja. El primer jugador paga el viaje a Moscú; el resto lo recibe de
São Paulo y comprimido.

**Lo dinámico (login, saldo, abrir un juego) sigue costando lo que cuesta
Moscú.** Eso no lo arregla ninguna configuración.

Por eso el despliegue tiene **un paso extra**: la caché se declara en el
contexto `http`, o sea en `/etc/nginx/nginx.conf`, no en el archivo del sitio.
Está explicado en la cabecera de `nginx-replica.conf`.

---

## Requisitos

1. **Un VPS** con IP pública y los puertos 80 y 443 abiertos.
2. **Un dominio tuyo** — no un subdominio de ganamos7.com (su DNS no es tuyo).
   Ej: `juego.tudominio.com`.
3. **DNS**: un registro **A** de tu dominio apuntando a la IP del VPS. Verificá
   que resuelva antes de seguir: `dig +short juego.tudominio.com`.

En los tres archivos, reemplazá `REEMPLAZAME.tudominio.com` por tu dominio real:

```bash
cd replica
sed -i 's/REEMPLAZAME.tudominio.com/juego.tudominio.com/g' nginx-replica.conf docker-compose.yml
```

---

## Opción A — Docker (recomendada)

nginx y la renovación del certificado corren en contenedores. Es lo que mejor
encaja con el resto del proyecto.

```bash
cd replica
mkdir -p certbot/conf certbot/webroot

# 1) Emitir el certificado la PRIMERA vez, con el puerto 80 libre.
#    (nginx todavía no está levantado, así que certbot puede usar --standalone)
docker run --rm -p 80:80 \
  -v "$PWD/certbot/conf:/etc/letsencrypt" \
  certbot/certbot certonly --standalone \
  -d juego.tudominio.com --agree-tos -m tu@email.com --no-eff-email

# 2) Levantar todo. nginx ya encuentra el certificado y arranca.
docker compose up -d
docker compose logs -f nginx
```

La renovación es automática: el contenedor `certbot` chequea cada 12 h y nginx
recarga la config cada 6 h para tomar el certificado nuevo sin cortar el
servicio.

---

## Opción B — nginx en el host

Si el VPS ya tiene nginx instalado:

```bash
# 1) La caché va en el contexto http, o sea DENTRO de `http { }` en
#    /etc/nginx/nginx.conf. Si falta esta línea, nginx -t falla con
#    "proxy_cache_path not defined" y no arranca.
#
#      proxy_cache_path /var/cache/nginx/replica levels=1:2
#                       keys_zone=replica:50m max_size=2g
#                       inactive=30d use_temp_path=off;
#
sudo mkdir -p /var/cache/nginx/replica

# 2) Los archivos
sudo cp replica-proxy.conf /etc/nginx/snippets/
sudo cp nginx-replica.conf /etc/nginx/sites-available/replica
sudo ln -s /etc/nginx/sites-available/replica /etc/nginx/sites-enabled/
sudo mkdir -p /var/www/replica && sudo cp widget.js /var/www/replica/
sudo mkdir -p /var/www/certbot

sudo certbot --nginx -d juego.tudominio.com     # emite el cert y toca la config

# 3) Recién ahora
sudo nginx -t && sudo systemctl reload nginx
```

`certbot --nginx` renueva solo por cron/systemd; no hay que hacer nada más.

Para confirmar que la caché quedó andando:

```bash
curl -sI https://juego.tudominio.com/assets/index-XXXX.js | grep -i x-cache
# 1ra vez: X-Cache: MISS     2da vez: X-Cache: HIT
```

Si siempre dice `MISS`, la caché no está funcionando y seguís igual que antes.

---

## Probar que anda

```bash
# 1) Llega la app (mismo bundle que el origen)
curl -sI https://juego.tudominio.com/home | head -1        # -> HTTP/2 200

# 2) El chat se está inyectando
curl -s https://juego.tudominio.com/home | grep -c replica/widget.js   # -> 1

# 3) El chat se sirve local
curl -sI https://juego.tudominio.com/replica/widget.js | head -1       # -> HTTP/2 200
```

Después, en el navegador: entrá a `https://juego.tudominio.com/home`,
**iniciá sesión** y confirmá que quedás adentro (no te rebota a ganamos7.com).
El chat tiene que aparecer abajo a la derecha y, si estás logueado, saludarte
por tu nombre.

---

## Si algo no anda

- **El login rebota a ganamos7.com** → faltó el `proxy_redirect`, o el origen
  mandó una URL absoluta que no matcheó. Mirá `curl -sI .../login` y fijate el
  `location:`.
- **La página carga pero sin estilos/juegos** → casi siempre es `Accept-Encoding`:
  el `sub_filter` necesita el HTML sin comprimir. Ya está puesto en `""`; no lo
  saques.
- **El chat no aparece** → `curl` del punto 2 da 0. El origen empezó a mandar un
  CSP: descomentá `proxy_hide_header Content-Security-Policy;` en la config.
- **`nginx: cannot load certificate`** al arrancar en Docker → todavía no emitiste
  el certificado. Volvé al paso 1 de la Opción A.
- **Un juego puntual no abre** → son iframes de terceros (Pragmatic, etc.) que
  cargan desde SU propio dominio, no desde el proxy. Eso es esperado y no lo
  arregla el proxy; el juego se sirve directo desde el proveedor.

---

## Qué NO hace

- No toca la base de datos ni la API de la plataforma: solo reenvía.
- No guarda contraseñas: pasan por el proxy hacia el origen y nada se loguea
  (el `location /` no registra el cuerpo de las requests, a propósito).
- No sirve para eludir un bloqueo del operador: si te cortan el acceso desde la
  IP del VPS, la réplica deja de funcionar. Por eso hace falta la autorización.
