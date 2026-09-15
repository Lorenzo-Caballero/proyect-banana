# Las páginas del proyecto, y por qué no abrían los juegos

**Para:** Fauno
**De:** el lado GOLDPAW (CRM / API / nginx del VPS)
**Fecha:** 15/09/2026 · medido en vivo contra `ganamoscrm.online`

> Autocontenido a propósito: se lo podés pasar entero a Claude sin más contexto.

---

## 1. Las páginas, y de quién es cada una

Hay **cuatro cosas distintas** que la gente llama "el sitio", y casi toda la
confusión sale de mezclarlas.

| Dominio | Qué es | De quién |
|---|---|---|
| `ganamos7.com` | La plataforma de verdad. El SPA de React donde se juega. | De la plataforma |
| `ganamoscrm.online` | **El nuestro.** Un Nginx en nuestro VPS que *espeja* `ganamos7.com` y le inyecta el chat/widget. Acá entra el jugador. | Propio (VPS) |
| `agents.ganamosonline.com` | El panel de agentes: altas, saldo, depósitos, retiros. Es contra esto que trabaja el bot. | Cuenta de agente propia |
| `ganamos.faunotattoo.com` | Etapa anterior. Nginx todavía lo acepta, pero **no se usa más**. | Propio (VPS) |

Y dentro de `ganamoscrm.online` conviven dos mundos en el mismo dominio:

```
ganamoscrm.online/home           -> proxy a ganamos7.com  (la plataforma)
ganamoscrm.online/slots, /casino -> proxy a ganamos7.com  (la plataforma)
ganamoscrm.online/crm.html       -> archivo NUESTRO del VPS
ganamoscrm.online/chat.html      -> archivo NUESTRO
ganamoscrm.online/descargar.html -> archivo NUESTRO (el APK)
ganamoscrm.online/gp-api/*.php   -> PHP NUESTRO (php-fpm local)
ganamoscrm.online/replica/widget.js -> el asistente inyectado, NUESTRO
```

La regla mental: **todo lo que no sea `/gp-api/`, `/replica/`, `/panel/` o un
`.html` nuestro, sale proxeado a `ganamos7.com`.** Por eso un error "del sitio"
puede ser de ellos o nuestro, y hay que mirar el path antes de opinar.

El widget se inyecta con `sub_filter` reemplazando `</body>`, con la versión
estampada en la URL:

```html
<script src="/replica/widget.js?v=<hash-del-commit>" defer></script>
```

Ese `?v=` es el termómetro más rápido que tenemos: dice **qué commit está
corriendo el VPS**. Se saca sin entrar a ningún lado:

```bash
curl -s https://ganamoscrm.online/home | grep -o 'widget.js?v=[a-z0-9]*'
```

---

## 2. Por qué no abrían los juegos

**Resumen en una línea:** el SPA le manda al proveedor de juegos **el dominio
desde el que lo estás mirando**, y desde la réplica ese dominio es
`ganamoscrm.online`, que el proveedor no tiene en su lista blanca.

### La evidencia, del propio bundle de la plataforma

`https://ganamoscrm.online/assets/index-B-xOvyR1.js` (3,3 MB, el SPA de React).
Para pedir el link de un juego de **Pragmatic** arma esto:

```js
{
  url: "pragmatic/game/link",
  getBody:    r => ({ game_id: r.id, platform: ..., lang: ... }),
  getHeaders: () => ({ headers: {
      "x-actual-domain": `https://${location.host}/`,   // <-- ACÁ
      "x-partner-name":  "ganamos",
      "x-partner-authorization": ...
  }})
}
```

`location.host` es **el dominio de la barra de direcciones del navegador**. O sea:

| El jugador entra por | El SPA manda |
|---|---|
| `ganamos7.com` | `x-actual-domain: https://ganamos7.com/` ✅ |
| `ganamoscrm.online` | `x-actual-domain: https://ganamoscrm.online/` ❌ |

`x-actual-domain` es exactamente el campo que los proveedores de casino usan
para validar desde qué dominio se lanza el juego. La licencia y el contrato son
**por dominio**: un origen que no está registrado no recibe sesión de juego.

Y no es solo Pragmatic. Otros nueve lanzamientos del bundle mandan el origen del
navegador por query string:

