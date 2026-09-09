-- 55_altas_id_ganamos.sql — Guardar el id de ganamos del jugador al crearlo.
--
-- POR QUE
-- El deposito de fichas (ejecutar_cargas.py) hace
--     POST /api/agent_admin/user/{id}/payment/
-- y ese {id} es el id del jugador EN GANAMOS. Hasta ahora ese id solo lo
-- teniamos por el espejo `usuarios` (usuarios.id), que lo pobla sync_usuarios.
-- Cuando el sync esta atrasado o CAIDO, un jugador recien creado no tiene fila
-- en `usuarios` -> no hay id -> el deposito queda en 'revisar' y las fichas NO
-- llegan al juego, aunque la plata ya entro. Pasó el 7/9/2026.
--
-- El id ya viene en la respuesta del panel cuando el bot CREA al jugador
-- (evaluar_respuesta lo ve en result.id). Guardarlo aca, en el momento del
-- alta, corta la dependencia del sync para depositar: acciones_cola.php cae a
-- altas.id_ganamos cuando usuarios.id no esta.
--
-- Idempotente (ADD COLUMN IF NOT EXISTS): la corre el provisionador sobre todas
-- las bases, incluidas las que ya la tienen.

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS id_ganamos BIGINT UNSIGNED NULL
  COMMENT 'id del jugador en la plataforma ganamos, capturado al crearlo (=usuarios.id)';
