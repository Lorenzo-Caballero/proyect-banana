# TODO_FASE_A.md — pendientes marcados durante Fase A

No son bugs que bloqueen un módulo — quedan anotados para no desviarse del
alcance de cada paso. Cuando se retomen, sacarlos de acá.

## Módulo 1 — Comprobantes sin resolver

- **Rastro en el chat del jugador.** Al asignar un comprobante manualmente,
  hoy sólo se le avisa por push (`notif_crear`, igual que el matcher
  automático). El resto del CRM deja un mensaje visible en el hilo cuando la
  acción parte de una conversación abierta (`crm.php`, vía
  `conversacion_id`) — acá no hay una conversación de contexto porque el
  módulo se abre desde el rail, no desde un chat. Si se quiere sumar: buscar
  `conversaciones WHERE usuario = recarga.usuario` y postear ahí si existe.
  Decidido explícitamente afuera del alcance del MVP de este módulo.
- **Paginación del listado.** `crm_comprobantes.php?accion=listar` trae hasta
  100 filas sin paginar. Es MVP a propósito — en el backup de Fase 0.5
  `pagos` estaba en 0 filas. Sumar paginación real cuando el volumen de
  pagos en `estado='revision'` supere ~50 simultáneos.

## Módulo 2 — Retiros pendientes

- **"Cancelar retiro" bloqueado por schema.** `acciones_saldo.estado` es
  `ENUM('pendiente','procesando','hecha','error','revisar')` — no incluye
  `'cancelada'`. Implementarlo como se diseñó (nota interna obligatoria +
  `notif_crear` al jugador + `crm_bitacora`) requiere primero:
  ```sql
  ALTER TABLE acciones_saldo
    MODIFY estado ENUM('pendiente','procesando','hecha','error','revisar','cancelada')
           NOT NULL DEFAULT 'pendiente';
  ```
  Confirmado seguro para el bot (filtra `WHERE estado = 'pendiente'` exacto,
  no `!= 'hecha'` — un estado nuevo nunca se toma por accidente), pero sigue
  siendo un cambio de schema en una tabla que el bot escribe en `LIVE`:
  necesita la ceremonia completa de backup + confirmación antes de aplicarse.
  Pendiente de decisión: aplicar la migración, o usar el workaround sin
  schema (`estado='error'` + prefijo `"CANCELADO POR OPERADOR: ..."` en
  `mensaje`, mezcla dos conceptos distintos, no es lo ideal).

## Módulo 3 — Cargas (Recargas)

- **`recargas` no tiene columna `origen`.** Hoy toda fila la crea
  `rl_crear_recarga()` desde el chatbot — no hay forma de distinguir, si en
  el futuro se agrega otro camino de creación (ej. una carga manual desde el
  CRM), de dónde salió cada una. No bloquea nada del MVP de este módulo
  (`crm_recargas.php` es de solo lectura + cancelar, no crea recargas), pero
  hay que tenerlo presente si se agrega un botón de "crear recarga manual"
  en el CRM más adelante.
- **El char-counter (0/200) del modal de cancelar se sumó solo en el nuevo
  modal de Cargas** (`#backCancelCarga`/`ccContador`), tal cual lo pidió el
  usuario para este módulo. El modal de cancelar retiro (`#backCancelRet`,
  Módulo 2) sigue sin uno — no se tocó por no haber sido pedido. Sumarlo ahí
  también es un cambio de una línea si se quiere después.
- **Comentario obsoleto en `fichas_lib.php`.** Hay un comentario que
  dice que el bot "no sabe retirar", cuando en realidad el flujo de
  retiro sí está implementado y funcionando. Es puramente cosmético
  (no afecta comportamiento), pero conviene corregirlo para no
  confundir a quien lea el código después.

## Módulo 4 — Transacciones globales

