-- ---------------------------------------------------------------------------
-- Migracion 04: jugadas de la ruleta (para el limite de 1 por dia por usuario).
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 04_ruleta.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ruleta_jugadas (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario       VARCHAR(50) NOT NULL,
  dia           DATE        NOT NULL,          -- para el limite diario
  premio_coins  BIGINT      NOT NULL,
  indice        TINYINT     NOT NULL,
  ip            VARCHAR(45) NULL,
  jugado_en     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Un solo giro por usuario por dia. El indice unico es la traba real
  -- contra el doble-click / doble-carga (mas fuerte que chequear y despues insertar).
  UNIQUE KEY uq_usuario_dia (usuario, dia),
  KEY ix_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