```js
home_url   = location.origin
return_url = encodeURIComponent(location.origin)
lobby_url  = location.origin
close_url  = encodeURIComponent(location.origin)
deposit_url= `${location.origin}/deposit`
```

Esos son sobre todo "a dónde vuelve el jugador cuando cierra el juego", así que
rompen la vuelta más que la apertura — pero si el proveedor también los valida,
suman.

### Lo que SÍ funciona, para que se vea que el proxy no está roto

Medido hoy contra la réplica:

```bash
$ curl -s 'https://ganamoscrm.online/api/site/pragmatic/gamelist?partner_name=ganamos'
{"status":0,"result":[{"gameID":"vs5wheel7s","gameName":"777 Wheel Blitz",...
```

El **listado** de juegos vuelve perfecto. O sea que el proxy, el login, la
sesión y la API de la plataforma andan. Lo único que se cae es el **lanzamiento**,
que es justo el paso donde viaja el dominio. Eso descarta las hipótesis fáciles
(“el proxy rompe todo”, “falta una cookie”, “es el CORS”).

### Las dos causas que NO son

Antes de tocar nada, descartar estas dos, que son las que uno supone primero:

1. **"Los juegos son iframes de terceros y el proxy no los alcanza."** Cierto
   pero irrelevante: el juego se sirve directo desde el proveedor
   (`bsw-dk1.pragmaticplay.net`, `prrplt3.com`, etc.) y eso está bien así. El
   problema es anterior: nunca se llega a tener la URL del juego.
2. **`Accept-Encoding`.** Es la causa clásica de "carga la página pero sin
   estilos ni juegos" en una réplica, porque `sub_filter` necesita el HTML sin
   comprimir. Ya está puesto en `""` en el `location /`, y el listado de juegos
   volviendo OK lo confirma. **No lo saques.**

### El arreglo probable, y lo que falta para confirmarlo

El header lo pone el navegador, pero **la request pasa por nuestro Nginx** camino
a la plataforma. O sea que lo podemos reescribir en el camino:

```nginx
# en el location / de replica/nginx-replica.conf
proxy_set_header x-actual-domain "https://ganamos7.com/";
```

Con eso el proveedor ve el dominio registrado y el resto sigue igual.

**Honestidad sobre lo que está probado y lo que no:**

- **Probado:** el SPA manda `x-actual-domain` con el dominio del navegador, y
  desde la réplica ese valor es `ganamoscrm.online`. Está en el bundle, se puede
  leer.
- **Probado:** el listado de juegos funciona por la réplica; el proxy no es el
  problema.
- **NO probado:** que el proveedor rechace *por eso*. Para confirmarlo hace
  falta una sola cosa: abrir un juego desde `ganamoscrm.online` con la consola
  del navegador en la pestaña **Red**, buscar la request a `.../game/link` y
  mirar **el cuerpo de la respuesta**. Ahí va a estar el motivo textual del
  proveedor. Es un minuto y evita adivinar.

Si el cuerpo confirma el dominio, el `proxy_set_header` de arriba lo arregla.
Si dice otra cosa, al menos ya sabemos qué, en vez de probar a ciegas.

> **Ojo con el deploy de Nginx:** `deploy.sh` **no** publica
> `replica/nginx-replica.conf`. Ese archivo se copia aparte:
> `git pull` → `sudo cp replica/nginx-replica.conf /etc/nginx/sites-available/replica`
> → `nginx -t && systemctl reload nginx`. El tropiezo típico es correr el `cp`
> **antes** del `pull`: aplica la config vieja "con éxito".

---

## 3. De paso: lo que sigue pendiente de tu lado

El bug de `PARA-FAUNO-deposito.md` **sigue abierto en el repo del bot**.
Verificado hoy sobre `Bot-python`: `alta_api.evaluar_deposito(status: int)`
todavía decide **solo por el código HTTP**, y no existe ninguna función que
reconozca el challenge del WAF (no hay `es_challenge` ni `/exhk` en ningún
archivo).

Mientras tanto eso vive de un **parche en caliente** que GOLDPAW aplica por
`docker cp` dentro del contenedor (`scripts/parche-deposito-cuerpo.py`). Ese
parche **sobrevive un `docker restart` pero NO que se recree el contenedor**: el
día que se recree, vuelven los depósitos fantasma que le costaron las fichas a
tres jugadores.

El detalle completo, con los casos reales y los tests, está en
`PARA-FAUNO-deposito.md`.
