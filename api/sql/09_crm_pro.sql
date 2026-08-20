-- ---------------------------------------------------------------------------
-- Migracion 09: CRM pro.
--   * anclar (fijar) conversaciones
--   * movimientos ahora tambien 'saldo' (cargar/retirar saldo del panel)
--   * cola de acciones de saldo para el worker Python (ejecuta en ganamos)
--
-- Los adjuntos (imagenes/pdf) van en mensajes.meta (JSON) — no hace falta tabla.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 09_crm_pro.sql
-- ---------------------------------------------------------------------------

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS fijada TINYINT(1) NOT NULL DEFAULT 0;

-- 'saldo' = movimiento del saldo real (positivo carga, negativo retiro)
ALTER TABLE movimientos
  MODIFY COLUMN tipo ENUM('ficha','bono','saldo') NOT NULL;

-- Cola de acciones que tocan el SALDO real de ganamos. El CRM las encola;
-- un worker Python (con la SESSION_COOKIE del agente) las ejecuta y marca.
CREATE TABLE IF NOT EXISTS acciones_saldo (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario     VARCHAR(50)  NOT NULL,
  tipo        ENUM('cargar','retirar') NOT NULL,
  monto       DECIMAL(14,2) NOT NULL,
  motivo      VARCHAR(200)  NULL,
  estado      ENUM('pendiente','hecha','error') NOT NULL DEFAULT 'pendiente',
  mensaje     VARCHAR(300)  NULL,
  creada_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ejecutada_en DATETIME    NULL,
  KEY ix_estado (estado, id),
  KEY ix_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
