# Pendientes — lo que sigue

Lo que Nahuel pidió y todavía no está hecho, con lo que ya se averiguó de cada
cosa para no volver a empezar de cero. Cuando algo se termina, se saca de acá.

No confundir con `TODO_FASE_A.md`, que son deudas acotadas de un módulo
concreto. Acá va lo que el dueño pidió explícitamente.

---

## 1. «Comprobantes» — DECIDIDO: se queda

**Nahuel, 18/09/2026: «quiero que la parte de comprobantes la mantengas».**

Queda como está. Lo que se averiguó y por qué la decisión es la correcta:

```
pagos por estado:     usado 84        ← ninguno en `revision`
recargas (30 días):   acreditada 51 · vencida 74 · pendiente 3 · cancelada 1
```

**Está vacío porque no hay nada roto, no porque sobre.** Es una bandeja de
EXCEPCIONES: muestra los pagos que el matcher no pudo atribuir a ningún jugador
(`pagos.estado = 'revision'`) para que una persona los asigne a mano. Hoy el
matcher atribuye el 100%. En septiembre llegó a tener 25 acumulados.

El día que el matcher no pueda atribuir un pago —y va a pasar: dos jugadores
transfiriendo el mismo monto a la vez, que es el precio de haber sacado los
centavos únicos— esa plata queda sin acreditar y esta es la única pantalla
donde se ve.

> Queda abierta una deuda menor del módulo en `TODO_FASE_A.md`: al asignar un
> comprobante a mano no queda rastro en el chat del jugador, sólo un push.

---

## 2. Bono de bienvenida por landing — HECHO (18/09/2026)

La acreditación **ya era por landing** (`landings.bono_pct`) y el CRM **ya
tenía el campo**. Lo que faltaba era exactamente el riesgo que Nahuel
anticipó: el bot no lo consultaba y le prometía a todos el porcentaje escrito
a mano en las indicaciones.

Ahora el que promete y el que paga leen lo mismo: `rl_bono_bienvenida_pct()`.

- Landing con 50 / 30 / 0 → cada jugador cobra lo de SU promo.
- Cuenta creada por el chat → el bono general del casino.
- Creada por un operador en el panel → ninguno.
- Si no le toca, el prompt lo dice **explícitamente** y aclara que eso manda
  sobre cualquier promo escrita más arriba. Callarse no alcanzaba: el texto del
  operador sigue ahí y el bot lo repetía igual.

Lo cubre `t_bono_landing.php`.

---

## 3. Tiempo real y el WAF — HECHO (18/09/2026)

Todo lo de esta sección se resolvió el mismo día. Queda acá como registro de
qué se decidió y qué NO se tocó, para no volver a abrirlo sin motivo.

**Lo que se hizo:** el reintento del challenge con espera creciente (lecturas y
escrituras), el barrido que guarda lo leído y retoma donde quedó, el libro y el
stock cada 5 minutos, el jugador recién creado entrando al CRM al confirmarse
el alta, el espejo de 62 a 16 requests, un solo detector del 200 falso con las
cuatro firmas, y el aviso por Telegram cuando una lectura queda vieja.

**La decisión sobre el saldo viejo, que la tomó Nahuel:** cuando la lectura
tiene más de dos minutos el bot deja de desmentir al jugador — dice lo que le
FIGURA, aclara que puede no estar al día, y lo pasa a un agente. No crea el
pedido.

**Lo que NO se tocó, y sigue valiendo:**

- **El espejo completo sigue cada 5 minutos.** Ahora tarda 15 segundos en vez
  de 47, así que bajarlo entraría — pero los que están hablando ya se refrescan
  cada minuto uno por uno, que es el caso que importa, y cada barrido de más es
  exposición de más al WAF sin beneficio.
- **Correr el worker más seguido que un minuto** necesitaría otro proceso con
  su propia sesión de Playwright, y eso son dos logins con la misma cuenta de
  agente peleándose — el problema documentado en `CLAUDE.md` que ya costó que
  el saldo «se actualizara de a ratos». No se hace sin resolver eso antes.
- **`USUARIOS_POR_PAGINA` quedó en 200 y no en 500**, que midió mejor todavía
  (7 requests, 10 s). Subirlo es una variable de entorno, una vez que se vea
  que 200 se porta bien.

---

## 4. Para pedirle a la plataforma

El único arreglo de fondo del WAF no está de nuestro lado: **que pongan la IP
del VPS en su lista blanca, o que den una API oficial de agente.** Todo lo
demás que hicimos es convivir con él. Vale la pena pedirlo — es una frase para
ellos y nos sacaría el problema de encima para siempre.
