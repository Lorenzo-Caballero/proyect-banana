-- ---------------------------------------------------------------------------
-- Migracion 41: archivar conversaciones.
--
-- POR QUE UNA COLUMNA Y NO UN CUARTO VALOR DE `estado`
--
-- `estado` (abierta/pendiente/cerrada) es el estado del TICKET: donde esta la
-- gestion. Archivar es otra cosa: "sacame esto de la bandeja". Son
-- independientes -- se archiva una cerrada porque ya no interesa, y tambien
-- una abierta que resulto ser spam.
--
-- Si archivar fuera un cuarto valor del ENUM, archivar PISARIA el estado del
-- ticket, y al desarchivar habria que adivinar cual era. Con una columna
-- aparte, una conversacion archivada sigue sabiendo que estaba pendiente.
--
-- Ademas el archivado tiene que sacarla de TODAS las pestañas a la vez; un
-- valor de ENUM solo la saca de las otras tres.
--
-- Se guarda quien y cuando: archivar esconde de la bandeja una conversacion
-- donde se hablo de plata, y esa decision tiene dueño.
--
-- La corre panel/provisionar.php contra cada base de cliente.
-- ---------------------------------------------------------------------------

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS archivada     TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS archivada_en  DATETIME    NULL,
  ADD COLUMN IF NOT EXISTS archivada_por VARCHAR(60) NULL;

-- La lista filtra por `archivada` en TODAS sus consultas, incluidas las de
-- inactividad que ya ordenan por actualizada_en. Sin este indice, cada carga
-- del CRM hace table scan.
ALTER TABLE conversaciones
  ADD KEY IF NOT EXISTS ix_archivada (archivada, actualizada_en);
