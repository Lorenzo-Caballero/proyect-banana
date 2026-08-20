-- ---------------------------------------------------------------------------
-- Migracion 06: ruleta con premio en BONOS y reclamo con usuario.
--
-- Nuevo flujo: se gira primero (sin usuario), el premio queda guardado con un
-- token; despues el jugador ingresa su usuario y RECLAMA -> el premio se
-- acredita en jugadores.bonus. Un giro por sesion por dia; un reclamo por
-- usuario por dia.
--
-- Reemplaza el uso de ruleta_jugadas (migracion 04), que podes dejar como esta.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 06_ruleta_giros.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ruleta_giros (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_id   VARCHAR(64) NOT NULL,
  dia          DATE        NOT NULL,          -- 1 giro por sesion por dia
  indice       TINYINT     NOT NULL,
  premio_bonus BIGINT      NOT NULL,
  token        VARCHAR(64) NOT NULL,          -- ata el giro al reclamo (anti-trampa)
  reclamado    TINYINT(1)  NOT NULL DEFAULT 0,
  usuario      VARCHAR(50) NULL,
  ip           VARCHAR(45) NULL,
  creado_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reclamado_en DATETIME    NULL,
  UNIQUE KEY uq_sesion_dia (session_id, dia),
  KEY ix_token (token),
  KEY ix_usuario_dia (usuario, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
