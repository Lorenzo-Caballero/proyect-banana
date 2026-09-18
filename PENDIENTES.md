# Pendientes — lo que sigue

Lo que Nahuel pidió y todavía no está hecho, con lo que ya se averiguó de cada
cosa para no volver a empezar de cero. Cuando algo se termina, se saca de acá.

No confundir con `TODO_FASE_A.md`, que son deudas acotadas de un módulo
concreto. Acá va lo que el dueño pidió explícitamente.

---

## 1. «Comprobantes» nunca muestra nada — ¿está de más?

**El pedido (18/09/2026):** *«sigo sin entender qué hace el apartado de
comprobantes en el CRM. Nunca me aparece nada ahí… revisá si ese apartado está
de más, si la información que se muestra ahí ya se muestra en otro lado.»*

**Lo que ya se midió (producción, 18/09/2026):**

```
pagos por estado:     usado 84        ← ninguno en `revision`
recargas (30 días):   acreditada 51 · vencida 74 · pendiente 3 · cancelada 1
```

**Está vacío porque no hay nada roto, no porque sobre.** Esa pantalla es una
bandeja de EXCEPCIONES: muestra los pagos que el matcher no pudo atribuir a
ningún jugador (`pagos.estado = 'revision'`) para que una persona los asigne a
mano. Hoy el matcher está atribuyendo el 100%, así que la bandeja está vacía —
que es el estado sano. En septiembre llegó a tener 25 acumulados, todos del
camino A (el botón «Depósitos» del juego), y ese fue el episodio que la hizo
necesaria.

**Entonces el problema no es que sobre: es que no se explica.** Una sección
siempre vacía y sin estado que diga por qué se lee como rota, y eso es
exactamente lo que pasó.

Tres salidas posibles, a decidir con Nahuel:

1. **Dejarla y explicarla.** Estado vacío que diga *«no hay nada para resolver:
   los 84 pagos del período se acreditaron solos»*, con el número. Un vacío que
   informa deja de parecer un error.
2. **Sacarla del rail y dejarla como aviso.** Que aparezca SOLO cuando hay algo
   —igual que el punto rojo de Conversaciones y el de Retiros pendientes— y que
   el resto del tiempo no ocupe lugar.
3. **Borrarla.** No recomendado: el día que el matcher no pueda atribuir un pago
   (y va a pasar: dos jugadores transfiriendo el mismo monto a la vez, que es
   el precio de haber sacado los centavos únicos), la plata queda sin acreditar
   y sin ninguna pantalla donde verla.

La 2 es la que más se parece a lo que Nahuel pide en el resto del CRM.

> Ojo al tocarla: `TODO_FASE_A.md` tiene una deuda abierta de este mismo módulo
> (al asignar un comprobante a mano no queda rastro en el chat del jugador,
> sólo un push).

---

## 2. Bono de bienvenida distinto según la landing

**El pedido (18/09/2026):** *«que se pueda configurar diferentes bonos de
bienvenida según la landing. Que el bot no siempre regale 50% en la primera
carga… si viene desde landing que no ofrece bono de bienvenida, el bot debe
entender eso y no ofrecerle bono a ese jugador.»*

**Para qué:** poder correr una landing con 50%, otra con 30% y otra sin bono, y
comparar costos y resultados. Hoy el bono es uno solo para todos y no se puede
medir nada de eso.

**El riesgo que Nahuel ya anticipó, y tiene razón:** *«no quiero que esté el
problema… en el que una persona venga desde una landing que no tiene bono y el
bot le diga: tenés un 50% de bono de bienvenida. El bot debe consultar antes
eso.»* O sea: no alcanza con que el bono correcto se acredite — el bot no tiene
que **prometer** un bono que ese jugador no va a cobrar. Prometer y no pagar es
peor que no ofrecer nada.

**Lo que hay que mirar antes de empezar:**

- El bono vive en `config_crm` (`CFG_CRM_DEFAULTS`, `api/config_crm.php`) y es
  **uno global**. Habría que sumarle una columna a `landings` y que el global
  quede como el valor por defecto — el del que llega por el chat sin landing.
- **De dónde sale la landing de un jugador:** `altas.origen` y `altas.url_landing`
  guardan por dónde entró. Es el único lado donde consta, así que la cadena es
  `usuario → altas → landing → % de bono`.
- **Quién decide el bono hoy:** `rl_es_primera_carga()` (`api/recargas_lib.php`)
  contesta si le toca, y el porcentaje sale de la config. Los dos caminos de
  acreditación (transferencia y botón «Depósitos») pasan por ahí.
- **Quién lo PROMETE:** el prompt del chatbot, en `chatbot_bloque_limites()`
  (`api/chatbot_contexto.php`). Ese bloque se arma por conversación, así que
  ahí es donde hay que meter el bono del jugador y no el global. Si la landing
  no da bono, ese bloque no tiene que mencionar ninguno — y las reglas fijas
  tienen que decir explícitamente que no invente uno.
- **Cuidado con el jugador sin alta conocida:** si no se puede saber de qué
  landing vino, va el bono global (el del chat). Ante la duda, el que ya está
  configurado — nunca uno inventado.
- Publicidad ya separa por landing (`landings`, `publicidad_lib.php`), así que
  la comparación de costos sale casi sola una vez que el dato existe.

---

## 3. Tiempo real — lo que queda

Hecho el 18/09/2026: el reintento del WAF (un challenge ya no cuesta 5 ni 15
minutos), el libro cada 5 minutos en vez de 15, el stock cada 5 en vez de 10, y
el jugador recién creado entra al CRM al confirmarse el alta.

**Lo que NO se tocó, y por qué:**

- **El espejo completo sigue cada 5 minutos.** Son ~62 páginas y 53 segundos de
  trabajo sobre un minuto de cron: bajarlo no entra. Los que están hablando ya
  se refrescan cada minuto uno por uno (`refrescar_saldos_activos`), que es el
  caso que importa.
- **El bot contesta con el espejo, dentro del mismo turno.** Si alguien acaba de
  ganar y pide retirar en su primer mensaje, el saldo que ve el bot puede tener
  hasta 5 minutos. `fichas_pedir_retiro()` le contesta *«tu saldo es X»* sin
  mirar qué tan vieja es esa lectura (`usuarios.saldo_visto_en` existe y no se
  usa para esto). Decisión pendiente de Nahuel: cuando la lectura está vieja y
  el jugador dice tener más, ¿el bot lo desmiente igual, o crea el pedido y lo
  mira una persona? Es plata, así que no se cambia sin decirlo.
- **Correr el worker más seguido que un minuto** necesitaría otro proceso con su
  propia sesión de Playwright, y eso son dos logins con la misma cuenta de
  agente peleándose — el problema documentado en `CLAUDE.md` que ya costó que
  el saldo «se actualizara de a ratos». No se hace sin resolver eso antes.
