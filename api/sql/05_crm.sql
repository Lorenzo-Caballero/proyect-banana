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
-- Solo si `jugadores` todavia existe. La BORRO la migracion 07, que unifico
-- todo en `usuarios`, y las bases nuevas nunca la tuvieron (se saltean las
-- migraciones 01-02). O sea que hoy este ALTER no aplica en ningun lado.
--
-- Sin la guarda fallaba SIEMPRE con "table doesn't exist", y como el
-- provisionador solo guarda la huella de las migraciones cuando no hubo
-- errores, eso dejaba las 40 migraciones de cada cliente corriendo cada
-- minuto, para siempre, tapando el log donde aparecen los avisos de salud.
--
-- Va con PREPARE y no con `ALTER TABLE IF EXISTS` porque esa forma no la
-- soporta MariaDB 10.4 (probado); esta anda en cualquier version.
SET @gp_hay_jugadores := (SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jugadores');
SET @gp_sql := IF(@gp_hay_jugadores > 0,
  'ALTER TABLE jugadores
     ADD COLUMN IF NOT EXISTS bonus            BIGINT      NOT NULL DEFAULT 0,
     ADD COLUMN IF NOT EXISTS tiene_app        TINYINT(1)  NOT NULL DEFAULT 0,
     ADD COLUMN IF NOT EXISTS notificaciones   TINYINT(1)  NOT NULL DEFAULT 0,
     ADD COLUMN IF NOT EXISTS ultima_actividad DATETIME    NULL',
  'DO 0');
PREPARE gp_st FROM @gp_sql;
EXECUTE gp_st;
DEALLOCATE PREPARE gp_st;


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
