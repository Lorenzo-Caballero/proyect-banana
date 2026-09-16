# El fast-path detecta el nombre ocupado y después lo tira yendo al formulario

**Para:** quien mantiene el repo del bot (`alta_api.py`, `bot_crear_jugador.py`)
**De:** el lado GOLDPAW (CRM / API / colector)
**Fecha:** 16/09/2026
**Severidad:** media → **baja** desde hoy (leé "Por qué ya no es urgente"), pero
el arreglo vale igual: es robustez que ya está escrita.

> **ESTADO: EL ARREGLO YA ESTÁ EN EL REPO DEL BOT**, commit `e0db90b`
> (16/09/2026), con `t_alta_api.py` en 74 OK. **Falta desplegar**:
> `bash /opt/goldpaw/scripts/deploy-bot.sh`. Abajo está el detalle de qué se
> tocó y por qué.

---

## Resumen en una línea

Cuando `evaluar_respuesta()` dictamina **renombrar** («nombre ya existente») y
el listado no se puede consultar, el bot igual iba al formulario **con el mismo
nombre**; el formulario cruza el MISMO WAF, revienta, y el mensaje que llega a
`altas_cola.php` («No apareció el formulario de alta») **no matchea ninguna
pista de nombre ocupado** — el diagnóstico correcto se pierde y el alta
reintenta con el nombre condenado durante horas.

## La evidencia (log del 16/09, alta 320 — y también 323 y 324)

```
fast-path 320 / Javierso: HTTP 200 -> renombrar | nombre ya existente
Creando jugador 320 / Javierso          <- al formulario, MISMO nombre
Excepcion en 320 / Javierso             <- "No aparecio el formulario de alta"
```

El veredicto `renombrar` era correcto. Con el backoff de 5/20/60 minutos y el
mismo nombre en cada vuelta, el jugador esperó horas para nada.

## El arreglo (ya escrito en `bot/bot_crear_jugador.py`)

1. **El veredicto `renombrar` ya no baja al formulario.** En el triage del
   fast-path, cuando `res is False` la lógica ya miraba `existe_en_panel()`:
   si el nombre figura en NUESTRO listado es un alta de un intento anterior
   (se marca ok), y si es de otro agente se reporta para renombrar. Lo que
   cambió es el tercer caso: si el listado **no se puede consultar** (devuelve
   `None` — típicamente porque también cruza el WAF), antes caía al formulario
   con el mismo nombre; ahora **reporta el «nombre ya existente» tal cual** y
   la cola renombra YA. Es el mismo razonamiento que ya estaba escrito para los
   challenges: «caer al formulario no ayuda: cruza el MISMO WAF». El único
   costo posible —renombrar un alta que en realidad creó un intento anterior
   nuestro, dejando una cuenta huérfana en el panel— es mucho menor que horas
   de espera, y está documentado en el comentario del código.

2. **El reintento del challenge pasó de 3 vueltas fijas de 1,5 s a 5 intentos
   con espera creciente** (1,5 / 3 / 4,5 / 6 s): las 3 vueltas no alcanzaban
   para los challenges persistentes de hoy. Y con un **latido por vuelta**
   (`_latir()`): el peor caso del bucle entero roza los 90 s del watchdog
   (`ALTA_WATCHDOG_SEG`), y sin el latido una pasada legítima con el panel
   lento moría por `os._exit` con el lote reclamado — el incidente del 7/9
   otra vez. El deadline del lote (`ALTA_LOTE_DEADLINE_MS`) sigue acotando el
   total; lo que no llega a intentarse vuelve a la cola como siempre.

**Cómo verificar en producción que tomó:** tras un veredicto `renombrar` con el
listado caído, el log tiene que mostrar `-> ... (a renombrar; sin listado para
confirmar)` y NUNCA un `Creando jugador <id> / <mismo nombre>` a continuación.

## Por qué ya no es urgente (lo que hicimos del lado GOLDPAW, 16/09)

- **Bajamos la presión sobre ese camino:** desde hoy el chat genera los
  nombres como la landing (`holaJuan847` — prefijo + 3 dígitos al azar,
  commit `f8db3d1` de GOLDPAW), así que el choque global de nombre se volvió
  prácticamente imposible y el `res is False` debería ser rarísimo.
- **Y pusimos una red:** si un alta falla **dos veces** con el mismo nombre,
  la renombramos **sin importar qué diga el mensaje** (commit `dcccc75`,
  `alta_debe_renombrar()`). Eso corta el ciclo infinito aunque el diagnóstico
  se vuelva a perder por cualquier otro camino.

O sea: tu arreglo es la solución de raíz y ya está commiteado (`e0db90b`);
las dos cosas de arriba hacen que no corra urgencia para desplegarlo. Cuando
lo tomes, es `deploy-bot.sh` y nada más.
