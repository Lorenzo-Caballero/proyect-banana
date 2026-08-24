-- ---------------------------------------------------------------------------
-- Migracion 36: una bandera explicita de "esta cuenta EXISTE en el panel".
--
-- Hasta ahora eso se deducia de `estado = 'ok'`. Funciona, pero `estado` es un
-- ENUM que usa toda la cola para otras cosas (pendiente, procesando, error) y
-- cualquier cambio ahi -- un valor nuevo, un UPDATE mal escrito, una migracion
-- futura -- se lleva puesta la unica condicion que protege las credenciales.
--
-- Con plata de por medio esa condicion merece una columna propia, que no haga
-- otra cosa y que solo pueda escribir el bot cuando el panel confirmo el alta:
--
--   creado_en_panel = 0  -> NO existe todavia. NO se entregan credenciales.
--   creado_en_panel = 1  -> el panel confirmo. Recien ahi salen usuario y clave.
--
-- Arranca en 0 para TODAS las filas, incluidas las que ya estan en 'ok'. Es a
-- proposito: preferimos que un alta vieja no entregue nada (el jugador ya tiene
-- sus datos, o los pide por chat) antes que arriesgar entregar credenciales de
-- una cuenta que en realidad nunca se creo. El backfill de abajo esta comentado
-- justamente para que sea una decision consciente y no un efecto del deploy.
--
-- Correr una sola vez:  mariadb -u USUARIO -p BASE < 36_alta_creado_en_panel.sql
-- (la corre sola provisionar.php, que aplica api/sql/*.sql en orden)
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS creado_en_panel TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'El panel de agentes confirmo el alta. Sin esto no se entregan credenciales.'
    AFTER estado;

-- El sondeo del widget pregunta por (id, entrega_sid) y despues mira esta
-- columna. Va en el mismo indice para que la consulta no toque la tabla.
ALTER TABLE altas
  ADD INDEX IF NOT EXISTS idx_entrega_confirmada (id, creado_en_panel);

-- Backfill OPCIONAL, a mano y solo si sabes lo que estas haciendo: marca como
-- creadas las altas que el bot ya habia confirmado antes de esta migracion.
-- Antes de correrlo, revisa en el panel que esos jugadores existan de verdad:
-- hubo una etapa en la que el bot creaba contra el panel VIEJO.
--
--   UPDATE altas SET creado_en_panel = 1 WHERE estado = 'ok';
