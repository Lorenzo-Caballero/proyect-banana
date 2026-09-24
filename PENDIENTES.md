# Pendientes — en orden, de a uno

Lo que Nahuel pidió y todavía no está hecho. **Está ordenado por prioridad y se
trabaja de arriba hacia abajo, de a uno.** Cuando algo se termina, se saca de
acá y se deja la fecha en el commit.

No confundir con `TODO_FASE_A.md`, que son deudas acotadas de un módulo. Acá va
lo que el dueño pidió explícitamente, más lo que encontramos y no resolvimos.

Orden acordado el 22/09/2026. Actualizado el 24/09.

---

## Hechos esta semana (se sacan de la cola)

- **Un cliente ya puede conectar su casilla de mail** (23/09/2026). Lo
  construyo Fauno; de este lado quedaron el cifrado y el arreglo para que
  el colector se entere de una casilla nueva sin reiniciarse.
- **El documento para el agente: DESCARTADO** (24/09/2026). Nahuel grabó un
  video explicando el CRM él mismo, así que no hace falta. No reabrir.
- **El acceso de un cliente resolvia a NUESTRA base** (24/09/2026). El cliente
  `ganamos` tenia db_nombre apuntando a u722310012_fauno888; su operador no
  podia entrar, y ese bug era lo unico que impedia que viera nuestros datos.
  Corregido a mano y con guarda en provisionar.php.
- **El chat deja de figurar anonimo tras el login** (24/09/2026). No era
  lento: el renombre vivia solo en el camino del mensaje. Ahora pasa en el
  sondeo, en menos de 7 segundos.

---

## 1. Dos bases con 3.000 jugadores no reciben migraciones

`gp_casinotest` (3.027 jugadores) y `gp_online` (3.028) existen pero **no
figuran en `clientes`**, así que `provisionar.php` —que recorre
`WHERE aprovisionado = 1 AND estado = 'activo'`— no las ve. Vienen quedándose
atrás en el esquema y nada avisa.

Quedó sin correr el paso que decide qué hacer:

```
mariadb -e "SELECT table_schema AS base, COUNT(*) AS tablas, MAX(update_time) AS ultima_escritura FROM information_schema.tables WHERE table_schema IN ('gp_casinotest','gp_cliente2','gp_online','gp_ganamos','u722310012_fauno888') GROUP BY table_schema ORDER BY ultima_escritura DESC"
```

- Escritura reciente → están en uso y hay que migrarlas
- Última escritura el 15/09 y nada después → restos de la mudanza, se borran con backup

**Lo que hay que dejar hecho igual, gane cual gane:** que `provisionar.php`
compare las bases `gp_*` que EXISTEN contra `clientes` y avise por Telegram
cuando encuentre una huérfana. Hoy el sistema confía en que esa tabla está
completa, y ya hay evidencia de que no siempre lo está: en `clientes` hay un
slug que dice `cleinte3`.

---

## 2. Volver a medir la demora de las notificaciones

La foto de ANTES está tomada (21/09/2026, últimos 7 días):

| demora | entregas | |
|---|---|---|
| hasta 10 s | 562 | 84,5% |
| hasta 1 min | 33 | 5,0% |
| hasta 15 min | 60 | 9,0% |
| más de 15 min | 10 | 1,5% |

El 84,5% eran entregas con la app ABIERTA (el widget sondea cada 25 s). El
segmento que Firebase vino a arreglar es el ~10,5% de abajo: los que había que
despertar. Repetir la consulta con la gente ya en 1.9 dice cuánto se movió.

```
mariadb u722310012_fauno888 -e "SELECT CASE WHEN TIMESTAMPDIFF(SECOND,n.creada_en,e.entregada_en)<=10 THEN '1 hasta 10s' WHEN TIMESTAMPDIFF(SECOND,n.creada_en,e.entregada_en)<=60 THEN '2 hasta 1min' WHEN TIMESTAMPDIFF(SECOND,n.creada_en,e.entregada_en)<=900 THEN '3 hasta 15min' ELSE '4 mas de 15min' END AS demora, COUNT(*) AS cuantos FROM notificaciones_entregas e JOIN notificaciones n ON n.id=e.notificacion_id JOIN dispositivos d ON d.device_id=e.device_id AND d.plataforma='android' WHERE e.entregada_en > NOW() - INTERVAL 7 DAY AND n.programada_en IS NULL GROUP BY demora ORDER BY demora"
```

---

## 3. La estética de Notificaciones y su congruencia con Juegos

Pedido el 22/09/2026 y no hecho. Revisar el apartado entero, no sólo parchar.

---

## Ofrecido, esperando que Nahuel decida

- **Grupo de control** para saber si los avisos CAUSAN cargas o sólo coinciden.
  Está el diseño; falta que él elija el porcentaje de jugadores que quedan
  afuera.
- **Premios de ruleta configurables por cliente** (hoy están fijos en el código).
- **Cron de fidelización cada 15 min** en vez de por hora.
- **Tarjeta de demoras de notificaciones** en el CRM, para no depender de correr
  la consulta del punto 2 a mano.

---

## Deudas técnicas anotadas

- **Capa 0 del matcher:** `recargas.trx_declarada` se guarda pero el matcher no
  casa por número de operación. Hoy desempata el titular.
- **Números de migración repetidos:** dos `72` y dos `73`, porque dos sesiones
  numeraron a ciegas. Hoy no rompe nada (el orden es determinista y todas son
  idempotentes), pero es la señal de que falta coordinar.
- **El proxy de `vps/`:** escrito y sin desplegar. Requiere autorización por
  escrito de Nahuel — por ahí pasaría el login de los jugadores.
- **`/colector` como volumen del compose:** pedido a Fauno. Mientras tanto vive
  por `docker cp` y un `--build` lo borra (se repone en ≤60 s por el cron).
- **Comprobante asignado a mano:** no deja rastro en el chat del jugador, sólo
  un push.

---

## Decisiones tomadas — no reabrir sin motivo

**«Comprobantes» se queda** (Nahuel, 18/09/2026). Está vacío porque el matcher
atribuye el 100%, no porque sobre: es la bandeja de EXCEPCIONES, el único lugar
donde se ve la plata que no se pudo atribuir. En septiembre llegó a tener 25
acumulados.

**La contraseña fija de las altas se queda.** Es una decisión de negocio: el
jugador la cambia si quiere, y si se la olvida el agente se la repone.

---

## Para pedirle a la plataforma (no depende de nosotros)

- **La IP del VPS en su lista blanca, o una API oficial de agente.** Es el único
  arreglo de fondo del WAF; todo lo demás que hicimos es convivir con él.
- **Que un token de juego muerto falle visible** en vez de dejar el launcher
  cargando para siempre.
- **Confirmar que `x-actual-domain` es la causa** de que los juegos no abran por
  la réplica. Está probado que el SPA manda el dominio del navegador; que el
  proveedor rechace POR ESO es la hipótesis.
