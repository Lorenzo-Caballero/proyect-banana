# PARA NAHUEL — Dominios del panel y failover automático (25-26/10/2026)

Resumen de lo que pasó con las altas estos días y cómo quedó blindado. Léelo si
las altas vuelven a fallar: casi seguro es un tema de **dominio del panel**, y
abajo está cómo diagnosticarlo y qué hace el sistema solo.

---

## 1. Qué pasó: las altas se cayeron por un certificado vencido

El panel de agentes se servía por `agents.ganamos7.com`. **El 22/10 se le venció
el certificado TLS de ese dominio** (es de la plataforma, no lo renovamos
nosotros). El bot no podía ni abrir la página ni crear usuarios:

    Page.goto: net::ERR_CERT_DATE_INVALID
    APIRequestContext.post: certificate has expired

No era el código ni ningún cambio nuestro — era el cert de ellos. Lo mostró
`salud_bot.php` (ahí mirá SIEMPRE primero cuando fallen las altas).

**Arreglo:** volvimos a `agents.ganamosonline.com` (cert válido, panel probado).
Las altas volvieron. (commit `d395f37`)

---

## 2. Tenemos 7 dominios que son LA MISMA cuenta y crean usuarios

Probamos cada uno con nuestras credenciales reales (login + crear un usuario de
verdad, no solo mirar). **Los siete** son el mismo panel, la misma cuenta de
agente (mismo ID, mismos jugadores) y **crean usuarios**:

| Dominio | Build del panel | Probado bajo carga real |
|---|---|---|
| `agents.ganamosonline.com` | viejo | ✅ **el principal** |
| `agents.ganamosbet.net` | viejo | — (alta probada) |
| `agents.ganamos.io` | viejo | ⚠️ bajo ráfaga tira challenges (ver 4) |
| `agents.ganamos.co` | nuevo | — (alta probada) |
| `agents.ganamos1.com` | nuevo | — (alta probada) |
| `agents.ganamos.net` | nuevo | — (alta probada) |
| `agents.ganamos.bet` | nuevo | — (alta probada) |

**Hay dos "builds" (versiones) del panel**, y es importante:

- **Build viejo** (online, bet.net, io): el alta manda `firstName`/`lastName`.
- **Build nuevo** (co, 1.com, .net, .bet): el alta exige un campo extra
  **`role`** (`0` = jugador, `6` = agente) y usa `first_name`/`last_name`. Sin
  `role` contesta `422 "role - Field required"`.

Para que el bot cree en los dos sin complicarse, el fast-path ahora manda un
**payload "superset"** que incluye todo (los dos formatos de nombre + `role:0`);
cada backend usa lo suyo e ignora el resto. Resultado: **el bot crea en
cualquiera de los 7**.

Scripts para volver a probar cualquier dominio (corren en el contenedor del bot,
no tocan producción):

- `probar_dominios.py` — ¿loguea y es nuestra misma cuenta? (solo lectura)
- `probar_alta.py` — ¿crea un usuario de verdad? (crea un `testio…` para borrar)

> **Pendiente tuyo:** borrá del panel los usuarios de prueba `testio…` y
> `testts…` que quedaron de estas pruebas (buscá "testio" y "testts").

---

## 3. La lección sobre los certificados: SE RENUEVAN SOLOS

No hace falta andar buscando "el dominio con el cert más largo" — es un dato que
**no importa**. Todos los dominios detrás de **Cloudflare** usan un certificado
de **Google Trust Services** que **Cloudflare renueva automáticamente** ~30 días
antes de vencer. La fecha que ves (ej: 7 Dic, 16 Ene) es solo la ventana de hoy;
se corre sola para siempre.

**El único que falló fue `ganamos7`, y no por la fecha: usaba Let's Encrypt de un
solo dominio, gestionado a mano, y lo dejaron lapsar.** Lo que importa no es la
fecha del cert sino **quién lo renueva** (Cloudflare = seguro; a mano = riesgo).

---

## 4. El error con ganamos.io (y por qué quedó de respaldo, no principal)

`ganamos.io` tiene el cert más largo y no tiene Bot Fight Mode, así que lo
pusimos de principal. **Fue un error.** Bajo la carga real del bot (crea de a 3
en paralelo) `ganamos.io` tira el challenge del WAF (ServicePipe `/exhk`) en
**cada** creación, sin parar, y las altas se traban.

La trampa: lo medimos con un `curl` suelto y **un** alta por API — eso dio 200,
pero **no predice la ráfaga del bot real**. La regla, que ya estaba en el
contexto del repo: **el WAF se mide con el bot creando en ráfaga, no con un
curl.** `ganamosonline`, con todos sus challenges, es el único probado
funcionando bajo carga real (los maneja `despejar_waf()`).

