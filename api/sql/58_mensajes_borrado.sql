-- 58_mensajes_borrado.sql — Eliminar un mensaje enviado, estilo WhatsApp.
--
-- Borrado BLANDO a propósito: la fila queda con borrado_en marcado y cumple
-- tres papeles a la vez:
--   1. El CRM muestra «Mensaje eliminado» en el hilo (rastro auditable: se ve
--      QUE hubo un mensaje y quién lo borró, no un hueco silencioso).
--   2. mis_mensajes.php deja de entregarlo (quien no lo recibió, no lo ve).
--   3. Es la LÁPIDA de la retracción: mis_mensajes devuelve los ids borrados
--      recientes y el widget saca la burbuja de la pantalla y de la charla
--      guardada del jugador que SÍ lo había recibido.
-- Un DELETE duro no puede hacer ni 1 ni 3.
--
-- Solo mensajes salientes (rol 'agente'/'bot'): los del jugador no se tocan
-- (lo exige crm.php, no el esquema). borrado_por = operador que lo eliminó.
--
-- Idempotente (IF NOT EXISTS), como toda la serie.

ALTER TABLE mensajes
  ADD COLUMN IF NOT EXISTS borrado_en DATETIME NULL
  COMMENT 'mensaje eliminado por un operador; se muestra como "Mensaje eliminado" y se retrae del widget';

ALTER TABLE mensajes
  ADD COLUMN IF NOT EXISTS borrado_por VARCHAR(60) NULL
  COMMENT 'operador que lo elimino';
