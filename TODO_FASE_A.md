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
