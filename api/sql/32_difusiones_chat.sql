-- ---------------------------------------------------------------------------
-- Migracion 32: difusiones masivas POR CHAT (además de push).
--
-- A diferencia del push (pasivo: el celular la descubre solo al sondear,
-- ver notificaciones.programada_en), un mensaje de chat necesita algo que lo
-- inserte de verdad en el momento elegido -- si no, aparecería antes de
-- tiempo en el historial de quien abra el chat. Por eso esto es una cola
-- propia que un cron (difusiones_chat_procesar.php) vacía cuando corresponde.
--
-- usuario NULL = masiva (se aplica a TODAS las conversaciones que ya
-- existen con un usuario vinculado; no crea conversaciones nuevas).
-- usuario con valor = un jugador puntual.
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 32_difusiones_chat.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS difusiones_chat (
  id             BIGINT       AUTO_INCREMENT PRIMARY KEY,
  usuario        VARCHAR(50)  NULL,               -- NULL = a todas las conversaciones existentes
  texto          TEXT         NOT NULL,
  programada_en  DATETIME     NOT NULL,           -- UTC, igual criterio que notificaciones.programada_en
  procesada_en   DATETIME     NULL,               -- cuándo la aplicó el cron (NULL = todavía pendiente)
  alcance        INT          NULL,               -- a cuántas conversaciones se les insertó, una vez procesada
  creado_por     VARCHAR(60)  NULL,               -- operador que la programó
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pendientes (procesada_en, programada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
