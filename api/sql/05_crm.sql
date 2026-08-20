-- ---------------------------------------------------------------------------
-- Migracion 05: CRM.
--   * conversaciones del chatbot (una por sesion de navegador)
--   * mensajes de cada conversacion
--   * movimientos de fichas/bonos (historial de la ficha del usuario)
--   * fichas de BONO + datos utiles en jugadores
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 05_crm.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
--
-- OJO collations: las tablas nuevas usan utf8mb4_unicode_ci para matchear tu
-- tabla `jugadores`. Al cruzar con `usuarios` (que quedo en uca1400), las
-- queries usan COLLATE explicito.
-- ---------------------------------------------------------------------------

-- --- Fichas de bono y datos del jugador ---
ALTER TABLE jugadores
  ADD COLUMN IF NOT EXISTS bonus            BIGINT      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tiene_app        TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS notificaciones   TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ultima_actividad DATETIME    NULL;


-- --- Conversaciones (una por session_id del navegador) ---
CREATE TABLE IF NOT EXISTS conversaciones (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_id     VARCHAR(64)  NOT NULL,
  usuario        VARCHAR(50)  NULL,        -- se completa cuando el user lo dice en el chat
  estado         ENUM('abierta','pendiente','cerrada') NOT NULL DEFAULT 'abierta',
  preview        VARCHAR(300) NULL,        -- ultimo texto, para la lista
  no_leidos      INT          NOT NULL DEFAULT 0,
  notas          TEXT         NULL,        -- notas internas del agente
  creada_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY ix_usuario (usuario),
  KEY ix_estado (estado, actualizada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Mensajes de cada conversacion ---
CREATE TABLE IF NOT EXISTS mensajes (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversacion_id BIGINT   NOT NULL,
  rol             ENUM('user','bot','agente') NOT NULL,
  texto           TEXT     NOT NULL,
  meta            TEXT     NULL,           -- JSON opcional (comprobante, montos, etc.)
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_conv (conversacion_id, creado_en),
  CONSTRAINT fk_conv FOREIGN KEY (conversacion_id)
      REFERENCES conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Movimientos de fichas/bonos (historial para la ficha del usuario) ---
CREATE TABLE IF NOT EXISTS movimientos (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario   VARCHAR(50) NOT NULL,
  tipo      ENUM('ficha','bono') NOT NULL,
  monto     BIGINT      NOT NULL,          -- puede ser negativo (ajuste)
  motivo    VARCHAR(200) NULL,
  origen    VARCHAR(30)  NOT NULL DEFAULT 'crm',   -- crm | ruleta | recarga
  creado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_usuario (usuario, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