Por eso `ganamosonline` volvió a ser el principal, y `ganamos.io` y los demás
quedaron de **respaldo**, no de principal. (commit `1ee5af9`)

---

## 5. Lo que quedó blindado: FAILOVER automático en dos niveles

Ya no dependemos de un solo dominio. El sistema tiene una **lista de 7 en orden
de preferencia** (del más probado al menos) y elige solo:

1. `agents.ganamosonline.com` — build viejo, **probado bajo carga** (principal)
2. `agents.ganamosbet.net` — build viejo
3. `agents.ganamos.io` — build viejo
4. `agents.ganamos.co` — build nuevo (`role:0`)
5. `agents.ganamos1.com` — build nuevo
6. `agents.ganamos.net` — build nuevo
7. `agents.ganamos.bet` — build nuevo

Los build-nuevo van últimos a propósito: crean en la prueba, pero **no medimos
cómo aguantan la ráfaga** (la lección de ganamos.io). Si alguno falla bajo carga,
el Nivel B lo saca solo.

**Nivel A — al desplegar** (`scripts/arreglar-bot-altas.sh`): elige el primero
con **cert vigente** y, entre esos, prefiere el **auto-renovable (Cloudflare)**.
Si a un dominio se le vence el cert, lo saltea solo. (commits `f2e1365`,
`0dbbaf4`)

**Nivel B — en caliente** (`scripts/vigilar-dominio.sh`, por cron cada 5 min):
mira si la cola de altas **drena**. Si queda trabada más de 12 min (sea por cert,
por WAF o lo que sea), **rota solo al siguiente dominio**. Tiene cooldown de 20
min para no saltar en loop. **Esto es lo que cubre "un WAF tapa operaciones":**
si el dominio en uso empieza a tirar challenges, deja de drenar la cola y el
sistema salta al siguiente sin que nadie lo toque. (commit `02e38f0`)

**Y el bot crea en cualquiera** gracias a la **plantilla superset** (manda
`role:0` + los dos formatos de nombre): así un failover a un build-nuevo no
falla por el campo `role`. En el dominio actual está dormida (ya tiene su
plantilla), así que no cambia nada hoy; se activa sola tras un failover.
(commit del bot `9305336`)

> **Pendiente tuyo:** instalar el cron del Nivel B en el VPS:
> ```
> */5 * * * * /opt/goldpaw/scripts/vigilar-dominio.sh >> /var/log/gp-vigilar-dominio.log 2>&1
> ```
> Probar en seco primero (no toca nada): `GP_DRY=1 bash /opt/goldpaw/scripts/vigilar-dominio.sh`

---

## 6. Comandos útiles para cuando falle

```bash
# 1. El estado real de las altas (desde afuera, sin entrar al CRM)
curl -s https://ganamoscrm.online/gp-api/salud_bot.php | python3 -m json.tool | head -30

# 2. ¿A qué dominio apunta el bot ahora?
docker exec ganamos-bot-creador env | grep -E "PANEL_URL|LOGIN_URL"
docker exec ganamos-bot-creador cat /datos/alta_endpoint.json | grep -o 'https://[^"]*' | head -1

# 3. Ver si un cert está vigente y quién lo emite (lo que mató a ganamos7)
echo | openssl s_client -servername agents.ganamosonline.com -connect agents.ganamosonline.com:443 2>/dev/null \
  | openssl x509 -noout -issuer -enddate

# 4. Forzar un dominio a mano (rollback inmediato)
PANEL_HOST_FORZADO=agents.ganamosonline.com bash ~/Bot-python/arreglar-bot-altas.sh

# 5. Probar otro dominio sin tocar producción (crea un testio para borrar)
docker cp ~/Bot-python/probar_alta.py ganamos-bot-creador:/app/
docker exec ganamos-bot-creador python /app/probar_alta.py agents.ganamosbet.net
```

---

## 7. Las dos reglas que hay que recordar

1. **Las altas fallan → mirá `salud_bot.php` primero.** Da el error real (cert,
   WAF, cola trabada) sin adivinar.
2. **Un dominio que responde a un `curl` NO está probado.** Lo único que prueba
   que sirve es un alta real por fast-path, bajo la carga del bot. Esto nos
   mordió tres veces (ganamos7, el 18/09, y ganamos.io el 25/10).

---

*Hecho con Claude Code. Detalle técnico completo en los comentarios de
`scripts/arreglar-bot-altas.sh`, `scripts/vigilar-dominio.sh` y
`panel/provisionar.php`.*
