-- ---------------------------------------------------------------------------
-- Migracion 35: entregar la clave del alta RECIEN cuando el bot la creo.
--
-- El chatbot le daba usuario y contrasena al jugador apenas encolaba el alta,
-- cuando todavia no existia nada en el panel de agentes. Si el bot despues
-- fallaba (nombre rechazado, sesion caida, panel cambiado), el jugador se
-- quedaba con unas credenciales que no entraban a ningun lado, y encima
-- convencido de que la cuenta ya era suya.
--
-- La clave no se puede leer de `altas.password` en el momento de entregarla:
-- altas_cola.php la borra justo cuando el alta sale bien (era lo unico que
-- justificaba tenerla en claro). Por eso se guarda aparte, en una columna que
-- SOLO se usa para mostrarsela una vez al que la pidio.
--
--   entrega_clave  la clave a mostrar. Se borra al entregarla.
--   entrega_sid    a quien mostrarsela: el session_id del chat que la pidio.
--                  Sin esto, cualquiera que sepa el nombre de usuario pide el
--                  estado y se lleva la contrasena.
--
-- Correr una sola vez:  mariadb -u USUARIO -p BASE < 35_alta_entrega.sql
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS entrega_clave VARCHAR(128) NULL AFTER password,
  ADD COLUMN IF NOT EXISTS entrega_sid   VARCHAR(64)  NULL AFTER entrega_clave;

-- El sondeo del widget pregunta por (id, sid) cada pocos segundos mientras el
-- bot trabaja. Sin indice es un scan por cada consulta de cada jugador.
ALTER TABLE altas
  ADD INDEX IF NOT EXISTS idx_entrega (id, entrega_sid);