- **Índice `ix_creado_en` en `movimientos`, pendiente.** Hoy (7 filas) no
  hace ninguna diferencia — ningún índice existente cubre un filtro por
  `creado_en` solo (`ix_usuario(usuario, creado_en)` y `ix_operador(operador)`
  no lideran con la fecha). La vista por defecto ("todo, ordenado por
  fecha, sin filtro") va a hacer table scan + filesort a partir de cierto
  volumen. Aplicar cuando el `COUNT(*)` de `movimientos` supere ~5000:
  ```sql
  ALTER TABLE movimientos ADD INDEX ix_creado_en (creado_en);
  ```
- **Bug de casing en `movimientos.operador` (y potencialmente en
  `crm_bitacora.operador`) — RESUELTO, `crm_auth.php` ya aplicado en
  producción.** `operador_login()` guardaba en `$_SESSION['operador']` el
  username tal como se tecleó al loguearse, no el valor canónico de la fila
  en `operadores` — como `operadores.username` tiene collation
  case-insensitive (`utf8mb4_unicode_ci`), variantes como "Nahuel"/"nahuel"
  logueaban como la misma persona pero grababan distinto en cualquier
  columna de auditoría. Fix aplicado: ahora guarda `(string)$fila['username']`
  (el canónico de la tabla), confirmado con diff + `php -l` y subido. Las
  filas históricas de ANTES del fix siguen con casing mezclado (no se
  reescribió el pasado, mismo criterio que el resto de Fase A) — por eso
  `crm_movimientos.php` sigue comparando con `LOWER()` en el filtro de
  operador, y así se queda: cubre tanto el histórico viejo como cualquier
  fila nueva.

## Módulo 6 — Finanzas

- **`crm_finanzas.php?accion=hoy` queda deprecado.** Con el rediseño del
  dashboard (filtros de tiempo unificados: Hoy/Ayer/7d/30d/90d/Este
  mes/Todo/custom, todos vía `?accion=rango`), "Hoy" pasó a ser un filtro
  más (`desde=hasta=hoy`) en vez de un endpoint aparte. El endpoint
  `?accion=hoy` se deja vivo en el backend (no se borra nada sin que lo
  pidan) pero el frontend nuevo no lo llama más. Evaluar remoción en Fase B
  si sigue sin uso.

- **Módulo 6.B — Stock de fichas en tiempo real, pendiente.** El
  dashboard de Finanzas muestra "N/A" en "Stock de fichas" y "Valor
  del stock" porque ese dato vive en el panel de ganamos, no en la BD
  del CRM. Para traerlo hace falta: (1) migración de tabla nueva
  `agencia_estado` con columnas `stock_fichas` + `actualizado_en`, (2)
  modificación mínima del bot Python para que cada vez que lee
  `saldo_agente_en_deposito` lo escriba en `agencia_estado`, (3)
  actualizar `?accion=foto` en `crm_finanzas.php` para leer el stock de
  ahí y sumarlo al patrimonio neto. ES EL ÚNICO PENDIENTE PARA CERRAR
  FASE A AL 100%. Toca `bot/` — coordinar con Fauno antes.

- **Costo por ficha editable desde UI, pendiente (Fase B).**
  `FINANZAS_COSTO_POR_FICHA` hoy se edita a mano en `config.local.php`
  (default 0.20). Distintos proveedores cobran distinto (0.20, 0.22,
  0.25). En Fase B, mover esta constante (y los umbrales de alertas) a
  un módulo Configuración con UI de edición, sin tocar el server.

## Endpoints públicos (fuera de Fase A hasta ahora)

- **`api/subir.php` sin autenticación en la rama del jugador.** Documentado
  en el docblock del archivo (Fase 0.5). La rama del agente (CRM) ya queda
  cubierta por `exigir_operador()` indirectamente (sólo la llama `crm.html`,
  que requiere sesión); la rama del jugador (`session_id`, comprobantes
  subidos por chat) es anónima por diseño y no se puede simplemente exigir
  sesión ahí. Gap conocido, prioridad ALTA: sumar validación de `session_id`
  existente en `conversaciones` + rate limit por IP (reusar el patrón de
  `_limite()` en `api/auth.php`) cuando se revisen los endpoints públicos en
  conjunto.

## Contexto de deploy (post-Fase A)

- **Dos entornos activos.** El proyecto corre en Hostinger
  (`orange-crab-483661.hostingersite.com`, entorno original) y en un
  VPS con dominio `ganamoscrm.online` (nueva producción). Confirmar con
  Fauno cuál es la fuente de verdad y si Hostinger se apaga o queda como
  archivo.

- **Multi-tenant en el VPS.** El VPS sirve varios clientes: cada dominio
  mapea a una base distinta vía la tabla `clientes` en `goldpaw_control`
  (columna `db_nombre`). `db.php` resuelve el tenant desde el `Host` del
  request. La base del CRM de Nahuel es `u722310012_fauno888`, mapeada a
  `ganamoscrm.online` y `ganamos.faunotattoo.com`.

- **Nginx + PHP-FPM 8.3.** El VPS usa Nginx (no Apache) con PHP-FPM 8.3.
  Ojo: hubo un incidente donde Nginx apuntaba a un socket de PHP 8.1
  inexistente y todo daba 502 — si vuelve a pasar, revisar
  `/etc/nginx/sites-enabled/replica` y confirmar que el socket apunta a
  `php8.3-fpm.sock`.

- **Deploy por Git.** Se dejó de subir archivos por SCP. Ahora todo pasa
  por el repo `proyect-banana` de Lorenzo-Caballero. Flujo: trabajar en
  rama `predeploy` → PR a `main` → Fauno mergea → deploy al VPS.
