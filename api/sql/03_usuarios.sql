-- ---------------------------------------------------------------------------
-- Migracion 03: espejo de los usuarios del panel de ganamos + su saldo.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 03_usuarios.sql
--
-- Lo llena sync_usuarios.py leyendo /api/agent_admin/user/ del panel.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS usuarios (
  id              BIGINT       PRIMARY KEY,          -- id del usuario en ganamos
  username        VARCHAR(80)  NOT NULL,
  balance         DECIMAL(14,2) NOT NULL DEFAULT 0,  -- saldo real
  bonus           DECIMAL(14,2) NOT NULL DEFAULT 0,  -- bono (no viene en la lista; queda 0)
  total_deposits  DECIMAL(14,2) NOT NULL DEFAULT 0,
  role            VARCHAR(10)  NULL,
  is_banned       TINYINT(1)   NOT NULL DEFAULT 0,
  creation_date   DATETIME     NULL,                 -- alta en ganamos
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username),
  KEY ix_balance (balance),
  KEY ix_banned (is_banned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
