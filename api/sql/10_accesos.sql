-- ---------------------------------------------------------------------------
-- Migracion 10: login real en tu dominio.
--
-- `accesos` = credenciales del sitio (usuario + contraseña hasheada). El usuario
-- debe existir en `usuarios` (los jugadores reales sincronizados de ganamos), asi
-- el login queda atado a las cuentas de la plataforma. NO guarda la contraseña de
-- ganamos: es una clave propia que el jugador define para tu sitio.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 10_accesos.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS accesos (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario       VARCHAR(50)  NOT NULL,          -- = usuarios.username
  password_hash VARCHAR(255) NOT NULL,          -- password_hash()
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login  DATETIME     NULL,
  UNIQUE KEY uq_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
